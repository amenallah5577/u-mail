@php
    $organizerName = $event->owner?->name ?: 'Former user';
    $organizerMail = $event->owner?->public_email ?: 'U-Mail account';
    $activeUser = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->title }} - U-Mail Invitation</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    @vite(['resources/css/app.css'])
</head>
<body class="calendar-invitation-page">
<main class="calendar-invitation-shell">
    <section class="calendar-invitation-hero">
        <a class="auth-brand" href="{{ route('login') }}">
            <img class="auth-logo" src="/images/utica-jendouba-logo.png" alt="UTICA Jendouba">
            <span><strong>U-Mail</strong><small>Invitation</small></span>
        </a>
        <div>
            <p class="eyebrow">PERSONAL EVENT INVITATION</p>
            <h1>{{ $event->title }}</h1>
        </div>
        <footer><span></span> Shared by {{ $organizerName }}</footer>
    </section>
    <section class="calendar-invitation-card">
        @if(session('status'))<div class="flash success">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
        <span class="calendar-scope-badge personal">Invitation</span>
        <h2>{{ $event->title }}</h2>
        <dl class="calendar-invitation-details">
            @if($event->location)
                <div><dt>Where</dt><dd>{{ $event->location }}</dd></div>
            @endif
            <div><dt>Organizer</dt><dd>{{ $organizerName }} · {{ $organizerMail }}</dd></div>
        </dl>
        @if($event->notes)
            <div class="calendar-notes">{{ $event->notes }}</div>
        @endif
        <div class="calendar-invitation-actions">
            @if($activeUser?->isActive() && $activeUser->id !== $event->owner_id)
                <form method="POST" action="{{ route('calendar.invitations.accept', $token) }}">
                    @csrf
                    <button class="primary-button" type="submit">Accept into my calendar</button>
                </form>
            @elseif($activeUser?->id === $event->owner_id)
                <a class="primary-button" href="{{ route('calendar.index', ['month' => $event->starts_at->format('Y-m'), 'filter' => 'personal']) }}">Open in my calendar</a>
            @else
                <a class="primary-button" href="{{ route('login') }}">Sign in to accept</a>
                <p>Visitors can view this invitation. A U-Mail account is required to add it to a calendar.</p>
            @endif
        </div>
    </section>
</main>
</body>
</html>
