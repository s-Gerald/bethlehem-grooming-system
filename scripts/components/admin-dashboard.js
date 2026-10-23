const PAYMENT_SIZE_OPTIONS = [
  { value: "small", label: "Small" },
  { value: "medium", label: "Medium" },
  { value: "large", label: "Large" },
  { value: "extra_large", label: "Extra Large" },
];

const PAYMENT_SERVICE_BY_ID = new Map();
const PAYMENT_SERVICE_BY_NAME = new Map();

function applyPaymentCatalogue(catalogue) {
  PAYMENT_SERVICE_BY_ID.clear();
  PAYMENT_SERVICE_BY_NAME.clear();
  for (const service of catalogue) {
    PAYMENT_SERVICE_BY_ID.set(service.id, service);
    PAYMENT_SERVICE_BY_NAME.set(normalizePaymentText(service.name), service);
  }
}

function normalizePaymentText(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/&/g, "and")
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

function normalizePaymentSize(value) {
  const normalized = String(value || "").trim().toLowerCase();
  const sizeMap = {
    small: "small",
    medium: "medium",
    large: "large",
    "extra large": "extra_large",
    "extra-large": "extra_large",
    extra_large: "extra_large",
    extralarge: "extra_large",
    xl: "extra_large",
  };

  return sizeMap[normalized] || "";
}

function normalizePaymentPetType(value) {
  const normalized = normalizePaymentText(value);

  if (normalized.includes("cat")) {
    return "cat";
  }

  if (normalized.includes("dog")) {
    return "dog";
  }

  return normalized;
}

function getPaymentBaseSizeOptions(petType) {
  return normalizePaymentPetType(petType) === "cat"
    ? PAYMENT_SIZE_OPTIONS.filter((option) => ["small", "medium"].includes(option.value))
    : PAYMENT_SIZE_OPTIONS;
}

function normalizePaymentSizeForPet(size, petType) {
  const options = getPaymentBaseSizeOptions(petType);
  const sizeKey = normalizePaymentSize(size);

  return options.some((option) => option.value === sizeKey)
    ? sizeKey
    : options[0]?.value || "";
}

function formatPaymentSizeLabel(value) {
  const normalized = normalizePaymentSize(value);
  const labels = {
    small: "Small",
    medium: "Medium",
    large: "Large",
    extra_large: "Extra Large",
  };

  return labels[normalized] || "Size not specified";
}

function parsePaymentNumber(value) {
  const amount = Number.parseFloat(String(value ?? "").replace(/,/g, ""));
  return Number.isFinite(amount) ? amount : null;
}

function getPaymentPetSizeCandidate(source) {
  if (!source) {
    return "";
  }

  return [
    source.size,
    source.petSize,
    source.pet_size,
    source.sizeKey,
    source.size_key,
    source.selectedSize,
    source.selected_size,
    source.pet?.size,
    source.pet?.petSize,
    source.pet?.pet_size,
  ].find((value) => normalizePaymentSize(value)) || "";
}

function getPaymentServiceDefinition(rawService) {
  const serviceId = String(
    rawService?.slug ??
      rawService?.serviceSlug ??
      rawService?.service_slug ??
      rawService?.serviceId ??
      rawService?.id ??
      "",
  );

  if (PAYMENT_SERVICE_BY_ID.has(serviceId)) {
    return PAYMENT_SERVICE_BY_ID.get(serviceId);
  }

  const nameKey = normalizePaymentText(
    rawService?.name ?? rawService?.serviceName ?? rawService?.service_name,
  );

  return PAYMENT_SERVICE_BY_NAME.get(nameKey) || null;
}

function inferPaymentSizeFromServices(services, petType) {
  if (!Array.isArray(services) || services.length === 0) {
    return "";
  }

  const allowedSizes = new Set(
    getPaymentBaseSizeOptions(petType).map((option) => option.value),
  );

  for (const rawService of services) {
    const serviceDefinition = getPaymentServiceDefinition(rawService);

    if (serviceDefinition?.kind !== "package") {
      continue;
    }

    const bookedAmount = parsePaymentNumber(
      rawService?.priceAtBooking ?? rawService?.price_at_booking,
    );

    if (!Number.isFinite(bookedAmount) || bookedAmount <= 0) {
      continue;
    }

    const matchedPriceOption = (serviceDefinition.priceOptions || []).find(
      (option) =>
        option.sizeKey &&
        allowedSizes.has(option.sizeKey) &&
        Math.abs(Number(option.minAmount || 0) - bookedAmount) < 0.01,
    );

    if (matchedPriceOption) {
      return matchedPriceOption.sizeKey;
    }
  }

  return "";
}

function formatPaymentAmount(amount) {
  return `\u20b1${Number(amount || 0).toLocaleString("en-PH")}`;
}

function formatPaymentPriceOption(priceOption, includeCurrency = true) {
  if (!priceOption) {
    return "";
  }

  const minAmount = includeCurrency
    ? formatPaymentAmount(priceOption.minAmount)
    : Number(priceOption.minAmount || 0).toLocaleString("en-PH");

  if (priceOption.pricingType === "plus") {
    return `${minAmount}+`;
  }

  if (priceOption.pricingType === "range") {
    const maxAmount = includeCurrency
      ? formatPaymentAmount(priceOption.maxAmount)
      : Number(priceOption.maxAmount || 0).toLocaleString("en-PH");
    return `${minAmount}–${maxAmount}`;
  }

  return minAmount;
}

function summarizePaymentPriceOptions(priceOptions) {
  let minimumAmount = Number.POSITIVE_INFINITY;
  let maximumAmount = 0;
  let hasMaximumAmount = false;
  let hasOpenEndedMaximum = false;

  priceOptions.forEach((priceOption) => {
    if (!Number.isFinite(priceOption?.minAmount)) {
      return;
    }

    minimumAmount = Math.min(minimumAmount, priceOption.minAmount);

    if (priceOption.maxAmount === null) {
      hasOpenEndedMaximum = true;
      return;
    }

    maximumAmount = Math.max(maximumAmount, priceOption.maxAmount);
    hasMaximumAmount = true;
  });

  return {
    minAmount: minimumAmount === Number.POSITIVE_INFINITY ? 0 : minimumAmount,
    maxAmount: hasOpenEndedMaximum ? null : hasMaximumAmount ? maximumAmount : 0,
    pricingType: hasOpenEndedMaximum ? "plus" : "range",
  };
}

function getPaymentServicePricing(serviceDefinition, petSize) {
  if (!serviceDefinition) {
    return {
      minAmount: 0.01,
      displayPrice: "Enter price",
      placeholder: "0.00",
      pricingType: "custom",
      selectedPriceOption: null,
    };
  }

  const options = Array.isArray(serviceDefinition.priceOptions)
    ? serviceDefinition.priceOptions
    : [];

  if (serviceDefinition.kind === "package") {
    const sizeKey = normalizePaymentSize(petSize);
    const selectedPriceOption = options.find((option) => option.sizeKey === sizeKey);

    if (selectedPriceOption) {
      return {
        minAmount: selectedPriceOption.minAmount || 0.01,
        displayPrice: formatPaymentPriceOption(selectedPriceOption),
        placeholder: formatPaymentPriceOption(selectedPriceOption, false),
        pricingType: selectedPriceOption.pricingType,
        selectedPriceOption,
      };
    }

    const summary = summarizePaymentPriceOptions(options);
    return {
      minAmount: summary.minAmount || 0.01,
      displayPrice:
        summary.maxAmount === null
          ? `${formatPaymentAmount(summary.minAmount)}+`
          : `${formatPaymentAmount(summary.minAmount)}-${formatPaymentAmount(summary.maxAmount)}`,
      placeholder:
        summary.maxAmount === null
          ? `${Number(summary.minAmount || 0).toLocaleString("en-PH")}+`
          : `${Number(summary.minAmount || 0).toLocaleString("en-PH")}-${Number(summary.maxAmount || 0).toLocaleString("en-PH")}`,
      pricingType: summary.pricingType,
      selectedPriceOption: null,
    };
  }

  const option = options[0] || null;

  if (!option) {
    return {
      minAmount: 0.01,
      displayPrice: "Enter price",
      placeholder: "0.00",
      pricingType: "custom",
      selectedPriceOption: null,
    };
  }

  return {
    minAmount: option.minAmount || 0.01,
    displayPrice: formatPaymentPriceOption(option),
    placeholder: formatPaymentPriceOption(option, false),
    pricingType: option.pricingType,
    selectedPriceOption: option,
  };
}

function shouldLockPaymentPrice(pricing, lockFixedPrices) {
  if (!lockFixedPrices) {
    return false;
  }

  const pricingType = pricing?.pricingType || "custom";

  return pricingType === "fixed";
}

function getPaymentPriceSafetyRules(pricing, serviceDefinition, petSize) {
  const rules = { safetyMaxAmount: null, reviewThreshold: null };
  if (pricing.pricingType !== "plus" || !serviceDefinition) return rules;

  if (serviceDefinition.kind === "package") {
    rules.safetyMaxAmount = pricing.minAmount + 500;
    if (petSize === "large") {
      rules.reviewThreshold = serviceDefinition.priceOptions.find(
        (option) => option.sizeKey === "extra_large",
      )?.pricingType === "plus"
        ? serviceDefinition.priceOptions.find((option) => option.sizeKey === "extra_large").minAmount : null;
    }
  } else if (serviceDefinition.kind === "ala_carte") {
    rules.safetyMaxAmount = pricing.minAmount + 200;
  }
  return rules;
}

/*
 * Backend integration contract:
 * - Optional preload config: window.ADMIN_DASHBOARD_CONFIG = { bootstrap, endpoints, handlers, ... }
 * - Optional runtime bridge after Alpine init: window.adminDashboardUI.setData(...), setConfig(...), getState()
 * - Action hooks: loadDashboard, walkInBooking, checkIn, startGrooming, markDone, archive, viewDetails
 */
