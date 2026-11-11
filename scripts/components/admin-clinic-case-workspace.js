// UX guidance only; the backend resolves catalogue prices authoritatively.
function clinicFinalPriceError(value, pricing, money) {
  const price = String(value ?? '').trim();
  if (!/^\d+(?:\.\d{1,2})?$/.test(price)) return 'Enter the final price using no more than 2 decimal places.';
  if (Number(price) < Number(pricing.min_price) || (pricing.max_price != null && Number(price) > Number(pricing.max_price)))
    return pricing.max_price != null ? `Enter an amount from ${money(pricing.min_price)} to ${money(pricing.max_price)}.`
      : `Enter an amount of at least ${money(pricing.min_price)}.`;
  return Number(price) > pricing.technical_max_price ? 'The final price exceeds the supported monetary limit.' : '';
}

// Case services use server pricing previews; historical lines use saved snapshots.
function clinicCaseWorkspace() {
  return {
    workspaceAppointment: null, workspaceSection: '', workspaceCaseId: null,
    serviceRows: [], servicePage: 0, serviceLastPage: 1, servicesLoading: false, servicesError: '',
    chargeRows: [], chargePage: 0, chargeLastPage: 1, billingLoading: false, billingError: '', billingTotal: null, billingSession: null,
    searchQuery: '', searchRows: [], searchPage: 0, searchLastPage: 1, searchLoading: false, searchError: '',
    selectedService: null, servicePricing: null, pricingLoading: false,
    serviceForm: null, serviceErrors: {}, serviceError: '', serviceSaving: false, serviceRemovingId: null, pendingPayload: null,
    _generation: 0, _servicesRequest: 0, _billingRequest: 0, _billingScope: '', _searchRequest: 0, _selectionRequest: 0, _searchTimer: null, _savedWeight: null,

    get canMutateServices() {
      const appt = this.workspaceAppointment;
      return !!this.$store.clinicAccess.permissions.clinical && !appt?.paid && !appt?.archived_at
        && (appt?.status === 'in_consultation'
          || (API.getUserRole() === 'admin' && appt?.status === 'for_payment'));
    },
    get canRecordCasePayment() {
      return !!this.$store.clinicAccess.permissions.payment && this.workspaceAppointment?.status === 'for_payment'
        && (!this.workspaceAppointment?.clinic_visit_group_id || this.billingSession?.can_pay)
        && !this.workspaceAppointment?.paid && !this.workspaceAppointment?.archived_at;
    },
    get treatmentCharges() { return this.chargeRows.filter(charge => ['medicine', 'vaccine'].includes(charge.kind)); },
    get needsFinalPrice() { return !!this.servicePricing?.requires_final_price; },
    get draftStorageKey() { return `clinic-service-draft:${this.$store.clinicAccess.user?.user_id}:${this.workspaceCaseId}`; },
    money(value) { return value == null ? '—' : Number(value).toLocaleString('en-PH', {style: 'currency', currency: 'PHP'}); },
    sizeLabel(size) { return ({small: 'Small', medium: 'Medium', large: 'Large', extra_large: 'Extra Large'})[size] || '—'; },
    methodLabel(method) { return ({fixed: 'Fixed', range: 'Range', starting_at: 'Starting At', tiered: 'Tiered'})[method] || method; },
    serviceInfo(entry) { return entry.service_snapshot || entry.clinic_service || {}; },
    serviceNotes(entry) { return entry.notes?.trim() ? entry.notes : '-'; },
    canRemoveService(entry) { return this.canMutateServices && entry.status === 'performed' && !entry.confinement_exists && !entry.confinement_charge_exists; },
    cataloguePrice(service) {
      if (service.pricing_method === 'range') return `${this.money(service.min_price)} – ${this.money(service.max_price)}`;
      if (service.pricing_method === 'starting_at') return `Starting at ${this.money(service.price)}`;
      if (service.pricing_method === 'tiered') return 'Price by case weight';
      return this.money(service.price);
    },
    priceRule() {
      if (this.selectedService?.pricing_method === 'range') return `Allowed price: ${this.money(this.servicePricing?.min_price)} – ${this.money(this.servicePricing?.max_price)}`;
      if (this.selectedService?.pricing_method === 'starting_at') return `Starting at ${this.money(this.servicePricing?.min_price)}`;
      return this.money(this.servicePricing?.resolved_price);
    },
    syncWorkspace(open, appt, section) {
      if (!open) {
        if (this.workspaceCaseId !== null) { ++this._generation; ++this._selectionRequest; ++this._searchRequest; }
        clearTimeout(this._searchTimer);
        this.workspaceCaseId = null;
        this.workspaceAppointment = null;
        this.workspaceSection = '';
        return;
      }
      if (!appt?.id) {
        clearTimeout(this._searchTimer);
        this.workspaceSection = '';
        return;
      }
      const weight = appt.vitals?.weight_kg;
      this.workspaceAppointment = appt;
      if (this.workspaceCaseId === appt.id && this.workspaceSection === section) {
        if (weight !== this._savedWeight) {
          this._savedWeight = weight;
          if (this.selectedService && !this.pendingPayload && this.canMutateServices) this.refreshPricing(this.selectedService.id);
        }
        return;
      }
      if (this.workspaceCaseId !== appt.id) {
        ++this._generation; ++this._selectionRequest; ++this._searchRequest;
        this.workspaceCaseId = appt.id;
        this.serviceRows = []; this.chargeRows = []; this.billingTotal = null; this.billingSession = null;
        this.selectedService = null; this.servicePricing = null; this.serviceForm = null; this.pendingPayload = null;
        this.searchRows = []; this.searchQuery = ''; this.searchError = ''; this.serviceError = ''; this.serviceErrors = {};
        this.pricingLoading = false; this.searchLoading = false;
      }
      this._savedWeight = weight;
      this.workspaceSection = section;
      if (section === 'services') {
        if (!this.serviceForm) this.restoreDraft();
        this.loadServices();
        if (this.$store.clinicAccess.permissions.billing_view) this.loadBilling();
      }
      else if (section === 'billing' && this.$store.clinicAccess.permissions.billing_view) this.loadBilling();
    },
    destroy() { clearTimeout(this._searchTimer); ++this._generation; ++this._searchRequest; ++this._selectionRequest; },
    async loadServices(page = 1) {
      const generation = this._generation, id = this.workspaceCaseId, request = ++this._servicesRequest;
      this.servicesLoading = true; this.servicesError = '';
      try {
        const response = await API.getClinicCaseServices(id, page);
        if (generation !== this._generation || request !== this._servicesRequest) return;
        this.serviceRows = page === 1 ? response.data : [...this.serviceRows, ...response.data];
        this.servicePage = response.current_page; this.serviceLastPage = response.last_page;
      } catch (error) { if (generation === this._generation && request === this._servicesRequest) this.servicesError = error.message || 'Could not load case services.'; }
      finally { if (generation === this._generation && request === this._servicesRequest) this.servicesLoading = false; }
    },
    async loadBilling(page = 1, scope = this.workspaceSection === 'services' ? 'treatment' : '') {
      const generation = this._generation, id = this.workspaceCaseId, request = ++this._billingRequest;
      this.billingLoading = true; this.billingError = '';
      try {
        const response = await API.getClinicCaseCharges(id, page, scope);
        if (generation !== this._generation || request !== this._billingRequest) return;
        this.chargeRows = page === 1 ? response.charges.data : [...this.chargeRows, ...response.charges.data];
        this.chargePage = response.charges.current_page; this.chargeLastPage = response.charges.last_page;
        this._billingScope = scope;
        this.billingTotal = response.total_amount;
        this.billingSession = response.session || null;
        window.dispatchEvent(new CustomEvent('clinic-case-patch', {detail: {id, total_amount: response.total_amount}}));
      } catch (error) { if (generation === this._generation && request === this._billingRequest) this.billingError = error.message || 'Could not load billing.'; }
      finally { if (generation === this._generation && request === this._billingRequest) this.billingLoading = false; }
    },
    searchServices() {
      clearTimeout(this._searchTimer); ++this._searchRequest;
      this._searchTimer = setTimeout(() => this.loadOptions(), 250);
    },
    async loadOptions(page = 1) {
      if (!this.canMutateServices || this.serviceSaving || this.serviceRemovingId !== null || this.pendingPayload) return;
      const generation = this._generation, request = ++this._searchRequest;
      this.searchLoading = true; this.searchError = '';
      try {
        const response = await API.clinicCaseServiceOptions(this.searchQuery.trim(), page);
        if (generation !== this._generation || request !== this._searchRequest) return;
        this.searchRows = response.data; this.searchPage = response.current_page; this.searchLastPage = response.last_page;
      } catch (error) { if (generation === this._generation && request === this._searchRequest) this.searchError = error.message || 'Could not search the catalogue.'; }
      finally { if (generation === this._generation && request === this._searchRequest) this.searchLoading = false; }
    },
    async selectService(service) {
      if (!this.canMutateServices || this.serviceSaving || this.serviceRemovingId !== null || this.pendingPayload) return;
      const composer = this.$refs.serviceComposer, generation = this._generation;
      clearTimeout(this._searchTimer); ++this._searchRequest; this.searchLoading = false;
      this.serviceForm = {service_id: service.id, request_key: crypto.randomUUID(), notes: '', applied_price: '', quantity: 1};
      this.selectedService = service; this.serviceError = ''; this.serviceErrors = {}; this.searchRows = [];
      this.persistDraft();
      await this.refreshPricing(service.id);
      if (generation !== this._generation || this.selectedService?.id !== service.id) return;
      this.$nextTick?.(() => {
        composer?.scrollIntoView({block: 'nearest'});
        composer?.querySelector('input, textarea')?.focus();
      });
    },
    async refreshPricing(serviceId) {
      const generation = this._generation, request = ++this._selectionRequest;
      this.pricingLoading = true; this.servicePricing = null;
      try {
        const response = await API.clinicCaseServicePricing(this.workspaceCaseId, serviceId);
        if (generation !== this._generation || request !== this._selectionRequest) return;
        this.selectedService = response.data; this.servicePricing = response.pricing;
      } catch (error) { if (generation === this._generation && request === this._selectionRequest) this.serviceError = error.message || 'Could not load service pricing. The service may be inactive.'; }
      finally { if (generation === this._generation && request === this._selectionRequest) this.pricingLoading = false; }
    },
    persistDraft() {
      try {
        sessionStorage.setItem(this.draftStorageKey, JSON.stringify({form: this.serviceForm, pending: this.pendingPayload}));
        return true;
      } catch {
        this.serviceError = 'The service draft could not be retained for retries. Enable browser session storage before submitting.';
        return false;
      }
    },
    restoreDraft() {
      if (!this.canMutateServices) return;
      try {
        const draft = JSON.parse(sessionStorage.getItem(this.draftStorageKey) || 'null');
        if (!draft?.form?.service_id || !draft.form.request_key) return;
        this.serviceForm = draft.form; this.pendingPayload = draft.pending;
        this.refreshPricing(draft.form.service_id);
      } catch { this.serviceError = 'The saved service draft could not be loaded.'; }
    },
    dismissService() {
      if (this.serviceSaving || this.pendingPayload) return;
      ++this._selectionRequest;
      sessionStorage.removeItem(this.draftStorageKey);
      this.serviceForm = null; this.selectedService = null; this.servicePricing = null;
      this.pricingLoading = false; this.serviceError = ''; this.serviceErrors = {};
    },
    validateService() {
      this.serviceErrors = {};
      if (this.selectedService?.billing_unit === 'shot' && !/^[1-9]\d*$/.test(String(this.serviceForm.quantity))) {
        this.serviceErrors.quantity = 'Enter a whole number of shots, minimum 1.';
      }
      if (this.needsFinalPrice) {
        const error = clinicFinalPriceError(this.serviceForm.applied_price, this.servicePricing, this.money);
        if (error) this.serviceErrors.applied_price = error;
      }
      return Object.keys(this.serviceErrors).length === 0;
    },
    async submitService() {
      if (!this.canMutateServices || this.serviceSaving || this.serviceRemovingId !== null || !this.serviceForm) return;
      if (!this.pendingPayload && (this.pricingLoading || !this.servicePricing || !this.validateService()
        || this.servicePricing.error)) return;
      const id = this.workspaceCaseId, generation = this._generation, form = this.serviceForm;
      const payload = this.pendingPayload || {notes: form.notes, clinic_service_id: form.service_id, request_key: form.request_key,
        ...(this.selectedService?.billing_unit === 'shot' ? {quantity: Number(form.quantity)} : {}),
        ...(this.needsFinalPrice ? {applied_price: form.applied_price.trim()} : {})};
      this.pendingPayload = payload;
      if (!this.persistDraft()) { this.pendingPayload = null; return; }
      this.serviceSaving = true; this.serviceError = '';
      window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: true}}));
      try {
        await API.clinicCreateCaseService(id, payload);
        sessionStorage.removeItem(this.draftStorageKey);
        if (generation !== this._generation) return;
        this.pendingPayload = null; this.serviceForm = null; this.selectedService = null; this.servicePricing = null;
        clearTimeout(this._searchTimer); ++this._searchRequest;
        this.searchQuery = ''; this.searchRows = []; this.searchPage = 0; this.searchLoading = false; this.searchError = '';
        await Promise.all([this.loadServices(), this.loadBilling()]);
        if (generation !== this._generation) return;
        this.$store.clinicFeedback.notify('Service added.', 'success');
        window.dispatchEvent(new CustomEvent('clinic-case-updated'));
      } catch (error) {
        if (generation !== this._generation) return;
        if (error.status === 422 || error.errors) {
          this.pendingPayload = null; this.persistDraft();
          this.serviceErrors = Object.fromEntries(Object.entries(error.errors || {}).map(([key, messages]) => [key, messages[0]]));
          this.serviceError = Object.values(this.serviceErrors)[0] || error.message;
          await this.refreshPricing(form.service_id);
        } else this.serviceError = 'The result could not be confirmed. Retry this same service request to check its result safely.';
      } finally {
        this.serviceSaving = false;
        window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: false}}));
      }
    },
    async removeService(entry, clinical = false) {
      if (!this.canRemoveService(entry) || this.serviceSaving || this.serviceRemovingId !== null || this.pendingPayload) return;
      const id = this.workspaceCaseId, generation = this._generation;
      this.serviceRemovingId = entry.id;
      let removing = false;
      try {
        const confirmed = await this.$store.clinicFeedback.confirm({title: 'Remove service?',
          message: clinical ? `Remove "${this.serviceInfo(entry).name}" from this case? Its linked Billing charge will also be removed. Images will be preserved in the patient history.`
            : `Remove "${this.serviceInfo(entry).name}" from this case? Its ${this.money(entry.charge?.amount ?? entry.applied_price)} charge will also be removed from Billing.`,
          confirmLabel: 'Remove Service', cancelLabel: 'Cancel', danger: true});
        if (!confirmed || generation !== this._generation || !this.canRemoveService(entry)) return;
        removing = true; this.servicesError = '';
        window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: true}}));
        await API.clinicDeleteCaseService(id, entry.id);
        if (generation !== this._generation) return;
        if (this.attachments) this.attachments = this.attachments.map(file => Number(file.clinic_case_service_id) === entry.id ? {...file, clinic_case_service_id: null} : file);
        await Promise.all([this.loadServices(), this.loadBilling()]);
        if (generation !== this._generation) return;
        this.$store.clinicFeedback.notify('Service removed.', 'success');
        window.dispatchEvent(new CustomEvent('clinic-case-updated'));
        return true;
      } catch (error) { if (generation === this._generation) this.servicesError = error.message || 'Could not remove this service.'; }
      finally {
        this.serviceRemovingId = null;
        if (removing) window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: false}}));
      }
    },
    async requestCasePayment() {
      if (!this.canRecordCasePayment || this.billingLoading || this.billingError || this.billingTotal == null) return;
      const generation = this._generation;
      if (this._billingScope === 'treatment') {
        await this.loadBilling(1, '');
        if (generation !== this._generation || this.billingError) return;
      }
      // The existing payment dialog must receive the complete reviewed breakdown.
      while (this.chargePage < this.chargeLastPage) {
        await this.loadBilling(this.chargePage + 1, '');
        if (generation !== this._generation || this.billingError) return;
      }
      if (!this.canRecordCasePayment) return;
      window.dispatchEvent(new CustomEvent('clinic-review-payment', {detail: {
        ...this.workspaceAppointment, charges: [...this.chargeRows], total_amount: this.billingTotal, session: this.billingSession,
      }}));
    },
  };
}

