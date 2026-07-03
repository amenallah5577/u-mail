@if(config('security.turnstile.enabled') && session('turnstile_required'))
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
    <div class="turnstile-wrap">
        <div class="cf-turnstile" data-sitekey="{{ config('security.turnstile.site_key') }}"></div>
    </div>
@endif
