<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify sign in - U-Mail</title><link rel="icon" type="image/png" href="/favicon.png">@vite(['resources/css/app.css'])
</head>
<body class="auth-page employee-auth">
<main class="auth-shell compact">
    <section class="auth-visual">
        <div class="auth-brand"><img class="auth-logo" src="/images/utica-jendouba-logo.png" alt="UTICA Jendouba"><span><strong>U-Mail</strong><small>Protected sign in</small></span></div>
        <div class="auth-copy"><p class="eyebrow">MULTI-FACTOR AUTHENTICATION</p><h1>One more step.<br><em>Verify access.</em></h1><p>Complete one of your enrolled security methods to open U-Mail.</p></div>
        <div class="auth-foot"><span></span> Secure verification</div>
    </section>
    <section class="auth-card reset-card">
        <p class="eyebrow">VERIFY SIGN IN</p><h2>Choose a method</h2>
        @if(session('status'))<div class="flash success">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
        <div class="mfa-method-actions">
            @foreach($methods as $method)
                <form method="POST" action="{{ route('mfa.challenge.method') }}">@csrf
                    <input type="hidden" name="method" value="{{ $method }}">
                    @include('auth.turnstile')
                    <button class="soft-button {{ $selectedMethod === $method ? 'selected' : '' }}">{{ $method === 'totp' ? 'Authenticator app' : 'Email code' }}</button>
                </form>
            @endforeach
        </div>
        @if($selectedMethod)
            <form method="POST" action="{{ route('mfa.challenge.verify') }}">@csrf
                <label>{{ $selectedMethod === 'email' ? 'Email verification code' : 'Authenticator code' }}<input name="code" required autofocus autocomplete="one-time-code"></label>
                @include('auth.turnstile')
                <button class="primary-button">Verify and sign in</button>
            </form>
            @if($selectedMethod === 'email')
                <form method="POST" action="{{ route('mfa.challenge.resend') }}">@csrf @include('auth.turnstile')<button class="soft-button">Send a new email code</button></form>
            @endif
        @endif
        @if($recoveryAvailable)
            <div class="recovery-challenge">
                <p class="panel-note">Lost your authenticator? Use one saved recovery code.</p>
                <form method="POST" action="{{ route('mfa.challenge.verify') }}">@csrf
                    <input type="hidden" name="use_recovery" value="1">
                    <label>Recovery code<input name="code" required></label>
                    @include('auth.turnstile')
                    <button class="soft-button">Use recovery code</button>
                </form>
            </div>
        @endif
    </section>
</main>
</body>
</html>
