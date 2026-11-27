<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Http\Request;

abstract class StaffController extends Controller
{
    protected function merchant(Request $request): Merchant
    {
        return $request->attributes->get('merchant');
    }
}
