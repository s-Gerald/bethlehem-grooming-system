function adminServices() {
  const pending = {};
  return {
    activeTab: 'grooming', loading: true, error: '',
    isAdmin: API.getUserRole() === 'admin', canViewClinic: false,
    _navigationListener: null,
    async init() {
      await this.$store.clinicAccess.init();
      this.canViewClinic = !!this.$store.clinicAccess.permissions.services_view;
      this._navigationListener = () => this.showTab();
      window.addEventListener('hashchange', this._navigationListener);
      await this.showTab();
    },
    destroy() { window.removeEventListener('hashchange', this._navigationListener); },
    selectTab(tab) {
      if (this.activeTab === tab && !this.error) return;
      window.location.hash = tab;
    },
    async mount(tab) {
      const host = this.$refs[tab];
      if (host.firstElementChild) return host.firstElementChild;
      return pending[tab] ??= (async () => {
        const response = await fetch(tab === 'clinic' ? './clinic/sections/services.html?v=services-20261017&icons=20261018' : './services/sections/grooming.html?v=services-20261017-followup&icons=20261018');
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
      const tab = window.location.hash === '#clinic' || !this.isAdmin ? 'clinic' : 'grooming';
      if (tab === 'clinic' && !this.canViewClinic) { this.error = 'You do not have access to Clinic Services.'; return; }
      if (tab !== this.activeTab) {
        const oldPanel = this.$refs[this.activeTab].firstElementChild;
        if (oldPanel) {
          const oldState = Alpine.$data(oldPanel);
          oldState.closeModal?.(true);
          oldState.closeDetails?.(false);
          oldState.closeEditor?.();
        }
      }
      this.activeTab = tab;
      if (window.location.hash !== `#${tab}`) history.replaceState(null, '', `#${tab}`);
      this.error = '';
      const existing = this.$refs[tab].firstElementChild;
      this.loading = !existing || !Alpine.$data(existing).loaded;
      try {
        const panel = await this.mount(tab);
        if (this.activeTab !== tab) return;
        await Alpine.$data(panel).activate();
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
