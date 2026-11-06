// Shared client bell for Settings, Grooming History, and pet details.
// Uses the existing customer notification API and destinations.
const bell = document.getElementById("notifBellBtn");
const badge = document.getElementById("notifBadge");
const dropdown = document.getElementById("notifDropdown");
const list = document.getElementById("notifList");
const markAll = document.getElementById("notifMarkAllRead");
let notifications = [];
const currentTab = window.ClientNotificationUI?.ensureDropdownControls(dropdown, () => draw(), closeDropdown);

function closeDropdown() {
  if (!bell || !dropdown) return;
  dropdown.style.display = "none";
  bell.setAttribute("aria-expanded", "false");
}

function positionDropdown() {
  window.ClientNotificationUI.positionDropdown(bell, dropdown);
}

function draw() {
  if (!list) return;
  window.ClientNotificationUI.render(list, notifications, currentTab?.() || "all");
}

async function loadNotifications() {
  if (!badge || !list) return;
  try {
    await API.loadCustomerData("/customer/notifications", () => API.getCustomerNotifications(), (data) => {
      notifications = Array.isArray(data.notifications) ? data.notifications : [];
      const count = Number(data.unread_count || 0);
      badge.textContent = count > 9 ? "9+" : String(count);
      badge.classList.toggle("hidden", count === 0);
      badge.classList.toggle("inline-flex", count > 0);
      window.ClientNotificationUI.setUnreadCount(dropdown, count);
      markAll?.toggleAttribute("disabled", count === 0);
      draw();
    });
  } catch {
    list.innerHTML = '<p class="px-4 py-6 text-center text-sm text-portal-muted">Notifications are unavailable.</p>';
  }
}

bell?.addEventListener("click", () => {
  if (!dropdown) return;
  if (dropdown.style.display !== "none") return closeDropdown();
  positionDropdown();
  dropdown.style.display = "flex";
  bell.setAttribute("aria-expanded", "true");
  void loadNotifications();
});

list?.addEventListener("click", async (event) => {
  const row = event.target.closest("[data-client-notification-index]");
  if (!row) return;
  const notification = notifications[Number(row.dataset.clientNotificationIndex)];
  if (!notification) return;
  try {
    await API.markCustomerNotificationRead(notification.id);
    if (notification.destination) {
      window.location.href = notification.destination;
      return;
    }
    await loadNotifications();
    document.dispatchEvent(new CustomEvent("client-notifications-changed", { detail: { source: "header" } }));
  } catch {
    // Reading notifications does not block the client page.
  }
});

markAll?.addEventListener("click", async () => {
  try {
    await API.markAllCustomerNotificationsRead();
    await loadNotifications();
    document.dispatchEvent(new CustomEvent("client-notifications-changed", { detail: { source: "header" } }));
  } catch {
    // Reading notifications does not block the client page.
  }
});

document.addEventListener("click", (event) => {
  if (dropdown?.style.display !== "none" && !dropdown.contains(event.target) && !bell?.contains(event.target)) closeDropdown();
});
window.addEventListener("resize", () => { if (dropdown?.style.display !== "none") positionDropdown(); });
document.addEventListener("client-notifications-changed", (event) => {
  if (event.detail?.source !== "header") void loadNotifications();
});
if (API.readCustomerCache("/customer/notifications")) void loadNotifications();
else window.requestAnimationFrame(() => {
  if (window.requestIdleCallback) window.requestIdleCallback(() => void loadNotifications(), { timeout: 1200 });
  else window.setTimeout(() => void loadNotifications(), 200);
});
window.setInterval(() => {
  if (document.visibilityState === "visible") void loadNotifications();
}, 15000);