// Medical Record imaging refers to the same occurrences and charges as Services.
function clinicImagingWorkspace() {
  return {
    imagingCaseId: null, imagingActive: false, imagingRows: [], imagingPage: 0, imagingLastPage: 1,
    imagingLoading: false, imagingError: '', imagingRetry: null,
    imagingSetupMessage: 'This imaging service cannot be added because its billing setup is incomplete. Ask an administrator to check Clinic Services.', imagingOptions: [], imagingQuery: '',
    imagingOptionsPage: 0, imagingOptionsLastPage: 1, imagingOptionsLoading: false,
    imagingSelected: null, imagingPricing: null, imagingPricingLoading: false,
    imagingNotes: '', imagingShots: 1, imagingSaving: false, imagingPending: null, imagingRequestKey: null,
    _imagingGeneration: 0, _imagingRowsRequest: 0, _imagingOptionsRequest: 0, _imagingPriceRequest: 0,
    _imagingSearchTimer: null,
    get imagingBusy() { return this.imagingSaving || this.serviceSaving || this.serviceRemovingId !== null || this.saving || this.attachmentBusy; },
    get validImagingShots() { return this.imagingSelected?.billing_unit !== 'shot' || /^[1-9]\d*$/.test(String(this.imagingShots)); },
    syncImaging(open, appt, section) {
      if (!open || !appt?.id) {
        if (this.imagingCaseId !== null) ++this._imagingGeneration;
        this.imagingCaseId = null; this.imagingActive = false; return;
      }
      if (this.imagingCaseId !== appt.id) {
        ++this._imagingGeneration; this.imagingCaseId = appt.id; this.imagingActive = false;
        this.imagingRows = []; this.imagingPage = 0; this.imagingLastPage = 1;
        this.imagingOptions = []; this.imagingOptionsPage = 0; this.imagingOptionsLastPage = 1;
        this.imagingQuery = ''; this.imagingError = ''; this.imagingRetry = null;
        this.imagingSelected = null; this.imagingPricing = null; this.imagingNotes = ''; this.imagingShots = 1;
        this.imagingPending = null; this.imagingRequestKey = null;
        this.imagingSaving = false; this.imagingPricingLoading = false; this.imagingOptionsLoading = false;
      }
      if (section === 'medical' && !this.imagingActive) this.loadImagingServices();
      this.imagingActive = section === 'medical';
    },
    destroy() { clearTimeout(this._imagingSearchTimer); ++this._imagingGeneration; },
    async loadImagingServices(page = 1) {
      const generation = this._imagingGeneration, request = ++this._imagingRowsRequest;
      this.imagingLoading = true; this.imagingError = ''; this.imagingRetry = null;
      try {
        const response = await API.getClinicCaseServices(this.imagingCaseId, page, 'imaging');
        if (generation !== this._imagingGeneration || request !== this._imagingRowsRequest) return;
        this.imagingRows = page === 1 ? response.data : [...this.imagingRows, ...response.data];
        this.imagingPage = response.current_page; this.imagingLastPage = response.last_page;
      } catch (error) { if (generation === this._imagingGeneration && request === this._imagingRowsRequest) { this.imagingError = 'Could not load imaging.'; this.imagingRetry = {action: 'rows', page}; } }
      finally { if (generation === this._imagingGeneration && request === this._imagingRowsRequest) this.imagingLoading = false; }
    },
    searchImagingServices() {
      clearTimeout(this._imagingSearchTimer); ++this._imagingOptionsRequest;
      ++this._imagingPriceRequest; this.imagingSelected = null; this.imagingPricing = null; this.imagingPricingLoading = false;
      this._imagingSearchTimer = setTimeout(() => this.loadImagingOptions(), 250);
    },
    async loadImagingOptions(page = 1) {
      if (!this.canEditClinical || this.imagingBusy || this.imagingPending) return;
      const generation = this._imagingGeneration, request = ++this._imagingOptionsRequest;
      this.imagingOptionsLoading = true; this.imagingError = ''; this.imagingRetry = null;
      try {
        const response = await API.clinicCaseServiceOptions(this.imagingQuery.trim(), page, 'imaging');
        if (generation !== this._imagingGeneration || request !== this._imagingOptionsRequest) return;
        this.imagingOptions = response.data; this.imagingOptionsPage = response.current_page; this.imagingOptionsLastPage = response.last_page;
      } catch (error) { if (generation === this._imagingGeneration && request === this._imagingOptionsRequest) { this.imagingError = 'Could not load imaging services.'; this.imagingRetry = {action: 'options', page}; } }
      finally { if (generation === this._imagingGeneration && request === this._imagingOptionsRequest) this.imagingOptionsLoading = false; }
    },
    async selectImagingService(service) {
      if (!this.canEditClinical || this.imagingBusy || this.imagingPending) return;
      clearTimeout(this._imagingSearchTimer); ++this._imagingOptionsRequest;
      const generation = this._imagingGeneration, request = ++this._imagingPriceRequest;
      this.imagingSelected = service; this.imagingQuery = service.name; this.imagingOptions = []; this.imagingOptionsLoading = false;
      this.imagingShots = 1; this.imagingRequestKey = crypto.randomUUID();
      this.imagingPricing = null; this.imagingPricingLoading = true; this.imagingError = ''; this.imagingRetry = null;
      try {
        const response = await API.clinicCaseServicePricing(this.imagingCaseId, service.id);
        if (generation !== this._imagingGeneration || request !== this._imagingPriceRequest) return;
        this.imagingSelected = response.data; this.imagingPricing = response.pricing;
        if (response.pricing.error || response.pricing.requires_final_price) this.imagingError = this.imagingSetupMessage;
      } catch (error) { if (generation === this._imagingGeneration && request === this._imagingPriceRequest) { this.imagingError = 'Could not check this imaging service.'; this.imagingRetry = {action: 'selection'}; } }
      finally { if (generation === this._imagingGeneration && request === this._imagingPriceRequest) this.imagingPricingLoading = false; }
    },
    async addImagingService() {
      if (!this.canEditClinical || this.imagingBusy || !this.imagingSelected) return;
      if (!this.imagingPending && (!this.imagingPricing || this.imagingPricingLoading || this.imagingPricing.error
        || this.imagingPricing.requires_final_price || !this.validImagingShots)) return;
      const id = this.imagingCaseId, generation = this._imagingGeneration;
      this.imagingPending ||= {clinic_service_id: this.imagingSelected.id, request_key: this.imagingRequestKey, notes: this.imagingNotes,
        ...(this.imagingSelected.billing_unit === 'shot' ? {quantity: Number(this.imagingShots)} : {})};
      this.imagingSaving = true; this.imagingError = ''; this.imagingRetry = null;
      window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: true}}));
      try {
        await API.clinicCreateCaseService(id, this.imagingPending);
        if (generation !== this._imagingGeneration) return;
        this.imagingPending = null; this.imagingSelected = null; this.imagingPricing = null;
        this.imagingQuery = ''; this.imagingNotes = ''; this.imagingShots = 1; this.imagingOptionsPage = 0;
        await Promise.all([this.loadImagingServices(), this.loadServices(), this.loadBilling()]);
        this.$store.clinicFeedback.notify('Imaging added.', 'success');
        window.dispatchEvent(new CustomEvent('clinic-case-updated'));
      } catch (error) {
        if (generation !== this._imagingGeneration) return;
        const rejected = (error.status >= 400 && error.status < 500) || error.errors;
        if (rejected) this.imagingPending = null;
        this.imagingError = error.errors?.clinic_service_id || error.errors?.applied_price ? this.imagingSetupMessage
          : error.errors ? Object.values(error.errors).flat()[0] : rejected ? 'This imaging service could not be added.'
          : 'The result could not be confirmed. Retry Add Imaging to check this same request safely.';
      } finally {
        if (generation === this._imagingGeneration) this.imagingSaving = false;
        window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving: false}}));
      }
    },
    async removeImagingService(entry) {
      if (!this.canEditClinical || this.imagingBusy || this.imagingPending) return;
      if (await this.removeService(entry, true)) await this.loadImagingServices();
      else if (this.servicesError) this.imagingError = 'Could not remove this imaging service. Try removing it again.';
    },
    async retryImaging() {
      const retry = this.imagingRetry;
      if (retry?.action === 'rows') await this.loadImagingServices(retry.page);
      else if (retry?.action === 'options') await this.loadImagingOptions(retry.page);
      else if (retry?.action === 'selection' && this.imagingSelected) await this.selectImagingService(this.imagingSelected);
    },
    imagingAttachments(entry) { return this.attachments.filter(file => Number(file.clinic_case_service_id) === entry.id); },
    async uploadImagingAttachment(entry, event) {
      const file = event.target.files?.[0];
      if (!file || !this.canEditClinical || this.imagingBusy || this.imagingPending) return;
      if (!/\.(jpe?g|png|dcm)$/i.test(file.name) || file.size > 10 * 1024 * 1024) {
        this.imagingError = 'Use a JPG, JPEG, PNG or DCM file up to 10 MB.'; this.imagingRetry = null;
        event.target.value = ''; return;
      }
      const id = this.imagingCaseId, generation = this._imagingGeneration;
      this.attachmentBusy = true; this.imagingError = ''; this.imagingRetry = null;
      try {
        const result = await API.clinicUploadAttachment(id, file, '', entry.id);
        if (generation !== this._imagingGeneration) return;
        this.attachments.push(result.attachment);
        this.$store.clinicFeedback.notify('Imaging file uploaded.', 'success');
        window.dispatchEvent(new CustomEvent('clinic-case-updated'));
      } catch (error) { if (generation === this._imagingGeneration) this.imagingError = error.errors ? Object.values(error.errors).flat()[0] : error.message || 'Could not upload the imaging file.'; }
      finally { event.target.value = ''; if (generation === this._imagingGeneration) this.attachmentBusy = false; }
    },
  };
}
