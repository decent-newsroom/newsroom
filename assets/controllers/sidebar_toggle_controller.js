import { Controller } from '@hotwired/stimulus';

/*
 * Sidebar toggle controller
 * Controls showing/hiding nav (#leftNav) and aside (#rightNav) on mobile viewports.
 * Uses aria-controls attribute of clicked toggle buttons.
 */
export default class extends Controller {
    static targets = [ ];

    connect() {
        this.mediaQuery = window.matchMedia('(min-width: 769px)');
        this.resizeListener = () => this.handleResize();
        this.keyListener = (e) => this.handleKeydown(e);
        this.clickOutsideListener = (e) => this.handleDocumentClick(e);
        this.mediaQuery.addEventListener('change', this.resizeListener);
        document.addEventListener('keydown', this.keyListener);
        document.addEventListener('click', this.clickOutsideListener);
        this.handleResize();
    }

    disconnect() {
        this.mediaQuery.removeEventListener('change', this.resizeListener);
        document.removeEventListener('keydown', this.keyListener);
        document.removeEventListener('click', this.clickOutsideListener);
    }

    toggle(event) {
        const controlId = event.currentTarget.getAttribute('aria-controls');
        const el = document.getElementById(controlId);
        if (!el) return;
        if (el.classList.contains('is-open')) {
            this.closeElement(el);
        } else {
            this.openElement(el);
        }
        this.syncAria(controlId);
    }

    close(event) {
        // Close button inside a sidebar
        const container = event.currentTarget.closest('nav, aside');
        if (container) {
            this.closeElement(container);
            this.syncAria(container.id);
        }
    }

    openElement(el) {
        // Only apply overlay behavior on mobile
        if (this.isDesktop()) return; // grid already shows them
        el.classList.add('is-open');
        document.body.classList.add('no-scroll');
    }

    closeElement(el) {
        el.classList.remove('is-open');
        if (!this.anyOpen()) {
            document.body.classList.remove('no-scroll');
        }
    }

    anyOpen() {
        return !!document.querySelector('nav.is-open, aside.is-open');
    }

    syncAria(id) {
        // Update any toggle buttons that control this id
        const expanded = document.getElementById(id)?.classList.contains('is-open') || false;
        document.querySelectorAll(`[aria-controls="${id}"]`).forEach(btn => {
            btn.setAttribute('aria-expanded', expanded.toString());
        });
    }

    handleResize() {
        if (this.isDesktop()) {
            // Ensure both sidebars are visible in desktop layout
            ['leftNav', 'rightNav'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.classList.remove('is-open');
                this.syncAria(id);
            });
            document.body.classList.remove('no-scroll');
        } else {
            // On mobile ensure aria-expanded is false unless explicitly opened
            ['leftNav', 'rightNav'].forEach(id => this.syncAria(id));
        }
    }

    handleKeydown(e) {
        if (e.key === 'Escape') {
            const open = document.querySelectorAll('nav.is-open, aside.is-open');
            if (open.length) {
                open.forEach(el => this.closeElement(el));
                ['leftNav', 'rightNav'].forEach(id => this.syncAria(id));
            }
        }
    }

    handleDocumentClick(e) {
        if (this.isDesktop()) return; // only needed mobile
        const open = document.querySelectorAll('nav.is-open, aside.is-open');
        if (!open.length) return;
        const inside = e.target.closest('nav, aside, .mobile-toggles');
        if (!inside) {
            open.forEach(el => this.closeElement(el));
            ['leftNav', 'rightNav'].forEach(id => this.syncAria(id));
        }
    }

    isDesktop() {
        return this.mediaQuery.matches;
    }
}

