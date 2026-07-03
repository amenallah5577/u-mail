(() => {
    try {
        sessionStorage.removeItem('u-mail:signed-out');
    } catch {
        // Session storage can be unavailable in strict browser modes.
    }
})();
