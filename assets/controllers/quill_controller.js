import { Controller } from '@hotwired/stimulus';
import Quill from 'quill';

import('quill/dist/quill.core.css');
import('quill/dist/quill.snow.css');

export default class extends Controller {
  connect() {
    // --- 1) Custom IMG blot that supports alt ---
    const BlockEmbed = Quill.import('blots/block/embed');
    class ImageAltBlot extends BlockEmbed {
      static blotName = 'imageAlt';   // if you want to replace default, rename to 'image'
      static tagName = 'IMG';

      static create(value) {
        const node = super.create();
        if (typeof value === 'string') {
          node.setAttribute('src', value);
        } else if (value && value.src) {
          node.setAttribute('src', value.src);
          if (value.alt) node.setAttribute('alt', value.alt);
        }
        node.setAttribute('draggable', 'false');
        return node;
      }

      static value(node) {
        return {
          src: node.getAttribute('src') || '',
          alt: node.getAttribute('alt') || '',
        };
      }
    }
    Quill.register(ImageAltBlot);

    // --- 2) Tooltip UI (modeled on Quill's link tooltip) ---
    const Tooltip = Quill.import('ui/tooltip');
    class ImageTooltip extends Tooltip {
      constructor(quill, boundsContainer) {
        super(quill, boundsContainer);
        this.root.classList.add('ql-image-tooltip');
        this.root.innerHTML = [
          '<span class="ql-tooltip-arrow"></span>',
          '<div class="ql-tooltip-editor">',
          '<input class="ql-image-src" type="text" placeholder="Image URL" />',
          '<input class="ql-image-alt" type="text" placeholder="Alt text" />',
          '<a class="ql-action"></a>',
          '<a class="ql-cancel"></a>',
          '</div>',
        ].join('');

        this.srcInput = this.root.querySelector('.ql-image-src');
        this.altInput = this.root.querySelector('.ql-image-alt');
        this.action = this.root.querySelector('.ql-action');
        this.cancel = this.root.querySelector('.ql-cancel');

        this.action.addEventListener('click', () => this.save());
        this.cancel.addEventListener('click', () => this.hide());

        const keyHandler = (e) => {
          if (e.key === 'Enter') { e.preventDefault(); this.save(); }
          if (e.key === 'Escape') { e.preventDefault(); this.hide(); }
        };
        this.srcInput.addEventListener('keydown', keyHandler);
        this.altInput.addEventListener('keydown', keyHandler);
      }

      edit(prefill = null) {
        const range = this.quill.getSelection(true);
        if (!range) return;

        const bounds = this.quill.getBounds(range);
        this.show();
        this.position(bounds);

        this.root.classList.add('ql-editing');
        this.srcInput.value = prefill?.src || '';
        this.altInput.value = prefill?.alt || '';
        this.srcInput.focus();
        this.srcInput.select();
      }

      hide() {
        this.root.classList.remove('ql-editing');
        super.hide();
      }

      save() {
        const src = (this.srcInput.value || '').trim();
        const alt = (this.altInput.value || '').trim();

        // basic safety: allow http(s) or data:image/*
        if (!src || !/^https?:|^data:image\//i.test(src)) {
          this.srcInput.focus();
          return;
        }

        const range = this.quill.getSelection(true);
        if (!range) return;

        // If selection is on existing ImageAlt blot, replace it; otherwise insert new
        const [blot, blotOffset] = this.quill.getLeaf(range.index);
        const isImageBlot = blot && blot.domNode && blot.domNode.tagName === 'IMG';

        if (isImageBlot) {
          // delete current, insert new one
          const idx = range.index - blotOffset;
          this.quill.deleteText(idx, 1, 'user');
          this.quill.insertEmbed(idx, 'imageAlt', { src, alt }, 'user');
          this.quill.setSelection(idx + 1, 0, 'user');
        } else {
          this.quill.insertEmbed(range.index, 'imageAlt', { src, alt }, 'user');
          this.quill.setSelection(range.index + 1, 0, 'user');
        }
        this.hide();
      }
    }

    // --- 3) Quill init ---
    const toolbarOptions = [
      ['bold', 'italic', 'strike'],
      ['link', 'blockquote', 'code-block', 'image'],
      [{ header: 1 }, { header: 2 }, { header: 3 }],
      [{ list: 'ordered' }, { list: 'bullet' }],
    ];

    const options = {
      theme: 'snow',
      modules: {
        toolbar: toolbarOptions,
      },
    };

    // Use the element in this controller's scope
    const editorEl = this.element.querySelector('#editor') || document.querySelector('#editor');
    const target = this.element.querySelector('#editor_content') || document.querySelector('#editor_content');

    const quill = new Quill(editorEl, options);

    // One tooltip instance per editor
    const imageTooltip = new ImageTooltip(quill, quill.root.parentNode);

    // Intercept toolbar 'image' to open our tooltip
    quill.getModule('toolbar').addHandler('image', () => {
      // If caret is on an IMG, prefill from it
      const range = quill.getSelection(true);
      let prefill = null;
      if (range) {
        const [blot] = quill.getLeaf(range.index);
        if (blot?.domNode?.tagName === 'IMG') {
          prefill = {
            src: blot.domNode.getAttribute('src') || '',
            alt: blot.domNode.getAttribute('alt') || '',
          };
        }
      }
      imageTooltip.edit(prefill);
    });


    // Nostr highlights
    // Match common bech32 nostr URIs
    const NOSTR_GLOBAL = /\bnostr:(?:note1|npub1|nprofile1|nevent1|naddr1|nrelay1|nsec1)[a-z0-9]+/gi;

    function highlightAll() {
      const text = quill.getText();                // includes trailing \n
      // Clear JUST the background attribute; leaves bold/italics/etc intact
      quill.formatText(0, text.length, { background: false }, 'api');

      for (const m of text.matchAll(NOSTR_GLOBAL)) {
        quill.formatText(m.index, m[0].length, { background:  'rgba(168, 85, 247, 0.18)' }, 'api');
      }
    }

    // 1) First load
    highlightAll();

    // 2) Keep it fresh on edits/paste
    quill.on('text-change', (delta, oldDelta, source) => {
      if (source === 'user') highlightAll();
    });


    // Keep your hidden field synced as HTML
    const sync = () => { if (target) target.value = quill.root.innerHTML; };
    quill.on('text-change', sync);
    // initialize once
    sync();
  }
}
