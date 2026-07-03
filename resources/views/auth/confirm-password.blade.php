<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm password - U-Mail</title><link rel="icon" type="image/png" href="/favicon.png">@vite(['resources/css/app.css'])
</head>
<body class="auth-page employee-auth">
<main class="auth-shell compact">
    <section class="auth-visual">
        <div class="auth-brand"><img class="auth-logo" src="/images/utica-jendouba-logo.png" alt="UTICA Jendouba"><span><strong>U-Mail</strong><small>Security confirmation</small></span></div>
        <div class="auth-copy"><p class="eyebrow">SENSITIVE ACTION</p><h1>Confirm identity.<br><em>Continue securely.</em></h1><p>Your confirmation remains valid for five minutes.</p></div>
        <div class="auth-foot"><span></span> Password confirmation</div>
    </section>
    <section class="auth-card">
        <p class="eyebrow">CONFIRM PASSWORD</p><h2>Enter your password</h2>
        @if(session('status'))<div class="flash success">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('password.confirm.submit') }}">@csrf
            <label>Current password<input type="password" name="password" required autofocus></label>
            <button class="primary-button">Confirm identity</button>
        </form>
        <a href="{{ route('mailbox') }}">Return to mailbox</a>
    </section>
</main>
</body>
</html>
