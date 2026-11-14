// Alpine.js is loaded from /js/alpine.min.js (bundled build of alpinejs 3.15).
// Shared Alpine helpers live here.
document.addEventListener('alpine:init', () => {
  window.Alpine.data('copyText', (text) => ({
    copied: false,
    copy() {
      navigator.clipboard.writeText(text).then(() => { this.copied = true; setTimeout(() => (this.copied = false), 2000); });
    },
  }));
});

// ─────────────────────────────────────────────────────────────────────────────
// xlGrid — Excel-style editable grid used by programme sheets and library programmes.
// Every edit is saved to the server immediately (PATCH per cell); the server is the source of
// truth and returns the updated row, so sign-offs cleared by an edit show at once.
// Keyboard: arrows / Tab move, Enter or typing edits, Esc cancels, Alt+Enter new line,
// Ctrl/Cmd+C copies a cell, Ctrl/Cmd+V pastes a cell or a block copied from Excel.
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('alpine:init', () => {
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;
  const send = async (method, url, body) => {
    const res = await fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const first = data.errors ? Object.values(data.errors)[0] : null;
      throw new Error((Array.isArray(first) ? first[0] : first) || data.message || 'The change could not be saved.');
    }
    return data;
  };

  window.Alpine.data('xlGrid', (cfg) => { let gridEl = null; return ({
    rows: cfg.rows,
    columns: cfg.columns,         // [{key, label, editable, wide, html}]
    custom: cfg.custom || [],     // [{key, label}] auditor-added columns
    sel: null,                    // {r, c}
    editing: false,
    draft: '',
    status: 'saved',
    error: null,
    menu: null,
    flash: null,

    init() {
      gridEl = this.$el.querySelector('.xl-wrap');
      const hash = window.location.hash.replace('#row-', '');
      if (hash) {
        const r = this.rows.findIndex((x) => x.uuid === hash);
        if (r >= 0) { this.sel = { r, c: 0 }; this.$nextTick(() => document.getElementById('row-' + hash)?.scrollIntoView({ block: 'center' })); }
      }
    },

    get allColumns() {
      return [...this.columns, ...this.custom.map((c) => ({ key: 'x:' + c.key, label: c.label, editable: true, custom: c.key }))];
    },
    value(row, col) {
      if (col.key.startsWith('x:')) return row.extra?.[col.key.slice(2)] ?? '';
      return row[col.key] ?? '';
    },
    display(row, col) {
      if (col.html && row[col.key + '_html'] !== undefined) return row[col.key + '_html'];
      const v = this.value(row, col);
      return v === '' ? '' : String(v).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    },
    canEditCell(col) { return cfg.canEdit && col.editable; },
    isSel(r, c) { return this.sel && this.sel.r === r && this.sel.c === c; },
    url(tpl, uuid, action) { return tpl.replace('__ID__', uuid).replace('__ACTION__', action || ''); },

    select(r, c) {
      if (this.editing && !(this.sel.r === r && this.sel.c === c)) this.commit();
      this.sel = { r, c };
      this.menu = null;
      this.$nextTick(() => this.focusGrid());
    },
    begin(initial) {
      if (!this.sel) return;
      const col = this.allColumns[this.sel.c];
      if (!this.canEditCell(col)) { this.toast(col.locked || 'This column is filled by signing off.'); return; }
      this.draft = initial !== undefined ? initial : String(this.value(this.rows[this.sel.r], col));
      this.editing = true;
      this.$nextTick(() => { const el = document.getElementById('xl-editor'); if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); this.autosize(el); } });
    },
    autosize(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight + 2, 320) + 'px'; },
    cancel() { this.editing = false; this.focusGrid(); },
    focusGrid() { gridEl?.focus({ preventScroll: true }); },
    async commit(move) {
      if (!this.editing) return;
      this.editing = false;
      const { r, c } = this.sel;
      const row = this.rows[r];
      const col = this.allColumns[c];
      const before = String(this.value(row, col));
      if (this.draft !== before) await this.save(row, col.key, this.draft);
      if (move) this.move(move[0], move[1]);
      this.focusGrid();
    },
    async save(row, field, value) {
      this.status = 'saving';
      try {
        const data = await send('PATCH', this.url(cfg.urls.row, row.uuid), { field, value });
        this.replace(data.row || data.step);
        this.status = 'saved';
        return true;
      } catch (e) {
        this.status = 'error'; this.toast(e.message, true);
        return false;
      }
    },
    replace(fresh) {
      const i = this.rows.findIndex((x) => x.uuid === fresh.uuid);
      if (i >= 0) {
        const wasSigned = this.rows[i].done_by_id; this.rows[i] = fresh;
        if (wasSigned && !fresh.done_by_id) this.toast('The row changed after sign-off, so its sign-offs were cleared.');
      }
    },
    move(dr, dc) {
      if (!this.sel) { this.sel = { r: 0, c: 0 }; return; }
      const r = Math.max(0, Math.min(this.rows.length - 1, this.sel.r + dr));
      const c = Math.max(0, Math.min(this.allColumns.length - 1, this.sel.c + dc));
      this.sel = { r, c };
      this.$nextTick(() => document.querySelector(`[data-cell="${r}-${c}"]`)?.scrollIntoView({ block: 'nearest', inline: 'nearest' }));
    },
    key(e) {
      if (this.editing) {
        // Keys typed before the editor has focus (fast typing right after the first key) are kept.
        if (e.target.id !== 'xl-editor' && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); this.draft += e.key; return; }
        if (e.key === 'Escape') { e.preventDefault(); this.cancel(); }
        else if (e.key === 'Enter' && (e.altKey || e.shiftKey)) {
          e.preventDefault();
          const el = e.target; const p = el.selectionStart;
          this.draft = this.draft.slice(0, p) + '\n' + this.draft.slice(el.selectionEnd);
          this.$nextTick(() => { el.setSelectionRange(p + 1, p + 1); this.autosize(el); });
        }
        else if (e.key === 'Enter' && !e.altKey && !e.shiftKey) { e.preventDefault(); this.commit([1, 0]); }
        else if (e.key === 'Tab') { e.preventDefault(); this.commit([0, e.shiftKey ? -1 : 1]); }
        return;
      }
      if (!this.sel) return;
      const k = e.key;
      const mod = e.ctrlKey || e.metaKey;
      if (k === 'ArrowDown') { e.preventDefault(); this.move(1, 0); }
      else if (k === 'ArrowUp') { e.preventDefault(); this.move(-1, 0); }
      else if (k === 'ArrowRight' || (k === 'Tab' && !e.shiftKey)) { e.preventDefault(); this.move(0, 1); }
      else if (k === 'ArrowLeft' || (k === 'Tab' && e.shiftKey)) { e.preventDefault(); this.move(0, -1); }
      else if (k === 'Enter' || k === 'F2') { e.preventDefault(); this.begin(); }
      else if (k === 'Delete' || k === 'Backspace') {
        e.preventDefault();
        const col = this.allColumns[this.sel.c];
        if (this.canEditCell(col) && this.value(this.rows[this.sel.r], col) !== '') this.save(this.rows[this.sel.r], col.key, '');
      } else if (mod && k.toLowerCase() === 'c') {
        e.preventDefault();
        navigator.clipboard.writeText(String(this.value(this.rows[this.sel.r], this.allColumns[this.sel.c]))).then(() => this.toast('Cell copied'));
      } else if (!mod && !e.altKey && k.length === 1) {
        e.preventDefault(); this.begin(k);
      }
    },
    async paste(e) {
      if (this.editing || !this.sel || !cfg.canEdit) return;
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData('text');
      if (!text) return;
      // A block copied from Excel arrives as tab-separated lines; quoted cells may hold new lines.
      const block = this.parseTsv(text);
      let saved = 0;
      for (let i = 0; i < block.length; i++) {
        let r = this.sel.r + i;
        if (r >= this.rows.length) {
          if (!cfg.canManage || !cfg.urls.add) break;
          await this.addRow(this.rows[this.rows.length - 1]?.uuid, true);
          r = this.rows.length - 1;
        }
        for (let j = 0; j < block[i].length; j++) {
          const col = this.allColumns[this.sel.c + j];
          if (!col || !this.canEditCell(col)) continue;
          if (String(this.value(this.rows[r], col)) === block[i][j]) continue;
          if (!(await this.save(this.rows[r], col.key, block[i][j]))) return;
          saved++;
        }
      }
      if (saved) this.toast(`${saved} cell(s) pasted`);
    },
    parseTsv(text) {
      const rows = []; let row = []; let cell = ''; let q = false;
      text = text.replace(/\r\n/g, '\n').replace(/\n$/, '');
      for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (q) {
          if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++; } else if (ch === '"') q = false; else cell += ch;
        } else if (ch === '"' && cell === '') q = true;
        else if (ch === '\t') { row.push(cell); cell = ''; }
        else if (ch === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; }
        else cell += ch;
      }
      row.push(cell); rows.push(row);
      return rows;
    },

    // ── rows ──
    async addRow(after, quiet) {
      try {
        const data = await send('POST', cfg.urls.add, after ? { after } : {});
        const fresh = data.row || data.step;
        this.rows.push(fresh);
        this.reorder(data.order);
        const r = this.rows.findIndex((x) => x.uuid === fresh.uuid);
        if (!quiet) { this.sel = { r, c: Math.min(1, this.allColumns.length - 1) }; this.$nextTick(() => this.begin('')); }
      } catch (e) { this.toast(e.message, true); }
      this.menu = null;
    },
    async removeRow(row) {
      if (!confirm(`Delete row ${row.ref || ''}? This cannot be undone.`)) return;
      try {
        await send('DELETE', this.url(cfg.urls.row, row.uuid));
        this.rows = this.rows.filter((x) => x.uuid !== row.uuid);
        this.sel = null;
      } catch (e) { this.toast(e.message, true); }
      this.menu = null;
    },
    async moveRow(row, direction) {
      try { const data = await send('POST', this.url(cfg.urls.move, row.uuid), { direction }); this.reorder(data.order); } catch (e) { this.toast(e.message, true); }
      this.menu = null;
    },
    reorder(order) {
      if (!order) return;
      const by = Object.fromEntries(this.rows.map((x) => [x.uuid, x]));
      this.rows = order.map((u) => by[u]).filter(Boolean);
    },
    async signoff(row, action) {
      try { const data = await send('POST', this.url(cfg.urls.signoff, row.uuid, action), {}); this.replace(data.row); this.toast(action === 'unsign' ? 'Sign-off withdrawn' : action === 'review' ? `${row.ref} reviewed` : `${row.ref} signed as done`); }
      catch (e) { this.toast(e.message, true); }
      this.menu = null;
    },
    copyLink(row) {
      navigator.clipboard.writeText(row.token).then(() => this.toast(`Link to ${row.ref} copied — paste it into any WP ref, conclusion or working paper.`));
      this.menu = null;
    },
    async insertToken(token) {
      if (!this.sel) { this.toast('Select a row first.'); return; }
      const row = this.rows[this.sel.r];
      const cur = row.wp_ref || '';
      if (cur.includes(token)) { this.toast('Already linked on this row.'); return; }
      if (await this.save(row, 'wp_ref', (cur ? cur + ' ' : '') + token)) this.toast('Link added to WP ref');
    },

    // ── custom columns ──
    async addColumn() {
      const label = prompt('Name of the new column');
      if (!label) return;
      try { const data = await send('POST', cfg.urls.columns, { label }); this.custom = data.custom; } catch (e) { this.toast(e.message, true); }
    },
    async renameColumn(col) {
      const label = prompt('Rename column', col.label);
      if (!label || label === col.label) return;
      try { const data = await send('PUT', cfg.urls.columns + '/' + col.custom, { label }); this.custom = data.custom; } catch (e) { this.toast(e.message, true); }
    },
    async removeColumn(col) {
      if (!confirm(`Remove the column "${col.label}" and its values?`)) return;
      try { const data = await send('DELETE', cfg.urls.columns + '/' + col.custom); this.custom = data.custom; } catch (e) { this.toast(e.message, true); }
    },

    toast(msg, isError) {
      this.flash = { msg, isError: !!isError };
      clearTimeout(this._t);
      this._t = setTimeout(() => (this.flash = null), isError ? 6000 : 3000);
    },
    get progress() {
      const n = this.rows.length;
      return { n, done: this.rows.filter((x) => x.done_by_id).length, reviewed: this.rows.filter((x) => x.reviewed_by_id).length };
    },
  }); });
});
