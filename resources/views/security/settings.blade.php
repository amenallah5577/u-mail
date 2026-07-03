@extends('layouts.app')
@section('title', 'Account Security')
@section('content')
@php
    $totpEnabled = $user->mfaMethods->contains(fn ($method) => $method->type === 'totp' && $method->confirmed_at);
    $emailEnabled = $user->mfaMethods->contains(fn ($method) => $method->type === 'email' && $method->confirmed_at);
@endphp
<section class="security-page">
    <div class="page-heading"><div><p class="eyebrow">ACCOUNT PROTECTION</p><h1>Security settings</h1></div><span class="page-count"><b>{{ (int) $totpEnabled + (int) $emailEnabled }}</b> MFA methods</span></div>
    <div class="security-grid">
        <article class="panel security-card">
            <p class="eyebrow">AUTHENTICATOR APP</p><h2>{{ $totpEnabled ? 'Authenticator enabled' : 'Add an authenticator app' }}</h2>
            <p class="panel-note">Use Google Authenticator, Microsoft Authenticator, Authy, or another compatible app.</p>
            @if($pendingTotp)
                <img class="mfa-qr" src="{{ $qrDataUri }}" alt="Authenticator setup QR code">
                <code class="manual-secret">{{ $pendingTotp->secret_encrypted }}</code>
                <form method="POST" action="{{ route('security.mfa.totp.confirm') }}">@csrf
                    <label>Six-digit code<input name="code" required autocomplete="one-time-code"></label>
                    <button class="primary-button">Confirm authenticator</button>
                </form>
            @elseif($totpEnabled)
                <form method="POST" action="{{ route('security.mfa.disable') }}">@csrf @method('DELETE')<input type="hidden" name="type" value="totp"><button class="soft-button danger-button">Disable authenticator MFA</button></form>
                <form method="POST" action="{{ route('security.mfa.recovery') }}">@csrf<button class="soft-button">Generate new recovery codes</button></form>
            @else
                <form method="POST" action="{{ route('security.mfa.totp.start') }}">@csrf<button class="primary-button">Start authenticator setup</button></form>
            @endif
        </article>
        <article class="panel security-card">
            <p class="eyebrow">EMAIL CODE</p><h2>{{ $emailEnabled ? 'Email MFA enabled' : 'Add email-code MFA' }}</h2>
            <p class="panel-note">Receive a six-digit, single-use sign-in code at your private contact email.</p>
            @if($emailEnabled)
                <form method="POST" action="{{ route('security.mfa.disable') }}">@csrf @method('DELETE')<input type="hidden" name="type" value="email"><button class="soft-button danger-button">Disable email MFA</button></form>
            @else
                <form method="POST" action="{{ route('security.mfa.email.enable') }}">@csrf<button class="primary-button">Enable email MFA</button></form>
            @endif
        </article>
        <article class="panel security-card password-security-card">
            <div class="security-card-intro">
                <span class="setting-symbol"><x-icon name="lock" /></span>
                <div><p class="eyebrow">PASSWORD</p><h2>Change password</h2><p class="panel-note">Use at least 12 characters with uppercase and lowercase letters, numbers, and symbols. Other signed-in devices will be disconnected.</p></div>
            </div>
            <form method="POST" action="{{ route('security.password.update') }}">
                @csrf
                <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
                <label>New password<input type="password" name="password" required autocomplete="new-password"></label>
                <label>Confirm new password<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
                <button class="primary-button">Change password</button>
            </form>
        </article>
    </div>
    @if($recoveryCodes)
        <div class="panel recovery-codes">
            <p class="eyebrow">SAVE THESE NOW</p><h2>One-time recovery codes</h2><p class="panel-note">Each code works once. They will not be shown again.</p>
            <div>@foreach($recoveryCodes as $code)<code>{{ $code }}</code>@endforeach</div>
        </div>
    @endif
    <p class="security-confirm-note">Changing MFA settings requires a recent password confirmation. <a href="{{ route('password.confirm') }}">Confirm password</a></p>
</section>
@endsection
