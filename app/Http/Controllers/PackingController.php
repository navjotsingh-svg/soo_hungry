<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Messaging\ActionConfirmer;
use App\Domain\Tiffin\MealAssignment;
use App\Domain\Tiffin\MealFulfillment;
use App\Enums\ActionStatus;
use App\Models\Customer;
use App\Models\ProposedAction;
use App\Models\ScheduleEntry;
use App\Models\Subscription;
use App\Models\TiffinMenuItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PackingController extends StaffController
{
    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->hasModule('tiffin'), 404);
        $now = CarbonImmutable::now($merchant->timezone);
        $today = $now->toDateString();
        $tomorrow = $now->addDay()->toDateString();
        $date = $request->query('date', $tomorrow);
        $slot = $request->query('slot');
        if (! in_array($slot, ['breakfast', 'lunch', 'dinner'], true)) {
            $slot = null;
        }
        $entries = ScheduleEntry::query()
            ->where('merchant_id', $merchant->id)
            ->whereDate('service_date', $date)
            ->with('customer', 'subscription')
            ->orderByRaw("case slot when 'breakfast' then 1 when 'lunch' then 2 else 3 end")
            ->orderBy('customer_id')
            ->get();
        $pending = ProposedAction::query()
            ->where('merchant_id', $merchant->id)
            ->where('status', ActionStatus::Pending)
            ->where('module', 'tiffin')
            ->get()
            ->filter(function (ProposedAction $action) use ($date): bool {
                foreach (array_merge($action->payload['apply'] ?? [], $action->payload['late'] ?? []) as $meal) {
                    if (($meal['date'] ?? null) === $date) {
                        return true;
                    }
                }

                return false;
            });

        $day = CarbonImmutable::parse($date, $merchant->timezone)->dayOfWeekIso;
        $menu = TiffinMenuItem::query()
            ->where('merchant_id', $merchant->id)
            ->where('day_of_week', $day)
            ->orderByRaw("case slot when 'breakfast' then 1 when 'lunch' then 2 else 3 end")
            ->orderBy('diet')
            ->orderBy('name')
            ->get();

        $customers = Customer::query()
            ->where('merchant_id', $merchant->id)
            ->with(['subscriptions' => fn ($query) => $query->orderByDesc('id')])
            ->orderBy('name')
            ->get();

        return view('tiffin.packing', [
            'date' => $date,
            'today' => $today,
            'tomorrow' => $tomorrow,
            'slot' => $slot,
            'entries' => $entries,
            'pending' => $pending,
            'canApprove' => $request->attributes->get('membership')->role->value === 'owner',
            'menu' => $menu,
            'weekday' => TiffinMenuItem::DAYS[$day],
            'customers' => $customers,
            'days' => TiffinMenuItem::DAYS,
        ]);
    }

    public function fulfill(Request $request, ScheduleEntry $entry, MealFulfillment $fulfillment): RedirectResponse
    {
        abort_unless($entry->merchant_id === $this->merchant($request)->id, 404);
        $wasScheduled = $entry->status === 'scheduled';
        $updated = $fulfillment->fulfill($entry, $request->user());

        return $this->backToDate($request, $updated, $wasScheduled
            ? $updated->customer->displayName().' '.ucfirst($updated->slot).' is packed.'
            : $updated->customer->displayName().' '.ucfirst($updated->slot).' is already '.str_replace('_', ' ', $updated->status).'.');
    }

    public function skip(Request $request, ScheduleEntry $entry, MealAssignment $assignment): RedirectResponse
    {
        abort_unless($entry->merchant_id === $this->merchant($request)->id, 404);
        $wasScheduled = $entry->status === 'scheduled';
        $updated = $assignment->skip($entry, $request->user());
        $label = $updated->customer->displayName().' '.ucfirst($updated->slot);

        return $this->backToDate($request, $updated, $wasScheduled
            ? $label.' is cancelled. The credit stays on the plan.'
            : $label.' is already '.str_replace('_', ' ', $updated->status).'.');
    }

    public function assign(Request $request, Customer $customer, MealAssignment $assignment): RedirectResponse
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->hasModule('tiffin'), 404);
        abort_unless($customer->merchant_id === $merchant->id, 404);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'days' => ['array'],
            'days.*' => ['integer', Rule::in(array_keys(TiffinMenuItem::DAYS))],
            'breakfast' => ['nullable', 'integer', 'between:0,9'],
            'lunch' => ['required', 'integer', 'between:0,9'],
            'dinner' => ['required', 'integer', 'between:0,9'],
            'diet' => ['nullable', Rule::in(array_keys(TiffinMenuItem::DIETS))],
            'slots' => ['array'],
            'slots.*' => ['array'],
            'slots.*.*' => ['string', Rule::in(['breakfast', 'lunch', 'dinner'])],
            'slots_present' => ['nullable'],
        ]);
        $breakfast = (int) ($data['breakfast'] ?? 0);
        $lunch = (int) $data['lunch'];
        $dinner = (int) $data['dinner'];
        $dayMeals = null;
        $days = array_map('intval', $data['days'] ?? []);
        if ($request->boolean('slots_present')) {
            $dayMeals = Subscription::dayMealsFromInput($data['slots'] ?? []);
            $shaped = Subscription::portionsForDayMeals($dayMeals, $breakfast, $lunch, $dinner);
            $days = $shaped['days'];
            $breakfast = $shaped['breakfast'];
            $lunch = $shaped['lunch'];
            $dinner = $shaped['dinner'];
        }
        if ($breakfast + $lunch + $dinner > 0 && empty($data['diet'])) {
            throw ValidationException::withMessages([
                'diet' => 'Choose veg or non-veg.',
            ]);
        }
        $assignment->assign(
            $customer,
            $days,
            $breakfast,
            $lunch,
            $dinner,
            $data['diet'] ?? null,
            $request->user(),
            $dayMeals,
        );

        return redirect()
            ->to(route('packing.index', $this->listQuery($data['date'], $request)).'#assignments')
            ->with('status', $customer->displayName().' is set for those days.');
    }

    private function backToDate(Request $request, ScheduleEntry $entry, string $message): RedirectResponse
    {
        return redirect()
            ->to(route('packing.index', $this->listQuery($entry->service_date->toDateString(), $request)).'#list')
            ->with('status', $message);
    }

    /** @return array{date: string, slot?: string} */
    private function listQuery(string $date, Request $request): array
    {
        $query = ['date' => $date];
        $slot = $request->input('slot');
        if (in_array($slot, ['breakfast', 'lunch', 'dinner'], true)) {
            $query['slot'] = $slot;
        }

        return $query;
    }

    public function approveLate(Request $request, ProposedAction $action, ActionConfirmer $confirmer): RedirectResponse
    {
        abort_unless($action->merchant_id === $this->merchant($request)->id, 404);
        $confirmer->approveLate($action, $request->user());

        return back()->with('status', 'Late exception approved.');
    }
}
