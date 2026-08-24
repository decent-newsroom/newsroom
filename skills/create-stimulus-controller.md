# Skill: Create a Stimulus Controller

Use this skill to add client-side behaviour via a Stimulus controller. This project uses **AssetMapper** — no npm build step; just drop the file and it is available.

---

## Naming & location

Controllers live under `assets/controllers/{domain}/`:

| Domain folder | Handles |
|---|---|
| `nostr/` | Signing, publishing, relay auth, Nostr-specific actions |
| `content/` | Home feed tabs, author articles, reading list dropdown |
| `editor/` | Quill, markdown sync, embeds, media |
| `media/` | Image loaders, media library, upload |
| `ui/` | Sidebar, back-to-top, gallery, wizard |
| `utility/` | Login, toast, clipboard, signer modal |
| `search/` | Search visibility, topic filter |
| `analytics/` | Chart controllers |
| `publishing/` | Image upload, Quill publish, tabular data |

**Filename pattern:** `{domain}_{name}_controller.js`  
**HTML data-controller:** `{domain}--{name}` (double-dash separator)

---

## 1. Create the controller file

File: `assets/controllers/{domain}/{domain}_{name}_controller.js`

```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    // Targets — DOM elements this controller manages
    static targets = ['button', 'panel'];

    // Values — typed data passed from HTML attributes
    static values = {
        pubkey: String,
        open:   { type: Boolean, default: false },
    };

    connect() {
        // Called when element is connected to the DOM.
        // Good for initialisation that needs the element to exist.
    }

    disconnect() {
        // Called when element is removed. Clean up subscriptions, timers, etc.
    }

    // --- Actions ---

    toggle() {
        this.openValue = !this.openValue;
    }

    // Values callback — fires whenever openValue changes
    openValueChanged(value) {
        this.panelTarget.hidden = !value;
    }
}
```

---

## 2. Register in `assets/controllers.json`

For **third-party** UX packages only. For your own local controllers this file is **not needed** — AssetMapper auto-discovers `assets/controllers/**/*_controller.js`.

---

## 3. Wire to HTML in a Twig template

```twig
<div
    data-controller="domain--name"
    data-domain--name-pubkey-value="{{ pubkey }}"
    data-domain--name-open-value="false"
>
    <button
        data-action="click->domain--name#toggle"
        data-domain--name-target="button"
    >
        Toggle
    </button>

    <div data-domain--name-target="panel" hidden>
        Panel content
    </div>
</div>
```

---

## 4. Importing utilities

Local TypeScript utilities (NIP-19, nostr tools):
```javascript
import { npubToHex, decodeNip19 } from '../../typescript/nostr-utils.ts';
```

Nostr signing (for publish flows):
```javascript
import { getRemoteSignerSession } from '../nostr/signer_manager.js';
```

External packages (managed via `importmap.php`):
```javascript
import { nip19 } from 'nostr-tools';
```

To add a new npm package:
```bash
docker compose exec php bin/console importmap:require nostr-tools
```

---

## 5. CSS for controller-driven state

Put styles in `assets/styles/03-components/` or `04-pages/`, never inline.

Use data attributes for state rather than adding/removing classes:

```css
/* In assets/styles/03-components/_your-component.css */
[data-domain--name-open-value="true"] .panel {
    display: block;
}
```

---

## 6. Compile assets after changes

```bash
docker compose exec php bin/console asset-map:compile
```

In development with the Docker stack running, changes are picked up automatically via the AssetMapper dev server.

---

## Checklist

- [ ] File created at `assets/controllers/{domain}/{domain}_{name}_controller.js`
- [ ] Controller extends `Controller` from `@hotwired/stimulus`
- [ ] Static `targets` and `values` declared
- [ ] `connect()`/`disconnect()` handle setup/teardown
- [ ] HTML wired with `data-controller`, `data-action`, `data-{controller}-target`
- [ ] No inline styles — CSS in `assets/styles/`
- [ ] Assets compiled: `asset-map:compile`
- [ ] `CHANGELOG.md` entry added

