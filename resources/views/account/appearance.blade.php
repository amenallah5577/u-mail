@extends('layouts.app')
@section('title', 'Appearance')
@section('content')
@php($themePreference = $user->theme_preference ?: 'light')
<section class="account-page appearance-page">
    <div class="page-heading">
        <div><p class="eyebrow">ACCOUNT SETTINGS</p><h1>Appearance</h1></div>
        <span class="page-count"><b data-appearance-heading>{{ strtoupper($themePreference) }}</b> Theme</span>
    </div>

    <form class="appearance-grid" method="POST" action="{{ route('appearance.update') }}" data-appearance-form data-saved-theme="{{ $themePreference }}">
        @csrf
        @method('PATCH')
        @foreach([
            'light' => ['Light', 'Use the bright U-Mail workspace.'],
            'dark' => ['Dark', 'Use darker signed-in pages with UTICA accents.'],
            'system' => ['System', 'Follow this computer automatically.'],
        ] as $value => [$label, $description])
            <label class="appearance-card {{ $themePreference === $value ? 'selected' : '' }}" data-appearance-card>
                <input type="radio" name="theme_preference" value="{{ $value }}" @checked($themePreference === $value)>
                <span class="appearance-preview {{ $value }}">
                    <i></i><b></b><b></b>
                </span>
                <span>
                    <strong>{{ $label }}</strong>
                    <small>{{ $description }}</small>
                </span>
            </label>
        @endforeach
        <footer>
            <span class="appearance-save-hint" data-appearance-hint>Current theme is saved.</span>
            <a class="soft-button" href="{{ route('account.settings') }}">Back to settings</a>
            <button class="primary-button small" type="submit">Save appearance</button>
        </footer>
    </form>
</section>
@endsection
