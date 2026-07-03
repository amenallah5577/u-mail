(() => {
    const userId = document.documentElement.dataset.userId;
    if (!userId) return;

    const storageKey = 'u-mail:signed-out';
    const meta = name => document.querySelector(`meta[name="${name}"]`)?.content;
    const loginUrl = meta('auth-login-url') || '/login';
    const sessionCheckUrl = meta('auth-session-check-url') || '/auth/session';

    function hasSignedOutCookie() {
        return document.cookie.split(';').some(cookie => cookie.trim() === 'u_mail_signed_out=1');
    }

    function hasSignedOutStorage() {
        try {
            return sessionStorage.getItem(storageKey) === 'true';
        } catch {
            return false;
        }
    }

    function clearSignedOutStorage() {
        try {
            sessionStorage.removeItem(storageKey);
        } catch {
            // Session storage can be unavailable in strict browser modes.
        }
    }

    function hidePage() {
        document.documentElement.style.visibility = 'hidden';
    }

    function showPage() {
        document.documentElement.style.visibility = '';
    }

    function redirectToLogin() {
        hidePage();
        window.location.replace(loginUrl);
    }

    function isBackForwardNavigation() {
        return performance.getEntriesByType?.('navigation')?.[0]?.type === 'back_forward';
    }

    async function verifyRestoredSession(force = false, hideDuringCheck = false) {
        if (hasSignedOutCookie()) {
            redirectToLogin();
            return;
        }

        const fromHistory = isBackForwardNavigation();
        const fromStorageMarker = hasSignedOutStorage();
        if (!force && !fromHistory && !fromStorageMarker) return;
        if (hideDuringCheck || fromHistory || fromStorageMarker) hidePage();

        try {
            const response = await fetch(sessionCheckUrl, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = response.ok ? await response.json() : { authenticated: false };

            if (hasSignedOutCookie() || !data.authenticated) {
                redirectToLogin();
                return;
            }

            if (String(data.user_id) !== String(userId)) {
                hidePage();
                window.location.reload();
                return;
            }

            clearSignedOutStorage();
            showPage();
        } catch {
            hidePage();
            window.location.reload();
        }
    }

    if (hasSignedOutCookie()) {
        redirectToLogin();
        return;
    }

    window.addEventListener('pageshow', event => {
        verifyRestoredSession(true, event.persisted || isBackForwardNavigation() || hasSignedOutCookie() || hasSignedOutStorage());
    });
    window.addEventListener('focus', () => verifyRestoredSession(true, hasSignedOutCookie() || hasSignedOutStorage()));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            verifyRestoredSession(true, hasSignedOutCookie() || hasSignedOutStorage());
        }
    });
    window.addEventListener('popstate', () => verifyRestoredSession(true, true));
    verifyRestoredSession();
})();
