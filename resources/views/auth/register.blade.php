<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request an account - U-Mail</title><link rel="icon" type="image/png" href="/favicon.png">@vite(['resources/css/app.css'])
</head>
<body class="auth-page employee-auth">
<main class="auth-shell">
    <section class="auth-visual">
        <div class="auth-brand"><img class="auth-logo" src="/images/utica-jendouba-logo.png" alt="UTICA Jendouba"><span><strong>UTICA</strong><small>Jendouba</small></span></div>
        <div class="auth-copy"><p class="eyebrow">JOIN U-MAIL</p><h1>Request your<br><em>work mailbox.</em></h1><p>Confirm your contact email, then an administrator reviews your request. After approval, your new address and temporary password arrive by email.</p></div>
        <div class="auth-foot"><span></span> Email confirmation required</div>
    </section>
    <section class="auth-card">
        <div class="auth-card-kicker">U-MAIL · UTICA JENDOUBA</div>
        <p class="eyebrow">ACCOUNT REQUEST</p><h2>Create your request</h2>
        <p class="auth-card-intro">Enter your contact email to receive a confirmation code. Your phone number is optional for now.</p>
        @if(config('mail.default') === 'smtp' && config('mail.mailers.smtp.host') === '127.0.0.1')
            <p class="auth-card-intro">For this local Wi-Fi pilot, ask the project owner for your confirmation code if it does not arrive in your inbox.</p>
        @endif
        @if(session('status'))<div class="flash success">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('register') }}">@csrf
            <label>First name<input name="first_name" value="{{ old('first_name') }}" required autofocus></label>
            <label>Last name<input name="last_name" value="{{ old('last_name') }}" required></label>
            <label>Contact email<input type="email" name="email" value="{{ old('email') }}" required></label>
            <label>Phone number <small>(optional)</small><input type="tel" name="phone" value="{{ old('phone') }}" placeholder="+216 00 000 000"></label>
            @include('auth.turnstile')
            <button class="primary-button">Send confirmation code</button>
        </form>
        <div class="auth-links"><a href="{{ route('login') }}">← Employee sign in</a><a href="{{ route('password.reset') }}">Reset password</a></div>
    </section>
</main>
</body>
</html>
