import { Controller } from "@hotwired/stimulus";
import { getComponent } from "@symfony/ux-live-component";

export default class extends Controller {
  static values  = { coordinate: String }
  static targets = ["list", "loading"]

  async connect() {
    // this.element IS the Live root now
    this.component = await getComponent(this.element);

    // Optional: hook for spinner polish
    this.component.on('render:started', () => this._showLoading());
    this.component.on('render:finished', () => this._hideLoading());

    // Initial render from cache so UI isn’t empty
    this._showLoading();
    await this.component.render();

    // Subscribe to Mercure and re-render on each ping
    const hubUrl = window.MercureHubUrl || document.querySelector('meta[name="mercure-hub"]')?.content;
    if (!hubUrl) return;

    const url = new URL(hubUrl);
    url.searchParams.append('topic', `/comments/${this.coordinateValue}`);

    this.es = new EventSource(url.toString());
    this.es.onmessage = async (msg) => {
      this._showLoading();
      this.component.set('payloadJson', JSON.stringify(msg.data));
      await this.component.render(); // Live re-hydrates from your server/cache
    };
  }

  disconnect() {
    try { this.es?.close(); } catch {}
  }

  _showLoading() {
    if (this.hasLoadingTarget) this.loadingTarget.style.display = '';
    if (this.hasListTarget)    this.listTarget.style.opacity = '0.6';
  }
  _hideLoading() {
    if (this.hasLoadingTarget) this.loadingTarget.style.display = 'none';
    if (this.hasListTarget)    this.listTarget.style.opacity = '';
  }
}
