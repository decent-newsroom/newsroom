import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values  = { coordinate: String }
  static targets = ["list", "loading"]

  connect() {
    this._debounceId = null;
    this._liveRoot   = this._findLiveRoot();
    console.log(this._liveRoot);

    // If the live controller isn't ready yet, wait for it.
    if (!this._getLiveController()) {
      this._onLiveConnect = () => {
        // Once live connects, do an initial render to paint cached HTML
        this._renderLiveComponent();
      };
      this._liveRoot?.addEventListener('live:connect', this._onLiveConnect, { once: true });
    } else {
      // Live controller already attached -> initial render now
      this._renderLiveComponent();
    }

    // Subscribe to Mercure updates
    const hubUrl = window.MercureHubUrl || document.querySelector('meta[name="mercure-hub"]')?.content;
    if (!hubUrl) {
      console.warn("[comments-mercure] Missing Mercure hub URL meta");
      this._hideLoading();
      return;
    }

    const topic = `/comments/${this.coordinateValue}`;
    const url   = new URL(hubUrl);
    url.searchParams.append("topic", topic);

    this.eventSource = new EventSource(url.toString());
    this.eventSource.onopen    = () => this._debouncedRefresh(50);
    this.eventSource.onerror   = (e) => console.warn("[comments-mercure] EventSource error", e);
    this.eventSource.onmessage = () => this._debouncedRefresh();
  }

  disconnect() {
    if (this.eventSource) { try { this.eventSource.close(); } catch {} }
    if (this._debounceId)  { clearTimeout(this._debounceId); }
    if (this._liveRoot && this._onLiveConnect) {
      this._liveRoot.removeEventListener('live:connect', this._onLiveConnect);
    }
  }

  // ---- private helpers -----------------------------------------------------

  _findLiveRoot() {
    // Works for both modern ("live") and older namespaced identifiers
    return this.element.closest(
      '[data-controller~="live"]'
    );
  }

  _getLiveController() {
    if (!this._liveRoot) return null;
    // Try both identifiers
    return (
      this.application.getControllerForElementAndIdentifier(this._liveRoot, 'live') ||
      this.application.getControllerForElementAndIdentifier(this._liveRoot, 'symfony--ux-live-component--live')
    );
  }

  _debouncedRefresh(delay = 150) {
    if (this._debounceId) clearTimeout(this._debounceId);
    this._debounceId = setTimeout(() => this._renderLiveComponent(), delay);
  }

  _renderLiveComponent() {
    const live = this._getLiveController();
    if (!live || typeof live.render !== 'function') {
      // Live not ready yet—try again very soon (and don't spam logs)
      setTimeout(() => this._renderLiveComponent(), 50);
      return;
    }

    this._showLoading();
    const p = live.render();
    if (p && typeof p.finally === 'function') {
      p.finally(() => this._hideLoading());
    } else {
      setTimeout(() => this._hideLoading(), 0);
    }
  }

  _showLoading() {
    if (this.hasLoadingTarget) this.loadingTarget.style.display = "";
    if (this.hasListTarget)    this.listTarget.style.opacity = "0.6";
  }
  _hideLoading() {
    if (this.hasLoadingTarget) this.loadingTarget.style.display = "none";
    if (this.hasListTarget)    this.listTarget.style.opacity = "";
  }
}
