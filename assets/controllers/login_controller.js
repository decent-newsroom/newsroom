import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

export default class extends Controller {
  async initialize() {
    this.component = await getComponent(this.element);
  }
  async loginAct() {
    const tags = [
      ['u', window.location.origin + '/login'],
      ['method', 'POST'],
      ['t', 'extension']
    ]
    const ev = {
      created_at: Math.floor(Date.now()/1000),
      kind: 27235,
      tags: tags,
      content: ''
    }

    const signed = await window.nostr.signEvent(ev);
    // base64 encode and send as Auth header
    const result = await fetch('/login', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Authorization': 'Nostr ' + btoa(JSON.stringify(signed))
      }
    }).then(response => {
      if (!response.ok) return false;
      return 'Authentication Successful';
    })
    if (!!result) {
      this.component.render();
    }
  }
}

