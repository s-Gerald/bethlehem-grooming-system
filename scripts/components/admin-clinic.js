// BEGIN GENERATED CLINIC CLINICAL RULES
// Source: resources/data/clinic-clinical-rules.json; run npm run build:clinical.
const CLINIC_CLINICAL_RULES = {"vitals":{"temperature_c":{"min":25,"max":45,"precision":1,"storageMax":999.9},"heart_rate_bpm":{"min":1,"max":400,"precision":0,"storageMax":65535},"respiratory_rate_bpm":{"min":1,"max":200,"precision":0,"storageMax":65535}},"normalRanges":{"dog":{"temperature_c":[37.5,39.2],"heart_rate_bpm":[60,160],"respiratory_rate_bpm":[10,30]},"cat":{"temperature_c":[38.1,39.2],"heart_rate_bpm":[120,240],"respiratory_rate_bpm":[24,42]}},"doseUnits":[{"value":"mL","label":"mL"},{"value":"mg","label":"mg"},{"value":"mcg","label":"mcg"},{"value":"g","label":"g"},{"value":"international units","label":"International units"},{"value":"tablet","label":"Tablet(s)"},{"value":"capsule","label":"Capsule(s)","whole":true},{"value":"drop","label":"Drop(s)","whole":true},{"value":"puff","label":"Puff(s)","whole":true},{"value":"dose","label":"Dose(s)","whole":true},{"value":"other","label":"Other — specify"}],"dosePrecision":3,"doseMax":99999.999,"doseUnitMaxLength":30,"injectionSiteRoutes":["subcutaneous","intramuscular"],"sources":["https://www.merckvetmanual.com/multimedia/table/normal-rectal-temperature-ranges","https://books.lib.uoguelph.ca/vetm3430/chapter/vital-parameters/"]};
// END GENERATED CLINIC CLINICAL RULES
let clinicPetWeightToolsReady;
function loadClinicPetWeightTools() {
  return clinicPetWeightToolsReady ??= import("./pet-weight-size.js?v=pet-weight-size-20261107-v1");
}

function clinicValidNumber(value, precision, min, max) {
  if (!['string', 'number'].includes(typeof value)) return false;
  const text = String(value).trim();
  const pattern = precision === 0 ? /^\d+$/ : new RegExp(`^(?:\\d+(?:\\.\\d{1,${precision}})?|\\.\\d{1,${precision}})$`);
  return pattern.test(text) && Number.isFinite(Number(text)) && Number(text) >= min && Number(text) <= max;
}

function clinicIsVeterinarian(user) {
  return user?.staff_subrole === 'veterinarian' && user?.role === 'staff';
}

function clinicVitalEntryValid(field, value, exceptional = false) {
  const rule = CLINIC_CLINICAL_RULES.vitals[field];
  return clinicValidNumber(value, rule.precision, exceptional ? 0 : rule.min, exceptional ? rule.storageMax : rule.max);
}

function clinicVitalNeedsVerification(field, value) {
  return String(value ?? '').trim() !== '' && !clinicVitalEntryValid(field, value) && clinicVitalEntryValid(field, value, true);
}

function clinicVitalFieldMessage(field, rawValue, {required = false, veterinarian = false, verification, savedVerification = false} = {}) {
  const value = String(rawValue ?? '').trim();
  if (!value) return required ? 'Required' : '';
  if (clinicVitalEntryValid(field, value)) return '';
  const exceptional = clinicVitalEntryValid(field, value, true);
  const notes = globalThis.ShortNotes;
  const reasonTooLong = notes ? notes.tooLong(verification?.reason) : Array.from(String(verification?.reason ?? '')).length > 500;
  if (exceptional && (savedVerification
    || (veterinarian && verification?.value === value && String(verification.reason ?? '').trim().length >= 5 && !reasonTooLong))) return '';
  if (verification?.value === value && veterinarian) return reasonTooLong ? (notes?.limitMessage || 'Use up to 500 characters.') : 'Enter a clinical reason (at least 5 characters).';
  if (exceptional) return 'This reading requires veterinarian verification.';
  const rule = CLINIC_CLINICAL_RULES.vitals[field];
  return field === 'temperature_c' ? `Use ${rule.min}–${rule.max} °C, up to ${rule.precision} decimal.`
    : `Use a whole number from ${rule.min} to ${rule.max}.`;
}

function clinicVitalWarning(field, value, species) {
  const range = CLINIC_CLINICAL_RULES.normalRanges[String(species ?? '').trim().toLowerCase()]?.[field];
  if (!range || !clinicVitalEntryValid(field, value, true)) return '';
  return Number(value) < range[0] || Number(value) > range[1] ? 'Unusual reading. Please confirm.' : '';
}

function clinicDoseErrors(amount, unit, required = false) {
  const errors = {}, catalog = CLINIC_CLINICAL_RULES;
  const hasAmount = amount != null && String(amount).trim() !== '', hasUnit = Boolean(unit);
  if ((required || hasUnit) && !hasAmount) errors.dose_amount = 'Enter the dose amount.';
  if ((required || hasAmount) && !hasUnit) errors.dose_unit = 'Select a dose unit.';
  if (hasAmount && !clinicValidNumber(amount, catalog.dosePrecision, 0.001, catalog.doseMax)) {
    errors.dose_amount = 'Enter 0.001–99,999.999, up to 3 decimals.';
  }
  if (hasAmount && catalog.doseUnits.find(item => item.value === unit)?.whole
      && Number.isFinite(Number(amount)) && !Number.isInteger(Number(amount))) errors.dose_amount = 'Use a whole-number count for this unit.';
  return errors;
}

