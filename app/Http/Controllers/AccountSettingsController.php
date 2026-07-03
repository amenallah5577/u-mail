<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AccountSettingsController extends Controller
{
    public function index(Request $request)
    {
        return view('account.settings', [
            'user' => $request->user(),
        ]);
    }
}
