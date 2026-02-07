import { Controller } from '@hotwired/stimulus'

/**
 * Controller for checking payment status of active indexing subscriptions.
 * Polls the server periodically and redirects when payment is confirmed.
 */
export default class extends Controller {
    static targets = ['result']
    static values = {
        checkUrl: String,
        redirectUrl: String,
        interval: { type: Number, default: 30000 } // 30 seconds
    }

    connect() {
        // Start auto-checking
        this.startPolling()
    }

    disconnect() {
        this.stopPolling()
    }

    startPolling() {
        this.intervalId = setInterval(() => this.check(), this.intervalValue)
    }

    stopPolling() {
        if (this.intervalId) {
            clearInterval(this.intervalId)
            this.intervalId = null
        }
    }

    async check() {
        if (!this.hasResultTarget) return

        this.resultTarget.innerHTML = '<span class="text-muted">Checking...</span>'

        try {
            const response = await fetch(this.checkUrlValue)
            const data = await response.json()

            if (data.isActive) {
                this.resultTarget.innerHTML = '<span class="text-success">✓ Payment confirmed! Redirecting...</span>'
                this.stopPolling()
                setTimeout(() => {
                    window.location.href = this.redirectUrlValue
                }, 1500)
            } else {
                this.resultTarget.innerHTML = `<span class="text-warning">Payment not yet confirmed. Status: ${data.status}</span>`
            }
        } catch (error) {
            this.resultTarget.innerHTML = '<span class="text-danger">Error checking status</span>'
        }
    }
}

