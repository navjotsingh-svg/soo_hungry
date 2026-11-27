<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shared\Auditor;
use App\Models\CatalogItem;
use App\Models\CloverConnection;
use App\Models\Merchant;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MenuController extends StaffController
{
    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->hasModule('cafe'), 404);
        $items = $merchant->cloverConnection?->items()->orderBy('name')->get() ?? collect();

        return view('menu.index', compact('items'));
    }

    public function create(Request $request): View
    {
        abort_unless($this->merchant($request)->hasModule('cafe'), 404);

        return view('menu.form', [
            'item' => new CatalogItem(['tax_rate_bps' => 500, 'available' => true]),
            'groups' => $this->blankGroups([]),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->hasModule('cafe'), 404);
        $data = $this->validated($request);
        $connection = $this->connection($merchant);
        $item = $connection->items()->create([
            'clover_item_id' => 'local-'.Str::lower((string) Str::ulid()),
            'name' => $data['name'],
            'price_cents' => $data['price_cents'],
            'tax_rate_bps' => $data['tax_rate_bps'],
            'available' => $data['available'],
            'modifier_groups' => $data['groups'],
            'synced_at' => now(),
        ]);
        $connection->update(['catalog_synced_at' => now()]);
        $auditor->record($merchant->id, $request->user(), 'menu.created', $item, 'staff', [
            'name' => $item->name,
            'price' => Money::cad($item->price_cents),
        ]);

        return redirect()->route('menu.index')->with('status', $item->name.' is on the menu.');
    }

    public function edit(Request $request, CatalogItem $catalogItem): View
    {
        $this->guard($request, $catalogItem);

        return view('menu.form', [
            'item' => $catalogItem,
            'groups' => $this->blankGroups($catalogItem->modifier_groups ?? []),
        ]);
    }

    public function update(Request $request, CatalogItem $catalogItem, Auditor $auditor): RedirectResponse
    {
        $this->guard($request, $catalogItem);
        $merchant = $this->merchant($request);
        $data = $this->validated($request);
        $catalogItem->update([
            'name' => $data['name'],
            'price_cents' => $data['price_cents'],
            'tax_rate_bps' => $data['tax_rate_bps'],
            'available' => $data['available'],
            'modifier_groups' => $data['groups'],
            'synced_at' => now(),
        ]);
        $catalogItem->connection?->update(['catalog_synced_at' => now()]);
        $auditor->record($merchant->id, $request->user(), 'menu.updated', $catalogItem, 'staff', [
            'name' => $catalogItem->name,
            'price' => Money::cad((int) $catalogItem->price_cents),
        ]);

        return redirect()->route('menu.edit', $catalogItem)->with('status', $catalogItem->name.' was updated. New quotes use this price.');
    }

    private function guard(Request $request, CatalogItem $item): void
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->hasModule('cafe'), 404);
        abort_unless($item->connection?->merchant_id === $merchant->id, 404);
    }

    private function connection(Merchant $merchant): CloverConnection
    {
        $existing = $merchant->cloverConnection;
        if ($existing !== null) {
            return $existing;
        }
        $location = $merchant->locations()->first();
        abort_unless($location !== null, 404);

        return $merchant->cloverConnection()->create([
            'location_id' => $location->id,
            'clover_merchant_id' => 'local-'.$merchant->id,
            'environment' => 'sandbox',
            'print_mode' => 'explicit_print',
            'ordering_paused' => false,
            'can_create_customers' => false,
            'catalog_synced_at' => now(),
            'sync_max_age_minutes' => 360,
        ]);
    }

    /**
     * @return array{name: string, price_cents: int, tax_rate_bps: int, available: bool, groups: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:30'],
            'groups' => ['nullable', 'array'],
            'groups.*.name' => ['nullable', 'string', 'max:80'],
            'groups.*.required' => ['nullable', 'boolean'],
            'groups.*.options' => ['nullable', 'string', 'max:2000'],
        ]);

        return [
            'name' => trim($data['name']),
            'price_cents' => (int) round(((float) $data['price']) * 100),
            'tax_rate_bps' => (int) round(((float) $data['tax_percent']) * 100),
            'available' => $request->boolean('available'),
            'groups' => $this->groups($data['groups'] ?? []),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $input
     * @return list<array<string, mixed>>
     */
    private function groups(array $input): array
    {
        $groups = [];
        $errors = [];
        foreach (array_values($input) as $index => $group) {
            $name = trim((string) ($group['name'] ?? ''));
            $lines = preg_split('/\r\n|\r|\n/', (string) ($group['options'] ?? '')) ?: [];
            $lines = array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
            if ($name === '' && $lines === []) {
                continue;
            }
            if ($name === '') {
                $errors["groups.$index.name"] = 'Name this option group.';
                continue;
            }
            if ($lines === []) {
                $errors["groups.$index.options"] = 'Add at least one option, as Name | price.';
                continue;
            }
            $options = [];
            foreach ($lines as $lineIndex => $line) {
                [$label, $price] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '0');
                if ($label === '' || ! is_numeric($price) || (float) $price < 0) {
                    $errors["groups.$index.options"] = 'Each option needs a name and a price, such as Large | 1.00.';
                    break;
                }
                $id = Str::slug($label) ?: 'option';
                if (collect($options)->contains('id', $id)) {
                    $id .= '-'.($lineIndex + 1);
                }
                $options[] = [
                    'id' => $id,
                    'name' => $label,
                    'price_cents' => (int) round(((float) $price) * 100),
                ];
            }
            if (isset($errors["groups.$index.options"])) {
                continue;
            }
            $groupId = Str::slug($name) ?: 'group-'.($index + 1);
            if (collect($groups)->contains('id', $groupId)) {
                $groupId .= '-'.($index + 1);
            }
            $groups[] = [
                'id' => $groupId,
                'name' => $name,
                'required' => filter_var($group['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'options' => $options,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function blankGroups(array $groups): array
    {
        while (count($groups) < 2) {
            $groups[] = ['name' => '', 'required' => false, 'options' => []];
        }

        return $groups;
    }
}
