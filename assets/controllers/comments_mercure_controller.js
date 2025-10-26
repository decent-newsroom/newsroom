import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values  = { coordinate: String }
  static targets = ["list", "loading"]

  connect() {
    this._debounceId = null;
    this._liveRoot   = this._findLiveRoot();
    this._liveReady  = false;
    this._queue      = []; // buffer Mercure payloads until live connects

    // 1) Wait for Live to connect (or mark ready if already connected)
    const live = this._getLiveController();
    if (live) {
      this._liveReady = true;
    } else if (this._liveRoot) {
      this._onLiveConnect = () => {
        this._liveReady = true;
        this._flushQueue();
      };
      this._liveRoot.addEventListener('live:connect', this._onLiveConnect, { once: true });
    }

    // Optional: initial render to paint cached HTML
    this._renderWhenReady();

    // 2) Subscribe to Mercure
    const hubUrl = window.MercureHubUrl || document.querySelector('meta[name="mercure-hub"]')?.content;
    if (!hubUrl) return;

    const topic = `/comments/${this.coordinateValue}`;
    const url   = new URL(hubUrl); url.searchParams.append("topic", topic);

    this.eventSource = new EventSource(url.toString());
    this.eventSource.onmessage = (event) => {
      const data = JSON.parse(event.data); // { comments, profiles, ... }
      const live = this._getLiveController();
      if (live) {
        live.set('payload', JSON.stringify(data)); // <- updates the writable LiveProp
        live.render();             // <- asks server to re-render
      }
    };
  }

  disconnect() {
    if (this.eventSource) try { this.eventSource.close(); } catch {}
    if (this._debounceId) clearTimeout(this._debounceId);
    if (this._liveRoot && this._onLiveConnect) {
      this._liveRoot.removeEventListener('live:connect', this._onLiveConnect);
    }
  }

  // ---- private -------------------------------------------------------------

  _findLiveRoot() {
    return this.element.closest(
      '[data-controller~="live"],' +
      '[data-controller~="symfony--ux-live-component--live"]'
    );
  }

  _getLiveController() {
    if (!this._liveRoot) return null;
    return this.application.getControllerForElementAndIdentifier(this._liveRoot, 'live')
      || this.application.getControllerForElementAndIdentifier(this._liveRoot, 'symfony--ux-live-component--live');
  }

  _renderWhenReady() {
    // If you also want an initial refresh from server/cache, you can do:
    const tryRender = () => {
      const live = this._getLiveController();
      if (!live || typeof live.render !== 'function') return setTimeout(tryRender, 50);
      live.render();
    };
    tryRender();
  }

  _flushQueue() {
    while (this._queue.length) {
      const data = this._queue.shift();
      this._ingest(data);
    }
  }

  _ingest(payload) {
    const live = this._getLiveController();
    if (!live || typeof live.action !== 'function') {
      // if still not ready, re-buffer and retry soon
      this._queue.unshift(payload);
      return setTimeout(() => this._flushQueue(), 50);
    }
    // Call your LiveAction; it will mutate props and re-render server-side
    // NOTE: payload must be a string; if you have an object, pass JSON.stringify(obj)
    live.action('ingest', { payload });
  }
}
