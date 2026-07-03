@extends('layouts.app')
@section('title', 'Show Profile')
@section('content')
<section class="account-page profile-page">
    <div class="page-heading">
        <div><p class="eyebrow">PERSONAL INFORMATION</p><h1>Show profile</h1></div>
        <a class="soft-button" href="{{ route('account.settings') }}">Account settings</a>
    </div>
    <div class="profile-grid">
        <aside class="panel profile-preview">
            <x-user-avatar :user="$user" large />
            <h2>{{ $user->name }}</h2>
            <p>{{ $user->mailAddress() }}</p>
            <span class="status {{ $user->status }}">{{ ucfirst($user->status) }} {{ $user->role }}</span>
            @if($user->phone)<small>{{ $user->phone }}</small>@endif
        </aside>
        <form class="panel profile-form" method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PATCH')
            <p class="eyebrow">EDIT PROFILE</p><h2>Your information</h2>
            <label>Full name<input name="name" value="{{ old('name', $user->name) }}" required maxlength="255"></label>
            <label>U-Mail address<input value="{{ $user->mailAddress() }}" disabled><small>This is your main address for sending, receiving, and signing in.</small></label>
            <label>Contact email<input value="{{ $user->email }}" disabled><small>This private address can also be used to sign in and receive account security messages.</small></label>
            <label>Phone number<input type="tel" name="phone" value="{{ old('phone', $user->phone) }}" maxlength="40" placeholder="+216 00 000 000"></label>
            <label class="photo-field">Profile photo
                <span class="photo-upload">
                    <x-icon name="camera" />
                    <span><b>Choose a profile photo</b><small data-photo-file-name>JPG, PNG, or WebP. Maximum 2 MB.</small></span>
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-photo-input>
                </span>
            </label>
            <button class="primary-button">Save profile</button>
        </form>
    </div>
    @if($user->profile_photo_path)
        <form class="remove-photo-form" method="POST" action="{{ route('profile.photo.destroy') }}">
            @csrf
            @method('DELETE')
            <button class="soft-button danger-button" data-confirm="Remove your profile photo?">Remove profile photo</button>
        </form>
    @endif
</section>
@endsection
