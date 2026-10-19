const VACCINATION_ADMINISTRATION_ROUTES = [
  { value: "subcutaneous", label: "Subcutaneous" },
  { value: "intramuscular", label: "Intramuscular" },
  { value: "intranasal", label: "Intranasal" },
  { value: "oral", label: "Oral" },
  { value: "other", label: "Other" },
];

function clinicTreatmentSummary(record) {
  const dose = record?.dose_amount != null && record.dose_amount !== ''
    ? `${Number(record.dose_amount)}${record.dose_unit ? ' ' + record.dose_unit : ''}` : '';
  const route = ({intramuscular: 'IM', subcutaneous: 'SC', intranasal: 'Intranasal', oral: 'Oral', other: 'Other'})[record?.route] || record?.route;
  return [dose, route].filter(Boolean).join(' · ') || '—';
}

function clinicTreatmentDetails(record) {
  if (!record) return [];
  const date = value => value ? new Date(String(value).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-PH', {dateStyle: 'medium'}) : '—';
  return [
    {label: 'Treatment type', value: record.treatment_type === 'medicine' ? 'Medicine' : 'Vaccine'},
    {label: 'Treatment', value: record.treatment_name || record.vaccine_name},
    {label: 'Product', value: record.product_name || record.inventory_item_name},
    {label: 'Source', value: record.inventory_item_id ? 'Bethlehem Inventory' : 'Historical / external'},
    {label: 'Status', value: ({draft: 'Draft', published: 'Published', voided: 'Voided'})[record.state] || record.state},
    {label: 'Manufacturer', value: record.manufacturer},
    {label: 'Batch / Lot', value: record.batch_number},
    {label: 'Expiry', value: date(record.product_expiry_date)},
    {label: 'Quantity', value: record.inventory_quantity == null ? '—' : `${Number(record.inventory_quantity)} ${record.inventory_unit || ''}`.trim()},
    {label: 'Strength / concentration', value: record.strength_concentration},
    {label: 'Dose', value: record.dose_amount == null ? '—' : `${Number(record.dose_amount)} ${record.dose_unit || ''}`.trim()},
    {label: 'Route', value: VACCINATION_ADMINISTRATION_ROUTES.find(route => route.value === record.route)?.label || record.route},
    {label: 'Administration Site', value: record.administration_site},
    {label: 'Administration Date', value: date(record.administered_date)},
    {label: 'Next Due Date', value: date(record.next_due_date)},
    {label: 'Notes', value: record.notes},
    {label: 'Case', value: record.clinic_appointment_reference},
    {label: 'Recorded By', value: record.recorded_by_user_name},
    ...(record.published_at ? [{label: 'Published', value: date(record.published_at)}, {label: 'Published By', value: record.published_by_user_name}] : []),
    ...(record.voided_at ? [{label: 'Voided', value: date(record.voided_at)}, {label: 'Voided By', value: record.voided_by_user_name}, {label: 'Void Reason', value: record.void_reason}] : []),
  ];
}

// Current Inventory use and historical documentation have separate stock behavior.
function vaccinationToday() {
  return clinicLocalDate(new Date());
}

function emptyVaccinationDraftForm() {
  return {
    inventory_item_id: "",
    inventory_quantity: 1,
    request_key: null,
    vaccine_name: "",
    product_name: "",
    manufacturer: "",
    batch_number: "",
    product_expiry_date: "",
    administered_date: vaccinationToday(),
    next_due_date: "",
    dose_amount: "",
    dose_unit: "",
    route: "",
    administration_site: "",
    notes: "",
  };
}

function adminClinicVaccinations() {
  return {
    pet: null,
    appointment: null,
    records: [],
    loading: false,
    error: "",
    petNotFound: false,
    actionBusyId: null,
    inline: false,
    _recordsRequest: 0,
    _detailRequest: 0,
    administrationRoutes: VACCINATION_ADMINISTRATION_ROUTES,

    vaccineOptions: [],
    optionsLoading: false,
    optionsError: "",

    formModal: {
      open: false,
      mode: "create",
      recordId: null,
      saving: false,
      clinicAppointmentId: null,
      unavailableVaccineName: "",
    },
    vaccineQuery: "",
    selectedVaccine: null,
    treatmentType: "vaccine",
    medicineQuery: "", medicineResults: [], medicineLoading: false, medicineError: "",
    selectedMedicine: null,
    _medicineTimer: null, _medicineRequest: 0,
    recordKind: "inventory",
    form: emptyVaccinationDraftForm(),
    formErrors: {},
    formErrorSummary: "",

    detailModal: {
      open: false,
      loading: false,
      error: "",
      recordId: null,
      record: null,
    },

    init() {
      window.addEventListener('clinic-treatment-prepare', event => {
        const {id, finishCase} = event.detail;
        if (id !== this.appointment?.id) return;
        event.detail.promise = finishCase
          ? Promise.resolve(!this.hasUnfinishedTreatment())
          : this.hasUnfinishedTreatment() ? this.saveTreatment(false) : Promise.resolve(true);
      });
      window.addEventListener('clinic-treatment-focus', event => {
        if (event.detail.id !== this.appointment?.id) return;
        this.formErrorSummary = event.detail.message;
        this.$nextTick(() => {
          this.$refs.treatmentForm?.scrollIntoView({block: 'start'});
          this.$refs.treatmentForm?.querySelector('input, button')?.focus();
        });
      });
      window.addEventListener("clinic-vaccinations-open", (event) => {
        this.openForAppointment(event.detail?.appt);
      });
      window.addEventListener('clinic-cases-loaded', (event) => {
        const current = event.detail?.find(item => item.id === this.appointment?.id);
        if (current) this.appointment = {...this.appointment, status: current.status, paid: current.paid,
          ...(!current.list_only ? {weight_kg: current.vitals?.weight_kg, confinement: current.confinement, archived_at: current.archived_at} : {})};
      });
      window.addEventListener('clinic-case-patch', (event) => {
        if (event.detail.id === this.appointment?.id) this.appointment = {...this.appointment, ...event.detail};
      });
      window.addEventListener('clinic-record-closed', () => {
        if (!this.inline) return;
        ++this._recordsRequest;
        this.closeChildDialogs();
        this.appointment = null; this.pet = null; this.records = [];
      });
    },

    openForAppointment(appt) {
      if (!appt?.pet?.id) {
        this.pet = null;
        this.appointment = appt || null;
        this.records = [];
        this.loading = false;
        this.petNotFound = true;
        this.error = "This appointment does not have a patient record that can receive treatment history.";
        return;
      }

      const changedCase = Number(this.appointment?.id) !== Number(appt.id) || Number(this.pet?.id) !== Number(appt.pet.id);
      this.pet = { ...appt.pet };
      this.appointment = {
        id: appt.id,
        appointment_reference: appt.appointment_reference,
        status: appt.status,
        weight_kg: appt.vitals?.weight_kg,
        confinement: appt.confinement,
        paid: appt.paid,
        archived_at: appt.archived_at,
      };
      this.petNotFound = false;
      this.error = "";

      if (changedCase) {
        this.records = [];
        this.closeChildDialogs();
      }

      this.loadVaccinations();
      if (this.canEditClinical) this.loadOptions();
      if (this.inline && (changedCase || !this.formModal.open)) this.openCreateForm();
    },

    closeChildDialogs() {
      ++this._detailRequest;
      this.clearMedicine();
      this.formModal.open = false;
      this.detailModal.open = false;
    },

    async loadVaccinations() {
      if (!this.pet?.id) return;

      const requestedPetId = Number(this.pet.id);
      const requestedCaseId = this.appointment?.id;
      const request = ++this._recordsRequest;
      this.loading = true;
      this.error = "";
      this.petNotFound = false;

      try {
        const response = await API.getAdminPetVaccinations(requestedPetId, requestedCaseId);
        if (request !== this._recordsRequest || this.appointment?.id !== requestedCaseId || Number(this.pet?.id) !== requestedPetId) return;
        this.pet = {
          ...this.pet,
          id: response.pet?.pet_id ?? this.pet.id,
          name: response.pet?.pet_name ?? this.pet.name,
          species: response.pet?.species ?? this.pet.species,
        };
        this.records = Array.isArray(response.vaccinations)
          ? response.vaccinations.filter(record => record.state !== "voided" && (requestedCaseId == null || String(record.clinic_appointment_id) === String(requestedCaseId)))
          : [];
      } catch (error) {
        if (request !== this._recordsRequest || this.appointment?.id !== requestedCaseId || Number(this.pet?.id) !== requestedPetId) return;
        this.records = [];
        this.petNotFound = error.status === 404;
        this.error = this.petNotFound
          ? "The selected pet could not be found."
          : (error.message || "Treatment history could not be loaded.");
      } finally {
        if (request === this._recordsRequest && this.appointment?.id === requestedCaseId && Number(this.pet?.id) === requestedPetId) {
          this.loading = false;
        }
      }
    },

    async loadOptions() {
      if (this.optionsLoading) return;
      this.optionsLoading = true;
      this.optionsError = "";

      try {
        const response = await API.getAdminVaccinationOptions();
        this.vaccineOptions = Array.isArray(response.vaccines) ? response.vaccines : [];
        if (this.formModal.open && this.recordKind === "inventory" && this.form.inventory_item_id) {
          this.restoreVaccineSelection();
        }
      } catch (error) {
        this.optionsError = error.message || "Clinic vaccine inventory could not be loaded.";
      } finally {
        this.optionsLoading = false;
      }
    },

    // New vaccinations can only be added while the linked case is ongoing.
    get canEditClinical() { return !!this.$store.clinicAccess.permissions.clinical; },

    caseOngoing() {
      if (this.appointment?.paid || this.appointment?.archived_at) return false;
      return !this.appointment?.id
        || this.appointment.status === 'in_consultation';
    },

    openCreateForm() {
      if (!this.canEditClinical) return;
      if (!this.pet?.id || !this.caseOngoing()) return;

      this.form = emptyVaccinationDraftForm();
      this.treatmentType = "vaccine";
      this.clearMedicine();
      this.recordKind = "inventory";
      this.formModal = {
        open: true,
        mode: "create",
        recordId: null,
        saving: false,
        clinicAppointmentId: this.appointment?.id ?? null,
        unavailableVaccineName: "",
      };
      this.clearVaccine();
      this.formErrors = {};
      this.formErrorSummary = "";
    },

    openEditForm(record) {
      if (!this.canEditClinical) return;
      if (record?.state !== "draft") return;
      if (this.inline && (!this.caseOngoing() || String(record.clinic_appointment_id) !== String(this.appointment?.id))) return;

      this.form = {
        inventory_quantity: record.inventory_quantity == null ? 1 : Number.isInteger(Number(record.inventory_quantity)) ? Number(record.inventory_quantity) : record.inventory_quantity,
        request_key: record.request_key ?? null,
        inventory_item_id: record.inventory_item_id ? String(record.inventory_item_id) : "",
        vaccine_name: record.vaccine_name || "",
        product_name: record.product_name || "",
        manufacturer: record.manufacturer || "",
        batch_number: record.batch_number || "",
        product_expiry_date: record.product_expiry_date || "",
        administered_date: record.administered_date || "",
        next_due_date: record.next_due_date || "",
        dose_amount: record.dose_amount ?? "",
        dose_unit: record.dose_unit || "",
        route: record.route || "",
        administration_site: record.administration_site || "",
        notes: record.notes || "",
      };
      this.formModal = {
        open: true,
        mode: "edit",
        recordId: record.id,
        saving: false,
        clinicAppointmentId: record.clinic_appointment_id ?? null,
        unavailableVaccineName: "",
      };
      this.treatmentType = record.treatment_type || "vaccine";
      this.clearMedicine();
      this.recordKind = record.inventory_item_id ? "inventory" : "historical";
      this.selectedVaccine = null;
      this.vaccineQuery = "";
      if (!this.optionsLoading && !this.optionsError && this.recordKind === "inventory") this.restoreVaccineSelection();

      this.formErrors = {};
      this.formErrorSummary = "";
      if (this.inline) this.$nextTick(() => { this.$refs.treatmentForm?.scrollIntoView({block: 'start'}); this.$refs.treatmentForm?.querySelector('button')?.focus(); });
    },

    restoreVaccineSelection() {
      if (this.treatmentType === "medicine") {
        this.restoreMedicineSelection();
        return;
      }
      const vaccine = this.vaccineOptions.find(
        (item) => Number(item.item_id) === Number(this.form.inventory_item_id),
      );
      if (vaccine && this.vaccineSelectable(vaccine)) {
        this.selectVaccine(vaccine, true);
      } else {
        this.formModal.unavailableVaccineName = this.form.vaccine_name;
      }
    },

    closeForm() {
      if (this.formModal.saving) return;
      if (this.inline) { this.resetInlineForm(); return; }
      this.formModal.open = false;
      this.clearMedicine();
      this.formErrors = {};
      this.formErrorSummary = "";
    },

    resetInlineForm() {
      this.openCreateForm();
      this.$nextTick(() => {
        if (this.$refs.treatmentPane) this.$refs.treatmentPane.scrollTop = 0;
        this.$refs.treatmentForm?.querySelector('button')?.focus({preventScroll: true});
      });
    },

    filteredVaccines() {
      const query = this.vaccineQuery.trim().toLowerCase();
      if (!query) return [];
      return this.vaccineOptions.filter(
        (item) => String(item.item_name || "").toLowerCase().includes(query),
      );
    },

    get selectedProduct() { return this.treatmentType === "medicine" ? this.selectedMedicine : this.selectedVaccine; },
    get productQuery() { return this.treatmentType === "medicine" ? this.medicineQuery : this.vaccineQuery; },
    set productQuery(value) { if (this.treatmentType === "medicine") this.medicineQuery = value; else this.vaccineQuery = value; },
    get productResults() { return (this.treatmentType === "medicine" ? this.medicineResults : this.filteredVaccines()).slice().sort((a, b) => Number(this.productSelectable(b)) - Number(this.productSelectable(a))); },
    get productLoading() { return this.treatmentType === "medicine" ? this.medicineLoading : this.optionsLoading; },
    get productSearchError() { return this.treatmentType === "medicine" ? this.medicineError : this.optionsError; },
    searchTreatmentProducts() { if (this.treatmentType === "medicine") this.searchMedicines(); },
    selectProduct(item) { if (this.treatmentType === "medicine") this.selectMedicine(item); else this.selectVaccine(item); },
    clearProduct() { this.clearVaccine(); this.clearMedicine(); this.form.inventory_quantity = 1; },

    productSelectable(item) {
      return item?.is_active !== false && Number(item?.available_quantity) >= 1;
    },
    vaccineSelectable(item) { return this.productSelectable(item); },

    inventoryUnitLabel(item, quantity = 1) {
      const unit = String(item?.unit || "unit").toLowerCase();
      if (unit === "pc") return Number(quantity) === 1 ? "piece" : "pieces";
      if (Number(quantity) === 1 || /s$/.test(unit)) return unit;
      return unit === "box" ? "boxes" : `${unit}s`;
    },

    productAvailabilityLabel(item) {
      if (Number(item?.quantity_on_hand) <= 0) return "Out of stock";
      if (item?.stock_status === "expired") return "Expired stock";
      if (item?.stock_status === "legacy_unit") return "Legacy unit · review Inventory";
      if (Number(item?.available_quantity) < 1) return "Unavailable stock";
      const quantity = Number(item.available_quantity);
      const label = `${quantity} ${this.inventoryUnitLabel(item, quantity)} available`;
      return item.stock_status === "low_stock" ? `Low stock · ${label}` : label;
    },
    vaccineAvailabilityLabel(item) { return this.productAvailabilityLabel(item); },

    changeInventoryQuantity(delta) {
      const quantity = Number(this.form.inventory_quantity);
      const next = (Number.isInteger(quantity) ? quantity : 1) + delta;
      if (next >= 1 && next <= Number(this.selectedProduct?.available_quantity)) this.form.inventory_quantity = next;
    },

    selectVaccine(item, restoring = false) {
      if (!this.vaccineSelectable(item)) return;
      this.selectedVaccine = item;
      this.form.inventory_item_id = String(item.item_id);
      if (!restoring) this.form.inventory_quantity = 1;
      this.formModal.unavailableVaccineName = "";
      delete this.formErrors.inventory_item_id;
    },

    clearVaccine() {
      this.selectedVaccine = null;
      this.form.inventory_item_id = "";
      this.vaccineQuery = "";
    },

    changeTreatmentType(type) {
      if (this.formModal.saving || type === this.treatmentType) return;
      this.treatmentType = type;
      this.clearVaccine();
      this.clearMedicine();
      for (const field of ["vaccine_name", "product_name", "manufacturer", "batch_number", "product_expiry_date", "next_due_date", "dose_amount", "dose_unit"]) this.form[field] = "";
      this.form.inventory_quantity = 1;
      this.formErrors = {};
      this.formErrorSummary = "";
    },

    changeSource() {
      this.clearVaccine();
      this.clearMedicine();
      this.form.inventory_quantity = 1;
      if (this.recordKind === "inventory") this.form.administered_date = vaccinationToday();
      this.formErrors = {};
      this.formErrorSummary = "";
    },

    clearMedicine() {
      clearTimeout(this._medicineTimer);
      ++this._medicineRequest;
      this.selectedMedicine = null;
      this.medicineQuery = "";
      this.medicineResults = [];
      this.medicineError = "";
      this.medicineLoading = false;
    },

    get medicineQuote() {
      return this.selectedMedicine ? clinicMedicinePrice(this.selectedMedicine, this.appointment?.id ? this.appointment.weight_kg : this.pet?.weight) : null;
    },
    medicineMoney(value) { return "₱" + Number(value).toFixed(2); },
    medicineRange: clinicPriceRange,

    searchMedicines() {
      const query = this.medicineQuery;
      this.clearMedicine();
      this.medicineQuery = query;
      this.form.inventory_item_id = "";
      if (query.trim().length < 2) return;
      const request = this._medicineRequest;
      this._medicineTimer = setTimeout(() => this.loadMedicineResults(query.trim(), request), 300);
    },

    async loadMedicineResults(query, request, restoreId = null) {
      this.medicineLoading = true;
      try {
        const response = await API.clinicMedicineOptions(query);
        if (request !== this._medicineRequest || !this.formModal.open) return;
        this.medicineResults = response.data || [];
        if (restoreId) {
          const item = this.medicineResults.find(item => Number(item.item_id) === Number(restoreId));
          if (item) this.selectMedicine(item, true);
          else this.medicineError = "This medicine is no longer available. Select another medicine.";
        } else if (!this.medicineResults.length) this.medicineError = "No matching Medicine products.";
      } catch (error) {
        if (request === this._medicineRequest) this.medicineError = error.message || "Unable to load medicines.";
      } finally {
        if (request === this._medicineRequest) this.medicineLoading = false;
      }
    },

    restoreMedicineSelection() {
      this.clearMedicine();
      return this.loadMedicineResults(this.form.vaccine_name, this._medicineRequest, this.form.inventory_item_id);
    },

    selectMedicine(item, restoring = false) {
      if (!this.productSelectable(item)) return;
      this.selectedMedicine = item;
      this.medicineQuery = item.item_name;
      this.medicineResults = [];
      this.medicineError = "";
      this.form.inventory_item_id = String(item.item_id);
      if (!restoring) {
        this.form.inventory_quantity = 1;
      }
      delete this.formErrors.inventory_item_id;
    },

    validateForm(finalizing = false) {
      const errors = {};
      const value = (field) => String(this.form[field] ?? "").trim();
      const maxLength = (field, max, label) => {
        if (value(field).length > max) {
          errors[field] = `${label} must not exceed ${max} characters.`;
        }
      };

      const usesInventory = this.recordKind === "inventory";
      const medicine = this.treatmentType === "medicine";
      if (usesInventory) {
        const quantity = value("inventory_quantity");
        if (quantity && (!/^\d+$/.test(quantity) || Number(quantity) < 1 || Number(quantity) > 99999999)) {
          errors.inventory_quantity = "Use a whole number of physical inventory units, at least 1.";
        } else if (this.selectedProduct && Number(quantity) > Number(this.selectedProduct.available_quantity)) {
          errors.inventory_quantity = "Quantity used exceeds available unexpired stock.";
        } else if (finalizing && !quantity) errors.inventory_quantity = "required";
        if (finalizing && (!this.selectedProduct || !this.productSelectable(this.selectedProduct))) errors.inventory_item_id = "required";
        if (medicine && this.selectedMedicine && this.medicineQuote?.error) errors.inventory_item_id = this.medicineQuote.error;
        if (finalizing && medicine && !this.formModal.clinicAppointmentId) errors.clinic_appointment_id = "An ongoing Clinic case is required for a medicine treatment.";
      }

      const dose = Number(value("dose_amount"));
      if (value("dose_amount") && (!Number.isFinite(dose) || dose <= 0)) {
        errors.dose_amount = "Dose amount must be greater than zero.";
      } else if (dose > 99999.999) {
        errors.dose_amount = "Dose amount must not exceed 99,999.999.";
      }

      if (Boolean(value("dose_amount")) !== Boolean(value("dose_unit"))) {
        errors[value("dose_amount") ? "dose_unit" : "dose_amount"] = "Enter both Dose Amount and Dose Unit, or leave both blank.";
      }
      maxLength("dose_unit", 30, "Dose unit");
      maxLength("administration_site", 100, "Administration site");

      if (
        value("route")
        && !this.administrationRoutes.some((route) => route.value === value("route"))
      ) {
        errors.route = "Choose a supported administration route.";
      }

      if (!medicine && usesInventory && value("next_due_date") && value("next_due_date") < vaccinationToday()) {
        errors.next_due_date = "Next vaccination date cannot be before today.";
      }
      if (!usesInventory) {
        if (finalizing && !value("vaccine_name")) errors.vaccine_name = "required";
        if ((finalizing && !value("administered_date")) || (value("administered_date") && (!/^\d{4}-\d{2}-\d{2}$/.test(value("administered_date")) || Number.isNaN(new Date(value("administered_date")).getTime()) || value("administered_date") > vaccinationToday()))) {
          errors.administered_date = "Enter the actual administration date, on or before today.";
        }
        for (const field of medicine ? [] : ["next_due_date", "product_expiry_date"]) {
          if (value(field) && value(field) < value("administered_date")) errors[field] = "This date must be on or after the administration date.";
        }
      }

      this.formErrors = errors;
      this.formErrorSummary = Object.keys(errors).length ? "Complete the required fields marked *." : "";

      if (this.formErrorSummary) {
        this.focusValidationSummary();
        return false;
      }

      return true;
    },

    buildPayload() {
      if (!this.form.request_key) this.form.request_key = crypto.randomUUID();
      const nullableText = (field) => {
        const text = String(this.form[field] ?? "").trim();
        return text || null;
      };
      const nullableId = (field) => {
        const raw = String(this.form[field] ?? "").trim();
        return raw ? Number(raw) : null;
      };

      return {
        treatment_type: this.treatmentType,
        ...(this.form.request_key ? { request_key: this.form.request_key } : {}),
        ...(this.treatmentType === "medicine" && this.recordKind === "inventory" ? {
          expected_price: this.medicineQuote?.price ?? null,
          expected_weight_kg: this.medicineQuote?.pet_weight_kg ?? null,
        } : {}),
        inventory_quantity: this.recordKind === "inventory" ? nullableText("inventory_quantity") : null,
        inventory_item_id: this.recordKind === "inventory" ? nullableId("inventory_item_id") : null,
        ...(this.recordKind === "historical" ? {
          vaccine_name: nullableText("vaccine_name"),
          administered_date: nullableText("administered_date"),
          product_name: nullableText("product_name"),
          manufacturer: nullableText("manufacturer"),
          batch_number: nullableText("batch_number"),
          product_expiry_date: nullableText("product_expiry_date"),
        } : {}),
        next_due_date: this.treatmentType === "vaccine" ? nullableText("next_due_date") : null,
        dose_amount: nullableText("dose_amount"),
        dose_unit: nullableText("dose_unit"),
        route: nullableText("route"),
        administration_site: nullableText("administration_site"),
        clinic_appointment_id: this.formModal.clinicAppointmentId
          ? Number(this.formModal.clinicAppointmentId)
          : null,
        notes: nullableText("notes"),
      };
    },

    hasUnfinishedTreatment() {
      if (!this.formModal.open) return false;
      if (this.formModal.mode === 'edit') return true;
      const blank = emptyVaccinationDraftForm();
      return !!this.productQuery.trim() || Object.keys(blank).some(key => key !== 'request_key'
        && String(this.form[key] ?? '').trim() !== String(blank[key] ?? '').trim());
    },

    async saveTreatment(requireComplete = true) {
      if (!this.canEditClinical || !this.caseOngoing() || this.actionBusyId !== null
        || this.formModal.saving || !this.validateForm(requireComplete)) return false;
      const id = this.appointment?.id, petId = this.pet.id;
      this.formModal.saving = true;
      this.formErrors = {}; this.formErrorSummary = '';
      try {
        const editing = this.formModal.mode === 'edit';
        const payload = this.buildPayload();
        const saved = await (editing
          ? API.updateAdminPetVaccination(petId, this.formModal.recordId, payload)
          : API.createAdminPetVaccination(petId, payload));
        if (id !== this.appointment?.id || petId !== this.pet?.id) return false;
        ++this._recordsRequest;
        this.loading = false;
        this.error = '';
        const index = this.records.findIndex(record => record.id === saved.vaccination.id);
        if (index < 0) this.records.unshift(saved.vaccination);
        else this.records.splice(index, 1, saved.vaccination);
        this.resetInlineForm();
        this.showToast(editing ? 'Treatment updated.' : 'Treatment added.', 'success');
        return true;
      } catch (error) {
        if (id === this.appointment?.id) this.applyBackendValidation(error, 'Treatment could not be saved.');
        return false;
      } finally { this.formModal.saving = false; }
    },

    async removeTreatment(record) {
      if (!this.canEditClinical || !this.caseOngoing() || record.state !== 'draft'
        || this.formModal.saving || this.actionBusyId !== null) return;
      const id = this.appointment.id, petId = this.pet.id;
      this.actionBusyId = record.id;
      try {
        const confirmed = await this.$store.clinicFeedback.confirm({title: 'Remove treatment?',
          message: 'Remove this treatment from the current visit?', cancelLabel: 'Cancel', confirmLabel: 'Remove', danger: true});
        if (!confirmed || id !== this.appointment?.id || !this.caseOngoing()) return;
        await API.deleteAdminPetVaccination(petId, record.id);
        if (id !== this.appointment?.id) return;
        ++this._recordsRequest;
        this.loading = false;
        this.error = '';
        this.records = this.records.filter(row => row.id !== record.id);
        if (this.formModal.recordId === record.id) this.resetInlineForm();
        this.showToast('Treatment removed.', 'success');
      } catch (error) { if (id === this.appointment?.id) this.error = error.message || 'Treatment could not be removed.'; }
      finally { this.actionBusyId = null; }
    },

    applyBackendValidation(error, fallback) {
      const backendErrors = error.errors || {};
      this.formErrors = Object.fromEntries(
        Object.entries(backendErrors).map(([field, messages]) => [
          field,
          Array.isArray(messages) ? messages.join(" ") : String(messages),
        ]),
      );
      this.formErrorSummary = Object.keys(this.formErrors).length ? "Complete the required fields marked *." : (error.message || fallback);
      this.focusValidationSummary();
    },

    fieldError(field) {
      const message = this.formErrors[field] || "";
      return message === "required" || /(?:field is required|is required\.)/i.test(message) ? "" : message;
    },

    focusValidationSummary() {
      this.$nextTick(() => {
        const firstInvalid = Array.from(this.$refs.treatmentForm?.querySelectorAll('[aria-invalid="true"]') || []).find(field => field.getClientRects().length);
        firstInvalid?.scrollIntoView({ block: "center", behavior: "smooth" });
        (firstInvalid || this.$refs.vaccinationValidationSummary)?.focus();
      });
    },

    async openDetails(record) {
      const request = ++this._detailRequest;
      this.detailReturnFocus = document.activeElement;
      this.detailModal = {
        open: true,
        loading: true,
        error: "",
        recordId: record.id,
        record: null,
      };
      this.$nextTick(() => this.$refs.treatmentDetailClose?.focus());

      try {
        const response = await API.getAdminPetVaccination(this.pet.id, record.id);
        if (request !== this._detailRequest) return;
        this.detailModal.record = response.vaccination || null;
      } catch (error) {
        if (request === this._detailRequest) this.detailModal.error = error.message || "Treatment details could not be loaded.";
      } finally {
        if (request === this._detailRequest) this.detailModal.loading = false;
      }
    },

    retryDetails() {
      const fallback = this.records.find(
        (record) => Number(record.id) === Number(this.detailModal.recordId),
      );
      if (fallback) this.openDetails(fallback);
    },

    closeDetails() {
      ++this._detailRequest;
      this.detailModal.open = false;
      this.detailModal.record = null;
      this.detailModal.error = "";
      this.$nextTick(() => this.detailReturnFocus?.focus());
    },

    dueLabel(status) {
      return {
        current: "Current",
        due_soon: "Due soon",
        overdue: "Overdue",
        unknown: "Unknown",
      }[status] || "Unknown";
    },

    dueBadgeClass(status) {
      return {
        current: "border-emerald-200 bg-emerald-50 text-emerald-700",
        due_soon: "border-amber-200 bg-amber-50 text-amber-800",
        overdue: "border-red-200 bg-red-50 text-red-700",
        unknown: "border-slate-200 bg-slate-50 text-slate-600",
      }[status] || "border-slate-200 bg-slate-50 text-slate-600";
    },

    formatDate(value) {
      if (!value) return "Not provided";
      const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
      if (Number.isNaN(date.getTime())) return "Not provided";
      return date.toLocaleDateString("en-PH", {
        year: "numeric",
        month: "short",
        day: "numeric",
      });
    },

    formatDateTime(value) {
      if (!value) return "Not provided";
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return "Not provided";
      return date.toLocaleString("en-PH", {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
      });
    },

    displayValue(value) {
      return value === null || value === undefined || String(value).trim() === ""
        ? "Not provided"
        : String(value);
    },

    doseLabel(record) {
      if (record?.dose_amount === null || record?.dose_amount === undefined || record?.dose_amount === "") {
        return "—";
      }
      return `${Number(record.dose_amount)}${record.dose_unit ? ` ${record.dose_unit}` : ""}`;
    },

    typeLabel(record) { return record?.treatment_type === "medicine" ? "Medicine" : "Vaccine"; },
    treatmentSummary: clinicTreatmentSummary,
    treatmentDetails(record) {
      return clinicTreatmentDetails(record).filter(field => !['Status', 'Published', 'Published By', 'Voided', 'Voided By', 'Void Reason'].includes(field.label));
    },
    routeSite(record) {
      return [this.administrationRoutes.find(route => route.value === record.route)?.label || record.route,
        record.administration_site].filter(Boolean).join(' / ') || '—';
    },
    trapTreatmentFocus(event) {
      const controls = [...event.currentTarget.querySelectorAll('button, input, textarea, [tabindex="0"]')]
        .filter(element => !element.disabled && element.getClientRects().length);
      const first = controls[0], last = controls.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    },

    showToast(message, variant = 'success') {
      this.$store.clinicFeedback.notify(message, variant);
    },
  };
}
