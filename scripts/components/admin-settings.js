function adminSettings() {
  const currentRole = typeof API !== "undefined" && typeof API.getUserRole === "function"
    ? API.getUserRole()
    : "admin";
  return {
    isAdmin: currentRole === "admin",
    isStaff: currentRole === "staff",
    activeSettingsTab: "general",
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
      firstName: "",
      lastName: "",
      fullName: "",
      roleLabel: "Staff",
      username: "",
      email: "",
      statusLabel: "",
    },
    staffProfile: { editing: false, confirming: false, submitting: false, firstName: "", lastName: "", username: "", currentPassword: "", showPassword: false, errors: {}, error: "" },
    pendingStaffEmailChange: null,
    _ownAccountRevision: 0,
    _ownEmailCompleted: false,
    _ownEmailOpener: null,
    staffEmailChange: { open: false, stage: 'form', newEmail: '', password: '', showPassword: false,
      digits: ['', '', '', '', '', ''], busy: false, error: '', errors: {}, resendRemaining: 0 },
    get staffProfileChanged() {
      const values = this.staffProfileValues();
      return values.first_name !== this.staffAccount.firstName || values.last_name !== this.staffAccount.lastName
        || values.username !== this.staffAccount.username;
    },
    get staffProfileValid() {
      return Object.keys(this.staffProfileValidation()).length === 0 && !Object.keys(this.staffProfile.errors).some(field => field !== 'current_password');
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
      emailError: "",
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
    staffEmailCorrection: { email: "", password: "", showPassword: false, errors: {}, error: "" },
    _staffDetailsOpener: null,
    _staffDetailsScrollTop: 0,
    staffDetailsModal: {
      open: false,
      view: "details",
      staff: null,
      selectedRole: "",
      password: "",
      showPassword: false,
      busy: false,
      error: "",
    },
    staffRecovery: { email: '', reason: '', identityVerified: false, password: '', showPassword: false,
      oldEmailSafe: false, challenge: null, digits: ['', '', '', '', '', ''], errors: {}, error: '', resendRemaining: 0 },
    securityVerification: {
      open: false,
      changeId: null,
      purpose: "",
      targetName: "",
      email: "",
      digits: ["", "", "", "", "", ""],
      submitting: false,
      resending: false,
      resendRemaining: 0,
      error: "",
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
      const legacyTab = new URLSearchParams(window.location.search).get('tab') || window.location.hash?.slice(1);
      if (['availability', 'capacity'].includes(legacyTab)) {
        window.location.replace(new URL('availability.html' + (legacyTab === 'capacity' ? '?service=grooming' : ''), window.location.href));
        return;
      }
      if (!['general', 'security'].includes(this.activeSettingsTab)) this.activeSettingsTab = 'general';
      if (API.getStaffIdentity) {
        try {
          const {user} = await API.getStaffIdentity();
          this.isAdmin = user.role === 'admin';
          this.isStaff = user.role === 'staff';
        } catch (error) { this.staffError = error.message || 'Could not verify settings access.'; return; }
      }
      if (legacyTab === 'security') await this.selectSettingsTab('security');
    },

    selectSettingsTab(tab) {
      if (!['general', 'security'].includes(tab)) tab = 'general';
      this.activeSettingsTab = tab;
      if (tab !== "security" || this.staffLoaded) return Promise.resolve();
      if (this._securityRequest) return this._securityRequest;
      this._securityRequest = (this.isAdmin ? this.loadSecurityAccounts() : this.loadOwnAccount())
        .finally(() => { this._securityRequest = null; });
      return this._securityRequest;
    },

    destroy() {
      this.stopSecurityVerificationFeedback();
      window.clearInterval(this._staffEmailResendTimer);
      window.clearInterval(this._staffRecoveryResendTimer);
      window.clearTimeout(this._staffRecoveryExpiryTimer);
      ++this._ownAccountRevision;
    },

    accountRoleLabel(account) {
      if (account?.staff_subrole === "veterinarian") return "Veterinarian";
      if (account?.staff_subrole === "clinic_receptionist") return "Clinic Receptionist";
      if (account?.staff_subrole === "grooming_receptionist") return "Grooming Receptionist";
      if (account?.staff_type === "clinic") return "Clinic Staff";
      return account?.role === "admin" ? "Administrator" : "Staff";
    },

    async loadOwnAccount() {
      const revision = ++this._ownAccountRevision;
      this.staffLoading = !this.staffLoaded;
      try {
        const response = await API.getSettingsSecurityAccount();
        if (revision !== this._ownAccountRevision || this._ownEmailCompleted) return;
        this.applyStaffAccount(response?.account || {});
        this.pendingStaffEmailChange = response?.pending_email_change || null;
        this.staffLoaded = true;
        return true;
      } catch (error) {
        if (revision === this._ownAccountRevision) this.staffPassword.error = error.message || "Could not load your account information.";
      } finally {
        this.staffLoading = false;
      }
    },

    applyStaffAccount(account) {
      if (this._ownEmailCompleted) return;
      this.staffAccount = {
        firstName: account.first_name || "", lastName: account.last_name || "",
        fullName: `${account.first_name || ""} ${account.last_name || ""}`.trim() || "Staff account",
        roleLabel: this.accountRoleLabel(account), username: account.username || "",
        email: account.email || "No email address",
        statusLabel: account.is_active && !account.is_archived ? "Active" : "Inactive",
        canChangeEmail: Boolean(this.isStaff && account.is_active && !account.is_archived && !account.password_setup_required),
      };
    },

    emailReplacementError(value, currentEmail) {
      const email = AccountEmail.normalize(value);
      const error = AccountEmail.validate(email);
      if (error) return error;
      return email.toLowerCase() === currentEmail?.trim().toLowerCase() ? 'Enter a different email address.' : '';
    },

    ownEmailValidation() {
      const form = this.staffEmailChange, errors = {};
      const emailError = this.emailReplacementError(form.newEmail, this.staffAccount.email);
      if (emailError) errors.email = emailError;
      if (!form.password) errors.current_password = 'Enter your current password.';
      return errors;
    },

    get canContinueOwnEmailChange() {
      return !this.staffEmailChange.busy && !Object.keys(this.ownEmailValidation()).length && !Object.keys(this.staffEmailChange.errors).length;
    },

    ownEmailInput(field) {
      delete this.staffEmailChange.errors[field];
      this.staffEmailChange.error = '';
    },

    validateOwnEmailField(field) {
      const error = this.ownEmailValidation()[field];
      if (error) this.staffEmailChange.errors[field] = error;
      else delete this.staffEmailChange.errors[field];
    },

    openOwnEmailChange() {
      if (!this.isStaff || !this.staffAccount.canChangeEmail || this.staffProfile.editing || this.staffProfile.submitting
        || this.staffPassword.submitting || this.securityVerification.open || this.staffEmailChange.busy || this._ownEmailCompleted) return;
      this._ownEmailOpener = document.activeElement;
      Object.assign(this.staffEmailChange, { open: true, stage: 'form', newEmail: '', password: '', showPassword: false,
        digits: ['', '', '', '', '', ''], errors: {}, error: '' });
      AccountEmail.showError(document.getElementById('ownNewEmail'), '');
      this.$nextTick(() => document.getElementById('ownNewEmail')?.focus());
    },

    closeOwnEmailChange() {
      if (this.staffEmailChange.busy || this.staffEmailChange.stage === 'success') return;
      Object.assign(this.staffEmailChange, { open: false, password: '', showPassword: false, digits: ['', '', '', '', '', ''], errors: {}, error: '' });
      window.clearInterval(this._staffEmailResendTimer);
      const opener = this._ownEmailOpener;
      this._ownEmailOpener = null;
      this.$nextTick(() => (document.getElementById(this.pendingStaffEmailChange ? 'ownEmailResume' : 'ownChangeEmail') || opener)?.focus());
    },

    applyOwnEmailPending(pending) {
      this.pendingStaffEmailChange = pending;
      Object.assign(this.staffEmailChange, { stage: pending.stage, newEmail: pending.new_email, digits: ['', '', '', '', '', ''], errors: {}, error: '' });
      this.startSecurityResendCountdown(pending.resend_after || 0, this.staffEmailChange, '_staffEmailResendTimer');
      this.focusSecurityCodeDigit(0, 'ownEmailCodeDigit');
    },

    async resumeOwnEmailChange() {
      if (this.staffEmailChange.busy || this.staffProfile.editing || this.staffPassword.submitting || this.securityVerification.open || this._ownEmailCompleted) return;
      this._ownEmailOpener = document.activeElement;
      this.staffEmailChange.busy = true;
      try {
        if (await this.loadOwnAccount()) {
          if (this.pendingStaffEmailChange) {
            this.staffEmailChange.open = true;
            this.applyOwnEmailPending(this.pendingStaffEmailChange);
          } else {
            this.staffEmailChange.busy = false;
            this.openOwnEmailChange();
          }
        }
      } finally { this.staffEmailChange.busy = false; }
    },

    ownEmailError(error) {
      const form = this.staffEmailChange;
      form.errors = Object.fromEntries(Object.entries(error.errors || {}).map(([key, value]) => [key, [].concat(value)[0]]));
      form.error = error.status >= 500 ? 'The verification email could not be queued. Please try again.'
        : this.firstApiError(error) || 'Could not complete this email change. Please try again.';
      if (form.stage === 'form' && (form.errors.email || form.errors.current_password)) form.error = '';
      if (error.retryAfter) this.startSecurityResendCountdown(error.retryAfter, form, '_staffEmailResendTimer');
      if (error.data?.status === 'locked' && this.pendingStaffEmailChange) this.pendingStaffEmailChange.attempts_remaining = 0;
    },

    focusOwnEmailDialog() {
      document.getElementById('ownEmailDialog')?.focus();
    },

    async startOwnEmailChange() {
      const form = this.staffEmailChange;
      if (form.busy || form.stage !== 'form' || !form.open || !this.staffAccount.canChangeEmail) return;
      form.errors = this.ownEmailValidation();
      form.error = '';
      if (Object.keys(form.errors).length) {
        if (form.errors.email) AccountEmail.showError(document.getElementById('ownNewEmail'), form.errors.email);
        this.$nextTick(() => document.getElementById(form.errors.email ? 'ownNewEmail' : 'ownEmailPassword')?.focus());
        return;
      }
      const submittedEmail = AccountEmail.normalize(form.newEmail);
      form.busy = true;
      this.focusOwnEmailDialog();
      try {
        const response = await API.requestStaffEmailChange({ email: AccountEmail.normalize(form.newEmail), current_password: form.password });
        this.applyOwnEmailPending(response.pending_email_change);
      } catch (error) { if (AccountEmail.normalize(form.newEmail) === submittedEmail) this.ownEmailError(error); }
      finally { form.password = ''; form.showPassword = false; form.busy = false; }
    },

    async verifyOwnEmailChange() {
      const form = this.staffEmailChange, pending = this.pendingStaffEmailChange;
      if (form.busy || !pending || !/^[0-9]{6}$/.test(form.digits.join(''))) return;
      form.busy = true; form.error = '';
      this.focusOwnEmailDialog();
      try {
        const response = await API.confirmStaffEmailChange(pending.id, form.stage, form.digits.join(''));
        if (response.completed) {
          this._ownEmailCompleted = true;
          ++this._ownAccountRevision;
          this.pendingStaffEmailChange = null;
          this.staffAccount = { firstName: '', lastName: '', fullName: '', username: '', email: '', roleLabel: '', statusLabel: '', canChangeEmail: false };
          this.staffLoaded = false;
          this.staffProfile.currentPassword = '';
          this.staffPassword.current = this.staffPassword.password = this.staffPassword.confirmation = '';
          this.staffPassword.error = '';
          form.stage = 'success'; form.digits = ['', '', '', '', '', ''];
          window.clearInterval(this._staffEmailResendTimer);
          this.$nextTick(() => document.getElementById('ownEmailSignIn')?.focus());
        } else this.applyOwnEmailPending(response.pending_email_change);
      } catch (error) {
        this.ownEmailError(error);
        form.digits = ['', '', '', '', '', ''];
        this.focusSecurityCodeDigit(0, 'ownEmailCodeDigit');
      } finally { form.busy = false; }
    },

    async resendOwnEmailChange() {
      const form = this.staffEmailChange, pending = this.pendingStaffEmailChange;
      if (form.busy || !pending || form.resendRemaining > 0) return;
      form.busy = true; form.error = '';
      this.focusOwnEmailDialog();
      try {
        const response = await API.resendStaffEmailChange(pending.id, form.stage);
        this.applyOwnEmailPending(response.pending_email_change);
      } catch (error) { this.ownEmailError(error); }
      finally { form.busy = false; }
    },

    async cancelOwnEmailChange() {
      const form = this.staffEmailChange;
      if (form.busy || form.stage === 'success') return;
      if (!this.pendingStaffEmailChange) { this.closeOwnEmailChange(); return; }
      form.busy = true; form.error = '';
      if (form.open) this.focusOwnEmailDialog();
      try {
        await API.cancelStaffEmailChange(this.pendingStaffEmailChange.id);
        this.pendingStaffEmailChange = null;
        form.busy = false;
        this.closeOwnEmailChange();
      } catch (error) { this.ownEmailError(error); }
      finally { form.busy = false; }
    },

    staffProfileValues() {
      return { first_name: this.staffProfile.firstName.trim().replace(/\s+/gu, ' '),
        last_name: this.staffProfile.lastName.trim().replace(/\s+/gu, ' '), username: this.staffProfile.username.trim() };
    },

    staffProfileValidation() {
      const values = this.staffProfileValues(), errors = {};
      for (const [field, label] of [['first_name', 'first name'], ['last_name', 'last name']]) {
        if (!values[field]) errors[field] = `Enter your ${label}.`;
        else if (Array.from(values[field]).length > 100) errors[field] = 'Use 100 characters or fewer.';
      }
      if (!values.username) errors.username = 'Enter a username.';
      else if (values.username.length < 3 || values.username.length > 50) errors.username = 'Use 3–50 characters.';
      else if (!/^[A-Za-z]/.test(values.username)) errors.username = 'Begin with a letter.';
      else if (!/^[A-Za-z][A-Za-z0-9._-]{2,49}$/.test(values.username)) errors.username = 'Use letters, numbers, periods, underscores, or hyphens.';
      return errors;
    },

    editStaffProfile() {
      if (!this.isStaff || !this.staffLoaded || this.staffProfile.submitting || this.staffEmailChange.open || this.staffEmailChange.busy) return;
      this.staffProfile = { editing: true, confirming: false, submitting: false,
        firstName: this.staffAccount.firstName, lastName: this.staffAccount.lastName, username: this.staffAccount.username,
        currentPassword: "", showPassword: false, errors: {}, error: "" };
      this.$nextTick(() => document.getElementById('staffProfileFirstName')?.focus());
    },

    cancelStaffProfile() {
      if (this.staffProfile.submitting) return;
      this.editStaffProfile();
      this.staffProfile.editing = false;
      this.$nextTick(() => document.getElementById('editStaffProfile')?.focus());
    },

    staffProfileInput(field) {
      delete this.staffProfile.errors[field];
      this.staffProfile.error = "";
    },

    validateStaffProfileField(field) {
      const error = this.staffProfileValidation()[field];
      if (error) this.staffProfile.errors[field] = error;
    },

    cancelStaffProfileConfirmation() {
      if (this.staffProfile.submitting) return;
      Object.assign(this.staffProfile, { confirming: false, currentPassword: "", showPassword: false, error: "" });
      delete this.staffProfile.errors.current_password;
      this.$nextTick(() => document.getElementById('staffProfileUsername')?.focus());
    },

    async submitStaffProfile() {
      if (!this.isStaff || !this.staffProfile.editing || this.staffProfile.submitting || this.staffProfile.confirming) return;
      this.staffProfile.errors = this.staffProfileValidation();
      if (!this.staffProfileValid || !this.staffProfileChanged) return;
      if (this.staffProfileValues().username === this.staffAccount.username) return this.saveStaffProfile();
      this.staffProfile.submitting = true;
      this.staffProfile.error = "";
      try {
        await API.validateStaffProfile(this.staffProfileValues());
        this.staffProfile.confirming = true;
        this.$nextTick(() => document.getElementById('staffProfileCurrentPassword')?.focus());
      } catch (error) {
        this.staffProfileApiError(error);
      } finally {
        this.staffProfile.submitting = false;
      }
    },

    async confirmStaffProfile() {
      if (!this.staffProfile.confirming || this.staffProfile.submitting) return;
      if (!this.staffProfile.currentPassword) {
        this.staffProfile.errors.current_password = 'Enter your current password.';
        return;
      }
      return this.saveStaffProfile(true);
    },

    staffProfileApiError(error) {
      const errors = error.errors || {};
      this.staffProfile.errors = Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, Array.isArray(messages) ? messages[0] : messages]));
      this.staffProfile.error = Object.keys(errors).length ? "" : (error.message || 'Could not save your profile. Try again.');
      if (['first_name', 'last_name', 'username'].some(field => errors[field])) this.staffProfile.confirming = false;
      this.$nextTick(() => {
        const ids = { first_name: 'staffProfileFirstName', last_name: 'staffProfileLastName', username: 'staffProfileUsername', current_password: 'staffProfileCurrentPassword' };
        document.getElementById(ids[Object.keys(errors)[0]])?.focus();
      });
    },

    async saveStaffProfile(confirming = false) {
      this.staffProfile.submitting = true;
      this.staffProfile.error = "";
      this.staffProfile.errors = {};
      const payload = this.staffProfileValues();
      if (confirming) payload.current_password = this.staffProfile.currentPassword;
      this.staffProfile.currentPassword = "";
      try {
        const response = await API.updateStaffProfile(payload);
        this.applyStaffAccount(response.account);
        this.staffProfile.editing = false;
        this.staffProfile.confirming = false;
        window.showSuccessToast?.('Profile updated successfully.');
        this.$nextTick(() => document.getElementById('editStaffProfile')?.focus());
      } catch (error) {
        this.staffProfileApiError(error);
      } finally {
        this.staffProfile.currentPassword = "";
        this.staffProfile.showPassword = false;
        this.staffProfile.submitting = false;
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
        this.staffAccounts = (response?.staff || []).map((staff) => this.staffAccountFields(staff));
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

    staffAccountFields(staff) {
      const fullName = `${staff.first_name || ""} ${staff.last_name || ""}`.trim() || "Staff account";
      const setupRequired = Boolean(staff.password_setup_required);
      const state = setupRequired ? "pending" : staff.is_active && !staff.is_archived ? "active" : "deactivated";
      return {
        id: staff.user_id,
        fullName,
        initials: `${staff.first_name?.charAt(0) || "S"}${staff.last_name?.charAt(0) || ""}`.toUpperCase(),
        username: setupRequired ? "" : staff.username || "",
        email: staff.email || "",
        ...this.staffRoleFields(staff),
        active: state === "active",
        archived: Boolean(staff.is_archived),
        state,
        setupRequired,
        setupLinkExpired: Boolean(staff.setup_link_expired),
        emailRecovery: staff.email_recovery || null,
        statusLabel: state === "pending" ? "Pending setup" : state === "active" ? "Active" : "Deactivated",
        statusClass: state === "pending" ? "bg-amber-50 text-amber-700" : state === "active" ? "bg-emerald-50 text-emerald-700" : "bg-slate-100 text-slate-500",
      };
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
      if (this.staffEmailChange.open || this.staffEmailChange.busy) return;
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
        emailError: "",
      };
    },

    openAddStaffAccount() {
      this.staffNotice = "";
      this.addStaffModal = this.emptyAddStaffModal(true);
      AccountEmail.showError(document.getElementById('newStaffEmail'), '');

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

      const email = AccountEmail.normalize(this.addStaffModal.email);
      this.addStaffModal.emailError = AccountEmail.validate(email);
      if (this.addStaffModal.emailError) {
        AccountEmail.showError(document.getElementById('newStaffEmail'), this.addStaffModal.emailError);
        this.$nextTick(() => document.getElementById('newStaffEmail')?.focus());
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
        if (error.errors?.email) {
          if (AccountEmail.normalize(this.addStaffModal.email) === email) {
            this.addStaffModal.emailError = [].concat(error.errors.email)[0];
            this.$nextTick(() => document.getElementById('newStaffEmail')?.focus());
          }
        } else this.addStaffModal.error = this.firstApiError(error);
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
      if (!staff || this.staffDetailsModal.busy) return;

      this._staffDetailsOpener = document.activeElement;
      this.staffDetailsModal = { open: true, view: "details", staff, selectedRole: "", password: "", showPassword: false, busy: false, error: "" };
      this.staffEmailCorrection = { email: "", password: "", showPassword: false, errors: {}, error: "" };
      this.staffDetailsModal.selectedRole = this.savedStaffRole;
      this.armStaffRecoveryExpiry();
      this.$nextTick(() => document.getElementById("staffDetailsClose")?.focus());
    },

    async closeStaffDetails() {
      if (this.staffDetailsModal.busy) return;
      if (this.staffDetailsModal.view === 'recovery-code' && !await this.cancelStaffRecovery()) return;
      this.cancelStaffRoleChange();
      this.resetStaffRecovery();
      window.clearTimeout(this._staffRecoveryExpiryTimer);
      this.staffEmailCorrection = { email: "", password: "", showPassword: false, errors: {}, error: "" };
      this.staffDetailsModal.view = "details";
      this.staffDetailsModal.open = false;
      this.staffDetailsModal.staff = null;
      this.staffDetailsModal.selectedRole = "";
      this._staffDetailsOpener?.focus();
      this._staffDetailsOpener = null;
    },

    resetStaffRecovery() {
      window.clearInterval(this._staffRecoveryResendTimer);
      this.staffRecovery = { email: '', reason: '', identityVerified: false, password: '', showPassword: false,
        oldEmailSafe: false, challenge: null, digits: ['', '', '', '', '', ''], errors: {}, error: '', resendRemaining: 0 };
    },

    get canAssistStaffEmail() {
      const staff = this.staffDetailsModal.staff;
      return Boolean(this.isAdmin && staff?.active && !staff.setupRequired && !staff.archived && !staff.emailRecovery);
    },

    armStaffRecoveryExpiry() {
      window.clearTimeout(this._staffRecoveryExpiryTimer);
      const staff = this.staffDetailsModal.staff, recovery = staff?.emailRecovery;
      const expires = Date.parse(recovery?.expires_at);
      if (!recovery || recovery.status !== 'pending' || !Number.isFinite(expires)) return;
      if (expires <= Date.now()) { recovery.status = 'expired'; return; }
      this._staffRecoveryExpiryTimer = window.setTimeout(() => {
        if (this.staffDetailsModal.staff?.id === staff.id && this.staffDetailsModal.staff?.emailRecovery?.id === recovery.id) {
          this.staffDetailsModal.staff.emailRecovery.status = 'expired';
        }
      }, expires - Date.now());
    },

    get staffRecoveryExpired() {
      const recovery = this.staffDetailsModal.staff?.emailRecovery;
      return Boolean(recovery && (recovery.status === 'expired' || Date.parse(recovery.expires_at) <= Date.now()));
    },

    get staffDetailsTitle() {
      return ({ email: 'Change Staff Email', recovery: 'Assist Email Change', 'recovery-code': 'Verify Recovery Request',
        'recovery-manage': this.staffRecoveryExpired ? 'Recovery Expired' : 'Recovery Pending' })[this.staffDetailsModal.view] || 'Staff account details';
    },

    recoveryValidation() {
      const form = this.staffRecovery, errors = {};
      const email = this.emailReplacementError(form.email, this.staffDetailsModal.staff?.email);
      if (email) errors.email = email;
      if (!['inaccessible', 'compromised'].includes(form.reason)) errors.reason = 'Select a recovery reason.';
      if (!form.identityVerified) errors.identity_verified = "Verify this staff member's identity.";
      if (!form.password) errors.current_password = 'Enter your admin password.';
      return errors;
    },

    get canStartStaffRecovery() {
      return this.canAssistStaffEmail && !this.staffDetailsModal.busy && !Object.keys(this.recoveryValidation()).length && !Object.keys(this.staffRecovery.errors).length;
    },

    openStaffRecovery() {
      if (this.staffDetailsModal.busy || this.staffDetailsModal.staff?.archived || (!this.canAssistStaffEmail && !this.staffDetailsModal.staff?.emailRecovery)) return;
      this._staffDetailsScrollTop = document.getElementById('staffDetailsBody')?.scrollTop || 0;
      this.cancelStaffRoleChange();
      this.resetStaffRecovery();
      AccountEmail.showError(document.getElementById('recoveryNewEmail'), '');
      const recovery = this.staffDetailsModal.staff.emailRecovery;
      this.staffDetailsModal.view = recovery ? 'recovery-manage' : 'recovery';
      if (recovery) this.startSecurityResendCountdown(recovery.resend_after || 0, this.staffRecovery, '_staffRecoveryResendTimer');
      this.$nextTick(() => document.getElementById(recovery ? 'recoveryAdminPassword' : 'recoveryNewEmail')?.focus());
    },

    recoveryDetails() {
      this.resetStaffRecovery();
      this.staffDetailsModal.view = 'details';
      this.$nextTick(() => {
        const body = document.getElementById('staffDetailsBody');
        if (body) body.scrollTop = this._staffDetailsScrollTop;
        document.getElementById(this.staffDetailsModal.staff?.emailRecovery ? 'staffViewRecovery' : 'staffAssistEmail')?.focus();
      });
    },

    applyRecoveryAccount(response) {
      if (!response?.staff) return;
      ++this._securityRevision;
      this._staffAccountsRequest = null;
      this.staffLoading = false;
      const updated = this.staffAccountFields(response.staff);
      const account = this.staffAccounts.find(account => account.id === updated.id);
      if (account) Object.assign(account, updated);
      Object.assign(this.staffDetailsModal.staff, updated);
      this.armStaffRecoveryExpiry();
    },

    recoveryError(error) {
      const form = this.staffRecovery;
      form.errors = {};
      for (const field of ['email', 'current_password', 'reason', 'identity_verified', 'old_email_safe']) {
        if (error.errors?.[field]) form.errors[field] = [].concat(error.errors[field])[0];
      }
      form.error = Object.keys(form.errors).length ? '' : error.message || 'Recovery could not be processed. Please try again.';
      if (this.staffDetailsModal.view === 'recovery-code' || (this.staffDetailsModal.view === 'recovery-manage' && form.errors.email)) {
        form.error = form.errors.email || error.message || 'Recovery could not be processed. Please try again.';
      }
      if (error.retryAfter) this.startSecurityResendCountdown(error.retryAfter, form, '_staffRecoveryResendTimer');
      this.$nextTick(() => document.getElementById(this.staffDetailsModal.view === 'recovery-code' ? 'recoveryCodeDigit0'
        : form.errors.email && this.staffDetailsModal.view === 'recovery' ? 'recoveryNewEmail' : 'recoveryAdminPassword')?.focus());
    },

    async startStaffRecovery() {
      const modal = this.staffDetailsModal, form = this.staffRecovery;
      if (modal.busy || modal.view !== 'recovery' || !this.canAssistStaffEmail) return;
      form.errors = this.recoveryValidation(); form.error = '';
      if (Object.keys(form.errors).length) {
        if (form.errors.email) AccountEmail.showError(document.getElementById('recoveryNewEmail'), form.errors.email);
        const first = Object.keys(form.errors)[0];
        this.$nextTick(() => document.getElementById(({ email: 'recoveryNewEmail', reason: 'recoveryReason',
          identity_verified: 'recoveryIdentity', current_password: 'recoveryAdminPassword' })[first])?.focus());
        return;
      }
      const submittedEmail = AccountEmail.normalize(form.email);
      modal.busy = true;
      this.$nextTick(() => document.getElementById('staffDetailsDialog')?.focus());
      try {
        const result = await API.startStaffEmailRecovery(modal.staff.id, { email: AccountEmail.normalize(form.email), reason: form.reason,
          identity_verified: form.identityVerified, identity_method: 'in_person_employment_record', current_password: form.password });
        form.challenge = result.recovery;
        modal.view = 'recovery-code';
        this.startSecurityResendCountdown(result.recovery.resend_after || 0, form, '_staffRecoveryResendTimer');
        this.$nextTick(() => document.getElementById('recoveryCodeDigit0')?.focus());
      } catch (error) { if (AccountEmail.normalize(form.email) === submittedEmail) this.recoveryError(error); }
      finally { form.password = ''; form.showPassword = false; modal.busy = false; }
    },

    async confirmStaffRecovery() {
      const modal = this.staffDetailsModal, form = this.staffRecovery;
      if (modal.busy || modal.view !== 'recovery-code' || form.digits.join('').length !== 6) return;
      modal.busy = true; form.error = '';
      this.$nextTick(() => document.getElementById('staffDetailsDialog')?.focus());
      try {
        const result = await API.confirmStaffEmailRecovery(modal.staff.id, form.challenge.id, form.digits.join(''));
        this.applyRecoveryAccount(result);
        modal.busy = false;
        this.recoveryDetails();
        window.showSuccessToast('Recovery email queued.');
      } catch (error) { form.digits = ['', '', '', '', '', '']; this.recoveryError(error); }
      finally { modal.busy = false; }
    },

    async resendStaffRecoveryCode() {
      const modal = this.staffDetailsModal, form = this.staffRecovery;
      if (modal.busy || form.resendRemaining || modal.view !== 'recovery-code') return;
      modal.busy = true; form.error = '';
      this.$nextTick(() => document.getElementById('staffDetailsDialog')?.focus());
      try {
        const result = await API.resendStaffRecoveryCode(modal.staff.id, form.challenge.id);
        form.challenge = result.recovery; form.digits = ['', '', '', '', '', ''];
        this.startSecurityResendCountdown(result.recovery.resend_after || 0, form, '_staffRecoveryResendTimer');
        this.$nextTick(() => document.getElementById('recoveryCodeDigit0')?.focus());
      } catch (error) { this.recoveryError(error); }
      finally { modal.busy = false; }
    },

    async cancelStaffRecovery() {
      const modal = this.staffDetailsModal, form = this.staffRecovery;
      if (modal.busy) return false;
      if (modal.view === 'recovery-code' && form.challenge) {
        modal.busy = true;
        this.$nextTick(() => document.getElementById('staffDetailsDialog')?.focus());
        try { this.applyRecoveryAccount(await API.cancelStaffEmailRecovery(modal.staff.id, form.challenge.id, '', false)); }
        catch (error) { this.recoveryError(error); return false; }
        finally { modal.busy = false; }
      }
      this.recoveryDetails();
      return true;
    },

    async resendStaffRecoveryLink() { await this.manageStaffRecovery('resend'); },
    async cancelStaffRecoveryRequest() { await this.manageStaffRecovery('cancel'); },
    async manageStaffRecovery(action) {
      const modal = this.staffDetailsModal, form = this.staffRecovery, recovery = modal.staff?.emailRecovery;
      if (modal.busy || modal.view !== 'recovery-manage' || !recovery || (action === 'resend' && form.resendRemaining)) return;
      form.errors = {}; form.error = '';
      if (!form.password) form.errors.current_password = 'Enter your admin password.';
      if (action === 'cancel' && !form.oldEmailSafe) form.errors.old_email_safe = 'Confirm that current email access is safe to restore.';
      if (Object.keys(form.errors).length) return;
      modal.busy = true;
      this.$nextTick(() => document.getElementById('staffDetailsDialog')?.focus());
      try {
        const result = action === 'cancel'
          ? await API.cancelStaffEmailRecovery(modal.staff.id, recovery.id, form.password, form.oldEmailSafe)
          : await API.resendStaffEmailRecovery(modal.staff.id, recovery.id, form.password);
        this.applyRecoveryAccount(result);
        modal.busy = false;
        this.recoveryDetails();
        window.showSuccessToast(action === 'cancel' ? 'Recovery cancelled.' : 'Recovery email queued.');
      } catch (error) { this.recoveryError(error); }
      finally { form.password = ''; form.showPassword = false; modal.busy = false; }
    },

    get canCorrectStaffEmail() {
      const staff = this.staffDetailsModal.staff;
      return Boolean(this.isAdmin && staff?.setupRequired && !staff.active && !staff.archived);
    },

    staffEmailValidation() {
      const errors = {};
      const emailError = this.emailReplacementError(this.staffEmailCorrection.email, this.staffDetailsModal.staff?.email);
      if (emailError) errors.email = emailError;
      if (!this.staffEmailCorrection.password) errors.current_password = "Enter your admin password.";
      return errors;
    },

    get canSubmitStaffEmailCorrection() {
      return this.canCorrectStaffEmail && !this.staffDetailsModal.busy && !this.staffSetupBusyId
        && Object.keys(this.staffEmailValidation()).length === 0
        && Object.keys(this.staffEmailCorrection.errors).length === 0;
    },

    validateStaffEmailField(field) {
      const error = this.staffEmailValidation()[field];
      if (error) this.staffEmailCorrection.errors[field] = error;
      else delete this.staffEmailCorrection.errors[field];
    },

    openStaffEmailCorrection() {
      if (!this.canCorrectStaffEmail || this.staffDetailsModal.busy || this.staffSetupBusyId) return;
      this._staffDetailsScrollTop = document.getElementById("staffDetailsBody")?.scrollTop || 0;
      this.cancelStaffRoleChange();
      this.staffEmailCorrection = { email: "", password: "", showPassword: false, errors: {}, error: "" };
      this.staffDetailsModal.view = "email";
      AccountEmail.showError(document.getElementById("staffNewEmail"), "");
      this.$nextTick(() => document.getElementById("staffNewEmail")?.focus());
    },

    cancelStaffEmailCorrection() {
      if (this.staffDetailsModal.busy) return;
      this.staffEmailCorrection = { email: "", password: "", showPassword: false, errors: {}, error: "" };
      this.staffDetailsModal.view = "details";
      this.$nextTick(() => {
        const body = document.getElementById("staffDetailsBody");
        if (body) body.scrollTop = this._staffDetailsScrollTop;
        document.getElementById("staffChangeEmail")?.focus();
      });
    },

    trapStaffDetailsFocus(event) {
      const controls = [...event.currentTarget.querySelectorAll('button:enabled, input:enabled, select:enabled, [tabindex="0"]')]
        .filter(element => element.getClientRects().length > 0);
      const first = controls[0], last = controls.at(-1);
      if (!first) { event.preventDefault(); return; }
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault(); first.focus();
      }
    },

    async submitStaffEmailCorrection() {
      const modal = this.staffDetailsModal, form = this.staffEmailCorrection;
      if (modal.view !== "email" || modal.busy || !this.canCorrectStaffEmail || this.staffSetupBusyId) return;
      form.errors = this.staffEmailValidation();
      form.error = "";
      if (Object.keys(form.errors).length) {
        if (form.errors.email) AccountEmail.showError(document.getElementById('staffNewEmail'), form.errors.email);
        this.$nextTick(() => document.getElementById(form.errors.email ? "staffNewEmail" : "staffEmailAdminPassword")?.focus());
        return;
      }
      const submittedEmail = AccountEmail.normalize(form.email);
      modal.busy = true;
      try {
        const response = await API.correctStaffSetupEmail(modal.staff.id, AccountEmail.normalize(form.email), form.password);
        // Reject account-list reads that began before this mutation completed.
        ++this._securityRevision;
        this._staffAccountsRequest = null;
        this.staffLoading = false;
        const updated = this.staffAccountFields(response.staff);
        const account = this.staffAccounts.find(account => account.id === updated.id);
        if (account) Object.assign(account, updated);
        Object.assign(modal.staff, updated);
        this.staffNotice = "";
        modal.busy = false;
        this.cancelStaffEmailCorrection();
        window.showSuccessToast(response.message);
      } catch (error) {
        form.errors = {};
        for (const field of ["email", "current_password"]) {
          if (error.errors?.[field] && (field !== 'email' || AccountEmail.normalize(form.email) === submittedEmail)) form.errors[field] = [].concat(error.errors[field])[0];
        }
        if (!Object.keys(form.errors).length && !(error.errors?.email && AccountEmail.normalize(form.email) !== submittedEmail)) form.error = error.errors?.account?.[0]
          || "The setup email could not be queued. Please try again.";
        form.password = "";
        form.showPassword = false;
        this.$nextTick(() => document.getElementById(form.errors.email ? "staffNewEmail" : "staffEmailAdminPassword")?.focus());
      } finally {
        modal.busy = false;
      }
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
          ? staff.emailRecovery ? `${staffName} was reactivated. Recovery access remains suspended.` : `${staffName} was reactivated and can sign in again.`
          : `${staffName} was deactivated and signed out.`);
        await this.loadSecurityAccounts();
      } catch (error) {
        this.staffStatusModal.error = this.firstApiError(error);
        this.staffStatusModal.busy = false;
      }
    },

    async resendStaffSetup(staff) {
      if (!staff?.setupRequired || this.staffSetupBusyId || this.staffDetailsModal.busy) return;
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
      if (!staff?.setupRequired || this.staffDetailsModal.busy) return;
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
      if (this.staffEmailChange.open) this.closeOwnEmailChange();
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
        resendRemaining: 0,
        error: "",
      };
    },

    openSecurityVerification(challenge) {
      this.stopSecurityVerificationFeedback();
      this.securityVerification = this.emptySecurityVerification(true, challenge);
      this.startSecurityResendCountdown(challenge.resend_after ?? 60);
      this.watchSecurityCodeDelivery(challenge.email_delivery_token);

      this.$nextTick(() => document.getElementById("securityCodeDigit0")?.focus());
    },

    closeSecurityVerification() {
      if (this.securityVerification.submitting) return;
      this.stopSecurityVerificationFeedback();
      this.securityVerification = this.emptySecurityVerification(false);
    },

    stopSecurityVerificationFeedback() {
      window.clearInterval(this._securityResendTimer);
      this._securityResendTimer = null;
      this._stopSecurityDeliveryCheck?.();
      this._stopSecurityDeliveryCheck = null;
    },

    startSecurityResendCountdown(seconds, verification = this.securityVerification, timerKey = '_securityResendTimer') {
      window.clearInterval(this[timerKey]);
      const until = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
      const tick = () => {
        verification.resendRemaining = Math.max(0, Math.ceil((until - Date.now()) / 1000));
        if (!verification.resendRemaining) {
          window.clearInterval(this[timerKey]);
          this[timerKey] = null;
        }
      };
      this[timerKey] = window.setInterval(tick, 1000);
      tick();
    },

    watchSecurityCodeDelivery(receipt) {
      this._stopSecurityDeliveryCheck?.();
      if (!receipt) return;
      const verification = this.securityVerification;
      this._stopSecurityDeliveryCheck = window.AuthenticationEmailDelivery.watch(receipt, (status) => {
        if (this.securityVerification !== verification || !verification.open) return;
        const text = window.AuthenticationEmailDelivery.failureMessage(status);
        if (text) {
          verification.error = text;
        }
      });
    },

    focusSecurityCodeDigit(index, prefix = 'securityCodeDigit') {
      this.$nextTick(() => document.getElementById(`${prefix}${Math.max(0, Math.min(5, index))}`)?.focus());
    },

    applySecurityCode(value, startIndex = 0, verification = this.securityVerification, prefix = 'securityCodeDigit') {
      const digits = String(value || "").replace(/\D/g, "").slice(0, 6 - startIndex);
      if (!digits) return;

      [...digits].forEach((digit, offset) => {
        verification.digits[startIndex + offset] = digit;
      });
      this.focusSecurityCodeDigit(Math.min(5, startIndex + digits.length), prefix);
    },

    handleSecurityCodeInput(index, event, verification = this.securityVerification, prefix = 'securityCodeDigit') {
      const digits = String(event.target.value || "").replace(/\D/g, "");
      if (digits.length > 1) {
        this.applySecurityCode(digits, index, verification, prefix);
        return;
      }

      const digit = digits.slice(-1);
      verification.digits[index] = digit;
      event.target.value = digit;
      if (digit && index < 5) this.focusSecurityCodeDigit(index + 1, prefix);
    },

    handleSecurityCodeBackspace(index, verification = this.securityVerification, prefix = 'securityCodeDigit') {
      if (verification.digits[index] || index === 0) return;
      verification.digits[index - 1] = "";
      this.focusSecurityCodeDigit(index - 1, prefix);
    },

    pasteSecurityCode(event, verification = this.securityVerification, prefix = 'securityCodeDigit') {
      const value = event.clipboardData?.getData("text") || "";
      verification.digits = ["", "", "", "", "", ""];
      this.applySecurityCode(value, 0, verification, prefix);
    },

    clearSecurityCode() {
      this.securityVerification.digits = ["", "", "", "", "", ""];
      this.focusSecurityCodeDigit(0);
    },

    async confirmSecurityCredentialChange() {
      if (!this.securityCodeComplete || !this.securityVerification.changeId
        || this.securityVerification.resending || this.securityVerification.submitting) return;

      this.securityVerification.error = "";
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

        this.stopSecurityVerificationFeedback();
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
      if (!this.securityVerification.changeId || this.securityVerification.resending
        || this.securityVerification.submitting || this.securityVerification.resendRemaining > 0) return;

      const verification = this.securityVerification;
      this._stopSecurityDeliveryCheck?.();
      this.securityVerification.error = "";
      this.securityVerification.resending = true;
      this.clearSecurityCode();
      try {
        const resendCode = this.isStaff
          ? API.resendStaffPasswordChangeCode
          : API.resendSecurityCredentialChangeCode;
        const response = await resendCode(this.securityVerification.changeId);
        if (this.securityVerification !== verification) return;
        this.securityVerification.email = response.confirmation_email;
        this.startSecurityResendCountdown(response.resend_after ?? 60);
        this.watchSecurityCodeDelivery(response.email_delivery_token);
      } catch (error) {
        if (this.securityVerification !== verification) return;
        this.securityVerification.error = this.firstApiError(error);
        if (error.retryAfter) this.startSecurityResendCountdown(error.retryAfter);
      } finally {
        verification.resending = false;
      }
    },
  };
}

