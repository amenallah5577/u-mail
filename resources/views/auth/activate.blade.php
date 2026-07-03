<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate account - U-Mail</title><link rel="icon" type="image/png" href="/favicon.png">@vite(['resources/css/app.css'])
</head>
<body class="auth-page">
<main class="auth-shell compact">
    <section class="auth-visual">
        <div class="auth-brand"><img class="auth-logo" src="/images/utica-jendouba-logo.png" alt="UTICA Jendouba"><span><strong>UTICA</strong><small>Jendouba</small></span></div>
        <div class="auth-copy"><p class="eyebrow">SECURE ACCOUNT SETUP</p><h1>Activate your<br><em>private workspace.</em></h1><p>Use the six-digit, single-use activation code sent to your private contact email.</p></div>
        <div class="auth-foot"><span></span> Protected employee access</div>
    </section>
    <section class="auth-card">
        <div class="auth-card-kicker">UTICA JENDOUBA MAIL</div>
        <p class="eyebrow">ACCOUNT ACTIVATION</p><h2>Activate account</h2>
        <p class="auth-card-intro">Enter the activation code from your email and choose your first password.</p>
        @if($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('activate') }}">@csrf
            <label>U-Mail or contact email<input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
            <label>One-time code<input name="code" inputmode="numeric" maxlength="6" required></label>
            <label>New password<input type="password" name="password" required></label>
            <label>Confirm password<input type="password" name="password_confirmation" required></label>
            @include('auth.turnstile')
            <button class="primary-button">Set password</button>
        </form>
        <div class="auth-links"><a href="{{ route('login') }}">← Employee sign in</a><a href="{{ route('password.reset') }}">Reset password</a></div>
    </section>
</main>
</body>
</html>
