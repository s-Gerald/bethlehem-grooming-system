// Shared by sidebar context and notification navigation, including nested routes.
const adminManagementBase = new URL('../../pages/admin/', document.currentScript?.src || new URL('/scripts/components/admin-sidebar.js', window.location.origin));
const adminManagementPages = new Map([
  ['dashboard.html', 'dashboard'], ['clients.html', 'customers'],
  ['appointments.html', 'appointments'], ['clinic.html', 'clinic'],
  ['inventory/inventory.html', 'inventory'], ['archive.html', 'archive'],
  ['transactions.html', 'transactions'], ['reports.html', 'reports'],
  ['services.html', 'services'], ['settings.html', 'settings'],
  ['availability.html', 'availability'], ['activity-log.html', 'activity-log'],
]);

function validateAdminNotificationOrigin(value) {
  if (!value || /^(?:\/\/|.*\\)/.test(value)) return null;
  try {
    const url = new URL(value, adminManagementBase);
    if (url.origin !== adminManagementBase.origin || url.username || url.password) return null;
    let route = [...adminManagementPages.keys()].find(path => new URL(path, adminManagementBase).pathname === url.pathname);
    for (const page of ['availability', 'activity-log']) {
      if (url.pathname === new URL(`../../api/admin/${page}/page`, adminManagementBase).pathname) route = `${page}.html`;
    }
    if (!route) return null;
    const page = adminManagementPages.get(route);
    const requestedSection = url.hash.slice(1).split('?')[0];
    const section = page === 'inventory' ? adminInventoryRoutes.includes(requestedSection) ? requestedSection : 'overview' : '';
    return {href: url.pathname + url.search + url.hash, page, section};
  } catch { return null; }
}

function getAdminNotificationOrigin(search = window.location.search) {
  return validateAdminNotificationOrigin(new URLSearchParams(search).get('return_to'));
}

function getAdminNotificationsHref(id) {
  const url = new URL('notifications.html', adminManagementBase);
  const {pathname, search = '', hash = ''} = window.location;
  const origin = validateAdminNotificationOrigin(pathname + search + hash);
  if (origin) url.searchParams.set('return_to', origin.href);
  if (id != null) url.searchParams.set('selected_id', String(id));
  return url.pathname + url.search;
}

function getAdminSidebarActivePage(pathname = window.location.pathname, search = window.location.search) {
  const normalizedPath = String(pathname || "")
    .replace(/\\/g, "/")
    .toLowerCase();

  if (
    normalizedPath.includes("/inventory/") ||
    normalizedPath.endsWith("/inventory.html")
  ) {
    return "inventory";
  }

  if (normalizedPath.endsWith('/api/admin/availability/page')) return 'availability';
  if (normalizedPath.endsWith('/api/admin/activity-log/page')) return 'activity-log';
  const pageName = normalizedPath.split("/").filter(Boolean).pop() || "";
  if (pageName === 'notifications.html') {
    return getAdminNotificationOrigin(search)?.page || '';
  }
  return adminManagementPages.get(pageName) || "";
}

const adminInventoryLinks = { overview: "Overview", products: "Products", "sell-product": "Point of Sale", history: "Stock Movements" };
const adminInventoryRoutes = ["overview", "products", "sell-product", "stock-in", "stock-out", "history"];

function getAdminInventorySection() {
  if (window.location.pathname.endsWith('/notifications.html')) return getAdminNotificationOrigin()?.section || '';
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
  button.setAttribute(":class", "activePage === 'inventory' ? 'bg-[#315b7e] text-white shadow-sm' : 'text-[#2f4b66]'");
  button.setAttribute("@click", "inventoryExpanded = activePage === 'inventory' || !inventoryExpanded");
  button.setAttribute(":aria-expanded", "inventoryExpanded");
  button.setAttribute("aria-expanded", String(getAdminSidebarActivePage() === 'inventory'));
  button.setAttribute("aria-controls", "inventory-submenu");
  button.innerHTML = `${link.innerHTML}<span class="ml-auto text-xs" aria-hidden="true" x-text="inventoryExpanded ? '▾' : '▸'">${getAdminSidebarActivePage() === 'inventory' ? '▾' : '▸'}</span>`;
  const submenu = document.createElement("div");
  submenu.id = "inventory-submenu";
  submenu.setAttribute("x-show", "inventoryExpanded");
  submenu.setAttribute("x-cloak", "");
  submenu.className = "admin-inventory-submenu";
  for (const [section, label] of Object.entries(adminInventoryLinks)) {
    const child = document.createElement("a");
    child.href = `${url.pathname}#${section}`;
    if (section === 'sell-product') {
      child.setAttribute('x-show', '!clinicOnly');
      child.setAttribute('data-admin-permission', '');
      child.style.display = 'none';
    }
    child.textContent = label;
    child.setAttribute(":class", `activePage === 'inventory' && inventorySection === '${section}' ? 'is-context-selected' : ''`);
    child.setAttribute(":aria-current", window.location.pathname.endsWith('/notifications.html') ? 'null' : `activePage === 'inventory' && inventorySection === '${section}' ? 'page' : null`);
    child.setAttribute("@click", "sidebarOpen = false");
    submenu.appendChild(child);
  }
  group.append(button, submenu);
  link.replaceWith(group);
}

