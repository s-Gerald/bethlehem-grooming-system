const CLINIC_SERVICE_MAX_PRICE = 100000;

function clinicServicePriceError(value) {
  const text = String(value ?? '').trim();
  if (!text || !Number.isFinite(Number(text)) || Number(text) < 0) return 'Enter a non-negative price.';
  if (Number(text) > CLINIC_SERVICE_MAX_PRICE) {
    return `Price must be ₱${CLINIC_SERVICE_MAX_PRICE.toLocaleString('en-PH', {minimumFractionDigits: 2})} or less.`;
  }
  if (!/^(?:\d+\.?\d{0,2}|\.\d{1,2})$/.test(text)) return 'Price must have no more than 2 decimal places.';
  return '';
}

function emptyClinicService() {
  return {
    name: '', category_id: '', charge_behavior: '', pricing_method: 'fixed', billing_unit: '',
    notes: '', price: '', min_price: '', max_price: '', tier_basis: 'pet_size',
    tiers: ['small', 'medium', 'large', 'extra_large'].map(size => ({size, enabled: true, price: '', max_weight_kg: ''})),
  };
}

function clinicServicePricing(service) {
  const money = value => '₱' + Number(value).toLocaleString('en-PH', {maximumFractionDigits: 2});
  let label;
  switch (service.pricing_method) {
    case 'range': label = `${money(service.min_price)}–${money(service.max_price)}`; break;
    case 'starting_at': label = `From ${money(service.price)}`; break;
    case 'tiered': {
      const prices = service.tiers.map(tier => Number(tier.price));
      const min = Math.min(...prices), max = Math.max(...prices);
      label = `${money(min)}${min === max ? '' : '–' + money(max)} by ${service.tier_basis === 'weight_kg' ? 'weight' : 'size'}`;
      break;
    }
    default: label = money(service.price);
  }
  // Per service is the implicit billing unit; other units are shown explicitly.
  return label + (service.billing_unit && service.billing_unit !== 'service' ? ` / ${service.billing_unit}` : '');
}

