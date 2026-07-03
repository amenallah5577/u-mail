<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password - U-Mail</title><link rel="icon" type="image/png" href="/favicon.png">@vite(['resources/css/app.css'])
</head>
<body class="auth-page">
<main class="auth-shell compact">
    <section class="auth-visual">
        <div class="auth-brand"><img class="auth-logo" src="/images/utica-jendouba-logo.png" alt="UTICA Jendouba"><span><strong>UTICA</strong><small>Jendouba</small></span></div>
        <div class="auth-copy"><p class="eyebrow">SECURE PASSWORD RESET</p><h1>Recover access.<br><em>Keep mail protected.</em></h1><p>Request a private reset code, then use it once to choose a new password.</p></div>
        <div class="auth-foot"><span></span> U-Mail password reset</div>
    </section>
    <section class="auth-card reset-card">
        <div class="auth-card-kicker">UTICA JENDOUBA MAIL</div>
        <p class="eyebrow">PASSWORD RESET</p><h2>Reset password</h2>
        <p class="auth-card-intro">Enter your U-Mail address. The reset code is sent only to the private contact email on file.</p>
        @if(session('status'))<div class="flash success">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
        <form class="reset-request" method="POST" action="{{ route('password.reset.request') }}">@csrf
            <label>U-Mail address<input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
            @include('auth.turnstile')
            <button class="soft-button">Send reset code</button>
        </form>
        <form method="POST" action="{{ route('password.reset.update') }}">@csrf
            <label>U-Mail address<input type="email" name="email" value="{{ old('email') }}" required></label>
            <label>Reset code<input name="code" inputmode="numeric" maxlength="6" required></label>
            <label>New password<input type="password" name="password" required></label>
            <label>Confirm password<input type="password" name="password_confirmation" required></label>
            @include('auth.turnstile')
            <button class="primary-button">Reset password</button>
        </form>
        <div class="auth-links"><a href="{{ route('login') }}">← Employee sign in</a></div>
    </section>
</main>
</body>
</html>