function installAdminServicesNavigation() {
  const clinic = document.querySelector('.admin-sidebar-menu a[href$="clinic.html"]');
  if (!clinic) return;
  const services = document.createElement('a');
  services.href = new URL('services.html', clinic.href).pathname;
  services.className = clinic.className;
  services.setAttribute(':class', "activePage === 'services' ? 'bg-[#315b7e] text-white shadow-sm' : 'text-[#2f4b66]'");
  services.setAttribute('x-show', 'canViewServices');
  services.setAttribute('data-admin-permission', '');
  services.style.display = 'none';
  services.setAttribute('@click', 'sidebarOpen = false');
  services.innerHTML = `<svg class="ph-icon h-5 w-5" aria-hidden="true" viewBox="0 0 256 256" fill="currentColor" focusable="false"><use href="${new URL('../../assets/icons/phosphor.svg?v=admin-icons-20261018', document.currentScript.src).href}#tag"></use></svg><span>Services</span>`;
  clinic.after(services);
}

function installAdminActivityNavigation() {
  const settings = document.querySelector('.admin-sidebar-menu a[href$="settings.html"]');
  if (!settings) return;
  const link = settings.cloneNode(false);
  link.href = new URL('activity-log.html', settings.href).href;
  link.setAttribute('x-show', 'isAdmin');
  link.setAttribute('data-admin-permission', '');
  link.style.display = 'none';
  link.setAttribute('@click', 'sidebarOpen = false');
  link.innerHTML = `<svg class="ph-icon h-5 w-5" aria-hidden="true" viewBox="0 0 256 256" fill="currentColor"><use href="${new URL('../../assets/icons/phosphor.svg?v=admin-icons-20261018', document.currentScript.src).href}#clock-counter-clockwise"></use></svg><span>Activity Log</span>`;
  settings.before(link);
}

function installAdminAvailabilityNavigation() {
  const settings = document.querySelector('.admin-sidebar-menu a[href$="settings.html"]');
  if (!settings) return;
  const link = settings.cloneNode(false);
  link.href = new URL('availability.html', settings.href).href;
  link.setAttribute('x-show', 'canViewAvailability');
  link.setAttribute('data-admin-permission', '');
  link.style.display = 'none';
  link.setAttribute('@click', 'sidebarOpen = false');
  link.innerHTML = `<svg class="ph-icon h-5 w-5" aria-hidden="true" viewBox="0 0 256 256" fill="currentColor"><use href="${new URL('../../assets/icons/phosphor.svg?v=admin-icons-20261018', document.currentScript.src).href}#clock"></use></svg><span>Availability</span>`;
  settings.before(link);
}

function getAdminSidebarIdentityState(user) {
  if (!user) return {
    sidebarReady: false, isAdmin: false, canViewAvailability: false, clinicPermissions: {}, canAccessGrooming: false, clinicOnly: false, canViewServices: false,
    staffDisplayName: '', staffInitials: '', staffRoleLabel: '',
  };
  const first = String(user.first_name || '').trim(), last = String(user.last_name || '').trim();
  return {
    sidebarReady: true,
    isAdmin: user.role === 'admin',
    canViewAvailability: user.role === 'admin' || user.staff_permissions?.availability_view === true,
    clinicOnly: user.staff_permissions?.clinic_workspace === true,
    canViewServices: user.role === 'admin' || (!!user.clinic_permissions?.services_view && !user.staff_permissions?.clinic_workspace),
    clinicPermissions: user.clinic_permissions || {},
    canAccessGrooming: user.role === 'admin' || !!user.staff_permissions?.grooming,
    staffDisplayName: `${first} ${last}`.trim() || 'Staff',
    staffInitials: `${first[0] || ''}${last[0] || ''}`.toUpperCase() || 'ST',
    staffRoleLabel: user.staff_type === 'clinic'
      ? ({ veterinarian: 'Veterinarian', clinic_receptionist: 'Clinic Receptionist' }[user.staff_subrole] || 'Clinic Staff')
      : user.staff_subrole === 'grooming_receptionist' ? 'Grooming Receptionist' : 'Staff',
  };
}

