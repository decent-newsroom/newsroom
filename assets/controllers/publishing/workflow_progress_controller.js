import { Controller } from '@hotwired/stimulus';

/**
 * Workflow Progress Bar Controller
 *
 * Handles animated progress bar with color transitions and status updates.
 *
 * Usage:
 *   <div data-controller="workflow-progress"
 *        data-workflow-progress-percentage-value="80"
 *        data-workflow-progress-status-value="ready_for_review"
 *        data-workflow-progress-color-value="success">
 *   </div>
 */
export default class extends Controller {
    static values = {
        percentage: { type: Number, default: 0 },
        status: { type: String, default: 'empty' },
        color: { type: String, default: 'secondary' },
        animated: { type: Boolean, default: true }
    }

    static targets = ['bar', 'badge', 'statusText', 'nextSteps']

    connect() {
        this.updateProgress();
    }

    percentageValueChanged() {
        this.updateProgress();
    }

    statusValueChanged() {
        this.updateStatusDisplay();
    }

    colorValueChanged() {
        this.updateBarColor();
    }

    updateProgress() {
        if (!this.hasBarTarget) return;

        const percentage = this.percentageValue;

        if (this.animatedValue) {
            // Smooth animation
            this.animateProgressBar(percentage);
        } else {
            // Instant update
            this.barTarget.style.width = `${percentage}%`;
            this.barTarget.setAttribute('aria-valuenow', percentage);
        }

        // Update accessibility
        this.updateAriaLabel();
    }

    animateProgressBar(targetPercentage) {
        const currentPercentage = parseInt(this.barTarget.style.width) || 0;
        const duration = 600; // ms
        const steps = 30;
        const increment = (targetPercentage - currentPercentage) / steps;
        const stepDuration = duration / steps;

        let currentStep = 0;

        const animate = () => {
            if (currentStep >= steps) {
                this.barTarget.style.width = `${targetPercentage}%`;
                this.barTarget.setAttribute('aria-valuenow', targetPercentage);
                return;
            }

            const newPercentage = currentPercentage + (increment * currentStep);
            this.barTarget.style.width = `${newPercentage}%`;
            this.barTarget.setAttribute('aria-valuenow', Math.round(newPercentage));

            currentStep++;
            requestAnimationFrame(() => {
                setTimeout(animate, stepDuration);
            });
        };

        animate();
    }

    updateBarColor() {
        if (!this.hasBarTarget) return;

        const colorClasses = [
            'bg-secondary', 'bg-info', 'bg-primary',
            'bg-success', 'bg-warning', 'bg-danger'
        ];

        // Remove all color classes
        colorClasses.forEach(cls => this.barTarget.classList.remove(cls));

        // Add new color class
        this.barTarget.classList.add(`bg-${this.colorValue}`);
    }

    updateStatusDisplay() {
        if (this.hasBadgeTarget) {
            const statusMessages = this.getStatusMessage(this.statusValue);
            this.badgeTarget.textContent = statusMessages.short;
        }

        if (this.hasStatusTextTarget) {
            const statusMessages = this.getStatusMessage(this.statusValue);
            this.statusTextTarget.textContent = statusMessages.long;
        }
    }

    updateAriaLabel() {
        if (!this.hasBarTarget) return;

        const percentage = this.percentageValue;
        const statusMessages = this.getStatusMessage(this.statusValue);
        const label = `${statusMessages.short}: ${percentage}% complete`;

        this.barTarget.setAttribute('aria-label', label);
    }

    getStatusMessage(status) {
        const messages = {
            'empty': {
                short: 'Not started',
                long: 'Reading list not started yet'
            },
            'draft': {
                short: 'Draft created',
                long: 'Draft created, add content to continue'
            },
            'has_metadata': {
                short: 'Title and summary added',
                long: 'Metadata complete, add articles next'
            },
            'has_articles': {
                short: 'Articles added',
                long: 'Articles added, checking requirements'
            },
            'ready_for_review': {
                short: 'Ready to publish',
                long: 'Your reading list is ready to publish'
            },
            'publishing': {
                short: 'Publishing...',
                long: 'Publishing to Nostr, please wait'
            },
            'published': {
                short: 'Published',
                long: 'Successfully published to Nostr'
            },
            'editing': {
                short: 'Editing',
                long: 'Editing published reading list'
            }
        };

        return messages[status] || messages['empty'];
    }

    // Public methods that can be called from other controllers
    setPercentage(percentage) {
        this.percentageValue = percentage;
    }

    setStatus(status) {
        this.statusValue = status;
    }

    setColor(color) {
        this.colorValue = color;
    }

    pulse() {
        if (!this.hasBarTarget) return;

        this.barTarget.classList.add('workflow-progress-pulse');
        setTimeout(() => {
            this.barTarget.classList.remove('workflow-progress-pulse');
        }, 1000);
    }

    celebrate() {
        if (!this.hasBarTarget) return;

        // Add celebration animation when reaching 100%
        if (this.percentageValue === 100) {
            this.barTarget.classList.add('workflow-progress-celebrate');
            setTimeout(() => {
                this.barTarget.classList.remove('workflow-progress-celebrate');
            }, 2000);
        }
    }
}

