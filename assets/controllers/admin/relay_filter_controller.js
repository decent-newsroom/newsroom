import { Controller } from '@hotwired/stimulus'

/**
 * Client-side filter/search for relay health tables.
 *
 * Targets
 *   search    — <input> that filters rows by relay URL
 *   row       — <tr> elements, each carrying data attributes:
 *                 data-relay-url        URL string (lowercased comparison)
 *                 data-relay-muted      "true" | "false"
 *                 data-relay-healthy    "true" | "false"
 *                 data-relay-configured "true" | "false" | (omit if unused)
 *                 data-relay-purpose    purpose string (omit if unused)
 *   count     — element whose text is updated with "N of M relays"
 *
 * Filter buttons carry data-filter-value and data-filter-btn attributes.
 * Active button gets the relay-filter-btn--active class.
 */
export default class extends Controller {
    static targets = ['search', 'row', 'count']
    static values = { activeFilter: { type: String, default: 'all' } }

    connect () {
        this._applyFilters()
    }

    search () {
        this._applyFilters()
    }

    setFilter (event) {
        this.element.querySelectorAll('[data-filter-btn]').forEach(btn =>
            btn.classList.remove('relay-filter-btn--active')
        )
        event.currentTarget.classList.add('relay-filter-btn--active')
        this.activeFilterValue = event.currentTarget.dataset.filterValue ?? 'all'
        this._applyFilters()
    }

    _applyFilters () {
        const query = this.hasSearchTarget
            ? this.searchTarget.value.toLowerCase().trim()
            : ''
        const filter = this.activeFilterValue

        let visible = 0
        const total = this.rowTargets.length

        this.rowTargets.forEach(row => {
            const url = (row.dataset.relayUrl ?? '').toLowerCase()
            const muted = row.dataset.relayMuted === 'true'
            const healthy = row.dataset.relayHealthy === 'true'
            const configured = row.dataset.relayConfigured === 'true'
            const purpose = row.dataset.relayPurpose ?? ''

            const matchesSearch = !query || url.includes(query)

            let matchesFilter = true
            if (filter === 'muted') {
                matchesFilter = muted
            } else if (filter === 'unhealthy') {
                matchesFilter = !healthy && !muted
            } else if (filter === 'configured') {
                matchesFilter = configured
            } else if (filter === 'discovered') {
                matchesFilter = !configured
            } else if (filter !== 'all') {
                // purpose tag filter (index page)
                matchesFilter = purpose === filter
            }

            const show = matchesSearch && matchesFilter
            row.hidden = !show
            if (show) visible++
        })

        if (this.hasCountTarget) {
            const label = `relay${total !== 1 ? 's' : ''}`
            this.countTarget.textContent = visible === total
                ? `${total} ${label}`
                : `${visible} of ${total} ${label}`
        }
    }
}

