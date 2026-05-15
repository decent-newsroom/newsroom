import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['days', 'hours', 'minutes', 'seconds'];
    static values  = { target: String };

    connect() {
        this.#tick();
        this.timer = setInterval(() => this.#tick(), 1000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    #tick() {
        const end  = new Date(this.targetValue).getTime();
        const now  = Date.now();
        const diff = end - now;

        if (diff <= 0) {
            this.#set('days',    '0');
            this.#set('hours',   '00');
            this.#set('minutes', '00');
            this.#set('seconds', '00');
            clearInterval(this.timer);
            return;
        }

        const days    = Math.floor(diff / 86400000);
        const hours   = Math.floor((diff % 86400000) / 3600000);
        const minutes = Math.floor((diff % 3600000)  / 60000);
        const seconds = Math.floor((diff % 60000)    / 1000);

        this.#set('days',    String(days));
        this.#set('hours',   String(hours).padStart(2, '0'));
        this.#set('minutes', String(minutes).padStart(2, '0'));
        this.#set('seconds', String(seconds).padStart(2, '0'));
    }

    #set(target, value) {
        const el = this[`${target}Target`];
        if (el && el.textContent !== value) el.textContent = value;
    }
}

