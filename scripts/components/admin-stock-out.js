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
      this.selected      = item;
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
      this.qty          = "";
      this.sellingPrice = "";
      this.notes        = "";
      this.entryError   = "";
    },

    canUseExpired(item = this.selected) {
      return !!item && !["pet_shop", "miscellaneous"].includes(item.category);
    },

    availableForReason(item = this.selected) {
      if (!item) return 0;
      const pendingQuantity = this.pending
        .filter(pending => pending.item_id === item.item_id)
        .reduce((sum, pending) => sum + Number(pending.quantity), 0);
      const physicalQuantity = parseFloat(item.quantity_on_hand ?? 0);

      return Math.max(0, Math.round((physicalQuantity - pendingQuantity) * 100) / 100);
    },

    // ── Add to pending ────────────────────────────────────────────────────────

    addToPending() {
      this.entryError = "";
      if (!this.selected) { this.entryError = "Select an item first."; return; }
      if (this.reason === "expired" && !this.canUseExpired()) {
        this.reason = "used";
      }

      const qty = Number(this.qty);
      if (!Number.isInteger(qty) || qty <= 0) {
        this.entryError = "Enter a whole number quantity.";
        return;
      }

      const available = this.availableForReason();
      if (qty > available) {
        this.entryError = "Insufficient stock. Please check available inventory.";
        return;
      }

      const existing = this.pending.find(p =>
        p.item_id === this.selected.item_id && p.reason === this.reason
      );
      if (existing) {
        existing.quantity      += qty;
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
      this.error = "";
      if (!this.pending.length) { this.error = "Add at least one item first."; return; }

      this.submitting = true;
      try {
        await InventoryAPI.stockOut(
          this.pending.map(p => ({
            item_id:       p.item_id,
            quantity:      p.quantity,
            reason:        p.reason,
            selling_price: p.selling_price,
            notes:         p.notes,
          }))
        );
        window.showSuccessToast(`Stock-out recorded for ${this.pending.length} item(s).`);
        this.pending    = [];
        this.clearSelected();
      } catch (err) {
        this.error = err.message || "Failed to record stock-out.";
      } finally {
        this.submitting = false;
      }
    },
  };
}
