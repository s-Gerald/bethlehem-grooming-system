let adminCustomerPetFieldModulesReady;
function loadAdminCustomerPetFields() {
  return adminCustomerPetFieldModulesReady ??= Promise.all([
  import("../services/booking-draft-service.js?registration=fcfs-20261027-v1"),
  import("./breed-combobox.js?pet-fields=20261026-v1"),
  import("./breed-coat-catalogue.js?pet-fields=20261026-v1"),
  import("./breed-coat-combobox.js?pet-fields=20261026-v1"),
  import("./fixed-option-combobox.js"),
  import("./color-combobox.js?pet-care=20261027-v1"),
  import("./pet-weight-size.js?v=pet-weight-size-20261011-v3"),
]).then(([
  bookingDraft,
  breedCombobox,
  breedCoatCatalogue,
  breedCoatCombobox,
  fixedOptionCombobox,
  colorCombobox,
  petWeightSize,
]) => ({
  ...bookingDraft,
  ...breedCombobox,
  ...breedCoatCatalogue,
  ...breedCoatCombobox,
  ...fixedOptionCombobox,
  ...colorCombobox,
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
    successMessage: "",
    ownerActionsOpen: false,
    petDiscardOpen: false,

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
      action:       "",   // deactivate | reactivate | archive | unarchive | archive_unregistered | restore_unregistered
      customer:     null,
      title:        "",
      message:      "",
      confirmLabel: "",
      error:        "",
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

    },
    _petEditMarkupReady: null,
    _petHistoryRequest: 0,
    petHistories: {},
    detail: {record: null},
    detailAttachmentBusyId: null,
    detailAttachmentError: '',
    _petCaseRequests: {},
    _petObservationRequests: {},

    // ── Init ──────────────────────────────────────────────

    async init() {
      this.resetPetHistory();
      this._refreshPetHistory = () => {
        if (!this.petModal.open || document.visibilityState === 'hidden') return;
        this._petCaseRequests = {}; this._petObservationRequests = {};
        for (const state of Object.values(this.petHistories)) state.loadedAt = 0;
        if (!this.detail.record && ['medical', 'confinements'].includes(this.petModal.activeTab)) {
          void this.loadPetHistory(this.petModal.activeTab);
        }
      };
      window.addEventListener('clinic-case-updated', this._refreshPetHistory);
      window.addEventListener('focus', this._refreshPetHistory);
      window.addEventListener('admin-session:unlocked', this._refreshPetHistory);
      document.addEventListener('visibilitychange', this._refreshPetHistory);
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
      AccountEmail.showError(document.getElementById('addCustomerEmail'), '');
      this.$nextTick(() => {
        PhilippineMobile.bind(document.getElementById('addCustomerPhone'), {
          errorId: 'addCustomerPhoneError',
          onChange: value => { this.addCustomerModal.form.phone = value; },
          onError: message => {
            if (message) this.addCustomerModal.errors.phone = [message];
            else delete this.addCustomerModal.errors.phone;
          },
        }).clear();
        for (const [id, field] of [['addCustomerFirstName', 'first_name'], ['addCustomerLastName', 'last_name']]) {
          HumanName.bind(document.getElementById(id), message => {
            if (message) this.addCustomerModal.errors[field] = [message];
            else delete this.addCustomerModal.errors[field];
          });
        }
      });
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
      if (['first_name', 'last_name'].includes(field)) return;
      if (!this.addCustomerModal.errors[field]) return;
      const errors = { ...this.addCustomerModal.errors };
      delete errors[field];
      this.addCustomerModal.errors = errors;
    },

    validateAddCustomerForm() {
      const form = this.addCustomerModal.form;
      const errors = {};
      const phone = String(form.phone ?? '').trim();
      const email = String(form.email || "").trim();

      for (const field of ['first_name', 'last_name']) {
        form[field] = HumanName.normalize(form[field]);
        const error = HumanName.validate(form[field]);
        if (error) errors[field] = [error];
      }
      const phoneError = PhilippineMobile.validate(phone);
      if (phoneError) errors.phone = [phoneError];
      PhilippineMobile.showError(document.getElementById('addCustomerPhone'), phoneError);
      const emailError = AccountEmail.validate(email, { required: false });
      if (emailError) {
        errors.email = [emailError];
        AccountEmail.showError(document.getElementById('addCustomerEmail'), emailError);
      }

      this.addCustomerModal.errors = errors;
      if (Object.keys(errors).length) this.$nextTick(() => document.getElementById({ first_name: 'addCustomerFirstName', last_name: 'addCustomerLastName', phone: 'addCustomerPhone', email: 'addCustomerEmail' }[Object.keys(errors)[0]])?.focus());
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
        email: AccountEmail.normalize(form.email) || null,
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
        const errors = { ...(err.errors || {}) };
        if (AccountEmail.normalize(form.email) !== (payload.email || '')) delete errors.email;
        if (this.normalizeCustomerPhone(form.phone) !== payload.phone) delete errors.phone;
        this.addCustomerModal.errors = errors;
        if (Object.keys(errors).length) this.$nextTick(() => document.getElementById({ first_name: 'addCustomerFirstName', last_name: 'addCustomerLastName', phone: 'addCustomerPhone', email: 'addCustomerEmail' }[Object.keys(errors)[0]])?.focus());
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

    customerActions(customer) {
      if (!customer || !["admin", "staff"].includes(API.getUserRole?.())) return [];
      const manual = customer.recordType === "unregistered";
      if (!manual && !this.isAdmin) return [];
      if (customer.isArchived) return [{ action: manual ? "restore_unregistered" : "unarchive", label: "Restore customer", icon: "arrow-counter-clockwise" }];
      const archive = { action: manual ? "archive_unregistered" : "archive", label: "Archive customer", icon: "archive" };
      return manual ? [archive] : [
        { action: customer.isActive ? "deactivate" : "reactivate", label: customer.isActive ? "Deactivate account" : "Reactivate account", icon: customer.isActive ? "prohibit" : "check-circle" },
        archive,
      ];
    },

    toggleOwnerActions() {
      if (this.ownerActionsOpen) return this.closeOwnerActions();
      this.ownerActionsOpen = true;
      this.$nextTick(() => this.$refs.ownerActionsMenu.querySelector('[role="menuitem"]')?.focus());
    },

    closeOwnerActions(restoreFocus = true) {
      if (!this.ownerActionsOpen) return;
      this.ownerActionsOpen = false;
      if (restoreFocus) this.$refs.ownerActionsButton.focus();
    },

    navigateOwnerActions(event) {
      const items = Array.from(this.$refs.ownerActionsMenu.querySelectorAll('[role="menuitem"]'));
      const current = items.indexOf(document.activeElement);
      let next;
      if (event.key === "ArrowDown") next = (current + 1) % items.length;
      else if (event.key === "ArrowUp") next = (current - 1 + items.length) % items.length;
      else if (event.key === "Home") next = 0;
      else if (event.key === "End") next = items.length - 1;
      else if (event.key === "Tab") { this.closeOwnerActions(false); return; }
      else return;
      event.preventDefault();
      items[next]?.focus();
    },

    confirmAction(action, customer) {
      if (this.busyId !== null || !this.customerActions(customer).some(item => item.action === action)) return;
      this.closeOwnerActions(false);
      const restore = ["unarchive", "restore_unregistered"].includes(action);
      const archive = ["archive", "archive_unregistered"].includes(action);
      const label = restore ? "Restore customer" : archive ? "Archive customer" : action === "deactivate" ? "Deactivate account" : "Reactivate account";
      const message = restore
        ? (customer.recordType === "unregistered"
          ? "Return this manual customer to current records."
          : "Return this customer to current records. Their previous account access status will be preserved; unknown previous access stays deactivated.")
        : archive
          ? "Move this customer out of daily operations. Pet and transaction history will be preserved."
          : action === "deactivate" ? "Disable portal login and end existing sessions. You can reactivate the account later."
          : "Enable this customer's portal login.";
      this.confirmModal = { open: true, action, customer, title: label + "?", message, confirmLabel: label, error: "" };
      this.setCustomerDetailScrollLock(true);
      this.$nextTick(() => this.$refs.confirmCancel.focus());
    },

    closeConfirm() {
      if (this.busyId !== null) return;
      this.confirmModal.open = false;
      this.setCustomerDetailScrollLock(false);
      this.$nextTick(() => this.$refs.ownerActionsButton?.focus());
    },

    async executeAction() {
      const { action, customer } = this.confirmModal;
      if (!this.confirmModal.open || this.busyId !== null || !this.customerActions(customer).some(item => item.action === action)) return;
      this.busyId = customer.id;
      this.confirmModal.error = "";
      const apiMap = {
        deactivate: () => API.deactivateCustomer(customer.id),
        reactivate: () => API.reactivateCustomer(customer.id),
        archive: () => API.archiveCustomer(customer.id),
        archive_unregistered: () => API.archiveUnregisteredCustomer(customer.id),
        unarchive: () => API.unarchiveCustomer(customer.id),
        restore_unregistered: () => API.restoreUnregisteredCustomer(customer.id),
      };
      try {
        const data = await apiMap[action]();
        this.successMessage = data.message || "Customer updated.";
        this.confirmModal.open = false;
        if (this.detailModal.open) this.closeCustomerDetails();
        this.setCustomerDetailScrollLock(false);
        await this.loadCustomers();
      } catch (err) {
        this.confirmModal.error = err.message || "Action failed. Please try again.";
      } finally {
        this.busyId = null;
      }
    },

    handleCustomerEscape(event) {
      if (this.petDiscardOpen) this.keepPetEditing();
      else if (this.petModal.open) this.closePetDetails();
      else if (this.confirmModal.open) this.closeConfirm();
      else if (this.ownerActionsOpen) this.closeOwnerActions();
      else if (this.detailModal.open) this.closeCustomerDetails();
      else return;
      event.preventDefault();
      event.stopPropagation();
    },

    trapDialogFocus(event) {
      const dialog = this.petDiscardOpen ? this.$refs.petDiscardDialog
        : this.petModal.open ? this.$refs.petDialog
        : this.confirmModal.open ? this.$refs.confirmDialog
        : this.detailModal.open ? this.$refs.ownerDialog : null;
      if (!dialog) return;
      const items = Array.from(dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]'))
        .filter(item => !item.disabled && item.tabIndex >= 0 && item.getClientRects().length && !item.closest('[inert]'));
      const first = items[0], last = items[items.length - 1];
      if (!items.length) { event.preventDefault(); dialog.focus(); return; }
      if (!dialog.contains(document.activeElement) || (event.shiftKey && document.activeElement === first)) {
        event.preventDefault(); (event.shiftKey ? last : first).focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault(); first.focus();
      }
    },

    closePetDetails(discard = false) {
      if (this.petModal.saving) return;
      if (this.petModal.editing && !discard) {
        this.petDiscardOpen = true;
        this.$nextTick(() => this.$refs.petKeepEditing.focus());
        return;
      }
      adminCustomerPetFields?.controls.color.close();
      this.resetPetHistory();
      this.petModal.open = false;
      this.petModal.editing = false;
      this.petDiscardOpen = false;
      this.setCustomerDetailScrollLock(false);
      this.$nextTick(() => this._petReturnFocus?.isConnected ? this._petReturnFocus.focus({ preventScroll: true }) : this.$refs.ownerClose?.focus());
    },

    keepPetEditing() {
      this.petDiscardOpen = false;
      this.$nextTick(() => this.$refs.petClose.focus());
    },

    async openPetDetail(pet) {
      this.resetPetHistory();
      this._petReturnFocus = document.activeElement;
      this.closeOwnerActions(false);
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

      };
      this.setCustomerDetailScrollLock(true);
      this.$nextTick(() => { this.$refs.petBody.scrollTop = 0; this.$refs.petClose.focus(); });
      await this.loadPetProfile();
    },

    async loadPetProfile() {
      const petId = this.petModal.pet?.id, request = this._petHistoryRequest;
      if (!petId || this.petModal.creating || this.petModal.profileLoading && this._petProfilePending) return;
      this._petProfilePending = true;

      this.petModal.profileLoading = true;
      this.petModal.profileError = "";

      try {
        const data = await API.getAdminPetProfile(petId, "grooming");
        if (request !== this._petHistoryRequest || !this.petModal.open || String(this.petModal.pet?.id) !== String(petId)) return;

        this.petModal.groomingRecords = data.grooming_records || [];
        this.petModal.profileLoaded = true;
      } catch (error) {
        if (request !== this._petHistoryRequest || !this.petModal.open || String(this.petModal.pet?.id) !== String(petId)) return;
        this.petModal.profileError = error.message || "Pet history could not be loaded.";
      } finally {
        if (request === this._petHistoryRequest && this.petModal.open && String(this.petModal.pet?.id) === String(petId)) {
          this._petProfilePending = false;
          this.petModal.profileLoading = false;
        }
      }
    },

    destroy() {
      window.removeEventListener('clinic-case-updated', this._refreshPetHistory);
      window.removeEventListener('focus', this._refreshPetHistory);
      window.removeEventListener('admin-session:unlocked', this._refreshPetHistory);
      document.removeEventListener('visibilitychange', this._refreshPetHistory);
      this.resetPetHistory();
    },

    resetPetHistory() {
      this._petHistoryRequest++;
      this._petProfilePending = false;
      this.petHistories = Object.fromEntries(['medical', 'confinements'].map(tab => [tab,
        {rows: [], loading: false, loaded: false, error: '', page: 0, lastPage: 1, loadedAt: 0}]));
      this._petCaseRequests = {}; this._petObservationRequests = {};
      this.detail = {record: null};
      this.detailAttachmentError = ''; this.detailAttachmentBusyId = null;
    },

    async selectPetProfileTab(tab) {
      if (!['overview', 'grooming', 'medical', 'confinements'].includes(tab)) return;
      this.detail = {record: null};
      this.petModal.activeTab = tab;
      if (['medical', 'confinements'].includes(tab)) {
        const state = this.petHistories[tab];
        if (!state?.loaded || Date.now() - state.loadedAt >= 15000) await this.loadPetHistory(tab);
      }
    },

    petProfileTabKeydown(event) {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const tabs = ['overview', 'grooming', 'medical', 'confinements'];
      const current = tabs.indexOf(this.petModal.activeTab);
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? 3 : (current + (event.key === 'ArrowRight' ? 1 : 3)) % 4;
      void this.selectPetProfileTab(tabs[next]);
      event.currentTarget.querySelectorAll('[role="tab"]')[next]?.focus();
    },

    async loadPetHistory(tab, page = 1) {
      const state = this.petHistories[tab], request = this._petHistoryRequest, petId = this.petModal.pet?.id;
      if (!state || state.loading || !petId || !this.petModal.open) return;
      state.loading = true; state.error = ''; state.retryPage = page;
      try {
        const data = await API.adminGetPetProfile(petId, tab === 'medical' ? 'clinical' : 'confinement', page, 'history');
        if (request !== this._petHistoryRequest || !this.petModal.open) return;
        state.rows = page === 1 ? data.data : [...state.rows, ...data.data];
        state.page = data.current_page; state.lastPage = data.last_page;
        state.loaded = true; state.loadedAt = Date.now();
      } catch (error) {
        if (request === this._petHistoryRequest) state.error = error.message || 'Could not load patient history.';
      } finally { if (request === this._petHistoryRequest) state.loading = false; }
    },

    historyCaseId(record) { return clinicHistoryCaseId(record); },
    historyCaseLabel(record) { return record?.appointment_reference || record?.appointment?.appointment_reference || 'Historical record'; },
    historyService(record) { return clinicHistoryService(record); },
    historyPrice(value) { return clinicHistoryPrice(value); },
    historyTime(value) { return clinicHistoryTime(value); },
    historyDuration(record) { return clinicConfinementDuration(record.admitted_at, record.discharged_at || Date.now()); },
    fmtDate(value) { return clinicHistoryDate(value); },
    formatFileSize(bytes) { return formatClinicFileSize(bytes); },
    caseTreatmentFields(record) {
      return clinicTreatmentDetails(record).filter(field => !['Treatment', 'Case', 'Published', 'Published By', 'Voided', 'Voided By'].includes(field.label))
        .map(field => field.label === 'Status' ? {...field, value: record.state === 'published' ? 'Administered' : field.value} : field)
        .filter(field => field.value != null && field.value !== '' && field.value !== '—');
    },
    confinementDetailFields(record) { return clinicConfinementFields(record, null); },
    get petHistoryTitle() { return this.detail.kind === 'confinement' ? 'Confinement' : this.detail.caseId ? 'Case Details' : 'Treatment Record'; },
    get petHistorySubtitle() {
      const record = this.detail.case || this.detail.record;
      if (!record) return '';
      return [this.detail.caseId ? this.historyCaseLabel(record) : null,
        this.fmtDate(record.appointment_date || record.treatment?.administered_date || record.appointment?.appointment_date)].filter(Boolean).join(' · ');
    },
    get petHistoryStatus() {
      const status = this.detail.case?.status;
      return ({for_payment: 'For Payment', completed: 'Completed'})[status] || '';
    },

    async viewPetHistory(record, trigger = document.activeElement, kind = 'medical') {
      this._petHistoryScroll = this.$refs.petBody?.scrollTop || 0;
      this._petHistoryTrigger = trigger?.querySelector?.('button') || trigger;
      const caseId = this.historyCaseId(record);
      this.detail = {record, kind, caseId, case: null, medical: null, treatments: [], services: [], confinement: null,
        loading: !!caseId && kind !== 'confinement', error: '', observations: [], observationsLoading: false, observationsError: '', page: 0, lastPage: 1};
      this.detailAttachmentError = ''; this.detailAttachmentBusyId = null;
      this.$nextTick(() => { if (this.$refs.petBody) this.$refs.petBody.scrollTop = 0; this.$refs.petHistoryBack?.focus(); });
      if (kind === 'confinement') {
        this.detail.confinement = record;
        this.detail.case = record.appointment;
        await this.loadHistoryObservations();
      } else if (!caseId) {
        this.detail.treatments = [record.treatment];
      } else await this.loadCaseDetail();
    },

    async loadCaseDetail() {
      const detail = this.detail, request = this._petHistoryRequest, petId = this.petModal.pet?.id;
      if (!detail.caseId || detail.pending) return;
      detail.loading = true; detail.pending = true; detail.error = '';
      const cache = this._petCaseRequests;
      let entry = cache[detail.caseId];
      try {
        if (!entry || !entry.pending && Date.now() - entry.at >= 15000) {
          entry = {pending: true};
          entry.promise = API.adminGetPetCaseDetails(petId, detail.caseId, 'history').then(data => {
            entry.pending = false; entry.at = Date.now(); return data;
          });
          cache[detail.caseId] = entry;
        }
        const data = await entry.promise;
        if (request !== this._petHistoryRequest || this.detail !== detail || !this.petModal.open) return;
        Object.assign(detail, {case: data.case, medical: data.medical_record, treatments: data.treatments || [], services: data.services || []});
      } catch (error) {
        if (cache[detail.caseId] === entry) delete cache[detail.caseId];
        if (request === this._petHistoryRequest && this.detail === detail) detail.error = error.message || 'Could not load case details.';
      } finally { if (request === this._petHistoryRequest && this.detail === detail) { detail.loading = false; detail.pending = false; } }
    },

    async loadHistoryObservations(page = 1) {
      const detail = this.detail, request = this._petHistoryRequest, cache = this._petObservationRequests;
      if (!detail.confinement || detail.observationsLoading) return;
      detail.observationsLoading = true; detail.observationsError = '';
      const key = `${detail.caseId}:${page}`;
      let entry = cache[key];
      try {
        if (!entry || !entry.pending && Date.now() - entry.at >= 15000) {
          entry = {pending: true};
          entry.promise = API.getClinicConfinementObservations(detail.caseId, page).then(data => {
            entry.pending = false; entry.at = Date.now(); return data;
          });
          cache[key] = entry;
        }
        const data = await entry.promise;
        if (request !== this._petHistoryRequest || this.detail !== detail || !this.petModal.open) return;
        detail.observations = page === 1 ? data.data : [...detail.observations, ...data.data];
        detail.page = data.current_page; detail.lastPage = data.last_page;
      } catch (error) {
        if (cache[key] === entry) delete cache[key];
        if (request === this._petHistoryRequest && this.detail === detail) detail.observationsError = error.message || 'Could not load monitoring history.';
      } finally { if (request === this._petHistoryRequest && this.detail === detail) detail.observationsLoading = false; }
    },

    backToPetHistory() {
      this.detail = {record: null};
      this.$nextTick(() => {
        if (this.$refs.petBody) this.$refs.petBody.scrollTop = this._petHistoryScroll;
        this._petHistoryTrigger?.focus({preventScroll: true});
      });
    },

    async downloadDetailAttachment(attachment) {
      const detail = this.detail;
      this.detailAttachmentBusyId = attachment.id; this.detailAttachmentError = '';
      try { await downloadClinicAttachment(detail.caseId, attachment); }
      catch (error) { if (this.detail === detail) this.detailAttachmentError = error.message || 'Failed to download attachment.'; }
      finally { if (this.detail === detail) this.detailAttachmentBusyId = null; }
    },

    async openAddPetForCustomer(customer) {
      this.resetPetHistory();
      if (!customer || customer.recordType !== "unregistered" || customer.isArchived) return;

      this._petReturnFocus = document.activeElement;
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

      this.$nextTick(() => { this.$refs.petBody.scrollTop = 0; this.$refs.petClose.focus(); });
      if (!await this.initializePetFormFields()) {
        this.petModal.saveError = "Pet controls could not be loaded. Please refresh and try again.";
        return;
      }

      const { tools, elements, controls } = adminCustomerPetFields;
      controls.species.reset();
      controls.gender.reset();
      controls.color.reset();
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
            const response = await fetch('./pet-edit-form.html?v=20261014&icons=20261018&pet-fields=20261026-v1&pet-care=20261027-v1');
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
          color: document.getElementById("adminPetColor"),
        };
        const controls = {
          color: tools.createColorCombobox({
            input: elements.color,
            listbox: document.getElementById("adminPetColorOptions"),
            errorElement: document.getElementById("adminPetColorError"),
          }),
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
        elements.color.addEventListener("change", () => {
          this.petModal.form.color = elements.color.value;
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
      controls.color.setValue(p.color, { preserveLegacy: true });
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
      if (this.petModal.saving) return;
      adminCustomerPetFields?.controls.color.close();
      if (this.petModal.creating) return this.closePetDetails(true);
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
        controls.color.showValidation();
        controls.breed.showValidation();
        controls.furType.showValidation();
        const weightMessage = tools.getWeightFieldValidationMessage(elements.weight)
          || tools.getWeightValidationMessage(payload.species, payload.weight);
        if (weightMessage) {
          tools.showWeightValidationInField(elements.weight, weightMessage);
        }
        if (validationMessage === controls.color.getValidationMessage()) {
          elements.color.focus();
        } else if (validationMessage === controls.furType.getValidationMessage()) {
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
        if (err.errors?.color?.[0]) {
          adminCustomerPetFields.controls.color.showValidation(err.errors.color[0]);
          adminCustomerPetFields.elements.color.focus();
          return;
        }
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
        color: controls.color.getValue() || null,
        medical_conditions: String(form.medical_conditions || "").trim() || null,
      };
    },

    validatePetEditPayload(payload) {
      const { tools, elements, controls } = adminCustomerPetFields;
      if (!payload.pet_name) return "Pet name is required.";

      const colorMessage = controls.color.getValidationMessage();
      if (colorMessage) return colorMessage;
      if ((payload.medical_conditions || "").length > 1000) return "Care notes must be 1,000 characters or fewer.";

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
      this._ownerReturnFocus = document.activeElement;
      const request = this._detailRequest = (this._detailRequest || 0) + 1;
      this.ownerActionsOpen = false;
      this.setCustomerDetailScrollLock(true);
      this.detailModal = {
        open:     true,
        customer: { ...customer, pets: [] },
        loading:  true,
        error:    "",
      };

      this.$nextTick?.(() => { this.$refs.ownerBody.scrollTop = 0; this.$refs.ownerClose.focus(); });
      try {
        const [data, format] = await Promise.all([
          customer.recordType === "unregistered"
            ? API.getUnregisteredCustomerDetails(customer.id)
            : API.getCustomerDetails(customer.id),
          import("../services/booking-format-service.js?v=pet-weight-format-20261019"),
        ]);
        if (request !== this._detailRequest || !this.detailModal.open) return;
        adminCustomerFormatWeight = format.formatSavedWeight;
        this.detailModal.customer = data.customer || this.detailModal.customer;
      } catch (err) {
        if (request === this._detailRequest) this.detailModal.error = err.message || "Failed to load customer details.";
      } finally {
        if (request === this._detailRequest) this.detailModal.loading = false;
      }
    },

    closeCustomerDetails() {
      if (this.petModal.open || this.confirmModal.open) return;
      this._detailRequest = (this._detailRequest || 0) + 1;
      this.ownerActionsOpen = false;
      this.detailModal.open = false;
      this.detailModal.error = "";
      this.setCustomerDetailScrollLock(false);
      this.$nextTick?.(() => this._ownerReturnFocus?.isConnected && this._ownerReturnFocus.focus({ preventScroll: true }));
    },

    setCustomerDetailScrollLock(locked) {
      locked = locked || this.detailModal.open || this.petModal.open || this.confirmModal.open || this.addCustomerModal.open;
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
      return PhilippineMobile.format(value, '—');
    },

    normalizeCustomerPhone(value) {
      return PhilippineMobile.normalize(value);
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

    categoryLabel(status) {
      return ({ active: "Portal Accounts", unregistered: "Walk-in / Manual", inactive: "Deactivated", archived: "Archived" })[status] || "Customers";
    },

    categoryDescription() {
      return ({ active: "Customers with an enabled online dashboard account.", unregistered: "Customer records created by staff without a portal account.", inactive: "Portal accounts with login access disabled.", archived: "Records retained for history and reference." })[this.statusFilter];
    },

    customerStatusLabel(customer) {
      const type = customer?.recordType === "unregistered" ? "Manual record" : "Portal account";
      if (customer?.isArchived) return "Archived \u00b7 " + type;
      if (customer?.recordType === "unregistered") return type;
      return type + (customer?.isActive ? " \u00b7 Enabled" : " \u00b7 Deactivated");
    },
  };
}
