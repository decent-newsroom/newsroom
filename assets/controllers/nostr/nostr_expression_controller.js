import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';
import { encodeNaddr } from '../../typescript/nostr-utils.ts';

/**
 * Expression Creator Controller (NIP-EX, kind 30880)
 *
 * Lets users pick a template, customise stages via a visual builder,
 * sign the event with their Nostr signer, publish it, and get a feed URL.
 */
export default class extends Controller {
  static targets = [
    'gallery', 'builder', 'result',
    'titleInput', 'dtagInput', 'contentInput',
    'stagesContainer', 'preview',
    'publishButton', 'feedUrl',
  ];

  static values = {
    publishUrl: String,
    templates: Array,
    feedBaseUrl: String,
    existingEvent: Object,
  };

  /* ------------------------------------------------------------------ */
  /*  Lifecycle                                                          */
  /* ------------------------------------------------------------------ */

  connect() {
    this.stages = [];
    this._existingDTag = null;
    this._previewTimer = null;

    // If editing an existing expression, load it into the builder
    if (this.hasExistingEventValue && this.existingEventValue && Object.keys(this.existingEventValue).length > 0) {
      this._loadExistingEvent(this.existingEventValue);
    }
  }

  /* ------------------------------------------------------------------ */
  /*  Load existing expression for editing                               */
  /* ------------------------------------------------------------------ */

  _loadExistingEvent(event) {
    const tags = event.tags || [];

    // Extract metadata
    let title = '';
    let summary = '';
    let dtag = '';

    for (const tag of tags) {
      if (tag[0] === 'title' && tag[1]) title = tag[1];
      if (tag[0] === 'summary' && tag[1]) summary = tag[1];
      if (tag[0] === 'd' && tag[1]) dtag = tag[1];
    }

    // Fallback: use d-tag as title display hint only
    this._existingDTag = dtag;
    this.titleInputTarget.value = title;
    this.dtagInputTarget.value = dtag;
    this.contentInputTarget.value = event.content || summary;

    // Parse tags into stages (skip d, title, summary, alt)
    const stageTags = tags.filter(t => !['d', 'title', 'summary', 'alt'].includes(t[0]));
    this.stages = this._parseTags(stageTags);

    this._renderStages();
    this.updatePreview();
    this.galleryTarget.style.display = 'none';
    this.builderTarget.style.display = '';
    this.resultTarget.style.display = 'none';
  }

  /* ------------------------------------------------------------------ */
  /*  Template selection                                                 */
  /* ------------------------------------------------------------------ */

  selectTemplate(event) {
    const id = event.currentTarget.dataset.templateId;
    const tpl = this.templatesValue.find(t => t.id === id);
    if (!tpl) return;

    this._existingDTag = null;
    this.titleInputTarget.value = tpl.title || tpl.name;
    this.dtagInputTarget.value = '';
    this.contentInputTarget.value = tpl.content || '';

    // Parse tags into stages
    this.stages = this._parseTags(tpl.tags);

    this._renderStages();
    this.updatePreview();
    this.galleryTarget.style.display = 'none';
    this.builderTarget.style.display = '';
    this.resultTarget.style.display = 'none';
  }

  backToTemplates() {
    this.galleryTarget.style.display = '';
    this.builderTarget.style.display = 'none';
  }

  reset() {
    this.stages = [];
    this._existingDTag = null;
    this.titleInputTarget.value = '';
    this.dtagInputTarget.value = '';
    this.contentInputTarget.value = '';
    this.galleryTarget.style.display = '';
    this.builderTarget.style.display = 'none';
    this.resultTarget.style.display = 'none';
  }

  /* ------------------------------------------------------------------ */
  /*  Stage management                                                   */
  /* ------------------------------------------------------------------ */

  addStage() {
    this.stages.push({ op: 'all', tags: [] });
    this._renderStages();
    this.updatePreview();
  }

  removeStage(event) {
    const idx = parseInt(event.currentTarget.dataset.stageIndex, 10);
    this.stages.splice(idx, 1);
    this._renderStages();
    this.updatePreview();
  }

