// Presentation shared by the client notification dropdown and full page.
// The caller owns loading, read state, click destinations, and pickup alerts.
(function () {
  const sprite = "../../assets/icons/phosphor.svg";
  const escapeHtml = (value) => String(value ?? "")
    .replaceAll("&", "&amp;").replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;").replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

  function dateOf(value) {
    if (!value) return null;
    const source = String(value);
    const withZone = /(?:Z|[+-]\d\d:?\d\d)$/i.test(source)
      ? source : `${source.replace(" ", "T")}+08:00`;
    const date = new Date(withZone);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function dateKey(value) {
    const date = value instanceof Date ? value : dateOf(value);
    if (!date) return "";
    const parts = new Intl.DateTimeFormat("en-CA", {
      timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit",
    }).formatToParts(date);
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    return `${values.year}-${values.month}-${values.day}`;
  }

  function iconFor(notification) {
    const type = String(notification?.type || "").toLowerCase();
    if (type.includes("cancel") || type.includes("expired")) return "x-circle";
    if (type.includes("pet_information")) return "paw-print";
    if (type.includes("reminder")) return "clock-counter-clockwise";
    if (type.includes("grooming") || type.includes("pickup") || type === "picked_up") return "check-circle";
    if (type.includes("booking") || type.includes("registration")) return "calendar-check";
    return "bell";
  }

  function iconMarkup(notification, large = false) {
    return `<span class="flex ${large ? "h-10 w-10" : "h-9 w-9"} shrink-0 items-center justify-center rounded-full bg-portal-surface-soft text-portal-primary" aria-hidden="true"><svg class="ph-icon ${large ? "h-5 w-5" : "h-4 w-4"}" viewBox="0 0 256 256" focusable="false"><use href="${sprite}#${iconFor(notification)}"></use></svg></span>`;
  }

  function messageMarkup(notification) {
    const message = String(notification?.display_message || notification?.message || "Notification");
    const phrases = [...(Array.isArray(notification?.pet_names) ? notification.pet_names : []),
      "Started Grooming", "Ready for Pickup", "Finished"];
    if (notification?.type === "pet_information_updated") {
      phrases.push("weight", "size", "breed", "fur type", "color", "species", "gender", "birthdate",
        "allergies and special care notes", "neutered status", "neutered date", "deceased status", "deceased date");
      const rename = message.match(/changed your pet's name from (.*?) to (.*?)(?: and updated |\.$)/);
      if (rename) phrases.push(rename[1], rename[2]);
      const savedName = message.match(/^Bethlehem Animal Clinic updated (.*)'s /);
      if (savedName) phrases.push(savedName[1]);
    }
    const escapedPhrases = [...new Set(phrases.map(value => String(value || "").trim()).filter(Boolean))]
      .sort((a, b) => b.length - a.length).map(value => value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));
    if (!escapedPhrases.length) return escapeHtml(message);
    const pattern = new RegExp(`(^|[^\\p{L}\\p{N}])(${escapedPhrases.join("|")})(?=$|[^\\p{L}\\p{N}])`, "gu");
    let html = "", offset = 0;
    for (const match of message.matchAll(pattern)) {
      html += escapeHtml(message.slice(offset, match.index)) + escapeHtml(match[1])
        + `<strong class="client-notification-emphasis">${escapeHtml(match[2])}</strong>`;
      offset = match.index + match[0].length;
    }
    return html + escapeHtml(message.slice(offset));
  }

  function timeLabel(value, includeDate = false) {
    const date = dateOf(value);
    if (!date) return "";
    return new Intl.DateTimeFormat("en-PH", {
      timeZone: "Asia/Manila",
      ...(includeDate ? { month: "short", day: "numeric" } : {}),
      hour: "numeric", minute: "2-digit",
    }).format(date);
  }

  function row(notification, index, large = false, formatMessage = messageMarkup) {
    const background = notification.is_read ? "bg-white" : "bg-portal-active";
    const formattedMessage = notification.type === "pet_information_updated"
      ? messageMarkup(notification) : formatMessage(notification);
    return `<button type="button" data-client-notification-index="${index}" class="flex w-full items-start gap-3 border-b border-portal-border px-4 py-3 text-left transition hover:bg-portal-surface-soft ${background} ${large ? "sm:px-6 sm:py-4" : ""}">
      ${iconMarkup(notification, large)}
      <span class="min-w-0 flex-1">
        ${notification.title ? `<span class="block text-sm font-semibold text-portal-text">${escapeHtml(notification.title)}</span>` : ""}
        <span class="block text-sm leading-snug text-portal-text">${formattedMessage}</span>
        <span class="mt-1 block text-xs text-portal-muted">${escapeHtml(timeLabel(notification.created_at, !large))}</span>
      </span>
      <span class="mt-2 h-2 w-2 shrink-0 rounded-full ${notification.is_read ? "bg-transparent" : "bg-portal-primary"}" aria-hidden="true"></span>
    </button>`;
  }

  function groupLabel(key, todayKey, dropdown) {
    if (key === todayKey) return "Today";
    if (dropdown) return "Earlier";
    const yesterday = dateKey(new Date(Date.now() - 86400000));
    if (key === yesterday) return "Yesterday";
    if (!key) return "Date unavailable";
    const [year, month, day] = key.split("-").map(Number);
    return new Intl.DateTimeFormat("en-PH", {
      timeZone: "UTC", month: "short", day: "numeric",
      ...(year !== Number(todayKey.slice(0, 4)) ? { year: "numeric" } : {}),
    }).format(new Date(Date.UTC(year, month - 1, day)));
  }

  function render(list, notifications, status = "all", dropdown = true, formatMessage = messageMarkup) {
    const visible = status === "unread"
      ? notifications.filter((notification) => !notification.is_read) : notifications;
    if (!visible.length) {
      list.innerHTML = `<p class="px-4 py-8 text-center text-sm text-portal-muted">${status === "unread" ? "No unread notifications." : "No notifications yet."}</p>`;
      return;
    }
    const todayKey = dateKey(new Date());
    const groups = new Map();
    visible.forEach((notification) => {
      const key = dateKey(notification.created_at);
      const groupKey = dropdown && key !== todayKey ? "earlier" : key;
      if (!groups.has(groupKey)) groups.set(groupKey, []);
      groups.get(groupKey).push(notification);
    });
    list.innerHTML = Array.from(groups, ([key, items]) => `<section>
      <p class="border-b border-portal-border px-4 py-2 text-[11px] font-bold text-portal-muted ${dropdown ? "" : "sm:px-6"}">${escapeHtml(groupLabel(key === "earlier" ? "" : key, todayKey, dropdown))}</p>
      ${items.map((notification) => row(notification, notifications.indexOf(notification), !dropdown, formatMessage)).join("")}
    </section>`).join("");
  }

  function ensureDropdownControls(dropdown, onTabChange) {
    const list = dropdown?.querySelector("#notifList");
    if (!list) return () => "all";
    const header = list.previousElementSibling;
    const tabs = document.createElement("div");
    tabs.className = "flex shrink-0 border-b border-portal-border";
    tabs.setAttribute("role", "tablist");
    tabs.setAttribute("aria-label", "Notification status");
    tabs.innerHTML = ["all", "unread"].map((status) => `<button type="button" data-client-notification-tab="${status}" role="tab" aria-selected="${status === "all"}" class="client-notification-tab flex-1 border-b-2 py-2 text-xs font-semibold transition">${status === "all" ? "All" : 'Unread <span data-client-unread-count class="hidden"></span>'}</button>`).join("");
    header.after(tabs);
    const footer = document.createElement("div");
    footer.className = "shrink-0 border-t border-portal-border px-4 py-3 text-center";
    footer.innerHTML = '<a href="./notifications.html" class="text-xs font-semibold text-portal-primary hover:underline">See all notifications</a>';
    list.after(footer);
    let status = "all";
    tabs.addEventListener("click", (event) => {
      const button = event.target.closest("[data-client-notification-tab]");
      if (!button) return;
      status = button.dataset.clientNotificationTab;
      tabs.querySelectorAll("button").forEach((tab) => tab.setAttribute("aria-selected", String(tab === button)));
      onTabChange(status);
    });
    return () => status;
  }

  function setUnreadCount(dropdown, count) {
    const label = dropdown?.querySelector("[data-client-unread-count]");
    if (!label) return;
    label.textContent = `(${count})`;
    label.classList.toggle("hidden", count === 0);
  }

  window.ClientNotificationUI = { render, ensureDropdownControls, setUnreadCount, dateOf, dateKey, timeLabel };
})();
