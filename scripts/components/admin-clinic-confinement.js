function clinicConfinementWorkspace() {
  const {money, methodLabel, sizeLabel, cataloguePrice} = clinicCaseWorkspace();
  return {
    money, methodLabel, sizeLabel, cataloguePrice,
    confinementCase: null, confinementCaseId: null, episode: null, loading: false, error: '', busy: false,
    extraForm: null, extraPricing: null, extraPending: null, episodePricing: null,
    options: [], optionsPage: 0, optionsLastPage: 1, optionsLoading: false,
    admissionForm: null, admissionPending: null, selectedService: null, pricing: null, pricingLoading: false,
    medicalForm: null, admissionErrors: {}, confirming: false,
    monitoringOpen: false, monitoringForm: null, monitoringPending: null,
    observations: [], observationPage: 0, observationLastPage: 1, observationsLoading: false,
    dischargeForm: null, dischargePending: null, dischargePreview: null, priceError: '',
    _generation: 0, _pricingRequest: 0, _visible: false,
    get clinicalConfinement() {
      const appt = this.confinementCase;
      return !!this.$store.clinicAccess.permissions.clinical && appt?.status === 'in_consultation' && !appt.paid && !appt.archived_at;
    },
    get canAdmitConfinement() { return this.clinicalConfinement && !this.episode && !this.confinementCase?.confinement && !this.loading; },
    get activeConfinement() { return !!this.episode && !this.episode.discharged_at; },
    get canCareForConfinement() { return this.clinicalConfinement && this.activeConfinement; },
    get confinementService() { return this.episode?.case_service?.service_snapshot || this.episode?.case_service?.clinic_service; },
    get confinementStoragePrefix() { return `clinic-confinement:${this.$store.clinicAccess.user?.user_id}:${this.confinementCaseId}:`; },
    formatConfinementTime(value) { return value ? new Date(value).toLocaleString('en-PH', {dateStyle: 'medium', timeStyle: 'short'}) : '—'; },
    duration() { return clinicConfinementDuration(this.episode?.admitted_at, this.episode?.discharged_at || this.$store.clinicConfinementClock?.now || Date.now()); },
    syncConfinement(open, appt, section, medicalForm) {
      if (!open) { if (this.confinementCaseId != null) ++this._generation; this.confinementCaseId = null; this._visible = false; return; }
      if (section !== 'confinement' || !appt?.id) { this._visible = false; return; }
      this.confinementCase = appt;
      this.medicalForm = medicalForm;
      if (this.confinementCaseId === appt.id && this._visible) return;
      if (this.confinementCaseId !== appt.id) {
        ++this._generation; ++this._pricingRequest;
        this.confinementCaseId = appt.id; this.episode = null; this.error = '';
        this.admissionForm = null; this.admissionPending = null; this.monitoringForm = null; this.monitoringPending = null;
        this.monitoringOpen = false; this.dischargeForm = null; this.dischargePending = null;
        this.selectedService = null; this.pricing = null; this.observations = [];
        this.admissionErrors = {}; this.confirming = false;
        this.extraForm = null; this.extraPending = null; this.extraPricing = null; this.episodePricing = null;
      }
      this.admissionErrors = {};
      this._visible = true;
      this.loadConfinement();
    },
    retain(kind, value) {
      try { sessionStorage.setItem(this.confinementStoragePrefix + kind, JSON.stringify(value)); return true; }
      catch { this.error = 'The request could not be retained for a safe retry. Enable browser session storage before submitting.'; return false; }
    },
    restore(kind) {
      try { return JSON.parse(sessionStorage.getItem(this.confinementStoragePrefix + kind) || 'null'); }
      catch { this.error = 'The saved confinement draft could not be loaded.'; return null; }
    },
    clearRetained(kind) { sessionStorage.removeItem(this.confinementStoragePrefix + kind); },
    persistAdmission() { if (this.admissionForm) this.retain('admission', {form: this.admissionForm, pending: this.admissionPending}); },
    persistMonitoring() { if (this.monitoringForm) this.retain('monitoring', {form: this.monitoringForm, pending: this.monitoringPending}); },
    persistDischarge() { if (this.dischargeForm) this.retain('discharge', {form: this.dischargeForm, pending: this.dischargePending}); },
    setConfinementBusy(saving, id = this.confinementCaseId) {
      this.busy = saving;
      window.dispatchEvent(new CustomEvent('clinic-workspace-busy', {detail: {id, saving}}));
    },
    updateConfinementCase(status = 'in_consultation') {
      this.confinementCase = {...this.confinementCase, status, confinement: this.episode};
      window.dispatchEvent(new CustomEvent('clinic-case-patch', {detail: {id: this.confinementCaseId, status, confinement: this.episode}}));
      window.dispatchEvent(new CustomEvent('clinic-case-updated'));
    },
    async loadConfinement() {
      const generation = this._generation;
      this.loading = true; this.error = '';
      try {
        const response = await API.getClinicConfinement(this.confinementCaseId);
        if (generation !== this._generation) return;
        this.episode = response.data; this.episodePricing = response.pricing;
        if (this.episode) {
          this.admissionForm = null; this.admissionPending = null; this.clearRetained('admission');
          await this.loadObservations();
          if (generation !== this._generation) return;
          if (this.canCareForConfinement) {
            const extra = this.restore('extra');
            if (extra) { this.extraForm = extra.form; this.extraPending = extra.pending; this.extraPricing = extra.pricing; }
            await this.loadConfinementOptions();
            const monitor = this.restore('monitoring');
            if (monitor) { this.monitoringForm = monitor.form; this.monitoringPending = monitor.pending; this.monitoringOpen = true; }
            const discharge = this.restore('discharge');
            if (discharge) { this.dischargeForm = discharge.form; this.dischargePending = discharge.pending; await this.loadDischargePreview(); }
          }
        } else if (this.clinicalConfinement) {
          const admission = this.restore('admission');
          if (admission) {
            this.admissionForm = admission.form; this.admissionPending = admission.pending;
            await this.previewAdmissionService();
          }
        }
      } catch (error) { if (generation === this._generation) this.error = error.message || 'Could not load confinement.'; }
      finally { if (generation === this._generation) this.loading = false; }
    },
    async loadConfinementOptions(page = 1) {
      const generation = this._generation;
      this.optionsLoading = true;
      try {
        const response = await API.clinicConfinementOptions(page);
        if (generation !== this._generation) return;
        this.options = page === 1 ? response.data : [...this.options, ...response.data];
        this.optionsPage = response.current_page; this.optionsLastPage = response.last_page;
      } catch (error) { if (generation === this._generation) this.error = error.message || 'Could not load confinement services.'; }
      finally { if (generation === this._generation) this.optionsLoading = false; }
    },
    async openAdmission() {
      if (!this.canAdmitConfinement || this.busy) return;
      this.admissionForm = {reason: '', notes: '', request_key: crypto.randomUUID()};
      this.admissionPending = null; this.admissionErrors = {}; this.error = ''; this.persistAdmission();
      this.$nextTick?.(() => this.$refs.confinementReason?.focus());
      await this.previewAdmissionService();
    },
    async previewAdmissionService() {
      const generation = this._generation, request = ++this._pricingRequest;
      this.pricingLoading = true; this.pricing = null; this.selectedService = null; this.error = ''; this.persistAdmission();
      try {
        const weight = this.medicalForm ? `?weight_kg=${encodeURIComponent(String(this.medicalForm.weight_kg ?? '').trim())}` : '';
        const response = await API.adminRequest('GET', `/admin/clinic-cases/${this.confinementCaseId}/confinement/admission-preview${weight}`);
        if (generation !== this._generation || request !== this._pricingRequest) return;
        this.selectedService = response.data; this.pricing = response.pricing;
      } catch (error) { if (generation === this._generation && request === this._pricingRequest) this.error = error.message || 'Could not preview this service.'; }
      finally { if (generation === this._generation && request === this._pricingRequest) this.pricingLoading = false; }
    },
    get admissionVeterinarianName() {
      const id = this.medicalForm?.primary_veterinarian_id;
      if (this.medicalForm && !id) return 'Set in Medical Record';
      return this.selectedVeterinarians?.find(vet => String(vet.user_id) === String(id))?.name
        || this.confinementCase?.record?.primary_veterinarian_name || 'Set in Medical Record';
    },
    get admissionRecordedWeight() { return this.medicalForm ? this.medicalForm.weight_kg : this.confinementCase?.vitals?.weight_kg; },
    validateAdmission() {
      const errors = {};
      const vet = this.medicalForm ? this.medicalForm.primary_veterinarian_id : this.confinementCase?.record?.primary_veterinarian_id;
      if (!vet) errors.primary_veterinarian_id = 'Select a primary attending veterinarian in Medical Record before admitting this patient.';
      if (!this.admissionForm?.reason.trim()) errors.reason = 'Enter a reason for admission.';
      const weight = this.admissionRecordedWeight;
      if (!Number.isFinite(Number(weight)) || Number(weight) <= 0) {
        errors.weight_kg = "Record the patient's weight in Medical Record first.";
      } else {
        const error = this.weightTools?.getWeightValueValidationMessage(weight);
        if (error) errors.weight_kg = error;
      }
      if (!errors.weight_kg && (!this.pricing || this.pricing.error)) errors.rate = this.pricing?.error || this.error || 'Resolve the confinement rate before admitting this patient.';
      this.admissionErrors = errors;
      return !Object.keys(errors).length;
    },
    showAdmissionError(error, savingPrerequisites = false) {
      const errors = error.errors || {};
      if (errors.primary_veterinarian_id) this.admissionErrors.primary_veterinarian_id = 'Select an available primary attending veterinarian in Medical Record before admitting this patient.';
      if (errors.weight_kg) this.admissionErrors.weight_kg = errors.weight_kg.join(' ');
      if (errors.reason) this.admissionErrors.reason = errors.reason.join(' ');
      this.error = savingPrerequisites
        ? 'Could not save the admission information. ' + (Object.values(errors).flat().join(' ') || error.message || 'Try again before admitting this patient.')
        : Object.values(errors).flat().join(' ') || error.message || 'Admission could not be confirmed. Retry this same request safely.';
    },
    async submitAdmission() {
      if (!this.canAdmitConfinement || this.busy || this.confirming || this.pricingLoading || !this.admissionForm) return;
      if (!this.admissionPending && !this.validateAdmission()) return;
      const payload = this.admissionPending || {...this.admissionForm};
      const id = this.confinementCaseId, generation = this._generation;
      this.confirming = true;
      let confirmed;
      try {
        confirmed = await this.$store.clinicFeedback.confirm({title: 'Admit patient for confinement?',
          message: [`Admit ${this.confinementCase?.pet?.name || 'this patient'} for confinement?`,
            `Attending veterinarian: ${this.admissionVeterinarianName}`,
            this.pricing?.resolved_price != null ? `Confinement rate: ${this.money(this.pricing.resolved_price)}/day` : '',
            `Reason: ${payload.reason.length > 240 ? payload.reason.slice(0, 240).trimEnd() + '…' : payload.reason}`].filter(Boolean).join('\n'),
          cancelLabel: 'Cancel', confirmLabel: 'Admit Patient', danger: false});
      } finally { this.confirming = false; }
      if (!confirmed || generation !== this._generation || !this.canAdmitConfinement) return;
      this.setConfinementBusy(true, id); this.error = '';
      let savingPrerequisites = false;
      try {
        if (!this.admissionPending) {
          savingPrerequisites = true;
          await this.persistConfinementPrerequisites();
          savingPrerequisites = false;
          if (generation !== this._generation) return;
          await this.previewAdmissionService();
          if (generation !== this._generation || !this.validateAdmission()) return;
          this.admissionPending = payload;
          if (!this.retain('admission', {form: this.admissionForm, pending: payload})) { this.admissionPending = null; return; }
        }
        const response = await API.clinicAdmitConfinement(id, payload);
        if (generation !== this._generation) return;
        this.clearRetained('admission');
        this.episode = response.data; this.admissionForm = null; this.admissionPending = null;
        await this.loadConfinement();
        this.updateConfinementCase();
        this.$store.clinicFeedback.notify('Patient admitted for confinement.', 'success');
      } catch (error) {
        if (generation !== this._generation) return;
        if (error.errors || error.status === 422) { this.admissionPending = null; this.persistAdmission(); }
        this.showAdmissionError(error, savingPrerequisites);
      } finally { this.setConfinementBusy(false, id); }
    },
    get visibleConfinementCharges() { return (this.episode?.confinement_charges || []).filter(extra => !extra.removed_at); },
    get admissionCharges() { return this.visibleConfinementCharges.filter(extra => extra.charge_behavior === 'admission_charge'); },
    get additionalConfinementCharges() { return this.visibleConfinementCharges.filter(extra => extra.charge_behavior === 'additional_charge'); },
    weightLabel(weight) { return weight != null && Number.isFinite(Number(weight)) && Number(weight) > 0 ? Number(weight).toFixed(2) + ' kg' : '—'; },
    billableDays(start = this.episode?.admitted_at, end = this.episode?.discharged_at || this.$store.clinicConfinementClock?.now || Date.now()) {
      return Math.max(1, Math.ceil((new Date(end).getTime() - new Date(start).getTime()) / 86400000));
    },
    get baseRate() { return this.episode?.case_service?.applied_price; },
    get baseSize() { return this.episode?.case_service?.service_snapshot?.pet_size; },
    async previewExtra() {
      this.extraPricing = null;
      if (!this.extraForm?.clinic_service_id) return;
      const generation = this._generation, request = ++this._pricingRequest;
      this.pricingLoading = true; this.error = '';
      try {
        const response = await API.clinicCaseServicePricing(this.confinementCaseId, this.extraForm.clinic_service_id);
        if (generation === this._generation && request === this._pricingRequest) this.extraPricing = response;
      } catch (error) { if (generation === this._generation) this.error = error.message; }
      finally { if (generation === this._generation && request === this._pricingRequest) this.pricingLoading = false; }
    },
    async openExtra(extra = null) {
      if (!this.canCareForConfinement || this.busy || this.extraPending) return;
      this.extraForm = extra ? {kind: 'stop', id: extra.id, applied_price: ''}
        : {kind: 'add', clinic_service_id: '', request_key: crypto.randomUUID(), applied_price: ''};
      this.extraPricing = extra ? {data: extra.case_service.clinic_service, pricing: extra.pricing} : null;
      this.error = '';
      if (extra && !this.extraPricing.pricing?.requires_final_price) await this.submitExtra();
      else this.$nextTick?.(() => (extra ? this.$refs.extraFinalPrice : this.$refs.extraService)?.focus());
    },
    get extraNeedsPrice() {
      return !!this.extraPricing?.pricing?.requires_final_price
        && (this.extraForm?.kind === 'stop' || this.extraPricing?.data?.billing_unit !== 'day');
    },
    closeExtra() {
      if (this.busy || this.extraPending) return;
      this.extraForm = null; this.extraPricing = null; this.clearRetained('extra');
      this.$nextTick?.(() => this.$refs.addConfinementCharge?.focus());
    },
    async submitExtra() {
      if (!this.canCareForConfinement || this.busy || this.pricingLoading || !this.extraForm) return;
      const form = this.extraForm;
      if (!this.extraPending && (!this.extraPricing || this.extraPricing.pricing?.error)) return;
      if (!this.extraPending && this.extraNeedsPrice) {
        const error = clinicFinalPriceError(form.applied_price, this.extraPricing.pricing, this.money);
        if (error) { this.error = error; return; }
      }
      const pending = this.extraPending || {kind: form.kind, id: form.id, payload: {
        ...(form.kind === 'add' ? {clinic_service_id: form.clinic_service_id, request_key: form.request_key} : {}),
        ...(this.extraNeedsPrice ? {applied_price: form.applied_price} : {}),
      }};
      if (!this.retain('extra', {form, pending, pricing: this.extraPricing})) return;
      this.extraPending = pending;
      await this.mutateExtra(pending);
    },
    async removeExtra(extra) {
      if (!this.canRemoveExtra(extra) || this.busy || this.extraPending) return;
      if (!await this.$store.clinicFeedback.confirm({title: 'Remove additional charge?', message: extra.case_service.service_snapshot?.name || extra.case_service.clinic_service?.name,
        confirmLabel: 'Remove', danger: true})) return;
      await this.mutateExtra({kind: 'remove', id: extra.id});
    },
    canRemoveExtra(extra) {
      const appt = this.confinementCase;
      return !!this.$store.clinicAccess.permissions.clinical && !appt?.paid && !appt?.archived_at
        && (appt?.status === 'in_consultation' || (API.getUserRole?.() === 'admin' && appt?.status === 'for_payment'))
        && extra.charge_behavior === 'additional_charge';
    },
    async mutateExtra(pending) {
      const generation = this._generation, id = this.confinementCaseId;
      this.setConfinementBusy(true, id); this.error = '';
      try {
        await API.adminRequest(pending.kind === 'remove' ? 'DELETE' : 'POST',
          `/admin/clinic-cases/${id}/confinement/charges${pending.id ? '/' + pending.id : ''}${pending.kind === 'stop' ? '/stop' : ''}`, pending.payload || null);
        if (generation !== this._generation) return;
        this.extraForm = null; this.extraPending = null; this.clearRetained('extra');
        await this.loadConfinement(); this.updateConfinementCase(this.episode?.discharged_at ? 'for_payment' : 'in_consultation');
        this.$store.clinicFeedback.notify(pending.kind === 'remove' ? 'Additional charge removed.' : pending.kind === 'stop' ? 'Daily charge stopped.' : 'Additional charge added.', 'success');
        this.$nextTick?.(() => this.$refs.addConfinementCharge?.focus());
      } catch (error) {
        if (generation !== this._generation) return;
        if (error.errors || error.status === 422) { this.extraPending = null; this.clearRetained('extra'); }
        this.error = error.errors ? Object.values(error.errors).flat().join(' ') : error.message || 'Could not confirm the charge. Retry safely.';
      } finally { this.setConfinementBusy(false, id); }
    },
    async loadObservations(page = 1) {
      const generation = this._generation;
      this.observationsLoading = true;
      try {
        const response = await API.getClinicConfinementObservations(this.confinementCaseId, page);
        if (generation !== this._generation) return;
        this.observations = page === 1 ? response.data : [...this.observations, ...response.data];
        this.observationPage = response.current_page; this.observationLastPage = response.last_page;
      } catch (error) { if (generation === this._generation) this.error = error.message || 'Could not load monitoring history.'; }
      finally { if (generation === this._generation) this.observationsLoading = false; }
    },
    openMonitoring() {
      if (!this.canCareForConfinement || this.busy) return;
      this.monitoringForm ||= {request_key: crypto.randomUUID(), temperature_c: '', heart_rate_bpm: '', respiratory_rate_bpm: '', notes: ''};
      this.monitoringOpen = true; this.error = ''; this.persistMonitoring();
    },
    async submitMonitoring() {
      if (!this.canCareForConfinement || this.busy || !this.monitoringForm) return;
      const form = this.monitoringForm;
      if (![form.temperature_c, form.heart_rate_bpm, form.respiratory_rate_bpm, form.notes.trim()].some(value => value !== '' && value != null)) {
        this.error = 'Record at least one clinical value or observation.'; return;
      }
      const payload = this.monitoringPending || Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' ? null : value]));
      this.monitoringPending = payload;
      if (!this.retain('monitoring', {form, pending: payload})) { this.monitoringPending = null; return; }
      const id = this.confinementCaseId, generation = this._generation;
      this.setConfinementBusy(true, id); this.error = '';
      try {
        await API.clinicRecordConfinementObservation(id, payload);
        if (generation !== this._generation) return;
        this.clearRetained('monitoring');
        this.monitoringForm = null; this.monitoringPending = null; this.monitoringOpen = false;
        await this.loadObservations();
        this.$store.clinicFeedback.notify('Monitoring recorded.', 'success');
      } catch (error) {
        if (generation !== this._generation) return;
        if (error.errors || error.status === 422) { this.monitoringPending = null; this.persistMonitoring(); }
        this.error = error.message || 'Monitoring could not be confirmed. Retry this same request safely.';
      } finally { this.setConfinementBusy(false, id); }
    },
    async loadDischargePreview() {
      const generation = this._generation;
      this.pricingLoading = true;
      try {
        const response = await API.clinicConfinementDischargePreview(this.confinementCaseId);
        if (generation === this._generation) this.dischargePreview = response;
      } catch (error) { if (generation === this._generation) this.error = error.message || 'Could not preview discharge billing.'; }
      finally { if (generation === this._generation) this.pricingLoading = false; }
    },
    async openDischarge() {
      if (!this.canCareForConfinement || this.busy) return;
      this.error = ''; this.priceError = ''; this.dischargePreview = null;
      this.dischargeForm ||= {applied_price: '', discharge_note: '', charge_prices: {}};
      this.persistDischarge(); await this.loadDischargePreview();
    },
    async submitDischarge() {
      if (!this.canCareForConfinement || this.busy || this.confirming || !this.dischargeForm || this.pricingLoading) return;
      const pricing = this.dischargePreview?.pricing;
      if (!this.dischargePending && (!pricing || pricing.error)) return;
      if (!this.dischargePending) {
        for (const extra of this.dischargePreview?.charges || []) {
          const error = extra.pricing.error || (extra.pricing.requires_final_price
            ? clinicFinalPriceError(this.dischargeForm.charge_prices?.[extra.id], extra.pricing, this.money) : '');
          if (error) { this.error = extra.service.name + ': ' + error; return; }
        }
      }
      this.priceError = !this.dischargePending && pricing.requires_final_price
        ? clinicFinalPriceError(this.dischargeForm.applied_price, pricing, this.money) : '';
      if (this.priceError) return;
      const payload = this.dischargePending || {discharge_note: this.dischargeForm.discharge_note,
        charge_prices: this.dischargeForm.charge_prices || {},
        ...(pricing.requires_final_price ? {applied_price: this.dischargeForm.applied_price.trim()} : {})};
      const id = this.confinementCaseId, generation = this._generation;
      this.confirming = true;
      let confirmed;
      try {
        const rate = pricing?.requires_final_price ? payload.applied_price : pricing?.resolved_price;
        confirmed = await this.$store.clinicFeedback.confirm({title: 'Discharge patient?',
          message: ['Discharge this patient from confinement and send the finalized charges to billing?',
            this.dischargePreview ? `Billable days: ${this.dischargePreview.quantity}` : '',
            rate != null ? `Estimated confinement amount: ${this.money(Number(rate) * this.dischargePreview.quantity)}` : ''].filter(Boolean).join('\n'),
          cancelLabel: 'Cancel', confirmLabel: 'Discharge & Send to Billing', danger: false});
      } finally { this.confirming = false; }
      if (!confirmed || generation !== this._generation || !this.canCareForConfinement) return;
      this.dischargePending = payload;
      if (!this.retain('discharge', {form: this.dischargeForm, pending: payload})) { this.dischargePending = null; return; }
      this.setConfinementBusy(true, id); this.error = ''; let succeeded = false;
      try {
        const response = await API.clinicDischargeConfinement(id, payload);
        if (generation !== this._generation) return;
        this.clearRetained('discharge');
        this.episode = response.data; this.dischargeForm = null; this.dischargePending = null;
        this.updateConfinementCase('for_payment');
        this.$store.clinicFeedback.notify('Patient discharged. The case is ready for Billing.', 'success'); succeeded = true;
      } catch (error) {
        if (generation !== this._generation) return;
        if (error.errors || error.status === 422) { this.dischargePending = null; this.persistDischarge(); }
        this.error = error.message || 'Discharge could not be confirmed. Retry to check this episode safely.';
      } finally { this.setConfinementBusy(false, id); }
      if (succeeded) this.selectSection?.('billing');
    },
    closeConfinementForm(kind) {
      if (this.busy || this[kind + 'Pending']) return;
      this[kind + 'Form'] = null; this.clearRetained(kind);
      if (kind === 'monitoring') this.monitoringOpen = false;
    },
  };
}
