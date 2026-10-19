function getAdminNotificationIcon(name) {
  return ({ 'calendar-days': 'calendar-dots', 'circle-check': 'check-circle', 'credit-card': 'credit-card',
    'circle-x': 'x-circle', history: 'clock-counter-clockwise', stethoscope: 'stethoscope' })[name] || 'bell';
}

function getAdminSidebarActivePage(pathname = window.location.pathname) {
  const normalizedPath = String(pathname || "")
    .replace(/\\/g, "/")
    .toLowerCase();

  if (
    normalizedPath.includes("/inventory/") ||
    normalizedPath.endsWith("/inventory.html")
  ) {
    return "inventory";
  }

  const pageName = normalizedPath.split("/").filter(Boolean).pop() || "";
  const pageMap = {
    "dashboard.html": "dashboard",
    "clients.html": "customers",
    "appointments.html": "appointments",
    "notifications.html": "appointments",
    "clinic.html": "clinic",
    "services.html": "services",
    "archive.html": "archive",
    "transactions.html": "transactions",
    "reports.html": "reports",
    "settings.html": "settings",
  };

  return pageMap[pageName] || "";
}

function revealStaffSettingsSidebarLink() {
  document.querySelectorAll('.admin-sidebar-menu a[href$="settings.html"]').forEach((link) => {
    const adminOnlySection = link.closest('[x-show="isAdmin"]');
    if (adminOnlySection) adminOnlySection.setAttribute('x-show', 'sidebarReady');
  });
}

const adminInventoryLinks = { overview: "Overview", products: "Products", "sell-product": "Point of Sale", history: "Stock Movements" };
const adminInventoryRoutes = ["overview", "products", "sell-product", "stock-in", "stock-out", "history"];

function getAdminInventorySection() {
  if (!String(window.location.pathname || "").endsWith("/inventory.html")) return "";
  const section = String(window.location.hash || "").slice(1).split("?")[0];
  return adminInventoryRoutes.includes(section) ? section : "overview";
}

function installAdminInventoryNavigation() {
  const link = document.querySelector('.admin-sidebar-menu a[href*="inventory.html"]');
  if (!link) return;
  const url = new URL("inventory.html", link.href);
  const group = document.createElement("div");
  group.className = "admin-inventory-navigation";
  const button = document.createElement("button");
  button.type = "button";
  button.className = `${link.className} w-full`;
  button.setAttribute(":class", link.getAttribute(":class") || "''");
  button.setAttribute("@click", "inventoryExpanded = activePage === 'inventory' || !inventoryExpanded");
  button.setAttribute(":aria-expanded", "inventoryExpanded");
  button.setAttribute("aria-expanded", String(getAdminSidebarActivePage() === 'inventory'));
  button.setAttribute("aria-controls", "inventory-submenu");
  button.innerHTML = `${link.innerHTML}<span class="ml-auto text-xs" aria-hidden="true" x-text="inventoryExpanded ? '▾' : '▸'"></span>`;
  const submenu = document.createElement("div");
  submenu.id = "inventory-submenu";
  submenu.setAttribute("x-show", "inventoryExpanded");
  submenu.setAttribute("x-cloak", "");
  submenu.className = "admin-inventory-submenu";
  for (const [section, label] of Object.entries(adminInventoryLinks)) {
    const child = document.createElement("a");
    child.href = `${url.pathname}#${section}`;
    child.textContent = label;
    child.setAttribute(":class", `activePage === 'inventory' && inventorySection === '${section}' ? 'is-active' : ''`);
    child.setAttribute(":aria-current", `activePage === 'inventory' && inventorySection === '${section}' ? 'page' : null`);
    child.setAttribute("@click", "sidebarOpen = false");
    submenu.appendChild(child);
  }
  group.append(button, submenu);
  link.replaceWith(group);
}