// Paint the shell and verified identity before Alpine or unrelated API requests finish.
function renderCachedAdminSidebar() {
  const user = window.API?.getCachedStaffIdentity?.()?.user;
  const aside = document.querySelector('.admin-sidebar-menu');
  if (!aside) return;
  const state = getAdminSidebarIdentityState(user), activePage = getAdminSidebarActivePage();
  const visible = {
    isAdmin: state.isAdmin,
    'isAdmin || clinicPermissions.access': state.isAdmin || !!state.clinicPermissions.access,
    'isAdmin || canAccessGrooming': state.canAccessGrooming,
    canViewServices: state.canViewServices,
    canViewAvailability: state.canViewAvailability,
    '!clinicOnly': state.sidebarReady && !state.clinicOnly,
  };
  aside.querySelectorAll('[x-show]').forEach(el => {
    const condition = el.getAttribute('x-show');
    if (Object.hasOwn(visible, condition)) el.style.display = visible[condition] ? '' : 'none';
  });
  aside.querySelector('[data-admin-account]')?.setAttribute('aria-busy', String(!state.sidebarReady));
  const text = {
    "isAdmin ? 'AD' : staffInitials": state.isAdmin ? 'AD' : state.staffInitials,
    "isAdmin ? 'Admin' : staffDisplayName": state.isAdmin ? 'Admin' : state.staffDisplayName,
    "isAdmin ? 'Clinic Manager' : staffRoleLabel": state.isAdmin ? 'Clinic Manager' : state.staffRoleLabel,
  };
  aside.querySelectorAll('[x-text]').forEach(el => {
    const value = text[el.getAttribute('x-text')];
    if (value !== undefined) el.textContent = value;
  });
  aside.querySelectorAll('nav a, nav button').forEach(el => {
    const selected = el.getAttribute(':class')?.includes(`activePage === '${activePage}'`);
    if (el.closest('.admin-inventory-submenu')) return;
    el.classList.toggle('text-[#2f4b66]', !selected);
    for (const name of ['bg-[#315b7e]', 'text-white', 'shadow-sm']) el.classList.toggle(name, !!selected);
    if (selected && el.tagName === 'A' && !window.location.pathname.endsWith('/notifications.html')) el.setAttribute('aria-current', 'page');
    else el.removeAttribute('aria-current');
  });
  if (activePage === 'inventory') {
    const submenu = aside.querySelector('#inventory-submenu');
    const section = getAdminInventorySection();
    submenu?.removeAttribute('x-cloak');
    submenu?.style.removeProperty('display');
    submenu?.querySelectorAll('a').forEach(link => {
      const selected = new URL(link.href).hash === `#${section}`;
      link.classList.toggle('is-context-selected', selected);
      if (selected && !window.location.pathname.endsWith('/notifications.html')) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
  }
  if (state.sidebarReady) {
    aside.setAttribute('data-admin-navigation-ready', '');
    aside.closest('[data-admin-shell]')?.setAttribute('data-admin-ready', '');
  } else {
    aside.removeAttribute('data-admin-navigation-ready');
    aside.closest('[data-admin-shell]')?.removeAttribute('data-admin-ready');
  }
}

// Build the shared group before Alpine walks the sidebar. Replacing a live
// Alpine link can leave its queued effects detached from their parent scope.
if (typeof document !== "undefined" && document.querySelector) {
  installAdminInventoryNavigation();
  installAdminServicesNavigation();
  installAdminActivityNavigation();
  installAdminAvailabilityNavigation();
  document.querySelectorAll('.admin-sidebar-menu nav a').forEach(link => {
    link.setAttribute('@click', 'sidebarOpen = false');
    if (link.closest('.admin-inventory-submenu')) return;
    const page = getAdminSidebarActivePage(new URL(link.href).pathname);
    link.setAttribute(':class', `activePage === '${page}' ? 'bg-[#315b7e] text-white shadow-sm' : 'text-[#2f4b66]'`);
    link.setAttribute(':aria-current', !window.location.pathname.endsWith('/notifications.html')
      && (window.location.pathname === new URL(link.href).pathname || ['activity-log', 'availability'].includes(page) && getAdminSidebarActivePage() === page)
      ? `activePage === '${page}' ? 'page' : null` : 'null');
  });
  renderCachedAdminSidebar();
}

function adminSidebar() {
  return {
    sidebarOpen: false,
    sidebarError: '',
    activePage: getAdminSidebarActivePage(),
    inventoryExpanded: getAdminSidebarActivePage() === "inventory",
    inventorySection: getAdminInventorySection(),
    _inventoryNavigationListener: null,
    incomingAppointmentCount: 0,
    _incomingAppointmentRevision: 0,
    _incomingAppointmentInterval: null,
    _incomingAppointmentListener: null,
    _visibilityListener: null,
    _sidebarSessionListener: null,
    _incomingCountRequest: null,
    _identityRevision: 0,
    _destroyed: false,
    ...getAdminSidebarIdentityState(window.API?.getCachedStaffIdentity?.()?.user),
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
        this._identityRevision++;
        this._incomingAppointmentRevision++;
        Object.assign(this, getAdminSidebarIdentityState(null));
        document.documentElement?.removeAttribute('data-admin-role');
        this.sidebarError = '';
        this.incomingAppointmentCount = 0;
        renderCachedAdminSidebar();
        this.$root?.removeAttribute('data-admin-ready');
        this.$root?.querySelector('.admin-sidebar-menu')?.removeAttribute('data-admin-navigation-ready');
        if (event.detail?.reload) void this.loadLoggedInIdentity();
      };
      window.addEventListener('admin-navigation:invalidated', this._navigationInvalidationListener);
      this._navigationUpdateListener = (event) => {
        if (this._destroyed || !event.detail?.user) return;
        this._identityRevision++;
        Object.assign(this, getAdminSidebarIdentityState(event.detail.user));
        document.documentElement?.setAttribute('data-admin-role', this.isAdmin ? 'admin' : 'staff');
        this.sidebarError = '';
        renderCachedAdminSidebar();
      };
      window.addEventListener('admin-navigation:updated', this._navigationUpdateListener);

      // Notification origins are presentation context, never ownership of workspace data.
      const ownsGroomingData = window.location.pathname.endsWith('/notifications.html')
        || ['dashboard', 'appointments'].includes(this.activePage);
      await this.loadLoggedInIdentity();
      if (!ownsGroomingData && this.canAccessGrooming) await this.loadIncomingAppointmentCount();
      if (this._destroyed) return;
      const refresh = () => {
        if (document.visibilityState === 'hidden' || API.isSessionLocked?.() || this._destroyed) return;
        if (!ownsGroomingData && this.canAccessGrooming) void this.loadIncomingAppointmentCount();
      };
      this._incomingAppointmentInterval = setInterval(refresh, 30000);
      this._visibilityListener = () => { if (document.visibilityState !== 'hidden') refresh(); };
      document.addEventListener('visibilitychange', this._visibilityListener);
      this._sidebarSessionListener = () => {
        void this.loadLoggedInIdentity();
        Promise.allSettled([this._incomingCountRequest]).then(refresh);
      };
      window.addEventListener('admin-session:unlocked', this._sidebarSessionListener);
    },

    destroy() {
      this._destroyed = true;
      this._incomingAppointmentRevision++;
      document.removeEventListener?.('visibilitychange', this._visibilityListener);
      window.removeEventListener('admin-session:unlocked', this._sidebarSessionListener);
      window.removeEventListener("hashchange", this._inventoryNavigationListener);
      window.removeEventListener('admin-navigation:invalidated', this._navigationInvalidationListener);
      window.removeEventListener('admin-navigation:updated', this._navigationUpdateListener);
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
      const revision = ++this._identityRevision;
      this.sidebarError = '';
      try {
        const response = await (API.getStaffIdentity ? API.getStaffIdentity() : API.getMe("staff"));
        const user = response?.user;
        if (!user || this._destroyed || revision !== this._identityRevision) return;
        Object.assign(this, getAdminSidebarIdentityState(user));
        API.enforceAdminPageAccess?.();
        this.$nextTick?.(() => {
          if (this._destroyed || revision !== this._identityRevision) return;
          this.$root?.setAttribute('data-admin-ready', '');
          this.$root?.querySelector('.admin-sidebar-menu')?.setAttribute('data-admin-navigation-ready', '');
          document.documentElement?.setAttribute('data-admin-role', this.isAdmin ? 'admin' : 'staff');
        });
      } catch {
        if (!this._destroyed && revision === this._identityRevision) this.sidebarError = 'Could not verify access.';
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
      return new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit",
      }).format(new Date());
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

  };
}
