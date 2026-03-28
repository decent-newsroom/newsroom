import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ["copyButton", "textToCopy"];
  static values = { text: String };

  copyToClipboard(event) {
    event.preventDefault();
    const text = this.hasTextValue ? this.textValue : this.textToCopyTarget.textContent;
    const button = this.hasCopyButtonTarget ? this.copyButtonTarget : event.currentTarget;
    const originalText = button.textContent;
    navigator.clipboard.writeText(text).then(() => {
      button.textContent = "Copied!";
      setTimeout(() => {
        button.textContent = originalText;
      }, 2000);
    }).catch(err => {
      console.error('Failed to copy: ', err);
    });
  }
}
