function adminStockOut() {
  return {
    // ── Item search ───────────────────────────────────────────────────────────
    searchQuery: "",
    searchResults: [],
    _searchTimer: null,
    selected: null,

    // ── Current entry ─────────────────────────────────────────────────────────
    qty: "",
    reason: "used",
    receiptId: "",
    sellingPrice: "",
    notes: "",
    entryError: "",

    // ── Pending list ──────────────────────────────────────────────────────────
    pending: [],

    // ── Submit state ──────────────────────────────────────────────────────────
    submitting: false,
    error: "",

    // ── Camera scanner ────────────────────────────────────────────────────────
    scannerActive: false,
    _scanner: null,

    // ── Search ────────────────────────────────────────────────────────────────

    async onSearchInput() {
      const q = this.searchQuery;
      if (q.length < 2) { this.searchResults = []; return; }
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(async () => {
        try {
          const res = await InventoryAPI.searchItems(q);
          this.searchResults = res.data;
        } catch { this.searchResults = []; }
      }, 300);
    },

    async onSearchEnter() {
      if (!this.searchQuery.trim()) return;
      clearTimeout(this._searchTimer);
      try {
        const res = await InventoryAPI.findByBarcode(this.searchQuery.trim());
        this.pickItem(res.data);
      } catch {
        const res = await InventoryAPI.searchItems(this.searchQuery.trim()).catch(() => ({ data: [] }));
        this.searchResults = res.data;
      }
    },

    pickItem(item) {
      if (item.is_active === false) return;
      this.selected      = item;
      this.receiptId     = '';
      this.sellingPrice  = item.selling_price ?? "";
      if (this.reason === "expired" && !this.canUseExpired(item)) this.reason = "used";
      this.qty           = "";
      this.notes         = "";
      this.entryError    = "";
      this.searchQuery   = "";
      this.searchResults = [];
      this.$nextTick(() => {
        this.$el.querySelector?.("[data-qty-input]")?.focus();
      });
    },

    clearSelected() {
      this.selected     = null;
      this.receiptId    = '';
      this.qty          = "";
      this.sellingPrice = "";
      this.notes        = "";
      this.entryError   = "";
    },

    canUseExpired(item = this.selected) {
      return !!item && !["pet_shop", "miscellaneous"].includes(item.category);
    },

    remainingBatches(item = this.selected) {
      return (item?.current_batches || []).map(batch => ({...batch,
        remaining_quantity: Math.max(0, Number(batch.available_quantity ?? batch.remaining_quantity) - this.pending
          .filter(p => p.item_id === item.item_id).flatMap(p => p.receipt_allocations || [])
          .filter(pick => pick.receipt_id === batch.transaction_id).reduce((sum, pick) => sum + pick.quantity, 0)),
      }));
    },

    get receiptOptions() {
      return this.remainingBatches().filter(batch => batch.remaining_quantity > 0 && (
        this.reason === 'expired' ? batch.is_expired : ['used', 'sold'].includes(this.reason)
          ? !this.canUseExpired() || batch.is_unexpired : true
      )).sort((a, b) => (this.canUseExpired() ? String(a.expiry_date || '9999').localeCompare(b.expiry_date || '9999') : 0)
        || String(a.received_at || '').localeCompare(b.received_at || '') || a.transaction_id - b.transaction_id);
    },

    receiptLabel(batch) {
      return [batch.batch_number, batch.stock_in_reference || `Receipt #${batch.transaction_id}`, batch.expiry_date,
        `${batch.remaining_quantity} ${this.selected?.unit || ''}`].filter(Boolean).join(' · ');
    },

    get showReceiptSelector() {
      return this.reason === 'expired' || (!!this.receiptOptions.length && (this.receiptOptions.length > 1
        || ['damaged', 'adjustment'].includes(this.reason) || Number(this.selected?.untracked_quantity) > 0));
    },

    availableForReason(item = this.selected) {
      if (!item) return 0;
      const pending = this.pending.filter(p => p.item_id === item.item_id);
      const physical = Number(item.quantity_on_hand || 0) - pending.reduce((sum, p) => sum + Number(p.quantity), 0);
      const reserved = Number(item.reserved_quantity || 0);
      let available = Math.max(0, physical - reserved);
      if (['used', 'sold'].includes(this.reason)) {
        const consumed = pending.reduce((sum, p) => sum + (p.receipt_allocations
          ? p.receipt_allocations.reduce((sum, pick) => sum + ((item.current_batches || []).find(b => b.transaction_id === pick.receipt_id)?.is_unexpired || !this.canUseExpired(item) ? pick.quantity : 0), 0)
          : Number(p.quantity)), 0);
        const usable = this.canUseExpired(item) ? Number(item.usable_quantity ?? item.unexpired_quantity ?? 0) : Number(item.quantity_on_hand || 0);
        available = Math.min(available, Math.max(0, usable - reserved - consumed));
      }
      if (this.reason === 'expired' && !this.receiptId) return 0;
      if (this.receiptId) {
        const receipt = this.receiptOptions.find(batch => String(batch.transaction_id) === String(this.receiptId));
        available = Math.min(available, Number(receipt?.remaining_quantity || 0));
        if (receipt?.is_unexpired && reserved > 0) {
          const usable = this.remainingBatches().filter(b => b.is_unexpired).reduce((sum, b) => sum + b.remaining_quantity, 0);
          available = Math.min(available, Math.max(0, usable - reserved));
        }
      }
      return Math.max(0, Math.floor(available));
    },

    get quantityError() { return this.receiptError ? '' : window.ProductForm.wholeQuantityError(this.qty, this.availableForReason(), this.selected?.unit || 'units'); },
    get receiptError() { return this.reason === 'expired' && !this.receiptId ? 'Select the expired stock receipt.' : ''; },
    get canAdd() { return !!this.selected && !this.quantityError && !this.receiptError && !this.submitting; },

    pendingAllocations(quantity) {
      const allocations = [];
      for (const batch of this.receiptOptions) {
        if (this.receiptId && String(batch.transaction_id) !== String(this.receiptId)) continue;
        if (quantity <= 0) break;
        const picked = Math.min(quantity, batch.remaining_quantity);
        allocations.push({receipt_id: batch.transaction_id, quantity: picked});
        quantity -= picked;
      }
      return allocations;
    },

    // ── Add to pending ────────────────────────────────────────────────────────

    addToPending() {
      this.entryError = "";
      if (!this.selected) { this.entryError = "Select an item first."; return; }
      if (this.reason === "expired" && !this.canUseExpired()) {
        this.reason = "used";
      }

      const qty = Number(this.qty);
      if (this.receiptError || this.quantityError) { this.entryError = this.receiptError || this.quantityError; return; }
      const allocations = this.pendingAllocations(qty);

      const existing = this.pending.filter(p => p.item_id === this.selected.item_id).at(-1);
      if (existing?.reason === this.reason && String(existing.receipt_id || '') === String(this.receiptId)) {
        existing.quantity      += qty;
        existing.receipt_allocations.push(...allocations);
        if (this.reason === "sold" && this.sellingPrice !== "") {
          existing.selling_price = parseFloat(this.sellingPrice);
        }
        if (this.notes.trim())        existing.notes         = this.notes.trim();
      } else {
        this.pending.push({
          item_id:       this.selected.item_id,
          item_name:     this.selected.item_name,
          unit:          this.selected.unit,
          category:      this.selected.category,
          quantity:      qty,
          reason:        this.reason,
          receipt_id: this.receiptId ? Number(this.receiptId) : null,
          receipt_label: this.receiptId ? this.receiptLabel(this.receiptOptions.find(b => String(b.transaction_id) === String(this.receiptId))) : null,
          receipt_allocations: allocations,
          selling_price: this.reason === "sold" && this.sellingPrice !== ""
            ? parseFloat(this.sellingPrice)
            : null,
          notes:         this.notes.trim() || null,
        });
      }

      this.clearSelected();
    },

    removePending(idx) {
      this.pending.splice(idx, 1);
    },

    // ── Camera scanner ────────────────────────────────────────────────────────

    openScanner() {
      ProductForm.openBarcodeScanner(this, "stockout-qr-reader", (decoded) => {
        this.closeScanner();
        this.searchQuery = decoded;
        this.onSearchEnter();
      });
    },

    closeScanner() {
      ProductForm.closeBarcodeScanner(this);
    },

    // ── Submit ────────────────────────────────────────────────────────────────

    async submit() {
      if (this.submitting) return;
      this.error = "";
      if (!this.pending.length) { this.error = "Add at least one item first."; return; }

      this.submitting = true;
      try {
        await InventoryAPI.stockOut(
          this.pending.map(p => ({
            item_id:       p.item_id,
            quantity:      p.quantity,
            reason:        p.reason,
            receipt_id:    p.receipt_id || null,
            selling_price: p.selling_price,
            notes:         p.notes,
          }))
        );
        window.showSuccessToast('Stock-out recorded successfully.');
        this.pending    = [];
        this.clearSelected();
      } catch (err) {
        this.error = err.message || "Failed to record stock-out.";
        window.showToast(this.error, 'error');
      } finally {
        this.submitting = false;
      }
    },
  };
}
