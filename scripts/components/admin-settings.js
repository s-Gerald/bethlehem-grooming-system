function adminSettings() {
  const currentRole = typeof API !== "undefined" && typeof API.getUserRole === "function"
    ? API.getUserRole()
    : "admin";
  const defaultAvailability = {
    clinic: {
      open_time: "08:00",
      close_time: "17:00",
    },
    grooming: {
      open_time: "08:00",
      close_time: "17:00",
    },
  };

  const clone = (value) => JSON.parse(JSON.stringify(value));

  return {
    isAdmin: currentRole === "admin",
    isStaff: currentRole === "staff",
    activeSettingsTab: "general",
    appointmentReminders: true,
    paymentReceipt: true,
    availabilityService: "clinic",
    availability: clone(defaultAvailability),
    savedAvailability: clone(defaultAvailability),
    availabilityLoading: true,
    availabilityLoaded: false,
    availabilitySaving: false,
    availabilityError: "",
    availabilitySuccess: "",
    groomersOnDuty: 0,
    petGroomers: [],
    petGroomerEditor: null,
    groomersOnDutySaving: false,
    groomersOnDutyError: "",
    adminAccount: {
      email: "Admin email",
      username: "",
    },
    adminPassword: {
      username: "",
      current: "",
      password: "",
      confirmation: "",
      showCurrent: false,
      showPassword: false,
      showConfirmation: false,
      submitting: false,
      error: "",
      success: "",
    },
    staffAccount: {
      fullName: "",
      roleLabel: "Staff",
      username: "",
      email: "",
      statusLabel: "",
    },
    staffPassword: {
      current: "",
      password: "",
      confirmation: "",
      showCurrent: false,
      showPassword: false,
      showConfirmation: false,
      submitting: false,
      error: "",
      success: "",
    },
    staffAccounts: [],
    staffLoading: true,
    staffLoaded: false,
    _securityRequest: null,
    _securityRevision: 0,
    _staffAccountsRequest: null,
    _staffAccountsEpoch: null,
    staffError: "",
    staffFilter: "all",
    staffNotice: "",
    addStaffModal: {
      open: false,
      step: "role",
      roleStage: "main",
      staffType: "",
      staffSubrole: "",
      firstName: "",
      lastName: "",
      email: "",
      submitting: false,
      error: "",
    },
    staffStatusModal: {
      open: false,
      staff: null,
      targetActive: false,
      password: "",
      showPassword: false,
      busy: false,
      error: "",
    },
    staffSetupModal: { open: false, staff: null, password: "", showPassword: false, busy: false, error: "" },
    staffSetupBusyId: null,
    staffDetailsModal: {
      open: false,
      staff: null,
      selectedRole: "",
      password: "",
      showPassword: false,
      busy: false,
      error: "",
    },
    securityVerification: {
      open: false,
      changeId: null,
      purpose: "",
      targetName: "",
      email: "",
      digits: ["", "", "", "", "", ""],
      submitting: false,
      resending: false,
      error: "",
      notice: "",
    },

    get activeAvailability() {
      return this.availability[this.availabilityService];
    },

    get activeAvailabilityLabel() {
      return this.availabilityService === "clinic" ? "Clinic" : "Grooming";
    },

    get activeStaffCount() {
      return this.staffAccounts.filter((staff) => staff.state === "active").length;
    },

    get pendingStaffCount() {
      return this.staffAccounts.filter((staff) => staff.state === "pending").length;
    },

    get deactivatedStaffCount() {
      return this.staffAccounts.filter((staff) => staff.state === "deactivated").length;
    },

    get filteredStaffAccounts() {
      return this.staffAccounts.filter((staff) => this.staffFilter === "all" || staff.state === this.staffFilter);
    },

    get securityCodeComplete() {
      return this.securityVerification.digits.every((digit) => /^\d$/.test(digit));
    },

    async init() {
      // settings.html?tab=security opens staff accounts (vaccination form's "Add veterinarian").
      if (this.isAdmin && new URLSearchParams(window.location.search).get("tab") === "security") {
        this.activeSettingsTab = "security";
      }

      await Promise.all([
        this.loadAvailability(),
        this.activeSettingsTab === "security" ? this.selectSettingsTab("security") : Promise.resolve(),
      ]);

    },

    selectSettingsTab(tab) {
      this.activeSettingsTab = tab;
      if (tab !== "security" || this.staffLoaded) return Promise.resolve();
      if (this._securityRequest) return this._securityRequest;
      this._securityRequest = (this.isAdmin ? this.loadSecurityAccounts() : this.loadOwnAccount())
        .finally(() => { this._securityRequest = null; });
      return this._securityRequest;
    },

    normalizeAvailability(payload) {
      const normalized = clone(defaultAvailability);

      for (const service of ["clinic", "grooming"]) {
        const settings = payload?.[service];
        if (!settings) continue;

        normalized[service] = {
          open_time: settings.open_time || normalized[service].open_time,
          close_time: settings.close_time || normalized[service].close_time,
        };
      }

      return normalized;
    },

    async loadAvailability() {
      this.availabilityLoading = true;
      this.availabilityError = "";

      try {
        const response = await API.getAvailabilitySettings();
        this.availability = this.normalizeAvailability(response.availability);
        this.savedAvailability = clone(this.availability);
        this.groomersOnDuty = Number(response.groomers_on_duty) || 0;
        this.petGroomers = response.pet_groomers || [];
        this.availabilityLoaded = true;
      } catch (error) {
        this.availabilityError =
          error.message || "Could not load availability settings.";
      } finally {
        this.availabilityLoading = false;
      }
    },

    accountRoleLabel(account) {
      if (account?.staff_subrole === "veterinarian") return "Veterinarian";
      if (account?.staff_subrole === "clinic_receptionist") return "Clinic Receptionist";
      if (account?.staff_subrole === "grooming_receptionist") return "Grooming Receptionist";
      if (account?.staff_type === "clinic") return "Clinic Staff";
      return account?.role === "admin" ? "Administrator" : "Staff";
    },

    async loadOwnAccount() {
      this.staffLoading = !this.staffLoaded;
      try {
        const response = await API.getSettingsSecurityAccount();
        const account = response?.account || {};
        this.staffAccount = {
          fullName: `${account.first_name || ""} ${account.last_name || ""}`.trim() || "Staff account",
          roleLabel: this.accountRoleLabel(account),
          username: account.username || "No username",
          email: account.email || "No email address",
          statusLabel: account.is_active && !account.is_archived ? "Active" : "Inactive",
        };
        this.staffLoaded = true;
      } catch (error) {
        this.staffPassword.error = error.message || "Could not load your account information.";
      } finally {
        this.staffLoading = false;
      }
    },

    editPetGroomer(person = null) {
      this.petGroomerEditor = { id: person?.id ?? null, first_name: person?.first_name || "" };
      this.groomersOnDutyError = "";
      this.$nextTick(() => document.getElementById("petGroomerFirstName")?.focus());
    },

    async savePetGroomer(data, id = null, toggle = null) {
      if (this.groomersOnDutySaving) return;
      this.groomersOnDutySaving = true;
      this.groomersOnDutyError = "";
      try {
        const response = await API.adminSavePetGroomer(data, id);
        this.petGroomers = response.pet_groomers;
        this.groomersOnDuty = response.groomers_on_duty;
        if (!toggle) this.petGroomerEditor = null;
      } catch (error) {
        this.groomersOnDutyError = error.message || "Could not save Pet Groomer.";
        if (toggle) toggle.checked = this.petGroomers.find((person) => person.id === id)?.on_duty || false;
      } finally {
        this.groomersOnDutySaving = false;
      }
    },

    loadSecurityAccounts() {
      const epoch = API.getAdminDataRevision?.();
      if (this._staffAccountsRequest && this._staffAccountsEpoch === epoch) return this._staffAccountsRequest;
      this._staffAccountsEpoch = epoch;
      const revision = ++this._securityRevision;
      this.staffLoading = !this.staffLoaded;
      this.staffError = "";
      const pending = API.getAdminSecurityAccounts().then(response => {
        if (revision !== this._securityRevision) return;
        this.adminAccount = {
          email: response?.admin?.email || "Admin email",
          username: response?.admin?.username || "",
        };
        this.adminPassword.username = this.adminAccount.username;
        this.staffAccounts = (response?.staff || []).map((staff) => {
          const fullName = `${staff.first_name || ""} ${staff.last_name || ""}`.trim()
            || "Staff account";
          const setupRequired = Boolean(staff.password_setup_required);
          const state = setupRequired ? "pending" : staff.is_active && !staff.is_archived ? "active" : "deactivated";
          const active = state === "active";
          return {
            id: staff.user_id,
            fullName,
            initials: `${staff.first_name?.charAt(0) || "S"}${staff.last_name?.charAt(0) || ""}`.toUpperCase(),
            username: setupRequired ? "" : staff.username || "",
            email: staff.email || "",
            ...this.staffRoleFields(staff),
            active,
            state,
            setupRequired,
            setupLinkExpired: Boolean(staff.setup_link_expired),
            statusLabel: state === "pending" ? "Pending setup" : state === "active" ? "Active" : "Deactivated",
            statusClass: state === "pending" ? "bg-amber-50 text-amber-700" : state === "active" ? "bg-emerald-50 text-emerald-700" : "bg-slate-100 text-slate-500",
          };
        });
        this.staffLoaded = true;
      }).catch(error => {
        if (revision !== this._securityRevision) return;
        this.staffError = error.message || "Could not load security accounts.";
      }).finally(() => {
        if (revision === this._securityRevision) {
          this.staffLoading = false;
          this._staffAccountsRequest = null;
        }
      });
      this._staffAccountsRequest = pending;
      return pending;
    },

    selectAvailabilityService(service) {
      if (!["clinic", "grooming"].includes(service)) return;

      this.availabilityService = service;
      this.availabilityError = "";
      this.availabilitySuccess = "";
    },

    resetAvailability() {
      this.availability[this.availabilityService] = clone(
        this.savedAvailability[this.availabilityService],
      );
      this.availabilityError = "";
      this.availabilitySuccess = "";
    },

    async saveAvailability() {
      const settings = this.activeAvailability;
      this.availabilityError = "";
      this.availabilitySuccess = "";

      if (
        !settings.open_time ||
        !settings.close_time
      ) {
        this.availabilityError = "Complete all availability time fields.";
        return;
      }

      this.availabilitySaving = true;

      try {
        const response = await API.adminUpdateAvailability({
          service: this.availabilityService,
          open_time: settings.open_time,
          close_time: settings.close_time,
        });

        this.availability = this.normalizeAvailability(response.availability);
        this.savedAvailability = clone(this.availability);
        this.availabilitySuccess =
          response.message || `${this.activeAvailabilityLabel} availability updated.`;
      } catch (error) {
        this.availabilityError =
          error.message || "Could not save availability settings.";
      } finally {
        this.availabilitySaving = false;
      }
    },

    passwordValidationError(password, confirmation, required = true) {
      if (!required && !password && !confirmation) return "";
      if (!password || !confirmation) {
        return "Complete both new password fields.";
      }
      if (password !== confirmation) {
        return "The new passwords do not match.";
      }
      if (
        password.length < 12 ||
        !/[a-z]/.test(password) ||
        !/[A-Z]/.test(password) ||
        !/\d/.test(password) ||
        !/[^A-Za-z0-9]/.test(password)
      ) {
        return "Use at least 12 characters with uppercase, lowercase, a number, and a symbol.";
      }

      return "";
    },

    firstApiError(error) {
      const validationErrors = error?.errors || {};
      const firstMessages = Object.values(validationErrors)[0];
      return Array.isArray(firstMessages) && firstMessages[0]
        ? firstMessages[0]
        : error?.message || "The request could not be completed.";
    },

    async submitAdminPassword() {
      this.adminPassword.error = "";
      this.adminPassword.success = "";

      if (!this.adminPassword.current) {
        this.adminPassword.error = "Enter your current password.";
        return;
      }

      const username = this.adminPassword.username.trim();
      const usernameChanged = username !== this.adminAccount.username;
      const validationError = this.passwordValidationError(
        this.adminPassword.password,
        this.adminPassword.confirmation,
        false,
      );
      if (validationError) {
        this.adminPassword.error = validationError;
        return;
      }
      if (!usernameChanged && !this.adminPassword.password) {
        this.adminPassword.error = "Enter a different username or a new password.";
        return;
      }

      this.adminPassword.submitting = true;
      try {
        const response = await API.requestAdminCredentialChange({
          current_password: this.adminPassword.current,
          username,
          password: this.adminPassword.password || null,
          password_confirmation: this.adminPassword.confirmation || null,
        });
        this.adminPassword.current = "";
        this.adminPassword.password = "";
        this.adminPassword.confirmation = "";
        this.adminPassword.username = this.adminAccount.username;
        this.openSecurityVerification(response);
      } catch (error) {
        this.adminPassword.error = this.firstApiError(error);
      } finally {
        this.adminPassword.submitting = false;
      }
    },

    async submitStaffPassword() {
      this.staffPassword.error = "";
      this.staffPassword.success = "";

      if (!this.staffPassword.current) {
        this.staffPassword.error = "Enter your current password.";
        return;
      }

      const validationError = this.passwordValidationError(
        this.staffPassword.password,
        this.staffPassword.confirmation,
      );
      if (validationError) {
        this.staffPassword.error = validationError;
        return;
      }
      if (this.staffPassword.current === this.staffPassword.password) {
        this.staffPassword.error = "Choose a password that is different from your current password.";
        return;
      }

      this.staffPassword.submitting = true;
      try {
        const response = await API.requestStaffPasswordChange({
          current_password: this.staffPassword.current,
          password: this.staffPassword.password,
          password_confirmation: this.staffPassword.confirmation,
        });
        this.staffPassword.current = "";
        this.staffPassword.password = "";
        this.staffPassword.confirmation = "";
        this.openSecurityVerification(response);
      } catch (error) {
        this.staffPassword.error = this.firstApiError(error);
      } finally {
        this.staffPassword.submitting = false;
      }
    },

    emptyAddStaffModal(open = false) {
      return {
        open,
        step: "role",
        roleStage: "main",
        staffType: "",
        staffSubrole: "",
        firstName: "",
        lastName: "",
        email: "",
        submitting: false,
        error: "",
      };
    },

    openAddStaffAccount() {
      this.staffNotice = "";
      this.addStaffModal = this.emptyAddStaffModal(true);

    },

    closeAddStaffAccount() {
      if (this.addStaffModal.submitting) return;
      this.addStaffModal = this.emptyAddStaffModal(false);
    },

    handleAddStaffClose() {
      if (this.addStaffModal.step === "role"
        && this.addStaffModal.roleStage === "clinic") {
        this.addStaffModal.roleStage = "main";
        this.addStaffModal.staffType = "";
        this.addStaffModal.staffSubrole = "";
        this.addStaffModal.error = "";

        return;
      }

      this.closeAddStaffAccount();
    },

    chooseStaffType(staffType) {
      if (!["clinic", "grooming"].includes(staffType)) return;
      this.addStaffModal.staffType = staffType;
      this.addStaffModal.staffSubrole = staffType === "grooming" ? "grooming_receptionist" : "";

      if (staffType === "clinic") {
        this.addStaffModal.roleStage = "clinic";
        this.addStaffModal.error = "";

        return;
      }

      this.addStaffModal.step = "details";
      this.addStaffModal.error = "";

      this.$nextTick(() => document.getElementById("newStaffFirstName")?.focus());
    },

    chooseClinicSubrole(staffSubrole) {
      if (!["veterinarian", "clinic_receptionist"].includes(staffSubrole)) return;
      this.addStaffModal.staffType = "clinic";
      this.addStaffModal.staffSubrole = staffSubrole;
      this.addStaffModal.step = "details";
      this.addStaffModal.error = "";

      this.$nextTick(() => document.getElementById("newStaffFirstName")?.focus());
    },

    returnToStaffType() {
      if (this.addStaffModal.submitting) return;
      this.addStaffModal.step = "role";
      this.addStaffModal.roleStage = this.addStaffModal.staffType === "clinic" ? "clinic" : "main";
      this.addStaffModal.error = "";

    },

    async requestNewStaffAccount() {
      this.addStaffModal.error = "";

      const firstName = this.addStaffModal.firstName.trim();
      const lastName = this.addStaffModal.lastName.trim();
      if (!firstName || !lastName) {
        this.addStaffModal.error = "Enter the staff first and last name.";
        return;
      }

      const staffSubrole = this.addStaffModal.staffSubrole;
      if (this.addStaffModal.staffType === "clinic"
        && !["veterinarian", "clinic_receptionist"].includes(staffSubrole)) {
        this.addStaffModal.error = "Choose a Clinic Staff sub-role.";
        return;
      }

      const email = this.addStaffModal.email.trim().toLowerCase();
      if (!email) {
        this.addStaffModal.error = "Enter the staff email address.";
        return;
      }
      if (!/^[^\s@]+@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+$/.test(email)) {
        this.addStaffModal.error = "Enter a valid email address, such as name@example.com.";
        return;
      }

      this.addStaffModal.submitting = true;
      try {
        await API.requestStaffAccount({
          staff_type: this.addStaffModal.staffType,
          staff_subrole: staffSubrole,
          first_name: firstName,
          last_name: lastName,
          email,
        });
        this.addStaffModal.step = "success";
        await this.loadSecurityAccounts();

      } catch (error) {
        this.addStaffModal.error = this.firstApiError(error);
      } finally {
        this.addStaffModal.submitting = false;
      }
    },

    openStaffStatusModal(staff) {
      if (staff.setupRequired) return;
      this.staffStatusModal = {
        open: true,
        staff,
        targetActive: !staff.active,
        password: "",
        showPassword: false,
        busy: false,
        error: "",
      };

      this.$nextTick(() => document.getElementById("staffStatusAdminPassword")?.focus());
    },

    staffRoleFields(staff) {
      const roleLabel = staff.staff_type === "clinic" ? "Clinic Staff"
        : this.accountRoleLabel(staff);
      const subroleLabel = staff.staff_subrole === "veterinarian" ? "Veterinarian"
        : staff.staff_subrole === "clinic_receptionist" ? "Clinic Receptionist" : "";
      return {
        staffType: staff.staff_type || "",
        staffSubrole: staff.staff_subrole || "",
        roleLabel,
        subroleLabel,
      };
    },

    get savedStaffRole() {
      const staff = this.staffDetailsModal.staff;
      return staff?.staffSubrole || "";
    },

    get staffRoleChangePending() {
      return Boolean(this.staffDetailsModal.staff && this.staffDetailsModal.selectedRole
        && this.staffDetailsModal.selectedRole !== this.savedStaffRole);
    },

    openStaffDetails(staff) {
      if (!staff) return;

      this.staffDetailsModal = { open: true, staff, selectedRole: "", password: "", showPassword: false, busy: false, error: "" };
      this.staffDetailsModal.selectedRole = this.savedStaffRole;

    },

    closeStaffDetails() {
      if (this.staffDetailsModal.busy) return;
      this.cancelStaffRoleChange();
      this.staffDetailsModal.open = false;
      this.staffDetailsModal.staff = null;
      this.staffDetailsModal.selectedRole = "";
    },

    changeStaffRole() {
      this.staffDetailsModal.error = "";
      if (!this.staffRoleChangePending) this.cancelStaffRoleChange();
    },

    cancelStaffRoleChange() {
      if (this.staffDetailsModal.busy) return;
      this.staffDetailsModal.selectedRole = this.savedStaffRole;
      this.staffDetailsModal.password = "";
      this.staffDetailsModal.showPassword = false;
      this.staffDetailsModal.error = "";
    },

    async saveStaffRoleChange() {
      const modal = this.staffDetailsModal;
      if (!this.staffRoleChangePending || !modal.password || modal.busy) return;

      modal.error = "";
      modal.busy = true;
      try {
        const response = await API.updateStaffAccountRole(modal.staff.id, modal.selectedRole, modal.password);
        Object.assign(modal.staff, this.staffRoleFields(response.staff));
        modal.busy = false;
        this.cancelStaffRoleChange();
        window.showSuccessToast("Role updated successfully");
      } catch (error) {
        modal.error = this.firstApiError(error);
      } finally {
        modal.busy = false;
      }
    },

    closeStaffStatusModal() {
      if (this.staffStatusModal.busy) return;
      this.staffStatusModal = {
        open: false,
        staff: null,
        targetActive: false,
        password: "",
        showPassword: false,
        busy: false,
        error: "",
      };
    },

    async updateStaffStatus() {
      const { staff, targetActive } = this.staffStatusModal;
      if (!staff || this.staffStatusModal.busy) return;

      this.staffStatusModal.error = "";
      if (!this.staffStatusModal.password) {
        this.staffStatusModal.error = "Enter your admin password to confirm.";
        return;
      }
      this.staffStatusModal.busy = true;
      try {
        await API.updateStaffAccountStatus(staff.id, targetActive, this.staffStatusModal.password);
        this.staffStatusModal.busy = false;
        this.closeStaffStatusModal();
        const staffName = staff.fullName && staff.fullName !== "Staff account"
          ? staff.fullName
          : staff.subroleLabel || staff.roleLabel || "Staff account";
        this.showStaffActionToast(targetActive
          ? `${staffName} was reactivated and can sign in again.`
          : `${staffName} was deactivated and signed out.`);
        await this.loadSecurityAccounts();
      } catch (error) {
        this.staffStatusModal.error = this.firstApiError(error);
        this.staffStatusModal.busy = false;
      }
    },

    async resendStaffSetup(staff) {
      if (!staff?.setupRequired || this.staffSetupBusyId) return;
      this.staffSetupBusyId = staff.id;
      this.staffNotice = "";
      this.staffError = "";
      try {
        const response = await API.resendStaffSetupEmail(staff.id);
        this.staffNotice = response.message;
        await this.loadSecurityAccounts();
        if (this.staffDetailsModal.open) {
          this.staffDetailsModal.staff = this.staffAccounts.find((account) => account.id === staff.id) || null;
        }
      } catch (error) {
        this.staffNotice = this.firstApiError(error);
      } finally {
        this.staffSetupBusyId = null;
      }
    },

    openStaffSetupModal(staff) {
      if (!staff?.setupRequired) return;
      this.closeStaffDetails();
      this.staffSetupModal = { open: true, staff, password: "", showPassword: false, busy: false, error: "" };

    },

    closeStaffSetupModal() {
      if (this.staffSetupModal.busy) return;
      this.staffSetupModal = { open: false, staff: null, password: "", showPassword: false, busy: false, error: "" };
    },

    async cancelStaffSetup() {
      const staff = this.staffSetupModal.staff;
      if (!staff || this.staffSetupModal.busy) return;
      this.staffSetupModal.error = "";
      if (!this.staffSetupModal.password) {
        this.staffSetupModal.error = "Enter your admin password to confirm.";
        return;
      }
      this.staffSetupModal.busy = true;
      try {
        const response = await API.cancelStaffSetup(staff.id, this.staffSetupModal.password);
        this.staffSetupModal.busy = false;
        this.closeStaffSetupModal();
        this.showStaffActionToast(response.message);
        await this.loadSecurityAccounts();
      } catch (error) {
        this.staffSetupModal.error = this.firstApiError(error);
        this.staffSetupModal.busy = false;
      }
    },

    showStaffActionToast(message) {
      this.$nextTick(() => {
        // Allow the existing modal's 150ms closing transition to finish.
        setTimeout(() => window.showSuccessToast(message), 200);
      });
    },

    closeSecurityModals() {
      if (this.staffDetailsModal.open) this.closeStaffDetails();
      if (this.addStaffModal.open) this.closeAddStaffAccount();
      if (this.staffStatusModal.open) this.closeStaffStatusModal();
      if (this.staffSetupModal.open) this.closeStaffSetupModal();
      if (this.securityVerification.open) this.closeSecurityVerification();
    },

    emptySecurityVerification(open = false, challenge = {}) {
      return {
        open,
        changeId: challenge.change_id || null,
        purpose: challenge.purpose || "",
        targetName: challenge.target_name || "",
        email: challenge.confirmation_email || "",
        digits: ["", "", "", "", "", ""],
        submitting: false,
        resending: false,
        error: "",
        notice: "",
      };
    },

    openSecurityVerification(challenge) {
      this.securityVerification = this.emptySecurityVerification(true, challenge);

      this.$nextTick(() => document.getElementById("securityCodeDigit0")?.focus());
    },

    closeSecurityVerification() {
      if (this.securityVerification.submitting) return;
      this.securityVerification = this.emptySecurityVerification(false);
    },

    focusSecurityCodeDigit(index) {
      this.$nextTick(() => document.getElementById(`securityCodeDigit${index}`)?.focus());
    },

    applySecurityCode(value, startIndex = 0) {
      const digits = String(value || "").replace(/\D/g, "").slice(0, 6 - startIndex);
      if (!digits) return;

      [...digits].forEach((digit, offset) => {
        this.securityVerification.digits[startIndex + offset] = digit;
      });
      this.focusSecurityCodeDigit(Math.min(5, startIndex + digits.length));
    },

    handleSecurityCodeInput(index, event) {
      const digits = String(event.target.value || "").replace(/\D/g, "");
      if (digits.length > 1) {
        this.applySecurityCode(digits, index);
        return;
      }

      const digit = digits.slice(-1);
      this.securityVerification.digits[index] = digit;
      event.target.value = digit;
      if (digit && index < 5) this.focusSecurityCodeDigit(index + 1);
    },

    handleSecurityCodeBackspace(index) {
      if (this.securityVerification.digits[index] || index === 0) return;
      this.securityVerification.digits[index - 1] = "";
      this.focusSecurityCodeDigit(index - 1);
    },

    pasteSecurityCode(event) {
      const value = event.clipboardData?.getData("text") || "";
      this.securityVerification.digits = ["", "", "", "", "", ""];
      this.applySecurityCode(value);
    },

    clearSecurityCode() {
      this.securityVerification.digits = ["", "", "", "", "", ""];
      this.focusSecurityCodeDigit(0);
    },

    async confirmSecurityCredentialChange() {
      if (!this.securityCodeComplete || !this.securityVerification.changeId) return;

      this.securityVerification.error = "";
      this.securityVerification.notice = "";
      this.securityVerification.submitting = true;
      try {
        const confirmChange = this.isStaff
          ? API.confirmStaffPasswordChange
          : API.confirmSecurityCredentialChange;
        const response = await confirmChange(
          this.securityVerification.changeId,
          this.securityVerification.digits.join(""),
        );

        if (response.requires_reauthentication) {
          API.clearAuthState();
          API.redirectToSignIn({ replace: true });
          return;
        }

        this.securityVerification = this.emptySecurityVerification(false);
        if (this.isStaff) {
          this.staffPassword.success = "Password updated successfully.";
          window.setTimeout?.(() => {
            this.staffPassword.success = "";
          }, 5000);
        } else {
          this.staffNotice = response.message;
          await this.loadSecurityAccounts();
        }
      } catch (error) {
        this.securityVerification.error = this.firstApiError(error);
        this.clearSecurityCode();
      } finally {
        this.securityVerification.submitting = false;
      }
    },

    async resendSecurityCredentialChangeCode() {
      if (!this.securityVerification.changeId || this.securityVerification.resending) return;

      this.securityVerification.error = "";
      this.securityVerification.notice = "";
      this.securityVerification.resending = true;
      try {
        const resendCode = this.isStaff
          ? API.resendStaffPasswordChangeCode
          : API.resendSecurityCredentialChangeCode;
        const response = await resendCode(this.securityVerification.changeId);
        this.securityVerification.email = response.confirmation_email;
        this.securityVerification.notice = response.message;
        this.clearSecurityCode();
      } catch (error) {
        this.securityVerification.error = this.firstApiError(error);
      } finally {
        this.securityVerification.resending = false;
      }
    },
  };
}
