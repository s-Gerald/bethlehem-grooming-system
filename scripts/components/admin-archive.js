function adminArchive() {
  const currentYear = new Date().getFullYear().toString();
  const unavailableText = "Data is currently unavailable.";

  return {
    archiveType: "grooming",
    archivedList: [],
    clinicArchivedList: [],
    totalCount: 0,
    clinicTotalCount: 0,
    searchQuery: "",
    sortOrder: "newest",
    selectedYear: currentYear,
    selectedMonth: "",
    loading: true,
    historyLoaded: false,
    _historyKey: "",
    _historyRequest: 0,
    _archiveIdentityLoaded: false,
    errorMessage: "",
    detailsModalOpen: false,
    detailsBooking: null,
    signedConsent: null,
    consentLoading: false,
    consentError: '',
    legacyGroomingConsent: null,
    legacySedationConsent: null,
    _consentRequest: 0,
    clinicDetailsModalOpen: false,
    detailsClinicRecord: null,
    clinicCare: null,
    clinicCareLoading: false,
    clinicCareError: "",
    clinicAttachmentBusyId: null,
    clinicAttachmentError: "",
    _clinicRequest: 0,

    async init() {
      this._restoreOnPageShow = (event) => {
        if (event.persisted) this.restoreHistoryContext(window.history.state?.groomingHistory);
      };
      window.addEventListener("pageshow", this._restoreOnPageShow);
      const context = window.history.state?.groomingHistory;
      if (context) this.searchQuery = context.searchQuery;
      await this.loadArchive();
      this.restoreHistoryContext(context);
    },

    destroy() {
      window.removeEventListener("pageshow", this._restoreOnPageShow);
    },

    restoreHistoryContext(context) {
      if (context) {
        this.searchQuery = context.searchQuery;
        this.sortOrder = context.sortOrder;
        this.selectedYear = context.selectedYear;
        this.selectedMonth = context.selectedMonth;
        const booking = this.filteredList.find((record) => String(record.id) === String(context.bookingId));
        if (booking) this.viewDetails(booking, null, context.modalScroll);
        this.$nextTick(() => {
          window.scrollTo(0, context.scrollY);
          if (booking) this._detailsTrigger = document.querySelector(`[data-history-booking="${booking.id}"]`);
        });
        window.history.replaceState({ ...window.history.state, groomingHistory: null }, "");
      }
    },

    async selectArchiveType(type) {
      if (!['grooming', 'clinic'].includes(type) || this.archiveType === type) return;

      this.archiveType = type;
      this.searchQuery = "";
      this.sortOrder = "newest";
      this.selectedYear = currentYear;
      this.selectedMonth = "";
      this.closeDetails();
      this.closeClinicRecord();
      await this.loadArchive({ force: false });
    },

    async loadArchive({ force = false } = {}) {
      if (!this._archiveIdentityLoaded && typeof API.getStaffIdentity === 'function') {
        this.loading = true;
        this.errorMessage = '';
        try {
          const identity = await API.getStaffIdentity();
          if (identity.user?.role === 'staff' && identity.user?.staff_type === 'clinic') this.archiveType = 'clinic';
          this._archiveIdentityLoaded = true;
        } catch (error) {
          this.errorMessage = error.message || 'Could not verify History access.';
          this.loading = false;
          return;
        }
      }
      const type = this.archiveType, search = this.searchQuery.trim();
      const key = JSON.stringify([type, search]), request = ++this._historyRequest;
      const endpoint = (type === 'clinic' ? '/admin/clinic-appointments/archived' : '/admin/bookings/archived')
        + (search ? '?' + new URLSearchParams({search}) : '');
      this.historyLoaded = this.historyLoaded && this._historyKey === key;
      this.loading = true;
      this.errorMessage = "";
      try {
        await API.loadAdminData(endpoint, () => type === 'clinic'
          ? API.getArchivedClinicAppointments({search}, {force}) : API.getArchivedBookings({search}, {force}), data => {
          if (request !== this._historyRequest || type !== this.archiveType || search !== this.searchQuery.trim()) return;
          if (type === 'clinic') {
            this.clinicArchivedList = data.archived || [];
            this.clinicTotalCount = data.total ?? this.clinicArchivedList.length;
          } else {
            this.archivedList = data.archived || [];
            this.totalCount = data.total ?? this.archivedList.length;
          }
          this._historyKey = key;
          this.historyLoaded = true;
          this.selectAvailableYear();
        }, {force});
      } catch (error) {
        if (request === this._historyRequest) this.errorMessage = error.message || "Failed to load history. Please try again.";
      } finally {
        if (request === this._historyRequest) { this.loading = false;  }
      }
    },

    onSearchChange() {
      this.loadArchive();
    },

    onYearChange() {
      this.selectedMonth = "";
    },

    selectAvailableYear() {
      const years = this.availableYears;
      if (years.length > 0 && !years.includes(this.selectedYear)) {
        this.selectedYear = years[0];
        this.selectedMonth = "";
      }
    },

    clearFilters() {
      this.searchQuery = "";
      this.sortOrder = "newest";
      this.selectedYear = currentYear;
      this.selectedMonth = "";
      this.loadArchive();
    },

    get sourceList() {
      return this.archiveType === "clinic" ? this.clinicArchivedList : this.archivedList;
    },

    recordDate(record) {
      return this.archiveType === "clinic"
        ? record?.appointment_date
        : record?.appointmentDate;
    },

    get availableYears() {
      const seen = new Set();
      for (const record of this.sourceList) {
        const date = this.recordDate(record);
        if (date) seen.add(date.slice(0, 4));
      }
      return [...seen].sort().reverse();
    },

    get availableMonths() {
      const seen = new Set();
      for (const record of this.sourceList) {
        const date = this.recordDate(record);
        if (date && date.startsWith(this.selectedYear)) seen.add(date.slice(0, 7));
      }
      return [...seen]
        .sort()
        .reverse()
        .map((month) => ({
          value: month,
          label: new Intl.DateTimeFormat("en-PH", { month: "long" }).format(
            new Date(`${month}-01T00:00:00`),
          ),
        }));
    },

    get filteredList() {
      let list = this.sourceList;

      if (this.selectedYear) {
        list = list.filter((record) => (this.recordDate(record) || "").startsWith(this.selectedYear));
      }

      if (this.selectedMonth) {
        list = list.filter((record) => (this.recordDate(record) || "").startsWith(this.selectedMonth));
      }

      return [...list].sort((a, b) => {
        const aDate = this.recordDate(a) || "";
        const bDate = this.recordDate(b) || "";
        switch (this.sortOrder) {
          case "az": return (a.ownerName || "").localeCompare(b.ownerName || "");
          case "za": return (b.ownerName || "").localeCompare(a.ownerName || "");
          case "oldest": return aDate.localeCompare(bDate);
          default: return bDate.localeCompare(aDate);
        }
      });
    },

    get groupedByDate() {
      const groups = {};
      for (const record of this.filteredList) {
        const date = this.recordDate(record) || "unknown";
        if (!groups[date]) groups[date] = [];
        groups[date].push(record);
      }

      const dateKeys = Object.keys(groups).sort((a, b) =>
        this.sortOrder === "oldest" ? a.localeCompare(b) : b.localeCompare(a),
      );

      return dateKeys.map((date) => ({
        date,
        label: this.formatGroupDate(date),
        bookings: groups[date],
      }));
    },

    get filteredCount() {
      return this.filteredList.length;
    },

    get hasActiveFilters() {
      return Boolean(
        this.searchQuery ||
        this.selectedMonth ||
        this.sortOrder !== "newest" ||
        this.selectedYear !== currentYear
      );
    },

    get emptyStateTitle() {
      if (this.hasActiveFilters) return "No records match your filters.";
      return this.archiveType === "clinic"
        ? "No clinic history data is currently available."
        : "No grooming history yet.";
    },

    get emptyStateMessage() {
      if (this.hasActiveFilters) return "Try adjusting your search or clearing the filters.";
      return this.archiveType === "clinic"
        ? "Completed, cancelled, and expired clinic visits will appear here."
        : "Completed and cancelled grooming visits will appear here.";
    },

    viewDetails(booking, trigger = null, scrollTop = 0) {
      if (!this.detailsModalOpen) {
        this._detailsBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
      }
      this._detailsTrigger = trigger;
      this.detailsBooking = booking;
      this.detailsModalOpen = true;
      this.loadSignedConsent(booking.id);
      this.$nextTick(() => {
        // Resolve the rendered dialog after startup/Back, as well as a row click.
        const dialog = document.querySelector('[aria-labelledby="grooming-record-title"]');
        dialog.querySelector('[aria-label="Close grooming record"]').focus({ preventScroll: true });
        dialog.querySelector('[aria-label="Grooming record details"]').scrollTop = scrollTop;
      });
    },

    closeDetails() {
      if (!this.detailsModalOpen) return;
      this.detailsModalOpen = false;
      this.detailsBooking = null;
      this._consentRequest++;
      this.signedConsent = null;
      document.body.style.overflow = this._detailsBodyOverflow;
      this._detailsTrigger?.focus();
      this._detailsTrigger = null;
    },

    trapDetailsFocus(event) {
      const targets = [...this.$refs.groomingRecordDialog.querySelectorAll('button, a[href], summary, [tabindex="0"]')]
        .filter((element) => !element.disabled && element.getClientRects().length);
      const first = targets[0];
      const last = targets[targets.length - 1];
      if (event.shiftKey && event.target === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && event.target === last) {
        event.preventDefault();
        first.focus();
      }
    },

    showSessionTime(kind, booking = this.detailsBooking) {
      const start = kind === "start";
      const iso = booking?.[start ? "startedAtIso" : "completedAtIso"];
      const time = booking?.[start ? "startedAt" : "completedAt"];
      if (this.isArchiveValueMissing(iso) && this.isArchiveValueMissing(time)) return false;
      return !(booking?.pets || []).some((pet) => {
        const petIso = pet[start ? "groomingStartedAtIso" : "groomingFinishedAtIso"];
        const petTime = pet[start ? "groomingStartedAt" : "groomingFinishedAt"];
        if (iso && petIso) return Number.isFinite(Date.parse(iso)) && Date.parse(iso) === Date.parse(petIso);
        return !iso && !petIso && !this.isArchiveValueMissing(time) && time === petTime;
      });
    },

    async loadSignedConsent(bookingId) {
      const request = ++this._consentRequest;
      this.signedConsent = null;
      this.legacyGroomingConsent = null;
      this.legacySedationConsent = null;
      this.consentError = '';
      this.consentLoading = true;
      if (this.$refs.signedAgreement) this.$refs.signedAgreement.open = false;
      try {
        const data = await API.getGroomingSignedConsent(bookingId);
        if (request !== this._consentRequest) return;
        this.signedConsent = data.consent;
        this.legacyGroomingConsent = data.legacy_grooming_consent;
        this.legacySedationConsent = data.legacy_sedation_consent;
      } catch {
        if (request === this._consentRequest) this.consentError = 'Unable to load consent.';
      } finally {
        if (request === this._consentRequest) this.consentLoading = false;
      }
    },

    consentLabel(kind) {
      const value = this.signedConsent?.[kind + '_consent']
        ?? (kind === 'grooming' ? this.legacyGroomingConsent : this.legacySedationConsent);
      if (value === true) return kind === 'grooming' ? 'Agreed' : 'Authorized';
      if (value === false) return kind === 'grooming' ? 'Not agreed' : 'Not authorized';
      return 'Not recorded';
    },

    consentSignedAt() {
      const value = this.signedConsent?.signed_at;
      if (!value || !Number.isFinite(Date.parse(value))) return 'Not recorded';
      const date = new Date(value);
      const options = { timeZone: 'Asia/Manila' };
      return new Intl.DateTimeFormat('en-US', { ...options, month: 'short', day: 'numeric', year: 'numeric' }).format(date)
        + ' · ' + new Intl.DateTimeFormat('en-US', { ...options, hour: 'numeric', minute: '2-digit' }).format(date);
    },

    groomingText(value) {
      const text = String(value ?? '').trim();
      return /^(?:[-—–]|not recorded|none provided|no staff note added\.?)$/i.test(text) ? '' : text;
    },

    get groomingSessionFields() {
      const booking = this.detailsBooking;
      if (!booking) return [];
      return [
        {label: 'Visit date', value: this.groomingText(booking.appointmentDate) ? this.formatAppointmentDate(booking.appointmentDate, '') : ''},
        {label: 'Entry source', value: booking.bookingType === 'Walk-In' ? 'Walk-in' : 'Pre-registration'},
        {label: 'Arrival window', value: booking.bookingType !== 'Walk-In' ? booking.appointmentTime : ''},
        {label: 'Queue number', value: booking.queueNumber || ''},
        {label: 'Dropped off', value: this.groomingText(booking.dropOffAtIso) ? this.formatDateTime(booking.dropOffAtIso, '') : booking.dropOffTime},
        {label: 'Grooming started', value: this.showSessionTime('start') ? (this.groomingText(booking.startedAtIso) ? this.formatDateTime(booking.startedAtIso, '') : booking.startedAt) : ''},
        ...this.cancellationFields(booking),
        {label: 'Finished', value: this.showSessionTime('finish') ? (this.groomingText(booking.completedAtIso) ? this.formatDateTime(booking.completedAtIso, '') : booking.completedAt) : ''},
      ].filter(field => this.groomingText(field.value));
    },

    cancellationFields(record) {
      return [
        { label: 'Cancellation reason', value: this.groomingText(record?.cancellationReason) },
        { label: 'Cancelled', value: record?.cancelledAt ? this.formatDateTime(record.cancelledAt, '') : '' },
        { label: 'Cancelled by', value: this.groomingText(record?.cancelledBy) },
      ].filter((field) => field.value);
    },

    groomingTimelineFields(pet) {
      return [
        {label: 'Grooming started', iso: pet.groomingStartedAtIso, time: pet.groomingStartedAt},
        {label: 'Finished', iso: pet.groomingFinishedAtIso, time: pet.groomingFinishedAt},
      ].map(field => ({label: field.label, value: this.groomingText(field.iso) ? this.formatDateTime(field.iso, '') : field.time}))
        .filter(field => this.groomingText(field.value)).concat(this.cancellationFields(pet));
    },

    petSize(pet) {
      // Verification belongs to this booking, not the pet's current profile.
      const confirmed = !this.isArchiveValueMissing(pet.confirmedSize);
      const registered = !this.isArchiveValueMissing(pet.registeredSize);
      const value = confirmed ? pet.confirmedSize : registered ? pet.registeredSize : pet.size;
      if (this.isArchiveValueMissing(value)) return "Not recorded";
      const label = String(value).charAt(0).toUpperCase() + String(value).slice(1);
      return label + (confirmed ? " · Clinic verified" : registered ? "" : " · Profile size (session size not recorded)");
    },

    productAddonsTotal(booking = this.detailsBooking) {
      return (booking?.productAddons || []).reduce((sum, line) => sum + Number(line.subtotal || 0), 0);
    },

    showProductSubtotal(line) {
      return Number(line.quantity) > 1 || Number(line.subtotal) !== Number(line.priceAtSale);
    },

    transactionUrl(booking = this.detailsBooking) {
      if (!booking?.payment) return "";
      const params = new URLSearchParams();
      if (booking.payment.id != null) params.set("payment", booking.payment.id);
      if (booking.bookingReference) params.set("reference", booking.bookingReference);
      return params.size ? `./transactions.html?${params}` : "";
    },

    rememberHistoryContext(event) {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      window.history.replaceState({ ...window.history.state, groomingHistory: {
        searchQuery: this.searchQuery, sortOrder: this.sortOrder,
        selectedYear: this.selectedYear, selectedMonth: this.selectedMonth,
        bookingId: this.detailsBooking.id, scrollY: window.scrollY,
        modalScroll: this.$refs.groomingRecordBody.scrollTop,
      } }, "");
    },

    viewClinicRecord(record, trigger = document.activeElement) {
      this._clinicTrigger = trigger;
      this.detailsClinicRecord = record;
      this.clinicCare = null;
      this.clinicCareError = "";
      this.clinicAttachmentError = "";
      this.clinicAttachmentBusyId = null;
      this.clinicDetailsModalOpen = true;
      this._clinicBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = "hidden";
      this.$nextTick(() => {
        this.$refs.clinicVisitBody.scrollTop = 0;
        this.$refs.clinicVisitClose.focus({preventScroll: true});
      });
      this.loadClinicCare();
    },

    async loadClinicCare() {
      const record = this.detailsClinicRecord, request = ++this._clinicRequest;
      if (record.treatment_count === 0 && record.service_count === 0 && !record.confinement) {
        this.clinicCare = {treatments: [], services: [], confinement: null};
        this.clinicCareLoading = false;
        return;
      }
      this.clinicCareLoading = true;
      this.clinicCareError = "";
      try {
        const data = await API.adminGetPetCaseDetails(record.pet.id, record.id);
        if (request !== this._clinicRequest) return;
        this.clinicCare = {
          treatments: (data.treatments || []).filter(item => item.state === 'published' && !item.voided_at),
          services: (data.services || []).filter(item => item.status === 'performed'),
          confinement: data.confinement,
        };
      } catch (error) {
        if (request === this._clinicRequest) this.clinicCareError = error.message || "Could not load care provided.";
      } finally {
        if (request === this._clinicRequest) this.clinicCareLoading = false;
      }
    },

    closeClinicRecord() {
      if (!this.clinicDetailsModalOpen) return;
      ++this._clinicRequest;
      this.clinicDetailsModalOpen = false;
      this.detailsClinicRecord = null;
      this.clinicCare = null;
      this.clinicCareLoading = false;
      document.body.style.overflow = this._clinicBodyOverflow;
      this._clinicTrigger?.focus({preventScroll: true});
      this._clinicTrigger = null;
    },

    trapClinicFocus(event) {
      const targets = [...this.$refs.clinicVisitDialog.querySelectorAll('button, a[href], [tabindex="0"]')]
        .filter(element => !element.disabled && element.getClientRects().length);
      const first = targets[0], last = targets[targets.length - 1];
      if (event.shiftKey && event.target === first) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && event.target === last) {
        event.preventDefault(); first.focus();
      }
    },

    clinicText(value) {
      return this.isArchiveValueMissing(value) ? '' : String(value).trim();
    },

    clinicReason(visit) {
      return this.clinicText(visit?.record?.chief_complaint) || this.clinicText(visit?.chief_complaint)
        || (visit?.common_concerns || []).filter(value => this.clinicText(value)).join(', ');
    },

    clinicDiagnosis(visit) {
      return this.clinicText(visit?.record?.working_diagnosis);
    },

    clinicCareSummary(visit) {
      const parts = [];
      if (visit.treatment_count) parts.push(`${visit.treatment_count} treatment${visit.treatment_count === 1 ? '' : 's'}`);
      if (visit.service_count) parts.push(`${visit.service_count} service${visit.service_count === 1 ? '' : 's'}`);
      if (!parts.length && this.clinicText(visit.record?.treatment_given)) parts.push('Treatment');
      if (visit.confinement) parts.push('Confinement');
      return parts.join(' · ');
    },

    clinicHasPayment(visit) {
      return !!visit && (visit.status === 'completed' || visit.paid || Number(visit.total_amount) > 0);
    },

    clinicTransactionUrl(visit = this.detailsClinicRecord) {
      if (!visit?.paid || visit.status !== 'completed') return "";
      const params = new URLSearchParams({clinic: visit.id, reference: visit.appointment_reference});
      return `./transactions.html?${params}`;
    },

    clinicMoney(value) {
      return this.clinicText(value) ? Number(value).toLocaleString('en-PH', {style: 'currency', currency: 'PHP'}) : '';
    },

    get clinicSummaryFields() {
      const visit = this.detailsClinicRecord, record = visit?.record;
      return [
        {label: 'Chief Complaint', value: this.clinicReason(visit)},
        {label: 'Relevant Patient History', value: record?.relevant_history},
        {label: 'Reported Signs', value: record?.reported_signs},
        {label: 'Physical Examination', value: record?.physical_exam_findings},
        {label: 'Working Diagnosis', value: record?.working_diagnosis},
        {label: 'Differential Diagnoses', value: record?.differential_diagnoses},
        {label: 'Veterinarian Notes', value: record?.vet_notes},
        {label: 'Visit Notes', value: visit?.status === 'cancelled' ? visit.notes : ''},
      ].filter(field => this.clinicText(field.value));
    },

    get clinicVitals() {
      const vitals = this.detailsClinicRecord?.vitals;
      return [
        ['Weight', 'weight_kg', 'kg'], ['Temperature', 'temperature_c', '°C'],
        ['Heart rate', 'heart_rate_bpm', 'bpm'], ['Respiratory rate', 'respiratory_rate_bpm', 'breaths/min'],
        ['Body condition score', 'body_condition_score', ''],
      ].filter(([, key]) => this.clinicText(vitals?.[key]))
        .map(([label, key, unit]) => ({label, value: `${vitals[key]} ${unit}`.trim()}));
    },

    clinicServiceName(entry) {
      return entry?.service_snapshot?.name || entry?.clinic_service?.name || '';
    },

    clinicServicePrice(entry) {
      return entry.applied_price == null ? '' : this.clinicMoney(Number(entry.applied_price) * Number(entry.quantity ?? 1));
    },

    clinicServiceAttachments(entry) {
      return (this.detailsClinicRecord?.record?.attachments || [])
        .filter(file => String(file.clinic_case_service_id) === String(entry.id));
    },

    get clinicMedicalAttachments() {
      // Keep unmatched files discoverable even if the linked service is no longer present.
      const serviceIds = new Set((this.clinicCare?.services || []).map(entry => String(entry.id)));
      return (this.detailsClinicRecord?.record?.attachments || [])
        .filter(file => !file.clinic_case_service_id || !serviceIds.has(String(file.clinic_case_service_id)));
    },

    async openClinicAttachment(file) {
      const request = this._clinicRequest;
      this.clinicAttachmentBusyId = file.id;
      this.clinicAttachmentError = '';
      try {
        await viewClinicAttachment(this.detailsClinicRecord.id, file);
      } catch (error) {
        if (request === this._clinicRequest) this.clinicAttachmentError = error.message || 'Could not open attachment. Try again.';
      } finally {
        if (request === this._clinicRequest) this.clinicAttachmentBusyId = null;
      }
    },

    clinicTreatmentFields(item) {
      const route = ({subcutaneous: 'Subcutaneous', intramuscular: 'Intramuscular', intranasal: 'Intranasal', oral: 'Oral', other: 'Other'})[item.route] || item.route;
      return [
        {label: 'Product', value: item.product_name},
        {label: 'Strength / concentration', value: item.strength_concentration},
        {label: 'Dose', value: this.clinicText(item.dose_amount) ? `${item.dose_amount} ${item.dose_unit || ''}`.trim() : ''},
        {label: 'Route', value: route}, {label: 'Administration site', value: item.administration_site},
        {label: 'Administered', value: item.administered_date ? this.formatAppointmentDate(item.administered_date, '') : ''},
        {label: 'Next due', value: item.next_due_date ? this.formatAppointmentDate(item.next_due_date, '') : ''},
        {label: 'Batch / Lot', value: item.batch_number}, {label: 'Notes', value: item.notes},
      ].filter(field => this.clinicText(field.value));
    },

    get clinicPrescriptions() {
      return (this.detailsClinicRecord?.record?.prescriptions || []).filter(item => this.clinicText(item.medicine));
    },

    clinicPrescriptionText(item) {
      return [item.strength_concentration, item.dose, item.route, item.frequency, item.duration, item.instructions]
        .filter(value => this.clinicText(value)).join(' · ');
    },

    get clinicDiagnostics() {
      return (this.detailsClinicRecord?.record?.diagnostics || [])
        .filter(item => this.clinicText(item.diagnostic) || this.clinicText(item.result) || this.clinicText(item.notes));
    },

    get clinicHasCare() {
      return !!(this.clinicCare?.treatments.length || this.clinicCare?.services.length || this.clinicCare?.confinement
        || this.clinicText(this.detailsClinicRecord?.record?.treatment_given) || this.clinicPrescriptions.length || this.clinicDiagnostics.length);
    },

    get clinicConfinementFields() {
      const episode = this.clinicCare?.confinement;
      if (!episode) return [];
      return [
        {label: 'Admitted', value: episode.admitted_at ? this.formatDateTime(episode.admitted_at, '') : ''},
        {label: 'Discharged', value: episode.discharged_at ? this.formatDateTime(episode.discharged_at, '') : ''},
        {label: 'Attending veterinarian', value: episode.attending_veterinarian_name},
        {label: 'Reason', value: episode.reason}, {label: 'Notes', value: episode.notes},
        {label: 'Discharge instructions', value: episode.discharge_note},
      ].filter(field => this.clinicText(field.value));
    },

    displayArchiveValue(value, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      if (value === null || value === undefined) return fallback;
      const text = String(value).trim();
      return !text || text === "—" ? fallback : text;
    },

    isArchiveValueMissing(value) {
      if (value === null || value === undefined) return true;
      const text = String(value).trim();
      return !text || text === "—";
    },

    formatGroupDate(dateStr) {
      if (!dateStr || dateStr === "unknown") return "Unknown Date";
      try {
        return new Intl.DateTimeFormat("en-PH", {
          weekday: "long",
          year: "numeric",
          month: "long",
          day: "numeric",
        }).format(new Date(`${dateStr}T00:00:00`));
      } catch {
        return dateStr;
      }
    },

    formatAppointmentDate(dateStr, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      if (!dateStr) return fallback;
      try {
        return new Intl.DateTimeFormat("en-PH", {
          weekday: "short",
          year: "numeric",
          month: "short",
          day: "numeric",
        }).format(new Date(`${dateStr}T00:00:00`));
      } catch {
        return dateStr;
      }
    },

    formatDateTime(value, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      if (!value) return fallback;
      try {
        return new Intl.DateTimeFormat("en-PH", {
          year: "numeric",
          month: "short",
          day: "numeric",
          hour: "numeric",
          minute: "2-digit",
        }).format(new Date(value));
      } catch {
        return this.displayArchiveValue(value, fallback);
      }
    },

    formatClinicVisit(record, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      const appointmentDate = record?.appointment_date;
      const time = record?.checked_in_at
        ? new Intl.DateTimeFormat("en-PH", { hour: "numeric", minute: "2-digit" }).format(new Date(record.checked_in_at))
        : record?.time_window?.window_label;

      if (!appointmentDate && !time) return fallback;
      if (!appointmentDate) return this.displayArchiveValue(time, fallback);

      const date = this.formatAppointmentDate(appointmentDate, fallback);
      return time ? `${date} • ${time}` : date;
    },

    clinicStatusClass(status) {
      if (status === "completed") return "bg-emerald-50 text-emerald-700 border-emerald-200";
      if (status === "cancelled") return "bg-rose-50 text-rose-700 border-rose-200";
      if (["no_show", "expired"].includes(status)) return "bg-slate-100 text-slate-600 border-slate-200";
      return "bg-slate-100 text-slate-600 border-slate-200";
    },

    formatMobileNumber(value, fallback = null) {
      fallback ??= this.clinicDetailsModalOpen ? "Not recorded" : unavailableText;
      const text = String(value ?? "").trim();
      if (!text || text === "—") return fallback;

      const digits = text.replace(/\D/g, "");
      if (digits.length === 11) {
        return `${digits.slice(0, 4)}-${digits.slice(4, 7)}-${digits.slice(7)}`;
      }

      return text;
    },

    formatPeso(amount) {
      return `\u20b1${Number(amount || 0).toFixed(2)}`;
    },

    petSummary(booking) {
      const pets = booking?.pets ?? [];
      if (!pets.length) return this.displayArchiveValue(booking?.petName, 'Pets not recorded');
      const names = pets.slice(0, 2).map((pet) => this.displayArchiveValue(pet.petName, 'Unnamed pet'));
      const remaining = pets.length - names.length;
      return names.join(', ') + (remaining ? ` +${remaining} pet${remaining === 1 ? '' : 's'}` : '');
    },

    serviceSummary(booking) {
      const names = [...new Set((booking?.services ?? []).map((service) => service.name).filter(Boolean))];
      if (!names.length) return this.displayArchiveValue(booking?.serviceLabel, 'Services not recorded');
      return names[0] + (names.length > 1 ? ` +${names.length - 1} more` : '');
    },

    sessionTime(booking) {
      if (booking?.startedAt && booking?.completedAt) {
        const crossesDate = booking.startedAtIso && booking.completedAtIso
          && booking.startedAtIso.slice(0, 10) !== booking.completedAtIso.slice(0, 10);
        const finishDate = crossesDate ? ` (${this.formatAppointmentDate(booking.completedAtIso.slice(0, 10))})` : '';
        return `${booking.startedAt} – ${booking.completedAt}${finishDate}`;
      }
      if (booking?.startedAt) return `Started ${booking.startedAt}`;
      if (booking?.completedAt) return `Finished ${booking.completedAt}`;
      if (booking?.dropOffTime) return `Dropped off ${booking.dropOffTime}`;
      return 'Time not recorded';
    },

    getServiceAvailedAmount(service) {
      const recorded =
        service?.paidPrice ??
        service?.paid_price ??
        service?.finalPrice ??
        service?.final_price ??
        service?.priceAtBooking ??
        service?.price_at_booking;
      if (recorded === null || recorded === undefined || recorded === '') return null;
      const amount = Number(recorded);

      return Number.isFinite(amount) && amount >= 0 ? amount : null;
    },

    formatServiceAvailedPrice(service) {
      const amount = this.getServiceAvailedAmount(service);
      return amount !== null ? this.formatPeso(amount) : "Price unavailable";
    },

    servicesForPet(pet, booking = this.detailsBooking) {
      const bookingPetId = pet?.bookingPetId ?? pet?.booking_pet_id;
      if (bookingPetId === null || bookingPetId === undefined) return [];
      return (booking?.services ?? []).filter((service) =>
        String(service.bookingPetId ?? service.booking_pet_id) === String(bookingPetId),
      );
    },

    paymentMethod(booking = this.detailsBooking) {
      if (booking?.payment?.paymentMethodLabel) return booking.payment.paymentMethodLabel;
      const method = booking?.payment?.paymentMethod ?? booking?.payment?.payment_method;
      if (method === 'online') return 'Online Payment';
      if (method === 'qrph') return 'QR Payment';
      return method ? method.charAt(0).toUpperCase() + method.slice(1) : 'Not recorded';
    },

    paymentAmount(booking = this.detailsBooking) {
      const amount = booking?.payment?.finalPrice ?? booking?.payment?.final_price
        ?? booking?.paidAmount ?? booking?.paid_amount;
      return amount === null || amount === undefined || amount === '' ? 'Not recorded' : this.formatPeso(amount);
    },

    getServicesAvailedTotal(booking = this.detailsBooking) {
      const productTotal = this.productAddonsTotal(booking);
      const recordedTotal =
        booking?.payment?.finalPrice ??
        booking?.payment?.final_price ??
        booking?.paidAmount ??
        booking?.paid_amount;
      const paymentTotal = recordedTotal === null || recordedTotal === undefined || recordedTotal === ''
        ? NaN : Number(recordedTotal);

      if (Number.isFinite(paymentTotal) && paymentTotal >= 0) return paymentTotal - productTotal;

      const cancelledIds = new Set((booking?.pets || []).filter((pet) => (pet.groomingState ?? pet.grooming_state) === 'cancelled')
        .map((pet) => String(pet.bookingPetId ?? pet.booking_pet_id)));
      const services = (booking?.services || []).filter((service) => !cancelledIds.has(String(service.bookingPetId ?? service.booking_pet_id)));
      const serviceAmounts = services.map((service) => this.getServiceAvailedAmount(service));

      return serviceAmounts.length > 0 && serviceAmounts.every((amount) => amount !== null)
        ? serviceAmounts.reduce((sum, amount) => sum + amount, 0)
        : null;
    },

    formatServicesAvailedTotal(booking = this.detailsBooking) {
      const total = this.getServicesAvailedTotal(booking);
      return Number.isFinite(total) && total >= 0
        ? this.formatPeso(total)
        : "Price unavailable";
    },
  };
}
