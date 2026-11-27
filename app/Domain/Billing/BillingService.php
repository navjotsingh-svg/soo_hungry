<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\Bill;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentTask;
use App\Models\Conversation;
use App\Models\Subscription;
use App\Models\User;
use App\Domain\Shared\Auditor;
use Illuminate\Support\Facades\DB;

final class BillingService
{
    public function __construct(private readonly Auditor $auditor) {}

    public function issue(
        Subscription $subscription,
        string $cycleKey,
        string $kind,
        string $issueDate,
        string $periodStart,
        string $periodEnd,
        string $dueDate,
        ?int $amountCents = null,
    ): Bill {
        return Bill::firstOrCreate(
            ['subscription_id' => $subscription->id, 'cycle_key' => $cycleKey],
            [
                'merchant_id' => $subscription->merchant_id,
                'customer_id' => $subscription->customer_id,
                'kind' => $kind,
                'issue_date' => $issueDate,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'due_date' => $dueDate,
                'amount_cents' => $amountCents ?? $subscription->plan_price_cents,
                'status' => 'issued',
            ],
        );
    }

    public function receive(Bill $bill, User $owner, int $amountCents, string $method, string $receivedDate, ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($bill, $owner, $amountCents, $method, $receivedDate, $notes): Payment {
            $payment = Payment::create([
                'merchant_id' => $bill->merchant_id,
                'bill_id' => $bill->id,
                'customer_id' => $bill->customer_id,
                'received_date' => $receivedDate,
                'method' => $method,
                'amount_cents' => $amountCents,
                'verified_by' => $owner->id,
                'notes' => $notes,
            ]);
            $this->refresh($bill);
            $this->auditor->record($bill->merchant_id, $owner, 'payment.verified', $payment, 'staff', [
                'bill_id' => $bill->id,
                'amount_cents' => $amountCents,
                'method' => $method,
            ]);

            return $payment;
        });
    }

    public function reverse(Payment $payment, User $owner, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $owner, $reason): Payment {
            $reversal = Payment::create([
                'merchant_id' => $payment->merchant_id,
                'bill_id' => $payment->bill_id,
                'customer_id' => $payment->customer_id,
                'received_date' => $payment->received_date->toDateString(),
                'method' => $payment->method,
                'amount_cents' => -$payment->amount_cents,
                'verified_by' => $owner->id,
                'reversal_of_id' => $payment->id,
                'notes' => $reason,
            ]);
            $this->refresh($payment->bill);
            $this->auditor->record($payment->merchant_id, $owner, 'payment.reversed', $reversal, 'staff', [
                'payment_id' => $payment->id,
            ]);

            return $reversal;
        });
    }

    public function claim(Customer $customer, ?Bill $bill, ?Conversation $conversation): PaymentTask
    {
        return PaymentTask::create([
            'merchant_id' => $customer->merchant_id,
            'customer_id' => $customer->id,
            'bill_id' => $bill?->id,
            'conversation_id' => $conversation?->id,
            'status' => 'open',
            'note' => 'Customer said they paid.',
        ]);
    }

    public function refresh(Bill $bill): void
    {
        $bill->refresh();
        $balance = $bill->balanceCents();
        $bill->update([
            'status' => match (true) {
                $balance <= 0 => 'paid',
                $balance < $bill->amount_cents => 'partial',
                default => 'issued',
            },
        ]);

        if ($balance <= 0) {
            $bill->payments()->get();
            \App\Models\PaymentReminder::query()
                ->where('bill_id', $bill->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);
        }
    }
}