  updateStageOp(event) {
    const idx = parseInt(event.currentTarget.dataset.stageIndex, 10);
    this.stages[idx].op = event.currentTarget.value;

    // Reset tags when switching operation type
    const opType = this._opType(event.currentTarget.value);
    if (opType === 'sort') {
      this.stages[idx].tags = [['sort-ns', 'tag'], ['sort-field', 'published_at'], ['sort-dir', 'desc']];
    } else if (opType === 'slice') {
      this.stages[idx].tags = [['slice-offset', '0'], ['slice-limit', '20']];
    } else if (opType === 'traversal') {
      // Preserve any existing `input` tags (traversal ops are single-input but may be
      // the first stage); drop only sort/slice scaffolding. Reset modifier to none.
      const keptInputs = this.stages[idx].tags.filter(t => t[0] === 'input');
      this.stages[idx].tags = [['traversal-modifier', ''], ...keptInputs];
    } else {
      // Keep existing tags or start fresh
      if (this.stages[idx].tags.length === 0 ||
          this.stages[idx].tags[0][0] === 'sort-ns' ||
          this.stages[idx].tags[0][0] === 'slice-offset' ||
          this.stages[idx].tags[0][0] === 'traversal-modifier') {
        // Preserve any existing inputs when leaving traversal mode.
        this.stages[idx].tags = this.stages[idx].tags.filter(t => t[0] === 'input');
      }
    }
    this._renderStages();
    this.updatePreview();
  }

  addClause(event) {
    const idx = parseInt(event.currentTarget.dataset.stageIndex, 10);
    this.stages[idx].tags.push(['match', 'prop', 'kind', '30023']);
    this._renderStages();
    this.updatePreview();
  }

  updateTraversalModifier(event) {
    const idx = parseInt(event.currentTarget.dataset.stageIndex, 10);
    const stage = this.stages[idx];
    if (!stage) return;
    const existing = stage.tags.find(t => t[0] === 'traversal-modifier');
    if (existing) {
      existing[1] = event.currentTarget.value;
    } else {
      stage.tags.unshift(['traversal-modifier', event.currentTarget.value]);
    }
    this.updatePreview();
  }

  addInput(event) {
    const idx = parseInt(event.currentTarget.dataset.stageIndex, 10);
    this.stages[idx].tags.push(['input', 'e', '']);
    this._renderStages();
    this.updatePreview();
  }

  removeClause(event) {
    const stageIdx = parseInt(event.currentTarget.dataset.stageIndex, 10);
    const clauseIdx = parseInt(event.currentTarget.dataset.clauseIndex, 10);
    this.stages[stageIdx].tags.splice(clauseIdx, 1);
    this._renderStages();
    this.updatePreview();
  }

