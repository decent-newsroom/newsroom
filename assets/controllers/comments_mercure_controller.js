import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values  = { coordinate: String }
  static targets = ["list", "loading"]

  connect() {
    this._liveRoot = this.element.closest(
      '[data-controller~="live"]'
    );

    const hubUrl = window.MercureHubUrl || document.querySelector('meta[name="mercure-hub"]')?.content;
    if (!hubUrl) return;

    const url = new URL(hubUrl);
    url.searchParams.append('topic', `/comments/${this.coordinateValue}`);

    this.es = new EventSource(url.toString());
    this.es.onmessage = (event) => this._pushToLive(event.data);
  }

  disconnect() {
    if (this.es) try { this.es.close(); } catch {}
  }

  _pushToLive(jsonString) {
    if (!this._liveRoot) return;
    // Find the hidden input bound to the LiveProp
    const input = this._liveRoot.querySelector('input[type="hidden"][data-model="payloadJson"]');
    if (!input) return;

    // Set value and dispatch an 'input' event so Live updates & re-renders
    input.value = jsonString;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }
}
