<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\IntegrationAlert;
use App\Models\OutboundNotification;
use App\Models\PickupOrder;
use App\Models\ScheduleEntry;
use App\Models\StaffHandoff;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends StaffController
{
    public function __invoke(Request $request): View
    {
        $merchant = $this->merchant($request);
        $tomorrow = CarbonImmutable::now($merchant->timezone)->addDay()->toDateString();
        $financials = $request->attributes->get('membership')->role->seesFinancials();

        return view('dashboard', [
            'merchant' => $merchant->load('modules', 'whatsappAccount', 'cutoff', 'cloverConnection'),
            'handoffs' => StaffHandoff::query()->where('merchant_id', $merchant->id)->where('status', 'open')->count(),
            'alerts' => IntegrationAlert::query()->where('merchant_id', $merchant->id)->where('status', 'open')->latest('id')->limit(5)->get(),
            'failedSends' => OutboundNotification::query()->where('merchant_id', $merchant->id)->where('status', 'failed')->count(),
            'tomorrowPortions' => $merchant->hasModule('tiffin')
                ? (int) ScheduleEntry::query()->where('merchant_id', $merchant->id)->whereDate('service_date', $tomorrow)->where('status', 'scheduled')->sum('quantity')
                : null,
            'tomorrow' => $tomorrow,
            'outstanding' => $financials && $merchant->hasModule('tiffin')
                ? Bill::query()->where('merchant_id', $merchant->id)->get()->sum(fn (Bill $bill): int => max(0, $bill->balanceCents()))
                : null,
            'openOrders' => $merchant->hasModule('cafe')
                ? PickupOrder::query()->where('merchant_id', $merchant->id)->where('pickup_status', '!=', 'collected')->count()
                : null,
        ]);
    }
}
