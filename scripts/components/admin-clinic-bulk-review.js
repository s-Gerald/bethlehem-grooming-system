function clinicBulkReview() {
  return {
    context: null, patients: [], preview: null, pending: null, loading: false, saving: false, error: '',
    doseUnits: CLINIC_CLINICAL_RULES.doseUnits,
    routes: ['subcutaneous', 'intramuscular', 'intranasal', 'oral', 'other'],
    get treatment() { return this.context?.kind === 'treatments'; },
    get shots() { return this.context?.item.billing_unit === 'shot'; },
    get selectedRows() { return this.patients.filter(patient => patient.selected); },
    get requiredStock() { return this.selectedRows.reduce((sum, patient) => sum + Number(patient.quantity || 0), 0); },
    get total() {
      return this.selectedRows.reduce((sum, patient) => sum + Number(this.price(patient) || 0) * (this.shots ? Number(patient.quantity || 0) : 1), 0);
    },
    get canApply() {
      if (this.loading || this.saving || !this.selectedRows.length) return false;
      if (this.pending) return true;
      if (this.patients.length < 2) return false;
      if (this.treatment && this.requiredStock > Number(this.preview?.available_quantity)) return false;
      return this.selectedRows.every(patient => !this.rowIssue(patient));
    },
    get storageKey() { return `clinic-bulk:${this.$store.clinicAccess.user?.user_id}:${this.context.caseId}:${this.context.kind}:${this.itemId}`; },
    get itemId() { return this.context?.item.item_id ?? this.context?.item.id; },
    money(amount) { return window.PaymentInvoice.formatPeso(amount); },
    price(patient) {
      return this.treatment ? patient.price : patient.pricing?.requires_final_price ? patient.finalPrice : patient.pricing?.resolved_price;
    },
    rowIssue(patient) {
      if (patient.error || patient.pricing?.error) return patient.error || patient.pricing.error;
      if ((this.treatment || this.shots) && !/^[1-9]\d*$/.test(String(patient.quantity))) return 'Enter a whole quantity.';
      if (patient.pricing?.requires_final_price) return clinicFinalPriceError(patient.finalPrice, patient.pricing, this.money);
      if (this.treatment) {
        const unit = patient.doseUnit === 'other' ? patient.customUnit : patient.doseUnit;
        const doseError = Object.values(clinicDoseErrors(patient.dose, unit, true))[0];
        if (doseError) return doseError;
        if (!patient.route) return 'Select the route.';
        if (CLINIC_CLINICAL_RULES.injectionSiteRoutes.includes(patient.route) && !patient.site.trim()) return 'Enter the administration site.';
      }
      return '';
    },
    async openReview(context) {
      if (this.$refs.review.open || !this.$store.clinicAccess.permissions.clinical) return;
      this.context = context;
      this.patients = [];
      this.preview = null;
      this.pending = null;
      this.error = '';
      this.$refs.review.showModal();
      try {
        const draft = JSON.parse(sessionStorage.getItem(this.storageKey) || 'null');
        if (draft?.pending) {
          this.pending = draft.pending;
          this.preview = draft.preview;
          this.patients = draft.patients;
          this.error = 'Retry to confirm the result of the previous request.';
          return;
        }
        this.patients = draft?.patients || [];
      } catch { /* A new review can still be opened. */ }
      await this.refresh();
    },
    async refresh() {
      if (this.pending || this.saving || this.loading) return;
      const { caseId, kind } = this.context;
      this.loading = true;
      this.error = '';
      try {
        const preview = await API.clinicBulkPreview(caseId, kind, this.itemId);
        const previous = new Map(this.patients.map(patient => [patient.id, patient]));
        this.preview = preview;
        this.patients = preview.patients.map(patient => ({
          selected: false, quantity: this.shots ? 1 : '', dose: '', doseUnit: '', customUnit: '', route: '',
          site: '', nextDue: '', finalPrice: '', requestKey: crypto.randomUUID(),
          ...previous.get(patient.id), ...patient, serverError: '',
        }));
        if (this.patients.length < 2) this.error = 'Fewer than two patients are currently eligible for bulk entry.';
      } catch (error) { this.error = error.message || 'Could not load the review.'; }
      finally { this.loading = false; }
    },
    selectAll() { this.patients.forEach(patient => { patient.selected = true; }); },
    persist() {
      sessionStorage.setItem(this.storageKey, JSON.stringify({ patients: this.patients, preview: this.preview, pending: this.pending }));
    },
    closeReview() {
      if (this.saving || this.loading) return;
      try { if (this.patients.length) this.persist(); } catch { /* Keep the current screen usable. */ }
      this.$refs.review.close();
    },
    payload() {
      const item = this.treatment ? 'inventory_item_id' : 'clinic_service_id';
      return {
        [item]: this.itemId,
        patients: this.selectedRows.map(patient => ({
          id: patient.id, request_key: patient.requestKey, expected_price: this.price(patient),
          ...(this.shots ? { quantity: Number(patient.quantity) } : {}),
          ...(patient.pricing?.requires_final_price ? { applied_price: patient.finalPrice } : {}),
          ...(this.treatment ? {
            inventory_quantity: Number(patient.quantity),
            dose_amount: patient.dose, dose_unit: patient.doseUnit, dose_unit_other: patient.customUnit || null,
            route: patient.route, administration_site: patient.site || null, next_due_date: patient.nextDue || null,
            expected_weight_kg: patient.priced_weight,
          } : {}),
        })),
      };
    },
    async apply() {
      if (!this.canApply) return;
      this.pending ||= this.payload();
      try { this.persist(); }
      catch {
        this.pending = null;
        this.error = 'Enable session storage so this request can be safely retried.';
        return;
      }
      const { caseId, kind } = this.context;
      const count = this.pending.patients.length;
      this.saving = true;
      this.error = '';
      this.patients.forEach(patient => { patient.serverError = ''; });
      try {
        await API.clinicBulkApply(caseId, kind, this.pending);
        sessionStorage.removeItem(this.storageKey);
        this.pending = null;
        this.patients = [];
        this.$refs.review.close();
        this.$store.clinicFeedback.notify(`Applied to ${count} ${count === 1 ? 'pet' : 'pets'}.`, 'success');
        window.dispatchEvent(new CustomEvent('clinic-bulk-applied', { detail: { caseId, kind } }));
        window.dispatchEvent(new CustomEvent('clinic-case-updated'));
      } catch (error) {
        if (error.status === 422 || error.errors) {
          this.pending = null;
          for (const [key, messages] of Object.entries(error.errors || {})) {
            const patient = this.patients.find(row => String(row.id) === key.split('.')[1]);
            if (patient) patient.serverError = messages[0];
          }
          this.error = Object.values(error.errors || {}).flat()[0] || error.message || 'Review the selected patients.';
          this.persist();
        } else this.error = 'The result could not be confirmed. Retry this same request.';
      } finally { this.saving = false; }
    },
  };
}
