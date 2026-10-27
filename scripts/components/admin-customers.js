let adminCustomerPetFieldModulesReady;
function loadAdminCustomerPetFields() {
  return adminCustomerPetFieldModulesReady ??= Promise.all([
  import("../services/booking-draft-service.js?registration=fcfs-20261027-v1"),
  import("./breed-combobox.js?pet-fields=20261026-v1"),
  import("./breed-coat-catalogue.js?pet-fields=20261026-v1"),
  import("./breed-coat-combobox.js?pet-fields=20261026-v1"),
  import("./fixed-option-combobox.js"),
  import("./pet-weight-size.js?v=pet-weight-size-20261011-v3"),
]).then(([
  bookingDraft,
  breedCombobox,
  breedCoatCatalogue,
  breedCoatCombobox,
  fixedOptionCombobox,
  petWeightSize,
]) => ({
  ...bookingDraft,
  ...breedCombobox,
  ...breedCoatCatalogue,
  ...breedCoatCombobox,
  ...fixedOptionCombobox,
  ...petWeightSize,
})).catch(error => { adminCustomerPetFieldModulesReady = null; throw error; });
}

let adminCustomerPetFields = null;
let adminCustomerFormatWeight = null;

function adminCustomers() {
  return {
    customers:    [],
    petSearchResults: [],
    totalCount:   0,
    loading:      true,
    customersLoaded: false,
    _customersKey: "",
    _customerRequest: 0,
    errorMessage: "",
    statusFilter: "active",   // active | inactive | archived | unregistered
    tierFilter:   "",          // new | returning | ""
    searchQuery:  "",
    busyId:       null,

    addCustomerModal: {
      open:   false,
      saving: false,
      error:  "",
      errors: {},
      form: {
        first_name:  "",
        last_name:   "",
        middle_name: "",
        phone:       "",
        email:       "",
      },
    },

    similarNameModal: {
      open: false,
      customers: [],
    },

    confirmModal: {
      open:         false,
      action:       "",   // deactivate | reactivate | archive | unarchive
      customer:     null,
      title:        "",
      message:      "",
      confirmLabel: "",
      error:        "",
    },

    resetModal: {
      open:     false,
      customer: null,
    },

    detailModal: {
      open:     false,
      customer: null,
      loading:  false,
      error:    "",
    },

    petModal: {
      open:      false,
      pet:       null,
      editing:   false,
      creating:  false,
      owner:     null,
      saving:    false,
      saveError: "",
      form:      {},
      activeTab: "overview",
      profileLoading: false,
      profileLoaded: false,
      profileError: "",
      groomingRecords: [],
      medicalRecords: [],
      vaccinations: [],
    },
    _petEditMarkupReady: null,

    // ── Init ──────────────────────────────────────────────

    async init() {
      const params = new URLSearchParams(window.location.search);
      const recordType = params.get("record_type");
      if (recordType === "unregistered") {
        this.statusFilter = "unregistered";
        this.tierFilter = "";
      }

      await this.loadCustomers();
      const customerId = params.get("customer_id");
      const petId      = params.get("pet_id");
      if (customerId) {
        const customer = this.customers.find(c => String(c.id) === customerId);
        if (customer) {
          await this.openCustomerSearchDestination(customer, petId);
        } else {
          // Not in the current filtered list — fetch directly by ID
          try {
            const data = recordType === "unregistered"
              ? await API.getUnregisteredCustomerDetails(customerId)
              : await API.getCustomerDetails(customerId);
            if (data.customer) {
              await this.openCustomerSearchDestination(data.customer, petId);
            }
          } catch { /* silently ignore if not found */ }
        }
      }
    },

    get isAdmin() {
      return API.getUserRole?.() === "admin";
    },

    // ── Load ─────────────────────────────────────────────

    async loadCustomers({ force = false } = {}) {
      const params = { status: this.statusFilter, tier: this.tierFilter, search: this.searchQuery.trim() };
      const query = new URLSearchParams();
      for (const [key, value] of Object.entries(params)) if (value) query.set(key, value);
      const key = `/admin/customers${query.size ? `?${query}` : ""}`, request = ++this._customerRequest;
      this.customersLoaded = this.customersLoaded && key === this._customersKey;
      this.loading = true;
      this.errorMessage = "";
      try {
        await API.loadAdminData(key, () => API.getCustomers(params, { force }), data => {
          if (request !== this._customerRequest) return;
          this.customers = data.customers || [];
          this.petSearchResults = data.pets || [];
          this.totalCount = data.total ?? this.customers.length;
          this._customersKey = key;
          this.customersLoaded = true;
        }, { force });
      } catch (err) {
        if (request === this._customerRequest) this.errorMessage = err.message || "Failed to load customers.";
      } finally {
        if (request === this._customerRequest) this.loading = false;
      }
    },

    setStatus(status) {
      this.statusFilter = status;
      if (status === "unregistered") this.tierFilter = "";
      this.loadCustomers({ force: false });
    },

    setTier(tier) {
      this.tierFilter = tier;
      this.loadCustomers({ force: false });
    },

    // Add unregistered customer

    openAddCustomerModal() {
      this.addCustomerModal = {
        open:   true,
        saving: false,
        error:  "",
        errors: {},
        form: {
          first_name:  "",
          last_name:   "",
          middle_name: "",
          phone:       "",
          email:       "",
        },
      };
      this.setCustomerDetailScrollLock(true);
    },

    closeAddCustomerModal() {
      if (this.addCustomerModal.saving) return;
      this.addCustomerModal.open = false;
      this.addCustomerModal.error = "";
      this.addCustomerModal.errors = {};
      this.setCustomerDetailScrollLock(false);
    },

    clearAddCustomerFieldError(field) {
      if (!this.addCustomerModal.errors[field]) return;
      const errors = { ...this.addCustomerModal.errors };
      delete errors[field];
      this.addCustomerModal.errors = errors;
    },

    validateAddCustomerForm() {
      const form = this.addCustomerModal.form;
      const errors = {};
      const phone = this.normalizeCustomerPhone(form.phone);
      const email = String(form.email || "").trim();

      if (!String(form.first_name || "").trim()) errors.first_name = ["First name is required."];
      if (!String(form.last_name || "").trim()) errors.last_name = ["Last name is required."];
      if (!/^09\d{9}$/.test(phone)) errors.phone = ["Phone number must be 11 digits and start with 09."];
      if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.email = ["Enter a valid email address or leave it blank."];
      }

      this.addCustomerModal.errors = errors;
      return Object.keys(errors).length === 0;
    },

    async submitUnregisteredCustomer(confirmSimilarName = false) {
      if (this.addCustomerModal.saving || !this.validateAddCustomerForm()) return;

      const form = this.addCustomerModal.form;
      const payload = {
        first_name: String(form.first_name || "").trim(),
        last_name: String(form.last_name || "").trim(),
        middle_name: String(form.middle_name || "").trim().replace(/\.+$/, "").toUpperCase() || null,
        phone: this.normalizeCustomerPhone(form.phone),
        email: String(form.email || "").trim() || null,
        confirm_similar_name: Boolean(confirmSimilarName),
      };

      this.addCustomerModal.saving = true;
      this.addCustomerModal.error = "";
      this.addCustomerModal.errors = {};

      try {
        await API.createUnregisteredCustomer(payload);
        this.similarNameModal = { open: false, customers: [] };
        this.addCustomerModal.open = false;
        this.statusFilter = "unregistered";
        this.tierFilter = "";
        this.searchQuery = "";
        this.setCustomerDetailScrollLock(false);
        await this.loadCustomers();
      } catch (err) {
        if (err.code === "similar_customer_name") {
          this.similarNameModal = {
            open: true,
            customers: err.data?.similarCustomers || [],
          };
          this.addCustomerModal.error = "";
          return;
        }
        this.addCustomerModal.errors = err.errors || {};
        this.addCustomerModal.error = err.errors
          ? ""
          : (err.message || "Failed to add customer. Please try again.");
      } finally {
        this.addCustomerModal.saving = false;
      }
    },

    closeSimilarNameModal() {
      if (this.addCustomerModal.saving) return;
      this.similarNameModal = { open: false, customers: [] };
    },

    // ── Confirm modal ─────────────────────────────────────

    confirmAction(action, customer) {
      const staffAllowedActions = ["archive_unregistered"];
      if (!this.isAdmin && !staffAllowedActions.includes(action)) return;

      const labels = {
        deactivate:  { title: "Deactivate Account",  confirmLabel: "Deactivate",  color: "amber"  },
        reactivate:  { title: "Reactivate Account",  confirmLabel: "Reactivate",  color: "green"  },
        archive:     { title: "Archive Account",     confirmLabel: "Archive",     color: "slate"  },
        archive_unregistered: { title: "Archive Customer", confirmLabel: "Archive", color: "slate" },
        unarchive:   { title: "Unarchive Account",   confirmLabel: "Unarchive",   color: "green"  },
      };

      const messages = {
        deactivate: `This will block ${customer.fullName} from logging in. You can reactivate their account at any time.`,
        reactivate: `This will restore ${customer.fullName}'s access and allow them to log in again.`,
        archive:    `This will permanently move ${customer.fullName} to the archive. They will not be able to log in.`,
        archive_unregistered: `This will move ${customer.fullName} and their pet records to the archive.`,
        unarchive:  `This will restore ${customer.fullName}'s account and reactivate their access.`,
      };

      const meta = labels[action] || {};

      this.confirmModal = {
        open:         true,
        action,
        customer,
        title:        meta.title        || action,
        message:      messages[action]  || "",
        confirmLabel: meta.confirmLabel || action,
        error:        "",
      };

    },

    closeConfirm() {
      this.confirmModal.open = false;
    },

    async executeAction() {
      const { action, customer } = this.confirmModal;
      const staffAllowedActions = ["archive_unregistered"];
      if (!this.isAdmin && !staffAllowedActions.includes(action)) return;
      if (!customer) return;

      this.busyId              = customer.id;
      this.confirmModal.error  = "";

      const apiMap = {
        deactivate: () => API.deactivateCustomer(customer.id),
        reactivate: () => API.reactivateCustomer(customer.id),
        archive:    () => API.archiveCustomer(customer.id),
        archive_unregistered: () => API.archiveUnregisteredCustomer(customer.id),
        unarchive:  () => API.unarchiveCustomer(customer.id),
      };

      try {
        await apiMap[action]();
        this.confirmModal.open = false;
        if (this.detailModal.open) this.closeCustomerDetails();
        await this.loadCustomers();
      } catch (err) {
        this.confirmModal.error = err.message || "Action failed. Please try again.";
      } finally {
        this.busyId = null;
      }
    },

    // ── Reset Password modal ──────────────────────────────

    openResetPassword(customer) {
      if (!this.isAdmin) return;

      this.resetModal = { open: true, customer };
    },

    closeReset() {
      this.resetModal.open = false;
    },

    async openPetDetail(pet) {
      this.petModal = {
        open: true,
        pet,
        editing: false,
        creating: false,
        owner: this.detailModal.customer || null,
        saving: false,
        saveError: "",
        form: {},
        activeTab: "overview",
        profileLoading: true,
        profileLoaded: false,
        profileError: "",
        groomingRecords: [],
        medicalRecords: [],
        vaccinations: [],
      };
      await this.loadPetProfile();
    },

    async loadPetProfile() {
      const petId = this.petModal.pet?.id;
      if (!petId || this.petModal.creating) return;

      this.petModal.profileLoading = true;
      this.petModal.profileError = "";

      try {
        const data = await API.getAdminPetProfile(petId);
        if (!this.petModal.open || String(this.petModal.pet?.id) !== String(petId)) return;

        this.petModal.groomingRecords = data.grooming_records || [];
        this.petModal.medicalRecords = data.medical_records || [];
        this.petModal.vaccinations = data.vaccinations || [];
        this.petModal.profileLoaded = true;
      } catch (error) {
        if (!this.petModal.open || String(this.petModal.pet?.id) !== String(petId)) return;
        this.petModal.profileError = error.message || "Pet history could not be loaded.";
      } finally {
        if (this.petModal.open && String(this.petModal.pet?.id) === String(petId)) {
          this.petModal.profileLoading = false;
        }
      }
    },

    selectPetProfileTab(tab) {
      if (!["overview", "grooming", "medical", "vaccinations"].includes(tab)) return;
      this.petModal.activeTab = tab;
    },

    async openAddPetForCustomer(customer) {
      if (!customer || customer.recordType !== "unregistered" || customer.isArchived) return;

      this.petModal = {
        open: true,
        pet: null,
        editing: true,
        creating: true,
        owner: customer,
        saving: false,
        saveError: "",
        activeTab: "overview",
        profileLoading: false,
        profileLoaded: false,
        profileError: "",
        groomingRecords: [],
        medicalRecords: [],
        vaccinations: [],
        form: {
          pet_name: "",
          species: "",
          breed: "",
          gender: "",
          birthdate: "",
          is_neutered: false,
          neutered_date: "",
          is_deceased: false,
          deceased_date: "",
          size: "",
          fur_type: "",
          weight: "",
          color: "",
          medical_conditions: "",
        },
      };

      if (!await this.initializePetFormFields()) {
        this.petModal.saveError = "Pet controls could not be loaded. Please refresh and try again.";
        return;
      }

      const { tools, elements, controls } = adminCustomerPetFields;
      controls.species.reset();
      controls.gender.reset();
      controls.breed.reset();
      controls.furType.reset();
      controls.size.reset();
      controls.size.setDisabled(true);
      elements.breed.value = "";
      elements.furType.value = "";
      tools.initializeWeightField(elements.weight);
      elements.weight.value = "";
    },

    async openCustomerSearchDestination(customer, petId = "") {
      await this.openCustomerDetails(customer);
      if (!petId || this.detailModal.error) return;

      const pet = (this.detailModal.customer?.pets || [])
        .find(candidate => String(candidate.id) === String(petId));

      if (pet) this.openPetDetail(pet);
    },

    async openPetSearchResult(result) {
      await this.openCustomerDetails({
        id:       result.ownerId,
        fullName: result.ownerName,
      });

      if (!this.detailModal.open || this.detailModal.error) return;

      const pet = (this.detailModal.customer?.pets || [])
        .find(candidate => String(candidate.id) === String(result.id));

      if (!pet) {
        this.detailModal.error = "This pet's details are no longer available.";
        return;
      }

      this.openPetDetail(pet);
    },

    async initializePetFormFields() {
      if (adminCustomerPetFields) return true;

      try {
        if (!this.$refs.petEditForm.firstElementChild) {
          await (this._petEditMarkupReady ??= (async () => {
            const response = await fetch('./pet-edit-form.html?v=20261014&icons=20261018&pet-fields=20261026-v1');
            if (!response.ok) throw new Error('Pet editing form could not be loaded.');
            const form = document.createElement('div');
            form.innerHTML = await response.text();
            Alpine.mutateDom(() => {
              this.$refs.petEditForm.appendChild(form);
              Alpine.initTree(form);
            });
          })().finally(() => { this._petEditMarkupReady = null; }));
        }
        const tools = await loadAdminCustomerPetFields();
        if (adminCustomerPetFields) return true;
        const elements = {
          species: document.getElementById("adminPetSpecies"),
          breed: document.getElementById("adminPetBreed"),
          gender: document.getElementById("adminPetGender"),
          size: document.getElementById("adminPetSize"),
          furType: document.getElementById("adminPetFurType"),
          weight: document.getElementById("adminPetWeight"),
        };
        const controls = {
          species: tools.createFixedOptionCombobox({
            root: document.getElementById("adminPetSpeciesCombobox"),
            input: elements.species,
            listbox: document.getElementById("adminPetSpeciesOptions"),
            toggleButton: document.getElementById("adminPetSpeciesDropdownButton"),
            placeholder: "Select species",
            options: [
              { value: "Dog", label: "Dog" },
              { value: "Cat", label: "Cat" },
            ],
          }),
          gender: tools.createFixedOptionCombobox({
            root: document.getElementById("adminPetGenderCombobox"),
            input: elements.gender,
            listbox: document.getElementById("adminPetGenderOptions"),
            toggleButton: document.getElementById("adminPetGenderDropdownButton"),
            placeholder: "Select gender",
            options: [
              { value: "male", label: "Male" },
              { value: "female", label: "Female" },
            ],
            displaySelectedLabel: true,
          }),
          breed: tools.createBreedCombobox({
            root: document.getElementById("adminPetBreedCombobox"),
            input: elements.breed,
            listbox: document.getElementById("adminPetBreedOptions"),
            toggleButton: document.getElementById("adminPetBreedDropdownButton"),
            errorElement: document.getElementById("adminPetBreedError"),
            petTypeInput: elements.species,
            getPetType: () => elements.species.value,
          }),
          furType: tools.createBreedCoatCombobox({
            root: document.getElementById("adminPetFurTypeCombobox"),
            breedInput: elements.breed,
            petTypeInput: elements.species,
            input: elements.furType,
            listbox: document.getElementById("adminPetFurTypeOptions"),
            toggleButton: document.getElementById("adminPetFurTypeDropdownButton"),
            errorElement: document.getElementById("adminPetFurTypeError"),
          }),
          size: tools.createFixedOptionCombobox({
            root: document.getElementById("adminPetSizeCombobox"),
            input: elements.size,
            listbox: document.getElementById("adminPetSizeOptions"),
            toggleButton: document.getElementById("adminPetSizeDropdownButton"),
            placeholder: "Select size",
            options: [],
            displaySelectedLabel: false,
          }),
        };

        adminCustomerPetFields = { tools, elements, controls };
        tools.initializeWeightField(elements.weight);
        controls.size.setDisabled(true);

        elements.species.addEventListener("change", () => {
          this.petModal.form.species = controls.species.getValue();
          this.syncPetWeightAndSize({ clearManualSize: true });
          this.validatePetWeightOnCommit();
        });
        elements.gender.addEventListener("change", () => {
          this.petModal.form.gender = controls.gender.getValue();
        });
        elements.breed.addEventListener("change", () => {
          this.petModal.form.breed = elements.breed.value;
        });
        elements.furType.addEventListener("change", () => {
          this.petModal.form.fur_type = elements.furType.value;
        });
        elements.weight.addEventListener("input", () => this.syncPetWeightAndSize());
        elements.weight.addEventListener("change", () => this.validatePetWeightOnCommit());
        elements.size.addEventListener("change", () => this.handleManualPetSizeChange());

        return true;
      } catch (error) {
        console.error("Admin pet fields could not be initialized.", error);
        return false;
      }
    },

    async startEditPet() {
      if (!await this.initializePetFormFields()) {
        this.petModal.saveError = "Pet editing controls could not be loaded. Please refresh and try again.";
        return;
      }

      const p = this.petModal.pet;
      this.petModal.form = {
        pet_name:            p.petName           || "",
        species:             p.species           || "",
        breed:               p.breed             || "",
        gender:              p.gender            || "",
        birthdate:           p.birthdate         || "",
        is_neutered:         p.isNeutered        || false,
        neutered_date:       p.neuteredDate      || "",
        is_deceased:         p.isDeceased        || false,
        deceased_date:       p.deceasedDate      || "",
        size:                p.size              || "",
        fur_type:            p.furType           || "",
        weight:              p.weight            ?? "",
        color:               p.color             || "",
        medical_conditions:  p.medicalConditions || "",
      };
      const { tools, elements, controls } = adminCustomerPetFields;
      const species = this.normalizePetSpecies(p.species);

      controls.species.setValue(species);
      controls.gender.setValue(String(p.gender || "").toLowerCase());
      controls.breed.setValue(p.breed || "");
      elements.furType.value = p.furType || "";
      controls.furType.update({ preserveValue: true, autoSelect: false });
      tools.initializeWeightField(elements.weight);
      elements.weight.value = tools.formatSavedWeight(p.weight);
      this.syncPetWeightAndSize({ clearManualSize: true });
      if (!tools.getSizeForWeight(species, elements.weight.value)) this.setStoredPetSize(p.size);

      await tools.breedCoatCatalogueReady;
      controls.furType.update({ preserveValue: true, autoSelect: false });

      this.petModal.editing   = true;
      this.petModal.saveError = "";
    },

    cancelEditPet() {
      if (this.petModal.creating) {
        this.petModal.open = false;
        this.petModal.editing = false;
        return;
      }
      this.petModal.editing   = false;
      this.petModal.saveError = "";
    },

    async saveEditPet() {
      if (this.petModal.saving) return;
      this.petModal.saveError = "";

      const payload = this.buildPetEditPayload();
      const validationMessage = this.validatePetEditPayload(payload);
      if (validationMessage) {
        const { tools, elements, controls } = adminCustomerPetFields;
        controls.breed.showValidation();
        controls.furType.showValidation();
        const weightMessage = tools.getWeightFieldValidationMessage(elements.weight)
          || tools.getWeightValidationMessage(payload.species, payload.weight);
        if (weightMessage) {
          tools.showWeightValidationInField(elements.weight, weightMessage);
        }
        if (validationMessage === controls.furType.getValidationMessage()) {
          elements.furType.focus();
        } else if (validationMessage === controls.breed.getValidationMessage()) {
          elements.breed.focus();
        } else {
          this.petModal.saveError = validationMessage;
        }
        return;
      }

      this.petModal.saving = true;

      try {
        const response = this.petModal.creating
          ? await API.adminAddCustomerPet(
              this.petModal.owner.recordType,
              this.petModal.owner.id,
              payload,
            )
          : await API.adminUpdatePet(this.petModal.pet.id, payload);

        const savedPet = response.pet || payload;
        const updated = {
          ...(this.petModal.pet || {}),
          id:                savedPet.pet_id || this.petModal.pet?.id,
          petName:           savedPet.pet_name,
          species:           savedPet.species,
          breed:             savedPet.breed,
          gender:            savedPet.gender,
          birthdate:         savedPet.birthdate,
          isNeutered:        Boolean(savedPet.is_neutered),
          neuteredDate:      savedPet.neutered_date,
          isDeceased:        Boolean(savedPet.is_deceased),
          deceasedDate:      savedPet.deceased_date,
          size:              savedPet.size,
          sizeVerified:      Array.isArray(savedPet.clinic_verified_fields) && savedPet.clinic_verified_fields.includes("size"),
          furType:           savedPet.fur_type,
          weight:            savedPet.weight,
          color:             savedPet.color,
          medicalConditions: savedPet.medical_conditions,
        };

        this.petModal.pet     = updated;
        this.petModal.editing = false;
        const wasCreating = this.petModal.creating;
        this.petModal.creating = false;

        // Sync the updated pet back into the customer detail modal list
        if (this.detailModal.customer?.pets) {
          const idx = this.detailModal.customer.pets.findIndex(p => p.id === updated.id);
          if (idx !== -1) {
            this.detailModal.customer.pets[idx] = updated;
          } else if (wasCreating) {
            this.detailModal.customer.pets.unshift(updated);
            this.detailModal.customer.petCount = this.detailModal.customer.pets.length;
            this.detailModal.customer.activePetCount = Number(this.detailModal.customer.activePetCount || 0) + 1;
          }
        }

      } catch (err) {
        if (err.errors?.fur_type?.[0]) {
          adminCustomerPetFields.controls.furType.showValidation(err.errors.fur_type[0]);
          adminCustomerPetFields.elements.furType.focus();
          return;
        }
        if (err.errors?.weight?.[0]) {
          const { tools, elements } = adminCustomerPetFields;
          tools.showWeightValidationInField(elements.weight, err.errors.weight[0]);
        }
        this.petModal.saveError = err.errors
          ? Object.values(err.errors).flat()[0]
          : (err.message || "Failed to save changes.");
      } finally {
        this.petModal.saving = false;
      }
    },

    buildPetEditPayload() {
      const { tools, elements, controls } = adminCustomerPetFields;
      const form = this.petModal.form;
      const isNeutered = Boolean(form.is_neutered);
      const isDeceased = Boolean(form.is_deceased);

      return {
        pet_name: String(form.pet_name || "").trim(),
        species: controls.species.getValue() || null,
        breed: elements.breed.value.trim() || null,
        gender: controls.gender.getValue() || null,
        birthdate: form.birthdate || null,
        is_neutered: isNeutered,
        neutered_date: isNeutered ? (form.neutered_date || null) : null,
        is_deceased: isDeceased,
        deceased_date: isDeceased ? (form.deceased_date || null) : null,
        size: tools.normalizePetSize(controls.size.getValue()) || null,
        fur_type: elements.furType.value || null,
        weight: tools.getEnteredWeight(elements.weight) || null,
        color: String(form.color || "").trim() || null,
        medical_conditions: String(form.medical_conditions || "").trim() || null,
      };
    },

    validatePetEditPayload(payload) {
      const { tools, elements, controls } = adminCustomerPetFields;
      if (!payload.pet_name) return "Pet name is required.";

      const breedValidationMessage = controls.breed.getValidationMessage();
      if (breedValidationMessage) return breedValidationMessage;

      const furTypeValidationMessage = controls.furType.getValidationMessage();
      if (furTypeValidationMessage) return furTypeValidationMessage;

      const weightValidationMessage = tools.getWeightFieldValidationMessage(elements.weight)
        || tools.getWeightValidationMessage(payload.species, payload.weight);
      if (weightValidationMessage) return weightValidationMessage;

      const allowedSizes = tools.getSizeOptions(payload.species).map(
        ({ value }) => tools.normalizePetSize(value),
      );
      if (payload.size && !allowedSizes.includes(payload.size)) {
        return "Select a size available for this pet type.";
      }

      return "";
    },

    normalizePetSpecies(value) {
      return String(value || "").trim().toLowerCase() === "cat" ? "Cat" : "Dog";
    },

    setStoredPetSize(storedSize) {
      if (!adminCustomerPetFields) return;
      const { tools, elements, controls } = adminCustomerPetFields;
      const normalizedStoredSize = tools.normalizePetSize(storedSize);
      const matchingOption = tools.getSizeOptions(elements.species.value).find(
        ({ value }) => tools.normalizePetSize(value) === normalizedStoredSize,
      );

      controls.size.setValue(matchingOption?.value || "");
    },

    syncPetWeightAndSize({ clearManualSize = false } = {}) {
      if (!adminCustomerPetFields) return;
      const { tools, elements, controls } = adminCustomerPetFields;
      tools.syncPetWeightAndSize(elements.weight, elements.species.value, controls.size, { clearManualSize });
    },

    validatePetWeightOnCommit() {
      this.syncPetWeightAndSize();
      return !adminCustomerPetFields?.tools.getWeightFieldValidationMessage(adminCustomerPetFields.elements.weight);
    },

    handleManualPetSizeChange() {
      this.syncPetWeightAndSize();
    },

    async openCustomerDetails(customer) {
      this.setCustomerDetailScrollLock(true);
      this.detailModal = {
        open:     true,
        customer: { ...customer, pets: [] },
        loading:  true,
        error:    "",
      };

      try {
        const [data, format] = await Promise.all([
          customer.recordType === "unregistered"
            ? API.getUnregisteredCustomerDetails(customer.id)
            : API.getCustomerDetails(customer.id),
          import("../services/booking-format-service.js?v=pet-weight-format-20261019"),
        ]);
        adminCustomerFormatWeight = format.formatSavedWeight;
        this.detailModal.customer = data.customer || this.detailModal.customer;
      } catch (err) {
        this.detailModal.error = err.message || "Failed to load customer details.";
      } finally {
        this.detailModal.loading = false;
      }
    },

    closeCustomerDetails() {
      this.detailModal.open = false;
      this.detailModal.error = "";
      this.setCustomerDetailScrollLock(false);
    },

    setCustomerDetailScrollLock(locked) {
      document.documentElement.classList.toggle("customer-detail-modal-open", locked);
      document.body.classList.toggle("customer-detail-modal-open", locked);
    },

    // ── Avatar helpers ────────────────────────────────────

    getInitials(fullName) {
      if (!fullName) return "?";
      const parts = fullName.trim().split(/\s+/);
      if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
      return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    },

    getAvatarColor(fullName) {
      // Deterministic color from name — same name always gets the same color
      const palette = [
        "#315b7e", "#4a7fa5", "#63a89d", "#7b6fa0",
        "#a06b5b", "#5b7a4a", "#7a5b8a", "#8a7a4a",
        "#4a6b8a", "#6b4a7a",
      ];
      let hash = 0;
      for (let i = 0; i < (fullName || "").length; i++) {
        hash = fullName.charCodeAt(i) + ((hash << 5) - hash);
      }
      return palette[Math.abs(hash) % palette.length];
    },

    // ── Date formatting ───────────────────────────────────

    formatDate(dateStr) {
      if (!dateStr) return "—";
      try {
        return new Intl.DateTimeFormat("en-PH", {
          year: "numeric", month: "short", day: "numeric",
        }).format(new Date(dateStr));
      } catch {
        return dateStr;
      }
    },

    formatMobileNumber(value) {
      const text = String(value ?? "").trim();
      if (!text || text === "—") return "—";

      const digits = text.replace(/\D/g, "");
      if (digits.length === 11) {
        return `${digits.slice(0, 4)}-${digits.slice(4, 7)}-${digits.slice(7)}`;
      }

      return text;
    },

    normalizeCustomerPhone(value) {
      const raw = String(value || "").trim();
      const digits = raw.replace(/\D/g, "");

      if (/^63\d{10}$/.test(digits)) return `0${digits.slice(2)}`;
      if (/^9\d{9}$/.test(digits)) return `0${digits}`;
      return digits;
    },

    formatTextValue(value) {
      const text = String(value ?? "").trim();
      return text || "Not provided";
    },

    formatLabel(value) {
      const text = String(value ?? "").trim();
      if (!text) return "Not provided";

      return text
        .replace(/_/g, " ")
        .split(" ")
        .filter(Boolean)
        .map(part => part.charAt(0).toUpperCase() + part.slice(1))
        .join(" ");
    },

    formatWeight(value) {
      if (value === null || value === undefined || value === "") return "Not provided";
      return `${adminCustomerFormatWeight(value)} kg`;
    },

    formatPetBoolean(value) {
      if (value === null || value === undefined || value === "") return "Not provided";
      return value === true || value === 1 || value === "1" ? "Yes" : "No";
    },

    formatPetNeuteredStatus(value, date) {
      const status = this.formatPetBoolean(value);
      return status === "Yes" && date
        ? `${status} \u00B7 ${this.formatDate(date)}`
        : status;
    },

    petOverviewDetails() {
      const pet = this.petModal.pet || {};

      return [
        { label: "Pet Name", value: this.formatTextValue(pet.petName) },
        { label: "Species", value: this.formatLabel(pet.species) },
        { label: "Breed", value: this.formatTextValue(pet.breed) },
        { label: "Gender", value: this.formatLabel(pet.gender) },
        { label: "Birthdate", value: this.formatDate(pet.birthdate) },
        { label: "Size", value: `${this.formatLabel(pet.size)} · ${pet.sizeVerified ? "✓ Clinic verified" : "Estimated from weight"}` },
        { label: "Weight", value: this.formatWeight(pet.weight) },
        { label: "Fur Type", value: this.formatLabel(pet.furType) },
        { label: "Color", value: this.formatTextValue(pet.color) },
        { label: "Neutered / Spayed", value: this.formatPetNeuteredStatus(pet.isNeutered, pet.neuteredDate) },
      ];
    },

    groomingStatusLabel(status) {
      return ({
        waiting_to_arrive: "Scheduled",
        checked_in: "Checked In",
        in_progress: "Being Groomed",
        grooming_finished: "Grooming Finished",
        for_payment: "For Payment",
        for_pickup: "Ready for Pickup",
        released: "Released",
        archived: "Completed",
        cancelled: "Cancelled",
        no_show: "No Show",
        expired: "Expired",
      })[status] || this.formatLabel(status);
    },

    groomingStatusClasses(status) {
      return ({
        waiting_to_arrive: "bg-blue-50 text-blue-700",
        checked_in: "bg-amber-50 text-amber-700",
        in_progress: "bg-violet-50 text-violet-700",
        grooming_finished: "bg-emerald-50 text-emerald-700",
        for_payment: "bg-orange-50 text-orange-700",
        for_pickup: "bg-cyan-50 text-cyan-700",
        released: "bg-emerald-50 text-emerald-700",
        archived: "bg-slate-100 text-slate-700",
        cancelled: "bg-red-50 text-red-700",
        no_show: "bg-slate-100 text-slate-600",
        expired: "bg-slate-100 text-slate-600",
      })[status] || "bg-slate-100 text-slate-700";
    },

    vaccinationStatusLabel(status) {
      return ({ current: "Current", due_soon: "Due soon", overdue: "Overdue", unknown: "Unknown" })[status] || "Unknown";
    },

    vaccinationStatusClasses(status) {
      return ({
        current: "border-emerald-200 bg-emerald-50 text-emerald-700",
        due_soon: "border-amber-200 bg-amber-50 text-amber-800",
        overdue: "border-red-200 bg-red-50 text-red-700",
        unknown: "border-slate-200 bg-slate-50 text-slate-600",
      })[status] || "border-slate-200 bg-slate-50 text-slate-600";
    },

    vaccinationDose(record) {
      if (record?.dose_amount === null || record?.dose_amount === undefined || record?.dose_amount === "") {
        return "Not provided";
      }

      return `${record.dose_amount}${record.dose_unit ? ` ${record.dose_unit}` : ""}`;
    },

    customerStatusLabel(customer) {
      if (customer?.isArchived) return "Archived";
      if (customer?.recordType === "unregistered") return "Unregistered";
      if (customer?.isActive) return "Active";
      return "Inactive";
    },
  };
}