  updateClause(event) {
    const stageIdx = parseInt(event.currentTarget.dataset.stageIndex, 10);
    const clauseIdx = parseInt(event.currentTarget.dataset.clauseIndex, 10);
    const field = event.currentTarget.dataset.field;
    const value = event.currentTarget.value;

    const tag = this.stages[stageIdx].tags[clauseIdx];
    if (!tag) return;

    let needsRerender = false;

    if (field === 'type') {
      needsRerender = true;

      // Extract logical fields from the SOURCE clause before rebuilding.
      //
      // Clause shapes are not structurally compatible across types:
      //   input     [type, inputType, value]
      //   match/not [type, ns, sel,  value]          // 4 slots
      //   cmp       [type, ns, sel,  comparator, value]   // 5 slots
      //   text      [type, ns, sel,  mode,       value]   // 5 slots
      //
      // A positional copy (tag[3] → new tag[3]) silently promotes the match
      // VALUE into the cmp COMPARATOR slot (e.g. "30023" ending up as the
      // comparator), which was impossible to fix via the UI because the
      // comparator dropdown would fall back to the default on blur while the
      // serialized tag still carried the stale value. Mapping semantically
      // below preserves ns/sel/value across type changes and uses type-
      // appropriate defaults for comparator/mode/inputType.
      const src = { inputType: null, ns: null, sel: null, comparator: null, mode: null, value: null };
      switch (tag[0]) {
        case 'input':
          src.inputType = tag[1];
          src.value = tag[2];
          break;
        case 'match':
        case 'not':
          src.ns = tag[1];
          src.sel = tag[2];
          src.value = tag[3];
          break;
        case 'cmp':
          src.ns = tag[1];
          src.sel = tag[2];
          src.comparator = tag[3];
          src.value = tag[4];
          break;
        case 'text':
          src.ns = tag[1];
          src.sel = tag[2];
          src.mode = tag[3];
          src.value = tag[4];
          break;
      }

      if (value === 'input') {
        this.stages[stageIdx].tags[clauseIdx] = ['input', src.inputType || 'e', src.value || ''];
      } else if (value === 'match' || value === 'not') {
        this.stages[stageIdx].tags[clauseIdx] = [value, src.ns || 'prop', src.sel || 'kind', src.value || ''];
      } else if (value === 'cmp') {
        this.stages[stageIdx].tags[clauseIdx] = [value, src.ns || 'tag', src.sel || 'published_at', src.comparator || 'gte', src.value || '7d'];
      } else if (value === 'text') {
        this.stages[stageIdx].tags[clauseIdx] = [value, src.ns || 'tag', src.sel || 'title', src.mode || 'contains-ci', src.value || ''];
      }
    } else if (field === 'namespace') {
      tag[1] = value;
    } else if (field === 'selector') {
      tag[2] = value;
    } else if (field === 'comparator') {
      tag[3] = value;
    } else if (field === 'value') {
      // For clause types with values in different positions
      const clauseType = tag[0];
      if (clauseType === 'input') {
        tag[2] = value;
      } else if (clauseType === 'match' || clauseType === 'not') {
        tag[3] = value;
      } else if (clauseType === 'cmp' || clauseType === 'text') {
        tag[4] = value;
      }
    } else if (field === 'input-type') {
      tag[1] = value;
    } else if (field === 'sort-ns') {
      tag[1] = value;
    } else if (field === 'sort-field') {
      tag[2] = value;
    } else if (field === 'sort-dir') {
      tag[3] = value;
    } else if (field === 'slice-offset') {
      tag[1] = value;
    } else if (field === 'slice-limit') {
      tag[2] = value;
    }

    if (needsRerender) {
      this._renderStages();
    }
    this.updatePreview();
  }

  /* ------------------------------------------------------------------ */
  /*  Sort/Slice helpers — update inline                                 */
  /* ------------------------------------------------------------------ */

  updateSortField(event) {
    const idx = parseInt(event.currentTarget.dataset.stageIndex, 10);
    const field = event.currentTarget.dataset.field;
    const stage = this.stages[idx];
    const meta = stage.tags.find(t => t[0] === field);
    if (meta) {
      meta[1] = event.currentTarget.value;
    }
    this.updatePreview();
  }

  updateSliceField(event) {
    const idx = parseInt(event.currentTarget.dataset.stageIndex, 10);
    const field = event.currentTarget.dataset.field;
    const stage = this.stages[idx];
    const meta = stage.tags.find(t => t[0] === field);
    if (meta) {
      meta[1] = event.currentTarget.value;
    }
    this.updatePreview();
  }

  /* ------------------------------------------------------------------ */
  /*  Preview                                                            */
  /* ------------------------------------------------------------------ */

  updatePreview() {
    clearTimeout(this._previewTimer);
    this._previewTimer = setTimeout(() => {
      const tags = this._buildTags();
      const event = {
        kind: 30880,
        content: this.contentInputTarget.value,
        tags: tags,
      };
      this.previewTarget.textContent = JSON.stringify(event, null, 2);
    }, 300);
  }

  /* ------------------------------------------------------------------ */
  /*  Publish                                                            */
  /* ------------------------------------------------------------------ */

