<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppearanceController extends Controller
{
    public function index(Request $request)
    {
        return view('account.appearance', ['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'theme_preference' => ['required', Rule::in(['light', 'dark', 'system'])],
        ]);

        $request->user()->update($data);

        return redirect()->route('appearance.settings')->with('status', 'Appearance updated.');
    }
}
