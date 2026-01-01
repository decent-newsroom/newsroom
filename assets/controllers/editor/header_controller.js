import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  connect() {
    console.log("Header controller connected");
  }

  saveDraft(event) {
    event.preventDefault();
    // Set isDraft to true
    const draftCheckbox = document.querySelector('input[name*="[isDraft]"]');
    if (draftCheckbox) {
      draftCheckbox.checked = true;
    } else {
      console.warn('[Header] Draft checkbox not found');
    }
    // Trigger click on the hidden Nostr publish button
    const publishButton = document.querySelector('[data-nostr--nostr-publish-target="publishButton"]');
    if (publishButton) {
      publishButton.click();
    } else {
      console.error('[Header] Hidden publish button not found');
    }
  }

  publish(event) {
    event.preventDefault();
    // Set isDraft to false
    const draftCheckbox = document.querySelector('input[name*="[isDraft]"]');
    if (draftCheckbox) {
      draftCheckbox.checked = false;
    } else {
      console.warn('[Header] Draft checkbox not found');
    }
    // Trigger click on the hidden Nostr publish button
    const publishButton = document.querySelector('[data-nostr--nostr-publish-target="publishButton"]');
    if (publishButton) {
      console.log('[Header] Triggering publish button click');
      publishButton.click();
    } else {
      console.error('[Header] Hidden publish button not found');
    }
  }
}
