<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee Sign In - U-Mail</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <script src="/js/auth-entry.js"></script>
    @vite(['resources/css/app.css'])
</head>
<body class="auth-page employee-auth">
<main class="auth-shell">
    <section class="auth-visual">
        <div class="auth-brand"><img class="auth-logo" src="/images/utica-jendouba-logo.png" alt="UTICA Jendouba"><span><strong>UTICA</strong><small>Jendouba</small></span></div>
        <div class="auth-copy">
            <p class="eyebrow">EMPLOYEE MAIL WORKSPACE</p>
            <h1>Work connected.<br><em>Mail protected.</em></h1>
            <p>UTICA Jendouba's private workspace for clear, secure communication between employees.</p>
        </div>
        <div class="auth-foot"><span></span> Employee access only</div>
    </section>
    <section class="auth-card">
        <div class="auth-card-kicker">U-MAIL · UTICA JENDOUBA</div>
        <p class="eyebrow">EMPLOYEE SIGN IN</p>
        <h2>Welcome back</h2>
        <p class="auth-card-intro">Your U-Mail address is your main sign-in. Your private contact email also works.</p>
        @if(session('status'))<div class="flash success">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('login') }}">@csrf
            <label>U-Mail or contact email<input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
            <label>Password<input type="password" name="password" required></label>
            <label class="check"><input type="checkbox" name="remember"> Keep me signed in</label>
            @include('auth.turnstile')
            <button class="primary-button">Enter mailbox</button>
        </form>
        <div class="auth-links">
            @if(config('registration.enabled'))
                <a href="{{ route('register') }}">Request an account</a>
            @else
                <span>Account requests temporarily unavailable</span>
            @endif
            <a href="{{ route('password.reset') }}">Reset password</a>
        </div>
    </section>
</main>
</body>
</html>
