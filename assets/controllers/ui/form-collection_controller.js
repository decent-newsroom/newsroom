import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ["collectionContainer"]

  static values = {
    index    : Number,
    prototype: String,
    wrapSortable: { type: Boolean, default: false },
  }

  addCollectionElement(event)
  {
    const item = document.createElement('li');
    const rendered = this.prototypeValue.replace(/__name__/g, this.indexValue);

    if (this.wrapSortableValue) {
      // Wrap in the sortable category item structure
      item.className = 'wizard-category-item mb-3';
      item.setAttribute('data-ui--sortable-target', 'item');

      // Parse the rendered fields to separate the select from the rest
      const tempDiv = document.createElement('div');
      tempDiv.innerHTML = rendered;

      // The first .form-group or <div> containing the select is the existingListCoordinate field
      const selectField = tempDiv.querySelector('.category-source-select');
      const selectRow = selectField ? selectField.closest('div.mb-3, div[class]') || selectField.parentElement : null;

      let selectHtml = '';
      let restHtml = '';

      if (selectRow) {
        selectHtml = selectRow.outerHTML;
        selectRow.remove();
        restHtml = tempDiv.innerHTML;
      } else {
        restHtml = rendered;
      }

      item.innerHTML =
        '<div class="wizard-category-item__handle" title="Drag to reorder">⠿</div>' +
        '<div class="wizard-category-item__content">' +
          '<div class="wizard-category-item__fields" data-controller="ui--category-toggle">' +
            selectHtml +
            '<div class="wizard-category-item__new-fields" data-ui--category-toggle-target="newFields">' +
              restHtml +
            '</div>' +
          '</div>' +
        '</div>' +
        '<button type="button" class="btn btn-sm btn-danger wizard-category-item__remove" ' +
          'data-action="click->ui--form-collection#removeCollectionElement" title="Remove category">✕</button>';
    } else {
      item.innerHTML = rendered;
    }

    this.collectionContainerTarget.appendChild(item);
    this.indexValue++;
  }

  removeCollectionElement(event)
  {
    const item = event.target.closest('li');
    if (item) {
      item.remove();
    }
  }
}
