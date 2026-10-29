// Client notification center. API message/read/destination behavior is shared
// with the header bell; this page loads older notifications in 30-item pages.
(async function () {
  if (!(API.waitForCustomerSession ? await API.waitForCustomerSession() : API.hasAuthenticatedSession("customer"))) {
    API.redirectToSignIn();
    return;
  }

  const list = document.getElementById("pageNotificationList");
  const tabs = document.querySelectorAll("[data-page-notification-tab]");
  const markAll = document.getElementById("pageMarkAllRead");
  const loadMore = document.getElementById("notificationLoadMore");
  const loadMoreContainer = document.getElementById("notificationLoadMoreContainer");
  const statusBox = document.getElementById("notificationStatus");
  const search = document.getElementById("notificationSearch");
  const sidebarToggle = document.getElementById("clientSidebarToggle");
  const sidebarClose = document.getElementById("clientSidebarClose");
  const sidebarBackdrop = document.getElementById("clientSidebarBackdrop");
  const sidebar = document.getElementById("clientSidebar");
  const mobile = window.matchMedia("(max-width: 1180px)");
  let currentStatus = "all";
  let notifications = [];
  let page = 1;
  let hasMore = false;
  let busy = false;
  let unreadCount = 0;

  function setSidebarState(open) {
    document.body.classList.toggle("client-sidebar-open", open);
    sidebarToggle?.setAttribute("aria-expanded", String(open));
    sidebarToggle?.setAttribute("aria-label", open ? "Close navigation menu" : "Open navigation menu");
  }
  sidebarToggle?.addEventListener("click", () => { if (mobile.matches) setSidebarState(!document.body.classList.contains("client-sidebar-open")); });
  sidebarClose?.addEventListener("click", () => setSidebarState(false));
  sidebarBackdrop?.addEventListener("click", () => setSidebarState(false));
  sidebar?.querySelectorAll("a").forEach((link) => link.addEventListener("click", () => setSidebarState(false)));
  document.addEventListener("keydown", (event) => { if (event.key === "Escape") setSidebarState(false); });
  mobile.addEventListener("change", (event) => { if (!event.matches) setSidebarState(false); });

  async function loadProfile() {
    try {
      const { user } = await API.getMe("customer");
      const first = String(user?.first_name || "");
      const last = String(user?.last_name || "");
      document.getElementById("clientProfileName").textContent = `${first} ${last}`.trim() || "Customer";
      document.getElementById("clientProfileInitials").textContent = `${first[0] || ""}${last[0] || ""}`.toUpperCase() || "--";
    } catch (error) {
      if (API.isAuthenticationError?.(error)) API.redirectToSignIn();
    }
  }
  void loadProfile();
  document.getElementById("clientLogoutBtn")?.addEventListener("click", async () => {
    try { await API.logout("customer"); }
    finally { API.redirectToSignIn({ replace: true }); }
  });

  function showError(message) {
    statusBox.textContent = message;
    statusBox.classList.remove("hidden");
  }
  function clearError() {
    statusBox.textContent = "";
    statusBox.classList.add("hidden");
  }
  function draw() {
    const term = search?.value.trim().toLowerCase() || "";
    const visible = term ? notifications.filter((notification) =>
      String(notification.display_message || notification.message || "").toLowerCase().includes(term)) : notifications;
    if (term && !visible.length) {
      list.innerHTML = '<p class="px-6 py-12 text-center text-sm text-portal-muted">No matching notifications in the loaded results.</p>';
    } else {
      window.ClientNotificationUI.render(list, visible, currentStatus, false);
    }
    loadMoreContainer.classList.toggle("hidden", !hasMore);
    const unreadLabel = document.getElementById("pageUnreadCount");
    unreadLabel.textContent = `(${unreadCount})`;
    unreadLabel.classList.toggle("hidden", unreadCount === 0);
    markAll.disabled = unreadCount === 0 || busy;
  }

  async function load({ more = false } = {}) {
    if (busy) return;
    busy = true;
    clearError();
    if (!more) {
      page = 1;
      hasMore = false;
      list.innerHTML = '<p class="px-6 py-12 text-center text-sm text-portal-muted">Loading notifications...</p>';
    }
    loadMore.disabled = true;
    loadMore.textContent = "Loading...";
    try {
      const data = await API.getCustomerNotifications({ page: more ? page + 1 : 1, sort: "recent", status: currentStatus });
      const results = Array.isArray(data.notifications) ? data.notifications : [];
      notifications = more ? notifications.concat(results) : results;
      page = more ? page + 1 : 1;
      hasMore = Boolean(data.has_more);
      unreadCount = Number(data.unread_count || 0);
    } catch (error) {
      showError(error?.message || "Notifications are unavailable. Please try again.");
      if (!more) notifications = [];
    } finally {
      busy = false;
      loadMore.disabled = false;
      loadMore.textContent = "Load More";
      draw();
    }
  }

  tabs.forEach((tab) => tab.addEventListener("click", () => {
    if (busy || currentStatus === tab.dataset.pageNotificationTab) return;
    currentStatus = tab.dataset.pageNotificationTab;
    tabs.forEach((item) => item.setAttribute("aria-selected", String(item === tab)));
    void load();
  }));
  search?.addEventListener("input", draw);
  loadMore?.addEventListener("click", () => { void load({ more: true }); });
  markAll?.addEventListener("click", async () => {
    if (busy || unreadCount === 0) return;
    busy = true;
    markAll.disabled = true;
    try {
      await API.markAllCustomerNotificationsRead();
      busy = false;
      await load();
      document.dispatchEvent(new CustomEvent("client-notifications-changed", { detail: { source: "page" } }));
    } catch (error) {
      busy = false;
      showError(error?.message || "Unable to mark notifications as read.");
      draw();
    }
  });
  list?.addEventListener("click", async (event) => {
    const row = event.target.closest("[data-client-notification-index]");
    if (!row || busy) return;
    const term = search?.value.trim().toLowerCase() || "";
    const visible = term ? notifications.filter((notification) =>
      String(notification.display_message || notification.message || "").toLowerCase().includes(term)) : notifications;
    const notification = visible[Number(row.dataset.clientNotificationIndex)];
    if (!notification) return;
    busy = true;
    row.disabled = true;
    try {
      await API.markCustomerNotificationRead(notification.id);
      if (notification.destination) {
        window.location.href = notification.destination;
        return;
      }
      busy = false;
      await load();
      document.dispatchEvent(new CustomEvent("client-notifications-changed", { detail: { source: "page" } }));
    } catch (error) {
      busy = false;
      row.disabled = false;
      showError(error?.message || "Unable to mark the notification as read.");
    }
  });
  document.addEventListener("client-notifications-changed", (event) => {
    if (event.detail?.source === "header" && !busy) void load();
  });
  void load();
})();
