function adminActivityLog() {
  return {
    rows: [], staff: [], typeGroups: [], loading: true, loaded: false, error: '', optionsError: '',
    page: 1, lastPage: 1, total: 0, filters: {},
    drawerOpen: false, detail: null, detailLoading: false, detailError: '',
    _request: 0, _optionsRequest: 0, _detailRequest: 0, _trigger: null, _destroyed: false, _keyboardInteraction: true,
    init() {
      this.resetFilters();
      this._sessionListener = () => { this._request++; this._optionsRequest++; this.rows = []; this.staff = []; this.typeGroups = []; this.closeDetails(); this.loaded = false; this._needsReload = true; };
      this._resumeListener = () => { if (this._needsReload) { this._needsReload = false; this.load(); this.loadOptions(); } };
      window.addEventListener('admin-session:locked', this._sessionListener);
      window.addEventListener('admin-navigation:invalidated', this._sessionListener);
      window.addEventListener('admin-session:unlocked', this._resumeListener);
      window.addEventListener('admin-navigation:updated', this._resumeListener);
      this.load();
      this.loadOptions();
    },
    loadOptions() {
      this.optionsError = '';
      const request = ++this._optionsRequest;
      return API.getAdminActivityOptions().then(data => {
        if (!this._destroyed && request === this._optionsRequest) { this.staff = data.staff; this.typeGroups = data.type_groups; }
      }).catch(() => { if (!this._destroyed && request === this._optionsRequest) this.optionsError = "Couldn't load filters."; });
    },
    destroy() {
      this._destroyed = true; this._request++; this._optionsRequest++; this.closeDetails();
      window.removeEventListener('admin-session:locked', this._sessionListener);
      window.removeEventListener('admin-navigation:invalidated', this._sessionListener);
      window.removeEventListener('admin-session:unlocked', this._resumeListener);
      window.removeEventListener('admin-navigation:updated', this._resumeListener);
    },
    resetFilters() {
      const now = new Date();
      const date = value => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(value);
      this.filters = {search: '', area: 'all', from: date(new Date(now.getTime() - 29 * 86400000)), to: date(now), staff: '', type: ''};
      this.page = 1;
    },
    clearFilters() { this.resetFilters(); return this.load(); },
    changeFilters() { this.page = 1; return this.load(); },
    async load(page = this.page) {
      const request = ++this._request;
      this.loading = true; this.error = '';
      try {
        const data = await API.getAdminActivityLog({...this.filters, page});
        if (this._destroyed || request !== this._request) return;
        this.rows = data.data; this.page = data.current_page; this.lastPage = data.last_page; this.total = data.total; this.loaded = true;
      } catch {
        if (!this._destroyed && request === this._request) this.error = "Couldn't load activity.";
      } finally {
        if (!this._destroyed && request === this._request) this.loading = false;
      }
    },
    setInteractionMode(keyboard) {
      this._keyboardInteraction = keyboard;
      if (keyboard) this.$root.querySelectorAll('[data-pointer-focus]').forEach(element => element.removeAttribute('data-pointer-focus'));
    },
    focusElement(element) {
      if (this._keyboardInteraction) element.removeAttribute('data-pointer-focus');
      else element.setAttribute('data-pointer-focus', 'true');
      element.focus({preventScroll: true});
    },
    async openDetails(row, trigger, event) {
      if (event) this.setInteractionMode(event.detail === 0 && !event.pointerType);
      const request = ++this._detailRequest;
      this._trigger = trigger; this.detail = null; this.detailError = ''; this.detailLoading = true; this.drawerOpen = true;
      this.$nextTick(() => {
        if (!this.drawerOpen || this._destroyed || request !== this._detailRequest) return;
        this.focusElement(this.$refs.drawerClose);
        this.$refs.content.inert = true;
        const shell = this.$root.closest('[data-admin-shell]');
        this._inertRegions = shell ? [...shell.querySelectorAll('.admin-sidebar-menu, header')].filter(region => !this.$refs.drawer.contains(region)) : [];
        this._inertRegions.forEach(region => { region.inert = true; });
      });
      try {
        const data = await API.getAdminActivityDetails(row.id);
        if (!this._destroyed && request === this._detailRequest) this.detail = data;
      } catch {
        if (!this._destroyed && request === this._detailRequest) this.detailError = "Couldn't load activity.";
      } finally {
        if (!this._destroyed && request === this._detailRequest) this.detailLoading = false;
      }
    },
    closeDetails() {
      this._detailRequest++; this.drawerOpen = false; this.detail = null;
      if (this.$refs?.content) this.$refs.content.inert = false;
      this._inertRegions?.forEach(region => { region.inert = false; });
      if (!this._destroyed && this._trigger?.isConnected) this.focusElement(this._trigger);
    },
    trapFocus(event) {
      if (event.key !== 'Tab') return;
      const items = [...this.$refs.drawer.querySelectorAll('button, a[href], input, select, [tabindex="0"]')].filter(el => !el.disabled && el.getClientRects().length);
      const first = items[0], last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    },
    formatDate(value, long = false) {
      if (!value) return '';
      return new Date(value).toLocaleString('en-PH', {timeZone: 'Asia/Manila', year: 'numeric', month: long ? 'long' : 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'});
    },
    changeLabel(key) {
      return ({booking_date: 'Visit day', appointment_date: 'Visit day', quantity_on_hand: 'Quantity',
        price_at_booking: 'Service price', price_min_at_booking: 'Minimum service price', price_max_at_booking: 'Maximum service price',
        is_active: 'Active', is_archived: 'Archived', staff_type: 'Department', staff_subrole: 'Role'})[key]
        || key.replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase());
    },
    changeValue(value, field) {
      if (value === null || value === undefined || value === '') return '—';
      if (typeof value === 'boolean') return value ? 'Yes' : 'No';
      if (['status', 'grooming_state', 'payment_status'].includes(field) && typeof value === 'string') {
        return value.replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase());
      }
      return typeof value === 'object' ? JSON.stringify(value) : String(value).replaceAll('_', ' ');
    },
  };
}
