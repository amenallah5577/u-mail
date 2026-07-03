(() => {
    const userId = document.documentElement.dataset.userId;
    if (!userId) return;

    try {
        if (localStorage.getItem(`u-mail:sidebar:${userId}:collapsed`) === 'true') {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    } catch {
        // Local storage can be unavailable in strict browser modes.
    }
})();
