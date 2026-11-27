<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Cafe\CafeOrderService;
use App\Models\CatalogItem;
use App\Models\MerchantUser;
use App\Models\PickupOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CafeOrderController extends StaffController
{
    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);
        if (! $merchant->hasModule('cafe')) {
            $cafes = MerchantUser::query()
                ->where('user_id', $request->user()->id)
                ->with('merchant')
                ->get()
                ->filter(fn (MerchantUser $membership): bool => $membership->merchant->hasModule('cafe'))
                ->values();

            return view('cafe.unavailable', compact('cafes'));
        }
        $orders = PickupOrder::query()->where('merchant_id', $merchant->id)->with('customer')->latest('id')->get();
        $items = $merchant->cloverConnection?->items ?? collect();

        return view('cafe.index', compact('orders', 'items'));
    }

    public function show(Request $request, PickupOrder $order): View
    {
        abort_unless($order->merchant_id === $this->merchant($request)->id, 404);
        $order->load('customer', 'lines', 'printEvents');

        return view('cafe.show', compact('order'));
    }

    public function ready(Request $request, PickupOrder $order): RedirectResponse
    {
        abort_unless($order->merchant_id === $this->merchant($request)->id, 404);
        $order->update(['pickup_status' => 'ready']);

        return back()->with('status', 'Marked ready for pickup.');
    }

    public function collected(Request $request, PickupOrder $order): RedirectResponse
    {
        abort_unless($order->merchant_id === $this->merchant($request)->id, 404);
        $order->update(['pickup_status' => 'collected']);

        return back()->with('status', 'Marked collected.');
    }

    public function reprint(Request $request, PickupOrder $order, CafeOrderService $orders): RedirectResponse
    {
        abort_unless($order->merchant_id === $this->merchant($request)->id, 404);
        $orders->reprint($order, $request->user());

        return back()->with('status', 'Reprint sent as a marked copy.');
    }

    public function reconcile(Request $request, PickupOrder $order, CafeOrderService $orders): RedirectResponse
    {
        abort_unless($order->merchant_id === $this->merchant($request)->id, 404);
        $orders->reconcile($order);

        return back()->with('status', 'Checked the order with Clover.');
    }

    public function availability(Request $request, CatalogItem $item): RedirectResponse
    {
        $connection = $item->connection;
        abort_unless($connection && $connection->merchant_id === $this->merchant($request)->id, 404);
        $item->update(['available' => ! $item->available]);

        return back()->with('status', $item->available ? $item->name.' is available.' : $item->name.' is sold out.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $connection = $this->merchant($request)->cloverConnection;
        abort_unless($connection, 404);
        $connection->update(['catalog_synced_at' => now()]);

        return back()->with('status', 'Menu marked as synced.');
    }
}
