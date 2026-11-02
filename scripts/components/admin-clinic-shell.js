function adminClinicShell() {
  return {
    activeSection: 'cases', sectionLoading: false, sectionError: '',
    _navigationListener: null,
    init() {
      this._navigationListener = () => this.showSection();
      window.addEventListener('hashchange', this._navigationListener);
      this.$nextTick(() => this.showSection());
    },
    destroy() { window.removeEventListener('hashchange', this._navigationListener); },
    async showSection() {
      if (window.location.hash === '#services') {
        window.location.replace('/admin/services#clinic');
        return;
      }
      if (window.location.hash !== '#cases') history.replaceState(null, '', '#cases');
      try {
        await this.$store.clinicAccess.init();
        await Alpine.$data(this.$refs.cases.firstElementChild).activate();
      } catch (error) { this.sectionError = error.message || 'Unable to load Clinic cases.'; }
    },
  };
}

// Keep Cases in the existing file. Initialize its data only on the first visit.
function adminClinicCasesSection() {
  const component = adminClinicPage();
  const initialize = component.init;
  return Object.assign(component, {
    _initialized: false,
    init() {},
    async activate() {
      if (!this._initialized) {
        this._initialized = true;
        await initialize.call(this);
      }
    },
  });
}
