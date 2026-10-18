function adminInventoryTransactions() {
  return {
    transactions: [],
    loading: true,
    loaded: false,
    _loadedQuery: "",
    _listRequest: 0,
    error: "",

    filterType: "",
    filterCategory: "",
    filterDateFrom: "",
    filterDateTo: "",
    currentPage: 1,
    lastPage: 1,
    total: 0,

    async init() {
      await this.load();
      this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
    },

    async load(page = 1) {
      const query = JSON.stringify([this.filterType, this.filterCategory, this.filterDateFrom, this.filterDateTo, page]), request = ++this._listRequest;
      this.loaded = this.loaded && query === this._loadedQuery;
      this.loading = true;
      this.error   = "";
      try {
        const res = await InventoryAPI.getTransactions({
          type:      this.filterType,
          category:  this.filterCategory,
          date_from: this.filterDateFrom,
          date_to:   this.filterDateTo,
          page,
        });
        if (request !== this._listRequest) return;
        this.loaded = true;
        this._loadedQuery = query;
        this.transactions = res.data;
        this.currentPage  = res.page;
        this.lastPage     = res.last_page;
        this.total        = res.total;
      } catch (err) {
        if (request !== this._listRequest) return;
        this.error = err.message || "Failed to load transactions.";
      } finally {
        if (request !== this._listRequest) return;
        this.loading = false;
        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
      }
    },

    applyFilters() { this.load(1); },

    clearFilters() {
      this.filterType      = "";
      this.filterCategory  = "";
      this.filterDateFrom  = "";
      this.filterDateTo    = "";
      this.load(1);
    },

    prevPage() { if (this.currentPage > 1) this.load(this.currentPage - 1); },
    nextPage() { if (this.currentPage < this.lastPage) this.load(this.currentPage + 1); },

    // ── Helpers ──────────────────────────────────────────────────────────────

    reasonStyle(reason) {
      return {
        purchase:   "background-color:#dcfce7;color:#166534",
        return:     "background-color:#dbeafe;color:#1d4ed8",
        used:       "background-color:#fef3c7;color:#92400e",
        sold:       "background-color:#d1fae5;color:#065f46",
        expired:    "background-color:#fee2e2;color:#b91c1c",
        damaged:    "background-color:#fee2e2;color:#b91c1c",
        adjustment: "background-color:#f1f5f9;color:#475569",
      }[reason] ?? "background-color:#f1f5f9;color:#475569";
    },

    reasonLabel(r) {
      return {
        purchase:   "Purchase",
        return:     "Return",
        used:       "Used",
        sold:       "Sold",
        expired:    "Expired",
        damaged:    "Damaged",
        adjustment: "Adjustment",
      }[r] ?? r;
    },

    qtyStyle(type) {
      return type === "stock_in" ? "color:#166534;font-weight:600" : "color:#b91c1c;font-weight:600";
    },

    categoryLabel(cat) {
      return {
        medicine:        "Medicine",
        vaccine:         "Vaccine",
        food:            "Food",
        grooming_supply: "Grooming Supply",
        pet_shop:        "Pet Shop",
        miscellaneous:   "Miscellaneous",
      }[cat] ?? (cat ?? "—");
    },

    fmtDate(d) {
      if (!d) return "—";
      const dt = new Date(d);
      const date = dt.toLocaleDateString("en-PH", { month: "short", day: "numeric" });
      const time = dt.toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit", hour12: true });
      return `${date} · ${time}`;
    },

    fmtQty(n) {
      const v = parseFloat(n ?? 0);
      return v % 1 === 0 ? v.toLocaleString() : v.toFixed(2);
    },
  };
}
