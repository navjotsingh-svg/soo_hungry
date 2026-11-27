<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Support\PhoneNumber;
use Illuminate\View\View;

class ChatLinkController extends Controller
{
    public function __invoke(Merchant $merchant): View
    {
        return view('chat-link', [
            'merchant' => $merchant->load('whatsappAccount', 'modules'),
            'link' => PhoneNumber::waMe($merchant->whatsappAccount?->display_number),
        ]);
    }
}
