function adminPos() {
  return {
    searchQuery: "", searchResults: [], searching: false, noResults: false,
    _searchTimer: null, _searchVersion: 0,
    cart: [], amountTendered: "", notes: "",
    submitting: false, validating: false, validationFailed: false, error: "", notice: "",
    receipt: null, receiptOpen: false,
    scannerActive: false, _scanner: null,

    openScanner() {
      if (this.submitting || this.validating || this.receiptOpen || this.scannerActive) return;
      this.cancelSearch();
      window.ProductForm.openBarcodeScanner(this, "pos-qr-reader", (barcode) => {
        if (!this.scannerActive) return;
        this.closeScanner();
        this.searchQuery = barcode;
        return this.scanBarcode(barcode);
      });
    },
    closeScanner() {
      window.ProductForm.closeBarcodeScanner(this);
      this.$nextTick(() => this.$refs.productSearch?.focus());
    },

    onSearchInput() {
      clearTimeout(this._searchTimer);
      const version = ++this._searchVersion;
      const q = this.searchQuery.trim();
      this.searchResults = [];
      this.noResults = false;
      this.searching = false;
      if (q.length < 2) return;
      this.searching = true;
      this._searchTimer = setTimeout(async () => {
        try {
          const res = await InventoryAPI.searchItems(q, false, "pos");
          if (version !== this._searchVersion) return;
          this.searchResults = res.data.sort((a, b) => Number(this.hasProductStock(b)) - Number(this.hasProductStock(a)));
          this.noResults = !this.searchResults.length;
        } catch (err) {
          if (version === this._searchVersion) this.error = err.message || "Could not search products. Try again.";
        } finally {
          if (version === this._searchVersion) this.searching = false;
        }
      }, 300);
    },

    async scanBarcode(code = this.searchQuery.trim()) {
      if (!code || this.submitting) return;
      clearTimeout(this._searchTimer);
      const version = ++this._searchVersion;
      this.searching = true;
      this.error = "";
      try {
        const res = await InventoryAPI.findByBarcode(code, false, "pos");
        if (version !== this._searchVersion) return;
        this.addToCart(res.data);
      } catch (err) {
        if (version === this._searchVersion) this.error = err.status === 404 ? "No active, priced retail product matches this barcode." : err.message || "No retail product matches this barcode.";
      } finally {
        if (version === this._searchVersion) this.searching = false;
      }
    },

    searchEnter() {
      const exact = this.searchResults.find(item => item.barcode === this.searchQuery.trim());
      if (exact) this.addToCart(exact);
      else if (/^\d+$/.test(this.searchQuery.trim())) this.scanBarcode();
      else if (this.searchResults.length === 1) this.addToCart(this.searchResults[0]);
      else this.focusResult();
    },

    focusResult() {
      this.$refs.results?.querySelector('button:not(:disabled)')?.focus();
    },

    moveResult(event, direction) {
      const buttons = Array.from(this.$refs.results.querySelectorAll('button:not(:disabled)'));
      const next = buttons[buttons.indexOf(event.target) + direction];
      if (next) next.focus();
      else if (direction < 0) this.$refs.productSearch.focus();
    },

    cancelSearch() {
      clearTimeout(this._searchTimer);
      ++this._searchVersion;
      this.searchResults = [];
      this.searching = false;
      this.noResults = false;
    },

    productAvailability(item) { return Math.max(0, Number(item.saleable_quantity ?? 0)); },
    isRetailProduct(item) {
      return item.is_active && ["food", "grooming_supply", "pet_shop", "miscellaneous"].includes(item.category)
        && Number(item.selling_price) > 0 && Number(item.selling_price) <= 999999.99;
    },
    hasProductStock(item) { return this.isRetailProduct(item) && this.productAvailability(item) >= 1; },

    addToCart(item) {
      if (this.submitting || this.validating || this.receiptOpen) return;
      if (!this.isRetailProduct(item)) { this.error = "This product is not available for retail sale."; return; }
      const available = this.productAvailability(item);
      if (available <= 0) { this.error = `${item.item_name} is out of stock.`; return; }
      if (available < 1) { this.error = `${item.item_name} has insufficient stock for one whole unit.`; return; }
      const existing = this.cart.find(line => line.item_id === item.item_id);
      if (existing) {
        if (!Number.isInteger(Number(existing.quantity)) || Number(existing.quantity) < 1) {
          this.error = `Enter a whole quantity of at least 1 for ${item.item_name}.`; return;
        }
        const nextQuantity = Number(existing.quantity) + 1;
        if (nextQuantity > available) { this.error = `Cannot add another ${item.item_name}. Saleable stock: ${available} ${item.unit}.`; return; }
        existing.stock = available;
        existing.quantity = nextQuantity;
        this.updateLineSubtotal(existing);
      } else {
        const quantity = 1;
        this.cart.push({ item_id: item.item_id, item_name: item.item_name, unit: item.unit,
          stock: available, quantity, price: Number(item.selling_price),
          subtotal: Math.round(quantity * Number(item.selling_price) * 100) / 100, unavailable: false });
      }
      this.searchQuery = "";
      this.cancelSearch();
      this.error = "";
      this.$nextTick(() => this.$refs.productSearch?.focus());
    },

    lineError(line) {
      const quantity = Number(line.quantity);
      if (line.unavailable) return "Product inactive, unpriced, or unavailable for retail sale. Remove it to continue.";
      if (!Number.isInteger(quantity) || quantity < 1)
        return "Enter a whole quantity of at least 1.";
      if (quantity > line.stock) return `Only ${line.stock} ${line.unit} of saleable stock available. Reduce the quantity.`;
      return "";
    },
    updateLineSubtotal(line) {
      line.subtotal = Math.round((Number(line.quantity) || 0) * line.price * 100) / 100;
    },
    removeFromCart(idx) { if (!this.submitting && !this.validating) this.cart.splice(idx, 1); },
    clearCart() {
      if (this.submitting || this.validating) return;
      this.cart = []; this.amountTendered = ""; this.notes = ""; this.error = ""; this.notice = ""; this.validationFailed = false;
    },

    reconcileCart(items) {
      const current = new Map(items.map(item => [Number(item.item_id), item]));
      const changes = [];
      for (const line of this.cart) {
        const item = current.get(Number(line.item_id));
        const stock = item ? this.productAvailability(item) : 0;
        const price = Number(item?.selling_price ?? 0);
        const unavailable = !item || !this.isRetailProduct(item);
        const details = [];
        if (line.stock !== stock) details.push(`stock changed from ${line.stock} to ${stock} ${line.unit}`);
        if (line.price !== price) details.push(`price changed from ₱${this.fmt(line.price)} to ₱${this.fmt(price)}`);
        if (unavailable && !line.unavailable) details.push("no longer available for retail sale");
        if (details.length) changes.push(`${line.item_name}: ${details.join('; ')}`);
        line.stock = stock;
        line.price = price;
        line.unavailable = unavailable;
        // Keep the entered quantity; staff must correct insufficient stock themselves.
        this.updateLineSubtotal(line);
      }
      this.notice = changes.length ? `${changes.join('. ')}. Review the updated cart before completing the sale again.` : "";
      return changes.length > 0;
    },

    async revalidateCart() {
      if (this.validating || !this.cart.length) return false;
      this.validating = true; this.validationFailed = false; this.error = "";
      try {
        const response = await InventoryAPI.validateCart({
          items: this.cart.map(line => ({ item_id: line.item_id, quantity: Number(line.quantity) })),
        });
        return !this.reconcileCart(response.data);
      } catch (err) {
        this.validationFailed = !err.status || err.status >= 500;
        this.error = this.validationFailed
          ? "Could not check current stock and prices. Retry validation before completing the sale."
          : Object.values(err.errors || {}).flat()[0] || err.message || "Could not validate the cart.";
        return false;
      } finally { this.validating = false; }
    },

    get totalAmount() { return Math.round(this.cart.reduce((sum, line) => sum + (Number(line.subtotal) || 0), 0) * 100) / 100; },
    get paymentMaximumAmount() { return window.CashPayment.maximumFor(this.totalAmount); },
    get changeAmount() { return window.CashPayment.change(this.amountTendered, this.totalAmount); },
    get cashError() { return window.CashPayment.error(this.amountTendered, this.totalAmount); },
    enforcePaymentAmountLimit(event = null) {
      const value = event?.target?.value ?? this.amountTendered;
      const capped = window.CashPayment.capInput(value, this.totalAmount);
      if (capped !== value) {
        this.amountTendered = capped;
        if (event?.target) event.target.value = capped;
      }
    },
    get canProcess() {
      return !this.validating && !this.validationFailed && this.cart.length > 0 && !this.cart.some(line => this.lineError(line))
        && this.totalAmount > 0 && this.totalAmount <= 999999.99 && !this.cashError;
    },

    async processSale() {
      if (this.submitting || this.validating || this.receiptOpen) return;
      this.error = "";
      if (!this.canProcess) { this.error = this.cart.map(line => this.lineError(line)).find(Boolean) || this.cashError || "Review the cart and supported total before completing the sale."; return; }
      this.submitting = true;
      try {
        if (!await this.revalidateCart()) return;
        if (!this.canProcess) { this.error = this.cashError || "Review the updated cart before completing the sale again."; return; }
        const res = await InventoryAPI.processSale({
          items: this.cart.map(line => ({ item_id: line.item_id, quantity: Number(line.quantity), expected_price: line.price, expected_stock: line.stock })),
          amount_tendered: Number(this.amountTendered), notes: this.notes || null,
        });
        this.completePosSale(res.data);
      } catch (err) {
        if (err.code === "POS_CART_CHANGED" || err.data?.code === "POS_CART_CHANGED") {
          this.reconcileCart(err.data.data);
          return;
        }
        this.error = err.status === 0 || err.status >= 500
          ? "Sale could not be confirmed. Check Records → Transactions before retrying if the connection was interrupted."
          : Object.values(err.errors || {}).flat()[0] || err.message || "Sale failed. Please try again.";
      } finally { this.submitting = false; }
    },

    completePosSale(receipt) {
      this.receipt = receipt;
      this.cart = []; this.amountTendered = ""; this.notes = ""; this.notice = "";
      this.searchQuery = ""; this.cancelSearch();
      this.receiptOpen = true;
      this.$nextTick(() => { this.$refs.successDialog.showModal(); });
      window.dispatchEvent?.(new CustomEvent('inventory:changed'));
    },
    closeSuccess() { this.$refs.successDialog?.close(); this.receiptOpen = false; this.cancelSearch(); },
    newSale() {
      this.closeSuccess(); this.receipt = null; this.clearCart(); this.searchQuery = "";
      this.$nextTick(() => this.$refs.productSearch?.focus());
    },
    printInvoice() { if (this.receipt) window.ProductSaleInvoice.print(); },
    fmt(n) { return Number(n ?? 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    destroy() { if (this.scannerActive) this.closeScanner(); this.closeSuccess(); },
    saleDate(value) {
      return value ? new Intl.DateTimeFormat("en-PH", { timeZone: "Asia/Manila", month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" }).format(new Date(value)) : "";
    },
  };
}
