// inventory-service.js — HTTP layer for inventory and POS endpoints.
// Loaded as a plain <script> before Alpine. Exposes global InventoryAPI.

var InventoryAPI = (() => {
  function request(method, endpoint, body = null, render = null) {
    if (typeof API === "undefined" || typeof API.adminRequest !== "function") {
      throw new Error("The shared admin API client is unavailable.");
    }

    if (render) return API.loadAdminData(endpoint, () => request(method, endpoint, body), render);
    return API.adminRequest(method, endpoint, body).then((response) => {
      if (method !== "GET" && (endpoint.startsWith("/inventory/") || endpoint === "/pos/transactions") && window.dispatchEvent) {
        window.dispatchEvent(new CustomEvent("inventory:changed"));
      }
      return response;
    });
  }

  // ── Items ─────────────────────────────────────────────────────────────────

  function getItems({ q = "", category = "", low_stock = false, include_inactive = false, inactive_only = false, page = 1 } = {}, render) {
    const p = new URLSearchParams({ page });
    if (q)                p.set("q", q);
    if (category)         p.set("category", category);
    if (low_stock)        p.set("low_stock", "1");
    if (include_inactive) p.set("include_inactive", "1");
    if (inactive_only)    p.set("inactive_only", "1");
    return request("GET", `/inventory/items?${p}`, null, render);
  }

  function getItem(id) {
    return request("GET", `/inventory/items/${id}`);
  }

  function createItem(payload) {
    return request("POST", "/inventory/items", payload);
  }

  function updateItem(id, payload) {
    return request("PUT", `/inventory/items/${id}`, payload);
  }

  function deactivateItem(id) {
    return request("POST", `/inventory/items/${id}/deactivate`);
  }

  function reactivateItem(id) {
    return request("POST", `/inventory/items/${id}/reactivate`);
  }

  // ── Barcode & search ──────────────────────────────────────────────────────

  function findByBarcode(barcode, includeInactive = false, saleContext = "") {
    const params = new URLSearchParams();
    if (includeInactive) params.set("include_inactive", "1");
    if (saleContext) params.set("sale_context", saleContext);
    return request("GET", `/inventory/barcode/${encodeURIComponent(barcode)}${params.size ? '?' + params : ''}`);
  }

  function searchItems(q, includeInactive = false, saleContext = "") {
    const params = new URLSearchParams({ q });
    if (includeInactive) params.set("include_inactive", "1");
    if (saleContext) params.set("sale_context", saleContext);
    return request("GET", `/inventory/search?${params}`);
  }

  // ── Stock movements ───────────────────────────────────────────────────────

  function stockIn(items) {
    // items: [{ item_id, quantity, reason, unit_cost?, batch_number?, batch_unavailable_reason?, expiry_date?, notes? }]
    return request("POST", "/inventory/stock-in", {
      items,
    });
  }

  function stockOut(items) {
    return request("POST", "/inventory/stock-out", { items });
  }

  // ── Alerts & history ──────────────────────────────────────────────────────

  function getLowStock({ page = 1 } = {}) {
    return request("GET", `/inventory/low-stock?page=${page}`);
  }

  function getExpiryAlerts({ page = 1 } = {}, render) {
    return request("GET", `/inventory/alerts/expiry?page=${page}`, null, render);
  }

  function getAlertBadge() {
    return request("GET", "/inventory/alerts/badge");
  }

  function getTransactions({ item_id = "", type = "", category = "", date_from = "", date_to = "", page = 1, per_page = 20 } = {}, render) {
    const p = new URLSearchParams({ page });
    if (per_page !== 20) p.set("per_page", per_page);
    if (item_id)   p.set("item_id", item_id);
    if (type)      p.set("type", type);
    if (category)  p.set("category", category);
    if (date_from) p.set("date_from", date_from);
    if (date_to)   p.set("date_to", date_to);
    return request("GET", `/inventory/transactions?${p}`, null, render);
  }

  function getSummary({ page = 1, view = '' } = {}, render) {
    return request("GET", `/inventory/summary?page=${page}${view ? '&view=' + encodeURIComponent(view) : ''}`, null, render);
  }

  // ── POS ───────────────────────────────────────────────────────────────────

  function validateCart(payload) {
    return request("POST", "/pos/validate-cart", payload);
  }

  function processSale(payload) {
    return request("POST", "/pos/transactions", payload);
  }

  function getReceipt(posId) {
    return request("GET", `/pos/transactions/${posId}`);
  }

  function getPosTransactions({ cashier_id = "", date_from = "", date_to = "", page = 1 } = {}) {
    const p = new URLSearchParams({ page });
    if (cashier_id) p.set("cashier_id", cashier_id);
    if (date_from)  p.set("date_from", date_from);
    if (date_to)    p.set("date_to", date_to);
    return request("GET", `/pos/transactions?${p}`);
  }

  return {
    createOnlinePayment: payload => request('POST', '/pos/online-payment', payload),
    onlinePaymentStatus: (id, key = null) => request('GET', `/pos/online-payment${id ? '/' + id : ''}${key ? '?request_key=' + encodeURIComponent(key) : ''}`),
    cancelOnlinePayment: id => request('DELETE', `/pos/online-payment/${id}`),
    // items
    getItems, getItem, createItem, updateItem, deactivateItem, reactivateItem,
    // barcode
    findByBarcode, searchItems,
    // stock
    stockIn, stockOut,
    // alerts + history
    getLowStock, getExpiryAlerts, getAlertBadge, getTransactions, getSummary,
    // pos
    validateCart, processSale, getReceipt, getPosTransactions,
  };
})();
