<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm contact email - U-Mail</title><link rel="icon" type="image/png" href="/favicon.png">@vite(['resources/css/app.css'])
</head>
<body class="auth-page employee-auth">
<main class="auth-shell">
    <section class="auth-visual">
        <div class="auth-brand"><img class="auth-logo" src="/images/utica-jendouba-logo.png" alt="UTICA Jendouba"><span><strong>UTICA</strong><small>Jendouba</small></span></div>
        <div class="auth-copy"><p class="eyebrow">CONFIRM YOUR EMAIL</p><h1>One quick<br><em>security check.</em></h1><p>Confirm that the contact email belongs to you before your request is sent for approval.</p></div>
        <div class="auth-foot"><span></span> Your code expires in 15 minutes</div>
    </section>
    <section class="auth-card">
        <div class="auth-card-kicker">U-MAIL · UTICA JENDOUBA</div>
        <p class="eyebrow">EMAIL CONFIRMATION</p><h2>Enter your code</h2>
        <p class="auth-card-intro">We sent a six-digit code to your contact email.</p>
        @if(config('mail.default') === 'smtp' && config('mail.mailers.smtp.host') === '127.0.0.1')
            <p class="auth-card-intro">For this local Wi-Fi pilot, the project owner can read the code from the private local inbox.</p>
        @endif
        @if(session('status'))<div class="flash success">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('register.verify.submit') }}">@csrf
            <label>Contact email<input type="email" name="email" value="{{ old('email', $email) }}" required autofocus></label>
            <label>Confirmation code<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></label>
            @include('auth.turnstile')
            <button class="primary-button">Confirm and submit request</button>
            <button class="auth-text-button" formaction="{{ route('register.verify.resend') }}" formnovalidate>Send a new code</button>
        </form>
        <div class="auth-links"><a href="{{ route('register') }}">← Change your details</a><a href="{{ route('login') }}">Employee sign in</a></div>
    </section>
</main>
</body>
</html>