function adminClinicServices() {
  return {
    rows: [], categories: [], q: '', category: '', status: 'active',
    total: 0, currentPage: 1, lastPage: 1,
    loading: false, error: '', loaded: false, optionsLoaded: false,
    _loadedQuery: '',
    _requestId: 0, _searchTimer: null, _activation: null,
    modalOpen: false, saving: false, busyId: null, editingId: null, formError: '',
    form: emptyClinicService(), details: null, _detailsTrigger: null,
    maximumPrice: CLINIC_SERVICE_MAX_PRICE, priceErrors: {}, weightErrors: {},
    init() {},
    get canManageServices() { return !!this.$store.clinicAccess.permissions.services_manage; },
    destroy() { clearTimeout(this._searchTimer); this.$refs.serviceDialog.close(); this.closeDetails(false); },
    async activate() {
      if (this.loaded) return;
      return this._activation ??= this.load().finally(() => { this._activation = null; });
    },
    pricing: clinicServicePricing,
    tierLabel(size) { return ({small: 'Small', medium: 'Medium', large: 'Large', extra_large: 'Extra Large'})[size]; },
    behaviorLabel(value) { return ({base_confinement: 'Base Confinement', admission_charge: 'Admission Charge', additional_charge: 'Additional Charge'})[value] || 'Not configured'; },
    get isConfinementService() { return this.categories.some(c => String(c.id) === String(this.form.category_id) && c.name === 'Confinement'); },
    get isBaseConfinement() { return this.isConfinementService && this.form.charge_behavior === 'base_confinement'; },
    configureConfinement() {
      if (!this.isConfinementService) this.form.charge_behavior = '';
      if (this.isBaseConfinement) {
        Object.assign(this.form, {pricing_method: 'tiered', tier_basis: 'weight_kg', billing_unit: 'day'});
        this.form.tiers.forEach(tier => { tier.enabled = true; });
      } else this.form.tier_basis = 'pet_size';
      this.formError = ''; this.priceErrors = {}; this.weightErrors = {};
    },
    get filtersActive() { return !!(this.q.trim() || this.category || this.status !== 'active'); },
    async load(page = 1) {
      clearTimeout(this._searchTimer);
      const requestId = ++this._requestId;
      const query = JSON.stringify([this.q.trim(), this.category, this.status, page]);
      this.loaded = this.loaded && query === this._loadedQuery;
      this.loading = true;
      this.error = '';
      const params = new URLSearchParams({page, status: this.status});
      if (this.q.trim()) params.set('q', this.q.trim());
      if (this.category) params.set('category_id', this.category);
      try {
        const [response, options] = await Promise.all([
          API.adminRequest('GET', `/admin/clinic-services?${params}`, null, {force: true}),
          this.optionsLoaded ? null : API.adminRequest('GET', '/admin/clinic-services/options'),
        ]);
        if (requestId !== this._requestId) return;
        if (options) {
          this.categories = options.categories;
          this.optionsLoaded = true;
        }
        if (page > response.last_page) { await this.load(response.last_page); return; }
        this.rows = response.data;
        this.total = response.total;
        this.currentPage = response.current_page;
        this.lastPage = response.last_page;
        this.loaded = true;
        this._loadedQuery = query;
      } catch (error) {
        if (requestId === this._requestId) this.error = error.message || 'Unable to load services. Please try again.';
      } finally { if (requestId === this._requestId) this.loading = false; }
    },
    search() {
      // Invalidate an older response as soon as the query changes.
      this._requestId++;
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => this.load(), 300);
    },
    clearFilters() { this.q = ''; this.category = ''; this.status = 'active'; return this.load(); },
    openDetails(service, event) {
      this.details = service;
      this._detailsTrigger = event.currentTarget;
      this.$refs.detailsDialog.showModal();
      this.$nextTick(() => this.$refs.detailsClose.focus());
    },
    closeDetails(restoreFocus = true) {
      this.$refs.detailsDialog.close();
      this.details = null;
      if (restoreFocus) this._detailsTrigger?.focus();
      this._detailsTrigger = null;
    },
    openModal(service = null) {
      if (!this.canManageServices) return;
      this.editingId = service?.id ?? null;
      this.form = emptyClinicService();
      if (service) {
        for (const key of Object.keys(this.form)) {
          if (service[key] != null) this.form[key] = service[key];
        }
        this.form.tiers = emptyClinicService().tiers.map(tier => {
          const saved = service.tiers?.find(row => row.size === tier.size);
          return {...tier, enabled: !!saved, price: saved?.price ?? '', max_weight_kg: saved?.max_weight_kg ?? ''};
        });
      }
      this.formError = '';
      this.priceErrors = {};
      this.configureConfinement();
      this.modalOpen = true;
      this.$refs.serviceDialog.showModal();
      this.$nextTick(() => this.$refs.serviceName.focus());
    },
    closeModal(force = false) {
      if (this.saving && !force) return;
      this.$refs.serviceDialog.close();
      this.modalOpen = false;
    },
    payload() {
      const f = this.form;
      const payload = {
        name: f.name.trim(), category_id: f.category_id,
        pricing_method: f.pricing_method, billing_unit: f.billing_unit || null,
        notes: f.notes || null,
        ...(this.isConfinementService ? {charge_behavior: f.charge_behavior} : {}),
      };
      if (f.pricing_method === 'range') Object.assign(payload, {min_price: f.min_price, max_price: f.max_price});
      else if (f.pricing_method === 'tiered') Object.assign(payload, {
        tier_basis: f.tier_basis,
        tiers: f.tiers.filter(tier => tier.enabled).map(({size, price, max_weight_kg}) => ({size, price,
          ...(this.isBaseConfinement ? {max_weight_kg: size === 'extra_large' ? null : max_weight_kg} : {})})),
      });
      else payload.price = f.price;
      return payload;
    },
    priceFields() {
      if (this.form.pricing_method === 'tiered') {
        return this.form.tiers.filter(tier => tier.enabled).map(tier => ({key: tier.size, value: tier.price}));
      }
      return (this.form.pricing_method === 'range' ? ['min_price', 'max_price'] : ['price'])
        .map(key => ({key, value: this.form[key]}));
    },
    validatePrice(key) {
      const field = this.priceFields().find(field => field.key === key);
      let error = field ? clinicServicePriceError(field.value) : '';
      if (!error && key === 'max_price' && !clinicServicePriceError(this.form.min_price)
        && Number(this.form.max_price) < Number(this.form.min_price)) {
        error = 'Maximum Price must be greater than or equal to Minimum Price.';
      }
      this.priceErrors[key] = error;
      if (key === 'min_price' && this.priceErrors.max_price) this.validatePrice('max_price');
      return !error;
    },
    priceInput(key) {
      // Recheck an existing error while correcting it; first validation happens on blur or Save.
      if (this.priceErrors[key]) this.validatePrice(key);
      if (key === 'min_price' && this.priceErrors.max_price) this.validatePrice('max_price');
    },
    validateCutoffs() {
      this.weightErrors = {};
      if (!this.isBaseConfinement) return true;
      this.form.tiers.slice(0, 3).forEach((tier, index) => {
        const cutoff = Number(tier.max_weight_kg);
        if (!Number.isFinite(cutoff) || cutoff <= 0) this.weightErrors[tier.size] = 'Enter a weight greater than 0 kg.';
        else if (index && cutoff <= Number(this.form.tiers[index - 1].max_weight_kg)) {
          this.weightErrors[tier.size] = 'Must be greater than the previous cutoff.';
        }
      });
      return !Object.values(this.weightErrors).some(Boolean);
    },
    weightRange(tier, service) {
      if (service.tier_basis !== 'weight_kg') return '';
      const index = ['small', 'medium', 'large', 'extra_large'].indexOf(tier.size);
      const previous = service.tiers.find(row => row.size === ['small', 'medium', 'large'][index - 1])?.max_weight_kg;
      if (index === 3) return `Above ${previous} kg`;
      return index === 0 ? `Up to ${tier.max_weight_kg} kg` : `Above ${previous}–${tier.max_weight_kg} kg`;
    },
    async save() {
      if (!this.canManageServices || this.saving) return;
      this.formError = '';
      const payload = this.payload();
      this.priceErrors = {};
      for (const field of this.priceFields()) this.validatePrice(field.key);
      const validCutoffs = this.validateCutoffs();
      if (!validCutoffs || Object.values(this.priceErrors).some(Boolean)) {
        this.$nextTick(() => this.$refs.serviceForm.querySelector('[aria-invalid="true"]')?.focus());
        return;
      }
      if (payload.tiers && !payload.tiers.length) { this.formError = 'Configure at least one pet size tier.'; return; }
      if (!this.$refs.serviceForm.reportValidity()) return;
      this.saving = true;
      try {
        await API.adminRequest(this.editingId ? 'PUT' : 'POST',
          `/admin/clinic-services${this.editingId ? '/' + this.editingId : ''}`, payload);
        this.closeModal(true);
        this.$store.clinicFeedback.notify(this.editingId ? 'Service updated.' : 'Service added.', 'success');
        await this.load(this.editingId ? this.currentPage : 1);
      } catch (error) {
        this.formError = error.errors ? Object.values(error.errors).flat().join(' ') : error.message || 'Unable to save service.';
        this.$nextTick(() => this.$refs.formError.focus());
      } finally { this.saving = false; }
    },
    async changeStatus(service) {
      if (!this.canManageServices || this.busyId) return;
      const active = !service.is_active;
      if (!await this.$store.clinicFeedback.confirm({
        title: `${active ? 'Reactivate' : 'Deactivate'} service?`,
        message: `${service.name}. ${active ? 'This service will be available in the catalog.' : 'This service will become inactive. Existing case charges are preserved.'}`,
        confirmLabel: active ? 'Reactivate' : 'Deactivate', danger: !active, dismissOnEscape: false,
      })) return;
      this.busyId = service.id;
      try {
        await API.adminRequest('PATCH', `/admin/clinic-services/${service.id}/status`, {is_active: active});
        this.$store.clinicFeedback.notify(active ? 'Service reactivated.' : 'Service deactivated.', 'success');
        await this.load(this.currentPage);
      } catch (error) { this.$store.clinicFeedback.notify(error.message || 'Unable to update service status.', 'error'); }
      finally { this.busyId = null; }
    },
  };
}
