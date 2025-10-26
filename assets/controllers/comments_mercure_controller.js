import { Controller } from "@hotwired/stimulus";

/**
 * Server-driven comments via Mercure + Symfony UX LiveComponent.
 *
 * Usage in Twig (root element is the LiveComponent root):
 * <div {{ attributes }}
 *      data-controller="comments-mercure"
 *      data-comments-mercure-coordinate-value="{{ current }}"
 *      data-comments-mercure-target="list"
 *      data-comments-mercure-target="loading">
 *   ...
 * </div>
 */
export default class extends Controller {
  static values = {
    coordinate: String,
  };

  static targets = ["list", "loading"];

  connect() {
    console.log("[comments-mercure] Connecting to Mercure for comments at", this.coordinateValue);
    this._debounceId = null;
    this._opened = false;

    // Initial paint: ask the LiveComponent to render once (gets cached HTML immediately)
    this._renderLiveComponent();

    // Subscribe to Mercure for live updates
    const topic = `/comments/${this.coordinateValue}`;
    const hubUrl =
      window.MercureHubUrl ||
      document.querySelector('meta[name="mercure-hub"]')?.content;

    if (!hubUrl) {
      console.warn(
        "[comments-mercure] Missing Mercure hub URL (meta[name=mercure-hub])"
      );
      this._hideLoading();
      return;
    }

    const url = new URL(hubUrl);
    url.searchParams.append("topic", topic);

    this.eventSource = new EventSource(url.toString());
    this.eventSource.onopen = () => {
      this._opened = true;
      // When the connection opens, do a quick refresh to capture anything new
      this._debouncedRefresh();
    };
    this.eventSource.onerror = (e) => {
      console.warn("[comments-mercure] EventSource error", e);
      // Keep the UI usable even if Mercure hiccups
      this._hideLoading();
    };
    this.eventSource.onmessage = (e) => {
      console.log('Mercure MSG', e.data);
      // We ignore the payload; Mercure is just a signal to re-render the live component
      this._debouncedRefresh();
    };
  }

  disconnect() {
    if (this.eventSource) {
      try {
        this.eventSource.close();
      } catch {}
    }
    if (this._debounceId) {
      clearTimeout(this._debounceId);
    }
  }

  // ---- private helpers -----------------------------------------------------

  _debouncedRefresh(delay = 150) {
    if (this._debounceId) clearTimeout(this._debounceId);
    this._debounceId = setTimeout(() => {
      this._renderLiveComponent();
    }, delay);
  }

  _renderLiveComponent() {
    // Show loading spinner (if present) only while we’re actually fetching
    this._showLoading();

    // The live component controller is bound to the same root element.
    const liveRoot =
      this.element.closest(
        '[data-controller~="symfony--ux-live-component--live"]'
      ) || this.element;

    const liveController =
      this.application.getControllerForElementAndIdentifier(
        liveRoot,
        "symfony--ux-live-component--live"
      );

    if (!liveController || typeof liveController.render !== "function") {
      console.warn(
        "[comments-mercure] LiveComponent controller not found on element:",
        liveRoot
      );
      this._hideLoading();
      return;
    }

    // Ask server for the fresh HTML; morphdom will patch the DOM in place.
    // render() returns a Promise (in recent UX versions). Handle both cases.
    try {
      const maybePromise = liveController.render();
      if (maybePromise && typeof maybePromise.then === "function") {
        maybePromise.finally(() => this._hideLoading());
      } else {
        // Older versions might not return a promise—hide the spinner soon.
        setTimeout(() => this._hideLoading(), 0);
      }
    } catch (e) {
      console.error("[comments-mercure] live.render() failed", e);
      this._hideLoading();
    }
  }

  _showLoading() {
    if (this.hasLoadingTarget) this.loadingTarget.style.display = "";
    if (this.hasListTarget) this.listTarget.style.opacity = "0.6";
  }

  _hideLoading() {
    if (this.hasLoadingTarget) this.loadingTarget.style.display = "none";
    if (this.hasListTarget) this.listTarget.style.opacity = "";
  }
}
