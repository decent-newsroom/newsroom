import { Controller } from "@hotwired/stimulus";
import { getComponent } from "@symfony/ux-live-component";

export default class extends Controller {
  static values  = { coordinate: String }
  static targets = ["list", "loading"]

  async connect() {
    // this.element IS the Live root now
    this.component = await getComponent(this.element);
    console.log("[comments_mercure] connected to Live Component:", this.component);

    // Initial render from cache so UI isn’t empty
    await this.component.render();

    // Subscribe to Mercure and re-render on each ping
    const hubUrl = window.MercureHubUrl || document.querySelector('meta[name="mercure-hub"]')?.content;
    if (!hubUrl) return;

    const url = new URL(hubUrl);
    url.searchParams.append('topic', `/comments/${this.coordinateValue}`);

    this.es = new EventSource(url.toString());
    this.es.onmessage = async (msg) => {
      this.component.set('payloadJson', msg.data);
      this.component.action('loadComments', { arg1: msg.data });
      await this.component.render();
    };
  }

  disconnect() {
    try { this.es?.close(); } catch {}
  }
}
