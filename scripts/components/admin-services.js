function adminServices() {
  const pending = {};
  const scripts = new Map();
  function loadScript(name) {
    if (!scripts.has(name)) scripts.set(name, new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = `/scripts/components/${name}?urls=20261103&v=${name === 'admin-grooming-services.js' ? 'grooming-time-20261105' : 'scoped-fetching-20261019'}`;
      script.onload = resolve;
      script.onerror = () => { scripts.delete(name); script.remove(); reject(new Error('Unable to load Services. Please retry.')); };
      document.head.appendChild(script);
    }));
    return scripts.get(name);
  }
  return {
    activeTab: 'grooming', loading: true, error: '',
    isAdmin: false, canViewClinic: false, canViewGrooming: false, identityReady: false,
    defaultTab: 'clinic', _identityKey: null, _accessRevision: 0, _navigationRevision: 0,
    _navigationListener: null,
    get canSwitchCatalogs() { return this.identityReady && this.canViewClinic && this.canViewGrooming; },
    get pageTitle() { return this.identityReady && this.canViewClinic && !this.canViewGrooming ? 'Clinic Services' : 'Services'; },
    async init() {
      this.applyIdentity(API.getCachedStaffIdentity?.()?.user);
      this.loadIdentity();
      this._accessInvalidated = event => {
        this.applyIdentity(null);
        this.$store.clinicAccess.user = null;
        this.$store.clinicAccess.permissions = {};
        this.error = 'Could not verify access.';
        if (event.detail?.reload) {
          this.$store.clinicAccess.ready = null;
          this.loadIdentity();
          void this._clinicAccess.then(() => this.showTab());
        }
      };
      this._identityUpdated = event => {
        if (!event.detail?.user) return;
        this.$store.clinicAccess.user = event.detail.user;
        this.$store.clinicAccess.permissions = event.detail.user.clinic_permissions || {};
        this.applyIdentity(event.detail.user);
        this._clinicAccess = Promise.resolve();
        void this.showTab();
      };
      window.addEventListener('admin-navigation:invalidated', this._accessInvalidated);
      window.addEventListener('admin-navigation:updated', this._identityUpdated);
      this._navigationListener = () => this.showTab();
      this._pageShowListener = event => {
        if (!event.persisted) return;
        const user = API.getCachedStaffIdentity?.()?.user || null;
        this.$store.clinicAccess.user = user;
        this.$store.clinicAccess.permissions = user?.clinic_permissions || {};
        this.applyIdentity(user);
        this.$store.clinicAccess.ready = null;
        this.loadIdentity();
        return this._clinicAccess.then(() => this.showTab());
      };
      window.addEventListener('hashchange', this._navigationListener);
      window.addEventListener('pageshow', this._pageShowListener);
      await this.showTab();
    },
    destroy() {
      this._accessRevision++;
      this._navigationRevision++;
      window.removeEventListener('hashchange', this._navigationListener);
      window.removeEventListener('pageshow', this._pageShowListener);
      window.removeEventListener('admin-navigation:invalidated', this._accessInvalidated);
      window.removeEventListener('admin-navigation:updated', this._identityUpdated);
    },
    loadIdentity() {
      const revision = this._accessRevision;
      this._clinicAccess = this.$store.clinicAccess.init().then(() => {
        if (revision === this._accessRevision) this.applyIdentity(this.$store.clinicAccess.user);
      });
    },
    applyIdentity(user) {
      const clinic = !!user?.clinic_permissions?.services_view;
      const grooming = user?.role === 'admin' || (!!user?.staff_permissions?.grooming_services_view && !user?.staff_permissions?.clinic_workspace);
      const key = user ? JSON.stringify([user.user_id, user.role, user.staff_subrole, clinic, grooming, user.clinic_permissions?.services_manage]) : null;
      if (key !== this._identityKey) {
        this._identityKey = key;
        this._accessRevision++;
        this._navigationRevision++;
        this.loading = true;
        this.resetPanels();
      }
      this.identityReady = !!user;
      this.isAdmin = user?.role === 'admin';
      this.canViewClinic = clinic;
      this.canViewGrooming = grooming;
      this.defaultTab = this.isAdmin || user?.staff_subrole === 'grooming_receptionist' ? 'grooming' : 'clinic';
    },
    resetPanels() {
      for (const tab of ['grooming', 'clinic']) {
        delete pending[tab];
        const panel = this.$refs[tab]?.firstElementChild;
        if (!panel) continue;
        this.closePanel(tab);
        Alpine.mutateDom(() => { Alpine.destroyTree(panel); this.$refs[tab].replaceChildren(); });
      }
    },
    requestedTab() {
      if (this.canViewClinic && !this.canViewGrooming) return 'clinic';
      return ['#grooming', '#clinic'].includes(window.location.hash) ? window.location.hash.slice(1) : this.defaultTab;
    },
    closePanel(tab) {
      const panel = this.$refs[tab]?.firstElementChild;
      if (!panel) return;
      const state = Alpine.$data(panel);
      state.closeModal?.(true);
      state.closeDetails?.(false);
      state.closeEditor?.();
    },
    selectTab(tab) {
      if (tab === 'grooming' ? !this.canViewGrooming : tab !== 'clinic' || !this.canViewClinic) return;
      if (this.activeTab === tab && !this.error) return;
      window.location.hash = tab;
    },
    async mount(tab) {
      if (!(tab === 'clinic' ? this.canViewClinic : tab === 'grooming' && this.canViewGrooming)) return null;
      const host = this.$refs[tab];
      if (host.firstElementChild) return host.firstElementChild;
      if (pending[tab]) return pending[tab];
      const revision = this._accessRevision;
      const request = pending[tab] = (async () => {
        const [response] = await Promise.all([
          fetch(tab === 'clinic' ? '/pages/admin/clinic/sections/services.html?urls=20261103&v=services-20261017&icons=20261018' : '/pages/admin/services/sections/grooming.html?urls=20261103&v=grooming-time-20261105&icons=20261018&staff-access=20261024'),
          loadScript(tab === 'clinic' ? 'admin-clinic-services.js' : 'admin-grooming-services.js'),
          ...(tab === 'clinic' ? [loadScript('inventory-money-input.js')] : []),
        ]);
        if (!response.ok) throw new Error('Unable to load Services. Please retry.');
        const markup = await response.text();
        if (revision !== this._accessRevision || !(tab === 'clinic' ? this.canViewClinic : this.canViewGrooming)) return null;
        const panel = document.createElement('section');
        panel.setAttribute('x-data', tab === 'clinic' ? 'adminClinicServices()' : 'adminGroomingServices()');
        panel.innerHTML = markup;
        // The Services page provides the shared heading and tabs.
        if (tab === 'clinic') panel.querySelector('header')?.remove();
        Alpine.mutateDom(() => { host.appendChild(panel); Alpine.initTree(panel); });
        return panel;
      })().finally(() => { if (pending[tab] === request) delete pending[tab]; });
      return request;
    },
    async showTab() {
      const access = this._clinicAccess;
      await access;
      if (access !== this._clinicAccess) return;
      const navigation = ++this._navigationRevision;
      if (!this.identityReady) { this.loading = false; this.error = 'Could not verify access.'; return; }
      const tab = this.requestedTab();
      if (tab === 'clinic' && !this.canViewClinic) { this.error = 'You do not have access to Clinic Services.'; return; }
      if (tab === 'grooming' && !this.canViewGrooming) { this.error = 'You do not have access to Grooming Services.'; return; }
      if (tab !== this.activeTab) {
        this.closePanel(this.activeTab);
      }
      this.activeTab = tab;
      if (window.location.hash !== `#${tab}`) history.replaceState(null, '', `#${tab}`);
      this.error = '';
      const existing = this.$refs[tab].firstElementChild;
      this.loading = !existing || !Alpine.$data(existing).loaded;
      try {
        const panel = await this.mount(tab);
        if (!panel || navigation !== this._navigationRevision) return;
        const state = Alpine.$data(panel);
        state._onPresentation = () => { if (navigation === this._navigationRevision) this.loading = false; };
        await state.activate();
      } catch (error) { if (navigation === this._navigationRevision) this.error = error.message || 'Unable to load Services.'; }
      finally { if (navigation === this._navigationRevision) this.loading = false; }
    },
    tabKeydown(event) {
      if (!this.canSwitchCatalogs) return;
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      const tabs = [...this.$refs.tabs.querySelectorAll('[role="tab"]')].filter(tab => tab.offsetParent !== null);
      if (!tabs.length) return;
      event.preventDefault();
      const index = tabs.indexOf(event.target);
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
        : (index + (event.key === 'ArrowLeft' ? -1 : 1) + tabs.length) % tabs.length;
      tabs[next].focus(); tabs[next].click();
    },
  };
}