  async publish(event) {
    event.preventDefault();

    const title = this.titleInputTarget.value.trim();
    if (!title) {
      this._toast('Please enter a title for the expression.', 'danger');
      return;
    }

    let signer;
    try {
      this._toast('Connecting to signer...', 'info');
      signer = await getSigner();
    } catch (e) {
      this._toast('No Nostr signer available. Please connect Amber or install a Nostr signer extension.', 'danger');
      return;
    }

    this.publishButtonTarget.disabled = true;

    try {
      this._toast('Preparing expression event...', 'info');
      const pubkey = await signer.getPublicKey();

      const tags = this._buildTags();

      const skeleton = {
        kind: 30880,
        created_at: Math.floor(Date.now() / 1000),
        tags: tags,
        content: this.contentInputTarget.value,
        pubkey: pubkey,
      };

      this._toast('Requesting signature...', 'info');
      console.log('[expression] Signing event:', skeleton);
      const signedEvent = await signer.signEvent(skeleton);
      console.log('[expression] Event signed:', signedEvent);

      this._toast('Publishing expression...', 'info');
      const result = await this._sendToBackend(signedEvent);
      console.log('[expression] Publish result:', result);

      // Build naddr and show the feed URL
      const dTag = this._extractDTag(tags);
      const naddr = encodeNaddr(30880, pubkey, dTag);
      const feedUrl = this.feedBaseUrlValue.replace('__NADDR__', naddr);

      this.feedUrlTarget.value = feedUrl;
      this.builderTarget.style.display = 'none';
      this.resultTarget.style.display = '';

      this._toast('Expression published successfully!', 'success');

    } catch (error) {
      console.error('[expression] Publishing error:', error);
      this._toast(`Publishing failed: ${error.message}`, 'danger');
    } finally {
      this.publishButtonTarget.disabled = false;
    }
  }

  copyFeedUrl() {
    const url = this.feedUrlTarget.value;
    navigator.clipboard.writeText(url).then(() => {
      this._toast('URL copied to clipboard!', 'success');
    }).catch(() => {
      // Fallback: select the text
      this.feedUrlTarget.select();
      this._toast('Press Ctrl+C to copy', 'info');
    });
  }

  /* ------------------------------------------------------------------ */
  /*  Internal: tag building                                             */
  /* ------------------------------------------------------------------ */

  _buildTags() {
    const title = this.titleInputTarget.value.trim();
    const dtagValue = this.dtagInputTarget.value.trim();
    const dTag = dtagValue || (this._existingDTag ? this._existingDTag : (this._slugify(title) + '-' + Date.now()));
    const tags = [['d', dTag], ['title', title]];

    for (const stage of this.stages) {
      const opType = this._opType(stage.op);

      if (opType === 'sort') {
        const ns = (stage.tags.find(t => t[0] === 'sort-ns') || [])[1] || 'tag';
        const field = (stage.tags.find(t => t[0] === 'sort-field') || [])[1] || 'published_at';
        const dir = (stage.tags.find(t => t[0] === 'sort-dir') || [])[1] || 'desc';
        tags.push(['op', 'sort', ns, field, dir]);
      } else if (opType === 'slice') {
        const offset = (stage.tags.find(t => t[0] === 'slice-offset') || [])[1] || '0';
        const limit = (stage.tags.find(t => t[0] === 'slice-limit') || [])[1] || '20';
        tags.push(['op', 'slice', offset, limit]);
      } else if (opType === 'dedup') {
        tags.push(['op', 'distinct']);
      } else if (opType === 'traversal') {
        const modifier = (stage.tags.find(t => t[0] === 'traversal-modifier') || [])[1] || '';
        if (modifier) {
          tags.push(['op', stage.op, modifier]);
        } else {
          tags.push(['op', stage.op]);
        }
        // Emit any input tags the user configured (validated server-side: only
        // allowed on a first-stage traversal op, per NIP-GX).
        for (const clause of stage.tags) {
          if (clause[0] === 'input' && clause[2]) {
            tags.push([...clause]);
          }
        }
      } else {
        // Filter or set ops
        tags.push(['op', stage.op]);

        for (const clause of stage.tags) {
          if (clause[0] === 'input') {
            tags.push([...clause]);
          } else if (clause[0] === 'match' || clause[0] === 'not') {
            // Split multi-values by comma
            const values = (clause[3] || '').split(',').map(v => v.trim()).filter(Boolean);
            if (values.length > 0) {
              tags.push([clause[0], clause[1], clause[2], ...values]);
            }
          } else if (clause[0] === 'cmp') {
            tags.push([clause[0], clause[1], clause[2], clause[3], clause[4]]);
          } else if (clause[0] === 'text') {
            tags.push([clause[0], clause[1], clause[2], clause[3], clause[4]]);
          }
        }
      }
    }

    tags.push(['alt', `Expression: ${title}`]);

    const summary = this.contentInputTarget.value.trim();
    if (summary) {
      tags.push(['summary', summary]);
    }

    return tags;
  }

