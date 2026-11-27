<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shared\Auditor;
use App\Domain\Tiffin\MealAssignment;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\TiffinMenuItem;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerDirectoryController extends StaffController
{
    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);
        $search = trim((string) $request->query('q', ''));
        $digits = preg_replace('/\D+/', '', $search) ?? '';
        $customers = Customer::query()
            ->where('merchant_id', $merchant->id)
            ->withCount('subscriptions')
            ->when($search !== '', function ($query) use ($search, $digits): void {
                $query->where(function ($inner) use ($search, $digits): void {
                    $inner->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');
                    if ($digits !== '') {
                        $inner->orWhere('phone_e164', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->orderByRaw('name is null')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('customers.index', compact('customers', 'search'));
    }

    public function create(): View
    {
        return view('customers.form', [
            'customer' => new Customer(['language' => 'en']),
            'plan' => null,
            'days' => TiffinMenuItem::DAYS,
        ]);
    }

    public function store(Request $request, Auditor $auditor, MealAssignment $assignment): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $data = $this->validated($request, $merchant->id);
        $schedule = $this->scheduleInput($request, $merchant->hasModule('tiffin'), false);
        $customer = Customer::create([
            'merchant_id' => $merchant->id,
            'name' => $data['name'],
            'phone_e164' => $data['phone'],
            'address' => $data['address'],
            'language' => $data['language'],
        ]);
        $auditor->record($merchant->id, $request->user(), 'customer.created', $customer, 'staff', [
            'name' => $customer->name,
            'phone' => PhoneNumber::mask($customer->phone_e164),
        ]);
        $scheduled = $this->applySchedule($request, $customer, $schedule, $assignment);

        $status = $customer->name.' is on the customer list.';
        if ($scheduled) {
            $status .= ' The tiffin schedule is on the packing list.';
        }

        return redirect()->route('customers.index')->with('status', $status);
    }

    public function edit(Request $request, Customer $customer): View
    {
        $this->guard($request, $customer);
        $customer->load('subscriptions');

        return view('customers.form', [
            'customer' => $customer,
            'plan' => $this->activePlan($customer),
            'days' => TiffinMenuItem::DAYS,
        ]);
    }

    public function update(Request $request, Customer $customer, Auditor $auditor, MealAssignment $assignment): RedirectResponse
    {
        $this->guard($request, $customer);
        $merchant = $this->merchant($request);
        $customer->load('subscriptions');
        $data = $this->validated($request, $merchant->id, $customer->id);
        $schedule = $this->scheduleInput($request, $merchant->hasModule('tiffin'), $this->activePlan($customer) !== null);
        $addressChanged = (string) $customer->address !== (string) $data['address'];
        $clearedApproval = $addressChanged && $customer->address_approved_at !== null;
        $customer->fill([
            'name' => $data['name'],
            'phone_e164' => $data['phone'],
            'address' => $data['address'],
            'language' => $data['language'],
        ]);
        if ($clearedApproval) {
            $customer->address_approved_at = null;
            $customer->address_approved_by = null;
        }
        $customer->save();
        $auditor->record($merchant->id, $request->user(), 'customer.updated', $customer, 'staff', [
            'name' => $customer->name,
            'phone' => PhoneNumber::mask($customer->phone_e164),
            'address_approval' => $clearedApproval ? 'cleared' : 'unchanged',
        ]);
        if ($this->applySchedule($request, $customer, $schedule, $assignment)) {
            $status = $customer->name.' was updated. The tiffin schedule is on the packing list.';
        } else {
            $status = $customer->name.' was updated.';
        }
        if ($clearedApproval) {
            $status .= ' The address needs owner approval again before a new plan uses it.';
        }

        return redirect()->route('customers.edit', $customer)->with('status', $status);
    }

    private function guard(Request $request, Customer $customer): void
    {
        abort_unless($customer->merchant_id === $this->merchant($request)->id, 404);
    }

    /**
     * @return array{name: string, phone: string, address: ?string, language: string}
     */
    private function validated(Request $request, int $merchantId, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:200'],
            'language' => ['required', Rule::in(['en', 'pa-Latn'])],
        ]);
        $phone = PhoneNumber::normalize($data['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'Enter a phone number with the area code.',
            ]);
        }
        $taken = Customer::query()
            ->where('merchant_id', $merchantId)
            ->where('phone_e164', $phone)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages([
                'phone' => 'This workspace already has a customer with that number.',
            ]);
        }

        return [
            'name' => trim($data['name']),
            'phone' => $phone,
            'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
            'language' => $data['language'],
        ];
    }

    /**
     * @return array{days: list<int>, breakfast: int, lunch: int, dinner: int, diet: ?string, day_meals: ?array}|null
     */
    private function scheduleInput(Request $request, bool $tiffin, bool $hasPlan): ?array
    {
        if (! $tiffin) {
            return null;
        }

        $data = $request->validate([
            'schedule_days' => ['array'],
            'schedule_days.*' => ['integer', Rule::in(array_keys(TiffinMenuItem::DAYS))],
            'breakfast' => ['nullable', 'integer', 'between:0,9'],
            'lunch' => ['nullable', 'integer', 'between:0,9'],
            'dinner' => ['nullable', 'integer', 'between:0,9'],
            'diet' => ['nullable', Rule::in(array_keys(TiffinMenuItem::DIETS))],
            'slots' => ['array'],
            'slots.*' => ['array'],
            'slots.*.*' => ['string', Rule::in(['breakfast', 'lunch', 'dinner'])],
            'slots_present' => ['nullable'],
        ]);
        $days = array_values(array_unique(array_map('intval', $data['schedule_days'] ?? [])));
        sort($days);
        $breakfast = (int) ($data['breakfast'] ?? 0);
        $lunch = (int) ($data['lunch'] ?? 0);
        $dinner = (int) ($data['dinner'] ?? 0);
        $diet = $data['diet'] ?? null;
        $dayMeals = null;
        if ($request->boolean('slots_present')) {
            $dayMeals = Subscription::dayMealsFromInput($data['slots'] ?? []);
            $shaped = Subscription::portionsForDayMeals($dayMeals, $breakfast, $lunch, $dinner);
            $days = $shaped['days'];
            $breakfast = $shaped['breakfast'];
            $lunch = $shaped['lunch'];
            $dinner = $shaped['dinner'];
        }
        $portions = $breakfast + $lunch + $dinner;
        if (! $hasPlan && $days === [] && $portions < 1) {
            return null;
        }
        if ($dayMeals === null && $days !== [] && $portions < 1) {
            throw ValidationException::withMessages([
                'lunch' => 'Set how many breakfast, lunch, or dinner portions to pack.',
            ]);
        }
        if ($dayMeals === null && $days === [] && $portions > 0) {
            throw ValidationException::withMessages([
                'schedule_days' => 'Choose at least one day.',
            ]);
        }
        if ($portions > 0 && $diet === null) {
            throw ValidationException::withMessages([
                'diet' => 'Choose veg or non-veg.',
            ]);
        }

        return [
            'days' => $days,
            'breakfast' => $breakfast,
            'lunch' => $lunch,
            'dinner' => $dinner,
            'diet' => $diet,
            'day_meals' => $dayMeals,
        ];
    }

    /**
     * @param  array{days: list<int>, breakfast: int, lunch: int, dinner: int, diet: ?string, day_meals: ?array}|null  $schedule
     */
    private function applySchedule(Request $request, Customer $customer, ?array $schedule, MealAssignment $assignment): bool
    {
        if ($schedule === null) {
            return false;
        }

        $assignment->assign($customer, $schedule['days'], $schedule['breakfast'], $schedule['lunch'], $schedule['dinner'], $schedule['diet'], $request->user(), $schedule['day_meals']);

        return true;
    }

    private function activePlan(Customer $customer): ?Subscription
    {
        return $customer->subscriptions
            ->sortByDesc('id')
            ->first(fn (Subscription $subscription): bool => in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Paused], true));
    }
}
