function adminInventoryItems() {
  return {
    items: [],
    loading: true,
    loaded: false,
    _loadedQuery: "",
    _listRequest: 0,
    error: "",
    toast: { show: false, msg: "", ok: true },

    // Filters + pagination
    q: "",
    category: "",
    showInactive: false,
    alertFilter: "",
    expiryItems: [],
    currentPage: 1,
    lastPage: 1,
    total: 0,
    perPage: 10,
    _searchTimer: null,

    details: { open: false, loading: false, busy: false, error: "", item: null, name: "", itemId: null },
    _detailsRequest: 0,
    _detailsTrigger: null,

    // Add / edit modal
    modal: { open: false, busy: false, error: "" },
    form: ProductForm.empty(),

    // Deactivate confirm
    deactivateTarget: null,
    deactivateModal: { open: false, busy: false, error: "" },

    // Camera barcode scanner
    scannerActive: false,
    _scanner: null,
    // which field the scanner result goes to: 'search' | 'form'
    _scanTarget: "search",

    async init() {
      await this.load();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    // ── List ────────────────────────────────────────────────────────────────

    async load(page = 1) {
      const query = JSON.stringify([this.q, this.category, this.showInactive, this.alertFilter, page]), request = ++this._listRequest;
      this.loaded = this.loaded && query === this._loadedQuery;
      this.loading = true;
      this.error = "";
      try {
        const res = this.alertFilter === "expiry"
          ? await InventoryAPI.getExpiryAlerts({ page })
          : await InventoryAPI.getItems({ q: this.q, category: this.category, low_stock: this.alertFilter === "low-stock", inactive_only: this.showInactive, page });
        if (request !== this._listRequest) return;
        this.loaded = true;
        this._loadedQuery = query;
        if (this.alertFilter === "expiry") this.expiryItems = res.data;
        else this.items = res.data;
        this.currentPage = res.page;
        this.lastPage    = res.last_page;
        this.total       = res.total;
        if (res.data.length === 0 && page > this.lastPage) {
          await this.load(this.lastPage);
          return;
        }
      } catch (err) {
        if (request !== this._listRequest) return;
        this.error = err.message || "Failed to load items.";
      } finally {
        if (request !== this._listRequest) return;
        this.loading = false;
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      }
    },

    onSearchInput() {
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => this.load(1), 350);
    },

    // On Enter in search box — try exact barcode first, fall back to current list
    async onSearchEnter() {
      if (!this.q.trim()) return;
      try {
        const res = await InventoryAPI.findByBarcode(this.q.trim(), this.showInactive);
        if (this.alertFilter === "low-stock" && !res.data.low_stock) {
          await this.load(1);
          return;
        }
        // Found exact match — replace list with single result
        this.items = [res.data];
        this.currentPage = 1;
        this.lastPage = 1;
        this.total = 1;
      } catch {
        // Not a barcode — do normal search
        await this.load(1);
      }
    },

    prevPage() { if (this.currentPage > 1) this.load(this.currentPage - 1); },
    nextPage() { if (this.currentPage < this.lastPage) this.load(this.currentPage + 1); },

    rowNumber(index) {
      return ((this.currentPage - 1) * this.perPage) + index + 1;
    },

    handleBarcodeInput(event) {
      const barcode = ProductForm.normalizeBarcode(event.target.value);
      this.form.barcode = barcode;
      event.target.value = barcode;
    },

    async openDetails(item, event) {
      const request = ++this._detailsRequest;
      if (event) this._detailsTrigger = event.currentTarget;
      this.details = { open: true, loading: true, busy: false, error: "", item: null, name: item.item_name, itemId: item.item_id };
      this.$nextTick(() => this.$refs?.detailsClose?.focus());
      try {
        const res = await InventoryAPI.getItem(item.item_id);
        if (request !== this._detailsRequest) return;
        this.details.item = res.data;
        this.details.name = res.data.item_name;
      } catch (err) {
        if (request === this._detailsRequest) this.details.error = err.message || "Failed to load product details.";
      } finally {
        if (request === this._detailsRequest) this.details.loading = false;
      }
    },

    closeDetails(restoreFocus = true) {
      ++this._detailsRequest;
      this.details.open = false;
      if (restoreFocus) this.$nextTick(() => {
        if (this._detailsTrigger?.isConnected === false) this.$refs?.productsAdd?.focus();
        else this._detailsTrigger?.focus();
      });
    },

    editDetails() {
      const item = this.details.item;
      if (!item) return;
      this.closeDetails(false);
      this.openEdit(item);
      this.$nextTick(() => this.$refs?.productForm?.querySelector("input")?.focus());
    },

    detailsKeydown(event) {
      if (event.key === "Escape") {
        event.preventDefault();
        this.closeDetails();
      } else if (event.key === "Tab") {
        const buttons = Array.from(this.$refs.detailsDialog.querySelectorAll("button:not(:disabled)"))
          .filter(button => button.getClientRects().length > 0);
        const first = buttons[0], last = buttons[buttons.length - 1];
        if (event.shiftKey && event.target === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && event.target === last) {
          event.preventDefault();
          first.focus();
        }
      }
    },

    fmtMoney(value) {
      return value === null || value === undefined || value === "" ? "—" : "₱" + Number(value).toFixed(2);
    },

    fmtExpiry(value) {
      if (!value) return "—";
      return new Date(value + "T00:00:00").toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" });
    },

    batchStatusStyle(status) {
      if (status === "Expired") return "background-color:#fee2e2;color:#b91c1c";
      if (status === "Expiring Soon" || status === "Expiry unknown") return "background-color:#fef3c7;color:#92400e";
      return "background-color:#dcfce7;color:#166534";
    },

    // ── Add / Edit modal ────────────────────────────────────────────────────

    openAdd() {
      this.form = ProductForm.empty();
      this.modal = { open: true, busy: false, error: "" };
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    openEdit(item) {
      this.form = ProductForm.empty({
        item_id:       item.item_id,
        item_name:     item.item_name,
        barcode:       item.barcode ?? "",
        category:      item.category,
        ...ProductForm.unitFields(item.unit),
        ...ProductForm.clinicFields(item),
        description:   item.description ?? "",
        unit_cost:     item.unit_cost ?? "",
        selling_price: item.selling_price ?? "",
        reorder_level: ProductForm.normalizeReorderLevel(item.reorder_level),
      });
      this.modal = { open: true, busy: false, error: "" };
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    async submitForm() {
      this.modal.error = "";
      if (ProductForm.isWeightBased(this.form) && !ProductForm.checkWeightSchedule(this.form)) {
        this.$nextTick(() => ProductForm.focusWeightError(this.$refs?.productForm?.querySelector('section[aria-label="Weight Price Schedule"]')));
        return;
      }
      this.modal.error = ProductForm.validate(this.form);
      if (this.modal.error) return;

      this.modal.busy = true;
      try {
        const payload = ProductForm.payload(this.form);
        const editing = Boolean(this.form.item_id);
        if (this.form.item_id) {
          await InventoryAPI.updateItem(this.form.item_id, payload);
        } else {
          await InventoryAPI.createItem(payload);
        }
        this.modal.open = false;
        await this.load(this.currentPage);
        this.showToast(editing ? "Product updated successfully." : "Product added successfully.");
      } catch (err) {
        this.modal.error = err.errors
          ? Object.values(err.errors).flat().join(" ")
          : (err.message || "Failed to save");
      } finally {
        this.modal.busy = false;
      }
    },

    // ── Deactivate ──────────────────────────────────────────────────────────

    confirmDeactivate(item) {
      this.deactivateTarget = item;
      this.deactivateModal = { open: true, busy: false, error: "" };
    },

    async doDeactivate() {
      if (!this.deactivateTarget) return;
      this.deactivateModal.busy = true;
      this.deactivateModal.error = "";
      try {
        await InventoryAPI.deactivateItem(this.deactivateTarget.item_id);
        this.deactivateModal.open = false;
        this.showToast("Item deactivated");
        await this.load(this.currentPage);
      } catch (err) {
        this.deactivateModal.error = err.message || "Failed to deactivate.";
      } finally {
        this.deactivateModal.busy = false;
      }
    },

    async doReactivate(item) {
      const inDetails = this.details.open && this.details.item?.item_id === item.item_id;
      const detailsRequest = this._detailsRequest;
      if (inDetails) this.details.busy = true;
      try {
        const res = await InventoryAPI.reactivateItem(item.item_id);
        this.showToast("Item reactivated");
        if (inDetails && this.details.open && detailsRequest === this._detailsRequest) {
          this.details.item = { ...this.details.item, ...res.data };
          this.details.name = res.data.item_name;
        }
        await this.load(this.currentPage);
      } catch (err) {
        this.showToast(err.message || "Failed to reactivate.", false);
      } finally {
        if (inDetails && detailsRequest === this._detailsRequest) {
          this.details.busy = false;
          if (this.details.open) this.$nextTick(() => this.$refs?.detailsClose?.focus());
        }
      }
    },

    // ── Camera barcode scanner ──────────────────────────────────────────────

    openScanner(target = "form") {
      this._scanTarget   = target;
      ProductForm.openBarcodeScanner(this, "inv-qr-reader", (decoded) => this.onScanned(decoded));
    },

    closeScanner() {
      ProductForm.closeBarcodeScanner(this);
    },

    onScanned(barcode) {
      this.closeScanner();
      if (this._scanTarget === "form") {
        this.form.barcode = barcode;
      } else {
        this.q = barcode;
        this.onSearchEnter();
      }
    },

    // ── Helpers ─────────────────────────────────────────────────────────────

    expiryStyle(item) { return InventoryAlerts.expiryStyle(item); },
    expiryLabel(item) { return InventoryAlerts.expiryLabel(item); },

    categoryLabel(cat) {
      return {
        medicine:        "Medicine",
        vaccine:         "Vaccine",
        food:            "Food",
        grooming_supply: "Grooming Supply",
        pet_shop:        "Pet Shop",
        miscellaneous:   "Miscellaneous",
      }[cat] ?? cat;
    },

    categoryColor(cat) {
      return {
        medicine:        "background-color:#dbeafe;color:#1d4ed8",
        vaccine:         "background-color:#ede9fe;color:#6d28d9",
        food:            "background-color:#dcfce7;color:#15803d",
        grooming_supply: "background-color:#fef9c3;color:#a16207",
        pet_shop:        "background-color:#fee2e2;color:#b91c1c",
        miscellaneous:   "background-color:#f1f5f9;color:#475569",
      }[cat] ?? "background-color:#f1f5f9;color:#475569";
    },

    stockStyle(item) {
      if (parseFloat(item.quantity_on_hand) <= 0)
        return "background-color:#fee2e2;color:#b91c1c";
      if (item.low_stock)
        return "background-color:#fef3c7;color:#92400e";
      return "background-color:#dcfce7;color:#166534";
    },

    stockLabel(item) {
      if (parseFloat(item.quantity_on_hand) <= 0) return "Out of Stock";
      if (item.low_stock)                          return "Low Stock";
      return "In Stock";
    },

    fmtQty(n) {
      const v = parseFloat(n ?? 0);
      return v % 1 === 0 ? v.toLocaleString() : v.toFixed(2);
    },

    showToast(msg, ok = true) {
      this.toast = { show: true, msg, ok };
      setTimeout(() => { this.toast.show = false; }, 3000);
    },
  };
}
