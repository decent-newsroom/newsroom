import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = ['promptBox']

  connect() {
    this.checkInstallEligibility()
  }

  checkInstallEligibility() {
    // Skip if already installed or dismissed
    if (
      localStorage.getItem('a2hs_installed') === '1' ||
      localStorage.getItem('a2hs_dismissed') === '1' ||
      window.matchMedia('(display-mode: standalone)').matches
    ) {
      return
    }

    // Track page loads
    let loadCount = parseInt(localStorage.getItem('a2hs_pageloads') || '0', 10)
    loadCount++
    localStorage.setItem('a2hs_pageloads', loadCount)

    // Listen for install prompt only after threshold
    if (loadCount >= 5) {
      window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault()
        this.deferredPrompt = e
        this.promptBoxTarget.classList.remove('hidden')
      }, { once: true }) // Listen once only
    }
  }

  install() {
    if (this.deferredPrompt) {
      this.deferredPrompt.prompt()
      this.deferredPrompt.userChoice.then((choiceResult) => {
        if (choiceResult.outcome === 'accepted') {
          localStorage.setItem('a2hs_installed', '1')
          console.log('User accepted the A2HS prompt')
        } else {
          console.log('User dismissed the A2HS prompt')
        }
        this.cleanupPrompt()
      })
    }
  }

  dismiss() {
    localStorage.setItem('a2hs_dismissed', '1')
    this.cleanupPrompt()
  }

  cleanupPrompt() {
    this.promptBoxTarget.classList.add('hidden')
    this.deferredPrompt = null
  }
}
