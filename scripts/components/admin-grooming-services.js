function adminGroomingServices() {
  const sizes = ['small', 'medium', 'large', 'extra_large'];
  const labels = ['Small', 'Medium', 'Large', 'Extra Large'];
  return {
    rows: [], priceLimits: null, loading: false, loaded: false, error: '', busyId: null,
    groups: [{kind: 'package', label: 'Grooming Packages', action: 'Add Grooming Package'},
      {kind: 'ala_carte', label: 'Ala carte', action: 'Add Ala carte Service'}],
    form: null, editingId: null, saving: false, formError: '', fieldErrors: {}, editorTab: 'details', timeDirty: false, priceNotice: '',
    init() {},
    get canManageServices() { return this.$store.clinicAccess.user?.role === 'admin'; },
    get catalogueEndpoint() { return this.canManageServices ? '/admin/grooming/services' : '/admin/grooming/services/catalogue'; },
    async activate() {
      if (this.loaded && (!API.readAdminCache || API.readAdminCache(this.catalogueEndpoint)?.fresh)) return;
      if (!this.loading) await this.load();
    },
    async load() {
      this.loading = true;
      this.error = '';
      try {
        await API.loadAdminData(this.catalogueEndpoint, () => API.adminRequest('GET', this.catalogueEndpoint), response => {
          this.rows = response.data;
          this.priceLimits = response.priceLimits;
          this.loaded = true;
          this._onPresentation?.();
        });
      } catch (error) { this.error = error.message || 'Unable to load Grooming Services.'; }
      finally { this.loading = false; }
    },
    services(kind) { return this.rows.filter(service => service.kind === kind); },
    pricing(option) {
      const money = value => `₱${Number(value).toLocaleString('en-PH', {maximumFractionDigits: 2})}`;
      return money(option.minAmount) + (option.pricingType === 'starting_at' ? '+'
        : option.pricingType === 'range' ? `–${money(option.maxAmount)}` : '');
    },
    openEditor(kind, service = null) {
      if (!this.canManageServices) return;
      this.editingId = service?.serviceId ?? null;
      this.fieldErrors = {};
      this.formError = '';
      this.editorTab = 'details';
      this.timeDirty = false;
      this.priceNotice = '';
      const priceRows = kind === 'package' ? sizes.map((sizeKey, index) => {
        const saved = service?.priceOptions.find(option => option.sizeKey === sizeKey);
        return {sizeKey, label: labels[index], enabled: !service || !!saved,
          pricingType: saved?.pricingType || 'fixed', amount: saved ? String(saved.minAmount) : '',
          maximum: saved?.pricingType === 'range' ? String(saved.maxAmount) : ''};
      }) : [{sizeKey: '', label: 'Price', enabled: true,
        pricingType: service?.priceOptions[0]?.pricingType || 'fixed',
        amount: service ? String(service.priceOptions[0].minAmount) : '',
        maximum: service?.priceOptions[0]?.pricingType === 'range' ? String(service.priceOptions[0].maxAmount) : ''}];
      this.form = {kind, name: service?.name || '', petType: service?.petType || 'dog',
        descriptionItems: (service?.descriptionItems?.length ? service.descriptionItems : ['']).map((text, index) => ({key: index, text})),
        nextDescriptionKey: service?.descriptionItems?.length || 1,
        includedServiceIds: [...(service?.includedServiceIds || [])],
        isActive: service?.isActive ?? true,
        priceRows, defaultPrices: (service?.defaultPriceOptions || []).map(option => ({...option})),
        timeRows: this.durationRows(service?.timeEstimates),
        supportsHaircuts: service?.supportsHaircuts || false,
        haircuts: (service?.haircutOptions || []).map((cut, index) => ({...cut, key: cut.id,
          timeRows: this.durationRows(cut.estimates)})), nextHaircutKey: 0};
      this.$refs.editor.showModal();
      this.$nextTick(() => this.$refs.serviceName.focus());
    },
    closeEditor() {
      if (this.saving) return;
      this.$refs.editor.close();
      this.form = null;
      this.priceNotice = '';
    },
    addDescription() {
      this.form.descriptionItems.push({key: this.form.nextDescriptionKey++, text: ''});
      this.$nextTick(() => this.$refs.editor.querySelector('[data-description-row]:last-child input')?.focus());
    },
    removeDescription(index) {
      this.form.descriptionItems.splice(index, 1);
      this.$nextTick(() => {
        const inputs = this.$refs.editor.querySelectorAll('[data-description-row] input');
        inputs[Math.min(index, inputs.length - 1)]?.focus();
      });
    },
    restorePrices() {
      for (const option of this.form.defaultPrices) {
        const row = this.form.priceRows.find(row => row.sizeKey === option.sizeKey);
        if (row) Object.assign(row, {enabled: true, pricingType: option.pricingType,
          amount: String(option.minAmount), maximum: option.pricingType === 'range' ? String(option.maxAmount) : ''});
      }
      this.formError = '';
      this.fieldErrors = {};
      this.priceNotice = 'Defaults selected. Save changes to apply.';
    },
    priceKey(row, field) { return (this.form.kind === 'package' ? `sizes.${row.sizeKey}.` : '') + field; },
    durationRows(estimates = {}) {
      const pair = minutes => ({hours: String(Math.floor(minutes / 60)), minutes: String(minutes % 60)});
      return sizes.map((sizeKey, index) => {
        const range = estimates?.[sizeKey];
        return {sizeKey, label: labels[index], enabled: !!range,
          mode: range && range[0] !== range[1] ? 'range' : 'fixed',
          min: pair(range?.[0] || 0), max: pair(range?.[1] || 0)};
      });
    },
    get timeGroups() {
      if (!this.form) return [];
      return [{key:'base', label:this.form.kind === 'package' ? 'Base package time' : 'Service time', base:true, rows:this.form.timeRows},
        ...this.form.haircuts.map(cut => ({key:cut.key, label:cut.name || 'New haircut', cut, rows:cut.timeRows}))];
    },
    supportedTimeRows(rows) {
      return rows.filter(row => this.form.kind === 'ala_carte' || this.form.priceRows.some(price => price.sizeKey === row.sizeKey && price.enabled));
    },
    addHaircut() {
      this.form.haircuts.push({key:'new-'+this.form.nextHaircutKey++, name:'',isActive:true,timeRows:this.durationRows()});
      this.timeDirty = true;
    },
    timeInput(input, row, bound, unit) {
      const value = input.value;
      if (/^[0-9]{0,2}$/.test(value) && (unit !== 'minutes' || Number(value) <= 59)) {
        row[bound][unit] = value; this.timeDirty = true;
      } else input.value = row[bound][unit];
    },
    timeErrors() {
      const errors = [];
      for (const cut of this.form.haircuts) if (!cut.name.trim()) errors.push('Enter a haircut name.');
      for (const group of this.timeGroups) for (const row of this.supportedTimeRows(group.rows)) {
        if (!row.enabled) continue;
        const total = pair => /^[0-9]{1,2}$/.test(pair.hours) && /^[0-9]{1,2}$/.test(pair.minutes)
          && Number(pair.minutes) <= 59 ? Number(pair.hours)*60 + Number(pair.minutes) : null;
        const min = total(row.min), max = row.mode === 'range' ? total(row.max) : min;
        if (min === null || max === null || min <= 0 || (row.mode === 'range' && max <= min)) {
          errors.push(`${group.label} · ${row.label}: enter a positive time${row.mode === 'range' ? ' with a maximum greater than the minimum' : ''}.`);
        }
      }
      return errors;
    },
    timePayload() {
      const estimates = rows => Object.fromEntries(this.supportedTimeRows(rows).map(row => [row.sizeKey,
        row.enabled ? {enabled:true,mode:row.mode,min:{...row.min},...(row.mode === 'range' ? {max:{...row.max}} : {})} : {enabled:false}]));
      return {estimates:estimates(this.form.timeRows),
        haircuts:this.form.haircuts.map(cut => ({...(cut.id ? {id:cut.id} : {}),name:cut.name.trim(),isActive:cut.isActive,estimates:estimates(cut.timeRows)}))};
    },
    pricingErrors() {
      const limits = this.priceLimits?.[this.form.kind];
      const valid = value => limits && /^\d+(?:\.\d{1,2})?$/.test(String(value))
        && Number(value) >= limits.minimum && Number(value) <= limits.maximum;
      const errors = {};
      const rows = this.form.priceRows.filter(row => row.enabled);
      if (!rows.length) errors.sizes = 'Enable a size.';
      let previous = 0;
      for (const row of rows) {
        const key = this.form.kind === 'package' ? `sizes.${row.sizeKey}.` : '';
        if (!valid(row.amount)) errors[key + 'amount'] = 'Enter a reasonable price amount.';
        else if (Number(row.amount) < previous) errors[key + 'amount'] = 'Must not be lower than the preceding size.';
        previous = Number(row.amount);
        if (!['fixed', 'range', 'starting_at'].includes(row.pricingType)) errors[key + 'pricing_type'] = 'Choose a pricing type.';
        if (row.pricingType === 'range') {
          if (!valid(row.maximum)) errors[key + 'maximum'] = 'Enter a reasonable price amount.';
          else if (Number(row.maximum) <= Number(row.amount)) errors[key + 'maximum'] = 'Must be greater than the minimum.';
        }
      }
      return errors;
    },
    validatePricing() {
      this.fieldErrors = this.pricingErrors();
      return Object.keys(this.fieldErrors).length === 0;
    },
    priceInput() {
      const errors = this.pricingErrors();
      for (const key of Object.keys(this.fieldErrors)) this.fieldErrors[key] = errors[key] || '';
    },
    validatePrice(row, field) {
      const key = this.priceKey(row, field);
      this.fieldErrors[key] = this.pricingErrors()[key] || '';
      this.priceInput();
      return !this.fieldErrors[key];
    },
    async save() {
      if (!this.canManageServices) return;
      if (this.saving) return;
      if (!this.$refs.form.checkValidity()) {
        this.editorTab = 'details'; this.$nextTick(() => this.$refs.form.reportValidity()); return;
      }
      if (!this.validatePricing()) { this.editorTab = 'details'; this.$nextTick(() => this.$refs.form.querySelector?.('[aria-invalid="true"]')?.focus()); return; }
      const timeErrors = this.timeErrors();
      if (timeErrors.length) { this.editorTab = 'time'; this.formError = timeErrors.join(' '); this.$nextTick(() => this.$refs.formError.focus()); return; }
      const f = this.form;
      const price = row => ({pricing_type: row.pricingType, amount: row.amount,
        ...(row.pricingType === 'range' ? {maximum: row.maximum} : {})});
      const payload = {name: f.name.trim(), kind: f.kind, isActive: f.isActive,
        ...(f.kind === 'package' ? {petType: f.petType, descriptionItems: f.descriptionItems.map(item => item.text.trim()),
          includedServiceIds: f.includedServiceIds, sizes: Object.fromEntries(f.priceRows.filter(row => row.enabled).map(row => [row.sizeKey, price(row)]))}
          : price(f.priceRows[0])),
        ...(this.timeDirty || !this.editingId ? {timeConfiguration:this.timePayload()} : {})};
      this.saving = true;
      this.formError = '';
      this.fieldErrors = {};
      try {
        await API.adminRequest(this.editingId ? 'PUT' : 'POST',
          `/admin/grooming/services${this.editingId ? '/' + this.editingId : ''}`, payload);
        this.saving = false;
        this.closeEditor();
        await this.load();
        this.$store.clinicFeedback.notify(this.editingId ? 'Service updated.' : 'Service added.');
      } catch (error) {
        this.fieldErrors = Object.fromEntries(Object.entries(error.errors || {}).map(([key, values]) => [key, values[0]]));
        this.formError = Object.values(this.fieldErrors).join(' ') || error.message || 'Unable to save service.';
        this.$nextTick(() => this.$refs.formError.focus());
      } finally { this.saving = false; }
    },
    async changeStatus(service) {
      if (!this.canManageServices) return;
      if (this.busyId !== null) return;
      this.busyId = service.serviceId;
      try {
        const response = await API.adminRequest('PATCH', `/admin/grooming/services/${service.serviceId}/status`, {isActive: !service.isActive});
        this.rows = this.rows.map(row => row.serviceId === service.serviceId ? response.data : row);
        this.$store.clinicFeedback.notify(response.data.isActive ? 'Service activated.' : 'Service deactivated.');
      } catch (error) { this.$store.clinicFeedback.notify(error.message || 'Unable to update service status.', 'error'); }
      finally { this.busyId = null; }
    },
    destroy() { this.$refs.editor.close(); },
  };
}
