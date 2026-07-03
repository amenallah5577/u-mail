<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restricted Sign In - U-Mail</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <script src="/js/auth-entry.js"></script>
    @vite(['resources/css/app.css'])
</head>
<body class="auth-page admin-auth">
<main class="admin-auth-shell">
    <header class="admin-auth-header">
        <a href="{{ route('login') }}" class="auth-brand"><img class="auth-logo" src="/images/utica-jendouba-logo.png" alt="UTICA Jendouba"><span><strong>UTICA</strong><small>Jendouba</small></span></a>
        <a href="{{ route('login') }}" class="portal-switch">Employee portal</a>
    </header>
    <section class="admin-auth-content">
        <div class="admin-auth-intro">
            <p class="eyebrow">RESTRICTED ACCESS</p>
            <h1>Manage access.<br><em>Protect the workspace.</em></h1>
            <p>This portal is reserved for authorized UTICA Jendouba account administrators.</p>
            <div class="admin-access-notes">
                <span>Account provisioning</span>
                <span>Access control</span>
                <span>Audit protected</span>
            </div>
        </div>
        <div class="admin-login-panel">
            <p class="eyebrow">RESTRICTED SIGN IN</p>
            <h2>Administrator<br>credentials</h2>
            @if(session('status'))<div class="flash success">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('admin.login.submit') }}">@csrf
                <label>U-Mail or contact email<input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
                <label>Password<input type="password" name="password" required></label>
                @include('auth.turnstile')
                <button class="admin-submit">Open workspace</button>
            </form>
            <a href="{{ route('password.reset') }}">Reset password</a>
        </div>
    </section>
    <footer class="admin-auth-footer"><span>U-Mail · UTICA Jendouba</span><span>Authorized personnel only</span></footer>
</main>
</body>
</html>
