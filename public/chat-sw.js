// Chat Push Notifications — Service Worker
// Registered on chat subdomains to handle Web Push events.

self.addEventListener('push', (event) => {
    const data = event.data?.json() ?? {};

    // Suppress notification if the user is already viewing this group's chat tab
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(clients => {
                const chatTabVisible = clients.some(c =>
                    c.visibilityState === 'visible' &&
                    c.url.includes(`/groups/${data.groupSlug}`)
                );
                if (chatTabVisible) return; // user is already looking at it

                return self.registration.showNotification(
                    data.groupName || 'Chat',
                    {
                        body: `${data.senderDisplayName || 'Someone'} sent a message`,
                        icon: '/favicon.ico',
                        tag: `chat-${data.groupSlug}`, // collapse repeat notifications per group
                        renotify: true,
                        data: { url: `/groups/${data.groupSlug}` }
                    }
                );
            })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/groups';

    event.waitUntil(
        self.clients.matchAll({ type: 'window' })
            .then(clients => {
                const existing = clients.find(c => c.url.includes(url));
                if (existing) return existing.focus();
                return self.clients.openWindow(url);
            })
    );
});

