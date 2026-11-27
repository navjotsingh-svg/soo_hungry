<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

use App\Models\CloverConnection;
use App\Models\Customer;
use App\Models\PickupOrder;
use App\Models\PickupOrderLine;
use App\Models\PrintEvent;
use App\Models\ProposedAction;
use App\Models\StaffHandoff;
use App\Models\User;
use App\Domain\Shared\Alerts;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CafeOrderService
{
    public function __construct(
        private readonly CloverGateway $gateway,
        private readonly CloverAtomicOrderPayload $payloads,
        private readonly Alerts $alerts,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function createLocal(ProposedAction $action, Customer $customer, array $lines, string $pickupName, string $pickupAt, int $subtotal, int $tax, int $total): PickupOrder
    {
        return DB::transaction(function () use ($action, $customer, $lines, $pickupName, $pickupAt, $subtotal, $tax, $total): PickupOrder {
            $existing = PickupOrder::query()->where('idempotency_key', $action->public_id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $location = $customer->merchant->locations()->firstOrFail();
            $order = PickupOrder::create([
                'merchant_id' => $customer->merchant_id,
                'location_id' => $location->id,
                'customer_id' => $customer->id,
                'proposed_action_id' => $action->id,
                'idempotency_key' => $action->public_id,
                'submission_status' => 'pending',
                'dispatch_status' => 'pending',
                'pickup_status' => 'pending',
                'quoted_subtotal_cents' => $subtotal,
                'quoted_tax_cents' => $tax,
                'quoted_total_cents' => $total,
                'pickup_name' => $pickupName,
                'pickup_at' => CarbonImmutable::parse($pickupAt)->utc(),
                'pay_at_pickup' => true,
                'catalog_synced_at' => $customer->merchant->cloverConnection?->catalog_synced_at,
            ]);

            foreach ($lines as $line) {
                $modifierTotal = 0;
                foreach ($line['modifiers'] ?? [] as $modifier) {
                    $modifierTotal += (int) $modifier['price_cents'];
                }
                $unit = (int) $line['unit_price_cents'] + $modifierTotal;
                PickupOrderLine::create([
                    'pickup_order_id' => $order->id,
                    'catalog_item_id' => $line['catalog_item_id'],
                    'clover_item_id' => $line['clover_item_id'],
                    'name' => $line['name'],
                    'quantity' => $line['quantity'],
                    'modifiers' => $line['modifiers'] ?? [],
                    'unit_price_cents' => (int) $line['unit_price_cents'],
                    'line_total_cents' => $unit * (int) $line['quantity'],
                ]);
            }

            return $order;
        });
    }

    public function linkCustomer(Customer $customer, CloverConnection $connection): bool
    {
        if ($customer->clover_customer_id) {
            return true;
        }

        $matches = $this->gateway->findCustomers($connection, $customer->phone_e164);
        if (count($matches) > 1) {
            StaffHandoff::create([
                'merchant_id' => $customer->merchant_id,
                'conversation_id' => $customer->conversation->id,
                'reason' => 'identity_conflict',
                'draft_interpretation' => 'More than one Clover customer matches this phone.',
                'status' => 'open',
            ]);

            return false;
        }

        if (count($matches) === 1) {
            $customer->update(['clover_customer_id' => $matches[0]['id']]);

            return true;
        }

        if (! $connection->can_create_customers || $customer->name === null) {
            StaffHandoff::create([
                'merchant_id' => $customer->merchant_id,
                'conversation_id' => $customer->conversation->id,
                'reason' => 'identity_conflict',
                'draft_interpretation' => 'Clover customer create is not approved or the name is missing.',
                'status' => 'open',
            ]);

            return false;
        }

        $id = $this->gateway->createCustomer($connection, $customer->name, $customer->phone_e164);
        $customer->update(['clover_customer_id' => $id]);

        return true;
    }

    public function submit(PickupOrder $order): PickupOrder
    {
        $order = $order->fresh() ?? $order;
        if ($order->clover_order_id) {
            return $order;
        }
        if ($order->submission_status === 'unknown') {
            return $this->reconcile($order);
        }

        $connection = CloverConnection::query()->where('merchant_id', $order->merchant_id)->firstOrFail();
        $payload = $this->payloads->build($order->load('lines'), $connection);

        try {
            $result = $this->gateway->createOrder($connection, $payload, $order->idempotency_key);
        } catch (CloverUncertainException $exception) {
            $order->update(['submission_status' => 'unknown']);
            $this->alerts->raise($order->merchant_id, 'clover', $exception->getMessage(), $order);

            return $this->reconcile($order->fresh());
        }

        $order->update([
            'clover_order_id' => $result->id,
            'submission_status' => 'submitted',
            'submitted_payload' => $result->payload,
        ]);
        $this->dispatchKitchen($order->fresh(), $connection);

        return $order->fresh();
    }

    public function reconcile(PickupOrder $order): PickupOrder
    {
        $connection = CloverConnection::query()->where('merchant_id', $order->merchant_id)->firstOrFail();
        $existing = $this->gateway->findByIdempotency($connection, $order->idempotency_key);
        if ($existing !== null) {
            $order->update(['clover_order_id' => $existing, 'submission_status' => 'submitted']);
            $this->dispatchKitchen($order->fresh(), $connection);

            return $order->fresh();
        }

        if ($order->submission_status === 'unknown') {
            $this->alerts->raise($order->merchant_id, 'clover', 'No Clover order was found for '.$order->idempotency_key.'. It was not created again automatically.', $order);
        }

        return $order->fresh();
    }

    public function dispatchKitchen(PickupOrder $order, ?CloverConnection $connection = null): void
    {
        if ($order->clover_order_id === null) {
            return;
        }
        if ($order->printEvents()->whereIn('kind', ['auto_route', 'explicit'])->exists()) {
            return;
        }

        $connection ??= CloverConnection::query()->where('merchant_id', $order->merchant_id)->firstOrFail();
        $ticket = $this->ticket($order);

        if ($connection->print_mode === 'creation_triggers_print') {
            PrintEvent::create([
                'pickup_order_id' => $order->id,
                'kind' => 'auto_route',
                'status' => 'recorded',
                'marked_copy' => false,
                'ticket' => $ticket,
                'created_at' => now(),
            ]);
            $order->update(['dispatch_status' => 'acknowledged']);

            return;
        }

        try {
            $result = $this->gateway->printOrder($connection, $order->clover_order_id);
        } catch (CloverUncertainException) {
            PrintEvent::create([
                'pickup_order_id' => $order->id,
                'kind' => 'explicit',
                'status' => 'unknown',
                'marked_copy' => false,
                'ticket' => $ticket,
                'created_at' => now(),
            ]);
            $order->update(['dispatch_status' => 'unknown']);
            $this->alerts->raise($order->merchant_id, 'printer', 'Print result unknown for '.$order->clover_order_id.'. Inspect the printer and the order.', $order);

            return;
        }

        PrintEvent::create([
            'pickup_order_id' => $order->id,
            'kind' => 'explicit',
            'clover_print_event_id' => $result->eventId,
            'status' => $result->status,
            'marked_copy' => false,
            'ticket' => $ticket,
            'created_at' => now(),
        ]);
        $order->update(['dispatch_status' => $result->status === 'printed' ? 'acknowledged' : 'unknown']);
        if ($result->status !== 'printed') {
            $this->alerts->raise($order->merchant_id, 'printer', 'Print result unknown for '.$order->clover_order_id.'.', $order);
        }
    }

    public function reprint(PickupOrder $order, User $user): PrintEvent
    {
        $connection = CloverConnection::query()->where('merchant_id', $order->merchant_id)->firstOrFail();
        try {
            $result = $this->gateway->printOrder($connection, (string) $order->clover_order_id);
            $status = $result->status;
            $eventId = $result->eventId;
        } catch (CloverUncertainException) {
            $status = 'unknown';
            $eventId = null;
        }

        return PrintEvent::create([
            'pickup_order_id' => $order->id,
            'kind' => 'reprint',
            'clover_print_event_id' => $eventId,
            'status' => $status,
            'marked_copy' => true,
            'ticket' => $this->ticket($order),
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
    }

    public function customerMessage(PickupOrder $order): string
    {
        $when = $order->pickup_at->timezone($order->merchant->timezone)->format('D g:i A');
        $total = Money::cad((int) $order->quoted_total_cents);
        $reference = $order->idempotency_key;
        $lead = $order->dispatch_status === 'acknowledged'
            ? "Pickup {$reference} for {$order->pickup_name} at {$when}."
            : "Checking with the café. Reference {$reference} for {$order->pickup_name} at {$when}.";

        return $lead." Total {$total}. Pay at the counter.";
    }

    /**
     * @return array<string, mixed>
     */
    public function ticket(PickupOrder $order): array
    {
        $order->loadMissing('lines', 'merchant');

        return [
            'reference' => $order->idempotency_key,
            'clover_order_id' => $order->clover_order_id,
            'pickup_name' => $order->pickup_name,
            'pickup_at' => $order->pickup_at->timezone($order->merchant->timezone)->toIso8601String(),
            'pay_at_pickup' => true,
            'total_cents' => $order->quoted_total_cents,
            'lines' => $order->lines->map(fn (PickupOrderLine $line): array => [
                'name' => $line->name,
                'quantity' => $line->quantity,
                'modifiers' => collect($line->modifiers ?? [])->pluck('name')->all(),
            ])->all(),
        ];
    }
}
