function adminServices() {
  const pending = {};
  const scripts = new Map();
  function loadScript(name) {
    if (!scripts.has(name)) scripts.set(name, new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = `../../scripts/components/${name}?v=${name === 'admin-grooming-services.js' ? 'staff-access-20261024' : 'scoped-fetching-20261019'}`;
      script.onload = resolve;
      script.onerror = () => { scripts.delete(name); script.remove(); reject(new Error('Unable to load Services. Please retry.')); };
      document.head.appendChild(script);
    }));
    return scripts.get(name);
  }
  return {
    activeTab: 'grooming', loading: true, error: '',
    isAdmin: false, canViewClinic: false, canViewGrooming: false, identityReady: false,
    _navigationListener: null,
    async init() {
      this.applyIdentity(API.getCachedStaffIdentity?.()?.user);
      this._clinicAccess = this.$store.clinicAccess.init().then(() => {
        this.applyIdentity(this.$store.clinicAccess.user);
      });
      this._accessInvalidated = event => {
        this.closePanel('grooming');
        this.closePanel('clinic');
        this.applyIdentity(null);
        this.$store.clinicAccess.user = null;
        this.$store.clinicAccess.permissions = {};
        this.error = 'Could not verify access.';
        if (event.detail?.reload) {
          this.$store.clinicAccess.ready = null;
          this._clinicAccess = this.$store.clinicAccess.init().then(() => {
            this.applyIdentity(this.$store.clinicAccess.user);
          });
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
      window.addEventListener('hashchange', this._navigationListener);
      await this.showTab();
    },
    destroy() {
      window.removeEventListener('hashchange', this._navigationListener);
      window.removeEventListener('admin-navigation:invalidated', this._accessInvalidated);
      window.removeEventListener('admin-navigation:updated', this._identityUpdated);
    },
    applyIdentity(user) {
      this.identityReady = !!user;
      this.isAdmin = user?.role === 'admin';
      this.canViewClinic = !!user?.clinic_permissions?.services_view;
      this.canViewGrooming = this.isAdmin || !!user?.staff_permissions?.grooming_services_view;
    },
    requestedTab() {
      return ['#grooming', '#clinic'].includes(window.location.hash) ? window.location.hash.slice(1) : this.isAdmin ? 'grooming' : 'clinic';
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
      if (this.activeTab === tab && !this.error) return;
      window.location.hash = tab;
    },
    async mount(tab) {
      const host = this.$refs[tab];
      if (host.firstElementChild) return host.firstElementChild;
      return pending[tab] ??= (async () => {
        const [response] = await Promise.all([
          fetch(tab === 'clinic' ? './clinic/sections/services.html?v=services-20261017&icons=20261018' : './services/sections/grooming.html?v=services-20261017-followup&icons=20261018&staff-access=20261024'),
          loadScript(tab === 'clinic' ? 'admin-clinic-services.js' : 'admin-grooming-services.js'),
          ...(tab === 'clinic' ? [loadScript('inventory-money-input.js')] : []),
        ]);
        if (!response.ok) throw new Error('Unable to load Services. Please retry.');
        const panel = document.createElement('section');
        panel.setAttribute('x-data', tab === 'clinic' ? 'adminClinicServices()' : 'adminGroomingServices()');
        panel.innerHTML = await response.text();
        // The Services page provides the shared heading and tabs.
        if (tab === 'clinic') panel.querySelector('header')?.remove();
        Alpine.mutateDom(() => { host.appendChild(panel); Alpine.initTree(panel); });
        return panel;
      })().finally(() => { delete pending[tab]; });
    },
    async showTab() {
      if (!this.identityReady) await this._clinicAccess;
      const tab = this.requestedTab();
      if (tab === 'clinic') {
        await this._clinicAccess;
        if (this.requestedTab() !== tab) return;
      }
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
        if (this.activeTab !== tab) return;
        const state = Alpine.$data(panel);
        state._onPresentation = () => { if (this.activeTab === tab) this.loading = false; };
        await state.activate();
      } catch (error) { if (this.activeTab === tab) this.error = error.message || 'Unable to load Services.'; }
      finally { if (this.activeTab === tab) this.loading = false; }
    },
    tabKeydown(event) {
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