function clinicLocalDate(d) {
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

// ── Shared confirm dialog and toast ───────────────────────────────────────────
// Used by every clinic page component instead of the browser's alert()/confirm().

// Threshold bands use the next starting weight; explicit historical ranges retain their endpoints.
function clinicMedicinePrice(item, weight) {
  if (item.pricing_method !== "weight_based") {
    if (!(Number(item.selling_price) > 0)) return { error: "Set this medicine's selling price in Inventory." };
    return { price: Number(item.selling_price), pet_weight_kg: null, min_weight_kg: null, max_weight_kg: null };
  }
  if (weight === "" || weight == null || !Number.isFinite(Number(weight)) || Number(weight) <= 0)
    return { error: "Record or update the pet's weight before selecting a weight-based medicine price." };
  const ranges = item.weight_prices || [];
  const matches = ranges.map((range, index) => {
    if (item.weight_pricing_mode === "catalog") {
      const min = Number(range.min_weight_kg), next = ranges[index + 1];
      let max = range.max_weight_kg, matches;
      if (min === 0) matches = max != null && Number(weight) < Number(max);
      else if (max == null) matches = Number(weight) > min;
      else {
        if (next) max = next.min_weight_kg;
        const inclusive = !next || next.max_weight_kg == null;
        matches = Number(weight) >= min && (inclusive ? Number(weight) <= Number(max) : Number(weight) < Number(max));
      }
      return {...range, max_weight_kg: max, matches};
    }
    const final = index === ranges.length - 1;
    const threshold = item.weight_pricing_mode === "threshold";
    const max = threshold && !final ? ranges[index + 1].min_weight_kg : range.max_weight_kg;
    const inclusive = !threshold || (final && Number(range.min_weight_kg) > 0);
    const open = threshold && final && Number(range.min_weight_kg) > 0 && max === null;
    return { ...range, max_weight_kg: max, matches: Number(weight) >= Number(range.min_weight_kg)
      && (open || (max != null && max !== "" && (inclusive ? Number(weight) <= Number(max) : Number(weight) < Number(max)))) };
  }).filter(range => range.matches);
  if (matches.length !== 1) return { error: "No price is configured for this pet's weight. Correct the pricing configuration or pet weight." };
  return { price: Number(matches[0].price), pet_weight_kg: Number(weight),
    min_weight_kg: matches[0].min_weight_kg, max_weight_kg: matches[0].max_weight_kg };
}

function clinicPriceRange(range) {
  return range.max_weight_kg == null ? `${Number(range.min_weight_kg)} kg and above`
    : `${Number(range.min_weight_kg)}–${Number(range.max_weight_kg)} kg`;
}

// ── Medical Record Modal ──────────────────────────────────────────────────────

function adminClinicModal() {
  return {
    open:       false,
    saving:     false,
    modalError: "",
    treatmentValidationError: false,
    workspaceSaving: false,
    weightTools: null,
    title:      "Medical Record",
    apptId:     null,
    currentAppt: null,
    activeSection: "medical",
    chargesOpen: false,
    formErrors: {},
    vitalExceptions: {},
    completionAttempted: false,
    veterinarians: [], optionsLoading: false, optionsError: "",
    vetDropdownOpen: false,
    vetSearch: "",
    administrationRoutes: [],
    recordedByName: "",
    returnFocus: null,
    _optionsRequest: 0,
    _clinicalOptionsLoaded: false,
    attachments: [],
    attachmentBusy: false,
    attachmentBusyId: null,
    attachmentError: "",
    form: {
      chief_complaint: "", relevant_history: "", reported_signs: "", physical_exam_findings: "",
      differential_diagnoses: "", working_diagnosis: "", treatment_given: "",
      primary_veterinarian_id: "", additional_veterinarian_ids: [], prescriptions: [],
      vet_notes: "", follow_up_date: "", follow_up_notes: "",
      weight_kg: "", temperature_c: "", heart_rate_bpm: "",
      respiratory_rate_bpm: "", body_condition_score: "",
    },

    init() {
      window.addEventListener("clinic-open-modal", (e) => this.openModal(e.detail));
      window.addEventListener("clinic-workspace-busy", (event) => {
        if (event.detail.id === this.apptId) this.workspaceSaving = event.detail.saving;
      });
      window.addEventListener("clinic-cases-loaded", (event) => {
        if (!this.open) return;
        const current = event.detail?.find((item) => item.id === this.apptId);
        if (current) this.currentAppt = current;
        else if (!this.currentAppt?.confinement) this.open = false;
      });
      window.addEventListener('clinic-case-patch', (event) => {
        if (this.open && event.detail.id === this.apptId) this.currentAppt = {...this.currentAppt, ...event.detail};
      });
      window.addEventListener('clinic-review-payment', (event) => {
        if (this.open && event.detail.id === this.apptId) this.closeModal();
      });
      window.addEventListener('clinic-treatment-validation', (event) => {
        if (event.detail.id !== this.apptId || !this.treatmentValidationError) return;
        this.modalError = event.detail.hasErrors ? 'Check the highlighted fields.' : '';
        if (!event.detail.hasErrors) this.treatmentValidationError = false;
      });
    },

    get canEditClinical() {
      return !!this.$store.clinicAccess.permissions.clinical
        && !this.currentAppt?.paid && !this.currentAppt?.archived_at
        && (this.currentAppt?.status === "in_consultation"
          || (API.getUserRole() === "admin" && this.currentAppt?.status === "for_payment"));
    },

    get isPreRegistered() { return this.currentAppt?.appointment_type === 'pre_registered'; },

    get canFinish() { return this.canEditClinical && this.currentAppt?.status === "in_consultation"
      && !(this.currentAppt?.confinement && !this.currentAppt.confinement.discharged_at); },
    get isVeterinarian() { return clinicIsVeterinarian(this.$store.clinicAccess.user); },
    get selectedVeterinarianIds() {
      return [...new Set([this.form.primary_veterinarian_id, ...this.form.additional_veterinarian_ids].filter(Boolean).map(String))];
    },
    get lockedVeterinarianId() {
      return this.canEditClinical && this.isVeterinarian ? String(this.$store.clinicAccess.user.user_id) : null;
    },
    get selectedVeterinarians() {
      const saved = [
        { user_id: this.currentAppt?.record?.primary_veterinarian_id, name: this.currentAppt?.record?.primary_veterinarian_name },
        ...(this.currentAppt?.record?.additional_veterinarians || []),
      ];
      return this.selectedVeterinarianIds.map(id => {
        const vet = this.veterinarians.find(vet => String(vet.user_id) === id) || saved.find(vet => String(vet.user_id) === id);
        const user = this.$store.clinicAccess.user;
        const ownName = id === this.lockedVeterinarianId ? [user?.first_name, user?.last_name].filter(Boolean).join(" ") : "";
        return { user_id: id, name: vet?.name || ownName || "Veterinarian" };
      });
    },
    get filteredVeterinarians() {
      const search = this.vetSearch.trim().toLowerCase();
      return this.veterinarians.filter(vet => String(vet.user_id) !== this.lockedVeterinarianId && vet.name.toLowerCase().includes(search));
    },
    toggleVeterinarian(id) {
      id = String(id);
      if (!this.canEditClinical || this.saving || id === this.lockedVeterinarianId) return;
      const selected = this.selectedVeterinarianIds;
      const next = selected.includes(id) ? selected.filter(value => value !== id) : [...selected, id];
      if (this.lockedVeterinarianId) {
        this.form.primary_veterinarian_id = this.lockedVeterinarianId;
        this.form.additional_veterinarian_ids = next.filter(value => value !== this.lockedVeterinarianId);
      } else {
        this.form.primary_veterinarian_id = next[0] || "";
        this.form.additional_veterinarian_ids = next.slice(1);
      }
    },
    removeVeterinarian(id) {
      const trigger = this.$refs.vetTrigger;
      this.toggleVeterinarian(id);
      this.$nextTick?.(() => trigger?.focus());
    },
    toggleVetDropdown() {
      this.vetDropdownOpen = !this.vetDropdownOpen;
      this.vetSearch = "";
      if (this.vetDropdownOpen) this.$nextTick?.(() => {
        const selector = this.veterinarians.length > 8 ? 'input[type="search"]' : 'input[type="checkbox"]';
        this.$refs.vetDropdown?.querySelector(selector)?.focus();
      });
    },
    closeVetDropdown(restoreFocus = false) {
      this.vetDropdownOpen = false;
      if (restoreFocus) this.$refs.vetTrigger?.focus();
    },
    vetDropdownKeydown(event) {
      if (event.key === "Enter") {
        event.preventDefault();
        if (event.target.type === "checkbox") event.target.click();
        return;
      }
      if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
      if (event.target.type === "search" && event.key !== "ArrowDown") return;
      const choices = [...this.$refs.vetDropdown.querySelectorAll('input[type="checkbox"]')];
      if (!choices.length) return;
      event.preventDefault();
      const index = choices.indexOf(event.target);
      const next = event.key === "Home" ? 0 : event.key === "End" ? choices.length - 1
        : (index + (event.key === "ArrowDown" ? 1 : -1) + choices.length) % choices.length;
      choices[next].focus();
    },
    errorForField(field) {
      return Object.entries(this.formErrors).find(([key]) => key === field || key.startsWith(field + "."));
    },
    hasFieldError(field) { return Boolean(this.errorForField(field)); },
    vitalEntryValid(field, value, exceptional = false) {
      return clinicVitalEntryValid(field, value, exceptional);
    },
    hasSavedVitalVerification(field) {
      const saved = this.currentAppt?.vitals;
      if (saved?.[field] == null || Number(saved[field]) !== Number(this.form[field])) return false;
      const verification = [...(saved.verification_history || [])].reverse().find(entry => entry.field === field);
      return verification && Number(verification.value) === Number(this.form[field]);
    },
    vitalNeedsVerification(field) {
      return clinicVitalNeedsVerification(field, this.form[field]);
    },
    startVitalVerification(field) {
      if (!this.canEditClinical || !this.isVeterinarian || !this.vitalNeedsVerification(field)) return;
      this.vitalExceptions[field] = {value: String(this.form[field]).trim(), reason: ''};
    },
    vitalFieldMessage(field) {
      const value = String(this.form[field] ?? '').trim();
      if (!value) return this.completionAttempted && field !== 'body_condition_score' ? 'Required' : '';
      if (field === 'weight_kg') return this.weightTools?.getWeightValueValidationMessage(value, { clinical: true }) || '';
      if (field === 'body_condition_score') return clinicValidNumber(value, 0, 1, 9) ? '' : 'Select a score from 1 to 9.';
      return clinicVitalFieldMessage(field, value, {veterinarian: this.isVeterinarian,
        verification: this.vitalExceptions[field], savedVerification: this.hasSavedVitalVerification(field)});
    },
    validateVitalField(field, interaction = 'blur') {
      if (this.vitalExceptions[field] && (this.vitalExceptions[field].value !== String(this.form[field]).trim()
        || this.vitalEntryValid(field, this.form[field]))) delete this.vitalExceptions[field];
      const message = this.vitalFieldMessage(field);
      if (interaction === 'input') {
        if (!this.hasFieldError(field) || message) return;
        delete this.formErrors[field];
      } else if (message) this.formErrors[field] = [message];
      else delete this.formErrors[field];
      this.refreshValidationSummary();
    },
    refreshValidationSummary() {
      const messages = Object.values(this.formErrors);
      this.modalError = !messages.length ? '' : messages.every(items => this.isMissingFieldError(items[0]))
        ? 'Complete the required fields marked *.' : 'Check the highlighted fields.';
    },
    vitalWarning(field) {
      return clinicVitalWarning(field, this.form[field], this.currentAppt?.pet?.species);
    },
    isMissingFieldError(message) {
      return /\bis required\b|^required$/i.test(message);
    },
    fieldError(field) {
      const error = this.errorForField(field);
      if (!error) return "";
      const [key, messages] = error;
      if (this.isMissingFieldError(messages[0])) return "";
      if (/characters/i.test(messages[0])) {
        const limit = messages[0].match(/\b(\d[\d,]*)\s+characters/i)?.[1];
        if (limit) return `Use up to ${Number(limit.replaceAll(",", "")).toLocaleString()} characters.`;
      }
      const name = key.split(".").at(-1);
      if (key.startsWith("additional_veterinarian_ids")) return "Select an available veterinarian.";
      if (name === "weight_kg") return this.weightTools?.getWeightValueValidationMessage(this.form.weight_kg, { clinical: true }) || "Enter a valid weight.";
      return {
        primary_veterinarian_id: "Select an available veterinarian.",
        temperature_c: this.vitalFieldMessage('temperature_c') || 'Invalid temperature.',
        heart_rate_bpm: this.vitalFieldMessage('heart_rate_bpm') || 'Invalid value.',
        respiratory_rate_bpm: this.vitalFieldMessage('respiratory_rate_bpm') || 'Invalid value.',
        body_condition_score: "Select a score from 1 to 9.",
        follow_up_date: "Choose a follow-up date within the allowed range.",
        medicine: "Enter a medicine name.", strength_concentration: "Enter a valid strength or concentration.",
        dose: "Enter a valid dose.", route: "Select a route.", frequency: "Enter a valid frequency.",
        duration: "Enter a valid duration.", quantity: "Enter a positive quantity, e.g. 14 tablets.",
        prescriptions: "Check the prescription entries.",
      }[name] || "Check this value.";
    },
    showValidationErrors(errors) {
      this.activeSection = 'medical';
      this.treatmentValidationError = false;
      this.formErrors = Object.fromEntries(Object.entries(errors).map(([field, messages]) => [
        field.startsWith('vital_exceptions.') ? field.split('.')[1] : field, messages,
      ]));
      const missingOnly = Object.values(errors).every(messages => this.isMissingFieldError(messages[0]));
      this.modalError = missingOnly ? "Complete the required fields marked *." : "Check the highlighted fields.";
      this.focusInvalidField();
    },
    focusInvalidField() {
      this.$nextTick?.(() => {
        const modal = this.$el.closest?.('[x-data="adminClinicModal()"]') || this.$el;
        const field = modal.querySelector('#medical-record-form [aria-invalid="true"]')
          || modal.querySelector('#medical-validation-summary');
        field?.focus();
        field?.scrollIntoView?.({ block: "nearest" });
      });
    },
    trapFocus(event) {
      const scope = this.chargesOpen ? event.currentTarget.querySelector('#case-billing-panel') : event.currentTarget;
      const controls = [...scope.querySelectorAll('button, input, select, textarea, a[href], [tabindex="0"]')]
        .filter(element => !element.disabled && element.getClientRects().length);
      const first = controls[0], last = controls.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    },
    routeLabel(route) { return route ? route[0].toUpperCase() + route.slice(1) : "—"; },
    addPrescription() {
      this.form.prescriptions.push({ medicine: "", strength_concentration: "", dose: "", route: "", frequency: "", duration: "", quantity: "", instructions: "" });
    },
    removeRow(section, index) { this.form[section].splice(index, 1); this.formErrors = {}; },
    async loadClinicalOptions() {
      const request = ++this._optionsRequest;
      this.optionsLoading = true;
      this.optionsError = "";
      try {
        const response = await API.getAdminVaccinationOptions(true);
        if (request !== this._optionsRequest || !this.open) return;
        this.veterinarians = response.veterinarians || [];
        this.administrationRoutes = response.administration_routes || [];
        this._clinicalOptionsLoaded = true;
        if (!this.currentAppt?.record?.recorded_by_name) this.recordedByName = response.current_user_name || "—";
        if (this.canEditClinical && response.current_user_is_veterinarian) {
          this.form.primary_veterinarian_id = String(response.current_user_id);
          this.form.additional_veterinarian_ids = this.form.additional_veterinarian_ids.filter(id => String(id) !== String(response.current_user_id));
        }
      } catch (error) {
        if (request === this._optionsRequest) this.optionsError = error.message || "Unable to load veterinarian and clinical options.";
      } finally { if (request === this._optionsRequest) this.optionsLoading = false; }
    },
    get followUpMinDate() {
      return clinicLocalDate(new Date());
    },

    get followUpMaxDate() {
      const d = new Date();
      d.setMonth(d.getMonth() + 3);
      return clinicLocalDate(d);
    },

    async openModal({ appt, section = "medical" } = {}) {
      if (!this.$store.clinicAccess.permissions.clinical) return;
      if (!appt) return;
      this.returnFocus = document.activeElement;
      this.weightTools = await loadClinicPetWeightTools();
      this.vetDropdownOpen = false;
      this.vetSearch = "";
      this.apptId     = appt.id;
      this.currentAppt = appt;
      ++this._optionsRequest;
      this.optionsLoading = false;
      this._clinicalOptionsLoaded = false;
      this.activeSection = "medical";
      this.chargesOpen = false;
      this.modalError = "";
      this.title      = `Patient Record — ${appt.ownerName}${appt.pet?.name ? " / " + appt.pet.name : ""}`;
      this.treatmentValidationError = false;
      const r = appt.record  || {};
      const v = appt.vitals  || {};
      this.attachments = (r.attachments || []).map((attachment) => ({ ...attachment }));
      this.attachmentBusy = false;
      this.attachmentBusyId = null;
      this.attachmentError = "";
      this.formErrors = {};
      this.recordedByName = r.recorded_by_name || "—";
      this.vitalExceptions = {};
      this.completionAttempted = false;
      this.form = {
        chief_complaint: this.isPreRegistered ? (appt.chief_complaint ?? "") : (r.chief_complaint || appt.chief_complaint || ""),
        relevant_history: r.relevant_history || "",
        reported_signs: r.reported_signs || "",
        physical_exam_findings: r.physical_exam_findings ?? r.findings ?? "",
        differential_diagnoses: r.differential_diagnoses || "",
        working_diagnosis: r.working_diagnosis ?? r.diagnosis ?? "",
        primary_veterinarian_id: String(r.primary_veterinarian_id || ""),
        additional_veterinarian_ids: (r.additional_veterinarians || []).map(vet => String(vet.user_id)),
        prescriptions: (r.prescriptions || []).map(row => ({ ...row })),
        treatment_given:      r.treatment_given      || "",
        vet_notes:            r.vet_notes            || "",
        follow_up_date:       r.follow_up_date       || "",
        follow_up_notes:      r.follow_up_notes      || "",
        weight_kg:            this.weightTools.formatSavedWeight(v.weight_kg ?? appt.pet?.weight),
        temperature_c:        v.temperature_c        ?? "",
        heart_rate_bpm:       v.heart_rate_bpm       ?? "",
        respiratory_rate_bpm: v.respiratory_rate_bpm ?? "",
        body_condition_score: v.body_condition_score ?? "",
      };
      globalThis.ShortNotes?.reset(this.$el.querySelector('#medical-record-form'));
      this.open = true;
      if (this.canEditClinical) {
        if (this.isVeterinarian) {
          this.form.primary_veterinarian_id = String(this.$store.clinicAccess.user.user_id);
          this.form.additional_veterinarian_ids = this.form.additional_veterinarian_ids.filter(id => id !== this.form.primary_veterinarian_id);
        }
      }
      this.selectSection(section);
      if (this.canEditClinical && !this._clinicalOptionsLoaded && !this.optionsLoading) this.loadClinicalOptions();
      this.$nextTick?.(() => {
        this.$el.querySelector('[aria-label="Close patient record"]')?.focus();
      });
    },

    selectSection(section) {
      if (this.saving || this.attachmentBusy || this.workspaceSaving) return;
      if (section === "billing") { this.openCharges(); return; }
      this.activeSection = ["vaccinations", "services", "confinement"].includes(section) ? section : "medical";
      if (this.activeSection === "medical" && this.canEditClinical && !this._clinicalOptionsLoaded && !this.optionsLoading) {
        this.loadClinicalOptions();
      }

      if (this.activeSection === "vaccinations" && this.currentAppt) {
        this.syncTreatmentAppointment();
      }
    },

    syncTreatmentAppointment() {
      const detail = {
        appt: {...this.currentAppt, vitals: {...this.currentAppt?.vitals, weight_kg: this.form.weight_kg}},
      };
      window.dispatchEvent(new CustomEvent('clinic-vaccinations-open', {detail}));
      return detail.recordsReady;
    },

    caseTabKeydown(event) {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || this.workspaceSaving) return;
      const tabs = [...event.currentTarget.querySelectorAll('[role="tab"]')].filter(tab => !tab.disabled && tab.getClientRects().length);
      const index = tabs.indexOf(event.target);
      if (index < 0) return;
      event.preventDefault();
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
      tabs[next]?.click(); tabs[next]?.focus();
    },

    openCharges() {
      if (!this.$store.clinicAccess.permissions.billing_view || !this.currentAppt?.id || this.saving || this.attachmentBusy || this.workspaceSaving) return;
      this.chargesOpen = true;
      this.$nextTick?.(() => this.$el.closest('[x-data="adminClinicModal()"]')?.querySelector('[aria-label="Close charges"]')?.focus());
    },
    closeCharges() {
      this.chargesOpen = false;
      this.$nextTick?.(() => this.$el.closest('[x-data="adminClinicModal()"]')?.querySelector('[aria-controls="case-billing-panel"]')?.focus());
      if (this.activeSection === 'medical') this.selectSection('medical');
    },
    closeModal() {
      if (this.saving || this.attachmentBusy || this.workspaceSaving) return;
      this.open = false;
      this.chargesOpen = false;
      this.vetDropdownOpen = false;
      ++this._optionsRequest;
      this.optionsLoading = false;
      this.returnFocus?.focus();
      this.activeSection = "medical";
      window.dispatchEvent(new CustomEvent('clinic-record-closed'));
    },

    async viewAttachment(attachment) {
      this.attachmentBusyId = attachment.id;
      this.attachmentError = "";
      try {
        await viewClinicAttachment(this.apptId, attachment);
      } catch (error) {
        this.attachmentError = error.message || "Failed to open attachment.";
      } finally {
        this.attachmentBusyId = null;
      }
    },

    async persistConfinementPrerequisites() {
      const weight = String(this.form.weight_kg ?? '').trim();
      const response = await API.clinicSaveRecord(this.apptId, {
        primary_veterinarian_id: this.form.primary_veterinarian_id || null,
        additional_veterinarian_ids: this.form.additional_veterinarian_ids,
        weight_kg: weight || null,
        finish_case: false,
      });
      this.currentAppt = {...this.currentAppt, record: response.record,
        vitals: {...this.currentAppt.vitals, weight_kg: weight || null}};
      this.recordedByName = response.record.recorded_by_name;
    },

    focusAdmissionMedicalField(field) {
      this.selectSection('medical');
      this.$nextTick?.(() => {
        const target = field === 'weight_kg' ? this.$refs.medicalWeight : this.$refs.vetTrigger;
        target?.focus(); target?.scrollIntoView?.({block: 'nearest'});
      });
    },

    clearRecordNoteError(field) {
      const key = field.dataset.recordNote;
      const notes = globalThis.ShortNotes;
      if (key && notes && this.formErrors[key]?.[0] === notes.shortenMessage && !notes.tooLong(field.value)) {
        delete this.formErrors[key];
      }
    },

    async validateRecordForSave(requireCompletion) {
      this.formErrors = {};
      this.modalError = "";
      this.weightTools ??= await loadClinicPetWeightTools();
      this.completionAttempted = requireCompletion;
      if (requireCompletion && !String(this.form.primary_veterinarian_id ?? '').trim()) {
        this.formErrors.primary_veterinarian_id = ['Required'];
      }
      for (const field of ['weight_kg', ...Object.keys(CLINIC_CLINICAL_RULES.vitals), 'body_condition_score']) {
        this.validateVitalField(field, 'save');
      }
      const followUp = this.form.follow_up_date;
      if (followUp && (followUp < this.followUpMinDate || followUp > this.followUpMaxDate)) {
        this.formErrors.follow_up_date = ['Invalid date'];
      }
      if (Object.keys(this.formErrors).length) {
        throw Object.assign(new Error('Check the highlighted fields.'), {errors: {...this.formErrors}});
      }
    },

    async saveRecord(finishCase = false, {forDischarge = false} = {}) {
      if (!this.canEditClinical || this.saving || (this.workspaceSaving && !forDischarge)
          || this.attachmentBusy || this.optionsLoading || (finishCase && !this.canFinish)) {
        if (forDischarge) throw new Error('The Medical Record is unavailable for saving. Try again.');
        return;
      }
      const notes = globalThis.ShortNotes;
      const original = this.currentAppt?.record || {};
      const narratives = {};
      for (const field of ['chief_complaint', 'relevant_history', 'reported_signs', 'physical_exam_findings',
        'differential_diagnoses', 'working_diagnosis', 'treatment_given', 'vet_notes', 'follow_up_notes']) {
        if (field === 'chief_complaint' && this.isPreRegistered) continue;
        const previous = original[field] ?? (field === 'chief_complaint' ? this.currentAppt?.chief_complaint
          : field === 'working_diagnosis' ? original.diagnosis : field === 'physical_exam_findings' ? original.findings : null);
        if (!notes?.unchangedLegacy(this.form[field], previous)) narratives[field] = this.form[field] || null;
      }
      const prescriptions = this.form.prescriptions;
      if (!prescriptions.some(row => notes?.tooLong(row.instructions))
        || JSON.stringify(prescriptions) !== JSON.stringify(original.prescriptions || [])) {
        narratives.prescriptions = prescriptions;
      }
      this.saving = true;
      const preparation = {id: this.apptId, finishCase, promise: null, errors: null};
      try {
        if (notes) {
          const errors = {};
          for (const [field, value] of Object.entries(narratives)) {
            if (notes.tooLong(value)) errors[field] = [notes.shortenMessage];
          }
          narratives.prescriptions?.forEach((row, index) => {
            if (notes.tooLong(row.instructions)) errors[`prescriptions.${index}.instructions`] = [notes.shortenMessage];
          });
          if (Object.keys(errors).length) throw Object.assign(new Error('Check the highlighted fields.'), {errors});
        }
        if (forDischarge) await this.validateRecordForSave(true);
        window.dispatchEvent(new CustomEvent('clinic-treatment-prepare', {detail: preparation}));
        if (preparation.errors) {
          if (forDischarge) throw Object.assign(new Error('Check the current treatment before discharging.'), {errors: preparation.errors});
          this.showTreatmentValidation(preparation.errors);
          return;
        }
        const treatmentReady = await (preparation.promise || Promise.resolve(true));
        if (!treatmentReady) {
          if (forDischarge) throw new Error('Check the current treatment in the Treatment tab before discharging.');
          this.activeSection = 'vaccinations';
          this.syncTreatmentAppointment();
          window.dispatchEvent(new CustomEvent('clinic-treatment-focus', {detail: {id: this.apptId,
            message: finishCase ? 'Add or discard the current treatment before sending this case to payment.' : 'Check the current treatment before saving.'}}));
          return;
        }
        if (!forDischarge) await this.validateRecordForSave(finishCase);
        if (finishCase) {
          const confirmed = await this.$store.clinicFeedback.confirm({title: 'Send case to payment?',
            message: 'Confirm administered doses against the prescription and product concentration. This will finalize the visit and move it to For Payment.',
            cancelLabel: 'Cancel', confirmLabel: 'Send to Payment'});
          if (!confirmed) return;
        }
        const numberOrNull = (value) => value === "" || value == null ? null : Number(value);
        const payload = {
          ...narratives,
          primary_veterinarian_id: this.form.primary_veterinarian_id || null,
          additional_veterinarian_ids: this.form.additional_veterinarian_ids,
          follow_up_date:       this.form.follow_up_date        || null,
          weight_kg:            String(this.form.weight_kg ?? "").trim() || null,
          temperature_c:        String(this.form.temperature_c ?? '').trim() || null,
          heart_rate_bpm:       String(this.form.heart_rate_bpm ?? '').trim() || null,
          respiratory_rate_bpm: String(this.form.respiratory_rate_bpm ?? '').trim() || null,
          ...(this.isVeterinarian && Object.keys(this.vitalExceptions).length ? {vital_exceptions: this.vitalExceptions} : {}),
          body_condition_score: numberOrNull(this.form.body_condition_score),
          finish_case:          finishCase,
        };
        const response = await API.clinicSaveRecord(this.apptId, payload);
        if (forDischarge) {
          const {weight_kg, temperature_c, heart_rate_bpm, respiratory_rate_bpm, body_condition_score} = payload;
          this.currentAppt = {...this.currentAppt, record: response.record,
            vitals: {...this.currentAppt.vitals, weight_kg, temperature_c, heart_rate_bpm, respiratory_rate_bpm, body_condition_score}};
          this.recordedByName = response.record.recorded_by_name;
          return;
        }
        this.open = false;
        this.returnFocus?.focus();
        window.dispatchEvent(new CustomEvent('clinic-record-closed'));
        window.dispatchEvent(new CustomEvent("clinic-case-updated"));
      } catch (e) {
        if (forDischarge) throw e;
        if (Object.keys(e.errors || {}).some(key => key.startsWith('treatments.'))) {
          this.showTreatmentValidation(e.errors, this.syncTreatmentAppointment());
        } else if (e.errors) this.showValidationErrors(e.errors);
        else this.modalError = e.message || "Unable to save the record. Try again.";
      } finally {
        this.saving = false;
      }
    },
    showTreatmentValidation(errors, recordsReady) {
      this.activeSection = 'vaccinations';
      const message = Object.values(errors).flat().join(' ');
      this.modalError = message;
      this.treatmentValidationError = true;
      window.dispatchEvent(new CustomEvent('clinic-treatment-focus', {detail: {id: this.apptId, message, errors, recordsReady}}));
    },
  };
}

