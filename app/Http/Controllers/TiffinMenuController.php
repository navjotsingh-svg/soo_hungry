<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shared\Auditor;
use App\Models\TiffinMenuItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TiffinMenuController extends StaffController
{
    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->hasModule('tiffin'), 404);
        $items = TiffinMenuItem::query()
            ->where('merchant_id', $merchant->id)
            ->orderBy('day_of_week')
            ->orderByRaw("case slot when 'breakfast' then 1 when 'lunch' then 2 else 3 end")
            ->orderBy('diet')
            ->orderBy('name')
            ->get();

        return view('tiffin.menu', [
            'items' => $items,
            'days' => TiffinMenuItem::DAYS,
            'slots' => TiffinMenuItem::SLOTS,
            'diets' => TiffinMenuItem::DIETS,
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->hasModule('tiffin'), 404);
        $data = $request->validate([
            'day_of_week' => ['required', 'integer', Rule::in(array_keys(TiffinMenuItem::DAYS))],
            'slot' => ['required', Rule::in(array_keys(TiffinMenuItem::SLOTS))],
            'diet' => ['required', Rule::in(array_keys(TiffinMenuItem::DIETS))],
            'name' => ['required', 'string', 'max:120'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ]);
        $photoPath = $request->file('photo')?->store('tiffin-menu/'.$merchant->id, 'public');
        $item = TiffinMenuItem::create([
            'merchant_id' => $merchant->id,
            'day_of_week' => (int) $data['day_of_week'],
            'slot' => $data['slot'],
            'diet' => $data['diet'],
            'name' => trim($data['name']),
            'photo_path' => $photoPath,
        ]);
        $auditor->record($merchant->id, $request->user(), 'tiffin_menu.created', $item, 'staff', [
            'day' => TiffinMenuItem::DAYS[$item->day_of_week],
            'slot' => $item->slot,
            'diet' => $item->diet,
            'name' => $item->name,
        ]);

        return redirect()->route('tiffin-menu.index')->with('status', $item->name.' is on the '.TiffinMenuItem::DAYS[$item->day_of_week].' '.TiffinMenuItem::SLOTS[$item->slot].' menu.');
    }

    public function destroy(Request $request, TiffinMenuItem $tiffinMenuItem, Auditor $auditor): RedirectResponse
    {
        $merchant = $this->merchant($request);
        abort_unless($merchant->hasModule('tiffin'), 404);
        abort_unless($tiffinMenuItem->merchant_id === $merchant->id, 404);
        $name = $tiffinMenuItem->name;
        $photoPath = $tiffinMenuItem->photo_path;
        $auditor->record($merchant->id, $request->user(), 'tiffin_menu.removed', $tiffinMenuItem, 'staff', [
            'name' => $name,
        ]);
        $tiffinMenuItem->delete();
        if (is_string($photoPath) && $photoPath !== '') {
            Storage::disk('public')->delete($photoPath);
        }

        return redirect()->route('tiffin-menu.index')->with('status', $name.' was removed from the weekly menu.');
    }
}
