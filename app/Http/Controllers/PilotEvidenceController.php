<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\InboundMessage;
use App\Models\IntegrationAlert;
use App\Models\PaymentReminder;
use App\Models\PickupOrder;
use App\Models\PrintEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PilotEvidenceController extends StaffController
{
    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);
        $orderQuery = PickupOrder::query()->where('merchant_id', $merchant->id);
        $orderCount = (clone $orderQuery)->count();
        $uncertainOrders = (clone $orderQuery)->where(function ($query): void {
            $query->whereIn('submission_status', ['unknown', 'pending'])
                ->orWhere('dispatch_status', 'unknown');
        })->count();
        $orders = (clone $orderQuery)->latest('id')->limit(15)->get();
        $reprints = PrintEvent::query()
            ->where('marked_copy', true)
            ->whereHas('order', fn ($query) => $query->where('merchant_id', $merchant->id))
            ->with('order')
            ->latest('id')
            ->limit(15)
            ->get();
        $alerts = IntegrationAlert::query()
            ->where('merchant_id', $merchant->id)
            ->latest('id')
            ->limit(15)
            ->get();
        $corrections = AuditEvent::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('action', ['meal.skipped', 'meal.fulfilled', 'tiffin.applied'])
            ->latest('id')
            ->limit(15)
            ->get();
        $takeovers = AuditEvent::query()
            ->where('merchant_id', $merchant->id)
            ->where('action', 'conversation.taken_over')
            ->latest('id')
            ->limit(15)
            ->get();
        $handoffs = InboundMessage::query()
            ->where('merchant_id', $merchant->id)
            ->where('result', 'handoff')
            ->latest('id')
            ->limit(15)
            ->get();
        $failedReminders = PaymentReminder::query()
            ->where('merchant_id', $merchant->id)
            ->where(function ($query): void {
                $query->where('status', 'failed')
                    ->orWhereHas('notification', fn ($inner) => $inner->where('status', 'failed'));
            })
            ->with('bill.customer', 'notification')
            ->latest('id')
            ->limit(15)
            ->get();
        $openChats = Conversation::query()
            ->where('merchant_id', $merchant->id)
            ->where('status', 'staff')
            ->count();

        return view('evidence.index', [
            'orders' => $orders,
            'orderCount' => $orderCount,
            'reprints' => $reprints,
            'alerts' => $alerts,
            'corrections' => $corrections,
            'takeovers' => $takeovers,
            'handoffs' => $handoffs,
            'failedReminders' => $failedReminders,
            'openChats' => $openChats,
            'uncertainOrders' => $uncertainOrders,
        ]);
    }
}
