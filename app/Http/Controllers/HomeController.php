<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $merchants = Schema::hasTable('merchants')
            ? Merchant::query()->with('whatsappAccount', 'modules')->orderBy('name')->get()
            : collect();

        return view('home', [
            'merchants' => $merchants,
            'links' => $merchants->mapWithKeys(fn (Merchant $merchant): array => [
                $merchant->id => PhoneNumber::waMe($merchant->whatsappAccount?->display_number),
            ]),
        ]);
    }
}