// LEGACY QR PAYMENT: TEMPORARILY DISABLED. Preserved editor; never composed into active Settings.
// Reactivation requires an intentional source/config change. PayMongo Online Payment is separate.
function legacyQrSettings() {
  let qrEditor = null, qrSelection = null, qrDestroyed = false;
  return {
    qrMethodOptions: ['GCash', 'Maya', 'Bank Transfer'],
    qrSettings: {accepted_methods: [], methodsOpen: false, customOpen: false, customName: '', editingMethod: '', enabled: false, version: 1, merchant_name: '', has_image: false,
      status: 'not_ready', missing_fields: [], fieldErrors: {},
      loaded: false, loading: false, saving: false, file: null, preview: '', officialConfirmed: false, error: '', success: ''},
    qrCrop: {open: false, busy: false, ready: false, error: '', detected: false},
    get qrStatusLabel() { return {not_ready: 'Not Ready', disabled: 'Disabled', enabled: 'Enabled'}[this.qrSettings.status] || 'Not Ready'; },
    get qrMissingLabel() {
      const labels = {image: 'QR image', merchant_name: 'account name', accepted_methods: 'payment methods'};
      return (this.qrSettings.missing_fields || []).map(key => labels[key]).filter(Boolean).join(', ');
    },
    destroy() {
      qrDestroyed = true;
      this.cancelQrCrop();
      if (this.qrSettings.preview) URL.revokeObjectURL(this.qrSettings.preview);
    },
    async loadQrSettings(force = false) {
      const state = this.qrSettings;
      if (!this.isAdmin || state.loading || state.saving || this.qrCrop.open || (state.loaded && !force)) return;
      state.loading = true;
      state.error = '';
      state.success = '';
      try {
        const {settings} = await API.getQrPaymentSettings();
        if (qrDestroyed) return;
        this.applyQrSettings(settings);
        await this.refreshQrPreview(settings);
      } catch (error) { state.error = error.message || 'Could not load QR settings.'; }
      finally { state.loading = false; }
    },

    get qrCustomMethods() {
      return this.qrSettings.accepted_methods.filter(method => !this.qrMethodOptions.includes(method));
    },
    get qrMethodCount() {
      const count = this.qrSettings.accepted_methods.length;
      return `${count} method${count === 1 ? '' : 's'} selected`;
    },
    qrMethodChecked(method) {
      return method === 'Other' ? this.qrSettings.customOpen || this.qrCustomMethods.length > 0
        : this.qrSettings.accepted_methods.includes(method);
    },
    toggleQrMethod(method) {
      const state = this.qrSettings;
      delete state.fieldErrors.accepted_methods;
      if (method === 'Other') {
        if (this.qrMethodChecked('Other')) {
          state.accepted_methods = state.accepted_methods.filter(value => this.qrMethodOptions.includes(value));
          this.cancelQrCustomMethod();
        } else {
          state.customOpen = true;
          this.$nextTick(() => this.$refs?.qrCustomName?.focus());
        }
      } else if (this.qrMethodOptions.includes(method)) {
        if (state.accepted_methods.includes(method)) this.removeQrMethod(method);
        else state.accepted_methods.push(method);
      }
    },
    removeQrMethod(method, returnFocus = false) {
      this.qrSettings.accepted_methods = this.qrSettings.accepted_methods.filter(value => value !== method);
      if (this.qrSettings.editingMethod === method) this.cancelQrCustomMethod();
      if (returnFocus) this.$nextTick(() => this.$refs?.qrMethodsTrigger?.focus());
    },
    editQrCustomMethod(method) {
      Object.assign(this.qrSettings, {customOpen: true, customName: method, editingMethod: method});
      this.$nextTick(() => this.$refs?.qrCustomName?.focus());
    },
    cancelQrCustomMethod() {
      Object.assign(this.qrSettings, {customOpen: false, customName: '', editingMethod: ''});
      delete this.qrSettings.fieldErrors.custom_method;
    },
    commitQrCustomMethod() {
      const state = this.qrSettings, name = state.customName.trim(), key = name.toLowerCase();
      const duplicate = [...this.qrMethodOptions, ...state.accepted_methods.filter(method => method !== state.editingMethod)]
        .some(method => method.toLowerCase() === key);
      let error = '';
      if (!name || ['cash', 'qrph', 'other', 'qr payment'].includes(key)) error = 'Enter a custom payment method name.';
      else if (name.length > 100) error = 'Use 100 characters or fewer.';
      else if (duplicate) error = 'This payment method already exists.';
      else if (!state.editingMethod && state.accepted_methods.length >= 20) error = 'Use up to 20 payment methods.';
      if (error) { state.fieldErrors.custom_method = error; return false; }
      const index = state.accepted_methods.indexOf(state.editingMethod);
      if (index >= 0) state.accepted_methods.splice(index, 1, name);
      else state.accepted_methods.push(name);
      this.cancelQrCustomMethod();
      delete state.fieldErrors.accepted_methods;
      return true;
    },
    closeQrMethods(returnFocus = false) {
      this.qrSettings.methodsOpen = false;
      if (returnFocus) this.$refs?.qrMethodsTrigger?.focus();
    },
    async focusQrMethod(event, edge = 0) {
      this.qrSettings.methodsOpen = true;
      await this.$nextTick();
      const options = [...this.$refs.qrMethodsPanel.querySelectorAll('input[type="checkbox"]')];
      const index = options.indexOf(event.target);
      const next = edge === -1 ? 0 : edge === 1 ? options.length - 1
        : (index + (event.key === 'ArrowUp' ? -1 : 1) + options.length) % options.length;
      options[next]?.focus();
    },
    applyQrSettings(settings) {
      Object.assign(this.qrSettings, settings, {merchant_name: settings.merchant_name || '',
        accepted_methods: [...(settings.accepted_methods ?? this.qrMethodOptions)],
        methodsOpen: false, customOpen: false, customName: '', editingMethod: '',
        file: null, officialConfirmed: false, loaded: true, fieldErrors: {}});
    },

    async refreshQrPreview(settings) {
      const blob = settings.has_image ? (await API.getQrPaymentImage(settings.version)).blob : null;
      if (qrDestroyed) return;
      if (this.qrSettings.preview) URL.revokeObjectURL(this.qrSettings.preview);
      this.qrSettings.preview = blob ? URL.createObjectURL(blob) : '';
    },

    chooseQrImage(event) {
      const file = event.target.files?.[0];
      event.target.value = '';
      return this.editQrImage(file);
    },
    dropQrImage(event) {
      event.preventDefault();
      return this.editQrImage(event.dataTransfer?.files?.[0]);
    },
    async editQrImage(file) {
      const state = this.qrSettings;
      if (!file || !this.isAdmin || !state.loaded || state.saving || state.loading) return;
      state.error = '';
      state.success = '';
      if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 3 * 1024 * 1024) {
        state.error = 'Select a PNG, JPEG or WebP image up to 3 MB.';
        return;
      }
      this.cancelQrCrop();
      const selection = new AbortController(); qrSelection = selection;
      Object.assign(this.qrCrop, {open: true, busy: true, ready: false, error: '', detected: false});
      try {
        await this.$nextTick();
        const {openQrImageEditor} = await import('../services/qr-image-editor.js?v=20261023-ux');
        if (selection.signal.aborted) return;
        const editor = await openQrImageEditor(file, this.$refs.qrCropImage, this.$refs.qrCropPreview, selection.signal);
        if (selection.signal.aborted) { editor.dispose(); return; }
        qrEditor = editor;
        this.qrCrop.detected = editor.detected;
        this.qrCrop.ready = true;
        this.$refs.qrCropControls?.focus();
      } catch (error) { if (!selection.signal.aborted) this.qrCrop.error = error.message || 'Could not open this image.'; }
      finally { if (qrSelection === selection) this.qrCrop.busy = false; }
    },
    cancelQrCrop() {
      qrSelection?.abort(); qrSelection = null;
      qrEditor?.dispose(); qrEditor = null;
      Object.assign(this.qrCrop, {open: false, busy: false, ready: false, error: ''});
    },
    zoomQrCrop(delta) { qrEditor?.zoom(delta); },
    moveQrCrop(x, y) { qrEditor?.move(x, y); },
    resetQrCrop() { qrEditor?.reset(); },
    async applyQrCrop(original = false) {
      if (!qrEditor || this.qrCrop.busy) return;
      const editor = qrEditor;
      this.qrCrop.busy = true; this.qrCrop.error = '';
      try {
        const file = await editor.approve(original);
        if (editor !== qrEditor) return;
        this.acceptQrImage(file);
        this.cancelQrCrop();
        this.$refs.qrImageBrowse?.focus();
      } catch (error) { if (editor === qrEditor) this.qrCrop.error = error.message || 'Could not apply this crop.'; }
      finally { if (editor === qrEditor) this.qrCrop.busy = false; }
    },
    acceptQrImage(file) {
      const state = this.qrSettings;
      if (state.preview) URL.revokeObjectURL(state.preview);
      state.preview = URL.createObjectURL(file);
      state.file = file;
      state.officialConfirmed = false;
      delete state.fieldErrors.image;
    },

    async saveQrSettings() {
      const state = this.qrSettings;
      if (!this.isAdmin || !state.loaded || state.saving || state.loading || this.qrCrop.open) return;
      if (state.customOpen && !this.commitQrCustomMethod()) {
        state.error = 'Check the highlighted field.';
        this.$nextTick(() => this.$refs?.qrCustomName?.focus());
        return;
      }
      state.saving = true;
      state.error = '';
      state.success = '';
      state.fieldErrors = {};
      const payload = new FormData();
      payload.append('enabled', state.enabled ? '1' : '0');
      payload.append('version', String(state.version));
      payload.append('merchant_name', state.merchant_name);
      payload.append('accepted_methods', JSON.stringify(state.accepted_methods));
      if (state.file) {
        payload.append('image', state.file);
        payload.append('official_qr_confirmed', state.officialConfirmed ? '1' : '0');
      }
      try {
        const {settings, message} = await API.saveQrPaymentSettings(payload);
        if (qrDestroyed) return;
        this.applyQrSettings(settings);
        if (this.$refs?.qrImageInput) this.$refs.qrImageInput.value = '';
        state.success = message || ({enabled: 'QR Payments enabled', disabled: 'Saved. QR Payments is disabled.'}[settings.status] || 'Saved. QR configuration is not ready.');
        try { await this.refreshQrPreview(settings); }
        catch { if (state.preview) URL.revokeObjectURL(state.preview); state.preview = ''; state.error = 'Saved, but the image preview could not load. Reload to retry.'; }
      } catch (error) {
        state.fieldErrors = Object.fromEntries(Object.entries(error.errors || {}).map(([key, value]) => [key, Array.isArray(value) ? value.join(' ') : value]));
        const methodErrors = Object.entries(state.fieldErrors).filter(([key]) => key.startsWith('accepted_methods.')).map(([, message]) => message);
        if (methodErrors.length) state.fieldErrors.accepted_methods = methodErrors.join(' ');
        state.error = Object.keys(state.fieldErrors).length ? 'Check the highlighted fields.' : error.message || 'Could not save QR settings.';
        this.$nextTick(() => this.$refs?.qrSettingsForm?.querySelector('[aria-invalid="true"]')?.focus());
      }
      finally { state.saving = false; }
    },

  };
}
