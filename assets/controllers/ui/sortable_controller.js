import { Controller } from '@hotwired/stimulus';

/**
 * Sortable controller — enables drag-to-reorder for list items.
 *
 * Uses pointer events (mousedown / mousemove / mouseup) instead of the
 * HTML5 Drag-and-Drop API, which is unreliable when list items contain
 * form controls (<input>, <select>, etc.).
 *
 * Dragging is restricted to the handle element (.wizard-category-item__handle).
 * New items added dynamically are automatically initialized via the Stimulus
 * `itemTargetConnected` lifecycle callback.
 */
export default class extends Controller {
    static targets = ['list', 'item'];

    connect() {
        this.draggedItem = null;
        this.placeholder = null;
        this.offsetY = 0;
        this.initialY = 0;

        this._onPointerMove = this.onPointerMove.bind(this);
        this._onPointerUp = this.onPointerUp.bind(this);
    }

    disconnect() {
        document.removeEventListener('pointermove', this._onPointerMove);
        document.removeEventListener('pointerup', this._onPointerUp);
    }

    /**
     * Stimulus lifecycle: called for each item target, including those
     * added dynamically after connect (e.g. via form-collection).
     */
    itemTargetConnected(item) {
        const handle = item.querySelector('.wizard-category-item__handle');
        if (handle) {
            handle.addEventListener('pointerdown', (e) => this.onPointerDown(e, item));
        }
    }

    onPointerDown(event, item) {
        // Only respond to primary button
        if (event.button !== 0) return;
        event.preventDefault();

        this.draggedItem = item;

        const rect = item.getBoundingClientRect();
        this.offsetY = event.clientY - rect.top;
        this.initialY = rect.top;

        // Create a placeholder to hold the space
        this.placeholder = document.createElement('li');
        this.placeholder.className = 'wizard-category-item wizard-category-item--placeholder mb-3';
        this.placeholder.style.height = rect.height + 'px';
        item.parentNode.insertBefore(this.placeholder, item);

        // Make the dragged item float
        item.classList.add('is-dragging');
        item.style.position = 'fixed';
        item.style.zIndex = '9999';
        item.style.width = rect.width + 'px';
        item.style.left = rect.left + 'px';
        item.style.top = rect.top + 'px';
        item.style.pointerEvents = 'none';

        document.addEventListener('pointermove', this._onPointerMove);
        document.addEventListener('pointerup', this._onPointerUp);
    }

    onPointerMove(event) {
        if (!this.draggedItem) return;

        // Move the dragged item with the cursor
        this.draggedItem.style.top = (event.clientY - this.offsetY) + 'px';

        // Find which sibling the pointer is over and move the placeholder
        const list = this.hasListTarget ? this.listTarget : this.placeholder.parentNode;
        const siblings = Array.from(list.querySelectorAll(':scope > [data-ui--sortable-target="item"]:not(.is-dragging), :scope > .wizard-category-item--placeholder'));

        for (const sibling of siblings) {
            if (sibling === this.placeholder) continue;
            const box = sibling.getBoundingClientRect();
            const midY = box.top + box.height / 2;

            if (event.clientY < midY) {
                list.insertBefore(this.placeholder, sibling);
                return;
            }
        }

        // Past all items — move placeholder to the end
        list.appendChild(this.placeholder);
    }

    onPointerUp(event) {
        if (!this.draggedItem) return;

        document.removeEventListener('pointermove', this._onPointerMove);
        document.removeEventListener('pointerup', this._onPointerUp);

        // Drop the item where the placeholder is
        const list = this.hasListTarget ? this.listTarget : this.placeholder.parentNode;
        list.insertBefore(this.draggedItem, this.placeholder);

        // Clean up
        this.placeholder.remove();
        this.placeholder = null;

        this.draggedItem.classList.remove('is-dragging');
        this.draggedItem.style.position = '';
        this.draggedItem.style.zIndex = '';
        this.draggedItem.style.width = '';
        this.draggedItem.style.left = '';
        this.draggedItem.style.top = '';
        this.draggedItem.style.pointerEvents = '';
        this.draggedItem = null;

        // Reindex form field names
        this.updateFieldIndices(list);
    }

    /**
     * Reindex form field names after reorder so Symfony receives them in the correct order.
     */
    updateFieldIndices(list) {
        const items = Array.from(list.querySelectorAll(':scope > [data-ui--sortable-target="item"]'));
        items.forEach((item, newIndex) => {
            const fields = item.querySelectorAll('input, select, textarea');
            fields.forEach(field => {
                if (field.name) {
                    field.name = field.name.replace(
                        /\[categories]\[\d+]/,
                        `[categories][${newIndex}]`
                    );
                }
                if (field.id) {
                    field.id = field.id.replace(
                        /categories_\d+/,
                        `categories_${newIndex}`
                    );
                }
            });
            const labels = item.querySelectorAll('label');
            labels.forEach(label => {
                if (label.htmlFor) {
                    label.htmlFor = label.htmlFor.replace(
                        /categories_\d+/,
                        `categories_${newIndex}`
                    );
                }
            });
        });
    }
}

