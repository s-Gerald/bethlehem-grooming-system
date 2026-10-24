// The shell owns navigation and resource loading; each workflow keeps its own state.
const InventorySections = {
  overview: { script: "admin-inventory-dashboard.js", factory: "adminInventoryDashboard", dependencies: ["inventory-alerts.js"] },
  products: { script: "admin-inventory-items.js", factory: "adminInventoryItems", dependencies: ["product-form.js", "inventory-alerts.js", "success-toast.js"] },
  "sell-product": { script: "admin-pos.js", factory: "adminPos", dependencies: ["cash-payment.js", "online-payment.js", "product-form.js", "payment-invoice.js", "product-sale-invoice.js"] },
  "stock-in": { script: "admin-stock-in.js", factory: "adminStockIn", dependencies: ["product-form.js", "success-toast.js"] },
  "stock-out": { script: "admin-stock-out.js", factory: "adminStockOut", dependencies: ["product-form.js", "success-toast.js"] },
  history: { script: "admin-inventory-transactions.js", factory: "adminInventoryTransactions", dependencies: [] },
};

function inventoryRoute(hash = window.location.hash) {
  const [name, query = ""] = hash.replace(/^#/, "").split("?");
  const section = Object.hasOwn(InventorySections, name) ? name : "overview";
  const params = new URLSearchParams(query);
  const filter = ["low-stock", "expiry"].includes(params.get('filter')) ? params.get('filter') : '';
  const stock = ['stock-in', 'stock-out'].includes(section);
  const item = params.get('item') || '';
  return {
    section, filter: section === 'products' ? filter : '',
    itemId: stock && /^[1-9]\d{0,9}$/.test(item) ? item : '',
    from: stock && params.get('from') === 'products' ? 'products' : 'overview',
    returnFilter: stock ? filter : '',
    key: Object.hasOwn(InventorySections, name) ? hash : '#overview',
  };
}

function inventoryStockHref(section, itemId, from = 'overview', filter = '') {
  const params = new URLSearchParams({ item: itemId, from });
  if (from === 'products' && filter) params.set('filter', filter);
  return `#${section}?${params}`;
}

const inventoryScripts = new Map();
function loadInventoryScript(name) {
  if (!inventoryScripts.has(name)) {
    const promise = new Promise((resolve, reject) => {
      const script = document.createElement("script");
      script.src = name === "scanner"
        ? "https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"
        : `../../../scripts/components/${name}?v=product-details-20261002&release=combined-20261023&icons=20261018&fetching=20261019&inventory=20261023&presentation=20261024`;
      script.onload = resolve;
      script.onerror = () => {
        script.remove();
        inventoryScripts.delete(name);
        reject(new Error("Unable to load this inventory section. Please try again."));
      };
      document.head.appendChild(script);
    });
    inventoryScripts.set(name, promise);
  }
  return inventoryScripts.get(name);
}

function adminInventory() {
  const pendingPanels = new Map();
  return {
    activeSection: "",
    mountedSections: {},
    sectionLoading: false,
    sectionError: "",
    inventoryRevision: 0,
    stockReturnHref: '#overview',
    stockReturnLabel: 'Back to Overview',
    _navigation: 0,
    _scrollPositions: {},
    _hashListener: null,
    _changeListener: null,

    init() {
      if (API.enforceAdminPageAccess && !API.enforceAdminPageAccess()) return;
      this._hashListener = () => this.showSection();
      this._changeListener = () => { this.inventoryRevision += 1; };
      window.addEventListener("hashchange", this._hashListener);
      window.addEventListener("inventory:changed", this._changeListener);
      if (!window.location.hash) history.replaceState(null, "", "#overview");
      return this.showSection();
    },

    destroy() {
      window.removeEventListener("hashchange", this._hashListener);
      window.removeEventListener("inventory:changed", this._changeListener);
    },

    sectionData(section) {
      const panel = this.mountedSections[section];
      return panel ? Alpine.$data(panel) : null;
    },

    async mountSection(section) {
      const existing = this.$refs.panels.querySelector(`[data-inventory-section="${section}"]`);
      if (existing) return existing;
      if (!pendingPanels.has(section)) {
        const mount = async () => {
          const config = InventorySections[section];
          const [response] = await Promise.all([
            fetch(`./sections/${section}.html?v=inventory-receipts-20261023&release=combined-20261023&icons=20261018&presentation=20261024`),
            ...config.dependencies.map(loadInventoryScript),
            loadInventoryScript(config.script),
          ]);
          if (!response.ok) throw new Error("Unable to load this inventory section. Please try again.");
          const markup = await response.text();
          const panel = document.createElement("section");
          panel.dataset.inventorySection = section;
          panel.setAttribute("x-data", `adminInventorySection('${section}')`);
          panel.setAttribute("x-show", `activeSection === '${section}' && !sectionLoading`);
          panel.style.display = "none";
          if (section === "stock-in" || section === "stock-out") panel.className = "pb-28";
          panel.innerHTML = markup;
          Alpine.mutateDom(() => {
            this.$refs.panels.appendChild(panel);
            Alpine.initTree(panel);
          });
          this.mountedSections[section] = panel;
          return panel;
        };
        pendingPanels.set(section, mount().finally(() => pendingPanels.delete(section)));
      }
      return pendingPanels.get(section);
    },

    async showSection() {
      const navigation = ++this._navigation;
      const route = inventoryRoute();
      if (!Object.hasOwn(InventorySections, window.location.hash.slice(1).split("?")[0])) {
        history.replaceState(null, "", "#overview");
      }
      const previous = this.$refs.panels.querySelector(`[data-inventory-section="${this.activeSection}"]`);
      if (this.activeSection !== route.section && this.activeSection) this._scrollPositions[this.activeSection] = window.scrollY || 0;
      if (previous && this.activeSection !== route.section) Alpine.$data(previous).leave();
      if (this.activeSection !== route.section) window.scrollTo(0, 0);
      this.activeSection = route.section;
      if (['stock-in', 'stock-out'].includes(route.section)) {
        this.stockReturnHref = route.from === 'products'
          ? '#products' + (route.returnFilter ? '?filter=' + route.returnFilter : '') : '#overview';
        this.stockReturnLabel = route.from === 'products' ? 'Back to Products' : 'Back to Overview';
      }
      this.sectionError = "";
      const existing = this.$refs.panels.querySelector(`[data-inventory-section="${route.section}"]`);
      this.sectionLoading = !existing || Alpine.$data(existing)._loadedRevision === -1 || !!route.itemId;
      document.title = `${({ overview: "Inventory", products: "Products", "sell-product": "Point of Sale", "stock-in": "Stock In", "stock-out": "Stock Out", history: "Stock Movements" })[route.section]} | Bethlehem Animal Clinic`;
      try {
        const panel = await this.mountSection(route.section);
        // A slower first visit must not override a more recent navigation.
        const current = inventoryRoute();
        if (navigation !== this._navigation || current.key !== route.key) return;
        const state = Alpine.$data(panel);
        state._onPresentation = () => {
          const current = inventoryRoute();
          if (navigation === this._navigation && current.key === route.key) this.sectionLoading = false;
        };
        await state.activate(route, `${this.inventoryRevision}:${API.getAdminDataRevision?.('inventory') || ''}`);
        if (navigation === this._navigation) this.$nextTick(() => {
          if (navigation === this._navigation) window.scrollTo(0, this._scrollPositions[route.section] || 0);
        });
      } catch (err) {
        if (navigation === this._navigation) {
          this.sectionError = err.message || "Unable to open this inventory section.";
        }
      } finally {
        if (navigation === this._navigation) this.sectionLoading = false;
      }
    },
  };
}

function adminInventorySection(section) {
  const component = window[InventorySections[section].factory]();
  const originalDestroy = component.destroy;
  const originalInit = component.init;
  return Object.assign(component, {
    _loadedRevision: -1,
    _activation: null,

    // Alpine calls this on mount. Loading is deferred to activate(), so hidden
    // sections never fetch data or run a second initialization.
    init() {},

    async activate(route, revision) {
      if (section === 'sell-product') this._paymentVisible = true;
      if (section === "stock-in") {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        this.today = now.toISOString().slice(0, 10);
      }
      if (this._activation) {
        await this._activation;
        if (inventoryRoute().key !== route.key) return;
      }
      if (section === "sell-product" && (!this._paymentInitialized || this.onlinePayment)) {
        await originalInit?.call(this);
        this._paymentInitialized = !this.error;
      }
      const filterChanged = section === "products" && this.alertFilter !== route.filter;
      if (section === "products") this.alertFilter = route.filter;
      const stock = ['stock-in', 'stock-out'].includes(section);
      if (this._loadedRevision === revision && !filterChanged && !(stock && route.itemId)) return;
      const refresh = async () => {
        if (section === "sell-product") {
          this.searchResults = [];
          if (this.searchQuery.length >= 2) this.onSearchInput();
        } else if (typeof this.load === "function") {
          await this.load(filterChanged ? 1 : (this.currentPage || 1));
        } else {
          // Keep unsent entries intact, but update the selected product's stock
          // and price after a mutation in another inventory section.
          this.searchResults = [];
          this.noResults = false;
          const itemId = route.itemId || this.selected?.item_id;
          if (itemId) {
            try {
              const response = await InventoryAPI.getItem(itemId);
              if (inventoryRoute().key !== route.key) return;
              if (!response.data?.is_active) throw new Error('This product is inactive.');
              this.error = '';
              if (String(this.selected?.item_id) === String(itemId)) {
                this.selected = { ...response.data, _newly_created: this.selected._newly_created };
                if (section === 'stock-out') this.sellingPrice = this.selected.selling_price ?? '';
              } else this.pickItem(response.data);
            } catch (error) {
              if (inventoryRoute().key !== route.key) return;
              this.clearSelected();
              this.error = error.message || 'This product is unavailable.';
              window.showToast(this.error, 'error');
            }
          }
          if (this.searchQuery.length >= 2) this.onSearchInput();
        }
        if (!this.error) this._loadedRevision = revision;
      };
      this._activation = refresh().finally(() => { this._activation = null; });
      await this._activation;
    },

    leave() {
      clearTimeout(this._searchTimer);
      if (this.scannerActive) this.closeScanner();
      if (this.details?.open) this.closeDetails(false);
      if (section === "sell-product") {
        this._paymentVisible = false;
        this._posPaymentWatch?.stop(); this._posPaymentWatch = null;
        this.closeSuccess();
      }
    },

    destroy() {
      this.leave();
      originalDestroy?.call(this);
    },
  });
}