  _extractDTag(tags) {
    for (const tag of tags) {
      if (tag[0] === 'd' && tag[1]) return tag[1];
    }
    return '';
  }

  _slugify(text) {
    return text
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')
      .substring(0, 40) || 'expression';
  }

  /* ------------------------------------------------------------------ */
  /*  Internal: parse template tags into stages                          */
  /* ------------------------------------------------------------------ */

  _parseTags(tags) {
    const stages = [];
    let current = null;

    for (const tag of tags) {
      if (tag[0] === 'op') {
        if (current) stages.push(current);

        const op = tag[1] || tag[0];
        const opType = this._opType(op);

        if (opType === 'sort') {
          current = {
            op,
            tags: [
              ['sort-ns', tag[2] || 'tag'],
              ['sort-field', tag[3] || 'published_at'],
              ['sort-dir', tag[4] || 'desc'],
            ],
          };
        } else if (opType === 'slice') {
          current = {
            op,
            tags: [
              ['slice-offset', tag[2] || '0'],
              ['slice-limit', tag[3] || '20'],
            ],
          };
        } else if (opType === 'traversal') {
          // NIP-GX: ["op","ancestor","root"] or ["op","descendant","leaves"].
          // parent/child have no modifier.
          current = {
            op,
            tags: [['traversal-modifier', tag[2] || '']],
          };
        } else {
          current = { op, tags: [] };
        }
      } else if (current) {
        current.tags.push([...tag]);
      }
    }

    if (current) stages.push(current);
    return stages;
  }

  _opType(op) {
    if (op === 'sort') return 'sort';
    if (op === 'slice') return 'slice';
    if (op === 'distinct') return 'dedup';
    if (['union', 'intersect', 'difference'].includes(op)) return 'set';
    if (['parent', 'child', 'ancestor', 'descendant'].includes(op)) return 'traversal';
    return 'filter'; // all, any, none
  }

  /* ------------------------------------------------------------------ */
  /*  Internal: render stage UI                                          */
  /* ------------------------------------------------------------------ */

  _renderStages() {
    const container = this.stagesContainerTarget;
    container.innerHTML = '';

    this.stages.forEach((stage, idx) => {
      const el = document.createElement('div');
      el.className = 'expression-stage';
      el.innerHTML = this._renderStageHtml(stage, idx);
      container.appendChild(el);
    });
  }

