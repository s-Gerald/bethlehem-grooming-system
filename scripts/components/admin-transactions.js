function adminTransactions() {
  const d = new Date();
  const currentDate = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const currentMonth = currentDate.slice(0, 7);
  const currentYear = currentDate.slice(0, 4);

  return {
    transactions: [],
    totalCount: 0,
    searchQuery: "",
    transactionPeriod: "day",
    clinicOnly: window.API?.isClinicWorkspace?.() === true,
    transactionType: window.API?.isClinicWorkspace?.() ? 'clinic_payment' : 'all',
    currentPage: 1,
    lastPage: 1,
    serverCollectionTotal: null,
    serverDateTotals: {},
    _loadVersion: 0,
    filterDate: "",
    filterMonth: "",
    filterYear: "",
    loading: true,
    transactionsLoaded: false,
    _transactionsKey: "",
    errorMessage: "",
    proofPages: [], _proofController: null, proofUrl: '', proofBusy: false, proofError: '', _proofVersion: 0,
    selectedTransaction: null,
    receiptModal: null,
    detailsTrigger: null,
    previousBodyOverflow: "",

    async init() {
      try {
        if (API.getStaffIdentity) await API.getStaffIdentity();
        this.clinicOnly = API.isClinicWorkspace?.() === true;
      } catch (error) {
        this.errorMessage = error.message || 'Could not verify transaction access.';
        this.loading = false;
        return;
      }
      const params = new URLSearchParams(window.location.search);
      const paymentId = params.get("payment");
      const posId = params.get("pos");
      const clinicId = params.get("clinic");
      const reference = params.get("reference");
      if (posId && !this.clinicOnly) this.transactionType = "product_sale";
      if (this.clinicOnly) this.transactionType = 'clinic_payment';
      if (clinicId) this.transactionType = "clinic_payment";
      if (paymentId || posId || clinicId || reference) this.searchQuery = reference || (posId && !this.clinicOnly ? 'POS-' + posId.padStart(6, '0') : "");
      await this.loadTransactions();
      if (paymentId || posId || clinicId || reference) {
        const tx = this.transactions.find((record) => posId
          ? this.isProductSale(record) && String(record.id) === posId
          : clinicId ? this.isClinicPayment(record) && String(record.id) === clinicId
          : paymentId ? !this.isProductSale(record) && !this.isClinicPayment(record) && String(record.id) === paymentId
          : record.reference === reference);
        if (tx) {
          this.openDetails(tx, null);
          this.$nextTick(() => { this.detailsTrigger = document.querySelector(`[data-transaction-id="${tx.key || tx.id}"]`); });
        } else if (!this.errorMessage) {
          this.errorMessage = "The linked transaction could not be found.";
        }
      }
    },

    async loadTransactions(page = 1) {
      if (this.clinicOnly) this.transactionType = 'clinic_payment';
      const version = ++this._loadVersion;
      const key = JSON.stringify([this.searchQuery.trim(), this.transactionPeriod, this.filterDate, this.filterMonth, this.filterYear, this.transactionType, page]);
      this.transactionsLoaded = this.transactionsLoaded && key === this._transactionsKey;
      this.loading      = true;
      this.errorMessage = "";

      try {
        const data = await API.getTransactions({
          search: this.searchQuery.trim(),
          period: this.transactionPeriod,
          date:   this.transactionPeriod === "day" ? this.filterDate : "",
          month:  this.transactionPeriod === "month" ? this.filterMonth : "",
          year:   this.transactionPeriod === "year" ? this.filterYear : "",
          transaction_type: this.transactionType,
          page,
        });
        if (version !== this._loadVersion) return;
        this.transactions = data.transactions || [];
        this.transactionsLoaded = true;
        this._transactionsKey = key;
        this.totalCount   = data.total ?? this.transactions.length;
        this.currentPage = data.page ?? 1;
        this.lastPage = data.last_page ?? 1;
        this.serverCollectionTotal = data.collection_total ?? null;
        this.serverDateTotals = data.date_totals || {};
      } catch (error) {
        if (version !== this._loadVersion) return;
        this.errorMessage = error.message || "Failed to load transactions. Please try again.";

      } finally {
        if (version === this._loadVersion) {
          this.loading = false;
        }
      }
    },

    onSearchChange() {
      this.loadTransactions();
    },

    onPeriodChange() {
      if (this.transactionPeriod === "day" && !this.filterDate) {
        this.filterDate = currentDate;
      }

      if (this.transactionPeriod === "month" && !this.filterMonth) {
        this.filterMonth = currentMonth;
      }

      if (this.transactionPeriod === "year" && !this.filterYear) {
        this.filterYear = currentYear;
      }

      this.loadTransactions();
    },

    onPeriodValueChange() {
      this.loadTransactions();
    },

    clearFilters() {
      this.searchQuery = "";
      this.transactionType = this.clinicOnly ? 'clinic_payment' : 'all';
      this.transactionPeriod = "day";
      this.filterDate = "";
      this.filterMonth = "";
      this.filterYear = "";
      this.loadTransactions();
    },

    get hasActiveFilters() {
      return (
        this.searchQuery ||
        this.transactionType !== (this.clinicOnly ? 'clinic_payment' : 'all') ||
        this.transactionPeriod !== "day" ||
        this.filterDate ||
        this.filterMonth ||
        this.filterYear
      );
    },

    // Groups transactions: today first, then earlier dates
    get groupedTransactions() {
      const groups = {};
      for (const tx of this.transactions) {
        const key = tx.dateKey || "unknown";
        if (!groups[key]) groups[key] = [];
        groups[key].push(tx);
      }

      const todayKey = currentDate;

      const sortedKeys = Object.keys(groups).sort((a, b) => a === "unknown" ? 1 : b === "unknown" ? -1 : b.localeCompare(a));

      return sortedKeys.map(dateKey => ({
        dateKey,
        isToday: dateKey === todayKey,
        label:   this.formatGroupDate(dateKey),
        items:   groups[dateKey],
        total:   Number(this.serverDateTotals[dateKey] ?? groups[dateKey].reduce((sum, tx) => sum + Number(tx.finalPrice), 0)),
      }));
    },

    get collectionTotal() {
      return this.serverCollectionTotal ?? this.transactions.reduce((sum, tx) => sum + Number(tx.finalPrice), 0);
    },

    get collectionCount() {
      return this.totalCount || this.transactions.length;
    },

    get hasSelectedPeriodValue() {
      if (this.transactionPeriod === "month") return Boolean(this.filterMonth);
      if (this.transactionPeriod === "year") return Boolean(this.filterYear);
      return Boolean(this.filterDate);
    },

    get collectionTitle() {
      if (this.transactionPeriod === "month") {
        return this.filterMonth ? `${this.formatMonth(this.filterMonth)} Collection` : "Monthly Collection";
      }

      if (this.transactionPeriod === "year") {
        return this.filterYear ? `${this.filterYear} Collection` : "Yearly Collection";
      }

      return this.filterDate ? `${this.formatShortDate(this.filterDate)} Collection` : "Collection";
    },

    petNames(tx) {
      const names = (tx.paymentSummary?.pets || []).map(pet => pet.pet_name).filter(Boolean);
      return names.length ? names : (tx.petName || "").split(",").map(name => name.trim()).filter(Boolean);
    },

    compactPetNames(tx) {
      const names = this.petNames(tx);
      return names.length > 3 ? `${names.slice(0, 2).join(", ")} +${names.length - 2} more` : names.join(", ") || "—";
    },

    isProductSale(tx) { return tx?.transactionType === "product_sale"; },
    isClinicPayment(tx) { return tx?.transactionType === "clinic_payment"; },
    isLegacyQrPayment(tx) {
      // Historical manual QR invoices stay unsupported even without loaded verification data.
      return !!tx?.qrPayment || (tx?.paymentMethod === 'qrph' && tx?.paymentMethodLabel !== 'Online Payment'
        && tx?.paymentProvider !== 'paymongo');
    },
    transactionName(tx) { return this.isProductSale(tx) ? "Product Sale" : tx.ownerName || "—"; },
    transactionDetails(tx) {
      if (!this.isProductSale(tx)) return this.compactPetNames(tx);
      const items = tx.items || [];
      if (!items.length) return "Products";
      const first = items[0];
      return `${first.item_name} ×${Number(first.quantity)}${items.length > 1 ? ' + ' + (items.length - 1) + ' more' : ''}`;
    },

    openDetails(tx, trigger) {
      this.clearPaymentProof();
      this.selectedTransaction = tx;
      // Build the invoice once from the transaction being inspected.
      this.receiptModal = this.isProductSale(tx) || this.isLegacyQrPayment(tx) ? null : {
        isTest: tx.isTest === true,
        bookingReference: tx.reference,
        ownerName: tx.ownerName,
        pets: (tx.paymentSummary?.pets || []).map((pet, index) => ({
          id: pet.booking_pet_id ?? index,
          name: pet.pet_name,
          species: pet.pet_species,
          lines: (pet.service_breakdown || []).map((line, lineIndex) => ({
            id: line.booking_service_id ?? lineIndex,
            name: line.label,
            price: line.price_at_booking,
          })),
          subtotal: pet.final_pet_charge ?? pet.original_pet_subtotal,
        })),
        products: tx.productAddons || [],
        groomingSubtotal: tx.groomingServicesTotal,
        productsSubtotal: tx.productAddonsTotal,
        finalPrice: tx.finalPrice,
        amountPaid: tx.amountPaid,
        change: tx.changeGiven,
        paymentMethod: tx.paymentMethodLabel || tx.paymentMethod,
        notes: tx.notes,
        paidAt: tx.paidAt,
      };
      if (this.isClinicPayment(tx)) {
        this.receiptModal = {
          isTest: tx.isTest === true,
          caseReference: tx.reference,
          ownerName: tx.ownerName,
          patientName: tx.petName,
          patientInfo: "",
          charges: tx.charges || [],
          finalPrice: tx.finalPrice,
          amountPaid: tx.amountPaid,
          change: tx.changeGiven,
          paymentMethod: tx.paymentMethodLabel || tx.paymentMethod,
          processedBy: tx.processedByName || "—",
          paidAt: tx.paidAt,
        };
      }
      this.detailsTrigger = trigger;
      this.previousBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = "hidden";
      this.$nextTick(() => {
        if (!this.selectedTransaction) return;
        this.$refs.detailsDialog.showModal();
        this.$refs.detailsDialog.querySelector('.transaction-dialog-body').scrollTop = 0;
      });
    },

    closeDetails() {
      this.clearPaymentProof();
      this.$refs.detailsDialog.close();
      document.body.style.overflow = this.previousBodyOverflow;
      this.selectedTransaction = null;
      this.receiptModal = null;
      this.detailsTrigger?.focus();
      this.detailsTrigger = null;
    },

    destroy() {
      this.clearPaymentProof();
      if (this.selectedTransaction) document.body.style.overflow = this.previousBodyOverflow;
    },

    clearPaymentProof() {
      this._proofVersion++;
      this._proofController?.abort();
      this._proofController = null;
      for (const url of this.proofPages) URL.revokeObjectURL(url);
      this.proofPages = [];
      if (this.proofUrl) URL.revokeObjectURL(this.proofUrl);
      this.proofUrl = this.proofError = '';
      this.proofBusy = false;
    },

    formatQrDate(value) {
      return window.PaymentInvoice.formatPaymentDateTime(value);
    },

    formatTransactionDate(tx) {
      if (!tx.paidAt) return "—";
      return `${this.formatShortDate(tx.dateKey)} · ${this.formatTime(tx.paidAt)}`;
    },

    formatInvoicePeso(amount) {
      return window.PaymentInvoice.formatPeso(amount);
    },

    formatInvoiceDate(value) {
      return window.PaymentInvoice.formatDate(value);
    },

    formatInvoiceProductUnitPrice(line) {
      return window.PaymentInvoice.formatProductUnitPrice(line);
    },

    paymentChargeQuantity(charge) {
      return charge.quantity == null ? "—" : Number(charge.quantity).toLocaleString("en-PH", { maximumFractionDigits: 2 });
    },

    formatClinicPaidAt(value) {
      if (!value) return "—";
      const date = new Date(value);
      return Number.isNaN(date.getTime()) ? value : date.toLocaleString("en-PH", {dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Manila"});
    },

    printInvoice() {
      if (this.isLegacyQrPayment(this.selectedTransaction)) return;
      if (!this.selectedTransaction) return;
      if (this.isProductSale(this.selectedTransaction)) window.ProductSaleInvoice.print();
      else if (this.isClinicPayment(this.selectedTransaction)) window.PaymentInvoice.print("clinicPaymentInvoice");
      else window.PaymentInvoice.print();
    },

    formatGroupDate(dateStr) {
      if (!dateStr || dateStr === "unknown") return "Unknown Date";
      try {
        const date = new Date(dateStr + "T00:00:00");
        return new Intl.DateTimeFormat("en-PH", {
          weekday: "long",
          year:    "numeric",
          month:   "long",
          day:     "numeric",
        }).format(date);
      } catch {
        return dateStr;
      }
    },

    formatTime(paidAt) {
      if (!paidAt) return "—";
      try {
        return new Intl.DateTimeFormat("en-PH", {
          hour:   "numeric",
          minute: "2-digit",
          hour12: true,
        }).format(new Date(paidAt));
      } catch {
        return paidAt;
      }
    },

    formatMonth(monthStr) {
      if (!monthStr) return "All dates";

      try {
        return new Intl.DateTimeFormat("en-PH", {
          year: "numeric",
          month: "short",
        }).format(new Date(monthStr + "-01T00:00:00"));
      } catch {
        return monthStr;
      }
    },

    formatShortDate(dateStr) {
      if (!dateStr) return "All dates";

      try {
        return new Intl.DateTimeFormat("en-PH", {
          month: "short",
          day: "numeric",
          year: "numeric",
        }).format(new Date(dateStr + "T00:00:00"));
      } catch {
        return dateStr;
      }
    },

    formatPeso(amount) {
      return "₱" + Number(amount).toFixed(2);
    },
  };
}

// Legacy manual QR proof viewer preserved, never exposed by active transaction history.
function legacyQrProofViewer() {
  return {
    async viewPaymentProof() {
      const qr = this.selectedTransaction?.qrPayment;
      if (!qr?.proof || this.proofBusy || this.proofUrl || this.proofPages.length) return;
      const version = this._proofVersion;
      const controller = new AbortController(); this._proofController = controller;
      this.proofBusy = true; this.proofError = '';
      try {
        const {blob} = await API.getQrPaymentProof(qr.id);
        if (version !== this._proofVersion) return;
        if (qr.proof.mime === 'application/pdf') {
          const {readPdfReceipt} = await import('../services/payment-receipt-pdf.js');
          if (version !== this._proofVersion) return;
          await readPdfReceipt(blob, async canvas => {
            const page = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
            if (!page) throw new Error('Could not render payment proof.');
            if (version === this._proofVersion) this.proofPages.push(URL.createObjectURL(page));
            return '';
          }, controller.signal);
        } else this.proofUrl = URL.createObjectURL(blob);
      } catch (error) { if (version === this._proofVersion) { this.clearPaymentProof(); this.proofError = error.message || 'Could not load payment proof.'; } }
      finally { if (version === this._proofVersion) this.proofBusy = false; }
    },

  };
}