// ── Clinic Records Page ───────────────────────────────────────────────────────

function adminClinicPage() {
  return {
    // Search state
    searchQuery: "",
    searchRows: [],
    noResults: false,
    searching: true,
    recordsLoaded: false,
    recordsError: '',
    _timer: null,
    _reqId: 0,
    recordsPage: 1,
    hasMoreRecords: false,
    activeCases: [],
    arrivals: [],
    activeCasesLoading: true,
    activeCasesLoaded: false,
    activeCasesError: '',
    _activeCaseRequest: 0,
    clinicToday: '',
    confinedCases: [], confinedCount: 0, confinedLoading: false, confinedLoaded: false, confinedError: '', confinedPage: 1, confinedLastPage: 1,
    _confinedRequest: 0,
    caseBusyId: null,
    accessError: null,
    payment: { open: false, case: null, method: "cash", amountPaid: "", touched: false, saving: false, error: "", recorded: false },
    receiptModal: null,
    paymentReturnFocus: null,
    paymentPageOverflow: null,
    tab: "active-cases",

    // Patient profile panel
    profile: {
      open: false,
      loading: false,
      error: null,
      pet: null,
      owner: null,
      records: [],
    },
    _profileRequest: 0,
    historyTab: 'medical',
    treatmentTypeFilter: 'all',
    historyTabs: [{id: 'medical', label: 'Medical Records'}, {id: 'treatment', label: 'Treatment'}, {id: 'services', label: 'Services'}, {id: 'confinement', label: 'Confinement'}],
    histories: {},
    profileReturnFocus: null,
    profilePageOverflow: null,
    profileScrollPosition: 0,
    profileRecordTrigger: null,
    profileVisit: null,
    profileVisitLoaded: false,
    profileBusy: false,
    profileActionError: '',
    profileEditing: false,
    profileEditorLoading: false,
    profileEditorSaving: false,

    // Owner's pet list (opened by View)
    petPicker: { open: false, owner: null, pets: [] },
    petPickerReturnFocus: null,

    // Read-only consultation detail
    detail: { record: null },
    _caseDetails: {},
    _caseObservations: {},
    detailAttachmentBusyId: null,
    detailAttachmentError: "",

    async init() {
      const token = API.getAdminToken?.();
      const role  = API.getUserRole?.();
      if (!token || (role !== "admin" && role !== "staff")) {
        API.redirectToSignIn?.();
        return;
      }
      try {
        const response = await API.getStaffIdentity();
        if (!response.user?.clinic_permissions?.access) {
          this.accessError = "Your staff role does not have access to Clinic.";
          return;
        }
      } catch (error) { this.accessError = error.message || "Could not verify Clinic access."; return; }
      const requestedTab = new URLSearchParams(window.location.search).get("tab");
      this.tab = this.allowedTabs.includes(requestedTab) ? requestedTab : this.defaultTab;
      if (this.tab === 'confined') this.loadConfinedCases();
      await this.loadActiveCases();
      this.deferPatientRecords();
      await this.restoreOnlinePayment();
      this._caseUpdatedListener = () => {
        this.loadActiveCases();
        if (this.profile.open) this.loadProfileVisit();
        if (this.tab === 'confined') this.loadConfinedCases();
      };
      window.addEventListener("clinic-case-updated", this._caseUpdatedListener);
      this._reviewPaymentListener = event => this.reviewPayment(event.detail);
      window.addEventListener("clinic-review-payment", this._reviewPaymentListener);
      this._refreshCases = () => {
        if (document.visibilityState === 'hidden' || API.isSessionLocked?.()) return;
        void this.loadActiveCases();
        if (this.tab === 'confined') void this.loadConfinedCases(this.confinedPage);
      };
      this._caseInterval = setInterval(this._refreshCases, 30000);
      document.addEventListener('visibilitychange', this._refreshCases);
      window.addEventListener('admin-session:unlocked', this._refreshCases);
    },

    deferPatientRecords() {
      const load = () => {
        this._recordsObserver?.disconnect();
        if (!this.recordsLoaded && !this.searchQuery.trim()) void this.loadAllRecords();
      };
      const section = this.$root?.querySelector('#patient-records-heading');
      if (!section || typeof IntersectionObserver === 'undefined') {
        this._recordsFrame = requestAnimationFrame(() => { this._recordsFrame = requestAnimationFrame(load); });
        return;
      }
      this._recordsObserver = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) load();
      }, { rootMargin: '200px' });
      this._recordsObserver.observe(section);
    },

    destroy() {
      this._activeCaseRequest++; this._reqId++; this._confinedRequest++;
      this._recordsObserver?.disconnect();
      if (this._recordsFrame) cancelAnimationFrame(this._recordsFrame);
      clearInterval(this._caseInterval);
      clearTimeout(this._timer);
      document.removeEventListener('visibilitychange', this._refreshCases);
      window.removeEventListener('admin-session:unlocked', this._refreshCases);
      window.removeEventListener('clinic-case-updated', this._caseUpdatedListener);
      window.removeEventListener('clinic-review-payment', this._reviewPaymentListener);
      this._clinicPaymentWatch?.stop();
    },

    async loadActiveCases({ force = false } = {}) {
      const request = ++this._activeCaseRequest;
      this.activeCasesLoading = true;
      this.activeCasesError = '';
      try {
        await API.loadAdminData("/admin/clinic-cases", async () => {
          const requestedAt = Date.now();
          const response = await API.getActiveClinicCases({ force });
          if (request === this._activeCaseRequest) {
            window.dispatchEvent(new CustomEvent("admin-clinic:activity-applied", { detail: { activeClinicCount: response.activeClinicCount, requestedAt } }));
          }
          return response;
        }, response => {
          if (request !== this._activeCaseRequest) return;
          this.activeCases = response.cases || [];
          this.arrivals = response.arrivals || [];
          this.clinicToday = response.today || '';
          this.confinedCount = response.confined_count || 0;
          this.activeCasesLoaded = true;
          window.dispatchEvent(new CustomEvent("clinic-cases-loaded", { detail: this.activeCases }));
        }, { force });
        return true;
      }
      catch { if (request === this._activeCaseRequest) this.activeCasesError = 'Could not load Clinic cases.'; return false; }
      finally { if (request === this._activeCaseRequest) this.activeCasesLoading = false; }
    },

    get visibleActiveCases() {
      return this.tab === 'for-payment' ? this.activeCases.filter(item => item.status === 'for_payment') : this.currentActiveCases;
    },
    get currentActiveCases() { return this.activeCases.filter(item => ['checked_in', 'in_consultation'].includes(item.status) && !(item.confinement && !item.confinement.discharged_at)); },
    get activeCount() { return this.currentActiveCases.length; },
    get waitingCases() { return this.arrivals.filter(item => item.status === 'waiting_to_arrive'); },
    get expectedCount() { return this.waitingCases.length; },
    get caseRows() { return this.tab === 'expected' ? this.waitingCases : this.visibleActiveCases; },
    get forPaymentCount() { return this.activeCases.filter(item => item.status === 'for_payment').length; },
    get defaultTab() { return this.permissions.clinical ? 'active-cases' : 'expected'; },
    get allowedTabs() { return ['expected', 'active-cases', ...(this.permissions.clinical ? ['confined'] : []), 'for-payment']; },
    async loadConfinedCases(page = 1) {
      if (!this.permissions.clinical) return;
      const request = ++this._confinedRequest;
      this.confinedLoading = true; this.confinedError = '';
      try {
        const response = await API.getClinicConfinedCases(page);
        if (request !== this._confinedRequest) return;
        this.confinedCases = response.data; this.confinedCount = response.total;
        this.confinedLoaded = true;
        this.confinedPage = response.current_page; this.confinedLastPage = response.last_page;
      } catch (error) { if (request === this._confinedRequest) this.confinedError = error.message || 'Could not load confined patients.'; }
      finally { if (request === this._confinedRequest) this.confinedLoading = false; }
    },
    confinementDuration(caseItem) {
      const episode = caseItem?.confinement;
      return clinicConfinementDuration(episode?.admitted_at, episode?.discharged_at || this.$store.clinicConfinementClock?.now || Date.now());
    },
    setTab(tab) {
      if (!this.allowedTabs.includes(tab)) return;
      this.tab = tab;
      const url = new URL(window.location.href);
      if (tab === "active-cases") url.searchParams.set("tab", "active-cases");
      else if (tab === 'expected') url.searchParams.set('tab', 'expected');
      else if (tab === 'for-payment') url.searchParams.set('tab', 'for-payment');
      else if (tab === 'confined') { url.searchParams.set('tab', 'confined'); this.loadConfinedCases(); }
      else url.searchParams.delete("tab");
      window.history.replaceState(null, "", url.pathname + url.search + url.hash);
      window.scrollTo(0, 0);
    },

    caseLabel(item) { return ({ online_request: "Online Queue Registration", consultation: "Consultation", vaccination: "Vaccination" })[item.case_type] || "Clinic case"; },
    caseStatus(item) { return ({ waiting_to_arrive: "Waiting to Arrive", checked_in: "Checked In", in_consultation: "In Consultation", for_payment: "For Payment" })[item.status] || item.status; },
    casePatientLabel(item) {
      return (item.status !== 'waiting_to_arrive' && item.queue_number ? `#${item.queue_number} · ` : '') + (item.pet?.name || 'Patient');
    },
    caseVisitDay(value) {
      if (!value) return "Not recorded";
      const date = new Date(String(value).slice(0, 10) + "T00:00:00+08:00");
      return Number.isNaN(date.getTime()) ? "Not recorded" : new Intl.DateTimeFormat("en-PH", {
        timeZone: "Asia/Manila", year: "numeric", month: "short", day: "numeric",
      }).format(date);
    },
    caseTimestamp(value) {
      if (!value) return "Not recorded";
      const date = new Date(value);
      return Number.isNaN(date.getTime()) ? "Not recorded" : new Intl.DateTimeFormat("en-PH", {
        timeZone: "Asia/Manila", year: "numeric", month: "short", day: "numeric", hour: "numeric", minute: "2-digit",
      }).format(date);
    },
    caseStartedLabel(item) {
      const date = item.started_on || item.appointment_date;
      if (!['checked_in', 'in_consultation'].includes(item.status) || !date || !this.clinicToday || date >= this.clinicToday) return '';
      return 'Checked in ' + new Date(date + 'T00:00:00').toLocaleDateString('en-PH', {
        month: 'short', day: 'numeric', ...(date.slice(0, 4) !== this.clinicToday.slice(0, 4) ? {year: 'numeric'} : {}),
      });
    },
    caseActionLabel(item) {
      if (this.canCheckInArrival(item)) return 'Check In';
      if (item.status === 'checked_in' && this.permissions.clinical) return 'Start Consultation';
      if (item.status === 'for_payment' && this.permissions.payment) return 'Review & Collect Payment';
      if (['checked_in', 'in_consultation', 'for_payment'].includes(item.status) && this.permissions.clinical) return 'Open Case';
      return '';
    },
    runCaseAction(item) {
      if (this.caseBusyId !== null) return;
      if (this.canCheckInArrival(item)) return this.checkInCase(item);
      if (item.status === 'checked_in' && this.permissions.clinical) return this.startCase(item);
      if (item.status === 'for_payment' && this.permissions.payment) return this.reviewPayment(item);
      if (['checked_in', 'in_consultation', 'for_payment'].includes(item.status) && this.permissions.clinical) this.openCase(item);
    },
    get permissions() { return this.$store.clinicAccess.permissions; },
    get canEditPatient() { return !!this.permissions.clinical; },
    canCheckInArrival(item) {
      return this.permissions.intake && item?.status === 'waiting_to_arrive'
        && (!item.appointment_date || !this.clinicToday || item.appointment_date === this.clinicToday);
    },
    money(value) { return Number(value || 0).toLocaleString("en-PH", { style: "currency", currency: "PHP" }); },
    async checkInCase(item) {
      if (!this.canCheckInArrival(item) || this.caseBusyId) return;
      this.caseBusyId = item.id;
      try {
        const response = await API.clinicCheckIn(item.id);
        this.applyCheckedInCase(response.appointment);
        if (String(item.pet?.id) === String(this.profile.pet?.id)) this.profileVisit = response.appointment;
        await this.loadActiveCases();
      }
      catch (error) { this.$store.clinicFeedback.notify(error.message || "Could not check in this patient.", "error"); }
      finally { this.caseBusyId = null; }
    },
    applyCheckedInCase(appointment) {
      this.arrivals = this.arrivals.filter(item => item.id !== appointment.id);
      this.activeCases = [...this.activeCases.filter(item => item.id !== appointment.id), appointment];
    },
    async reviewPayment(item, checkOnline = true) {
      if (!this.permissions.payment || item.status !== "for_payment" || this.payment.open) return;
      this.paymentReturnFocus = document.activeElement;
      this.paymentPageOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      this.receiptModal = null;
      this.payment = { online: null, verifying: false, open: true, case: item, method: "cash", amountPaid: "", touched: false, saving: false, error: "", recorded: false };
      this.$nextTick?.(() => document.getElementById('clinic-cash-received')?.focus());
      if (checkOnline || item.list_only) {
        this.payment.saving = true;
        try {
          if (item.list_only) this.payment.case = (await API.getClinicCase(item.id)).case;
          if (checkOnline) await this.applyClinicOnlineStatus(await API.clinicOnlinePaymentStatus(item.id));
        }
        catch (error) { this.payment.error = error.message || 'Could not check payment.'; }
        finally { this.payment.saving = false; }
      }
    },
    get paymentTotalDue() { return Number(this.payment.case?.total_amount); },
    get paymentCashError() { return window.CashPayment.error(this.payment.amountPaid, this.paymentTotalDue); },
    get paymentMaximumAmount() { return Math.min(999999, window.CashPayment.maximumFor(this.paymentTotalDue)); },
    get paymentChange() { return window.CashPayment.change(this.payment.amountPaid, this.paymentTotalDue); },
    get canConfirmPayment() {
      return this.permissions.payment && this.payment.open && !this.payment.recorded && !this.payment.saving
        && (!this.payment.case?.status || this.payment.case.status === 'for_payment')
        && !this.payment.online && Number.isFinite(this.paymentTotalDue) && this.paymentTotalDue >= 0
        && (this.payment.method === 'online' ? this.paymentTotalDue > 0 : !this.paymentCashError);
    },
    updatePaymentCash() {
      this.payment.amountPaid = window.CashPayment.capInput(this.payment.amountPaid, this.paymentTotalDue);
      this.payment.error = '';
    },
    paymentChargeQuantity(charge) {
      return charge.quantity == null ? '—' : Number(charge.quantity).toLocaleString('en-PH', {maximumFractionDigits: 2});
    },
    paymentChargeDetail(charge) {
      return charge.quantity != null && charge.unit_price != null ? `${this.paymentChargeQuantity(charge)} × ${this.money(charge.unit_price)}` : '';
    },
    formatClinicPaidAt(value) {
      if (!value) return '—';
      const date = new Date(value);
      return Number.isNaN(date.getTime()) ? value : date.toLocaleString('en-PH', {dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila'});
    },
    formatInvoicePeso(value) { return window.PaymentInvoice.formatPeso(value); },
    formatInvoiceDate(value) { return window.PaymentInvoice.formatDate(value); },
    printClinicInvoice() {
      if (this.payment.recorded && this.receiptModal) window.PaymentInvoice.print('clinicPaymentInvoice');
    },
    trapPaymentFocus(event) {
      const controls = [...event.currentTarget.querySelectorAll('button, input, select, [tabindex="0"]')]
        .filter(element => !element.disabled && element.getClientRects().length);
      const first = controls[0], last = controls.at(-1);
      if (event.shiftKey && (document.activeElement === first || !controls.includes(document.activeElement))) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    },
    closePayment() {
      if (this.payment.saving) return;
      this._clinicPaymentWatch?.stop(); this._clinicPaymentWatch = null;
      this.payment.open = false;
      document.body.style.overflow = this.paymentPageOverflow ?? '';
      this.$nextTick?.(() => {
        if (this.paymentReturnFocus?.isConnected && this.paymentReturnFocus.getClientRects().length) this.paymentReturnFocus.focus();
        else document.getElementById('clinic-for-payment-filter')?.focus();
      });
    },
    async recordPayment() {
      this.payment.touched = true;
      if (!this.canConfirmPayment) return;
      this.payment.saving = true; this.payment.error = "";
      try {
        if (this.payment.method === 'online') {
          window.OnlinePayment.remember('clinic', {}, this.payment.case.id);
          const response = await API.createClinicOnlinePayment(this.payment.case.id);
          await this.applyClinicOnlineStatus(response);
          if (response.online_payment?.status === 'pending') this.openClinicCheckout();
          return;
        }
        const response = await API.clinicRecordPayment(this.payment.case.id, {
          total_amount: this.payment.case.total_amount, payment_method: 'cash', amount_paid: Number(this.payment.amountPaid),
        });
        this.completeClinicPayment(response.appointment);
      } catch (error) {
        if (this.payment.method === 'online') {
          try { await this.applyClinicOnlineStatus(await API.clinicOnlinePaymentStatus(this.payment.case.id)); }
          catch { /* Preserve the original create error. */ }
        }
        this.payment.error = Object.values(error.errors || {}).flat().join(' ') || error.message || "Could not record payment.";
        if (error.errors?.total_amount) {
          await this.loadActiveCases({ force: true });
          const current = this.activeCases.find((item) => item.id === this.payment.case.id);
          if (current) this.payment.case = current;
        }
      }
      finally { this.payment.saving = false; }
    },
    completeClinicPayment(recorded) {
      this._clinicPaymentWatch?.stop();
      window.OnlinePayment?.clear('clinic');
      this.receiptModal = {
        isTest: recorded.is_test === true,
        providerPaymentId: window.PaymentInvoice.paymentId(recorded.provider_payment_id, recorded.payment_method),
        caseReference: recorded.appointment_reference,
        ownerName: recorded.ownerName,
        contactNumber: recorded.contactNumber === '—' ? '' : recorded.contactNumber,
        patientName: recorded.pet?.name || 'Patient',
        patientInfo: [recorded.pet?.species, recorded.pet?.breed, recorded.pet?.gender].filter(Boolean).join(' · '),
        charges: recorded.charges || [],
        finalPrice: Number(recorded.total_amount),
        paymentMethod: recorded.payment_method_label || (recorded.payment_method === 'cash' ? 'Cash' : recorded.payment_method),
        amountPaid: recorded.cash_received,
        change: recorded.change,
        paidAt: recorded.paid_at,
        processedBy: recorded.processed_by?.name || '—',
      };
      this.payment.recorded = true;
      this.$nextTick?.(() => {
        document.getElementById('clinic-payment-recorded-title')?.focus();
      });
      window.dispatchEvent(new CustomEvent('clinic-case-patch', {detail: {id: recorded.id, paid: recorded.paid, status: recorded.status}}));
      this.activeCases = this.activeCases.filter(item => item.id !== recorded.id);
      if (this.profileVisit?.id === recorded.id) this.profileVisit = null;
      window.dispatchEvent(new CustomEvent("clinic-case-updated"));
    },
    openClinicCheckout() {
      window.OnlinePayment.open('clinic', this.payment.online, this.payment.case.id);
    },
    async applyClinicOnlineStatus(response) {
      const attempt = response.online_payment;
      if (response.appointment) this.payment.case = {...this.payment.case, ...response.appointment};
      if (attempt?.status === 'paid') {
        this.payment.online = attempt;
        if (attempt.reconciliation_reason) { this.payment.error = 'Payment received. Contact an administrator to reconcile this case.'; return; }
        this.completeClinicPayment({...this.payment.case, ...response.appointment});
      } else if (attempt?.status === 'pending') {
        this.payment.online = attempt; this.payment.method = 'online';
        window.OnlinePayment.remember('clinic', attempt, this.payment.case.id);
        this.payment.error = attempt.reconciliation_reason ? 'Checkout creation needs reconciliation. Contact an administrator.' : '';
        if (!this._clinicPaymentWatch) {
          this._clinicPaymentWatch = window.OnlinePayment.watch({
            load: () => API.clinicOnlinePaymentStatus(this.payment.case.id, this.payment.online?.id),
            apply: result => this.applyClinicOnlineStatus(result),
            error: error => {this.payment.error = error.message || 'Could not check payment. Retrying.';},
            active: () => this.payment.open && this.payment.online?.status === 'pending',
            exhausted: () => {this.payment.error = 'Payment is still unconfirmed. Check Status to continue.';},
          });
          // Start via the watcher once so status requests never overlap.
          this._clinicPaymentWatch.start();
        }
      } else {
        this._clinicPaymentWatch?.stop(); this._clinicPaymentWatch = null;
        this.payment.online = null; this.payment.verifying = false;
        window.OnlinePayment?.clear('clinic');
        if (attempt) this.payment.error = ({failed: 'Checkout creation failed. Try again.', cancelled: 'Online payment cancelled.', expired: 'Checkout expired. Choose a payment method to retry.'})[attempt.status] || '';
      }
    },
    async restoreOnlinePayment() {
      const saved = window.OnlinePayment?.get('clinic');
      if (!saved?.payable || !this.permissions.payment) return;
      this.tab = 'for-payment';
      const item = this.activeCases.find(item => String(item.id) === String(saved.payable)) || {id: saved.payable, status: 'for_payment'};
      await this.reviewPayment(item, false);
      this.payment.saving = true; this.payment.verifying = saved.outcome === 'success';
      try {
        const response = await API.clinicOnlinePaymentStatus(saved.payable, saved.id);
        this.payment.case = {...item, ...response.appointment};
        await this.applyClinicOnlineStatus(response);
        if (saved.outcome === 'cancel' && this.payment.online?.status === 'pending') await this.cancelClinicOnlinePayment();
      } catch (error) { this.payment.error = error.message || 'Could not restore payment. Reopen this payment to retry.'; }
      finally {this.payment.saving = false;}
    },
    async checkClinicPaymentStatus() {
      if (this.payment.saving) return;
      this._clinicPaymentWatch?.stop(); this._clinicPaymentWatch = null;
      await this.applyClinicOnlineStatus({online_payment: this.payment.online});
      await this._clinicPaymentWatch?.check();
    },
    async cancelClinicOnlinePayment() {
      if (this._clinicCancelling) return;
      this._clinicCancelling = true; this.payment.saving = true; this.payment.error = '';
      this._clinicPaymentWatch?.stop(); this._clinicPaymentWatch = null;
      try { await this.applyClinicOnlineStatus(await API.cancelClinicOnlinePayment(this.payment.case.id)); }
      catch (error) { this.payment.error = error.message || 'Online payment is still pending.'; }
      finally {
        this._clinicCancelling = false; this.payment.saving = false;
        if (this.payment.open && this.payment.online?.status === 'pending') await this.applyClinicOnlineStatus({online_payment: this.payment.online});
      }
    },
    canCancelCase(item) {
      return !!this.permissions.queue && !['completed', 'cancelled'].includes(item.status)
        && (this.permissions.clinical || item.status === 'checked_in')
        && !(item.confinement && !item.confinement.discharged_at);
    },
    async cancelCase(item) {
      if (!this.canCancelCase(item) || this.caseBusyId !== null) return;
      const confirmed = await this.$store.clinicFeedback.confirm({
        title: "Cancel this case?",
        message: `The case for ${item.pet?.name || "this patient"} will be cancelled.`,
        confirmLabel: "Cancel Case",
        cancelLabel: "Keep Case",
        danger: true,
      });
      if (!confirmed) return;
      this.caseBusyId = item.id;
      try {
        await API.clinicCancel(item.id);
      }
      catch (error) { this.$store.clinicFeedback.notify(error.message || "Could not cancel this case.", "error"); }
      finally {
        await this.loadActiveCases({force: true});
        if (this.profileVisit?.id === item.id) await this.loadProfileVisit();
        this.caseBusyId = null;
      }
    },
    async openCase(item, section) {
      if (!this.permissions.clinical || !['checked_in', 'in_consultation', 'for_payment'].includes(item.status)) return;
      if (this.caseBusyId !== null) return;
      this.caseBusyId = item.id;
      try {
        if (item.list_only) item = (await API.getClinicCase(item.id)).case;
        window.dispatchEvent(new CustomEvent("clinic-open-modal", { detail: { appt: item, section: section || (item.case_type === "vaccination" ? "vaccinations" : "medical") } }));
      } catch (error) { this.$store.clinicFeedback.notify(error.message || 'Could not open this case.', 'error'); }
      finally { this.caseBusyId = null; }
    },
    async startCase(item) {
      if (!this.permissions.clinical || this.caseBusyId || item.status !== 'checked_in') return;
      this.caseBusyId = item.id;
      try {
        const response = await API.startClinicCase(item.id);
        if (String(item.pet?.id) === String(this.profile.pet?.id)) this.profileVisit = response.case;
        await this.loadActiveCases();
        this.caseBusyId = null;
        if (this.profile.open) this.openProfileCase(response.case);
        else this.openCase(response.case);
      }
      catch (error) { this.$store.clinicFeedback.notify(error.message || "Could not start this case.", "error"); }
      finally { this.caseBusyId = null; }
    },
    startWalkIn() {
      if (!this.permissions.intake) return;
      window.location.href = "/admin/walk-in/customer?flow=clinic&new=1";
    },

    async loadAllRecords(append = false) {
      const rid = ++this._reqId;
      this.searching = true;
      this.noResults = false;
      this.recordsError = '';
      const page = append ? this.recordsPage + 1 : 1;
      try {
        const render = res => {
          if (rid !== this._reqId) return;
          this.recordsPage = page;
          this.hasMoreRecords = !!res.has_more;
          this.searchRows = append ? this.searchRows.concat(res.rows || []) : (res.rows || []);
          this.recordsLoaded = true;
          this.noResults = this.searchRows.length === 0;
        };
        if (append) render(await API.getClinicRecords(page));
        else await API.loadAdminData("/admin/clinic-records?page=1", () => API.getClinicRecords(1), render);
      } catch {
        if (rid !== this._reqId) return;
        this.recordsError = 'Could not load patient records.';
        this.noResults = false;
      } finally {
        if (rid === this._reqId) {
          this.searching = false;
        }
      }
    },

    _buildRows(res) {
      const rows = [];
      const rowsByOwner = new Map();
      const seenPetIds = new Set();

      for (const c of (res.customers || [])) {
        const pets = (c.pets || []).filter(p => !p.isArchived);
        pets.forEach(p => seenPetIds.add(String(p.id)));
        const row = { owner: c, pets };
        rowsByOwner.set(`${c.recordType}:${c.id}`, row);
        rows.push(row);
      }
      for (const p of (res.pets || [])) {
        if (seenPetIds.has(String(p.id))) continue;
        const key = `${p.ownerRecordType}:${p.ownerId}`;
        let row = rowsByOwner.get(key);
        if (!row) {
          row = {
            owner: {
              id: p.ownerId,
              recordType: p.ownerRecordType,
              fullName: p.ownerName,
              phone: null,
              email: null,
            },
            pets: [],
          };
          rowsByOwner.set(key, row);
          rows.push(row);
        }
        row.pets.push(p);
      }

      this.searchRows = rows;
      this.recordsLoaded = true;
      this.hasMoreRecords = false;
      this.noResults  = rows.length === 0;
    },

    async onSearchInput() {
      this.recordsLoaded = false;
      this.recordsError = "";
      const q = this.searchQuery.trim();
      clearTimeout(this._timer);
      const rid = ++this._reqId;
      if (q.length === 0) {
        await this.loadAllRecords();
        return;
      }
      if (q.length < 2) {
        this.searchRows = [];
        this.noResults  = false;
        this.searching  = false;
        return;
      }
      this._timer = setTimeout(async () => {
        this.searching = true;
        try {
          const res = await API.searchWalkInCustomers(q);
          if (rid !== this._reqId) return;
          this._buildRows(res);
        } catch {
          if (rid !== this._reqId) return;
          this.recordsError = "Could not load patient records.";
          this.noResults  = false;
        } finally {
          if (rid === this._reqId) this.searching = false;
        }
      }, 350);
    },

    clearSearch() {
      clearTimeout(this._timer);
      this._reqId++;
      this.searchQuery = "";
      this.searchRows  = [];
      this.recordsLoaded = false;
      this.recordsError = "";
      this.noResults   = false;
      this.searching   = false;
      this.loadAllRecords();
    },

    openCaseProfile(item, trigger) {
      if (this.caseBusyId !== null) return;
      return this.openProfile({pet: item.pet, owner: {...item.owner,
        fullName: item.ownerName, phone: item.contactNumber}}, trigger);
    },

    async openProfile(row, trigger = document.activeElement) {
      if (!row.pet?.id) return;
      const request = ++this._profileRequest;
      if (!this.profile.open) {
        this.profileReturnFocus = trigger;
        this.profilePageOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
      }
      this.detail = { record: null };
      this._caseDetails = {};
      this._caseObservations = {};
      this.profileVisit = null;
      this.profileVisitLoaded = false;
      this.profileActionError = '';
      this.profileEditing = false;
      this.historyTab = 'medical';
      this.treatmentTypeFilter = 'all';
      this.histories = Object.fromEntries(['treatment', 'services', 'confinement'].map(tab =>
        [tab, {rows: [], loaded: false, loading: false, error: '', page: 0, lastPage: 1}]));
      this.profile = {
        open: true,
        loading: true,
        error: null,
        pet: row.pet,
        owner: row.owner,
        records: [],
      };
      this.$nextTick?.(() => {
        if (this.$refs.profileBody) this.$refs.profileBody.scrollTop = 0;
        this.$refs.profileClose?.focus();
      });
      try {
        const [data, ownerData, currentCase] = await Promise.all([
          API.adminGetPetProfile(row.pet.id),
          !row.owner?.id ? Promise.resolve({customer: {pets: [row.pet]}}) : row.owner.recordType === 'unregistered'
            ? API.getUnregisteredCustomerDetails(row.owner.id)
            : API.getCustomerDetails(row.owner.id),
          API.getCurrentPetClinicCase(row.pet.id),
        ]);
        if (request !== this._profileRequest) return;
        const pet = ownerData.customer?.pets?.find(pet => String(pet.id) === String(row.pet.id));
        if (!pet) throw new Error('Patient information could not be found. Please reopen the profile.');
        this.profile.pet = pet;
        this.profile.owner = { ...row.owner, ...ownerData.customer };
        this.profile.records = data.medical_records || [];
        this.profileVisit = currentCase.case;
        this.profileVisitLoaded = true;
      } catch (err) {
        if (request !== this._profileRequest) return;
        this.profile.error = err.message || "Failed to load pet profile.";
      } finally {
        if (request === this._profileRequest) {
          this.profile.loading = false;
        }
      }
    },

    closeProfile(restoreFocus = true) {
      if (this.profileBusy || this.profileEditorSaving || this.profileEditorLoading) return;
      this._profileRequest++;
      this.profile.open = false;
      this.detail = { record: null };
      if (this.profilePageOverflow !== null) {
        document.body.style.overflow = this.profilePageOverflow;
        this.profilePageOverflow = null;
      }
      if (restoreFocus) this.$nextTick?.(() => this.profileReturnFocus?.focus());
    },

    trapProfileFocus(event) {
      const controls = [...event.currentTarget.querySelectorAll('button, input, select, textarea, a[href], [tabindex="0"]')]
        .filter(element => !element.disabled && element.getClientRects().length);
      const first = controls[0], last = controls.at(-1);
      const active = document.activeElement;
      // Section headings receive programmatic focus but are outside the Tab sequence.
      const atBoundary = event.shiftKey ? active === first : active === last;
      const hasNextControl = controls.some(element => active?.compareDocumentPosition?.(element) & (event.shiftKey ? 2 : 4));
      if (atBoundary || (!controls.includes(active) && !hasNextControl)) {
        event.preventDefault();
        (event.shiftKey ? last : first)?.focus();
      }
    },

    get patientName() { return this.profile.pet?.petName || this.profile.pet?.name || 'Patient'; },
    get patientConditions() { return String(this.profile.pet?.medicalConditions ?? this.profile.pet?.medical_conditions ?? '').trim(); },
    patientInformation() {
      const pet = this.profile.pet || {};
      const label = value => value ? String(value).replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase()) : '—';
      const status = value => value == null ? '—' : (value === true || value === 1 || value === '1' ? 'Yes' : 'No');
      const neutered = pet.isNeutered ?? pet.is_neutered;
      const deceased = pet.isDeceased ?? pet.is_deceased;
      return [
        {label: 'Species', value: label(pet.species)},
        {label: 'Breed', value: pet.breed || '—'},
        {label: 'Gender', value: label(pet.gender)},
        {label: 'Birthdate / Age', value: pet.birthdate ? `${this.fmtDate(pet.birthdate)} · ${this.petAge(pet)}` : '—'},
        {label: 'Weight', value: pet.weight != null && pet.weight !== '' ? `${pet.weight} kg` : '—'},
        {label: 'Size', value: label(pet.size), bodyCondition: pet.bodyCondition, bodyConditionVerified: pet.bodyConditionVerified, sizeVerified: pet.sizeVerified},
        {label: 'Color', value: pet.color || '—'},
        {label: 'Fur Type', value: label(pet.furType ?? pet.fur_type)},
        {label: 'Neutered / Spayed', value: status(neutered)},
        ...(status(neutered) === 'Yes' ? [{label: 'Neutered Date', value: this.fmtDate(pet.neuteredDate ?? pet.neutered_date)}] : []),
        {label: 'Deceased', value: status(deceased)},
        ...(status(deceased) === 'Yes' ? [{label: 'Deceased Date', value: this.fmtDate(pet.deceasedDate ?? pet.deceased_date)}] : []),
      ];
    },

    openPetPicker(row, trigger = document.activeElement) {
      if (!row.pets?.length) return;
      this.petPickerReturnFocus = trigger?.querySelector?.('button') || trigger;
      this.petPicker = { open: true, owner: row.owner, pets: row.pets };
    },

    closePetPicker(restoreFocus = true) {
      this.petPicker.open = false;
      if (restoreFocus) this.$nextTick?.(() => this.petPickerReturnFocus?.focus());
    },

    get currentClinicVisit() {
      return this.profileVisit;
    },
    async loadProfileVisit() {
      const request = this._profileRequest, petId = this.profile.pet?.id;
      if (!petId) return false;
      try {
        const response = await API.getCurrentPetClinicCase(petId);
        if (request !== this._profileRequest) return false;
        this.profileVisit = response.case || response.arrival || null;
        this.profilePetConflict = response.pet_conflict || null;
        this.profileVisitLoaded = true;
        this.profileActionError = '';
        return true;
      } catch (error) {
        if (request === this._profileRequest) {
          this.profileVisitLoaded = false;
          this.profileActionError = error.message || 'Could not verify the current clinic visit. Please reopen the profile.';
        }
        return false;
      }
    },
    get profileActionLabel() {
      if (!this.profileVisitLoaded || this.profile.loading || this.profile.error || (this.profilePetConflict && !this.profilePetConflict.replaceable)) return '';
      const visit = this.currentClinicVisit;
      if (!visit || visit.status === 'waiting_to_arrive') return this.permissions.intake && (!visit || this.canCheckInArrival(visit)) ? 'Check In Patient' : '';
      if (visit.status === 'for_payment') return this.permissions.clinical ? 'Open Case' : '';
      return this.caseActionLabel(visit);
    },
    get profileStatusLabel() {
      if (this.profilePetConflict) return this.profilePetConflict.replaceable ? 'Pending Grooming registration will be cancelled at check-in.' : 'Resolve the active Grooming visit before intake.';
      return !this.profileActionLabel && this.profileVisitLoaded && this.currentClinicVisit ? this.caseStatus(this.currentClinicVisit) : '';
    },
    runProfileAction() {
      if (!this.profileActionLabel || this.profileBusy || this.caseBusyId !== null || this.profileEditorSaving || this.profileEditorLoading) return;
      const visit = this.currentClinicVisit;
      if (!visit || visit.status === 'waiting_to_arrive') return this.checkInPatient();
      if (visit.status === 'checked_in' && this.permissions.clinical) return this.startCase(visit);
      return this.openProfileCase();
    },
    openProfileCase(visit = this.currentClinicVisit) {
      if (!visit || !['checked_in', 'in_consultation', 'for_payment'].includes(visit.status) || !this.permissions.clinical
        || this.profileBusy || this.profileEditorSaving || this.profileEditorLoading) return;
      const trigger = this.petPicker.open ? this.petPickerReturnFocus : this.profileReturnFocus;
      this.closeProfile(false);
      this.closePetPicker(false);
      this.$nextTick?.(() => { trigger?.focus(); this.openCase(visit); });
    },
    async checkInPatient() {
      if (this.profilePetConflict && !this.profilePetConflict.replaceable) return;
      if (!this.permissions.intake || !this.profile.pet?.id || this.profileBusy || !this.profileVisitLoaded || this.profile.loading || this.profile.error) return;
      if (this.currentClinicVisit?.status !== 'waiting_to_arrive' && this.currentClinicVisit) return;
      if (this.currentClinicVisit && !this.canCheckInArrival(this.currentClinicVisit)) return;
      this.profileBusy = true;
      this.profileActionError = '';
      try {
        if (!await this.loadProfileVisit()) return;
        let visit = this.currentClinicVisit;
        if (visit && visit.status !== 'waiting_to_arrive') return;
        if (visit) {
          visit = (await API.clinicCheckIn(visit.id)).appointment;
        } else {
          try {
            visit = (await API.createClinicCase({ pet_id: this.profile.pet.id, case_type: 'consultation' })).case;
          } catch (error) {
            const existing = error.code === 'pre_registration_exists' ? error.data?.arrival
              : error.code === 'active_case_exists' ? error.data?.case : null;
            if (!existing) throw error;
            this.profileVisit = existing;
            await this.loadActiveCases();
            return;
          }
        }
        this.profileVisit = visit;
        this.applyCheckedInCase(visit);
        await this.loadActiveCases();
      } catch (error) {
        this.profileActionError = error.message || 'Could not check in this patient.';
      } finally { this.profileBusy = false; }
    },
    async editPatient() {
      if (!this.canEditPatient || this.profileEditorLoading || this.profileBusy) return;
      this.profileEditing = true;
      this.profileEditorLoading = true;
      this.profileActionError = '';
      try {
        await loadClinicPatientEditor();
        let panel = this.$refs.patientEditor.firstElementChild;
        if (!panel) {
          panel = document.createElement('div');
          panel.setAttribute('x-data', 'adminClinicPatientEditor()');
          panel.innerHTML = `<p x-show="petModal.saveError" role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" x-text="petModal.saveError"></p>
            <button type="button" @click="cancelEditPet()" :disabled="petModal.saving" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 disabled:opacity-50">Cancel</button>
            <div x-ref="petEditForm"></div>`;
          Alpine.mutateDom(() => { this.$refs.patientEditor.appendChild(panel); Alpine.initTree(panel); });
        }
        const editor = Alpine.$data(panel);
        editor.petModal.pet = this.profile.pet;
        editor.petModal.creating = false;
        editor.detailModal.customer = this.profile.owner;
        await editor.startEditPet();
        this.$nextTick?.(() => {
          this.$refs.profileBody.scrollTop = 0;
          panel.querySelector('#adminPetName')?.focus();
        });
      } catch (error) { this.profileActionError = error.message || 'Could not open patient editing.'; }
      finally { this.profileEditorLoading = false; }
    },
    cancelPatientEdit() {
      if (this.profileEditorLoading || this.profileEditorSaving) return;
      const panel = this.$refs.patientEditor.firstElementChild;
      if (panel) Alpine.$data(panel).cancelEditPet();
      else this.finishPatientEdit();
    },
    finishPatientEdit(pet) {
      if (pet) {
        this.profile.pet = pet;
        for (const row of this.searchRows) {
          const existing = row.pets?.find(item => String(item.id) === String(pet.id));
          if (existing) Object.assign(existing, pet);
        }
        const existing = this.petPicker.pets?.find(item => String(item.id) === String(pet.id));
        if (existing) Object.assign(existing, pet);
      }
      this.profileEditing = false;
      this.$nextTick?.(() => this.$refs.editPatient?.focus());
    },

    selectHistory(tab) {
      this.historyTab = tab;
      if (tab !== 'medical' && !this.histories[tab].loaded && !this.histories[tab].loading) this.loadHistory(tab);
    },
    historyKeydown(event) {
      const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
      if (!keys.includes(event.key)) return;
      event.preventDefault();
      const index = this.historyTabs.findIndex(tab => tab.id === this.historyTab);
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? 3 : (index + (event.key === 'ArrowRight' ? 1 : 3)) % 4;
      this.selectHistory(this.historyTabs[next].id);
      event.currentTarget.querySelectorAll('[role="tab"]')[next]?.focus();
    },
    async loadHistory(tab, page = 1) {
      const state = this.histories[tab], request = this._profileRequest, petId = this.profile.pet.id;
      if (state.loading) return;
      state.loading = true; state.error = '';
      try {
        const response = tab === 'treatment' ? await API.getAdminPetVaccinations(petId)
          : await API.adminGetPetProfile(petId, tab, page);
        if (request !== this._profileRequest) return;
        const rows = tab === 'treatment' ? response.vaccinations || [] : response.data;
        state.rows = page === 1 ? rows : [...state.rows, ...rows];
        state.page = response.current_page || 1; state.lastPage = response.last_page || 1;
        state.loaded = true;
      } catch (error) { if (request === this._profileRequest) state.error = error.message || 'Could not load patient history.'; }
      finally { if (request === this._profileRequest) { state.loading = false;  } }
    },
    get filteredTreatmentRows() {
      const rows = this.histories.treatment?.rows || [];
      return this.treatmentTypeFilter === 'all' ? rows : rows.filter(record => record.treatment_type === this.treatmentTypeFilter);
    },
    treatmentTypeLabel(record) {
      return record.treatment_type === 'vaccine' ? 'Vaccine' : record.treatment_type === 'medicine' ? 'Medicine' : '—';
    },
    treatmentSummary: record => clinicTreatmentSummary(record),
    treatmentDetails: record => clinicTreatmentDetails(record),
    historyService(record) { return clinicHistoryService(record); },
    historyPrice(value) { return clinicHistoryPrice(value); },
    historyDuration(episode) { return clinicConfinementDuration(episode.admitted_at, episode.discharged_at || this.$store.clinicConfinementClock?.now || Date.now()); },
    historyTime(value) { return clinicHistoryTime(value); },
    historyCaseId(record) { return clinicHistoryCaseId(record); },
    historyCaseReference(record) {
      return record?.appointment_reference || record?.clinic_appointment_reference || record?.appointment?.appointment_reference
        || (this.historyCaseId(record) ? this.profile.records.find(item => String(item.appointment_id) === String(this.historyCaseId(record)))?.appointment_reference : null);
    },
    historyCaseLabel(record) { return this.historyCaseReference(record) || (this.historyCaseId(record) ? 'Case #' + this.historyCaseId(record) : 'Historical record'); },
    historyViewLabel(record, kind) {
      return this.historyCaseId(record) ? `View ${kind} for case ${this.historyCaseLabel(record)}` : 'View historical treatment record';
    },
    get profileDetailTitle() { return this.detail.caseId ? 'Case Details' : 'Treatment Record'; },
    get caseDetailStatus() {
      return ({completed: 'Completed', cancelled: 'Cancelled', no_show: 'No Show', expired: 'Expired'})[this.detail.case?.status]
        || this.caseStatus(this.detail.case || {});
    },
    get profileDetailSubtitle() {
      if (!this.detail.record) return '';
      if (!this.detail.caseId) return this.fmtDate(this.detail.record.administered_date);
      const medical = this.profile.records.find(record => String(record.appointment_id) === String(this.detail.caseId));
      const date = this.detail.case?.appointment_date || medical?.appointment_date;
      return [this.detail.case?.appointment_reference || this.historyCaseLabel(this.detail.record), date ? this.fmtDate(date) : null].filter(Boolean).join(' · ');
    },
    caseTreatmentFields(record) {
      return this.treatmentDetails(record).filter(field => !['Treatment', 'Case', 'Recorded By', 'Administration Date',
        'Published', 'Published By', 'Voided', 'Voided By'].includes(field.label));
    },
    confinementDetailFields(record) {
      return clinicConfinementFields(record, this.detail.medical, this.$store.clinicConfinementClock?.now);
    },
    async loadHistoryObservations(page = 1) {
      const detail = this.detail, request = this._profileRequest, cache = this._caseObservations;
      if (detail.observationsLoading || !detail.confinement) return;
      detail.observationsLoading = true; detail.observationsError = '';
      try {
        const key = `${detail.caseId}:${page}`;
        cache[key] ??= API.getClinicConfinementObservations(detail.caseId, page);
        const response = await cache[key];
        if (request !== this._profileRequest || this.detail !== detail) return;
        detail.observations = page === 1 ? response.data : [...detail.observations, ...response.data];
        detail.page = response.current_page; detail.lastPage = response.last_page;
      } catch (error) {
        delete cache[`${detail.caseId}:${page}`];
        if (request === this._profileRequest && this.detail === detail) detail.observationsError = error.message || 'Could not load monitoring history.';
      }
      finally { if (request === this._profileRequest && this.detail === detail) detail.observationsLoading = false; }
    },

    async viewDetail(record, trigger = document.activeElement, kind = 'medical') {
      this.profileScrollPosition = this.$refs.profileBody?.scrollTop || 0;
      this.profileRecordTrigger = trigger?.querySelector?.('button') || trigger;
      const caseId = this.historyCaseId(record);
      this.detail = { record, kind, caseId, case: null, medical: null, treatments: [], services: [], confinement: null,
        observations: [], observationsLoading: false, observationsError: '', loading: !!caseId, error: '', page: 0, lastPage: 1 };
      this.detailAttachmentError = "";
      this.$nextTick?.(() => {
        if (this.$refs.profileBody) this.$refs.profileBody.scrollTop = 0;
        this.$refs.profileBack?.focus();
      });
      if (caseId) await this.loadCaseDetail();
    },

    async loadCaseDetail() {
      const detail = this.detail, request = this._profileRequest, cache = this._caseDetails;
      detail.loading = true; detail.error = '';
      try {
        cache[detail.caseId] ??= API.adminGetPetCaseDetails(this.profile.pet.id, detail.caseId);
        const data = await cache[detail.caseId];
        if (request !== this._profileRequest || this.detail !== detail) return;
        Object.assign(detail, {case: data.case, medical: data.medical_record, treatments: data.treatments || [],
          services: data.services || [], confinement: data.confinement, loading: false});
        if (detail.confinement) this.loadHistoryObservations();
        this.$nextTick?.(() => {
          this.scrollCaseDetail(detail);
        });
      } catch (error) {
        delete cache[detail.caseId];
        if (request === this._profileRequest && this.detail === detail) {
          detail.error = error.message || 'Could not load case details.';
          detail.loading = false;
        }
      }
    },

    scrollCaseDetail(detail) {
      if (this.detail !== detail) return;
      const body = this.$refs.profileBody;
      const entry = body?.querySelector(`[data-case-entry="${detail.kind}-${Number(detail.record.id)}"]`);
      const section = body?.querySelector(`[data-case-section="${detail.kind}"]`);
      const target = entry || section;
      if (!target) return;
      (target.querySelector?.('h3, h4') || target).focus({preventScroll: true});
      body.scrollTo({top: detail.kind === 'medical' ? 0 : Math.max(0, body.scrollTop + target.getBoundingClientRect().top - body.getBoundingClientRect().top - 20),
        behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
    },

    backToProfile() {
      this.detail = { record: null };
      this.$nextTick?.(() => {
        if (this.$refs.profileBody) this.$refs.profileBody.scrollTop = this.profileScrollPosition;
        this.profileRecordTrigger?.focus({ preventScroll: true });
      });
    },

    formatFileSize(bytes) { return formatClinicFileSize(bytes); },

    async downloadDetailAttachment(attachment) {
      this.detailAttachmentBusyId = attachment.id;
      this.detailAttachmentError = "";
      try {
        await downloadClinicAttachment(this.detail.caseId, attachment);
      } catch (error) {
        this.detailAttachmentError = error.message || "Failed to download attachment.";
      } finally {
        this.detailAttachmentBusyId = null;
      }
    },

    petAge(pet) {
      if (!pet?.birthdate) return null;
      const yrs = Math.floor((Date.now() - new Date(pet.birthdate)) / (365.25 * 24 * 3600 * 1000));
      return yrs <= 0 ? "< 1 yr" : `${yrs} yr${yrs === 1 ? "" : "s"}`;
    },

    petSummary(pet) {
      if (!pet) return "—";
      return [pet.species, pet.breed].filter(Boolean).join(" · ") || "Pet";
    },

    ownerInitials(name) {
      return String(name || "?").trim().split(/\s+/).slice(0, 2).map(w => w[0]).join("").toUpperCase();
    },

    fmtDate(value) { return clinicHistoryDate(value); },
  };
}

let clinicPatientEditorScript;
function loadClinicPatientEditor() {
  return clinicPatientEditorScript ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = '/scripts/components/admin-customers.js?v=customer-actions-20260927&pet-weight=pet-weight-size-20261107-v1&shared-editor=20261014&icons=20261018&admin-perf=20261019&pet-format=20261019&pet-fields=20261030-v1&registration=fcfs-20261027-v1&registration-rollover=20261027-v3&pet-care=20261027-v1&mobile-format=20261110&pet-birthdate=20261103-v1&urls=20261103&pet-profile=20261110-v1&body-condition=20261110-v2&name-policy=20261110&short-notes=20261110';
    script.onload = resolve;
    script.onerror = () => { clinicPatientEditorScript = null; script.remove(); reject(new Error('Could not load patient editing. Please try again.')); };
    document.head.appendChild(script);
  });
}

function adminClinicPatientEditor() {
  const editor = adminCustomers({ clinicalPetEditor: true });
  const save = editor.saveEditPet;
  const cancel = editor.cancelEditPet;
  return Object.assign(editor, {
    init() {},
    async saveEditPet() {
      if (!this.$store.clinicAccess.permissions.clinical) return;
      this.$dispatch('clinic-patient-edit-saving', true);
      try {
        await save.call(this);
        if (!this.petModal.editing && !this.petModal.saveError) this.$dispatch('clinic-patient-edit-finished', {pet: this.petModal.pet});
      } finally { this.$dispatch('clinic-patient-edit-saving', false); }
    },
    cancelEditPet() {
      if (this.petModal.saving) return;
      cancel.call(this);
      this.$dispatch('clinic-patient-edit-finished', {});
    },
  });
}
