import { Controller } from '@hotwired/stimulus';

/**
 * search--advanced-filters controller
 *
 * Lightweight UX helper for the advanced search filters panel.
 * The actual filter state lives in LiveComponent LiveProps; this
 * controller only handles minor client-side niceties.
 */
export default class extends Controller {
    connect() {
        // Nothing needed on connect — panel visibility is server-rendered
        // via the showFilters LiveProp.
    }
}

