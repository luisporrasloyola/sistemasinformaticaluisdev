(() => {
    const baseUrl = window.APP_URL || window.location.origin;

    const updateBadge = (id, count) => {
        const badge = document.getElementById(id);
        if (!badge) return;

        const total = Number(count) || 0;
        badge.textContent = total > 99 ? '99+' : String(total);
        badge.style.display = total > 0 ? 'flex' : 'none';
    };

    const fetchCount = async (url) => {
        const response = await fetch(`${url}?t=${Date.now()}`, {
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const data = await response.json();
        return data && data.ok ? data.unread_count : 0;
    };

    const refreshHeaderBadges = async () => {
        const [observations, notifications] = await Promise.all([
            fetchCount(`${baseUrl}/servicios/get_observation_notifications.php`),
            fetchCount(`${baseUrl}/servicios/get_notifications.php`),
        ]);

        updateBadge('obsNotifBadge', observations);
        updateBadge('notifBadge', notifications);
    };

    window.addEventListener('load', () => {
        refreshHeaderBadges().catch(() => {});
        window.setInterval(() => refreshHeaderBadges().catch(() => {}), 20000);
    });
})();