function adminDashboard() {
  return {
    _initialized: false,
    pageMode: window.location?.pathname?.endsWith('/dashboard.html') ? 'dashboard' : 'grooming',
    bookingsLoaded: false,
    bookingsLoading: true,
    bookingsError: "",
    summaryLoaded: false,
    summaryLoading: true,
    summaryError: "",
    activityLoaded: false,
    activityLoading: true,
    activityError: "",
    dashboardQueuePreview: [],
    activeTodayCount: null,
    _bookingRequest: null,
    _bookingRequestDate: null,
    _bookingRevision: 0,
    _summaryRequest: null,
    _activityRequest: null,
    _dashboardRetryAt: { summary: 0, activity: 0 },
    _visibilityListener: null,
    _sessionResumeListener: null,
    _destroyed: false,

    activeTab: "incoming",
    dashboardSearchQuery: "",
    dashboardSearchOpen: false,
    dashboardSearchLoading: false,
    dashboardSearchError: "",
    dashboardSearchCustomers: [],
    dashboardSearchPets: [],
    _dashboardSearchRequestId: 0,
    todayCount: 0,
    weekCount: 0,
    revenueToday: 0,
    revenuePaymentCount: 0,
    waitingNow: 0,
    currentCapacity: 0,
    maxCapacity: 20,
    recentActivity: [],
    detailsModalOpen: false,
    detailsBooking: null,
    noteEditor: { kind: "", bookingPetId: null, value: "", busy: false, error: "" },
    sedationConsentCapture: {
      confirmed: false,
      busy: false,
      error: "",
    },
    pendingActions: {},
    expandedQueuedBookingIds: {},
    expandedInProgressBookingIds: {},
    groomingMenuBookingId: null,
    _groomingMenuTrigger: null,
    _pollFailures: {},
    selectedDate: new Intl.DateTimeFormat("en-CA", {
      timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit",
    }).format(new Date()),
    get todayDate() {
      return this.localToday();
    },
    get maxDate() {
      const d = new Date(`${this.todayDate}T00:00:00Z`);
      d.setUTCDate(d.getUTCDate() + 3);
      return d.toISOString().slice(0, 10);
    },
    forPaymentList: [],
    activeGroomers: 2,
    activeGroomingPets: 0,
    groomingWorkload: null,
    groomerCapacityBusy: false,
    groomerCapacityError: "",
    pickupModal: { open: false, booking: null, busy: false },
    actionConfirmModal: {
      open: false,
      action: "",
      booking: null,
      title: "",
      message: "",
      confirmLabel: "",
      busyLabel: "",
      icon: "checkIn",
      variant: "primary",
      cancellationReason: "",
      petSizes: [],
      busy: false,
      error: "",
    },
    paymentModal: {
      paymentMethod: 'cash',
      qr: null,
      open: false,
      booking: null,
      isEarlyPayment: false,
      finalPrice: "",
      petBreakdown: [],
      products: [],
      showProductSearch: false,
      productQuery: "",
      productResults: [],
      productSearchError: "",
      scannerActive: false,
      _scanner: null,
      amountPaid: "",
      notes: "",
      busy: false,
      error: "",
    },
    receiptModal: {
      open: false,
      bookingReference: "",
      ownerName: "",
      contactNumber: "",
      petName: "",
      serviceLabel: "",
      appointmentDate: "",
      appointmentTime: "",
      pets: [],
      products: [],
      groomingSubtotal: 0,
      productsSubtotal: 0,
      finalPrice: 0,
      amountPaid: 0,
      change: 0,
      paymentMethod: "",
      notes: "",
      paidAt: "",
      isEarlyPayment: false,
    },
    config: {
      bootstrap: null,
      endpoints: {},
      handlers: {},
      walkInBookingUrl: "./walk-in-booking.html?flow=grooming&new=1",
      detailPageUrl: "",
      enableOptimisticUpdates: false,
      includeFutureAppointments: false,
    },

    /*****************************************************************************************
     * BACKEND INTEGRATION AREA
     *
     * These arrays are intentionally empty by default.
     * Backend should populate them with real data.
     *****************************************************************************************/
    // MARK AS DONE FLOW
    // in_progress -> for_pickup
    //
    // Required backend actions:
    // 1. Update status to "for_pickup"
    // 2. Save completion timestamp
    // 3. Send SMS to client
    // 4. Update client dashboard / notifications
    // 5. Return updated appointment record

    // ARCHIVE FLOW
    // for_pickup -> archived record
    //
    // Required backend actions:
    // 1. Move or copy completed session data into archive storage/table
    // 2. Preserve important session details for history
    // 3. Remove it from active for_pickup list if archive is successful
    // 4. Return success response to frontend

    incomingList: [],
    queuedList: [],
    inProgressList: [],
    forPickupList: [],
    // forPickupList kept for backward-compat; new data comes as forPaymentList
    releasedList: [],

    get showFutureAppointments() {
      return Boolean(this.config.includeFutureAppointments);
    },

    // Boots the dashboard, loads any backend config/data, and exposes the UI bridge.
    async init() {
      if (this._initialized) return;
      this._initialized = true;

      if (!API.hasAuthenticatedSession("admin")) {
        API.redirectToSignIn();
        return;
      }

      if (this._destroyed) return;
      this.selectedDate = this.todayDate;

      this.registerBridge();
      this.loadConfig();


      if (this.pageMode === 'dashboard') {
        this.startContentPolling();
        const primary = this.loadDashboardSummary();
        void this.loadDashboardActivity();
        await primary;
        this.dispatchDashboardEvent('admin-dashboard:ready', { state: this.getState() });
        return;
      }

      try {
        await this.bootstrapDashboard();
      } catch (error) {
        console.error("Failed to initialize admin dashboard:", error);
      }

      this.dispatchDashboardEvent("admin-dashboard:ready", {
        state: this.getState(),
      });

      // Configure action handlers to call the real API
      this.mergeConfig({
        handlers: {
          checkIn: async ({ booking, petSizes, internalStaffNote }) => {
            await API.adminCheckIn(booking.id, petSizes, internalStaffNote);
            await this.loadAdminBookings();
            this.setTab("queued");
          },
          undoCheckIn: async ({ booking }) => {
            await API.adminRevertCheckIn(booking.id);
            await this.loadAdminBookings();
            this.setTab("incoming");
          },
          startGrooming: async ({ booking }) => {
            await API.adminStartGrooming(booking.id);
            await this.loadAdminBookings();
            this.setTab("in-progress");
          },
          undoPetStart: async ({ booking }) => {
            await API.adminUndoPetStart(booking.id, booking.actionPet.bookingPetId ?? booking.actionPet.booking_pet_id ?? booking.actionPet.id);
            await this.loadAdminBookings();
            this.setTab(this.inProgressList.some((item) => String(item.id) === String(booking.id)) ? "in-progress" : "queued");
          },
          cancelPet: async ({ booking, cancellationReason }) => {
            const response = await API.adminCancelPet(booking.id, booking.actionPet.bookingPetId ?? booking.actionPet.booking_pet_id ?? booking.actionPet.id, cancellationReason);
            await this.loadAdminBookings();
            if (response.booking_status === "for_payment") this.setTab("for-payment");
          },
          startPetGrooming: async ({ booking }) => {
            const pet = booking?.actionPet;
            const bookingPetId = pet?.bookingPetId ?? pet?.booking_pet_id ?? pet?.id;

            if (!bookingPetId) {
              throw new Error("Cannot start grooming: pet record is missing.");
            }

            const assessment = booking.bookingType === "Walk-In" ? {} : {
              estimate_factors: pet.estimateFactors || [],
              grooming_preference: pet.groomingPreference || null,
            };
            const response = await API.adminStartPetGrooming(booking.id, bookingPetId, assessment);
            await this.loadAdminBookings();

            this.setInProgressBookingExpanded(booking.id, true);
            this.setTab("in-progress");

            return response;
          },
          markDone: async ({ booking }) => {
            await API.adminMarkDone(booking.id);
            await this.loadAdminBookings();
            this.setTab(booking.paid ? "to-be-picked-up" : "for-payment");
          },
          markPetDone: async ({ booking }) => {
            const pet = booking?.actionPet;
            const bookingPetId = pet?.bookingPetId ?? pet?.booking_pet_id ?? pet?.id;

            if (!bookingPetId) {
              throw new Error("Cannot finish grooming: pet record is missing.");
            }

            const response = await API.adminMarkPetDone(booking.id, bookingPetId);
            await this.loadAdminBookings();

            if (response?.all_pets_finished) {
              const completedStatus = this.normalizeStatus(response.booking_status);
              this.setTab(
                completedStatus === "to-be-picked-up" || completedStatus === "released"
                  ? "to-be-picked-up"
                  : "for-payment",
              );
            } else {
              this.setInProgressBookingExpanded(booking.id, true);
              this.setTab("in-progress");
            }

            return response;
          },
          cancel: async ({ booking, cancellationReason = "" }) => {
            await API.adminCancelBooking(booking.id, cancellationReason);
            await this.loadAdminBookings();
          },
          archive: async ({ booking }) => {
            await API.adminArchiveBooking(booking.id);
            await this.loadAdminBookings();
          },
          viewDetails: ({ booking }) => {
            this._detailsReturnFocus = document.activeElement;
            this.detailsBooking   = booking;
            this.cancelNoteEdit();
            this.sedationConsentCapture = {
              confirmed: false,
              busy: false,
              error: "",
            };
            this.detailsModalOpen = true;
            this.$nextTick(() => {
              this.$refs.groomingDetailsClose?.focus();
            });
          },
        },
      });

      this.startContentPolling();
      await this.loadAdminBookings({ force: false });
      await this.restoreGroomingOnlinePayment();
    },

    startContentPolling() {
      if (this._destroyed || this._bookingInterval) return;
      const refresh = () => {
        if (document.visibilityState === 'hidden' || API.isSessionLocked?.() || this._destroyed) return;
        if (this.pageMode === 'dashboard') {
          void this.loadDashboardSummary();
          void this.loadDashboardActivity();
        } else void this.loadAdminBookings();
      };
      this._bookingInterval = setInterval(refresh, 30000);
      this._visibilityListener = () => { if (document.visibilityState !== 'hidden') refresh(); };
      document.addEventListener('visibilitychange', this._visibilityListener);
      this._sessionResumeListener = () => {
        if (!this.bookingsLoaded && !this.summaryLoaded && !this.activityLoaded) return;
        Promise.allSettled([this._bookingRequest, this._summaryRequest, this._activityRequest]).then(refresh);
      };
      window.addEventListener('admin-session:unlocked', this._sessionResumeListener);
    },

    destroy() {
      this._destroyed = true;
      this.stopOnlinePaymentPoll();
      this._bookingRevision++;
      clearInterval(this._bookingInterval);
      this._bookingInterval = null;
      clearTimeout(this._paymentProductSearchTimer);
      document.removeEventListener('visibilitychange', this._visibilityListener);
      window.removeEventListener('admin-session:unlocked', this._sessionResumeListener);
      this.stopOnlinePaymentPoll();
      this.closePaymentScanner();
      this.paymentModal.qr?.dispose();
    },

    loadDashboardSummary({ force = false } = {}) {
      if (this._destroyed || this.pageMode !== 'dashboard' || document.visibilityState === 'hidden') return Promise.resolve();
      if (this._summaryRequest) return this._summaryRequest;
      if (!force && Date.now() < this._dashboardRetryAt.summary) return Promise.resolve();
      this.summaryLoading = !this.summaryLoaded;
      let failed = false;
      const onRefreshError = error => { failed = true; this.dashboardRefreshFailed('summary', error); };
      this._summaryRequest = API.loadAdminData("/admin/dashboard/summary", () => API.getAdminDashboardSummary({ force }), data => {
        if (this._destroyed) return;
        this.dashboardQueuePreview = data.queuePreview || [];
        this.applyDashboardData(data);
        this.summaryLoaded = true;
      }, { force, onRefreshError }).then(() => {
        if (!failed && !this._destroyed) {
          this.summaryError = '';
          this._pollFailures.summary = this._dashboardRetryAt.summary = 0;
        }
      }).catch(onRefreshError)
        .finally(() => { this.summaryLoading = false; this._summaryRequest = null; });
      return this._summaryRequest;
    },

    loadDashboardActivity({ force = false } = {}) {
      if (this._destroyed || this.pageMode !== 'dashboard' || document.visibilityState === 'hidden') return Promise.resolve();
      if (this._activityRequest) return this._activityRequest;
      if (!force && Date.now() < this._dashboardRetryAt.activity) return Promise.resolve();
      this.activityLoading = !this.activityLoaded;
      let failed = false;
      const onRefreshError = error => { failed = true; this.dashboardRefreshFailed('activity', error); };
      this._activityRequest = API.loadAdminData("/admin/dashboard/activity", () => API.getAdminDashboardActivity({ force }), data => {
        if (this._destroyed) return;
        this.recentActivity = this.normalizeRecentActivity(data.recentActivity);
        this.activityLoaded = true;
      }, { force, onRefreshError }).then(() => {
        if (!failed && !this._destroyed) {
          this.activityError = '';
          this._pollFailures.activity = this._dashboardRetryAt.activity = 0;
        }
      }).catch(onRefreshError)
        .finally(() => { this.activityLoading = false; this._activityRequest = null; });
      return this._activityRequest;
    },

    dashboardRefreshFailed(section, error) {
      if (this._destroyed) return;
      const label = section === 'summary' ? 'Dashboard' : 'activity';
      this[`${section}Error`] = this[`${section}Loaded`]
        ? `Could not refresh ${label}. Data may be outdated.`
        : `Could not refresh ${label}.`;
      if (error.status === 0 || error.status >= 500 || error.status === 429) {
        const failures = this._pollFailures[section] = Math.min((this._pollFailures[section] || 0) + 1, 2);
        this._dashboardRetryAt[section] = Date.now() + 30000 * 2 ** failures;
      }
    },

    get dashboardSearchHasResults() {
      return this.dashboardSearchCustomers.length > 0
        || this.dashboardSearchPets.length > 0;
    },

    openDashboardSearch() {
      if (this.dashboardSearchQuery.trim()) {
        this.dashboardSearchOpen = true;
      }
    },

    closeDashboardSearch() {
      this.dashboardSearchOpen = false;
    },

    async searchDashboard() {
      const search = this.dashboardSearchQuery.trim();
      const requestId = ++this._dashboardSearchRequestId;

      if (!search) {
        this.dashboardSearchOpen = false;
        this.dashboardSearchLoading = false;
        this.dashboardSearchError = "";
        this.dashboardSearchCustomers = [];
        this.dashboardSearchPets = [];
        return;
      }

      this.dashboardSearchOpen = true;
      this.dashboardSearchLoading = true;
      this.dashboardSearchError = "";

      try {
        const data = await API.searchAdminDashboard(search);
        if (requestId !== this._dashboardSearchRequestId) return;

        this.dashboardSearchCustomers = data.customers || [];
        this.dashboardSearchPets = data.pets || [];
      } catch (error) {
        if (requestId !== this._dashboardSearchRequestId) return;

        this.dashboardSearchCustomers = [];
        this.dashboardSearchPets = [];
        this.dashboardSearchError = error.message || "Search is unavailable. Please try again.";
      } finally {
        if (requestId === this._dashboardSearchRequestId) {
          this.dashboardSearchLoading = false;
        }
      }
    },

    openDashboardCustomer(customer) {
      const params = new URLSearchParams({
        customer_id: String(customer.id),
        record_type: customer.recordType || "registered",
      });
      window.location.href = `./clients.html?${params.toString()}`;
    },

    openDashboardPet(pet) {
      const params = new URLSearchParams({
        customer_id: String(pet.ownerId),
        pet_id: String(pet.id),
        record_type: pet.ownerRecordType || "registered",
      });
      window.location.href = `./clients.html?${params.toString()}`;
    },

    // Keep the shared poll alive so transient failures recover without a reload.
    _recordPollFailure(intervalProp, label, error) {
      this._pollFailures[intervalProp] = (this._pollFailures[intervalProp] || 0) + 1;
      console.error(`${label} failed:`, error);
    },

    _resetPollFailures(intervalProp) {
      this._pollFailures[intervalProp] = 0;
    },

    // Exposes a small runtime API so backend scripts can update the dashboard safely.
    registerBridge() {
      window.adminDashboardUI = {
        setData: (payload) => this.applyDashboardData(payload),
        setConfig: (config) => this.mergeConfig(config),
        getState: () => this.getState(),
        runAction: (actionName, booking) =>
          this.runBookingAction(actionName, booking),
      };
    },

    // Reads optional preload config from global window variables.
    loadConfig() {
      this.mergeConfig(window.ADMIN_DASHBOARD_CONFIG || {});

      if (window.ADMIN_DASHBOARD_BOOTSTRAP) {
        this.config.bootstrap = window.ADMIN_DASHBOARD_BOOTSTRAP;
      }
    },

    // Merges new config values while preserving existing endpoint and handler maps.
    mergeConfig(nextConfig = {}) {
      const endpoints = {
        ...(this.config.endpoints || {}),
        ...(nextConfig.endpoints || {}),
      };
      const handlers = {
        ...(this.config.handlers || {}),
        ...(nextConfig.handlers || {}),
      };

      this.config = {
        ...this.config,
        ...nextConfig,
        endpoints,
        handlers,
      };
    },

    // Loads initial dashboard data from bootstrap payload, custom handler, or endpoint.
    async bootstrapDashboard() {
      if (this.config.bootstrap) {
        this.applyDashboardData(this.config.bootstrap);
        return;
      }

      const loadHandler = this.config.handlers?.loadDashboard;
      if (typeof loadHandler === "function") {
        const payload = await loadHandler({
          dashboard: this,
          state: this.getState(),
        });
        this.applyDashboardData(payload);
        return;
      }

      const endpointConfig = this.resolveEndpointConfig("loadDashboard");
      if (!endpointConfig) {
        return;
      }

      try {
        const payload = await this.requestEndpoint(
          "loadDashboard",
          null,
          endpointConfig,
        );
        this.applyDashboardData(payload);
      } catch (error) {
        console.error("Failed to load admin dashboard data:", error);
      }
    },

    setTab(tabName) {
      this.closeGroomingMenu();
      this.activeTab = tabName;
    },

    // Handles the walk-in booking button using a URL, handler, or fallback event.
    async handleWalkInBooking() {
      try {
        if (this.config.walkInBookingUrl) {
          window.location.href = this.interpolateUrl(
            this.config.walkInBookingUrl,
            null,
          );
          return;
        }

        const handler = this.config.handlers?.walkInBooking;
        if (typeof handler === "function") {
          await handler({
            dashboard: this,
            state: this.getState(),
          });
          return;
        }

        this.dispatchDashboardEvent("admin-dashboard:walk-in-requested", {
          state: this.getState(),
        });
        console.info(
          "Walk-in booking action triggered, but no handler is configured.",
        );
      } catch (error) {
        console.error("Walk-in booking handler failed:", error);
      }
    },

    // Opens a second confirmation before moving an Incoming booking into Queued.
    confirmCheckInBooking(booking) {
      this.openActionConfirmModal({
        action: "checkIn",
        booking,
        title: "Confirm Check In",
        message: "Are you sure you want to check in this visit?",
        confirmLabel: "Yes, Check In",
        busyLabel: "Checking in...",
        icon: "checkIn",
        variant: "primary",
      });
      this.actionConfirmModal.petSizes = (booking?.pets || []).filter((pet) => this.petGroomingState(pet) !== "cancelled").map((pet) => ({
        bookingPetId: pet.bookingPetId ?? pet.booking_pet_id ?? pet.id,
        name: pet.petName ?? pet.pet_name ?? pet.name ?? "Pet",
        weight: pet.weight ?? "—",
        currentSize: normalizePaymentSize(pet.size) || "",
        verified: Boolean(pet.sizeVerified),
        groomingEstimate: pet.groomingEstimate,
        species: pet.species ?? pet.petType,
        size: normalizePaymentSizeForPet(pet.size, pet.species ?? pet.petType),
        estimateFactors: [...(pet.groomingEstimate?.factors || [])],
        groomingPreference: pet.groomingEstimate?.preference || "",
      }));
      this.actionConfirmModal.internalStaffNote = booking?.internalStaffNote || "";
    },

    formatPaymentSizeLabel(value) {
      return formatPaymentSizeLabel(value);
    },

    getPaymentBaseSizeOptions(species) {
      return getPaymentBaseSizeOptions(species);
    },

    // Opens a second confirmation before moving a Queued booking into In-Progress.
    confirmStartGroomingBooking(booking) {
      if (this.isGroomerCapacityFull) {
        this.groomerCapacityError = "Groomer capacity is full. Finish a pet before starting another.";
        return;
      }

      this.openActionConfirmModal({
        action: "startGrooming",
        booking,
        title: "Confirm Start Grooming",
        message: "Are you sure you want to start grooming this visit?",
        confirmLabel: "Yes, Start",
        busyLabel: "Starting...",
        icon: "grooming",
        variant: "primary",
      });
    },

    // Confirms a per-pet start. The selected pet travels with the cloned booking
    // so the shared confirmation modal can continue using its existing contract.
    confirmStartGroomingPet(booking, pet) {
      if (this.petGroomingState(pet) !== "not_started" || pet?.isGroomingStarted || this.isGroomerCapacityFull) {
        if (this.isGroomerCapacityFull) {
          this.groomerCapacityError = "Groomer capacity is full. Finish a pet before starting another.";
        }
        return;
      }

      const petName = String(pet?.petName ?? pet?.pet_name ?? pet?.name ?? "this pet").trim();
      const isWalkIn = booking.bookingType === "Walk-In";
      const hasEarlierUnfinishedPets = this.hasEarlierQueuedUnfinishedPets(booking);
      this.openActionConfirmModal({
        action: "startPetGrooming",
        booking: {
          ...booking,
          actionPet: isWalkIn ? { ...pet } : { ...pet, estimateFactors: [...(pet.groomingEstimate?.factors || [])], groomingPreference: pet.groomingEstimate?.preference || "" },
        },
        title: hasEarlierUnfinishedPets ? "Queue Order Warning" : (isWalkIn ? "Start Grooming" : "Confirm Start Grooming"),
        message: hasEarlierUnfinishedPets
          ? "An earlier queue still has unfinished pets. Are you sure you want to start grooming this pet first?"
          : (isWalkIn ? `Start grooming for ${petName}?` : `Are you sure you want to start grooming for ${petName}?`),
        confirmLabel: "Yes, Start",
        busyLabel: "Starting...",
        icon: "grooming",
        variant: hasEarlierUnfinishedPets ? "warning" : "primary",
      });
    },

    isPetGroomingFinished(pet) {
      if (this.petGroomingState(pet) === "finished") {
        return true;
      }

      if (pet?.isGroomingFinished === true) {
        return true;
      }

      const finishedValues = [
        pet?.groomingFinishedAt,
        pet?.grooming_finished_at,
        pet?.groomingFinishedAtIso,
        pet?.grooming_finished_at_iso,
        pet?.groomingEndTime,
        pet?.grooming_end_time,
      ];

      return finishedValues.some((value) => {
        const text = String(value ?? "").trim().toLowerCase();
        return Boolean(text && !["-", "\u2014", "none", "null", "not provided"].includes(text));
      });
    },

    isPetGroomingStarted(pet) {
      if (["in_progress", "finished"].includes(
        this.petGroomingState(pet),
      )) {
        return true;
      }

      if (pet?.isGroomingStarted === true) {
        return true;
      }

      const startedValues = [
        pet?.groomingStartedAt,
        pet?.grooming_started_at,
        pet?.groomingStartedAtIso,
        pet?.grooming_started_at_iso,
        pet?.groomingStartTime,
        pet?.grooming_start_time,
      ];

      return startedValues.some((value) => {
        const text = String(value ?? "").trim().toLowerCase();
        return Boolean(text && !["-", "\u2014", "none", "null", "not provided"].includes(text));
      });
    },

    hasActiveGroomingPets(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      return pets.some((pet) => this.petGroomingState(pet) === "in_progress");
    },

    hasStartedGroomingPets(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      return pets.some((pet) => this.isPetGroomingStarted(pet));
    },

    hasActiveGroomingPetsForBooking(bookingId) {
      const id = String(bookingId ?? "");
      const booking = [...this.inProgressList, ...this.queuedList].find(
        (item) => String(item?.id ?? "") === id,
      );

      return this.hasActiveGroomingPets(booking);
    },

    hasUnfinishedQueuedPets(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      return pets.some((pet) => !this.isPetGroomingFinished(pet) && this.petGroomingState(pet) !== "cancelled");
    },

    getWaitingGroomingPets(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      return pets.filter((pet) => this.petGroomingState(pet) === "not_started");
    },

    petGroomingState(pet) {
      const explicit = String(
        pet?.groomingState ?? pet?.grooming_state ?? "",
      ).trim().toLowerCase();
      if (["not_started", "in_progress", "finished", "cancelled"].includes(explicit)) {
        return explicit;
      }

      if (explicit) return explicit;
      if (pet?.isGroomingFinished === true) return "finished";
      if (pet?.isGroomingStarted === true) return "in_progress";

      const finished = pet?.groomingFinishedAtIso
        ?? pet?.grooming_finished_at
        ?? pet?.groomingFinishedAt;
      if (finished) return "finished";

      const started = pet?.groomingStartedAtIso
        ?? pet?.grooming_start_time
        ?? pet?.groomingStartedAt;
      return started ? "in_progress" : "not_started";
    },

    petGroomingStateLabel(pet) {
      return {
        not_started: "Not started",
        in_progress: "In Progress",
        finished: "Finished",
        cancelled: "Cancelled",
      }[this.petGroomingState(pet)] || "Not started";
    },

    isPetGroomingInProgress(pet) {
      return this.petGroomingState(pet) === "in_progress";
    },

    hasEarlierQueuedUnfinishedPets(booking) {
      const selectedId = String(booking?.id ?? "");
      const selectedIndex = this.queuedList.findIndex(
        (queuedBooking) => String(queuedBooking?.id ?? "") === selectedId,
      );

      if (selectedIndex > 0) {
        return this.queuedList
          .slice(0, selectedIndex)
          .some((queuedBooking) => this.hasUnfinishedQueuedPets(queuedBooking));
      }

      const selectedQueueNumber = Number(booking?.queueNumber ?? 0);
      if (!Number.isFinite(selectedQueueNumber) || selectedQueueNumber <= 0) {
        return false;
      }

      return this.queuedList.some((queuedBooking) => {
        const queueNumber = Number(queuedBooking?.queueNumber ?? 0);
        return (
          Number.isFinite(queueNumber) &&
          queueNumber > 0 &&
          queueNumber < selectedQueueNumber &&
          this.hasUnfinishedQueuedPets(queuedBooking)
        );
      });
    },

    // Opens a second confirmation before finishing an In-Progress booking.
    confirmMarkBookingDone(booking) {
      this.openActionConfirmModal({
        action: "markDone",
        booking,
        title: "Confirm Finished",
        message: "Are you sure you want to mark this grooming visit as finished?",
        confirmLabel: "Yes, Finished",
        busyLabel: "Finishing...",
        icon: "finished",
        variant: "primary",
      });
    },

    // Confirms completion for one pet without finishing the owner booking early.
    confirmMarkPetDone(booking, pet) {
      if (!pet?.isGroomingStarted || pet?.isGroomingFinished) {
        return;
      }

      const petName = String(pet?.petName ?? pet?.pet_name ?? pet?.name ?? "this pet").trim();
      this.openActionConfirmModal({
        action: "markPetDone",
        booking: {
          ...booking,
          actionPet: { ...pet, estimateFactors: [...(pet.groomingEstimate?.factors || [])], groomingPreference: pet.groomingEstimate?.preference || "" },
        },
        title: "Confirm Finished",
        message: `Are you sure you want to mark ${petName} as finished?`,
        confirmLabel: "Yes, Finished",
        busyLabel: "Finishing...",
        icon: "finished",
        variant: "primary",
      });
    },

    confirmUndoCheckIn(booking) {
      if (!this.shouldShowUndoCheckIn(booking)) return;
      this.openActionConfirmModal({ action: "undoCheckIn", booking,
        title: "Undo Check-in", message: "", confirmLabel: "Undo Check-in",
        busyLabel: "Undoing...", icon: "undo", variant: "primary" });
    },

    confirmUndoPetStart(booking, pet) {
      if (!this.canUndoPetStart(booking, pet)) return;
      this.openActionConfirmModal({ action: "undoPetStart", booking: { ...booking, actionPet: pet },
        title: "Undo Start", message: "", confirmLabel: "Undo Start",
        busyLabel: "Undoing...", icon: "undo", variant: "primary" });
    },

    confirmCancelBooking(booking) {
      if (!this.shouldShowOwnerCancel(booking)) return;
      this.openActionConfirmModal({ action: "cancel", booking,
        title: booking.bookingType === "Walk-In" ? "Cancel walk-in?" : "Cancel pre-registration?", message: "", confirmLabel: "Confirm cancellation",
        busyLabel: "Cancelling...", icon: "cancel", variant: "danger" });
    },

    confirmCancelPet(booking, pet) {
      if (!this.canCancelPet(booking, pet)) return;
      const title = `Cancel ${pet.petName ?? pet.pet_name ?? pet.name}`;
      this.openActionConfirmModal({ action: "cancelPet", booking: { ...booking, actionPet: pet },
        title, message: "", confirmLabel: "Confirm cancellation",
        busyLabel: "Cancelling...", icon: "cancel", variant: "danger" });
    },

    openActionConfirmModal({
      action,
      booking,
      title,
      message,
      confirmLabel,
      busyLabel,
      icon = "checkIn",
      variant = "primary",
    }) {
      this.actionConfirmModal = {
        open: true,
        action,
        booking: this.cloneBooking(booking),
        title,
        message,
        confirmLabel,
        busyLabel,
        icon,
        variant,
        cancellationReason: "",
        petSizes: [],
        internalStaffNote: "",
        busy: false,
        error: "",
      };
      this.$nextTick(() => this.$refs?.groomingActionCancel?.focus());
    },

    closeActionConfirmModal(force = false) {
      if (this.actionConfirmModal.busy && !force) {
        return;
      }

      this.actionConfirmModal = {
        open: false,
        action: "",
        booking: null,
        title: "",
        message: "",
        confirmLabel: "",
        busyLabel: "",
        icon: "checkIn",
        variant: "primary",
        cancellationReason: "",
        petSizes: [],
        internalStaffNote: "",
        busy: false,
        error: "",
      };
    },

    async executeConfirmedBookingAction() {
      const { action, booking } = this.actionConfirmModal;
      if (this.actionConfirmModal.busy || !action || !booking) {
        return;
      }

      if (["cancel", "cancelPet"].includes(action) && !this.actionConfirmModal.cancellationReason.trim()) {
        this.actionConfirmModal.error = "Enter a cancellation reason.";
        return;
      }
      this.actionConfirmModal.busy = true;
      this.actionConfirmModal.error = "";

      try {
        if (action === "checkIn") {
          await this.checkInBooking(booking, this.actionConfirmModal.petSizes, this.actionConfirmModal.internalStaffNote);
        } else if (action === "startGrooming") {
          await this.startGroomingBooking(booking);
        } else if (action === "startPetGrooming") {
          await this.runBookingAction("startPetGrooming", booking);
        } else if (action === "markDone") {
          await this.markBookingDone(booking);
        } else if (action === "markPetDone") {
          await this.runBookingAction("markPetDone", booking);
        } else if (action === "cancel") {
          await this.cancelBooking(
            booking,
            this.actionConfirmModal.cancellationReason,
          );
        } else if (action === "undoCheckIn") {
          await this.runBookingAction("undoCheckIn", booking);
        } else if (action === "undoPetStart" || action === "cancelPet") {
          await this.runBookingAction(action, booking, { cancellationReason: this.actionConfirmModal.cancellationReason });
        }
        this.closeActionConfirmModal(true);
      } catch (error) {
        this.actionConfirmModal.error = error.data?.detail ? `${error.message}\n${error.data.detail}` : error.message || "Action failed. Please try again.";
      } finally {
        this.actionConfirmModal.busy = false;
      }
    },

    // Starts the Incoming -> Queued transition for a booking.
    async checkInBooking(booking, petSizes = [], internalStaffNote = "") {
      await this.runBookingAction("checkIn", booking, {
        petSizes: petSizes.map((pet) => ({ booking_pet_id: pet.bookingPetId, size: pet.size, estimate_factors: pet.estimateFactors || [] })),
        internalStaffNote,
      });
    },

    async cancelBooking(booking, cancellationReason = "") {
      await this.runBookingAction("cancel", booking, { cancellationReason });
    },

    // Starts the Queued -> In-Progress transition for a booking.
    async startGroomingBooking(booking) {
      await this.runBookingAction("startGrooming", booking);
    },

    // Starts the In-Progress -> For Pickup transition for a booking.
    async markBookingDone(booking) {
      await this.runBookingAction("markDone", booking);
    },

    // Starts the For Pickup -> Archived transition for a booking.
    async archiveBooking(booking) {
      await this.runBookingAction("archive", booking);
    },

    // Opens booking details using a handler, endpoint response, detail URL, or event hook.
    async viewBookingDetails(booking) {
      try {
        const detailHandler = this.config.handlers?.viewDetails;
        if (typeof detailHandler === "function") {
          await detailHandler({
            booking: this.cloneBooking(booking),
            dashboard: this,
            state: this.getState(),
          });
          return;
        }

        const endpointConfig = this.resolveEndpointConfig("viewDetails", booking);
        if (endpointConfig) {
          const response = await this.requestEndpoint(
            "viewDetails",
            booking,
            endpointConfig,
          );
          const redirectUrl =
            response?.redirectUrl ||
            response?.detailsUrl ||
            response?.url ||
            "";

          if (redirectUrl) {
            window.location.href = redirectUrl;
            return;
          }
        }

        if (this.config.detailPageUrl) {
          window.location.href = this.interpolateUrl(
            this.config.detailPageUrl,
            booking,
          );
          return;
        }

        this.dispatchDashboardEvent("admin-dashboard:view-details", {
          booking: this.cloneBooking(booking),
          state: this.getState(),
        });
        console.info("View Details clicked, but no detail handler is configured.");
      } catch (error) {
        console.error("View details handler failed:", error);
      }
    },

    // Runs a booking action with busy-state protection and backend/event integration.
    async runBookingAction(actionName, booking, actionOptions = {}) {
      if (!booking || booking.id === undefined || booking.id === null) {
        console.warn(`Cannot run ${actionName}: missing booking id.`);
        return;
      }

      const actionKey = this.getActionKey(actionName, booking.id);
      if (this.pendingActions[actionKey]) {
        return;
      }

      this.pendingActions = {
        ...this.pendingActions,
        [actionKey]: true,
      };

      try {
        if (["startGrooming", "startPetGrooming"].includes(actionName)) {
          this.groomerCapacityError = "";
        }

        this.dispatchDashboardEvent("admin-dashboard:action-start", {
          action: actionName,
          booking: this.cloneBooking(booking),
          state: this.getState(),
        });

        const handler = this.config.handlers?.[actionName];
        let response = null;

        if (typeof handler === "function") {
          response = await handler({
            ...actionOptions,
            booking: this.cloneBooking(booking),
            dashboard: this,
            state: this.getState(),
          });
        } else {
          const endpointConfig = this.resolveEndpointConfig(actionName, booking);
          if (endpointConfig) {
            response = await this.requestEndpoint(
              actionName,
              booking,
              endpointConfig,
            );
          } else {
            this.dispatchDashboardEvent("admin-dashboard:action-requested", {
              action: actionName,
              booking: this.cloneBooking(booking),
              state: this.getState(),
            });
          }
        }

        this.applyActionResponse(actionName, booking, response);

        this.dispatchDashboardEvent("admin-dashboard:action-success", {
          action: actionName,
          booking: this.cloneBooking(booking),
          response,
          state: this.getState(),
        });
      } catch (error) {
        console.error(`Admin dashboard ${actionName} failed:`, error);
        if (["startGrooming", "startPetGrooming"].includes(actionName)) {
          this.groomerCapacityError = error.message;
          await this.loadAdminBookings();
        }
        this.dispatchDashboardEvent("admin-dashboard:action-error", {
          action: actionName,
          booking: this.cloneBooking(booking),
          error: error.message,
          state: this.getState(),
        });
        throw error;
      } finally {
        const nextPendingActions = { ...this.pendingActions };
        delete nextPendingActions[actionKey];
        this.pendingActions = nextPendingActions;
      }
    },

    // Applies whatever shape the backend returns after a booking action completes.
    applyActionResponse(actionName, booking, response) {
      if (!response) {
        if (this.config.enableOptimisticUpdates) {
          this.applyOptimisticTransition(actionName, booking);
        }
        return;
      }

      const payload =
        response.dashboardData ||
        response.dashboard ||
        response.state ||
        response.lists ||
        (this.isDashboardPayload(response) ? response : null);

      if (payload) {
        this.applyDashboardData(payload);
        return;
      }

      const updatedBooking = response.booking || response.record || response.data;
      if (updatedBooking && typeof updatedBooking === "object") {
        this.applyBookingUpdate(actionName, booking, updatedBooking);
        return;
      }

      if (response.redirectUrl || response.url) {
        window.location.href = response.redirectUrl || response.url;
        return;
      }

      if (this.config.enableOptimisticUpdates) {
        this.applyOptimisticTransition(actionName, booking);
      }

    },

    // Replaces a single booking across lists after the backend returns an updated record.
    applyBookingUpdate(actionName, previousBooking, updatedBooking) {
      const normalizedBooking = this.normalizeBooking(
        {
          ...previousBooking,
          ...updatedBooking,
        },
        this.getNextStatusForAction(actionName, previousBooking?.status),
      );

      this.removeBookingFromLists(previousBooking?.id ?? normalizedBooking.id);

      const listKey = this.getListKeyForStatus(normalizedBooking.status);
      if (listKey) {
        this[listKey] = this.insertBookingSorted(this[listKey], normalizedBooking);
      }

    },

    // Moves a booking locally when optimistic updates are enabled and no payload is returned.
    applyOptimisticTransition(actionName, booking) {
      const nextStatus = this.getNextStatusForAction(actionName, booking.status);
      if (!nextStatus || nextStatus === "archived") {
        this.removeBookingFromLists(booking.id);
        return;
      }

      const updatedBooking = {
        ...booking,
        status: nextStatus,
      };

      this.applyBookingUpdate(actionName, booking, updatedBooking);
    },

    // Normalizes and applies dashboard metrics plus all booking lists into Alpine state.
    applyDashboardData(payload = {}) {
      if ('activeTodayCount' in payload) this.activeTodayCount = payload.activeTodayCount;
      if (payload.estimateRules) window.GroomingEstimates.configure(payload.estimateRules);
      const nextPayload = this.normalizeDashboardPayload(payload);

      if ("todayCount" in nextPayload) {
        this.todayCount = nextPayload.todayCount;
      }

      if ("weekCount" in nextPayload) {
        this.weekCount = nextPayload.weekCount;
      }

      if ("revenueToday" in nextPayload) {
        this.revenueToday = nextPayload.revenueToday;
      }

      if ("revenuePaymentCount" in nextPayload) {
        this.revenuePaymentCount = nextPayload.revenuePaymentCount;
      }

      if ("waitingNow" in nextPayload) this.waitingNow = nextPayload.waitingNow;

      if ("currentCapacity" in nextPayload) {
        this.currentCapacity = nextPayload.currentCapacity;
      }

      if ("maxCapacity" in nextPayload) {
        this.maxCapacity = nextPayload.maxCapacity;
      }


      if ("activeGroomers" in nextPayload) {
        this.activeGroomers = nextPayload.activeGroomers;
      }
      if ("groomingWorkload" in nextPayload) this.groomingWorkload = nextPayload.groomingWorkload;

      if ("activeGroomingPets" in nextPayload) {
        this.activeGroomingPets = nextPayload.activeGroomingPets;
      }

      if ("recentActivity" in nextPayload) {
        this.recentActivity = nextPayload.recentActivity;
      }

      if ("incomingList" in nextPayload) {
        this.incomingList = nextPayload.incomingList;
      }

      if ("queuedList" in nextPayload) {
        this.queuedList = nextPayload.queuedList;
      }

      if ("inProgressList" in nextPayload) {
        this.inProgressList = nextPayload.inProgressList;
      }

      if ("forPickupList" in nextPayload) {
        this.forPickupList = nextPayload.forPickupList;
      }

      if ("forPaymentList" in nextPayload) {
        this.forPaymentList = nextPayload.forPaymentList;
      }

      if ("releasedList" in nextPayload) {
        this.releasedList = nextPayload.releasedList;
      }

      if (this.detailsModalOpen && this.detailsBooking) {
        const current = [
          ...this.incomingList, ...this.queuedList, ...this.inProgressList,
          ...this.forPaymentList, ...this.forPickupList, ...this.releasedList,
        ].find((booking) => String(booking.id) === String(this.detailsBooking.id));
        if (current) {
          this.detailsBooking = current;
          if ((this.noteEditor.kind === "staff" && !this.canEditInternalStaffNote())
            || (this.noteEditor.kind === "pet" && !this.canEditGroomingVisitNotes())) {
            this.cancelNoteEdit();
          }
        }
      }

      this.dispatchDashboardEvent("admin-dashboard:data-applied", {
        state: this.getState(),
      });
    },

    // Accepts several backend payload shapes and converts them into one dashboard format.
    normalizeDashboardPayload(payload) {
      const bookingsByStatus = Array.isArray(payload.bookings)
        ? this.groupBookingsByStatus(payload.bookings)
        : {};

      const summary = payload.summary || payload.metrics || {};
      const capacity = payload.capacity || {};
      const groomerCapacity = payload.groomerCapacity || payload.groomer_capacity || {};

      const nextPayload = {};
      if ("groomingWorkload" in payload) nextPayload.groomingWorkload = payload.groomingWorkload;

      if (this.hasValue(payload.todayCount) || this.hasValue(summary.today)) {
        nextPayload.todayCount = this.toNumber(
          payload.todayCount ?? summary.today,
          this.todayCount,
        );
      }

      if (this.hasValue(payload.weekCount) || this.hasValue(summary.week)) {
        nextPayload.weekCount = this.toNumber(
          payload.weekCount ?? summary.week,
          this.weekCount,
        );
      }

      if (
        this.hasValue(payload.revenueToday) ||
        this.hasValue(summary.revenueToday) ||
        this.hasValue(summary.revenue_today)
      ) {
        nextPayload.revenueToday = this.toNumber(
          payload.revenueToday ?? summary.revenueToday ?? summary.revenue_today,
          this.revenueToday,
        );
      }

      if (
        this.hasValue(payload.revenuePaymentCount) ||
        this.hasValue(summary.revenuePaymentCount) ||
        this.hasValue(summary.revenue_payment_count)
      ) {
        nextPayload.revenuePaymentCount = this.toNumber(
          payload.revenuePaymentCount ??
            summary.revenuePaymentCount ??
            summary.revenue_payment_count,
          this.revenuePaymentCount,
        );
      }

      if (this.hasValue(payload.waitingNow) || this.hasValue(summary.waitingNow)) {
        nextPayload.waitingNow = this.toNumber(payload.waitingNow ?? summary.waitingNow, 0);
      }

      if (
        this.hasValue(payload.currentCapacity) ||
        this.hasValue(capacity.current)
      ) {
        nextPayload.currentCapacity = this.toNumber(
          payload.currentCapacity ?? capacity.current,
          this.currentCapacity,
        );
      }

      if (this.hasValue(payload.maxCapacity) || this.hasValue(capacity.max)) {
        nextPayload.maxCapacity = this.toNumber(
          payload.maxCapacity ?? capacity.max,
          this.maxCapacity,
        );
      }

      if (
        this.hasValue(payload.activeGroomers) ||
        this.hasValue(groomerCapacity.groomers_on_duty)
      ) {
        nextPayload.activeGroomers = this.toNumber(
          payload.activeGroomers ?? groomerCapacity.groomers_on_duty,
          this.activeGroomers,
        );
      }

      if (
        this.hasValue(payload.activeGroomingPets) ||
        this.hasValue(groomerCapacity.active_pets)
      ) {
        nextPayload.activeGroomingPets = this.toNumber(
          payload.activeGroomingPets ?? groomerCapacity.active_pets,
          this.activeGroomingPets,
        );
      }

      if ("recentActivity" in payload || "recent_activity" in payload) {
        nextPayload.recentActivity = this.normalizeRecentActivity(
          payload.recentActivity ?? payload.recent_activity,
        );
      }

      if (
        "incomingList" in payload ||
        "incoming" in payload ||
        "incoming" in bookingsByStatus
      ) {
        nextPayload.incomingList = this.normalizeList(
          payload.incomingList ?? payload.incoming ?? bookingsByStatus.incoming,
          "incoming",
        );
      }

      if (
        "queuedList" in payload ||
        "queued" in payload ||
        "queued" in bookingsByStatus
      ) {
        nextPayload.queuedList = this.normalizeList(
          payload.queuedList ?? payload.queued ?? bookingsByStatus.queued,
          "queued",
        );
      }

      if (
        "inProgressList" in payload ||
        "inProgress" in payload ||
        "in_progress" in bookingsByStatus ||
        "in-progress" in bookingsByStatus
      ) {
        nextPayload.inProgressList = this.normalizeList(
          payload.inProgressList ??
            payload.inProgress ??
            bookingsByStatus["in_progress"] ??
            bookingsByStatus["in-progress"],
          "in-progress",
        );
      }

      if (
        "forPickupList" in payload ||
        "forPickup" in payload ||
        "for_pickup" in bookingsByStatus ||
        "for-pickup" in bookingsByStatus
      ) {
        nextPayload.forPickupList = this.normalizeList(
          payload.forPickupList ??
            payload.forPickup ??
            bookingsByStatus["for_pickup"] ??
            bookingsByStatus["for-pickup"],
          "for-pickup",
        );
      }

      if (
        "forPaymentList" in payload ||
        "forPayment" in payload ||
        "for_payment" in bookingsByStatus ||
        "for-payment" in bookingsByStatus
      ) {
        nextPayload.forPaymentList = this.normalizeList(
          payload.forPaymentList ??
            payload.forPayment ??
            bookingsByStatus["for_payment"] ??
            bookingsByStatus["for-payment"],
          "for-payment",
        );
      }

      if (
        "releasedList" in payload ||
        "released" in bookingsByStatus
      ) {
        nextPayload.releasedList = this.normalizeList(
          payload.releasedList ?? bookingsByStatus["released"],
          "to-be-picked-up",
        );
      }

      this.promoteStartedQueuedBookings(nextPayload);

      return nextPayload;
    },

    promoteStartedQueuedBookings(nextPayload) {
      if (!Array.isArray(nextPayload.queuedList)) {
        return;
      }

      const queuedBookings = [];
      const promotedBookings = [];

      for (const booking of nextPayload.queuedList) {
        if (this.hasStartedGroomingPets(booking)) {
          promotedBookings.push(
            this.normalizeBooking(
              {
                ...booking,
                status: "in-progress",
              },
              "in-progress",
            ),
          );
        } else {
          queuedBookings.push(booking);
        }
      }

      if (promotedBookings.length === 0) {
        return;
      }

      const existingInProgressIds = new Set(
        (nextPayload.inProgressList || []).map((booking) => String(booking?.id ?? "")),
      );

      nextPayload.queuedList = queuedBookings;
      nextPayload.inProgressList = [
        ...(nextPayload.inProgressList || []),
        ...promotedBookings.filter(
          (booking) => !existingInProgressIds.has(String(booking?.id ?? "")),
        ),
      ].sort((left, right) => this.compareBookings(left, right));
    },

    // Normalizes a booking list and keeps the rendered order stable.
    normalizeList(list, fallbackStatus) {
      if (!Array.isArray(list)) {
        return [];
      }

      return list
        .map((booking) => this.normalizeBooking(booking, fallbackStatus))
        .sort((left, right) => this.compareBookings(left, right));
    },

    // Normalizes one booking record so the template can safely read consistent fields.
    normalizeBooking(booking, fallbackStatus) {
      const normalizedStatus = this.normalizeStatus(
        booking?.status || fallbackStatus,
      );
      const bookingId =
        booking?.id ??
        booking?.bookingId ??
        booking?.appointmentId ??
        booking?.queueNumber ??
        this.createFallbackId();

      return {
        ...booking,
        id: bookingId,
        queueNumber: this.toNumber(
          booking?.queueNumber ?? booking?.queue ?? 0,
          0,
        ),
        ownerName: this.toStringValue(
          booking?.ownerName ?? booking?.clientName ?? booking?.owner,
        ),
        contactNumber: this.toStringValue(
          booking?.contactNumber ?? booking?.phone ?? booking?.contact,
        ),
        petName: this.toStringValue(booking?.petName),
        petType: this.formatBookingPetTypes(booking),
        breed: this.toStringValue(booking?.breed),
        serviceLabel: this.toStringValue(
          booking?.serviceLabel ?? booking?.service ?? booking?.packageLabel,
        ),
        appointmentDate: this.toStringValue(
          booking?.appointmentDate ?? booking?.date,
        ),
        appointmentTime: this.toStringValue(
          booking?.appointmentTime ?? booking?.time,
        ),
        dropOffTime: this.toStringValue(
          booking?.dropOffTime ?? booking?.dropoffTime,
        ),
        startedAt: this.toStringValue(booking?.startedAt),
        completedAt: this.toStringValue(booking?.completedAt),
        clientNotified: Boolean(booking?.clientNotified),
        paid: Boolean(booking?.paid),
        status: normalizedStatus,
      };
    },

    // Groups a flat bookings array by normalized status values.
    groupBookingsByStatus(bookings) {
      return bookings.reduce((groups, booking) => {
        const status = this.normalizeStatus(booking?.status);
        if (!status) {
          return groups;
        }

        if (!groups[status]) {
          groups[status] = [];
        }

        groups[status].push(booking);
        return groups;
      }, {});
    },

    // Sorts bookings primarily by queue number and then by owner name.
    compareBookings(left, right) {
      const leftQueue = Number(left?.queueNumber ?? 0);
      const rightQueue = Number(right?.queueNumber ?? 0);

      if (leftQueue !== rightQueue) {
        return leftQueue - rightQueue;
      }

      return String(left?.ownerName || "").localeCompare(
        String(right?.ownerName || ""),
      );
    },

    // Inserts a booking into a list and re-sorts the result.
    insertBookingSorted(list, booking) {
      return [...list, booking].sort((left, right) =>
        this.compareBookings(left, right),
      );
    },

    // ── QUEUE ETA ENGINE ─────────────────────────────────────────────────────
    _computeQueueETAs() {
      return Object.fromEntries(Object.entries(this.groomingWorkload?.pets || {}).map(([id, pet]) => {
        const completion = pet.projected_completion ? new Date(pet.projected_completion).getTime() : NaN;
        return [id, { estDoneMin: completion, estDoneMax: completion, unavailable: !Number.isFinite(completion),
          queueWait: pet.queue_wait_minutes, start: pet.projected_start, state: pet.state }];
      }));
    },

    get petETAs() { return this._computeQueueETAs(); },

    get queueSummary() {
      const waiting = [...this.inProgressList, ...this.queuedList].flatMap((booking) => this.getWaitingGroomingPets(booking));
      const estimates = waiting.map((pet) => pet.groomingEstimate).filter(Boolean);
      const avgWaitLabel = estimates.length && estimates.length === waiting.length
        ? window.GroomingEstimates.formatRange(estimates.reduce((sum, e) => sum + e.minMinutes, 0) / estimates.length,
            estimates.reduce((sum, e) => sum + e.maxMinutes, 0) / estimates.length) : "—";
      const entries = Object.values(this.petETAs);
      const lastDoneLabel = entries.some((entry) => entry.overdue || entry.unavailable) ? "Awaiting groomer availability"
        : entries.length ? window.GroomingEstimates.formatTimeWindow(Math.max(...entries.map((entry) => entry.estDoneMin)), Math.max(...entries.map((entry) => entry.estDoneMax))) : null;
      return { petsWaiting: waiting.length, avgWaitLabel, lastDoneLabel };
    },

    get showGroomingWorkload() {
      const summary = this.queueSummary;
      return summary.avgWaitLabel !== "—" || Boolean(summary.lastDoneLabel || this.groomingWorkloadMessage);
    },

    get isGroomerCapacityFull() { return this.activeGroomingPets >= this.activeGroomers; },

    get groomingWorkloadMessage() {
      const finish = this.groomingWorkload?.projected_last_completion;
      const close = this.groomingWorkload?.closing_time;
      if (finish && close && new Date(finish) > new Date(close)) {
        return `Projected completion exceeds grooming hours · ${this.workloadClosingLabel}`;
      }
      const state = this.groomingWorkload?.state;
      return state === "Needs staff action" ? "Current grooming workload needs attention"
        : state === "At risk" ? "Schedule at risk"
        : this.isGroomerCapacityFull && this.queueSummary.petsWaiting > 0 ? "Groomer capacity reached" : "";
    },

    get workloadClosingLabel() {
      const close = this.groomingWorkload?.closing_time;
      return close ? `Grooming closes at ${window.GroomingEstimates.formatTimeWindow(new Date(close).getTime(), new Date(close).getTime())}` : "";
    },

    getPetWorkloadState(pet) {
      return this.groomingWorkload?.pets?.[String(pet.bookingPetId ?? pet.id)]?.state || "";
    },

    get operationalBookings() {
      return ({ queued: this.queuedList, "in-progress": this.inProgressList,
        "for-payment": this.forPaymentList, "to-be-picked-up": this.releasedList })[this.activeTab] || [];
    },

    groomingStatusLabel(booking) {
      const status = this.normalizeStatus(booking?.status);
      return ({ incoming: "Incoming", queued: "Waiting", "in-progress": "In Progress",
        "for-payment": booking?.paid ? "Paid & Ready" : "Awaiting Payment",
        "for-pickup": booking?.paid ? "Paid & Ready" : "Awaiting Payment",
        "to-be-picked-up": "Paid & Ready" })[status] || "";
    },

    groomingCardTone(booking) {
      return ({ queued: "waiting", "in-progress": "progress", "for-payment": "payment",
        "for-pickup": "payment", "to-be-picked-up": "pickup" })[this.normalizeStatus(booking?.status)] || "incoming";
    },

    groomingPetCount(booking) {
      const count = booking?.pets?.length || booking?.numberOfPets || (booking?.petName ? 1 : 0);
      return `${count} ${count === 1 ? "pet" : "pets"}`;
    },

    incomingPetSummary(booking) {
      const pets = this.getIncomingPets(booking);
      const name = pets[0]?.petName ?? pets[0]?.pet_name ?? pets[0]?.name ?? "";
      return pets.length > 1 ? `${this.groomingPetCount(booking)} · ${name} + ${pets.length - 1} more` : name;
    },

    groomingBookingTiming(booking) {
      const status = this.normalizeStatus(booking?.status);
      if (status === "queued") return booking.dropOffTime ? `Dropped off ${booking.dropOffTime}` : "";
      if (status === "in-progress") return booking.startedAt ? `Started ${booking.startedAt}` : "";
      return booking.completedAt ? `Finished ${booking.completedAt}` : "";
    },

    isGroomingBookingExpanded(booking) {
      return this.normalizeStatus(booking.status) === "queued"
        ? this.isQueuedBookingExpanded(booking.id) : this.isInProgressBookingExpanded(booking.id);
    },

    toggleGroomingBooking(booking) {
      this.closeGroomingMenu();
      if (this.normalizeStatus(booking.status) === "queued") this.toggleQueuedBooking(booking.id);
      else this.toggleInProgressBooking(booking.id);
    },

    toggleGroomingCardFromPointer(event, booking) {
      if (!["queued", "in-progress"].includes(this.normalizeStatus(booking.status)) || !booking.pets?.length) return;
      if (event.target.closest('button, a, input, textarea, select, label, [role="menu"]')) return;
      this.toggleGroomingBooking(booking);
    },

    openGroomingMenu(booking, event, focusLast = false) {
      this.groomingMenuBookingId = booking.id;
      this._groomingMenuTrigger = event.currentTarget;
      this.$nextTick(() => {
        const items = this._groomingMenuTrigger?.parentElement.querySelectorAll('[role="menuitem"]');
        items?.[focusLast ? items.length - 1 : 0]?.focus();
      });
    },

    toggleGroomingMenu(booking, event) {
      if (this.groomingMenuBookingId === booking.id) this.closeGroomingMenu();
      else this.openGroomingMenu(booking, event);
    },

    closeGroomingMenu(restoreFocus = false) {
      const trigger = this._groomingMenuTrigger;
      this.groomingMenuBookingId = null;
      this._groomingMenuTrigger = null;
      if (restoreFocus) trigger?.focus();
    },

    handleGroomingMenuKeydown(event) {
      if (event.key === "Escape") {
        event.preventDefault();
        this.closeGroomingMenu(true);
      } else if (event.key === "Tab") {
        this.closeGroomingMenu(true);
      } else if (["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
        event.preventDefault();
        const items = [...event.currentTarget.querySelectorAll('[role="menuitem"]')];
        const current = items.indexOf(document.activeElement);
        const next = event.key === "Home" ? 0 : event.key === "End" ? items.length - 1
          : (current + (event.key === "ArrowDown" ? 1 : -1) + items.length) % items.length;
        items[next]?.focus();
      }
    },

    groomingSelectionLabels(pet) {
      const factors = new Map(this.estimateFactorOptions.map((option) => [option.value, option.label]));
      return [...new Set([pet?.groomingEstimate?.preferenceLabel,
        ...(pet?.groomingEstimate?.factors || []).map((factor) => factors.get(factor))]
        .filter((label) => label && label.trim() && label !== "—"))];
    },

    groomingPetIdentity(pet) {
      return [pet.species, pet.breed, this.formatPetCardValue(pet.size, ""), pet.weight, pet.furType]
        .filter((value) => value && value !== "—").join(" · ");
    },

    getBookingETA(booking) {
      const map = this.petETAs;
      const entries = (booking.pets || []).filter((pet) => !this.isPetGroomingFinished(pet) && this.petGroomingState(pet) !== "cancelled").map((pet) => map[String(pet.bookingPetId ?? pet.id ?? "")]).filter(Boolean);
      if (!entries.length) return null;
      if (entries.some((entry) => entry.overdue)) return "Taking longer than estimated";
      if (entries.some((entry) => entry.unavailable)) return "Awaiting groomer availability";
      return window.GroomingEstimates.formatTimeWindow(Math.max(...entries.map((entry) => entry.estDoneMin)), Math.max(...entries.map((entry) => entry.estDoneMax)));
    },

    getPetElapsed(pet) {
      const start = new Date(pet.groomingStartedAtIso).getTime();
      if (!Number.isFinite(start)) return "";
      const minutes = Math.round((Date.now() - start) / 60000);
      return minutes > 0 ? `${window.GroomingEstimates.formatDuration(minutes)} elapsed` : "";
    },

    getPetEstDone(booking, pet) {
      const entry = this.petETAs[String(pet.bookingPetId ?? pet.id ?? "")];
      if (!entry) return "";
      if (entry.overdue) return "Taking longer than estimated";
      if (entry.unavailable) return "Awaiting groomer availability";
      return `Est. ready: ${window.GroomingEstimates.formatTimeWindow(entry.estDoneMin, entry.estDoneMax)}`;
    },

    getPetEstimateLabel(pet) {
      return pet.groomingEstimate?.formatted || "—";
    },

    get estimateFactorOptions() { return window.GroomingEstimates.factors(); },

    getEstimatePreferences(pet) {
      const booking = this.actionConfirmModal.booking;
      const packageId = (booking?.services || []).find((service) => String(service.bookingPetId) === String(pet.bookingPetId ?? pet.id) && window.GroomingEstimates.preferences(service.slug).length)?.slug;
      return window.GroomingEstimates.preferences(packageId);
    },

    assessmentPreview(pet) {
      const snapshot = pet.groomingEstimate;
      const original = this.actionConfirmModal.booking?.pets?.find((item) => String(item.bookingPetId ?? item.id) === String(pet.bookingPetId ?? pet.id));
      if (snapshot && normalizePaymentSize(pet.size) === normalizePaymentSize(original?.size)
          && (pet.groomingPreference || null) === snapshot.preference
          && JSON.stringify(pet.estimateFactors || []) === JSON.stringify(snapshot.factors || [])) return snapshot.formatted;
      const booking = this.actionConfirmModal.booking;
      const services = (booking?.services || []).filter((service) => String(service.bookingPetId) === String(pet.bookingPetId ?? pet.id));
      const packageId = window.GroomingEstimates.packageForServices(services.map((service) => service.slug));
      return window.GroomingEstimates.calculate({ servicePackage: packageId, groomingPreference: pet.groomingPreference,
        estimateFactors: pet.estimateFactors, alaCarteServices: services.filter((service) => service.slug !== packageId).map((service) => service.slug) },
        normalizePaymentSize(pet.size), { allowMissingPreference: true })?.formatted || pet.groomingEstimate?.formatted || "—";
    },

    async setActiveGroomers(n) {
      const nextValue = Math.min(5, Math.max(1, Number(n) || 1));
      if (this.groomerCapacityBusy || nextValue === this.activeGroomers) {
        return;
      }

      this.groomerCapacityBusy = true;
      this.groomerCapacityError = "";

      try {
        const response = await API.adminUpdateGroomersOnDuty(nextValue);
        this.activeGroomers = Number(response?.groomers_on_duty) || nextValue;
        await this.loadAdminBookings();
      } catch (error) {
        this.groomerCapacityError = error.message || "Unable to update groomers on duty.";
      } finally {
        this.groomerCapacityBusy = false;
      }
    },
    // ── END QUEUE ETA ENGINE ─────────────────────────────────────────────────

    // Removes a booking from every visible status list using its id.
    removeBookingFromLists(bookingId) {
      const id = String(bookingId);
      this.incomingList    = this.incomingList.filter((item) => String(item.id) !== id);
      this.queuedList      = this.queuedList.filter((item) => String(item.id) !== id);
      this.inProgressList  = this.inProgressList.filter((item) => String(item.id) !== id);
      this.forPickupList   = this.forPickupList.filter((item) => String(item.id) !== id);
      this.forPaymentList  = this.forPaymentList.filter((item) => String(item.id) !== id);
      this.releasedList    = this.releasedList.filter((item) => String(item.id) !== id);
    },

    // Maps a frontend action name to the next booking status.
    getNextStatusForAction(actionName, currentStatus) {
      const normalizedCurrentStatus = this.normalizeStatus(currentStatus);
      const actionStatusMap = {
        checkIn: "queued",
        undoCheckIn: "incoming",
        startGrooming: "in-progress",
        markDone: "for-payment",
        cancel: "cancelled",
        archive: "archived",
        pickedUp: "archived",
      };

      return actionStatusMap[actionName] || normalizedCurrentStatus;
    },

    // Resolves a booking status into the matching Alpine list property name.
    getListKeyForStatus(status) {
      const normalizedStatus = this.normalizeStatus(status);
      const listMap = {
        incoming: "incomingList",
        queued: "queuedList",
        "in-progress": "inProgressList",
        "for-pickup": "forPickupList",
        "for-payment": "forPaymentList",
        "to-be-picked-up": "releasedList",
      };

      return listMap[normalizedStatus] || "";
    },

    // Builds a unique key for tracking the loading state of one booking action.
    getActionKey(actionName, bookingId) {
      return `${actionName}:${bookingId}`;
    },

    // Returns whether a specific booking action is currently running.
    isActionBusy(actionName, bookingId) {
      return Boolean(this.pendingActions[this.getActionKey(actionName, bookingId)]);
    },

    isQueuedBookingExpanded(bookingId) {
      return Boolean(this.expandedQueuedBookingIds[String(bookingId)]);
    },

    setQueuedBookingExpanded(bookingId, expanded) {
      this.expandedQueuedBookingIds = {
        ...this.expandedQueuedBookingIds,
        [String(bookingId)]: Boolean(expanded),
      };
    },

    toggleQueuedBooking(bookingId) {
      this.setQueuedBookingExpanded(
        bookingId,
        !this.isQueuedBookingExpanded(bookingId),
      );
    },

    isInProgressBookingExpanded(bookingId) {
      return Boolean(this.expandedInProgressBookingIds[String(bookingId)]);
    },

    setInProgressBookingExpanded(bookingId, expanded) {
      this.expandedInProgressBookingIds = {
        ...this.expandedInProgressBookingIds,
        [String(bookingId)]: Boolean(expanded),
      };
    },

    toggleInProgressBooking(bookingId) {
      this.setInProgressBookingExpanded(
        bookingId,
        !this.isInProgressBookingExpanded(bookingId),
      );
    },

    // Provides the temporary button label shown while an action is in progress.
    getActionLabel(actionName) {
      const labelMap = {
        checkIn: "Checking in...",
        startGrooming: "Grooming...",
        startPetGrooming: "Starting...",
        markDone: "Finishing...",
        markPetDone: "Finishing...",
        cancel: "Cancelling...",
        archive: "Archiving...",
      };

      return labelMap[actionName] || "Working...";
    },

    // Normalizes endpoint config so actions can accept either strings or config objects.
    resolveEndpointConfig(actionName, booking = null) {
      const endpointConfig = this.config.endpoints?.[actionName];
      if (!endpointConfig) {
        return null;
      }

      if (typeof endpointConfig === "string") {
        return {
          url: endpointConfig,
          method: actionName === "loadDashboard" || actionName === "viewDetails"
            ? "GET"
            : "POST",
        };
      }

      return {
        ...endpointConfig,
      };
    },

    // Sends the configured fetch request for a dashboard action or initial load.
    async requestEndpoint(actionName, booking, endpointConfig) {
      if (!endpointConfig?.url) {
        return null;
      }

      const method = (endpointConfig.method || "GET").toUpperCase();
      const headers = {
        "Content-Type": "application/json",
        ...(endpointConfig.headers || {}),
      };
      const url = this.interpolateUrl(endpointConfig.url, booking);
      const requestInit = {
        method,
        headers,
      };

      if (method !== "GET" && method !== "HEAD") {
        const payload =
          typeof endpointConfig.body === "function"
            ? endpointConfig.body({
                booking: this.cloneBooking(booking),
                action: actionName,
                state: this.getState(),
              })
            : endpointConfig.body;

        requestInit.body = JSON.stringify(
          payload ?? {
            id: booking?.id ?? null,
            booking: this.cloneBooking(booking),
            action: actionName,
          },
        );
      }

      const response = await fetch(url, requestInit);
      const responseData = await this.parseResponse(response);

      if (!response.ok) {
        const errorMessage =
          responseData?.message ||
          response.statusText ||
          `Request failed with status ${response.status}`;
        throw new Error(errorMessage);
      }

      return responseData;
    },

    // Parses JSON responses when available and safely falls back to plain text.
    async parseResponse(response) {
      const contentType = response.headers.get("content-type") || "";

      if (contentType.includes("application/json")) {
        return response.json().catch(() => ({}));
      }

      const text = await response.text().catch(() => "");
      if (!text) {
        return null;
      }

      return {
        message: text,
      };
    },

    // Replaces route placeholders like :id with the current booking id.
    interpolateUrl(url, booking) {
      if (!url) {
        return "";
      }

      return url.replace(/:id\b/g, booking?.id ?? "");
    },

    // Creates a shallow copy before passing booking data to external handlers.
    cloneBooking(booking) {
      if (!booking) {
        return null;
      }

      return {
        ...booking,
      };
    },

    // Returns a snapshot of the dashboard state for handlers and custom events.
    getState() {
      return {
        activeTodayCount: this.activeTodayCount,
        selectedDate: this.selectedDate,
        activeTab: this.activeTab,
        todayCount: this.todayCount,
        weekCount: this.weekCount,
        revenueToday: this.revenueToday,
        revenuePaymentCount: this.revenuePaymentCount,
        waitingNow: this.waitingNow,
        currentCapacity: this.currentCapacity,
        maxCapacity: this.maxCapacity,
        activeGroomers: this.activeGroomers,
        activeGroomingPets: this.activeGroomingPets,
        recentActivity: [...this.recentActivity],
        incomingList: [...this.incomingList],
        queuedList: [...this.queuedList],
        inProgressList: [...this.inProgressList],
        forPickupList: [...this.forPickupList],
        forPaymentList: [...this.forPaymentList],
        releasedList: [...this.releasedList],
      };
    },

    // Detects whether a returned object looks like a dashboard data payload.
    isDashboardPayload(value) {
      if (!value || typeof value !== "object" || Array.isArray(value)) {
        return false;
      }

      return (
        "incomingList" in value ||
        "queuedList" in value ||
        "inProgressList" in value ||
        "forPickupList" in value ||
        "forPaymentList" in value ||
        "incoming" in value ||
        "queued" in value ||
        "inProgress" in value ||
        "forPickup" in value ||
        "forPayment" in value ||
        "bookings" in value ||
        "summary" in value ||
        "metrics" in value ||
        "capacity" in value ||
        "recentActivity" in value ||
        "recent_activity" in value
      );
    },

    // Checks whether a value is present without treating zero as empty.
    hasValue(value) {
      return value !== undefined && value !== null;
    },

    // Converts a value to a number and falls back safely when parsing fails.
    toNumber(value, fallbackValue = 0) {
      const parsedValue = Number(value);
      return Number.isFinite(parsedValue) ? parsedValue : fallbackValue;
    },

    formatDashboardCurrency(amount) {
      return `\u20b1${Number(amount || 0).toLocaleString("en-PH", {
        maximumFractionDigits: 0,
      })}`;
    },

    formatServiceAvailedPrice(service, booking = this.detailsBooking) {
      const bounds = this.getServiceAvailedBounds(service, booking);
      return bounds ? this.formatServiceAvailedBounds(bounds) : "Price unavailable";
    },

    getServiceAvailedBounds(service, booking = this.detailsBooking, pet = null) {
      const bookedAmount = parsePaymentNumber(
        service?.priceAtBooking ?? service?.price_at_booking,
      );
      if (booking?.paid && bookedAmount > 0) {
        return { min: bookedAmount, max: bookedAmount };
      }

      const savedMin = parsePaymentNumber(service?.priceMinAtBooking ?? service?.price_min_at_booking);
      const savedMax = parsePaymentNumber(service?.priceMaxAtBooking ?? service?.price_max_at_booking);
      if (savedMin > 0) {
        return { min: savedMin, max: savedMax > 0 ? savedMax : null };
      }
      if (service?.paymentPriceRules?.min > 0) {
        return { min: Number(service.paymentPriceRules.min), max: service.paymentPriceRules.max === null ? null : Number(service.paymentPriceRules.max) };
      }
      if (bookedAmount > 0) return { min: bookedAmount, max: bookedAmount };

      const serviceDefinition = getPaymentServiceDefinition(service);
      if (!serviceDefinition) {
        return bookedAmount > 0 ? { min: bookedAmount, max: bookedAmount } : null;
      }

      const petSize = getPaymentPetSizeCandidate(pet) || this.getServiceAvailedPetSize(service, booking);
      const pricing = getPaymentServicePricing(serviceDefinition, petSize);
      if (pricing.pricingType === "range" || pricing.pricingType === "plus") {
        return { min: pricing.minAmount, max: pricing.pricingType === "range" ? pricing.selectedPriceOption?.maxAmount : null };
      }

      return bookedAmount > 0
        ? { min: bookedAmount, max: bookedAmount }
        : { min: pricing.minAmount, max: pricing.minAmount };
    },

    formatServiceAvailedBounds(bounds) {
      if (!bounds || !Number.isFinite(bounds.min) || bounds.min <= 0) return "Price unavailable";
      if (bounds.max === null) return `${this.formatPeso(bounds.min)}+`;
      return bounds.max > bounds.min
        ? `${formatPaymentAmount(bounds.min)}–${formatPaymentAmount(bounds.max)}`
        : this.formatPeso(bounds.min);
    },

    getServiceAvailedAmount(service, booking = this.detailsBooking, pet = null) {
      const amount = parsePaymentNumber(
        service?.priceAtBooking ??
          service?.price_at_booking ??
          service?.paidPrice ??
          service?.paid_price ??
          service?.finalPrice ??
          service?.final_price,
      );

      if (Number.isFinite(amount) && amount > 0) {
        return amount;
      }

      const serviceDefinition = getPaymentServiceDefinition(service);
      if (!serviceDefinition) {
        return null;
      }

      const petSize =
        getPaymentPetSizeCandidate(pet) ||
        this.getServiceAvailedPetSize(service, booking);
      const pricing = getPaymentServicePricing(serviceDefinition, petSize);

      return pricing.displayPrice === "Enter price" ? null : pricing.minAmount;
    },

    billableGroomingServices(booking) {
      const cancelledIds = new Set((booking?.pets || []).filter((pet) => this.petGroomingState(pet) === "cancelled")
        .map((pet) => String(pet.bookingPetId ?? pet.booking_pet_id ?? pet.id)));
      return (booking?.services || []).filter((service) => !cancelledIds.has(String(service.bookingPetId ?? service.booking_pet_id)));
    },

    getServicesAvailedTotal(booking = this.detailsBooking) {
      const paymentTotal = parsePaymentNumber(
        booking?.paidAmount ??
          booking?.paid_amount ??
          booking?.payment?.finalPrice ??
          booking?.payment?.final_price,
      );

      if (Number.isFinite(paymentTotal) && paymentTotal > 0) {
        return paymentTotal;
      }

      const services = this.billableGroomingServices(booking);
      if (services.length === 0) {
        return null;
      }

      const serviceAmounts = services.map((service) =>
        this.getServiceAvailedAmount(service, booking),
      );

      if (serviceAmounts.some((amount) => !Number.isFinite(amount) || amount <= 0)) {
        return null;
      }

      return serviceAmounts.reduce((total, amount) => total + amount, 0);
    },

    formatServicesAvailedTotal(booking = this.detailsBooking) {
      if (booking?.paid) return this.formatServiceAvailedBounds({ min: this.getServicesAvailedTotal(booking), max: this.getServicesAvailedTotal(booking) });
      const services = this.billableGroomingServices(booking);
      const bounds = services.map((service) => this.getServiceAvailedBounds(service, booking));
      if (!bounds.length || bounds.some((item) => !item || item.min <= 0)) return "Price unavailable";
      return this.formatServiceAvailedBounds({
        min: bounds.reduce((sum, item) => sum + item.min, 0),
        max: bounds.some((item) => item.max === null) ? null : bounds.reduce((sum, item) => sum + item.max, 0),
      });
    },

    getDetailsPaymentPet(pet, booking = this.detailsBooking, pets = this.normalizePaymentPets(booking)) {
      if (pets.length === 0) {
        return null;
      }

      const bookingPetId = pet?.bookingPetId ?? pet?.booking_pet_id;
      if (bookingPetId) {
        const matchedPet = pets.find((candidate) =>
          [candidate.bookingPetId, candidate.id].some((value) =>
            value !== null &&
            value !== undefined &&
            String(value) === String(bookingPetId),
          ),
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      const petId = pet?.petId ?? pet?.pet_id ?? pet?.id;
      if (petId) {
        const matchedPet = pets.find((candidate) =>
          [candidate.petId, candidate.id].some((value) =>
            value !== null &&
            value !== undefined &&
            String(value) === String(petId),
          ),
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      const petName = pet?.petName ?? pet?.pet_name ?? pet?.name;
      if (petName) {
        const normalizedPetName = normalizePaymentText(petName);
        const matchedPet = pets.find((candidate) =>
          normalizePaymentText(candidate.name) === normalizedPetName,
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      return pets.length === 1 ? pets[0] : null;
    },

    getPetServicesAvailed(pet, booking = this.detailsBooking) {
      const pets = this.normalizePaymentPets(booking);
      const normalizedPet = this.getDetailsPaymentPet(pet, booking, pets);
      if (!normalizedPet) {
        return [];
      }

      const services = this.normalizePaymentServices(booking?.services);
      return this.getPaymentServicesForPet(
        booking,
        normalizedPet,
        pets,
        services,
      );
    },

    // Presents pets as dogs first, cats second, and others afterward.
    getQueuedPets(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      const speciesRank = (pet) => {
        const species = String(
          pet?.species ?? pet?.petType ?? pet?.pet_type ?? "",
        ).trim().toLowerCase();

        if (species === "dog" || species === "dogs") return 0;
        if (species === "cat" || species === "cats") return 1;
        return 2;
      };

      return pets
        .map((pet, originalIndex) => ({ pet, originalIndex }))
        .sort((left, right) =>
          speciesRank(left.pet) - speciesRank(right.pet) ||
          left.originalIndex - right.originalIndex,
        )
        .map(({ pet }) => pet);
    },

    isGroomingPaid(booking) {
      return Boolean(booking?.paid || booking?.payment);
    },

    shouldShowUndoCheckIn(booking) {
      return this.normalizeStatus(booking?.status) === "queued"
        && !this.isGroomingPaid(booking) && !this.hasStartedGroomingPets(booking)
        && (booking?.pets || []).every((pet) => ["not_started", "cancelled"].includes(this.petGroomingState(pet)))
        && !booking?.startedAt && !booking?.completedAt;
    },

    shouldShowOwnerCancel(booking) {
      return ["incoming", "queued"].includes(this.normalizeStatus(booking?.status))
        && !this.isGroomingPaid(booking) && !this.hasStartedGroomingPets(booking)
        && (booking?.pets || []).every((pet) => ["not_started", "cancelled"].includes(this.petGroomingState(pet)))
        && !booking?.startedAt && !booking?.completedAt;
    },

    canCancelPet(booking, pet) {
      return ["incoming", "queued", "in-progress"].includes(this.normalizeStatus(booking?.status))
        && !this.isGroomingPaid(booking) && !booking?.completedAt
        && ((booking?.pets || []).filter((item) => this.petGroomingState(item) !== "cancelled").length > 1
          || ((booking?.pets || []).length > 1 && this.hasStartedGroomingPets(booking)))
        && this.petGroomingState(pet) === "not_started"
        && !this.isPetGroomingStarted(pet) && !this.isPetGroomingFinished(pet);
    },

    canUndoPetStart(booking, pet) {
      return ["queued", "in-progress"].includes(this.normalizeStatus(booking?.status))
        && !this.isGroomingPaid(booking) && !booking?.completedAt
        && this.isPetGroomingInProgress(pet) && !this.isPetGroomingFinished(pet);
    },

    formatPetQueueNumber(pet, petIndex = 0) {
      const queueNumber = Number(pet?.petQueueNumber ?? petIndex + 1);
      return `#P${Number.isFinite(queueNumber) && queueNumber > 0 ? queueNumber : petIndex + 1}`;
    },

    formatPetCardServiceLabel(pet, booking) {
      const names = this.getPetServicesAvailed(pet, booking)
        .map((service) => service?.name ?? service?.serviceName ?? service?.service_name)
        .filter(Boolean);

      return names.length > 0 ? names.join(", ") : "No selected services recorded";
    },

    getIncomingPets(booking) {
      return Array.isArray(booking?.pets) && booking.pets.length > 0
        ? booking.pets
        : [{ petName: booking?.petName, breed: booking?.breed, species: booking?.petType }];
    },

    formatIncomingPetBreedType(pet) {
      return [pet?.breed, pet?.species ?? pet?.petType ?? pet?.pet_type]
        .map((value) => this.formatPetCardValue(value, ""))
        .filter(Boolean)
        .join(" · ");
    },

    formatIncomingPetServices(pet, booking) {
      const names = this.getPetServicesAvailed(pet, booking)
        .map((service) => service?.name ?? service?.serviceName ?? service?.service_name)
        .filter(Boolean);
      return names.length > 0
        ? names[0] + (names.length > 1 ? ` + ${names.length - 1} more` : "")
        : this.getIncomingPets(booking).length === 1
          ? booking?.serviceLabel || "No selected services recorded"
          : "No selected services recorded";
    },

    formatPetCardValue(value, fallback = "Not provided") {
      const text = String(value ?? "").trim();
      if (!text || text === "—") {
        return fallback;
      }

      return text
        .replace(/_/g, " ")
        .replace(/\b\w/g, (character) => character.toUpperCase());
    },

    escapePrintHtml(value) {
      const element = document.createElement("span");
      element.textContent = String(value ?? "");
      return element.innerHTML;
    },

    printPetCard(booking, pet, petIndex = 0) {
      const services = this.getPetServicesAvailed(pet, booking);
      const serviceItems = services.length > 0
        ? services.map((service) => {
            const serviceName = service?.name ?? service?.serviceName ?? service?.service_name ?? "Grooming service";
            return `<li>${this.escapePrintHtml(serviceName)}</li>`;
          }).join("")
        : "";

      const petName = pet?.petName ?? pet?.pet_name ?? pet?.name ?? "Pet";
      const groomingInstructions =
        pet?.specialInstructions?.trim() ||
        pet?.special_instructions?.trim() ||
        "";
      const staffNote = String(booking?.internalStaffNote ?? "").trim();
      const medicalInformation = String(
        pet?.medicalConditions ??
        pet?.medical_conditions ??
        "",
      ).trim();
      const breed = this.formatPetCardValue(pet?.breed, "");
      const breedOrSpecies = breed || this.formatPetCardValue(
        pet?.species ?? pet?.petType ?? pet?.pet_type,
        "",
      );
      const size = this.formatPetCardValue(pet?.size ?? pet?.petSize ?? pet?.pet_size, "");
      const petDescription = [breedOrSpecies, size].filter(Boolean).join(" · ");
      const printRoot = document.createElement("section");
      // Override the shared page size only for this pet's print operation.
      const pageStyle = document.createElement("style");
      pageStyle.media = "print";
      pageStyle.textContent = "@page { size: 105mm 148mm; margin: 0; }";
      const previousTitle = document.title;
      let cleanupTimer = null;

      printRoot.className = "pet-grooming-print-clone";
      printRoot.innerHTML = `
        <header class="pet-grooming-print-header">
          <p class="pet-grooming-print-label">Queue number</p>
          <strong class="pet-grooming-print-queue">${this.escapePrintHtml(this.formatPetQueueNumber(pet, petIndex))}</strong>
          <h1 class="pet-grooming-print-name">${this.escapePrintHtml(petName)}</h1>
          ${petDescription ? `<p class="pet-grooming-print-description">${this.escapePrintHtml(petDescription)}</p>` : ""}
          <p class="pet-grooming-print-owner"><span>Owner</span> ${this.escapePrintHtml(booking?.ownerName || "Not provided")}</p>
        </header>
        ${serviceItems ? `
        <section class="pet-grooming-print-section">
          <h2>Selected services</h2>
          <ul class="pet-grooming-print-services">${serviceItems}</ul>
        </section>` : ""}
        ${groomingInstructions ? `
        <section class="pet-grooming-print-section">
          <h2>${booking?.bookingType === "Walk-In" ? "Grooming & Visit Notes" : "Customer Note"}</h2>
          <p class="pet-grooming-print-notes">${this.escapePrintHtml(groomingInstructions)}</p>
        </section>` : ""}
        ${staffNote ? `<section class="pet-grooming-print-section"><h2>Staff Note</h2><p class="pet-grooming-print-notes">${this.escapePrintHtml(staffNote)}</p></section>` : ""}
        ${medicalInformation ? `
        <section class="pet-grooming-print-section pet-grooming-print-medical">
          <h2>Medical Alert</h2>
          <p class="pet-grooming-print-notes">${this.escapePrintHtml(medicalInformation)}</p>
        </section>` : ""}
        <footer class="pet-grooming-print-brand">
          <p class="pet-grooming-print-subtitle">Grooming Cage Slip</p>
          <p class="pet-grooming-print-clinic">Bethlehem Animal Clinic</p>
        </footer>
      `;

      const cleanup = () => {
        printRoot.remove();
        pageStyle.remove();
        document.body.classList.remove("pet-grooming-card-printing");
        document.title = previousTitle;
        window.removeEventListener("afterprint", cleanup);
        if (cleanupTimer) {
          window.clearTimeout(cleanupTimer);
          cleanupTimer = null;
        }
      };

      document.head.appendChild(pageStyle);
      document.body.appendChild(printRoot);
      document.body.classList.add("pet-grooming-card-printing");
      document.title = `${this.formatPetQueueNumber(pet, petIndex)} ${petName}`;
      window.addEventListener("afterprint", cleanup);
      cleanupTimer = window.setTimeout(cleanup, 60000);
      try {
        window.print();
      } catch (error) {
        cleanup();
        throw error;
      }
    },

    getPetServicesAvailedTotal(pet, booking = this.detailsBooking) {
      const normalizedPet = this.getDetailsPaymentPet(pet, booking);
      const petServices = this.getPetServicesAvailed(pet, booking);

      if (!normalizedPet || petServices.length === 0) {
        return null;
      }

      const serviceAmounts = petServices.map((service) =>
        this.getServiceAvailedAmount(service, booking, normalizedPet),
      );

      if (serviceAmounts.some((amount) => !Number.isFinite(amount) || amount <= 0)) {
        return null;
      }

      return serviceAmounts.reduce((total, amount) => total + amount, 0);
    },

    formatPetServicesAvailedTotal(pet, booking = this.detailsBooking) {
      if (booking?.paid) {
        const total = this.getPetServicesAvailedTotal(pet, booking);
        return Number.isFinite(total) && total > 0 ? this.formatPeso(total) : "Price unavailable";
      }
      const services = this.getPetServicesAvailed(pet, booking);
      const bounds = services.map((service) => this.getServiceAvailedBounds(service, booking, pet));
      if (!bounds.length || bounds.some((item) => !item || item.min <= 0)) return "Price unavailable";
      return this.formatServiceAvailedBounds({
        min: bounds.reduce((sum, item) => sum + item.min, 0),
        max: bounds.some((item) => item.max === null) ? null : bounds.reduce((sum, item) => sum + item.max, 0),
      });
    },

    getServiceAvailedPetSize(service, booking = this.detailsBooking) {
      const serviceSize = getPaymentPetSizeCandidate(service);

      if (serviceSize) {
        return serviceSize;
      }

      const matchedPet = this.getServiceAvailedPet(service, booking);

      return (
        getPaymentPetSizeCandidate(matchedPet) ||
        getPaymentPetSizeCandidate(booking)
      );
    },

    getServiceAvailedPet(service, booking = this.detailsBooking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];

      if (pets.length === 0) {
        return null;
      }

      const bookingPetId = service?.bookingPetId ?? service?.booking_pet_id;
      if (bookingPetId) {
        const matchedPet = pets.find((pet) =>
          String(pet?.bookingPetId ?? pet?.booking_pet_id ?? pet?.id) ===
          String(bookingPetId),
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      const petId = service?.petId ?? service?.pet_id;
      if (petId) {
        const matchedPet = pets.find((pet) =>
          String(pet?.petId ?? pet?.pet_id) === String(petId),
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      const petName = service?.petName ?? service?.pet_name;
      if (petName) {
        const normalizedPetName = normalizePaymentText(petName);
        const matchedPet = pets.find((pet) =>
          normalizePaymentText(pet?.petName ?? pet?.pet_name ?? pet?.name) ===
          normalizedPetName,
        );

        if (matchedPet) {
          return matchedPet;
        }
      }

      return pets.length === 1 ? pets[0] : null;
    },

    formatPercent(value) {
      return `${Number(value || 0).toFixed(1)}%`;
    },

    normalizeRecentActivity(activity) {
      return (Array.isArray(activity) ? activity : [])
        .map((item, index) => ({
          id: item?.id ?? `activity-${index}`,
          type: this.toStringValue(item?.type || "activity"),
          title: this.toStringValue(item?.title || "Activity"),
          subtitle: this.toStringValue(item?.subtitle || item?.message),
          createdAt: this.toStringValue(item?.createdAt ?? item?.created_at ?? item?.time),
          timeLabel: this.toStringValue(item?.timeLabel ?? item?.time_label),
        }))
        .filter((item) => item.title);
    },

    get availableCapacity() {
      return Math.max(0, this.maxCapacity - this.currentCapacity);
    },

    get capacityPercent() {
      return this.maxCapacity > 0
        ? Math.min(100, Math.max(0, this.currentCapacity / this.maxCapacity * 100))
        : 0;
    },

    todayQueuePreview() {
      if (this.pageMode === 'dashboard' && this.summaryLoaded) return this.dashboardQueuePreview;
      const today = this.localToday();
      const visibleBookings = [...this.inProgressList, ...this.queuedList]
        .filter((booking, index, bookings) =>
          bookings.findIndex((candidate) => String(candidate.id) === String(booking.id)) === index,
        );

      return visibleBookings
        .filter((booking) => booking.appointmentDate === today)
        .sort((left, right) => {
          const statusOrder = { "in-progress": 0, queued: 1 };
          const leftStatus = statusOrder[left.status] ?? 2;
          const rightStatus = statusOrder[right.status] ?? 2;
          if (leftStatus !== rightStatus) return leftStatus - rightStatus;
          return this.compareBookings(left, right);
        })
        .slice(0, 5);
    },

    queuePreviewStatusLabel(status) {
      return this.normalizeStatus(status) === "in-progress" ? "In progress" : "Queued";
    },

    queuePreviewBadgeClass(status) {
      return this.normalizeStatus(status) === "in-progress"
        ? "bg-sky-100 text-sky-700"
        : "bg-amber-100 text-amber-700";
    },

    queuePreviewAccentClass(status) {
      return this.normalizeStatus(status) === "in-progress"
        ? "bg-sky-100 text-sky-700"
        : "bg-amber-100 text-amber-700";
    },

    queuePreviewDetail(booking) {
      const service = booking?.serviceType === "clinic" ? "Clinic" : "Grooming";
      return `${service} · Checked in${booking?.dropOffTime ? ` ${booking.dropOffTime}` : ""}`;
    },

    activityDotClass(type) {
      const normalizedType = this.toStringValue(type);
      if (normalizedType === "completed") return "admin-activity-dot-pickup";
      if (normalizedType === "in_progress") return "bg-sky-600";
      if (normalizedType === "queued" || normalizedType === "check_in") return "bg-amber-700";
      if (normalizedType === "payment") return "bg-green-600";
      return "bg-slate-400";
    },

    activityTimeLabel(activity) {
      if (activity?.timeLabel) return activity.timeLabel;
      if (!activity?.createdAt) return "";

      const date = new Date(activity.createdAt);
      if (Number.isNaN(date.getTime())) return "";

      return date.toLocaleTimeString("en-PH", {
        hour: "numeric",
        minute: "2-digit",
      });
    },

    // Converts nullable values into template-safe strings.
    toStringValue(value) {
      return value === undefined || value === null ? "" : String(value);
    },

    // Derive the card label from the actual pets instead of trusting a first-pet summary.
    formatBookingPetTypes(booking) {
      const pets = Array.isArray(booking?.pets) ? booking.pets : [];
      if (pets.length === 0) {
        return this.toStringValue(booking?.petType);
      }

      const typeCounts = pets.reduce((counts, pet) => {
        const type = this.toStringValue(
          pet?.species ?? pet?.petType ?? pet?.pet_type,
        ).trim().toLowerCase();

        if (type) {
          counts.set(type, (counts.get(type) || 0) + 1);
        }

        return counts;
      }, new Map());

      if (typeCounts.size === 0) {
        return this.toStringValue(booking?.petType);
      }

      const supportedTypes = ["dog", "cat"].filter((type) => typeCounts.has(type));
      const otherTypes = Array.from(typeCounts.keys()).filter(
        (type) => !supportedTypes.includes(type),
      );
      const labels = [...supportedTypes, ...otherTypes].map((type) => {
        const label = type.charAt(0).toUpperCase() + type.slice(1);
        return typeCounts.get(type) > 1 ? `${label}s` : label;
      });

      if (labels.length === 1) return labels[0];
      if (labels.length === 2) return `${labels[0]} and ${labels[1]}`;

      return `${labels.slice(0, -1).join(", ")}, and ${labels.at(-1)}`;
    },

    formatMobileNumber(value) {
      const text = this.toStringValue(value).trim();
      if (!text) return "—";

      const digits = text.replace(/\D/g, "");
      if (digits.length === 11) {
        return `${digits.slice(0, 4)}-${digits.slice(4, 7)}-${digits.slice(7)}`;
      }

      return text;
    },

    // Converts backend status variants into the frontend status names used by the UI.
    normalizeStatus(status) {
      if (!status) {
        return "";
      }

      const normalizedStatus = String(status).trim().toLowerCase();
      const statusMap = {
        in_progress: "in-progress",
        inprogress: "in-progress",
        for_pickup: "for-pickup",
        forpickup: "for-pickup",
        for_payment: "for-payment",
        forpayment: "for-payment",
      };

      return statusMap[normalizedStatus] || normalizedStatus;
    },

    // Creates a simple time string for optimistic status transitions.
    formatCurrentTime() {
      return new Date().toLocaleTimeString([], {
        hour: "numeric",
        minute: "2-digit",
      });
    },

    // Generates a temporary id when the backend record does not include one yet.
    createFallbackId() {
      if (window.crypto && typeof window.crypto.randomUUID === "function") {
        return window.crypto.randomUUID();
      }

      return `booking-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    },

    // Broadcasts dashboard lifecycle and action events for external listeners.
    dispatchDashboardEvent(name, detail = {}) {
      window.dispatchEvent(
        new CustomEvent(name, {
          detail,
        }),
      );
    },


    // ── Incoming list grouped by date (today first) ───────────────────────

    // Use the clinic's calendar day regardless of the browser's timezone.
    localToday() {
      return new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit",
      }).format(new Date());
    },

    groupedIncoming() {
      const groups = {};
      for (const booking of this.incomingList) {
        const date = booking.appointmentDate || 'unknown';
        if (!groups[date]) groups[date] = [];
        groups[date].push(booking);
      }

      const today = this.localToday();

      return Object.keys(groups)
        .sort((a, b) => a.localeCompare(b))
        .map(date => ({
          date,
          label: this.formatDateGroupLabel(date),
          isToday: date === today,
          bookings: groups[date],
        }));
    },

    formatDateGroupLabel(dateStr) {
      const today    = this.localToday();
      const tomorrowDate = new Date(`${today}T00:00:00Z`);
      tomorrowDate.setUTCDate(tomorrowDate.getUTCDate() + 1);
      const tomorrow = tomorrowDate.toISOString().slice(0, 10);

      const formatted = new Date(dateStr + 'T00:00:00').toLocaleDateString('en-PH', {
        weekday: 'long',
        month:   'long',
        day:     'numeric',
        year:    'numeric',
      });

      if (dateStr === today)    return 'Today — ' + formatted;
      if (dateStr === tomorrow) return 'Tomorrow — ' + formatted;
      return formatted;
    },

    // ── Payment ───────────────────────────────────────────────────────────────

    get paymentTotalDue() {
      if (this.paymentModal.onlinePayment) return Number(this.paymentModal.onlinePayment.amount);
      return this.paymentServicesTotal + this.paymentProductsTotal;
    },

    get paymentServicesTotal() {
      return this.paymentModal.petBreakdown.reduce(
        (sum, pet) => sum + this.paymentPetSubtotal(pet),
        0,
      );
    },

    get paymentProductsTotal() {
      return this.paymentModal.products.reduce((sum, line) => sum + line.quantity * line.price, 0);
    },

    get paymentLineCount() {
      return this.paymentModal.petBreakdown.reduce(
        (count, pet) => count + (Array.isArray(pet.lines) ? pet.lines.length : 0),
        0,
      );
    },

    get paymentMaximumAmount() {
      return window.CashPayment.maximumFor(this.paymentTotalDue);
    },

    get paymentChange() {
      return window.CashPayment.change(this.paymentModal.amountPaid, this.paymentTotalDue);
    },

    get canSubmitPayment() {
      return (
        !this.paymentModal.busy && !this.paymentModal.onlinePayment &&
        this.paymentModal.petBreakdown.length > 0 &&
        this.paymentTotalDue > 0 &&
        !this.hasMissingPaymentPrices() &&
        !this.getInvalidPaymentLine() &&
        (this.paymentModal.paymentMethod === 'online' || (this.paymentModal.paymentMethod === 'qrph'
          ? this.paymentModal.qr && !this.paymentModal.qr.validationError(this.paymentTotalDue)
          : !window.CashPayment.error(this.paymentModal.amountPaid, this.paymentTotalDue)))
      );
    },

    async openPaymentModal(booking, isEarlyPayment = false, attemptId = null, restoredResponse = null) {
      if (this.paymentModal.open) return;
      if (!isEarlyPayment && booking?.paymentReady === false) {
        alert(booking?.paymentBlockedReason || "This visit is not ready for final payment.");
        return;
      }

      this.paymentModal.open = true;
      this.paymentModal.busy = true;
      this.paymentModal.error = "";
      this.paymentModal.petBreakdown = [];
      try {
        const { loadGroomingCatalogue } = await import("../services/grooming-service.js?v=managed-services-20261017-2");
        applyPaymentCatalogue(await loadGroomingCatalogue({includeInactive: true}));
      } catch (error) {
        this.paymentModal.busy = false;
        this.paymentModal.error = "Unable to load current grooming pricing. Close and reopen this payment to retry.";
        return;
      }
      if (!this.paymentModal.open) return;
      const petBreakdown = this.buildPaymentBreakdown(booking, { lockFixedPrices: true });
      this.paymentModal = {
        paymentMethod: 'cash', onlinePayment: null, verifying: false,
        qr: null,
        open: true,
        booking,
        isEarlyPayment,
        finalPrice: "",
        petBreakdown,
        products: [],
        showProductSearch: false,
        productQuery: "",
        productResults: [],
        productSearchError: "",
        scannerActive: false,
        _scanner: null,
        amountPaid: "",
        notes: "",
        busy: false,
        error: "",
      };
      const modal = this.paymentModal;
      modal.busy = true;
      try {
        await this.applyOnlinePaymentStatus(restoredResponse || await API.groomingOnlinePaymentStatus(booking.id, attemptId));
      } catch (error) {
        modal.error = error.message || 'Could not check active payments. Reopen this payment to retry.';
        return;
      } finally { if (this.paymentModal === modal) modal.busy = false; }
      if (!modal.open || this.paymentModal !== modal || modal.onlinePayment) return;
      try {
        const {createQrPayment} = await import('./qr-payment.js?v=20261023-inline-errors');
        if (!modal.open || this.paymentModal !== modal || this._destroyed) return;
        modal.qr = createQrPayment();
        await modal.qr.load();
      } catch (error) {
        if (modal.open && this.paymentModal === modal) modal.error = 'QR settings could not load. Cash remains available.';
      }
    },

    get paymentMethodLabel() {
      return this.paymentModal.paymentMethod === 'online' ? 'Online Payment' : this.paymentModal.paymentMethod === 'qrph' ? this.paymentModal.qr?.paymentSource || 'QR Payment' : 'Cash';
    },
    selectPaymentMethod(method) {
      const modal = this.paymentModal;
      if (modal.busy || modal.onlinePayment) return;
      if (method === 'online') { modal.qr?.cancelScan?.(); modal.paymentMethod = 'online'; modal.error = ''; return; }
      const cash = method === 'cash';
      if (!cash && (!modal.qr?.settings?.enabled || !modal.qr.settings.accepted_methods.includes(method))) return;
      modal.paymentMethod = cash ? 'cash' : 'qrph';
      modal.error = '';
      if (modal.qr) { modal.qr.paymentSource = cash ? '' : method; modal.qr.verified = false; }
      if (!cash) void modal.qr.showImage();
    },

    closePaymentModal(force = false) {
      if (this.paymentModal.busy && !force) return;
      this.stopOnlinePaymentPoll();
      this.closePaymentScanner();
      this.paymentModal.qr?.dispose();
      this.paymentModal = {
        paymentMethod: 'cash', qr: null, onlinePayment: null, verifying: false,
        open: false, booking: null, isEarlyPayment: false,
        finalPrice: "", petBreakdown: [], products: [], showProductSearch: false,
        productQuery: "", productResults: [], productSearchError: "", scannerActive: false, _scanner: null,
        amountPaid: "", notes: "", busy: false, error: "",
      };
    },

    async openPaymentScanner() {
      if (!window.Html5Qrcode) {
        try {
          if (!window.__groomingScannerLibrary) {
            window.__groomingScannerLibrary = new Promise((resolve, reject) => {
              const script = document.createElement('script');
              script.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
              script.onload = resolve;
              script.onerror = () => { script.remove(); window.__groomingScannerLibrary = null; reject(new Error('Scanner could not load. Try again.')); };
              document.head.appendChild(script);
            });
          }
          await window.__groomingScannerLibrary;
        } catch (error) { this.paymentModal.error = error.message; return; }
      }
      if (!this.paymentModal.open || this._destroyed) return;
      this.paymentModal.productSearchError = "";
      ProductForm.openBarcodeScanner(this.paymentModal, "grooming-payment-barcode-reader", (decoded) => {
        if (!this.paymentModal.scannerActive) return;
        this.closePaymentScanner();
        this.scanPaymentBarcode(decoded);
      });
    },

    closePaymentScanner() {
      if (this.paymentModal?._scanner) ProductForm.closeBarcodeScanner(this.paymentModal);
      if (this.paymentModal) this.paymentModal.scannerActive = false;
    },

    async scanPaymentBarcode(decoded) {
      if (!this.paymentModal.open) return;
      const barcode = ProductForm.normalizeBarcode(decoded);
      if (!barcode) {
        this.paymentModal.productSearchError = "No valid barcode was scanned.";
        return;
      }
      this.paymentModal.productQuery = barcode;
      this.paymentModal.productResults = [];
      clearTimeout(this._paymentProductSearchTimer);
      try {
        const response = await InventoryAPI.findByBarcode(barcode);
        if (!this.paymentModal.open || this.paymentModal.productQuery !== barcode) return;
        if (!this.hasPaymentProductStock(response.data)) {
          this.paymentModal.productSearchError = "This product is unavailable for Grooming add-ons.";
          return;
        }
        this.addPaymentProduct(response.data);
      } catch {
        if (this.paymentModal.open) this.paymentModal.productSearchError = "No available product matches this barcode.";
      }
    },

    isPaymentProductSearchResult(item) {
      return item && !["medicine", "vaccine"].includes(item.category)
        && item.selling_price != null;
    },

    isEligiblePaymentProduct(item) {
      return item?.is_active && this.isPaymentProductSearchResult(item);
    },

    hasPaymentProductStock(item) {
      return this.isEligiblePaymentProduct(item) && Number(item.saleable_quantity) >= 1;
    },

    paymentProductStockLabel(item) {
      if (!item.is_active) return "Deactivated";
      if (Number(item.quantity_on_hand) <= 0) return "Out of stock";
      if (Number(item.expired_quantity) > 0) return "Expired stock";
      return "Unavailable stock";
    },

    searchPaymentProducts() {
      const query = this.paymentModal.productQuery.trim();
      clearTimeout(this._paymentProductSearchTimer);
      this.paymentModal.productSearchError = "";
      if (query.length < 2) {
        this.paymentModal.productResults = [];
        return;
      }
      this._paymentProductSearchTimer = setTimeout(async () => {
        try {
          const response = await InventoryAPI.searchItems(query, true, "grooming");
          if (this.paymentModal.productQuery.trim() !== query || !this.paymentModal.open) return;
          this.paymentModal.productResults = (response.data || [])
            .filter((item) => this.isPaymentProductSearchResult(item))
            .sort((a, b) => Number(this.hasPaymentProductStock(b)) - Number(this.hasPaymentProductStock(a)));
        } catch (error) {
          this.paymentModal.productResults = [];
          this.paymentModal.productSearchError = error.message || "Product search failed.";
        }
      }, 300);
    },

    addPaymentProduct(item) {
      if (!this.hasPaymentProductStock(item)) return;
      const stock = Math.floor(Number(item.saleable_quantity));
      const existing = this.paymentModal.products.find((line) => line.item_id === item.item_id);
      if (existing) {
        existing.stock = stock;
        if (existing.quantity >= stock) {
          this.paymentModal.productSearchError = `${item.item_name} has no more available stock.`;
          return;
        }
        existing.quantity += 1;
      } else {
        if (stock < 1) return;
        this.paymentModal.products.push({ item_id: item.item_id, name: item.item_name,
          unit: item.unit, price: Number(item.selling_price), stock, quantity: 1 });
      }
      this.paymentModal.productQuery = "";
      this.paymentModal.productResults = [];
      this.paymentModal.error = "";
    },

    changePaymentProductQuantity(line, change) {
      line.quantity = Math.max(1, Math.min(line.stock, line.quantity + change));
      this.paymentModal.error = "";
    },

    removePaymentProduct(index) {
      this.paymentModal.products.splice(index, 1);
      this.paymentModal.error = "";
    },

    buildPaymentBreakdown(booking, paymentOptions = {}) {
      const pets = this.normalizePaymentPets(booking).filter((pet) => pet.groomingState !== "cancelled");
      const services = this.normalizePaymentServices(booking?.services);
      /*
       * Backend handoff:
       * Multi-pet payment cards need either pets[].services or services[] items
       * that include bookingPetId/booking_pet_id. Service slug fields are also
       * preferred so the frontend can show the exact minimum price rule.
      */
      return pets.map((pet, petIndex) => {
        const petServices = this.getPaymentServicesForPet(booking, pet, pets, services);
        const inferredSizeKey = inferPaymentSizeFromServices(petServices, pet.petTypeKey);
        const pricedPet = inferredSizeKey && !pet.confirmedSize
          ? {
              ...pet,
              sizeKey: inferredSizeKey,
              sizeLabel: formatPaymentSizeLabel(inferredSizeKey),
            }
          : pet;
        const lines = petServices.map((service, serviceIndex) =>
          this.normalizePaymentLine(service, pricedPet, `${petIndex}-${serviceIndex}`, paymentOptions),
        );

        return {
          ...pricedPet,
          isSizeEditing: false,
          lines,
        };
      });
    },

    normalizePaymentPets(booking) {
      const sourcePets = Array.isArray(booking?.pets) && booking.pets.length > 0
        ? booking.pets
        : [{
            petName: booking?.petName,
            petType: booking?.petType,
            species: booking?.petType,
            breed: booking?.breed,
            size: booking?.petSize ?? booking?.size,
          }];
      const bookingSizeCandidate = sourcePets.length === 1
        ? getPaymentPetSizeCandidate(booking)
        : "";

      return sourcePets.map((pet, index) => {
        const petTypeKey = normalizePaymentPetType(
          pet?.species ?? pet?.petType ?? pet?.pet_type,
        );
        const sizeCandidate = getPaymentPetSizeCandidate(pet) || bookingSizeCandidate;
        const sizeKey = normalizePaymentSizeForPet(
          sizeCandidate,
          petTypeKey,
        );

        return {
          id: pet?.bookingPetId ?? pet?.booking_pet_id ?? pet?.id ?? `pet-${index + 1}`,
          bookingPetId: pet?.bookingPetId ?? pet?.booking_pet_id ?? null,
          petId: pet?.petId ?? pet?.pet_id ?? pet?.id ?? null,
          name: this.toStringValue(
            pet?.petName ?? pet?.pet_name ?? pet?.name ?? `Pet ${index + 1}`,
          ),
          species: this.toStringValue(pet?.species ?? pet?.petType ?? pet?.pet_type),
          petTypeKey,
          breed: this.toStringValue(pet?.breed),
          sizeKey,
          sizeLabel: formatPaymentSizeLabel(sizeKey),
          confirmedSize: pet?.confirmedSize ?? pet?.confirmed_size ?? null,
          verifiedSizeKey: normalizePaymentSize(pet?.confirmedSize ?? pet?.confirmed_size)
            || (pet?.sizeVerified ? normalizePaymentSize(sizeCandidate) : ""),
          services: Array.isArray(pet?.services) ? pet.services : [],
          groomingState: pet?.groomingState ?? pet?.grooming_state ?? "not_started",
        };
      });
    },

    normalizePaymentServices(services) {
      if (!Array.isArray(services)) {
        return [];
      }

      return services.map((service, index) => ({
        ...service,
        id: service?.bookingServiceId ?? service?.booking_service_id ?? service?.id ?? `service-${index + 1}`,
        bookingPetId: service?.bookingPetId ?? service?.booking_pet_id ?? null,
        petId: service?.petId ?? service?.pet_id ?? null,
        petName: service?.petName ?? service?.pet_name ?? "",
      }));
    },

    getPaymentServicesForPet(booking, pet, pets, services) {
      const directPetServices = this.normalizePaymentServices(pet?.services);

      if (directPetServices.length > 0) {
        return directPetServices;
      }

      const matchedServices = services.filter((service) => {
        if (service.bookingPetId && pet.bookingPetId) {
          return String(service.bookingPetId) === String(pet.bookingPetId);
        }

        if (service.petId && pet.petId) {
          return String(service.petId) === String(pet.petId);
        }

        if (service.petName && pet.name) {
          return normalizePaymentText(service.petName) === normalizePaymentText(pet.name);
        }

        return false;
      });

      if (matchedServices.length > 0) {
        return matchedServices;
      }

      if (pets.length === 1) {
        return services;
      }

      const unscopedServices = services.filter(
        (service) => !service.bookingPetId && !service.petId && !service.petName,
      );
      const petIndex = pets.findIndex((candidate) => candidate.id === pet.id);

      /*
       * Frontend fallback:
       * when the API only sends a flat services[] list, split it across pets
       * only if the count makes the grouping unambiguous. Backend should still
       * expose bookingPetId/booking_pet_id for exact multi-pet association.
       */
      if (
        petIndex >= 0 &&
        unscopedServices.length >= pets.length &&
        unscopedServices.length % pets.length === 0
      ) {
        const servicesPerPet = unscopedServices.length / pets.length;
        const startIndex = petIndex * servicesPerPet;

        return unscopedServices.slice(startIndex, startIndex + servicesPerPet);
      }

      return pets[0]?.id === pet.id ? unscopedServices : [];
    },

    normalizePaymentLine(rawService, pet, fallbackId, paymentOptions = {}) {
      const serviceDefinition = getPaymentServiceDefinition(rawService);
      const fallbackAmount = parseFloat(rawService?.priceAtBooking ?? rawService?.price_at_booking ?? 0);
      let pricing = serviceDefinition
        ? getPaymentServicePricing(serviceDefinition, pet.sizeKey)
        : fallbackAmount > 0
          ? {
              minAmount: fallbackAmount,
              displayPrice: formatPaymentAmount(fallbackAmount),
              placeholder: Number(fallbackAmount).toLocaleString("en-PH"),
              pricingType: "fixed",
              selectedPriceOption: null,
            }
          : getPaymentServicePricing(null, pet.sizeKey);
      const savedMin = parsePaymentNumber(rawService?.priceMinAtBooking ?? rawService?.price_min_at_booking ?? rawService?.paymentPriceRules?.min);
      const savedMax = parsePaymentNumber(rawService?.priceMaxAtBooking ?? rawService?.price_max_at_booking ?? rawService?.paymentPriceRules?.max);
      if (!(savedMin > 0) && fallbackAmount > 0) {
        pricing = { minAmount: fallbackAmount, maxAmount: fallbackAmount, pricingType: "fixed",
          displayPrice: formatPaymentAmount(fallbackAmount), placeholder: String(fallbackAmount) };
      }
      if (savedMin > 0) {
        const pricingType = savedMax > 0 ? (savedMax === savedMin ? "fixed" : "range") : "plus";
        pricing = {
          ...pricing,
          minAmount: savedMin,
          selectedPriceOption: null,
          maxAmount: savedMax > 0 ? savedMax : null,
          pricingType,
          displayPrice: formatPaymentPriceOption({ pricingType, minAmount: savedMin, maxAmount: savedMax }),
          placeholder: String(savedMin),
        };
      }
      const pricingType = pricing.pricingType || "custom";
      const lockFixedPrices = Boolean(paymentOptions.lockFixedPrices);
      const isFixedPriceLocked = shouldLockPaymentPrice(pricing, lockFixedPrices);

      return {
        id: rawService?.id ?? fallbackId,
        bookingServiceId: rawService?.bookingServiceId ?? rawService?.booking_service_id ?? null,
        serviceDefinition,
        serviceId: serviceDefinition?.id ?? rawService?.slug ?? rawService?.serviceSlug ?? rawService?.service_slug ?? "",
        kind: serviceDefinition?.kind ?? rawService?.kind ?? "service",
        name: this.toStringValue(
          rawService?.name ??
            rawService?.serviceName ??
            rawService?.service_name ??
            serviceDefinition?.name ??
            "Grooming Service",
        ),
        description: this.toStringValue(
          rawService?.description ??
            rawService?.notes ??
            serviceDefinition?.descriptionItems?.[0] ??
            (serviceDefinition?.kind === "package" ? "Package service" : "A la carte service"),
        ),
        minAmount: pricing.minAmount,
        maxAmount: pricing.maxAmount ?? pricing.selectedPriceOption?.maxAmount ?? null,
        ...getPaymentPriceSafetyRules(pricing, serviceDefinition, pet.sizeKey),
        savedMinAmount: savedMin > 0 ? savedMin : fallbackAmount > 0 ? fallbackAmount : null,
        savedMaxAmount: savedMax > 0 ? savedMax : !(savedMin > 0) && fallbackAmount > 0 ? fallbackAmount : null,
        originalSizeKey: pet.sizeKey,
        priceHint: pricing.displayPrice,
        placeholder: pricing.placeholder,
        pricingType,
        lockFixedPrices,
        isFixedPriceLocked,
        priceTouched: false,
        amount: isFixedPriceLocked ? Number(pricing.minAmount).toFixed(2) : "",
      };
    },

    updatePaymentPetSize(pet) {
      pet.sizeKey = normalizePaymentSizeForPet(pet.sizeKey, pet.petTypeKey);
      pet.sizeLabel = formatPaymentSizeLabel(pet.sizeKey);

      pet.lines.forEach((line) => {
        this.refreshPaymentLinePricing(pet, line);
      });

      this.paymentModal.error = "";
    },

    refreshPaymentLinePricing(pet, line) {
      const wasFixedPriceLocked = Boolean(line.isFixedPriceLocked);
      let pricing = getPaymentServicePricing(line.serviceDefinition, pet.sizeKey);
      if (line.savedMinAmount !== null &&
          (line.serviceDefinition?.kind !== "package" || line.originalSizeKey === pet.sizeKey)) {
        const pricingType = line.savedMaxAmount !== null
          ? (line.savedMaxAmount === line.savedMinAmount ? "fixed" : "range") : "plus";
        pricing = {
          ...pricing,
          minAmount: line.savedMinAmount,
          selectedPriceOption: null,
          maxAmount: line.savedMaxAmount,
          pricingType,
          displayPrice: formatPaymentPriceOption({ pricingType, minAmount: line.savedMinAmount, maxAmount: line.savedMaxAmount }),
          placeholder: String(line.savedMinAmount),
        };
      }
      const pricingType = pricing.pricingType || "custom";
      line.minAmount = pricing.minAmount;
      line.maxAmount = pricing.maxAmount ?? pricing.selectedPriceOption?.maxAmount ?? null;
      Object.assign(line, getPaymentPriceSafetyRules(pricing, line.serviceDefinition, pet.sizeKey));
      line.priceHint = pricing.displayPrice;
      line.placeholder = pricing.placeholder;
      line.pricingType = pricingType;
      line.isFixedPriceLocked = shouldLockPaymentPrice(pricing, line.lockFixedPrices);

      if (line.isFixedPriceLocked) {
        line.amount = Number(pricing.minAmount).toFixed(2);
      } else if (wasFixedPriceLocked) {
        line.amount = "";
        line.priceTouched = false;
      }
    },

    getPaymentSizeOptions(pet) {
      const baseOptions = getPaymentBaseSizeOptions(pet?.petTypeKey ?? pet?.species);
      const packageLines = (pet?.lines || []).filter(
        (line) => line.serviceDefinition?.kind === "package",
      );
      const allowedSizes = new Set();

      packageLines.forEach((line) => {
        (line.serviceDefinition?.priceOptions || []).forEach((option) => {
          if (option.sizeKey) {
            allowedSizes.add(option.sizeKey);
          }
        });
      });

      const options = allowedSizes.size > 0
        ? baseOptions.filter((option) => allowedSizes.has(option.value))
        : baseOptions;

      if (pet?.sizeKey && !options.some((option) => option.value === pet.sizeKey)) {
        return [
          ...options,
          { value: pet.sizeKey, label: formatPaymentSizeLabel(pet.sizeKey) },
        ];
      }

      return options;
    },

    paymentPetSubtotal(pet) {
      return (pet?.lines || []).reduce((sum, line) => {
        const amount = parseFloat(line.amount);
        return Number.isFinite(amount) ? sum + amount : sum;
      }, 0);
    },

    paymentLineError(line) {
      if (line.amount === "" || line.amount === null || line.amount === undefined) return "Enter a price for this service.";
      if (!/^\d+(?:\.\d+)?$/.test(String(line.amount))) {
        return "Enter a valid amount without signs or scientific notation.";
      }
      if (!/^\d+(?:\.\d{1,2})?$/.test(String(line.amount))) {
        return "Enter an amount with no more than 2 decimal places.";
      }
      const amount = Number(line.amount);
      if (!Number.isFinite(amount)) return "Enter a valid amount.";
      if (line.pricingType === "fixed" && amount !== line.minAmount) {
        return `This service has a fixed price of ${formatPaymentAmount(line.minAmount)}.`;
      }
      if (line.safetyMaxAmount !== null && line.safetyMaxAmount !== undefined && amount > line.safetyMaxAmount) {
        return `Enter ${formatPaymentAmount(line.safetyMaxAmount)} or less for this service.`;
      }
      if (amount >= line.minAmount &&
          (line.maxAmount === null || amount <= line.maxAmount)) {
        return "";
      }
      if (line.pricingType === "range" && line.maxAmount !== null) {
        return `Enter an amount from ${formatPaymentAmount(line.minAmount)} to ${formatPaymentAmount(line.maxAmount)}.`;
      }
      return `Enter an amount of at least ${formatPaymentAmount(line.minAmount)}.`;
    },

    paymentLineWarning(line) {
      if (this.paymentLineError(line) || line.reviewThreshold === null || line.reviewThreshold === undefined) return "";
      const amount = Number(line.amount);
      if (amount < line.reviewThreshold) return "";
      const comparison = amount === line.reviewThreshold ? "at" : "above";
      return `This amount is ${comparison} the Extra Large starting price of ${formatPaymentAmount(line.reviewThreshold)}. Confirm the pet's size and final charge.`;
    },

    enforcePaymentAmountLimit(event = null) {
      const currentValue = event?.target?.value ?? this.paymentModal.amountPaid;
      const maximumValue = window.CashPayment.capInput(currentValue, this.paymentTotalDue);

      if (maximumValue !== currentValue) {
        this.paymentModal.amountPaid = maximumValue;

        if (event?.target) {
          event.target.value = maximumValue;
        }
      }

      if (event) {
        this.clearPaymentError();
      }
    },

    hasMissingPaymentPrices() {
      return this.paymentModal.petBreakdown.some((pet) =>
        (pet.lines || []).some((line) => line.amount === "" || line.amount === null || line.amount === undefined),
      );
    },

    getInvalidPaymentLine() {
      for (const pet of this.paymentModal.petBreakdown) {
        for (const line of pet.lines || []) {
          const message = this.paymentLineError(line);
          if (message) return { pet, line, message };
        }
      }

      return null;
    },

    clearPaymentError() {
      this.paymentModal.error = "";
    },

    focusPaymentReference(modal) {
      this.$nextTick(() => {
        if (this.paymentModal !== modal || !modal.open || modal.paymentMethod !== 'qrph' || !modal.qr?.referenceError) return;
        const {paymentModalBody: body, qrPaymentReferenceField: field, qrPaymentReference: input} = this.$refs;
        if (!body || !field || !input) return;
        const bounds = body.getBoundingClientRect();
        const target = field.getBoundingClientRect();
        if (target.top < bounds.top || target.bottom > bounds.bottom) body.scrollTop += target.top - bounds.top - 12;
        input.focus({preventScroll: true});
      });
    },

    formatPeso(amount) {
      return `\u20b1${Number(amount || 0).toFixed(2)}`;
    },

    formatInvoicePeso(amount) {
      return window.PaymentInvoice.formatPeso(amount);
    },

    formatInvoiceDate(value) {
      return window.PaymentInvoice.formatDate(value);
    },

    formatInvoiceProductUnitPrice(line) {
      return window.PaymentInvoice.formatProductUnitPrice(line);
    },

    formatReceiptValue(value, fallbackValue = "Not specified") {
      const text = this.toStringValue(value).trim();
      return text ? text : fallbackValue;
    },

    buildPaymentReceiptPets(petBreakdown) {
      const pets = Array.isArray(petBreakdown) ? petBreakdown : [];

      return pets.map((pet, petIndex) => {
        const lines = (Array.isArray(pet?.lines) ? pet.lines : []).map((line, lineIndex) => {
          const amount = parseFloat(line?.amount);

          return {
            id: `${pet?.id ?? `pet-${petIndex + 1}`}-${line?.id ?? lineIndex}`,
            name: this.formatReceiptValue(line?.name, "Grooming Service"),
            price: Number.isFinite(amount) ? amount : 0,
          };
        });

        return {
          id: pet?.id ?? `receipt-pet-${petIndex + 1}`,
          name: this.formatReceiptValue(pet?.name, `Pet ${petIndex + 1}`),
          species: this.formatReceiptValue(pet?.species || pet?.petTypeKey),
          breed: this.formatReceiptValue(pet?.breed),
          sizeLabel: this.formatReceiptValue(pet?.sizeLabel || formatPaymentSizeLabel(pet?.sizeKey)),
          lines,
          subtotal: lines.reduce((sum, line) => sum + line.price, 0),
        };
      });
    },

    buildPaymentServicePrices(petBreakdown) {
      const pets = Array.isArray(petBreakdown) ? petBreakdown : [];
      const servicePrices = [];

      pets.forEach((pet) => {
        (Array.isArray(pet?.lines) ? pet.lines : []).forEach((line) => {
          const bookingServiceId =
            line?.bookingServiceId ?? line?.booking_service_id ?? null;
          const amount = parseFloat(line?.amount);

          if (!bookingServiceId || !Number.isFinite(amount)) {
            return;
          }

          servicePrices.push({
            booking_service_id: bookingServiceId,
            amount: Number(line.amount),
          });
        });
      });

      return servicePrices;
    },

    buildServerPaymentReceiptPets(summary, fallbackPets = []) {
      if (!Array.isArray(summary?.pets)) {
        return this.buildPaymentReceiptPets(fallbackPets);
      }

      const bookingPets = this.buildPaymentReceiptPets(fallbackPets);
      return summary.pets.map((pet, index) => {
        const bookingPet = bookingPets.find((candidate) => String(candidate.id) === String(pet.booking_pet_id))
          ?? bookingPets[index];
        return {
          id: pet.booking_pet_id ?? `receipt-pet-${index + 1}`,
          name: this.formatReceiptValue(pet.pet_name, `Pet ${index + 1}`),
          species: this.formatReceiptValue(pet.pet_species),
          breed: bookingPet?.breed ?? "Not specified",
          sizeLabel: pet.confirmed_size ? formatPaymentSizeLabel(pet.confirmed_size) : bookingPet?.sizeLabel ?? "Not specified",
          lines: (pet.service_breakdown || []).map((line, lineIndex) => ({
            id: line.booking_service_id ?? `${index}-${lineIndex}`,
            name: this.formatReceiptValue(line.label, "Grooming Service"),
            price: Number(line.price_at_booking || 0),
          })),
          subtotal: Number(pet.final_pet_charge || 0),
        };
      });
    },

    async submitPayment() {
      if (this.paymentModal.busy || this.paymentModal.onlinePayment) return;
      const modal = this.paymentModal;
      const { booking, isEarlyPayment, amountPaid, notes } = this.paymentModal;
      const fp = Number(this.paymentTotalDue.toFixed(2));
      const paymentMethod = modal.paymentMethod || 'cash';
      const ap = Number(paymentMethod === 'qrph' ? fp : amountPaid);

      if (this.paymentModal.petBreakdown.length === 0) {
        this.paymentModal.error = "No booked services were found for this payment.";
        return;
      }

      if (this.hasMissingPaymentPrices()) {
        this.paymentModal.error = "Please enter the confirmed price for every service.";
        return;
      }

      const invalidLine = this.getInvalidPaymentLine();
      if (invalidLine) {
        this.paymentModal.error = `${invalidLine.line.name} for ${invalidLine.pet.name}: ${invalidLine.message}`;
        return;
      }

      if (!fp || fp <= 0) {
        this.paymentModal.error = "Please enter service prices before confirming payment.";
        return;
      }
      const cashError = paymentMethod === 'online' ? '' : paymentMethod === 'qrph'
        ? modal.qr?.validationError(fp) || (!modal.qr ? 'QR payments are unavailable.' : '')
        : window.CashPayment.error(amountPaid, fp);
      if (cashError) {
        if (paymentMethod === 'qrph' && modal.qr?.referenceError) this.focusPaymentReference(modal);
        else this.paymentModal.error = cashError;
        return;
      }

      const servicePrices = this.buildPaymentServicePrices(this.paymentModal.petBreakdown);
      const petSizes = this.paymentModal.petBreakdown
        .filter((pet) => pet.bookingPetId && ["small", "medium", "large", "extra_large"].includes(pet.sizeKey))
        .map((pet) => ({ booking_pet_id: pet.bookingPetId, size: pet.sizeKey }));
      if (servicePrices.length !== this.paymentLineCount) {
        this.paymentModal.error = "Service price records are incomplete. Please refresh and try again.";
        return;
      }

      this.paymentModal.busy  = true;
      this.paymentModal.error = "";

      try {
        const payload = {
          final_price:     fp,
          ...(paymentMethod !== 'online' ? {amount_paid: ap} : {}),
          payment_method:  paymentMethod,
          notes:           notes || null,
          service_prices:  servicePrices,
          pet_sizes:       petSizes,
          products:        this.paymentModal.products.map((line) => ({ item_id: line.item_id, quantity: line.quantity })),
          ...(paymentMethod === 'qrph' ? modal.qr.payload() : {}),
        };
        if (paymentMethod === 'qrph' && modal.qr.receiptFile) payload.receipt = modal.qr.receiptFile;
        if (paymentMethod === 'online') {
          window.OnlinePayment.remember('grooming', {}, booking.id);
          const response = await API.createGroomingOnlinePayment(booking.id, {...payload, flow: isEarlyPayment ? 'early' : 'final'});
          if (this.paymentModal !== modal || !modal.open) return;
          if (response.success === false || !['pending', 'paid'].includes(response.online_payment?.status)) {
            throw new Error(response.message || 'Unable to create online payment. Check its status before retrying.');
          }
          await this.applyOnlinePaymentStatus(response);
          if (response.online_payment?.status === 'pending') this.openGroomingCheckout();
          return;
        }
        const res = isEarlyPayment
          ? await API.payNow(booking.id, payload)
          : await API.processPayment(booking.id, payload);

        await this.completeGroomingPayment(res);
      } catch (err) {
        if (this.paymentModal !== modal || !modal.open) return;
        if (paymentMethod === 'online') {
          try {
            await this.applyOnlinePaymentStatus(await API.groomingOnlinePaymentStatus(booking.id));
            if (modal.onlinePayment) return;
          } catch { /* Preserve the original failure; the saved identity supports recovery. */ }
        }
        const {external_reference: referenceErrors, ...otherErrors} = err.errors || {};
        const referenceError = [referenceErrors].flat().filter(Boolean).join(' ');
        if (paymentMethod === 'qrph' && referenceError) {
          modal.qr.setReferenceError(referenceError);
          modal.error = Object.values(otherErrors).flat().join(' ');
          this.focusPaymentReference(modal);
        } else {
          modal.error = Object.values(err.errors || {}).flat().join(" ") || err.message || "Payment failed. Please try again.";
        }
      } finally {
        if (this.paymentModal === modal) modal.busy = false;
      }
    },

    async completeGroomingPayment(res) {
      const { booking, isEarlyPayment, amountPaid, notes } = this.paymentModal;
      const fp = this.paymentTotalDue;
      const ap = Number(amountPaid || 0);
      const paymentMethod = res.payment_method || 'cash';
      const receiptPets = this.buildServerPaymentReceiptPets(
        res?.payment_summary,
        this.paymentModal.petBreakdown,
      );
      const receiptPetNames = receiptPets.map((pet) => pet.name).filter(Boolean).join(", ");
      const receiptServiceNames = [
        ...new Set(
          receiptPets.flatMap((pet) => pet.lines.map((line) => line.name)).filter(Boolean),
        ),
      ].join(", ");

      const receiptProducts = res.product_addons || [];
      const finalPrice = Number(res.final_price ?? fp);
      const productsSubtotal = receiptProducts.reduce(
        (sum, line) => sum + Math.round(Number(line.subtotal) * 100), 0,
      ) / 100;
      const groomingSubtotal = (Math.round(finalPrice * 100) - Math.round(productsSubtotal * 100)) / 100;

      window.OnlinePayment?.clear('grooming');
      this.closePaymentModal(true);
      this.receiptModal = {
        open: true,
        isTest: res.is_test === true,
        bookingReference: this.formatReceiptValue(
          booking.bookingReference ?? booking.booking_reference ?? booking.reference ?? booking.id,
          "Pending",
        ),
        ownerName:     this.formatReceiptValue(booking.ownerName, "Not provided"),
        contactNumber: this.formatReceiptValue(booking.contactNumber, "Not provided"),
        petName:       receiptPetNames || this.formatReceiptValue(booking.petName, "No pet selected"),
        serviceLabel:  receiptServiceNames || this.formatReceiptValue(booking.serviceLabel, "Grooming"),
        appointmentDate: this.formatReceiptValue(booking.appointmentDate, "No date selected"),
        appointmentTime: this.formatReceiptValue(booking.appointmentTime, "No time selected"),
        pets:          receiptPets,
        products:      receiptProducts,
        groomingSubtotal,
        productsSubtotal,
        finalPrice,
        amountPaid:    paymentMethod === 'online' ? null : Number(res.amount_paid ?? ap),
        change:        paymentMethod === 'online' ? null : res.change ?? (ap - fp),
        paymentMethod: res.payment_method_label ?? (res.payment_method === "cash" ? "Cash" : res.payment_method ?? paymentMethod),
        qrPayment: res.qr_payment || null,
        notes:         res.notes ?? notes ?? "",
        paidAt:        res.paid_at ?? (res.qr_payment ? "" : new Date().toLocaleString("en-PH")),
        isEarlyPayment,
      };
      await this.loadAdminBookings();
      if (!isEarlyPayment && res?.booking_status === "released") {
        this.setTab("to-be-picked-up");
      }
      if (isEarlyPayment && res?.all_pets_finished) {
        this.setTab("to-be-picked-up");
      }
    },

    formatQrDate(value) {
      return window.PaymentInvoice.formatPaymentDateTime(value);
    },

    stopOnlinePaymentPoll() {
      this._groomingPaymentWatch?.stop();
      this._groomingPaymentWatch = null;
    },

    scheduleOnlinePaymentPoll() {
      const modal = this.paymentModal;
      if (this._groomingPaymentWatch || !modal.open || modal.onlinePayment?.status !== 'pending') return;
      this._groomingPaymentWatch = window.OnlinePayment.watch({
        load: () => API.groomingOnlinePaymentStatus(modal.booking.id, modal.onlinePayment.id),
        apply: response => this.applyOnlinePaymentStatus(response),
        error: error => {modal.error = error.message || 'Could not check payment.';},
        active: () => this.paymentModal === modal && modal.open && !this._destroyed && modal.onlinePayment?.status === 'pending',
        exhausted: () => {modal.error = 'Payment is still unconfirmed. Check Status to continue; the checkout remains held.';},
      });
      this._groomingPaymentWatch.start();
    },

    async checkGroomingPaymentStatus() {
      if (this.paymentModal.busy) return;
      this.stopOnlinePaymentPoll();
      this.scheduleOnlinePaymentPoll();
      await this._groomingPaymentWatch?.check();
    },

    async applyOnlinePaymentStatus(response) {
      const attempt = response.online_payment;
      if (attempt?.status === "paid") {
        this.stopOnlinePaymentPoll();
        this.paymentModal.onlinePayment = attempt;
        this.paymentModal.paymentMethod = "online";
        this.paymentModal.isEarlyPayment = attempt.flow === "early";
        if (attempt.reconciliation_reason || !response.payment) {
          this.paymentModal.error = 'Payment received. Contact an administrator for reconciliation.';
          return;
        }
        await this.completeGroomingPayment(response.payment);
      } else if (attempt?.status === "pending") {
        if (response.checkout_summary?.pets) {
          const modal = this.paymentModal;
          modal.notes = response.checkout_notes ?? modal.notes;
          modal.checkoutDraft ||= {pets: modal.petBreakdown, products: modal.products};
          modal.petBreakdown = this.normalizePaymentPets({pets: response.checkout_summary.pets.map(pet => ({
            ...pet, species: pet.pet_species, confirmed_size: pet.confirmed_size,
          }))}).map((pet, index) => ({...pet, isSizeEditing: false,
            lines: (response.checkout_summary.pets[index].service_breakdown || []).map(line => ({
              id: line.booking_service_id, bookingServiceId: line.booking_service_id, name: line.label,
              amount: line.price_at_booking, pricingType: 'fixed', isFixedPriceLocked: true,
              priceHint: this.formatPeso(line.price_at_booking), description: '', priceTouched: false,
            })),
          }));
          modal.products = (response.checkout_products || []).map(line => ({
            item_id: line.item_id, name: line.name, quantity: line.quantity, price: line.priceCents / 100,
          }));
        }
        window.OnlinePayment?.remember('grooming', attempt, this.paymentModal.booking.id);
        this.paymentModal.onlinePayment = attempt;
        this.paymentModal.paymentMethod = "online";
        this.paymentModal.isEarlyPayment = attempt.flow === "early";
        this.paymentModal.error = attempt.reconciliation_reason
          ? "Checkout creation needs reconciliation. Contact an administrator." : "";
        this.scheduleOnlinePaymentPoll();
      } else {
        this.stopOnlinePaymentPoll();
        if (this.paymentModal.checkoutDraft) {
          this.paymentModal.petBreakdown = this.paymentModal.checkoutDraft.pets;
          this.paymentModal.products = this.paymentModal.checkoutDraft.products;
          this.paymentModal.checkoutDraft = null;
        }
        this.paymentModal.onlinePayment = null;
        this.paymentModal.verifying = false;
        window.OnlinePayment?.clear('grooming');
        this.paymentModal.error = ({failed: 'Checkout could not be created. Try again.', cancelled: 'Online payment cancelled.', expired: 'Checkout expired. Choose a payment method to retry.'})[attempt?.status] || '';
      }
    },

    openGroomingCheckout() {
      window.OnlinePayment?.open('grooming', this.paymentModal.onlinePayment, this.paymentModal.booking.id);
    },
    async restoreGroomingOnlinePayment() {
      const saved = window.OnlinePayment?.get('grooming');
      if (!saved?.payable) return;
      try {
        const response = await API.groomingOnlinePaymentStatus(saved.payable, saved.id);
        const booking = [this.incomingList, this.queuedList, this.inProgressList, this.forPaymentList, this.releasedList]
          .flat().find(item => String(item?.id) === String(saved.payable)) || response.booking;
        if (!booking) throw new Error('Payment context could not be restored.');
        await this.openPaymentModal({...booking, paymentReady: true}, response.online_payment?.flow === 'early', saved.id, response);
        if (this.paymentModal.open) {
          this.paymentModal.verifying = saved.outcome === 'success';
          if (saved.outcome === 'cancel' && this.paymentModal.onlinePayment?.status === 'pending') await this.cancelOnlinePayment();
        }
      } catch (error) {
        this.paymentModal.open = true; this.paymentModal.error = error.message || 'Could not restore payment. Reopen this payment to retry.';
      }
    },

    async cancelOnlinePayment() {
      if (this.paymentModal.busy) return;
      this.stopOnlinePaymentPoll();
      this.paymentModal.busy = true;
      try {
        const response = await API.cancelGroomingOnlinePayment(this.paymentModal.booking.id);
        await this.applyOnlinePaymentStatus(response);
      } catch (error) {
        this.paymentModal.error = error.message || "Online payment is still pending.";
      } finally {
        this.paymentModal.busy = false;
        if (this.paymentModal.open && this.paymentModal.onlinePayment?.status === "pending") this.scheduleOnlinePaymentPoll();
      }
    },

    closeReceiptModal() {
      this.receiptModal = {
        open: false, bookingReference: "", ownerName: "", contactNumber: "",
        petName: "", serviceLabel: "", appointmentDate: "", appointmentTime: "",
        pets: [], products: [], groomingSubtotal: 0, productsSubtotal: 0,
        finalPrice: 0, amountPaid: 0, change: 0,
        paymentMethod: "", notes: "", paidAt: "", isEarlyPayment: false,
      };
    },

    printReceipt() {
      if (this.receiptModal.qrPayment || this.receiptModal.paymentMethod === 'QR Payment') return;
      window.PaymentInvoice.print();
    },

    async releaseBooking(booking) {
      try {
        await API.releaseBooking(booking.id);
        await this.loadAdminBookings();
      } catch (err) {
        alert(err.message || "Release failed. Please try again.");
      }
    },

    confirmPickedUp(booking) {
      this.pickupModal = { open: true, booking, busy: false };
    },

    async executePickedUp() {
      const booking = this.pickupModal.booking;
      if (!booking) return;

      this.pickupModal.busy = true;
      try {
        await API.markPickedUp(booking.id);
        this.pickupModal = { open: false, booking: null, busy: false };
        await this.loadAdminBookings();
      } catch (err) {
        this.pickupModal.busy = false;
        alert(err.message || "Failed to mark as picked up. Please try again.");
      }
    },

    // ── Admin Bookings ────────────────────────────────────────────────────────

    loadAdminBookings({ force = false } = {}) {
      const selectedDate = this.selectedDate;
      const cacheRevision = API.getAdminDataRevision?.();
      if (this._bookingRequest && this._bookingRequestDate === selectedDate && this._bookingCacheRevision === cacheRevision) return this._bookingRequest;
      this._bookingCacheRevision = cacheRevision;
      const revision = ++this._bookingRevision;
      this._bookingRequestDate = selectedDate;
      this.bookingsLoading = !this.bookingsLoaded;
      this.bookingsError = '';
      const params = new URLSearchParams();
      if (selectedDate) params.set("date", selectedDate);
      if (this.showFutureAppointments) params.set("include_future", "1");
      params.set("view", "board");
      const pending = API.loadAdminData(`/admin/bookings?${params}`, () => API.getAdminBookings(selectedDate, {
        includeFuture: this.showFutureAppointments, boardOnly: true, force,
      }), data => {
        this._resetPollFailures("_bookingInterval");
        if (revision !== this._bookingRevision || selectedDate !== this.selectedDate || this._destroyed) return;
        this.applyDashboardData(data);
        this.bookingsLoaded = true;
      }, { force }).catch(error => {
        if (revision !== this._bookingRevision || this._destroyed) return;
        this.bookingsError = 'Could not refresh Grooming.';
        this._recordPollFailure("_bookingInterval", "loadAdminBookings", error);
      }).finally(() => {
        if (revision === this._bookingRevision) {
          this.bookingsLoading = false;
          this._bookingRequest = null;
        }
      });
      this._bookingRequest = pending;
      return pending;
    },

    // Called when the date picker changes — reloads bookings for the new date
    onDateChange() {
      this.bookingsLoaded = false;
      this.loadAdminBookings();
    },

    closeDetailsModal() {
      this.detailsModalOpen = false;
      this.detailsBooking   = null;
      this.cancelNoteEdit();
      this.sedationConsentCapture = {
        confirmed: false,
        busy: false,
        error: "",
      };
      this._detailsReturnFocus?.focus();
      this._detailsReturnFocus = null;
    },

    trapGroomingModalFocus(event) {
      const controls = [...event.currentTarget.querySelectorAll('button, input, textarea, summary, [tabindex="0"]')]
        .filter((element) => !element.disabled && element.getClientRects().length);
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
      }
    },

    canEditInternalStaffNote(booking = this.detailsBooking) {
      return booking?.bookingType !== "Walk-In" && ["incoming", "queued", "in-progress"].includes(this.normalizeStatus(booking?.status));
    },

    canEditGroomingVisitNotes(booking = this.detailsBooking) {
      return booking?.bookingType === "Walk-In" && ["queued", "in-progress"].includes(this.normalizeStatus(booking?.status));
    },

    beginNoteEdit(kind, pet = null) {
      if (kind === "staff" && !this.canEditInternalStaffNote()) return;
      if (kind === "pet" && !this.canEditGroomingVisitNotes()) return;
      this.noteEditor = {
        kind,
        bookingPetId: pet?.bookingPetId ?? null,
        value: kind === "staff" ? (this.detailsBooking?.internalStaffNote || "") : (pet?.specialInstructions || ""),
        busy: false,
        error: "",
      };
    },

    cancelNoteEdit() {
      this.noteEditor = { kind: "", bookingPetId: null, value: "", busy: false, error: "" };
    },

    async saveNoteEdit() {
      const { kind, bookingPetId, value } = this.noteEditor;
      if (this.noteEditor.busy || !this.detailsBooking?.id) return;
      if (kind === "staff" && !this.canEditInternalStaffNote()) return;
      if (kind === "pet" && !this.canEditGroomingVisitNotes()) return;
      this.noteEditor.busy = true;
      this.noteEditor.error = "";
      try {
        if (kind === "staff") {
          const result = await API.adminUpdateInternalStaffNote(this.detailsBooking.id, value);
          this.detailsBooking.internalStaffNote = result.internal_staff_note;
        } else {
          const result = await API.adminUpdateGroomingVisitNotes(this.detailsBooking.id, bookingPetId, value);
          const pet = this.detailsBooking.pets.find((item) => item.bookingPetId === bookingPetId);
          if (pet) pet.specialInstructions = result.grooming_visit_notes;
        }
        this.cancelNoteEdit();
        await this.loadAdminBookings();
      } catch (error) {
        this.noteEditor.busy = false;
        this.noteEditor.error = error.message || "Could not save notes.";
      }
    },

    sedationConsentStatusLabel(booking = this.detailsBooking) {
      if (booking?.sedationConsent) return "Sedation authorized";
      return booking?.sedationConsentRecorded ? "Sedation not authorized" : "Sedation not recorded";
    },

    sedationConsentStatusClass(booking) {
      return booking?.sedationConsent
        ? "bg-green-100 text-green-800"
        : "bg-amber-100 text-amber-900";
    },

    sedationConsentSourceLabel(booking) {
      if (!booking?.sedationConsent) return "";
      if (booking.sedationConsentSource === "customer_online") return "Online";
      return ["staff_in_person", "staff_walk_in"].includes(booking.sedationConsentSource) ? "In person" : "";
    },

    async recordDetailsSedationConsent() {
      const bookingId = this.detailsBooking?.id;
      if (!bookingId || this.sedationConsentCapture.busy) return;

      if (!this.sedationConsentCapture.confirmed) {
        this.sedationConsentCapture.error =
          "Confirm that the customer understands and agrees.";
        return;
      }

      this.sedationConsentCapture.busy = true;
      this.sedationConsentCapture.error = "";

      try {
        const response = await API.adminRecordSedationConsent(bookingId);
        this.detailsBooking = {
          ...this.detailsBooking,
          sedationConsent: true,
          sedation_consent: true,
          sedationConsentRecorded: true,
          sedationConsentSource: response.sedation_consent?.source || "staff_in_person",
          sedationConsentRecordedAt: response.sedation_consent?.recorded_at || null,
          canRecordSedationConsent: false,
        };
        this.sedationConsentCapture = {
          confirmed: false,
          busy: false,
          error: "",
        };
        await this.loadAdminBookings();
      } catch (error) {
        this.sedationConsentCapture.busy = false;
        this.sedationConsentCapture.error =
          error.message || "Failed to record sedation consent.";
      }
    },

  };
}
