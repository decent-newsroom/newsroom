import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ["copyButton", "textToCopy"];

  copyToClipboard(event) {
    event.preventDefault();
    const text = this.textToCopyTarget.textContent;
    navigator.clipboard.writeText(text).then(() => {
      this.copyButtonTarget.textContent = "Copied!";
      setTimeout(() => {
        this.copyButtonTarget.textContent = "Copy to Clipboard";
      }, 2000);
    }).catch(err => {
      console.error('Failed to copy: ', err);
    });
  }
}
