function adminStockIn() {
  return {
    // ── Item search ───────────────────────────────────────────────────────────
    searchQuery: "",
    searchResults: [],
    noResults: false,
    _searchTimer: null,
    selected: null,

    // ── Inline create product ─────────────────────────────────────────────────
    showCreateForm: false,
    createForm: ProductForm.empty(),
    createBusy: false,
    createError: "",

    // ── Current entry ─────────────────────────────────────────────────────────
    qty: "",
    unitCost: "",
    reason: "purchase",
    batchNumber: "",
    batchUnavailable: false,
    batchUnavailableReason: "",
    expiryDate: "",
    today: (() => {
      const now = new Date();
      now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
      return now.toISOString().slice(0, 10);
    })(),
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
    _scanTarget: "search",

    // ── Search ────────────────────────────────────────────────────────────────

    async onSearchInput() {
      const q = this.searchQuery;
      if (q.length < 2) {
        this.searchResults = [];
        this.noResults     = false;
        this.showCreateForm = false;
        return;
      }
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(async () => {
        try {
          const res = await InventoryAPI.searchItems(q, true);
          this.searchResults = res.data;
          this.noResults     = res.data.length === 0;
          if (!this.noResults) this.showCreateForm = false;
        } catch {
          this.searchResults = [];
          this.noResults     = false;
        }
      }, 300);
    },

    async onSearchEnter() {
      if (!this.searchQuery.trim()) return;
      clearTimeout(this._searchTimer);
      try {
        const res = await InventoryAPI.findByBarcode(this.searchQuery.trim());
        this.noResults = false;
        this.pickItem(res.data);
      } catch {
        const res = await InventoryAPI.searchItems(this.searchQuery.trim(), true).catch(() => ({ data: [] }));
        this.searchResults = res.data;
        this.noResults     = res.data.length === 0;
      }
    },

    pickItem(item) {
      if (item.is_active === false) return;
      this.selected       = item;
      this.unitCost       = item.unit_cost ?? "";
      this.qty            = "";
      this.batchNumber    = "";
      this.batchUnavailable = false;
      this.batchUnavailableReason = "";
      this.expiryDate     = "";
      this.notes          = "";
      this.entryError     = "";
      this.searchQuery    = "";
      this.searchResults  = [];
      this.noResults      = false;
      this.showCreateForm = false;
      this.$nextTick(() => {
        this.$el.querySelector?.("[data-qty-input]")?.focus();
      });
    },

    clearSelected() {
      this.selected    = null;
      this.qty         = "";
      this.unitCost    = "";
      this.batchNumber = "";
      this.batchUnavailable = false;
      this.batchUnavailableReason = "";
      this.expiryDate  = "";
      this.notes       = "";
      this.entryError  = "";
    },

    get needsExpiry() {
      return !!this.selected && !["pet_shop", "miscellaneous"].includes(this.selected.category);
    },

    get needsManufacturerLot() {
      return !!this.selected && ['medicine', 'vaccine'].includes(this.selected.category);
    },

    // ── Inline create product ─────────────────────────────────────────────────

    openCreateForm() {
      this.createForm = ProductForm.empty({ item_name: this.searchQuery.trim() });
      this.createError    = "";
      this.showCreateForm = true;
    },

    cancelCreate() {
      this.showCreateForm = false;
      this.createError    = "";
    },

    handleCreateBarcodeInput(event) {
      const barcode = ProductForm.normalizeBarcode(event.target.value);
      this.createForm.barcode = barcode;
      event.target.value = barcode;
    },

    openCreateBarcodeScanner() {
      this.openScanner("create-form");
    },

    async createAndSelect() {
      this.createError = "";
      if (ProductForm.isWeightBased(this.createForm) && !ProductForm.checkWeightSchedule(this.createForm)) {
        this.$nextTick(() => ProductForm.focusWeightError(this.$el?.querySelector('section[aria-label="Weight Price Schedule"]')));
        return;
      }
      this.createError = ProductForm.validate(this.createForm);
      if (this.createError) return;

      this.createBusy = true;
      try {
        const payload = ProductForm.payload(this.createForm);
        const res     = await InventoryAPI.createItem(payload);
        const newItem = { ...res.data, _newly_created: true };
        this.pickItem(newItem);
        window.showSuccessToast('Product added.');
      } catch (err) {
        this.createError = err.errors
          ? Object.values(err.errors).flat().join(" ")
          : (err.message || "Failed to create product.");
        window.showToast('Unable to create product.', 'error');
      } finally {
        this.createBusy = false;
      }
    },

    // Undo while item is still in the chip (before adding to pending)
    async undoSelected() {
      if (!this.selected?._newly_created) return;
      try {
        await InventoryAPI.deactivateItem(this.selected.item_id);
      } catch { /* best-effort: item may be gone already */ }
      this.clearSelected();
      this.noResults      = false;
      this.showCreateForm = false;
    },

    // Undo after item was added to the pending list
    async undoCreatedItem(idx) {
      const p = this.pending[idx];
      if (!p._newly_created) return;
      try {
        await InventoryAPI.deactivateItem(p.item_id);
      } catch { /* best-effort */ }
      this.pending.splice(idx, 1);
    },

    // ── Add to pending ────────────────────────────────────────────────────────

    addToPending() {
      this.entryError = "";
      if (!this.selected)        { this.entryError = "Select an item first.";       return; }
      const qty = Number(this.qty);
      if (!Number.isInteger(qty) || qty <= 0) {
        this.entryError = "Enter a whole number quantity.";
        return;
      }
      if (this.needsExpiry && !this.expiryDate) {
        this.entryError = "Expiry date is required.";
        return;
      }
      if (this.needsExpiry && this.expiryDate < this.today) {
        this.entryError = "Expiry date cannot be in the past.";
        return;
      }
      const batch = this.batchUnavailable ? '' : this.batchNumber.trim();
      const unavailable = this.batchUnavailable ? this.batchUnavailableReason.trim() : '';
      if (this.needsManufacturerLot && ((!batch && !unavailable)
        || /^(?:N\/?A|NONE|UNKNOWN|UNAVAILABLE|NOT AVAILABLE|NOT APPLICABLE|-)$/i.test(batch))) {
        this.entryError = 'Enter the manufacturer lot or explain why it is unavailable.';
        return;
      }
      if (batch.length > 100 || unavailable.length > 255) {
        this.entryError = 'Lot must be at most 100 characters; reason at most 255.';
        return;
      }

      const existing = this.pending.find(p =>
        p.item_id === this.selected.item_id
        && (p.batch_number ?? "") === batch
        && (p.batch_unavailable_reason ?? "") === unavailable
        && p.reason === this.reason
        && (p.expiry_date ?? "") === this.expiryDate
      );
      if (existing) {
        existing.quantity += qty;
        if (this.unitCost !== "") existing.unit_cost = parseFloat(this.unitCost);
        if (this.notes.trim())    existing.notes     = this.notes.trim();
      } else {
        this.pending.push({
          item_id:        this.selected.item_id,
          item_name:      this.selected.item_name,
          unit:           this.selected.unit,
          category:       this.selected.category,
          quantity:       qty,
          unit_cost:      this.unitCost !== "" ? parseFloat(this.unitCost) : null,
          reason:         this.reason,
          batch_number:   batch || null,
          batch_unavailable_reason: unavailable || null,
          expiry_date:    this.needsExpiry ? (this.expiryDate || null) : null,
          notes:          this.notes.trim() || null,
          _newly_created: this.selected._newly_created ?? false,
        });
      }

      this.clearSelected();
    },

    removePending(idx) {
      this.pending.splice(idx, 1);
    },

    // ── Submit ────────────────────────────────────────────────────────────────

    async submit() {
      if (this.submitting) return;
      this.error = "";
      if (!this.pending.length) { this.error = "Add at least one item first."; return; }

      this.submitting = true;
      try {
        await InventoryAPI.stockIn(this.pending.map(p => ({
          item_id:      p.item_id,
          quantity:     p.quantity,
          reason:       p.reason,
          unit_cost:    p.unit_cost,
          batch_number: p.batch_number,
          batch_unavailable_reason: p.batch_unavailable_reason ?? null,
          expiry_date:  p.expiry_date,
          notes:        p.notes,
        })));
        window.showSuccessToast("Stock-in recorded successfully.");
        this.pending    = [];
        this.clearSelected();
      } catch (err) {
        this.error = err.message || "Failed to record stock-in.";
        window.showToast(this.error, 'error');
      } finally {
        this.submitting = false;
      }
    },

    // ── Camera scanner ────────────────────────────────────────────────────────

    openScanner(target = "search") {
      this._scanTarget = target;
      ProductForm.openBarcodeScanner(this, "stockin-qr-reader", (decoded) => this.onScanned(decoded));
    },

    closeScanner() {
      ProductForm.closeBarcodeScanner(this);
    },

    onScanned(barcode) {
      this.closeScanner();
      if (this._scanTarget === "create-form") {
        this.createForm.barcode = barcode;
        return;
      }
      this.searchQuery = barcode;
      this.onSearchEnter();
    },
  };
}
