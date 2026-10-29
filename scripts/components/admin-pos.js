function adminPos() {
  return {
    searchQuery: "", searchResults: [], searching: false, noResults: false,
    _searchTimer: null, _searchVersion: 0, productDropdown: null,
    paymentMethod: "cash", onlinePayment: null, verifyingPayment: false, requestKey: null, _posCancelling: false,
    cart: [], amountTendered: "", notes: "",
    submitting: false, openingCheckout: false, validating: false, validationFailed: false, error: "", notice: "",
    receipt: null, receiptOpen: false,
    scannerActive: false, _scanner: null,

    async init() {
      const saved = window.OnlinePayment?.get('pos');
      this.requestKey = saved?.requestKey || this.requestKey;
      if (!saved && !this.onlinePayment) return;
      this.onlinePayment ||= {id: saved?.id, status: 'recovering', amount: this.totalAmount};
      this.paymentMethod = 'online';
      this.submitting = true; this.verifyingPayment = saved?.outcome === 'success';
      try {
        const response = await InventoryAPI.onlinePaymentStatus(saved?.id || this.onlinePayment?.id, this.requestKey);
        await this.applyPosOnlineStatus(response);
        if (saved?.outcome === 'cancel' && this.onlinePayment?.status === 'pending') await this.cancelPosOnlinePayment();
      } catch (error) {
        if (saved?.id && [403, 404].includes(error.status)) {
          window.OnlinePayment.clear('pos'); this.requestKey = null;
          this.onlinePayment = {status: 'recovering', amount: this.totalAmount};
          try {await this.applyPosOnlineStatus(await InventoryAPI.onlinePaymentStatus());}
          catch (retryError) {this.error = retryError.message || 'Could not check online payments.';}
        } else this.error = 'Could not restore Online Payment. Check Status to retry.';
      }
      finally {this.submitting = false;}
    },

    openScanner() {
      if (this.submitting || this.validating || this.onlinePayment || this.receiptOpen || this.scannerActive) return;
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

    showProductDropdown() {
      this.productDropdown ||= ProductSearchDropdown.create(() => {
        clearTimeout(this._searchTimer); ++this._searchVersion; this.searching = false;
      });
      this.productDropdown.show(this.$refs.productSearch, this.$refs.productPopup);
    },
    async focusProducts() {
      if (this.submitting || this.validating || this.onlinePayment || this.receiptOpen) return;
      this.showProductDropdown();
      if (this.searchQuery.trim()) { this.onSearchInput(); return; }
      return this.loadProductResults('', ++this._searchVersion, true);
    },
    async loadProductResults(query, version, suggestions = false) {
      this.searching = true; this.noResults = false; this.searchResults = [];
      try {
        const res = await InventoryAPI.searchItems(query, true, "pos", suggestions);
        if (version !== this._searchVersion) return;
        this.searchResults = (res.data || []).filter(item => ["food", "grooming_supply", "pet_shop", "miscellaneous"].includes(item.category))
          .filter(item => !suggestions || this.hasProductStock(item))
          .sort((a, b) => Number(this.hasProductStock(b)) - Number(this.hasProductStock(a)));
        this.noResults = !this.searchResults.length;
      } catch (err) {
        if (version === this._searchVersion) this.error = err.message || "Could not search products. Try again.";
      } finally { if (version === this._searchVersion) this.searching = false; }
    },
    onSearchInput() {
      if (this.submitting || this.validating || this.onlinePayment || this.receiptOpen) return;
      clearTimeout(this._searchTimer);
      this.showProductDropdown();
      const version = ++this._searchVersion;
      const q = this.searchQuery.trim();
      this.searchResults = []; this.noResults = false; this.searching = false;
      if (!q) return this.loadProductResults('', version, true);
      if (q.length < 2) return;
      this.searching = true;
      this._searchTimer = setTimeout(() => this.loadProductResults(q, version), 300);
    },
    productKey(event) {
      if (['ArrowDown', 'ArrowUp'].includes(event.key) && !this.productDropdown?.open) this.focusProducts();
      const handled = this.productDropdown?.key(event, this.searchResults, item => this.hasProductStock(item), item => this.addToCart(item), this.$refs.productPopup);
      if (event.key === 'Enter' && !handled) { event.preventDefault(); this.searchEnter(); }
    },
    productStockLabel(item) { return ProductSearchDropdown.retailStatus(item); },

    async scanBarcode(code = this.searchQuery.trim()) {
      if (!code || this.submitting || this.validating || this.onlinePayment || this.receiptOpen) return;
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
      const exact = this.searchResults.find(item => item.barcode === this.searchQuery.trim() && this.hasProductStock(item));
      if (exact) this.addToCart(exact);
      else if (/^\d+$/.test(this.searchQuery.trim())) this.scanBarcode();
      else if (this.searchResults.length === 1 && this.hasProductStock(this.searchResults[0])) this.addToCart(this.searchResults[0]);
    },

    cancelSearch() {
      this.productDropdown?.close();
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
      if (this.submitting || this.validating || this.onlinePayment || this.receiptOpen) return;
      if (!this.isRetailProduct(item)) { this.error = "This product is not available for retail sale."; return; }
      const available = this.productAvailability(item);
      if (available <= 0) { this.error = `${item.item_name} is out of stock.`; return; }
      if (available < 1) { this.error = `${item.item_name} has insufficient stock for one whole unit.`; return; }
      const existingIndex = this.cart.findIndex(line => line.item_id === item.item_id);
      const existing = this.cart[existingIndex];
      if (existing) {
        if (!Number.isInteger(Number(existing.quantity)) || Number(existing.quantity) < 1) {
          this.error = `Enter a whole quantity of at least 1 for ${item.item_name}.`; return;
        }
        const nextQuantity = Number(existing.quantity) + 1;
        if (nextQuantity > available) { this.error = `Cannot add another ${item.item_name}. Saleable stock: ${available} ${item.unit}.`; return; }
        existing.stock = available;
        existing.quantity = nextQuantity;
        this.updateLineSubtotal(existing);
        if (existingIndex > 0) {
          this.cart.splice(existingIndex, 1);
          this.cart.unshift(existing);
        }
      } else {
        const quantity = 1;
        this.cart.unshift({ item_id: item.item_id, item_name: item.item_name, unit: item.unit,
          stock: available, quantity, price: Number(item.selling_price),
          subtotal: Math.round(quantity * Number(item.selling_price) * 100) / 100, unavailable: false });
      }
      this.searchQuery = "";
      this.cancelSearch();
      this.error = "";
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
    removeFromCart(idx) { if (!this.submitting && !this.validating && !this.onlinePayment) this.cart.splice(idx, 1); },
    clearCart() {
      if (this.submitting || this.validating || this.onlinePayment) return;
      this.paymentMethod = "cash"; this.requestKey = null;
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
      if (this.validating || this.onlinePayment || !this.cart.length) return false;
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
        && !this.onlinePayment && this.totalAmount > 0 && this.totalAmount <= 999999.99 && (this.paymentMethod !== "cash" || !this.cashError);
    },

    async processSale() {
      if (this.submitting || this.validating || this.onlinePayment || this.receiptOpen) return;
      this.error = "";
      if (!this.canProcess) { this.error = this.cart.map(line => this.lineError(line)).find(Boolean) || this.cashError || "Review the cart and supported total before completing the sale."; return; }
      this.submitting = true;
      try {
        if (!await this.revalidateCart()) return;
        if (!this.canProcess) { this.error = this.cashError || "Review the updated cart before completing the sale again."; return; }
        if (this.paymentMethod !== 'cash') {
          this.requestKey ||= window.crypto.randomUUID();
          window.OnlinePayment.remember('pos', {request_key: this.requestKey}, null);
          this.onlinePayment = {status: 'recovering', amount: this.totalAmount};
          this.openingCheckout = true;
          this.cancelSearch();
          const response = await InventoryAPI.createOnlinePayment({
            request_key: this.requestKey, payment_method: this.paymentMethod,
            items: this.cart.map(line => ({item_id: line.item_id, quantity: Number(line.quantity), expected_price: line.price, expected_stock: line.stock})),
            notes: this.notes || null,
          });
          await this.applyPosOnlineStatus(response);
          if (response.online_payment?.status === 'pending') this.openPosCheckout();
          return;
        }
        const res = await InventoryAPI.processSale({
          items: this.cart.map(line => ({ item_id: line.item_id, quantity: Number(line.quantity), expected_price: line.price, expected_stock: line.stock })),
          amount_tendered: Number(this.amountTendered), notes: this.notes || null,
        });
        this.completePosSale(res.data);
      } catch (err) {
        if (this.paymentMethod !== 'cash' && this.requestKey) {
          try {
            const recovered = await InventoryAPI.onlinePaymentStatus(null, this.requestKey);
            if (!recovered.online_payment && err.status >= 400 && err.status < 500 && err.status !== 408) this.onlinePayment = null;
            await this.applyPosOnlineStatus(recovered);
          }
          catch { /* Keep the original create error. */ }
          if (this.onlinePayment) {
            this.error = this.onlinePayment.checkout_url
              ? 'Checkout could not open. Use Open Checkout to retry.'
              : 'Checkout could not be confirmed. Check Status before retrying.';
            return;
          }
        }
        if (err.code === "POS_CART_CHANGED" || err.data?.code === "POS_CART_CHANGED") {
          this.reconcileCart(err.data.data);
          return;
        }
        this.error = err.status === 0 || err.status >= 500
          ? "Sale could not be confirmed. Check Records → Transactions before retrying if the connection was interrupted."
          : Object.values(err.errors || {}).flat()[0] || err.message || "Sale failed. Please try again.";
      } finally {
        const openingCheckout = this.openingCheckout;
        this.submitting = false; this.openingCheckout = false;
        if (openingCheckout && this.onlinePayment && this._paymentVisible !== false) this.$nextTick(() => this.$refs.paymentStatus?.focus());
      }
    },

    completePosSale(receipt) {
      this._posPaymentWatch?.stop(); this._posPaymentWatch = null;
      this.onlinePayment = null; this.verifyingPayment = false;
      window.OnlinePayment?.clear('pos'); this.requestKey = null;
      this.receipt = receipt;
      this.cart = []; this.amountTendered = ""; this.notes = ""; this.notice = "";
      this.searchQuery = ""; this.cancelSearch();
      this.receiptOpen = true;
      this.$nextTick(() => { this.$refs.successDialog.showModal(); });
      window.dispatchEvent?.(new CustomEvent('inventory:changed'));
    },
    openPosCheckout() {
      if (this._paymentVisible === false || !this.canOpenPosCheckout) return;
      try {
        window.OnlinePayment.open('pos', {...this.onlinePayment, request_key: this.requestKey}, null);
      } catch {
        this.error = 'Checkout could not open. Use Open Checkout to retry.';
      }
    },
    get canOpenPosCheckout() {
      return this.onlinePayment?.status === 'pending' && !!this.onlinePayment.checkout_url && !this.onlinePayment.reconciliation_reason;
    },
    get posPaymentTitle() {
      if (this.onlinePayment?.status === 'paid') return 'Payment received';
      if (this.verifyingPayment || this.onlinePayment?.status === 'recovering') return 'Verifying Online Payment…';
      return this.canOpenPosCheckout ? 'Payment Pending' : 'Checkout needs review';
    },
    get posPaymentMessage() {
      if (this.onlinePayment?.status === 'paid') return 'Contact an administrator to reconcile stock.';
      if (this.onlinePayment?.status === 'recovering') return 'Check Status to recover this payment attempt.';
      return this.canOpenPosCheckout ? 'Waiting for payment confirmation.' : 'Check Status or contact an administrator.';
    },
    async applyPosOnlineStatus(response) {
      const attempt = response.online_payment;
      const wasPending = this.onlinePayment?.status === 'pending';
      this.verifyingPayment = false;
      if (!attempt && this.requestKey && this.onlinePayment?.status === 'recovering') {
        this.error = 'Checkout is still unconfirmed. Check Status or contact an administrator.';
        return;
      }
      if (response.cart) {
        const currentById = new Map();
        for (const line of this.cart) currentById.set(Number(line.item_id), line);
        const sameCart = this.cart.length === response.cart.length && response.cart.every(line => {
          const current = currentById.get(Number(line.item_id));
          return current && Number(current.quantity) === Number(line.quantity)
            && current.price === Number(line.price_at_sale) && current.subtotal === Number(line.subtotal);
        });
        if (!sameCart) this.cart = response.cart.map(line => ({
          item_id: line.item_id, item_name: line.name, unit: line.unit, quantity: line.quantity,
          price: Number(line.price_at_sale), stock: line.quantity, subtotal: Number(line.subtotal), unavailable: false,
        }));
        this.notes = response.notes ?? this.notes;
      }
      if (attempt?.status === 'paid') {
        this._posPaymentWatch?.stop();
        this.onlinePayment = attempt;
        if (!response.receipt || attempt.reconciliation_reason) {this.error = 'Payment received. Contact an administrator to reconcile stock.'; return;}
        this.completePosSale(response.receipt);
      } else if (attempt?.status === 'pending') {
        this.onlinePayment = attempt; this.paymentMethod = 'online';
        this.cancelSearch();
        window.OnlinePayment.remember('pos', {...attempt, request_key: this.requestKey}, null);
        this.error = attempt.reconciliation_reason ? 'Checkout creation needs reconciliation. Contact an administrator.' : '';
        if (!this._posPaymentWatch && this._paymentVisible !== false) {
          this._posPaymentWatch = window.OnlinePayment.watch({
            load: () => InventoryAPI.onlinePaymentStatus(this.onlinePayment.id), apply: result => this.applyPosOnlineStatus(result),
            error: error => {this.error = error.message || 'Could not check payment. Retrying.';},
            active: () => this._paymentVisible !== false && this.onlinePayment?.status === 'pending',
            exhausted: () => {this.error = 'Payment is still unconfirmed. Check Status to continue.';},
          });
          this._posPaymentWatch.start();
        }
        if (!wasPending && !this.submitting && this._paymentVisible !== false) this.$nextTick(() => this.$refs.paymentStatus?.focus());
      } else {
        this._posPaymentWatch?.stop(); this._posPaymentWatch = null;
        this.onlinePayment = null; this.verifyingPayment = false; this.requestKey = null;
        window.OnlinePayment?.clear('pos');
        if (attempt) this.error = ({failed: 'Checkout creation failed. Review the cart and retry.', cancelled: 'Online payment cancelled.', expired: 'Checkout expired. Review the cart before retrying.'})[attempt.status] || '';
        if (attempt && this._paymentVisible !== false) this.$nextTick(() => this.$refs.productSearch?.focus());
      }
    },
    async checkPosPaymentStatus() {
      if (this.submitting || !this.onlinePayment || this._paymentVisible === false) return;
      this.submitting = true; this.error = '';
      this._posPaymentWatch?.stop(); this._posPaymentWatch = null;
      try {await this.applyPosOnlineStatus(await InventoryAPI.onlinePaymentStatus(this.onlinePayment.id, this.requestKey));}
      catch {this.error = 'Could not check Online Payment. Check Status to retry.';}
      finally {
        this.submitting = false;
        if (this.onlinePayment?.status === 'pending' && !this._posPaymentWatch) {
          const error = this.error;
          await this.applyPosOnlineStatus({online_payment: this.onlinePayment});
          this.error = error;
        }
      }
    },
    async cancelPosOnlinePayment() {
      if (this._posCancelling || !this.onlinePayment?.id || this.onlinePayment.status !== 'pending') return;
      this._posCancelling = true; this.submitting = true; this.error = '';
      this._posPaymentWatch?.stop(); this._posPaymentWatch = null;
      try {await this.applyPosOnlineStatus(await InventoryAPI.cancelOnlinePayment(this.onlinePayment.id));}
      catch (error) {this.error = error.message || 'Online payment is still pending.';}
      finally {
        this._posCancelling = false; this.submitting = false;
        if (this.onlinePayment?.status === 'pending' && !this._posPaymentWatch) {
          const error = this.error;
          await this.applyPosOnlineStatus({online_payment: this.onlinePayment});
          this.error = error;
        }
      }
    },
    closeSuccess() { this.$refs.successDialog?.close(); this.receiptOpen = false; this.cancelSearch(); },
    newSale() {
      this.closeSuccess(); this.receipt = null; this.clearCart(); this.searchQuery = "";
      this.$nextTick(() => this.$refs.productSearch?.focus());
    },
    printInvoice() { if (this.receipt) window.ProductSaleInvoice.print(); },
    fmt(n) { return Number(n ?? 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    saleDate(value) {
      return value ? new Intl.DateTimeFormat("en-PH", { timeZone: "Asia/Manila", month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" }).format(new Date(value)) : "";
    },
    destroy() { this._posPaymentWatch?.stop(); if (this.scannerActive) this.closeScanner(); this.closeSuccess(); },
  };
}
