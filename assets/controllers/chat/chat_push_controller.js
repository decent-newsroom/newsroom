import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for Web Push notification subscription on chat subdomains.
 *
 * Usage:
 *   <div data-controller="chat--push"
 *        data-chat--push-vapid-key-value="BASE64_VAPID_PUBLIC_KEY"
 *        data-chat--push-subscribe-url-value="/push/subscribe"
 *        data-chat--push-unsubscribe-url-value="/push/unsubscribe">
 *     <div data-chat--push-target="prompt" class="chat-push-prompt" style="display:none;">
 *       <span>Enable notifications to know when new messages arrive</span>
 *       <button data-action="chat--push#requestPermission">Enable</button>
 *       <button data-action="chat--push#dismissPrompt">Not now</button>
 *     </div>
 *   </div>
 */
export default class extends Controller {
    static values = {
        vapidKey: String,
        subscribeUrl: { type: String, default: '/push/subscribe' },
        unsubscribeUrl: { type: String, default: '/push/unsubscribe' },
    };

    static targets = ['prompt'];

    connect() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return; // Push not supported
        }

        switch (Notification.permission) {
            case 'granted':
                this._ensureSubscription();
                break;
            case 'default':
                this._showPrompt();
                break;
            case 'denied':
                // User has blocked notifications — do nothing
                break;
        }
    }

    async requestPermission() {
        const permission = await Notification.requestPermission();
        this._hidePrompt();

        if (permission === 'granted') {
            await this._subscribe();
        }
    }

    dismissPrompt() {
        this._hidePrompt();
        // Remember dismissal for this session
        sessionStorage.setItem('chat-push-dismissed', '1');
    }

    // --- Private ---

    _showPrompt() {
        if (sessionStorage.getItem('chat-push-dismissed') === '1') return;
        if (this.hasPromptTarget) {
            this.promptTarget.style.display = '';
        }
    }

    _hidePrompt() {
        if (this.hasPromptTarget) {
            this.promptTarget.style.display = 'none';
        }
    }

    async _ensureSubscription() {
        try {
            const registration = await navigator.serviceWorker.register('/chat-sw.js');
            const subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                await this._subscribe(registration);
            }
        } catch (err) {
            console.warn('[chat-push] Failed to ensure subscription:', err);
        }
    }

    async _subscribe(existingRegistration) {
        try {
            const registration = existingRegistration || await navigator.serviceWorker.register('/chat-sw.js');
            await navigator.serviceWorker.ready;

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this._urlBase64ToUint8Array(this.vapidKeyValue),
            });

            const response = await fetch(this.subscribeUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(subscription.toJSON()),
            });

            if (!response.ok) {
                console.error('[chat-push] Server rejected subscription:', await response.text());
            }
        } catch (err) {
            console.error('[chat-push] Subscription failed:', err);
        }
    }

    /**
     * Convert a base64url-encoded string to a Uint8Array (for applicationServerKey).
     */
    _urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }
}