function installAdminServicesNavigation() {
  const clinic = document.querySelector('.admin-sidebar-menu a[href$="clinic.html"]');
  if (!clinic) return;
  clinic.setAttribute('@click', 'sidebarOpen = false');
  clinic.setAttribute('x-show', 'isAdmin || clinicPermissions.access');
  const grooming = document.querySelector('.admin-sidebar-menu a[href$="appointments.html"]');
  grooming?.setAttribute('x-show', 'isAdmin || canAccessGrooming');
  const services = document.createElement('a');
  services.href = new URL('services.html', clinic.href).pathname;
  services.className = clinic.className;
  services.setAttribute(':class', "activePage === 'services' ? 'bg-[#315b7e] text-white shadow-sm' : 'text-[#2f4b66]'");
  services.setAttribute('x-show', 'isAdmin || clinicPermissions.services_view');
  services.setAttribute('@click', 'sidebarOpen = false');
  services.innerHTML = `<svg class="ph-icon h-5 w-5" aria-hidden="true" viewBox="0 0 256 256" fill="currentColor" focusable="false"><use href="${new URL('../../assets/icons/phosphor.svg?v=admin-icons-20261018', document.currentScript.src).href}#tag"></use></svg><span>Services</span>`;
  clinic.after(services);
}

function getAdminSidebarIdentityState(user) {
  if (!user) return {};
  const first = String(user.first_name || '').trim(), last = String(user.last_name || '').trim();
  return {
    sidebarReady: true,
    isAdmin: user.role === 'admin',
    clinicPermissions: user.clinic_permissions || {},
    canAccessGrooming: user.role === 'admin' || (user.staff_type === 'grooming' && user.staff_subrole === 'grooming_receptionist'),
    staffDisplayName: `${first} ${last}`.trim() || 'Staff',
    staffInitials: `${first[0] || ''}${last[0] || ''}`.toUpperCase() || 'ST',
    staffRoleLabel: user.staff_type === 'clinic'
      ? ({ veterinarian: 'Veterinarian', clinic_receptionist: 'Clinic Receptionist' }[user.staff_subrole] || 'Clinic Staff')
      : user.staff_subrole === 'grooming_receptionist' ? 'Grooming Receptionist' : 'Staff',
  };
}

// Paint verified presentation before Alpine or any unrelated API request finishes.
function renderCachedAdminSidebar() {
  const user = window.API?.getCachedStaffIdentity?.()?.user;
  const aside = document.querySelector('.admin-sidebar-menu');
  if (!user || !aside) return;
  const state = getAdminSidebarIdentityState(user), activePage = getAdminSidebarActivePage();
  const visible = {
    isAdmin: state.isAdmin, sidebarReady: true,
    'isAdmin || clinicPermissions.access': state.isAdmin || !!state.clinicPermissions.access,
    'isAdmin || clinicPermissions.services_view': state.isAdmin || !!state.clinicPermissions.services_view,
    'isAdmin || canAccessGrooming': state.canAccessGrooming,
  };
  aside.querySelectorAll('[x-show]').forEach(el => {
    const condition = el.getAttribute('x-show');
    if (Object.hasOwn(visible, condition)) el.style.display = visible[condition] ? '' : 'none';
  });
  aside.querySelectorAll('[data-admin-navigation]').forEach(el => {
    el.removeAttribute('x-cloak');
    el.style.removeProperty('display');
  });
  const text = {
    "isAdmin ? 'AD' : staffInitials": state.isAdmin ? 'AD' : state.staffInitials,
    "isAdmin ? 'Admin' : staffDisplayName": state.isAdmin ? 'Admin' : state.staffDisplayName,
    "isAdmin ? 'Clinic Manager' : staffRoleLabel": state.isAdmin ? 'Clinic Manager' : state.staffRoleLabel,
  };
  aside.querySelectorAll('[x-text]').forEach(el => {
    const value = text[el.getAttribute('x-text')];
    if (value) el.textContent = value;
  });
  aside.querySelectorAll('nav a, nav button').forEach(el => {
    const selected = el.getAttribute(':class')?.includes(`activePage === '${activePage}'`);
    if (el.closest('.admin-inventory-submenu')) return;
    el.classList.toggle('text-[#2f4b66]', !selected);
    for (const name of ['bg-[#315b7e]', 'text-white', 'shadow-sm']) el.classList.toggle(name, !!selected);
    if (selected && el.tagName === 'A') el.setAttribute('aria-current', 'page');
  });
  if (activePage === 'inventory') {
    const submenu = aside.querySelector('#inventory-submenu');
    submenu?.removeAttribute('x-cloak');
    submenu?.style.removeProperty('display');
    submenu?.querySelectorAll('a').forEach(link => {
      const selected = new URL(link.href).hash === `#${getAdminInventorySection()}`;
      link.classList.toggle('is-active', selected);
      if (selected) link.setAttribute('aria-current', 'page');
    });
  }
  aside.setAttribute('data-admin-navigation-ready', '');
  aside.closest('[data-admin-shell]')?.setAttribute('data-admin-ready', '');
}

