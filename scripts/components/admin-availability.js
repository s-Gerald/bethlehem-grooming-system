function adminAvailability() {
  const defaultAvailability = {clinic: {open_time: '08:00', close_time: '17:00'}, grooming: {open_time: '08:00', close_time: '17:00'}};
  const clone = value => JSON.parse(JSON.stringify(value));
  return {
    canManage: false, identityReady: false, availabilityService: 'clinic',
    availability: clone(defaultAvailability), savedAvailability: clone(defaultAvailability),
    availabilityLoading: false, availabilityLoaded: false, availabilitySaving: false,
    availabilityError: '', availabilitySuccess: '', intakeStopped: {clinic: false, grooming: false},
    intakeSuccess: '', intakeModal: {open: false, action: '', service: '', busy: false, error: ''},
    capacity: null, capacityMaximum: '', capacitySaving: false, capacityError: '', capacitySuccess: '',
    groomersOnDuty: 0, petGroomers: [], petGroomerEditor: null, groomersOnDutySaving: false, groomersOnDutyError: '',
    blockedDates: [], blockedDatesError: '',
    blockForm: {startDate: '', endDate: '', reason: '', busy: false, error: '', warning: ''},
    _request: null, _revision: 0, _identityRevision: 0, _destroyed: false,
    get activeAvailability() { return this.availability[this.availabilityService]; },
    get activeAvailabilityLabel() { return this.availabilityService === 'clinic' ? 'Clinic' : 'Grooming'; },
    get intakeChoices() { return ['clinic', 'grooming'].filter(service => this.intakeStopped[service] === (this.intakeModal.action === 'reopen')); },
    get busy() { return this.intakeModal.busy || this.capacitySaving || this.availabilitySaving || this.groomersOnDutySaving || this.blockForm.busy; },
    todayDate() { return new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date()); },
    intakeDescription() {
      const service = this.intakeModal.service === 'clinic' ? 'Clinic' : 'Grooming';
      const other = this.intakeModal.service === 'clinic' ? 'Grooming' : 'Clinic';
      if (this.intakeModal.action === 'reopen') return `Reopen ${service} intake for today. ${other} will remain ${this.intakeStopped[other.toLowerCase()] ? 'stopped' : 'open'}.`;
      return `Stop new ${service} intake for today. ${other} will remain ${this.intakeStopped[other.toLowerCase()] ? 'stopped' : 'open'}.`;
    },
    async init() {
      if (new URLSearchParams(window.location.search).get('service') === 'grooming') this.availabilityService = 'grooming';
      this._refresh = () => {
        if (document.visibilityState !== 'hidden' && !API.isSessionLocked?.() && !this.busy && !this.intakeModal.open) void this.loadAvailability();
      };
      this._identityInvalidated = event => { this.invalidateIdentity(); if (event.detail?.reload) void this.verifyIdentity(); };
      this._identityUpdated = event => { if (event.detail?.user) { this.applyIdentity(event.detail.user); this._refresh(); } };
      this._dataInvalidated = event => {
        if (!event.detail?.groups?.includes('availability')) return;
        ++this._revision; this._request = null; this.availabilityLoading = false; this._refresh();
      };
      window.addEventListener?.('focus', this._refresh);
      window.addEventListener?.('admin-session:unlocked', this._refresh);
      window.addEventListener?.('admin-navigation:invalidated', this._identityInvalidated);
      window.addEventListener?.('admin-navigation:updated', this._identityUpdated);
      window.addEventListener?.('admin-data:invalidated', this._dataInvalidated);
      document.addEventListener?.('visibilitychange', this._refresh);
      await this.verifyIdentity();
    },
    applyIdentity(user) {
      const canView = user.role === 'admin' || !!user.staff_permissions?.availability_view;
      const canManage = canView && (user.role === 'admin' || user.staff_permissions?.availability_manage === true);
      if (this.identityReady !== canView || this.canManage !== canManage) { ++this._identityRevision; ++this._revision; this._request = null; this.availabilityLoading = false; }
      this.identityReady = canView;
      this.canManage = canManage;
      if (!canView) this.availabilityLoaded = false;
      if (!this.canManage) { this.petGroomerEditor = null; if (!this.intakeModal.busy) this.intakeModal.open = false; }
    },
    async verifyIdentity() {
      const revision = ++this._identityRevision;
      try {
        const {user} = await API.getStaffIdentity();
        if (this._destroyed || revision !== this._identityRevision) return;
        this.applyIdentity(user);
        if (this.identityReady) await this.loadAvailability();
      } catch (error) { if (revision === this._identityRevision) this.availabilityError = error.message || 'Could not verify access.'; }
    },
    invalidateIdentity() {
      ++this._revision; ++this._identityRevision; this._request = null;
      this.canManage = this.identityReady = this.availabilityLoaded = false;
      this.petGroomerEditor = null; this.intakeModal.open = false;
    },
    destroy() {
      this._destroyed = true; this.invalidateIdentity();
      for (const [name, listener] of [['focus', this._refresh], ['admin-session:unlocked', this._refresh], ['admin-navigation:invalidated', this._identityInvalidated], ['admin-navigation:updated', this._identityUpdated], ['admin-data:invalidated', this._dataInvalidated]]) window.removeEventListener?.(name, listener);
      document.removeEventListener?.('visibilitychange', this._refresh);
    },
    loadAvailability() {
      if (!this.identityReady || this._destroyed) return Promise.resolve(false);
      if (this._request) return this._request;
      const revision = ++this._revision;
      this.availabilityLoading = true; this.availabilityError = '';
      const pending = API.getAvailabilitySettings({force: true}).then(data => {
        if (revision !== this._revision || this._destroyed) return false;
        const hours = this.normalizeAvailability(data.availability);
        for (const service of ['clinic', 'grooming']) {
          if (!this.availabilityLoaded || JSON.stringify(this.availability[service]) === JSON.stringify(this.savedAvailability[service])) this.availability[service] = clone(hours[service]);
        }
        this.savedAvailability = clone(hours);
        if (!this.capacity || String(this.capacityMaximum) === String(this.capacity.max)) this.capacityMaximum = data.capacity.max;
        this.capacity = data.capacity; this.intakeStopped = data.stopped_today;
        this.petGroomers = data.pet_groomers || []; this.groomersOnDuty = data.groomers_on_duty;
        this.blockedDates = data.blocked_dates || []; this.availabilityLoaded = true;
        return true;
      }).catch(error => {
        if (revision === this._revision) this.availabilityError = error.message || 'Could not load Availability.';
        return false;
      }).finally(() => { if (revision === this._revision) { this.availabilityLoading = false; this._request = null; } });
      this._request = pending; return pending;
    },
    async openIntakeConfirmation(action, opener = null) {
      if (!this.canManage || this.busy || this.intakeModal.open || !['stop', 'reopen'].includes(action)) return;
      if (!await this.loadAvailability() || !this.canManage) return;
      this.intakeModal = {open: false, action, service: '', busy: false, error: ''};
      this.intakeModal.service = this.intakeChoices[0] || '';
      if (!this.intakeModal.service) return;
      this._intakeOpener = opener; this.intakeModal.open = true;
      this.$nextTick?.(() => this.$refs?.intakeCancel?.focus());
    },
    closeIntakeConfirmation() {
      if (this.intakeModal.busy) return;
      this.intakeModal.open = false;
      this.$nextTick?.(() => {
        const fallback = this.intakeStopped.clinic || this.intakeStopped.grooming ? this.$refs?.reopenIntake : this.$refs?.stopIntake;
        const opener = this._intakeOpener;
        (opener && opener.offsetParent !== null ? opener : fallback)?.focus();
      });
    },
    async executeIntakeAction() {
      if (!this.canManage || !this.intakeModal.open || this.intakeModal.busy || !this.intakeChoices.includes(this.intakeModal.service)) return;
      this.intakeModal.busy = true; this.intakeModal.error = '';
      const identity = this._identityRevision, {service, action} = this.intakeModal;
      ++this._revision; this._request = null; this.availabilityLoading = false;
      try {
        const data = action === 'stop' ? await API.adminStopToday(service) : await API.adminReopenToday(service);
        if (identity !== this._identityRevision || this._destroyed) return;
        this.intakeStopped = data.stopped_today;
        this.intakeSuccess = data.message || (service === 'clinic' ? 'Clinic' : 'Grooming') + ' intake updated.';
        this.intakeModal.busy = false; this.closeIntakeConfirmation();
        window.showSuccessToast?.(this.intakeSuccess);
      } catch (error) { if (identity === this._identityRevision) this.intakeModal.error = error.message || 'Could not change intake.'; }
      finally { this.intakeModal.busy = false; }
    },
    async saveCapacity() {
      if (!this.canManage || this.capacitySaving) return;
      this.capacityError = ''; this.capacitySuccess = '';
      if (!/^[1-9][0-9]*$/.test(String(this.capacityMaximum)) || Number(this.capacityMaximum) > 500) { this.capacityError = 'Enter a whole number from 1 to 500.'; return; }
      this.capacitySaving = true;
      const identity = this._identityRevision;
      ++this._revision; this._request = null; this.availabilityLoading = false;
      try {
        const data = await API.adminUpdateCapacity({maximum: Number(this.capacityMaximum)});
        if (identity !== this._identityRevision || this._destroyed) return;
        this.capacity = data.capacity; this.capacityMaximum = data.capacity.max;
        this.capacitySuccess = 'Capacity saved.';
      } catch (error) { if (identity === this._identityRevision) this.capacityError = error.message || 'Could not save capacity.'; }
      finally { this.capacitySaving = false; }
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

    editPetGroomer(person = null) {
      if (!this.canManage) return;
      this.petGroomerEditor = { id: person?.id ?? null, first_name: person?.first_name || "" };
      this.groomersOnDutyError = "";
      this.$nextTick(() => document.getElementById("petGroomerFirstName")?.focus());
    },

    async savePetGroomer(data, id = null, toggle = null) {
      const identity = this._identityRevision;
      if (!this.canManage || this.busy) return;
      if (this.groomersOnDutySaving) return;
      this.groomersOnDutySaving = true;
      this.groomersOnDutyError = "";
      try {
        const response = await API.adminSavePetGroomer(data, id);
        if (identity !== this._identityRevision || this._destroyed) return;
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
      const identity = this._identityRevision;
      if (!this.canManage || this.busy) return;
      const service = this.availabilityService;
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
          service,
          open_time: settings.open_time,
          close_time: settings.close_time,
        });

        if (identity !== this._identityRevision || this._destroyed) return;
        const saved = this.normalizeAvailability(response.availability);
        this.availability[service] = clone(saved[service]);
        this.savedAvailability[service] = clone(saved[service]);
        this.availabilitySuccess =
          response.message || `${this.activeAvailabilityLabel} availability updated.`;
      } catch (error) {
        this.availabilityError =
          error.message || "Could not save availability settings.";
      } finally {
        this.availabilitySaving = false;
      }
    },

    clearBlockedDateForm() {
      this.blockForm = {
        startDate: "",
        endDate: "",
        reason: "",
        busy: false,
        error: "",
        warning: "",
      };
    },

    async submitBlockedDate() {
      const identity = this._identityRevision;
      if (!this.canManage || this.busy) return;

      this.blockForm.error = "";
      this.blockForm.warning = "";

      if (!this.blockForm.startDate || !this.blockForm.endDate) {
        this.blockForm.error = "Please select both a start and end date.";
        return;
      }
      if (this.blockForm.endDate < this.blockForm.startDate) {
        this.blockForm.error = "End date cannot be before start date.";
        return;
      }

      this.blockForm.busy = true;
      try {
        const res = await API.addBlockedDate({
          start_date: this.blockForm.startDate,
          end_date:   this.blockForm.endDate,
          reason:     this.blockForm.reason || null,
        });
        if (identity !== this._identityRevision || this._destroyed) return;
        if (res.conflict_warning) {
          this.blockForm.warning = res.conflict_warning;
        }
        this.blockForm.startDate = "";
        this.blockForm.endDate   = "";
        this.blockForm.reason    = "";
        await this.loadAvailability();
      } catch (err) {
        this.blockForm.error = err.message || "Failed to block dates. Please try again.";
      } finally {
        this.blockForm.busy = false;
      }
    },

    async removeBlockedDate(id) {
      const identity = this._identityRevision;
      if (!this.canManage || this.busy) return;

      try {
        await API.removeBlockedDate(id);
        if (identity !== this._identityRevision || this._destroyed) return;
        this.blockedDates = this.blockedDates.filter(b => b.id !== id);
      } catch (err) {
        this.blockedDatesError = err.message || "Failed to remove blocked date.";
      }
    },

  };
}
