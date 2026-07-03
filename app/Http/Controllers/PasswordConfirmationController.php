<?php

namespace App\Http\Controllers;

use App\Services\SecurityAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordConfirmationController extends Controller
{
    public function show()
    {
        return view('auth.confirm-password');
    }

    public function confirm(Request $request, SecurityAuditService $audit)
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            $audit->record('password_confirmation.failed', $request->user(), request: $request);
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        $request->session()->put('auth.password_confirmed_at', time());
        $audit->record('password_confirmation.succeeded', $request->user(), request: $request);

        $destination = $request->user()->isOwner()
            ? route('owner.credentials')
            : ($request->user()->isAdmin() ? route('admin.employees') : route('security.settings'));

        return redirect()->intended($destination);
    }
}