// Build the shared group before Alpine walks the sidebar. Replacing a live
// Alpine link can leave its queued effects detached from their parent scope.
if (typeof document !== "undefined" && document.querySelector) {
  installAdminInventoryNavigation();
  installAdminServicesNavigation();
  revealStaffSettingsSidebarLink();
  document.querySelectorAll('.admin-sidebar-menu nav a').forEach(link => {
    link.setAttribute('@click', 'sidebarOpen = false');
    if (link.closest('.admin-inventory-submenu')) return;
    const page = getAdminSidebarActivePage(new URL(link.href).pathname);
    link.setAttribute(':aria-current', `activePage === '${page}' ? 'page' : null`);
  });
  document.querySelectorAll('.admin-sidebar-menu a[href$="archive.html"] span').forEach((label) => {
    if (label.textContent.trim() === "Archive") label.textContent = "History";
  });
  renderCachedAdminSidebar();
}

function adminSidebar() {
  return {
    sidebarOpen: false,
    sidebarReady: false,
    sidebarError: '',
    activePage: getAdminSidebarActivePage(),
    inventoryExpanded: getAdminSidebarActivePage() === "inventory",
    inventorySection: getAdminInventorySection(),
    clinicPermissions: {},
    canAccessGrooming: false,
    _inventoryNavigationListener: null,
    isAdmin: false,
    staffDisplayName: "Staff",
    staffRoleLabel: "Staff",
    staffInitials: "ST",
    clinicStopped: false,
    clinicStatusLoaded: false,
    incomingAppointmentCount: 0,
    _incomingAppointmentRevision: 0,
    _incomingAppointmentInterval: null,
    _incomingAppointmentListener: null,
    _visibilityListener: null,
    _sidebarSessionListener: null,
    _incomingCountRequest: null,
    _clinicStatusRequest: null,
    _destroyed: false,
    ...getAdminSidebarIdentityState(window.API?.getCachedStaffIdentity?.()?.user),
    stopModal: {
      open: false,
      title: "",
      message: "",
      confirmLabel: "Confirm",
      action: "",
      busy: false,
      error: "",
    },
    blockedDates: [],
    blockedDatesLoading: false,
    blockedDatesLoaded: false,
    blockedDatesError: "",
    _blockedDatesRequest: 0,
    blockDatesModal: { open: false },
    blockForm: {
      startDate: "",
      endDate: "",
      reason: "",
      busy: false,
      error: "",
      warning: "",
    },

    get hasIncomingAppointments() {
      // Keep the shared sidebar binding; the dot represents all active grooming customers today.
      return this.incomingAppointmentCount > 0;
    },

    async init() {
      if (this._sidebarInitialized) return;
      this._sidebarInitialized = true;
      this.detectActivePage();
      this._inventoryNavigationListener = () => {
        this.inventorySection = getAdminInventorySection();
        if (this.activePage === "inventory") this.inventoryExpanded = true;
      };
      window.addEventListener("hashchange", this._inventoryNavigationListener);
      if (API.enforceAdminPageAccess && !API.enforceAdminPageAccess()) return;

      this.registerIncomingAppointmentListener();
      this._navigationInvalidationListener = (event) => {
        this.sidebarReady = false;
        this.$root?.removeAttribute('data-admin-ready');
        this.$root?.querySelector('.admin-sidebar-menu')?.removeAttribute('data-admin-navigation-ready');
        if (event.detail?.reload) void this.loadLoggedInIdentity();
      };
      window.addEventListener('admin-navigation:invalidated', this._navigationInvalidationListener);

      const ownsGroomingData = ['dashboard', 'appointments'].includes(this.activePage);
      if (this.activePage === 'dashboard') {
        this._dashboardStatusReady = () => { void this.loadClinicStatus(); };
        window.addEventListener('admin-dashboard:ready', this._dashboardStatusReady, { once: true });
      }
      await Promise.all([
        this.activePage === 'dashboard' ? Promise.resolve() : this.loadClinicStatus(),
        this.loadLoggedInIdentity().then(async () => {
          if (!API.getAdminGroomingActivity) await window.AppClock?.load?.();
          if (!ownsGroomingData && (this.isAdmin || this.staffRoleLabel === 'Grooming Receptionist')) return this.loadIncomingAppointmentCount();
        }),
      ]);
      if (this._destroyed) return;
      const refresh = () => {
        if (document.visibilityState === 'hidden' || API.isSessionLocked?.() || this._destroyed) return;
        void this.loadClinicStatus();
        if (!ownsGroomingData && (this.isAdmin || this.staffRoleLabel === 'Grooming Receptionist')) void this.loadIncomingAppointmentCount();
      };
      this._incomingAppointmentInterval = setInterval(refresh, 30000);
      this._visibilityListener = () => { if (document.visibilityState !== 'hidden') refresh(); };
      document.addEventListener('visibilitychange', this._visibilityListener);
      this._sidebarSessionListener = () => {
        void this.loadLoggedInIdentity();
        Promise.allSettled([this._incomingCountRequest, this._clinicStatusRequest]).then(refresh);
      };
      window.addEventListener('admin-session:unlocked', this._sidebarSessionListener);
    },

    destroy() {
      this._destroyed = true;
      this._incomingAppointmentRevision++;
      document.removeEventListener?.('visibilitychange', this._visibilityListener);
      window.removeEventListener('admin-session:unlocked', this._sidebarSessionListener);
      window.removeEventListener('admin-dashboard:ready', this._dashboardStatusReady);
      window.removeEventListener("hashchange", this._inventoryNavigationListener);
      window.removeEventListener('admin-navigation:invalidated', this._navigationInvalidationListener);
      if (this._incomingAppointmentInterval) {
        clearInterval(this._incomingAppointmentInterval);
      }

      if (this._incomingAppointmentListener) {
        window.removeEventListener(
          "admin-dashboard:data-applied",
          this._incomingAppointmentListener,
        );
      }
    },

    detectActivePage() {
      this.activePage = getAdminSidebarActivePage();
    },

    async loadLoggedInIdentity() {
      if (typeof API.getMe !== "function") return;
      this.sidebarError = '';
      try {
        const response = await (API.getStaffIdentity ? API.getStaffIdentity() : API.getMe("staff"));
        const user = response?.user;
        if (!user || this._destroyed) return;
        Object.assign(this, getAdminSidebarIdentityState(user));
        this.$nextTick?.(() => {
          this.$root?.setAttribute('data-admin-ready', '');
          this.$root?.querySelector('.admin-sidebar-menu')?.setAttribute('data-admin-navigation-ready', '');
          document.documentElement?.setAttribute('data-admin-role', this.isAdmin ? 'admin' : 'staff');
        });
      } catch {
        this.sidebarError = 'Could not verify access.';
      }
    },

    registerIncomingAppointmentListener() {
      this._incomingAppointmentListener = (event) => {
        const state = event.detail?.state;
        if (Number.isFinite(state?.activeTodayCount)) {
          this._incomingAppointmentRevision++;
          this.incomingAppointmentCount = state.activeTodayCount;
          return;
        }
        if (state?.selectedDate === this.todayDate()) {
          this.setIncomingAppointmentCount(state);
        }
      };

      window.addEventListener(
        "admin-dashboard:data-applied",
        this._incomingAppointmentListener,
      );
    },

    todayDate() {
      const today = window.AppClock?.todayKey?.();
      if (today) return today;

      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    },

    setIncomingAppointmentCount(data, today = this.todayDate()) {
      this._incomingAppointmentRevision += 1;
      // Use the existing status lists, including released bookings awaiting pickup.
      const activeLists = [
        data?.incomingList ?? data?.incoming,
        data?.queuedList ?? data?.queued,
        data?.inProgressList ?? data?.inProgress,
        data?.forPaymentList ?? data?.forPayment,
        data?.forPickupList ?? data?.forPickup,
        data?.releasedList,
      ];
      this.incomingAppointmentCount = activeLists.reduce((count, list) =>
        count + (Array.isArray(list)
          ? list.filter((booking) => booking?.appointmentDate === today).length
          : 0), 0);
    },

    async loadIncomingAppointmentCount() {
      if (this._incomingCountRequest) return this._incomingCountRequest;
      if (
        !window.API ||
        typeof API.getAdminBookings !== "function" ||
        typeof API.getAdminToken !== "function" ||
        !API.getAdminToken()
      ) {
        return;
      }

      try {
        const today = this.todayDate();
        const revision = ++this._incomingAppointmentRevision;
        this._incomingCountRequest = API.getAdminGroomingActivity
          ? API.getAdminGroomingActivity() : API.getAdminBookings(today);
        const data = await this._incomingCountRequest;
        if (!this._destroyed && revision === this._incomingAppointmentRevision && today === this.todayDate()) {
          if (Number.isFinite(data.activeTodayCount)) this.incomingAppointmentCount = data.activeTodayCount;
          else this.setIncomingAppointmentCount(data, today);
        }
      } catch {
        // Non-fatal: the sidebar should stay usable even if the count cannot load.
      } finally {
        this._incomingCountRequest = null;
      }
    },

    async handleLogout() {
      try {
        await API.logout("admin");
      } finally {
        API.redirectToSignIn({ replace: true });
      }
    },

    async loadClinicStatus() {
      if (this._clinicStatusRequest) return this._clinicStatusRequest;
      try {
        this._clinicStatusRequest = API.getClinicStatus();
        const data = await this._clinicStatusRequest;
        if (this._destroyed) return;
        this.clinicStopped = Boolean(data.stopped_today);
        this.clinicStatusLoaded = true;
        window.dispatchEvent(new CustomEvent('admin-clinic:status', { detail: data }));
      } catch {
        // Non-fatal
      } finally {
        this._clinicStatusRequest = null;
      }
    },

    async openBlockedDatesModal() {
      if (!this.isAdmin) return;

      this.blockDatesModal.open = true;
      this.clearBlockedDateForm();
      await this.loadBlockedDates();
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

    async loadBlockedDates() {
      const request = ++this._blockedDatesRequest;
      this.blockedDatesLoading = true;
      this.blockedDatesError = "";
      try {
        const data = await API.getBlockedDates();
        if (request !== this._blockedDatesRequest) return;
        this.blockedDates = data.blocked_dates || [];
        this.blockedDatesLoaded = true;
      } catch {
        if (request === this._blockedDatesRequest) this.blockedDatesError = "Could not load blocked dates.";
      } finally {
        if (request === this._blockedDatesRequest) this.blockedDatesLoading = false;
      }
    },

    async submitBlockedDate() {
      if (!this.isAdmin) return;

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
        if (res.conflict_warning) {
          this.blockForm.warning = res.conflict_warning;
        }
        this.blockForm.startDate = "";
        this.blockForm.endDate   = "";
        this.blockForm.reason    = "";
        await this.loadBlockedDates();
      } catch (err) {
        this.blockForm.error = err.message || "Failed to block dates. Please try again.";
      } finally {
        this.blockForm.busy = false;
      }
    },

    async removeBlockedDate(id) {
      if (!this.isAdmin) return;

      try {
        await API.removeBlockedDate(id);
        this.blockedDates = this.blockedDates.filter(b => b.id !== id);
      } catch (err) {
        alert(err.message || "Failed to remove blocked date.");
      }
    },

    handleStopReceivingClick() {
      if (!this.isAdmin || !this.clinicStatusLoaded) return;

      if (this.clinicStopped) {
        this.stopModal = {
          open: true,
          title: "Reopen for Today?",
          message: "This will allow Clinic and Grooming walk-ins and check-ins for the rest of the day.",
          confirmLabel: "Yes, Reopen",
          action: "reopen",
          busy: false,
          error: "",
        };
      } else {
        this.stopModal = {
          open: true,
          title: "Stop Receiving for Today?",
          message: "This stops physical intake for Clinic and Grooming today. Unused pre-registrations expire after their selected date ends. You can reopen intake today.",
          confirmLabel: "Yes, Stop",
          action: "stop",
          busy: false,
          error: "",
        };
      }
    },

    async executeStopReceiving() {
      if (!this.isAdmin) return;

      const action = this.stopModal.action;
      this.stopModal.busy = true;
      this.stopModal.error = "";

      try {
        if (action === "stop") {
          await API.adminStopToday();
          this.clinicStopped = true;
        } else {
          await API.adminReopenToday();
          this.clinicStopped = false;
        }
        this.stopModal.open = false;
      } catch (err) {
        this.stopModal.error = err.message || "Something went wrong. Please try again.";
      } finally {
        this.stopModal.busy = false;
      }
    },
  };
}
