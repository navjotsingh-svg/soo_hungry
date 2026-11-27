<?php

declare(strict_types=1);

namespace App\Domain\Cafe;

use App\Models\CloverConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HttpCloverGateway implements CloverGateway
{
    public function __construct(private readonly string $baseUrl) {}

    public function checkout(CloverConnection $connection, array $payload, int $quotedTotalCents): CloverCheckout
    {
        $response = $this->send($connection, 'post', '/atomic_order/checkouts', $payload);
        $total = (int) ($response['total'] ?? $quotedTotalCents);

        return new CloverCheckout(
            (int) ($response['subtotal'] ?? $quotedTotalCents),
            (int) ($response['tax'] ?? 0),
            $total,
        );
    }

    public function createOrder(CloverConnection $connection, array $payload, string $idempotencyKey): CloverOrderResult
    {
        $response = $this->send($connection, 'post', '/atomic_order/orders', $payload, $idempotencyKey);
        $id = (string) ($response['id'] ?? '');
        if ($id === '') {
            throw new CloverUncertainException('Clover did not return an order id.');
        }

        return new CloverOrderResult($id, $payload);
    }

    public function findByIdempotency(CloverConnection $connection, string $idempotencyKey): ?string
    {
        $response = $this->send($connection, 'get', '/orders?filter='.urlencode('title=WA-'.$idempotencyKey));
        $id = $response['elements'][0]['id'] ?? null;

        return $id ? (string) $id : null;
    }

    public function printOrder(CloverConnection $connection, string $cloverOrderId): CloverPrintResult
    {
        $response = $this->send($connection, 'post', '/print_event', [
            'orderRef' => ['id' => $cloverOrderId],
            'printer' => ['id' => $connection->default_printer_id],
        ]);

        return new CloverPrintResult(
            isset($response['id']) ? 'printed' : 'unknown',
            isset($response['id']) ? (string) $response['id'] : null,
        );
    }

    public function findCustomers(CloverConnection $connection, string $phoneE164): array
    {
        $response = $this->send($connection, 'get', '/customers?filter=phoneNumber='.urlencode($phoneE164));
        $matches = [];
        foreach ($response['elements'] ?? [] as $customer) {
            $matches[] = [
                'id' => (string) $customer['id'],
                'name' => trim(($customer['firstName'] ?? '').' '.($customer['lastName'] ?? '')),
                'phone' => $phoneE164,
            ];
        }

        return $matches;
    }

    public function createCustomer(CloverConnection $connection, string $name, string $phoneE164): string
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        $response = $this->send($connection, 'post', '/customers', [
            'firstName' => $first,
            'lastName' => $last,
            'phoneNumbers' => [['phoneNumber' => $phoneE164]],
        ]);

        return (string) $response['id'];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function send(CloverConnection $connection, string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): array
    {
        if ($connection->access_token === null) {
            throw new CloverUncertainException('Clover access token is not configured.');
        }

        $request = Http::withToken($connection->access_token)->timeout(15)->baseUrl($this->baseUrl.'/v3/merchants/'.$connection->clover_merchant_id);
        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        try {
            $response = $method === 'get'
                ? $request->get($path)
                : $request->post($path, $payload ?? []);
        } catch (ConnectionException $exception) {
            throw new CloverUncertainException($exception->getMessage(), 0, $exception);
        }

        if ($response->serverError() || $response->status() === 0) {
            throw new CloverUncertainException('Clover returned '.$response->status());
        }

        return $response->json() ?? [];
    }
}
