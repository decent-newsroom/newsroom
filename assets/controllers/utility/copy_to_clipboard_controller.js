import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ["copyButton", "textToCopy"];

  copyToClipboard(event) {
    event.preventDefault();
    const text = this.textToCopyTarget.textContent;
    const originalText = this.copyButtonTarget.textContent;
    navigator.clipboard.writeText(text).then(() => {
      this.copyButtonTarget.textContent = "Copied!";
      setTimeout(() => {
        this.copyButtonTarget.textContent = originalText;
      }, 2000);
    }).catch(err => {
      console.error('Failed to copy: ', err);
    });
  }
}
