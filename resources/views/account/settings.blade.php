@extends('layouts.app')
@section('title', 'Account Settings')
@section('content')
<section class="account-page">
    <div class="page-heading">
        <div><p class="eyebrow">YOUR U-MAIL ACCOUNT</p><h1>Account settings</h1></div>
        <span class="page-count"><b>{{ $user->hasMfa() ? 'ON' : 'OFF' }}</b> MFA</span>
    </div>
    <div class="account-settings-grid">
        <a class="account-setting-card" href="{{ route('profile.show') }}">
            <x-user-avatar :user="$user" large />
            <div><p class="eyebrow">PROFILE</p><h2>Show profile</h2><span>Edit your name, phone number, and profile photo.</span></div>
            <x-icon name="arrow-right" class="setting-arrow" />
        </a>
        <a class="account-setting-card" href="{{ route('security.settings') }}">
            <span class="setting-symbol"><x-icon name="shield" /></span>
            <div><p class="eyebrow">PROTECTION</p><h2>Security</h2><span>Manage authenticator, email MFA, and recovery codes.</span></div>
            <x-icon name="arrow-right" class="setting-arrow" />
        </a>
        <a class="account-setting-card" href="{{ route('notifications.settings') }}">
            <span class="setting-symbol"><x-icon name="bell" /></span>
            <div><p class="eyebrow">ALERTS</p><h2>Mail notifications</h2><span>{{ $user->mail_notifications_enabled ? 'Enabled' : 'Off' }} · Get notified about new inbox mail.</span></div>
            <x-icon name="arrow-right" class="setting-arrow" />
        </a>
        <a class="account-setting-card" href="{{ route('appearance.settings') }}">
            <span class="setting-symbol"><x-icon name="moon" /></span>
            <div><p class="eyebrow">DISPLAY</p><h2>Appearance</h2><span>{{ ucfirst($user->theme_preference ?: 'light') }} · Choose light, dark, or system mode.</span></div>
            <x-icon name="arrow-right" class="setting-arrow" />
        </a>
        @if(! $user->isAdmin())
            <article class="account-setting-card account-toggle-card" data-tour-target="account-settings">
                <span class="setting-symbol"><x-icon name="sparkles" /></span>
                <div><p class="eyebrow">GET STARTED</p><h2>U-Mail tutorial</h2><span>Restart the guided tour for compose, inbox, search, and account settings.</span></div>
                <button class="soft-button small" type="button" data-onboarding-restart>Restart tutorial</button>
            </article>
        @endif
        <article class="account-setting-card account-summary-card">
            <span class="setting-symbol"><x-icon name="user" /></span>
            <div><p class="eyebrow">ACCOUNT</p><h2>{{ ucfirst($user->role) }} access</h2><span>{{ $user->mailAddress() }} · U-Mail address</span><span>{{ ucfirst($user->status) }} · Contact email kept private</span></div>
        </article>
    </div>
</section>
@endsection