  _renderStageHtml(stage, idx) {
    const opType = this._opType(stage.op);
    let html = `
      <div class="expression-stage__header">
        <span class="expression-stage__number">${idx + 1}</span>
        <select class="form-control form-control-sm"
                data-action="change->nostr--nostr-expression#updateStageOp"
                data-stage-index="${idx}">
          <optgroup label="Filter">
            <option value="all"${stage.op === 'all' ? ' selected' : ''}>all — keep if every clause matches</option>
            <option value="any"${stage.op === 'any' ? ' selected' : ''}>any — keep if any clause matches</option>
            <option value="none"${stage.op === 'none' ? ' selected' : ''}>none — keep if no clause matches</option>
          </optgroup>
          <optgroup label="Sort">
            <option value="sort"${stage.op === 'sort' ? ' selected' : ''}>sort</option>
            <option value="slice"${stage.op === 'slice' ? ' selected' : ''}>slice (paginate)</option>
          </optgroup>
          <optgroup label="Set">
            <option value="union"${stage.op === 'union' ? ' selected' : ''}>union — merge inputs</option>
            <option value="intersect"${stage.op === 'intersect' ? ' selected' : ''}>intersect — keep shared</option>
            <option value="difference"${stage.op === 'difference' ? ' selected' : ''}>difference — subtract</option>
            <option value="distinct"${stage.op === 'distinct' ? ' selected' : ''}>distinct — deduplicate</option>
          </optgroup>
          <optgroup label="Traversal">
            <option value="parent"${stage.op === 'parent' ? ' selected' : ''}>parent — one-hop up</option>
            <option value="child"${stage.op === 'child' ? ' selected' : ''}>child — one-hop down</option>
            <option value="ancestor"${stage.op === 'ancestor' ? ' selected' : ''}>ancestor — all the way up</option>
            <option value="descendant"${stage.op === 'descendant' ? ' selected' : ''}>descendant — all the way down</option>
          </optgroup>
        </select>
        <button class="btn btn-sm btn-danger"
                data-action="click->nostr--nostr-expression#removeStage"
                data-stage-index="${idx}"
                title="Remove stage">&times;</button>
      </div>`;

    if (opType === 'sort') {
      const ns = (stage.tags.find(t => t[0] === 'sort-ns') || [])[1] || 'tag';
      const field = (stage.tags.find(t => t[0] === 'sort-field') || [])[1] || 'published_at';
      const dir = (stage.tags.find(t => t[0] === 'sort-dir') || [])[1] || 'desc';
      html += `
        <div class="expression-stage__body expression-sort-fields">
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateSortField"
                  data-stage-index="${idx}" data-field="sort-ns">
            <option value="tag"${ns === 'tag' ? ' selected' : ''}>tag</option>
            <option value="prop"${ns === 'prop' ? ' selected' : ''}>prop</option>
          </select>
          <input type="text" class="form-control form-control-sm" value="${this._esc(field)}"
                 data-action="input->nostr--nostr-expression#updateSortField"
                 data-stage-index="${idx}" data-field="sort-field"
                 placeholder="Field name" />
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateSortField"
                  data-stage-index="${idx}" data-field="sort-dir">
            <option value="desc"${dir === 'desc' ? ' selected' : ''}>desc</option>
            <option value="asc"${dir === 'asc' ? ' selected' : ''}>asc</option>
          </select>
        </div>`;
    } else if (opType === 'slice') {
      const offset = (stage.tags.find(t => t[0] === 'slice-offset') || [])[1] || '0';
      const limit = (stage.tags.find(t => t[0] === 'slice-limit') || [])[1] || '20';
      html += `
        <div class="expression-stage__body expression-slice-fields">
          <label>Offset</label>
          <input type="number" class="form-control form-control-sm" value="${this._esc(offset)}"
                 data-action="input->nostr--nostr-expression#updateSliceField"
                 data-stage-index="${idx}" data-field="slice-offset" min="0" />
          <label>Limit</label>
          <input type="number" class="form-control form-control-sm" value="${this._esc(limit)}"
                 data-action="input->nostr--nostr-expression#updateSliceField"
                 data-stage-index="${idx}" data-field="slice-limit" min="1" max="500" />
        </div>`;
    } else if (opType === 'dedup') {
      html += `<div class="expression-stage__body"><em>Deduplicates items by canonical identity.</em></div>`;
    } else if (opType === 'traversal') {
      const modifier = (stage.tags.find(t => t[0] === 'traversal-modifier') || [])[1] || '';
      const modifierOptions = stage.op === 'ancestor'
        ? [['', 'all ancestors (nearest first)'], ['root', 'root only']]
        : stage.op === 'descendant'
          ? [['', 'all descendants (DFS)'], ['leaves', 'leaves only']]
          : null;

      html += `<div class="expression-stage__body expression-traversal-fields">`;
      if (modifierOptions) {
        html += `
          <label>Modifier</label>
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateTraversalModifier"
                  data-stage-index="${idx}">
            ${modifierOptions.map(([v, label]) =>
              `<option value="${v}"${modifier === v ? ' selected' : ''}>${label}</option>`
            ).join('')}
          </select>`;
      } else {
        html += `<em>Resolves the ${stage.op === 'parent' ? 'direct parent(s)' : 'direct children'} of each input event (NIP-GX).</em>`;
      }

      // Inputs: only meaningful on the first stage, but render any that exist so
      // the user can see and edit them. Non-first traversal stages MUST have none.
      stage.tags
        .filter(t => t[0] === 'input')
        .forEach((clause) => {
          // Find the real index within stage.tags for removeClause/updateClause
          const realIdx = stage.tags.indexOf(clause);
          html += this._renderClauseHtml(clause, idx, realIdx);
        });

      html += `
        <div class="expression-clause-actions">
          <button class="btn btn-sm btn-secondary"
                  data-action="click->nostr--nostr-expression#addInput"
                  data-stage-index="${idx}">+ Input</button>
          <small class="text-muted">First stage requires an input; later traversal stages consume the previous stage result.</small>
        </div>
      </div>`;
    } else {
      // Filter or set ops — show clauses + inputs
      html += `<div class="expression-stage__body">`;
      stage.tags.forEach((clause, ci) => {
        html += this._renderClauseHtml(clause, idx, ci);
      });
      html += `
        <div class="expression-clause-actions">
          <button class="btn btn-sm btn-secondary"
                  data-action="click->nostr--nostr-expression#addClause"
                  data-stage-index="${idx}">+ Clause</button>
          <button class="btn btn-sm btn-secondary"
                  data-action="click->nostr--nostr-expression#addInput"
                  data-stage-index="${idx}">+ Input</button>
        </div>
      </div>`;
    }

    return html;
  }

