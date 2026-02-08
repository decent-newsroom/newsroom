import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        articleId: Number,
        coordinate: String
    };

    async broadcast(event) {
        event.preventDefault();
        const button = event.currentTarget;
        button.disabled = true;
        const originalText = button.innerHTML;
        button.innerHTML = '<span class="spinning">⟳</span> Broadcasting...';

        try {
            const payload = {};
            if (this.hasArticleIdValue) payload.article_id = this.articleIdValue;
            if (this.hasCoordinateValue) payload.coordinate = this.coordinateValue;

            const response = await fetch('/api/broadcast-article', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            // Check if response is JSON before parsing
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 200));
                throw new Error(`Server returned non-JSON response (${response.status}). Check server logs.`);
            }

            const data = await response.json();

            if (response.ok && data.success) {
                // Show success toast
                const successMsg = `Article broadcast to ${data.broadcast.successful}/${data.broadcast.total_relays} relays`;
                window.showToast(successMsg, 'success', 5000);

                // Update button temporarily
                button.innerHTML = `✓ Broadcast!`;

                // Log failed relays as warning if any
                if (data.broadcast.failed > 0 && data.broadcast.failed_relays.length > 0) {
                    const failedMsg = `${data.broadcast.failed} relay(s) failed: ${data.broadcast.failed_relays.map(r => r.relay).join(', ')}`;
                    window.showToast(failedMsg, 'warning', 6000);
                    console.warn('Failed relays:', data.broadcast.failed_relays);
                }

                // Reset button after delay
                setTimeout(() => {
                    button.disabled = false;
                    button.innerHTML = originalText;
                }, 2000);
            } else {
                throw new Error(data.error || 'Failed to broadcast');
            }
        } catch (error) {
            console.error('Broadcast error:', error);

            // Show error toast
            window.showToast(`Failed to broadcast article: ${error.message}`, 'danger', 5000);

            // Update button to show error
            button.innerHTML = '⚠ Failed';
            setTimeout(() => {
                button.disabled = false;
                button.innerHTML = originalText;
            }, 2000);
        }
    }
}
