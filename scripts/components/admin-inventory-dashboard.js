function adminInventoryDashboard() {
  return {
    loading: true,
    loaded: false,
    error: "",
    totalItems: 0,
    lowStockCount: 0,
    expiryCount: 0,
    lowStockItems: [],
    expiryItems: [],
    recentTransactions: [],
    activeTab: "low_stock",

    async init() { await this.load(); },

    async load() {
      this.loading = true;
      this.error = "";
      try {
        await InventoryAPI.getSummary({ page: 1, view: 'overview' }, summary => {
          this.loaded = true;
          this._onPresentation?.();
          this.totalItems = summary.total_items;
          this.lowStockCount = summary.low_stock_count;
          this.expiryCount = summary.expiry_alert_count;
          this.lowStockItems = (summary.low_stock_items ?? []).slice(0, 5);
          this.expiryItems = (summary.expiry_items ?? []).slice(0, 5);
          this.recentTransactions = (summary.recent_transactions ?? []).slice(0, 5);
        });
      } catch (err) {
        this.error = err.message || "Failed to load inventory overview.";
      } finally {
        this.loading = false;
      }
    },

    expiryStyle(item) { return InventoryAlerts.expiryStyle(item); },
    expiryLabel(item) { return InventoryAlerts.expiryLabel(item); },

    typeStyle(type) {
      return type === "stock_in"
        ? "background-color:#dcfce7;color:#166534"
        : "background-color:#fee2e2;color:#b91c1c";
    },

    fmtDate(d) {
      if (!d) return "—";
      return new Date(d).toLocaleString("en-PH", { dateStyle: "medium", timeStyle: "short" });
    },

    fmtQty(n) {
      const v = parseFloat(n ?? 0);
      return v % 1 === 0 ? v.toLocaleString() : v.toFixed(2);
    },
  };
}