  _renderClauseHtml(clause, stageIdx, clauseIdx) {
    const type = clause[0];

    if (type === 'input') {
      const inputType = clause[1] || 'e';
      const inputValue = clause[2] || '';
      return `
        <div class="expression-clause">
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="type">
            <option value="input" selected>input</option>
            <option value="match">match</option>
            <option value="not">not</option>
            <option value="cmp">cmp</option>
            <option value="text">text</option>
          </select>
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="input-type">
            <option value="e"${inputType === 'e' ? ' selected' : ''}>e (event ID)</option>
            <option value="a"${inputType === 'a' ? ' selected' : ''}>a (address)</option>
          </select>
          <input type="text" class="form-control form-control-sm expression-clause__value"
                 value="${this._esc(inputValue)}"
                 data-action="input->nostr--nostr-expression#updateClause"
                 data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="value"
                 placeholder="${inputType === 'e' ? 'Event ID or nevent/note' : '30880:pubkey:d-tag or naddr'}" />
          <button class="btn btn-sm btn-danger"
                  data-action="click->nostr--nostr-expression#removeClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}">&times;</button>
        </div>`;
    }

    if (type === 'match' || type === 'not') {
      const ns = clause[1] || 'prop';
      const selector = clause[2] || '';
      const value = clause[3] || '';
      return `
        <div class="expression-clause">
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="type">
            <option value="input">input</option>
            <option value="match"${type === 'match' ? ' selected' : ''}>match</option>
            <option value="not"${type === 'not' ? ' selected' : ''}>not</option>
            <option value="cmp">cmp</option>
            <option value="text">text</option>
          </select>
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="namespace">
            <option value="prop"${ns === 'prop' ? ' selected' : ''}>prop</option>
            <option value="tag"${ns === 'tag' ? ' selected' : ''}>tag</option>
          </select>
          <input type="text" class="form-control form-control-sm" value="${this._esc(selector)}"
                 data-action="input->nostr--nostr-expression#updateClause"
                 data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="selector"
                 placeholder="Field (e.g. pubkey, kind, t)" />
          <input type="text" class="form-control form-control-sm expression-clause__value"
                 value="${this._esc(value)}"
                 data-action="input->nostr--nostr-expression#updateClause"
                 data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="value"
                 placeholder="Value(s) — comma-separated or $contacts, $me, $interests" />
          <button class="btn btn-sm btn-danger"
                  data-action="click->nostr--nostr-expression#removeClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}">&times;</button>
        </div>`;
    }

    if (type === 'cmp') {
      const ns = clause[1] || 'tag';
      const selector = clause[2] || '';
      const comparator = clause[3] || 'gte';
      const value = clause[4] || '';
      return `
        <div class="expression-clause">
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="type">
            <option value="input">input</option>
            <option value="match">match</option>
            <option value="not">not</option>
            <option value="cmp" selected>cmp</option>
            <option value="text">text</option>
          </select>
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="namespace">
            <option value="tag"${ns === 'tag' ? ' selected' : ''}>tag</option>
            <option value="prop"${ns === 'prop' ? ' selected' : ''}>prop</option>
          </select>
          <input type="text" class="form-control form-control-sm" value="${this._esc(selector)}"
                 data-action="input->nostr--nostr-expression#updateClause"
                 data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="selector"
                 placeholder="Field (e.g. published_at, created_at)" />
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="comparator">
            <option value="gt"${comparator === 'gt' ? ' selected' : ''}>gt</option>
            <option value="gte"${comparator === 'gte' ? ' selected' : ''}>gte</option>
            <option value="lt"${comparator === 'lt' ? ' selected' : ''}>lt</option>
            <option value="lte"${comparator === 'lte' ? ' selected' : ''}>lte</option>
            <option value="eq"${comparator === 'eq' ? ' selected' : ''}>eq</option>
          </select>
          <input type="text" class="form-control form-control-sm expression-clause__value"
                 value="${this._esc(value)}"
                 data-action="input->nostr--nostr-expression#updateClause"
                 data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="value"
                 placeholder="Value (e.g. 7d, 30d, 1712345678)" />
          <button class="btn btn-sm btn-danger"
                  data-action="click->nostr--nostr-expression#removeClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}">&times;</button>
        </div>`;
    }

    if (type === 'text') {
      const ns = clause[1] || 'tag';
      const selector = clause[2] || 'title';
      const mode = clause[3] || 'contains-ci';
      const value = clause[4] || '';
      return `
        <div class="expression-clause">
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="type">
            <option value="input">input</option>
            <option value="match">match</option>
            <option value="not">not</option>
            <option value="cmp">cmp</option>
            <option value="text" selected>text</option>
          </select>
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="namespace">
            <option value="tag"${ns === 'tag' ? ' selected' : ''}>tag</option>
            <option value="prop"${ns === 'prop' ? ' selected' : ''}>prop</option>
          </select>
          <input type="text" class="form-control form-control-sm" value="${this._esc(selector)}"
                 data-action="input->nostr--nostr-expression#updateClause"
                 data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="selector"
                 placeholder="Field (e.g. title, content)" />
          <select class="form-control form-control-sm"
                  data-action="change->nostr--nostr-expression#updateClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="comparator">
            <option value="contains-ci"${mode === 'contains-ci' ? ' selected' : ''}>contains-ci</option>
            <option value="eq-ci"${mode === 'eq-ci' ? ' selected' : ''}>eq-ci</option>
            <option value="prefix-ci"${mode === 'prefix-ci' ? ' selected' : ''}>prefix-ci</option>
          </select>
          <input type="text" class="form-control form-control-sm expression-clause__value"
                 value="${this._esc(value)}"
                 data-action="input->nostr--nostr-expression#updateClause"
                 data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}" data-field="value"
                 placeholder="Search text" />
          <button class="btn btn-sm btn-danger"
                  data-action="click->nostr--nostr-expression#removeClause"
                  data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}">&times;</button>
        </div>`;
    }

    // Fallback for unknown clause types
    return `<div class="expression-clause"><code>${this._esc(JSON.stringify(clause))}</code>
      <button class="btn btn-sm btn-danger"
              data-action="click->nostr--nostr-expression#removeClause"
              data-stage-index="${stageIdx}" data-clause-index="${clauseIdx}">&times;</button></div>`;
  }

  /* ------------------------------------------------------------------ */
  /*  Internal: backend communication                                    */
  /* ------------------------------------------------------------------ */

  async _sendToBackend(signedEvent) {
    const response = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ event: signedEvent }),
    });

    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.error || `HTTP ${response.status}`);
    }

    return response.json();
  }

  /* ------------------------------------------------------------------ */
  /*  Internal: helpers                                                  */
  /* ------------------------------------------------------------------ */

  _esc(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  _toast(message, type = 'info', duration = 4000) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type, duration);
    }
  }
}

