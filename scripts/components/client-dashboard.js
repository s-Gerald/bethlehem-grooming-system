// Connected to pages/client/dashboard.html
// Depends on: api.js (loaded before this script)

// Client dashboard shell: mobile sidebar, profile name, and logout.
// Icons use the local Phosphor regular SVG sprite (no hydration required).
// Connected to the sidebar/profile controls in pages/client/dashboard.html.
function scheduleCustomerDashboardIdleTask(task) {
  if (typeof window.requestIdleCallback === "function") {
    window.requestIdleCallback(task, { timeout: 1200 });
    return;
  }

  setTimeout(task, 200);
}

function scheduleCustomerDashboardAfterPaint(task) {
  const schedule = () => scheduleCustomerDashboardIdleTask(task);
  if (typeof window.requestAnimationFrame === "function") {
    window.requestAnimationFrame(schedule);
    return;
  }

  schedule();
}

(function () {
  const mobileSidebarQuery = window.matchMedia("(max-width: 1180px)");
  const sidebarToggle = document.getElementById("clientSidebarToggle");
  const sidebarClose = document.getElementById("clientSidebarClose");
  const sidebarBackdrop = document.getElementById("clientSidebarBackdrop");
  const sidebar = document.getElementById("clientSidebar");
  const profileName = document.getElementById("clientProfileName");
  const profileInitials = document.getElementById("clientProfileInitials");
  const logoutBtn = document.getElementById("clientLogoutBtn");

  if (!sidebarToggle || !sidebarClose || !sidebarBackdrop || !sidebar) return;

  const sidebarLinks = sidebar.querySelectorAll("a");
  const navigationLinks = sidebar.querySelectorAll(".portal-nav-item");

  function setActiveNavigation(activeLink) {
    navigationLinks.forEach((link) => {
      if (link === activeLink) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });
  }

  function syncActiveNavigation() {
    const activeLink = Array.from(navigationLinks).find((link) =>
      new URL(link.href, window.location.href).pathname === window.location.pathname
    );
    if (activeLink) setActiveNavigation(activeLink);
  }

  navigationLinks.forEach((link) => {
    link.addEventListener("click", (event) => {
      if (!event.defaultPrevented && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
        setActiveNavigation(link);
      }
    });
  });
  syncActiveNavigation();
  window.addEventListener("pageshow", syncActiveNavigation);

  // Sidebar navigation section: open/close behavior for tablet and mobile.
  function setSidebarState(isOpen) {
    document.body.classList.toggle("client-sidebar-open", isOpen);
    sidebarToggle.setAttribute("aria-expanded", String(isOpen));
    sidebarToggle.setAttribute(
      "aria-label",
      isOpen ? "Close navigation menu" : "Open navigation menu",
    );
  }

  function closeSidebar() {
    setSidebarState(false);
  }

  function toggleSidebar() {
    if (!mobileSidebarQuery.matches) return;
    const isOpen = document.body.classList.contains("client-sidebar-open");
    setSidebarState(!isOpen);
  }

  setSidebarState(false);

  sidebarToggle.addEventListener("click", toggleSidebar);
  sidebarClose.addEventListener("click", closeSidebar);
  sidebarBackdrop.addEventListener("click", closeSidebar);

  sidebarLinks.forEach((link) => {
    link.addEventListener("click", closeSidebar);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeSidebar();
    }
  });

  mobileSidebarQuery.addEventListener("change", (event) => {
    if (!event.matches) {
      closeSidebar();
    }
  });

  // Client profile section: load the logged-in customer's name and initials.
  const preRegisterButton = document.getElementById("preRegisterButton");
  let resolveCustomerProfile;
  if (preRegisterButton) {
    preRegisterButton.customerProfileReady = new Promise((resolve) => { resolveCustomerProfile = resolve; });
  }
  const loadDashboardProfile = async () => {
    try {
      const { user } = await API.getMe("customer");
      resolveCustomerProfile?.(user);
      const firstName = user.first_name || "";
      const lastName = user.last_name || "";

      const greeting = document.getElementById("dashboardGreeting");
      if (greeting) {
        const initialSession = String(document.cookie || "").split(";").some((cookie) =>
          cookie.trim() === `bethlehem_customer_initial_session_${encodeURIComponent(user.user_id)}=1`
        );
        greeting.textContent = `${initialSession ? "Welcome" : "Welcome back"}${firstName ? `, ${firstName}` : ""}`;
      }

      if (profileName) {
        profileName.textContent = `${firstName} ${lastName}`.trim() || "Customer";
      }

      if (profileInitials) {
        profileInitials.textContent =
          ((firstName[0] || "") + (lastName[0] || "")).toUpperCase() || "--";
      }
    } catch (error) {
      resolveCustomerProfile?.(null);
      if (API.isAuthenticationError?.(error) || !API.hasAuthenticatedSession?.("customer")) {
        API.redirectToSignIn?.();
        return;
      }

      // A temporary API outage must not bounce between dashboard and sign-in.
      console.error("Unable to load the customer profile.", error);
    }
  };
  scheduleCustomerDashboardAfterPaint(loadDashboardProfile);

  // Logout section: end the customer session and return to sign in.
  if (logoutBtn) {
    logoutBtn.addEventListener("click", async () => {
      try {
        await API.logout("customer");
      } finally {
        API.redirectToSignIn({ replace: true });
      }
    });
  }
})();

// ── Customer Notification Bell ────────────────────────────────────────────────
(function () {
  const bellBtn        = document.getElementById("notifBellBtn");
  const badge          = document.getElementById("notifBadge");
  const dropdown       = document.getElementById("notifDropdown");
  const list           = document.getElementById("notifList");
  const markAllBtn     = document.getElementById("notifMarkAllRead");
  const pickupPopup    = document.getElementById("pickupPopup");
  const pickupMessage  = document.getElementById("pickupMessage");
  const pickupDismiss  = document.getElementById("pickupDismissBtn");
  let notificationsLoading = false;
  let shownNotifications = [];
  const currentTab = window.ClientNotificationUI.ensureDropdownControls(dropdown, () => renderNotifList(shownNotifications));

  const SHOWN_PICKUPS_KEY = "shownPickupNotifs";
  const PICKUP_READY_STATUSES = new Set([
    "for_payment",
    "for_pickup",
    "for-pickup",
    "ready_for_pickup",
  ]);

  function getShownPickups() {
    try { return JSON.parse(localStorage.getItem(SHOWN_PICKUPS_KEY) || "[]"); }
    catch { return []; }
  }

  function markPickupShown(id) {
    const shown = getShownPickups();
    if (!shown.includes(id)) {
      shown.push(id);
      localStorage.setItem(SHOWN_PICKUPS_KEY, JSON.stringify(shown));
    }
  }

  // ── Toggle dropdown ────────────────────────────────────────────────────────
  bellBtn.addEventListener("click", () => {
    dropdown.style.display === "none" ? openDropdown() : closeDropdown();
  });

  document.addEventListener("click", (e) => {
    if (dropdown.style.display === "none") return;
    const path = e.composedPath();
    if (!path.includes(dropdown) && !path.includes(bellBtn)) {
      closeDropdown();
    }
  });

  window.addEventListener("resize", () => {
    if (dropdown.style.display !== "none") positionDropdown();
  });

  function openDropdown() {
    void loadNotifications();
    positionDropdown();
    dropdown.style.display = "flex";
    bellBtn.setAttribute("aria-expanded", "true");
  }

  function closeDropdown() {
    dropdown.style.display = "none";
    bellBtn.setAttribute("aria-expanded", "false");
  }

  function positionDropdown() {
    const rect      = bellBtn.getBoundingClientRect();
    const gap       = 8;
    const margin    = 12;
    const dropWidth = Math.min(384, window.innerWidth - margin * 2);

    // Right-align to bell, clamped so it never clips the left edge
    let right = window.innerWidth - rect.right;
    right = Math.max(margin, right);

    dropdown.style.top   = (rect.bottom + gap) + "px";
    dropdown.style.right = right + "px";
    dropdown.style.left  = "auto";
    dropdown.style.width = dropWidth + "px";
  }

  // ── Mark all read ──────────────────────────────────────────────────────────
  markAllBtn.addEventListener("click", async () => {
    try {
      await API.markAllCustomerNotificationsRead();
      await loadNotifications();
    } catch { /* silent */ }
  });

  // ── Pickup popup dismiss ───────────────────────────────────────────────────
  pickupDismiss.addEventListener("click", async () => {
    const notifId = pickupPopup.dataset.notifId;
    pickupPopup.classList.add("hidden");
    if (notifId) {
      markPickupShown(notifId);
      await loadNotifications();
    }
  });

  // ── Load notifications ─────────────────────────────────────────────────────
  async function loadNotifications() {
    if (notificationsLoading) return;
    notificationsLoading = true;

    try {
      const data = await API.loadCustomerData("/customer/notifications", () => API.getCustomerNotifications(), (data) => {

        // Badge
        const count = data.unread_count || 0;
        window.ClientNotificationUI.setUnreadCount(dropdown, Number(count));
        if (count > 0) {
          badge.textContent = count > 9 ? "9+" : count;
          badge.classList.remove("hidden");
          badge.classList.add("inline-flex");
        } else {
          badge.classList.add("hidden");
          badge.classList.remove("inline-flex");
        }

        // List
        renderNotifList(data.notifications || []);
      });

      // Pickup alert popup — refresh the tracker first so the booking shows
      // its updated status before the popup fires.
      const pickup = data.pickup_alert;
      if (pickup && !getShownPickups().map(String).includes(String(pickup.id))) {
        await window._refreshAppointments?.({ force: true });
        pickupMessage.innerHTML = await buildPickupMessage(pickup);
        pickupPopup.dataset.notifId = pickup.id;
        pickupPopup.classList.remove("hidden");
      }
    } catch { /* silent — non-critical */ }
    finally { notificationsLoading = false; }
  }

  function renderNotifList(notifications) {
    shownNotifications = notifications;
    window.ClientNotificationUI.render(list, notifications, currentTab(), true, formatNotificationMessage);

    // Mark single notification as read on click
    list.querySelectorAll("[data-client-notification-index]").forEach((el) => {
      el.addEventListener("click", async () => {
        const notification = notifications[Number(el.dataset.clientNotificationIndex)];
        const id = notification?.id;
        if (!id) return;
        try {
          await API.markCustomerNotificationRead(id);

          if (
            ["pet_information_updated"].includes(
              notification?.type,
            )
            && notification.destination
          ) {
            window.location.href = notification.destination;
            return;
          }

          await loadNotifications();
        } catch { /* silent */ }
      });
    });

  }

  function notifIcon(notification) {
    const type = typeof notification === "object" && notification
      ? notification.type
      : notification;

    if (type === "grooming_finished") {
      return groomingFinishedIcon(notification);
    }

    const icons = {
      reminder_24h:    "📅",
      reminder_3h:     "⏰",
      grooming_started:"✂️",
      ready_for_pickup:"🐾",
      pickup_reminder: "⏳",
      picked_up:       "🏠",
    };
    return icons[type] || "🔔";
  }

  function groomingFinishedIcon(notification) {
    const petTypes = notificationPetTypes(notification);

    if (petTypes.includes("cat")) {
      return "🐱";
    }

    if (petTypes.includes("dog")) {
      return "🐶";
    }

    return "🐾";
  }

  function formatNotificationMessage(notification) {
    if (!notification || typeof notification !== "object") {
      return boldImportantTerms(escapeHtml(stripDecorativePaws(notification)));
    }

    if (notification.type === "grooming_started") {
      return formatPetStatusMessage(notification, "Started Grooming");
    }

    if (notification.type === "grooming_finished") {
      return formatPetStatusMessage(notification, "Finished");
    }

    if (notification.type === "ready_for_pickup") {
      return formatReadyForPickupMessage(notification);
    }

    return boldImportantTerms(
      escapeHtml(stripDecorativePaws(notification.display_message || notification.message))
    );
  }

  function formatPetStatusMessage(notification, statusLabel) {
    const petNames = notificationPetNames(notification);
    const message = escapeHtml(stripDecorativePaws(notification.display_message || notification.message));

    return boldImportantTerms(boldPetNames(message, petNames));
  }

  function formatReadyForPickupMessage(notification) {
    const petCount = notificationPetNames(notification).length;
    const subject = petCount > 1 ? "pets are" : "pet is";

    return `Your ${subject} now <strong class="client-notification-emphasis">Ready for Pickup</strong> and looking fabulous! Please come to the clinic to pick them up.`;
  }

  function notificationPetNames(notification) {
    return Array.isArray(notification?.pet_names)
      ? notification.pet_names.map((name) => String(name).trim()).filter(Boolean)
      : [];
  }

  function notificationPetTypes(notification) {
    return Array.isArray(notification?.pet_types)
      ? notification.pet_types.map((type) => String(type).trim().toLowerCase()).filter(Boolean)
      : [];
  }

  function boldPetNames(escapedMessage, petNames) {
    return petNames.reduce((message, petName) => {
      const escapedName = escapeHtml(String(petName).trim());

      if (!escapedName) return message;

      const pattern = new RegExp(
        `(^|[^\\p{L}\\p{N}])(${escapeRegExp(escapedName)})(?=$|[^\\p{L}\\p{N}])`,
        "gu"
      );

      return message.replace(pattern, '$1<strong class="client-notification-emphasis">$2</strong>');
    }, String(escapedMessage || ""));
  }

  function escapeRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  function boldImportantTerms(escapedMessage) {
    return String(escapedMessage || "")
      .replace(/\bStarted Grooming\b/g, '<strong class="client-notification-emphasis">Started Grooming</strong>')
      .replace(/\bReady for Pickup\b/g, '<strong class="client-notification-emphasis">Ready for Pickup</strong>')
      .replace(/\bFinished\b/g, '<strong class="client-notification-emphasis">Finished</strong>');
  }

  function stripDecorativePaws(message) {
    return String(message || "")
      .replace(/\s*🐾\s*/g, " ")
      .replace(/\s{2,}/g, " ")
      .trim();
  }

  function escapeHtml(value) {
    return String(value || "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[char]);
  }

  function formatNotifTime(dateStr) {
    if (!dateStr) return "";
    return new Date(dateStr).toLocaleString("en-PH", {
      month: "short", day: "numeric",
      hour: "numeric", minute: "2-digit",
    });
  }

  async function buildPickupMessage(pickup) {
    const providedPetNames = Array.isArray(pickup?.pet_names)
      ? pickup.pet_names.map((name) => String(name).trim()).filter(Boolean)
      : [];

    if (providedPetNames.length > 0) {
      return formatReadyForPickupMessage({ pet_names: providedPetNames });
    }

    try {
      const booking = await findPickupBooking(pickup);
      const petNames = getBookingPetNames(booking);

      if (petNames.length > 0) {
        return formatReadyForPickupMessage({ pet_names: petNames });
      }
    } catch { /* use API-provided message below */ }

    return formatReadyForPickupMessage({ pet_names: [] });
  }

  async function findPickupBooking(pickup) {
    const data = await API.getBookingHistory({ historyLimit: 0 });
    const activeBookings = Array.isArray(data?.bookings) ? data.bookings : [];
    const pickupBookingId = getPickupBookingId(pickup);

    if (pickupBookingId) {
      const matchedBooking = activeBookings.find((booking) =>
        String(getBookingId(booking)) === String(pickupBookingId)
      );
      if (matchedBooking) return matchedBooking;
    }

    const readyBookings = activeBookings.filter((booking) =>
      PICKUP_READY_STATUSES.has(String(booking?.status || "").toLowerCase())
    );

    return readyBookings.length === 1 ? readyBookings[0] : null;
  }

  function getPickupBookingId(pickup) {
    return (
      pickup?.booking_id ??
      pickup?.bookingId ??
      pickup?.booking?.booking_id ??
      pickup?.booking?.id ??
      null
    );
  }

  function getBookingId(booking) {
    return booking?.booking_id ?? booking?.bookingId ?? booking?.id ?? null;
  }

  function getBookingPetNames(booking) {
    if (!booking) return [];

    const names = [];
    if (Array.isArray(booking.pet_names)) names.push(...booking.pet_names);
    if (Array.isArray(booking.petNames)) names.push(...booking.petNames);
    if (Array.isArray(booking.pets)) {
      booking.pets.forEach((pet) => {
        names.push(pet?.pet_name ?? pet?.petName ?? pet?.name ?? "");
      });
    }

    names.push(booking.pet_name ?? booking.petName ?? "");

    return [...new Set(names.map((name) => String(name).trim()).filter(Boolean))];
  }

  // Keep the notification request behind the first dashboard paint.
  scheduleCustomerDashboardAfterPaint(loadNotifications);
  setInterval(() => {
    if (document.visibilityState === "visible") void loadNotifications();
  }, 30000);
})();

// ── Appointments, Grooming Tracker & History ─────────────────────────────────
(function () {
  const scheduleDashboardDataIdle = typeof scheduleCustomerDashboardIdleTask === "function"
    ? scheduleCustomerDashboardIdleTask
    : (task) => setTimeout(task, 0);
  const scheduleDashboardDataAfterPaint = typeof scheduleCustomerDashboardAfterPaint === "function"
    ? scheduleCustomerDashboardAfterPaint
    : scheduleDashboardDataIdle;
  const appointmentsList         = document.getElementById("appointmentsList");
  const groomingTrackerEl        = document.getElementById("groomingTracker");
  const groomingTrackerMetadataEl = document.getElementById("groomingTrackerMetadata");
  const groomingHistoryEl        = document.getElementById("groomingHistory");
  const myPetsPreviewEl = document.getElementById("myPetsPreview");
  const trackerSection = document.getElementById("trackerSection");
  const upcomingSection = document.getElementById("upcomingSection");
  const groomingQueueLoadingEl = document.getElementById("groomingQueueLoading");
  const groomingQueueDetailsEl = document.getElementById("groomingQueueDetails");
  const groomingQueueSummaryEl = document.getElementById("groomingQueueSummary");
  const groomingCapacityBadgeEl = document.getElementById("groomingCapacityBadge");
  const groomingCapacityTextEl = document.getElementById("groomingCapacityText");
  const groomingStatusAnnouncement = document.getElementById("groomingStatusAnnouncement");
  let dashboardPets = [];
  let dashboardBookings = [];
  let lastGroomingAnnouncement = "";
  let lastAppointmentsMarkup = "";
  let lastTrackerMarkup = "";
  let lastTrackerMetadataMarkup = "";
  let trackerVisits = [];
  const expandedTrackerVisits = new Set();
  let lastPetsMarkup = "";
  let lastHistoryMarkup = "";
  const rescheduleModal          = document.getElementById("rescheduleModal");
  const closeRescheduleModal     = document.getElementById("closeRescheduleModal");
  const rescheduleBookingRef     = document.getElementById("rescheduleBookingRef");
  const rescheduleDate           = document.getElementById("rescheduleDate");
  const rescheduleSlotsContainer = document.getElementById("rescheduleSlotsContainer");
  const rescheduleMessage        = document.getElementById("rescheduleMessage");
  const submitRescheduleBtn      = document.getElementById("submitRescheduleBtn");
  const cancelModal              = document.getElementById("cancelModal");
  const closeCancelModalBtn      = document.getElementById("closeCancelModal");
  const cancelBookingRefEl       = document.getElementById("cancelBookingRef");
  const confirmCancelBtn         = document.getElementById("confirmCancelBtn");
  const keepBookingBtn           = document.getElementById("keepBookingBtn");
  const cancelMessageEl          = document.getElementById("cancelMessage");

  let activeBookingId     = null;
  let activeRescheduleBooking = null;
  let selectedWindowId    = null;
  let cancelTargetBooking = null;
  let rescheduleLoadId    = 0;
  let dashboardPetsLoadState = "idle";
  let appointmentsLoading = false;
  let appointmentsLoaded = false;
  let groomingCapacityLoading = false;
  let groomingCapacityLoaded = false;
  let rescheduleClinicStatus = {
    stoppedToday: false,
    blockedDates: [],
  };

  const UPCOMING_APPOINTMENT_STATUSES = new Set(["waiting_to_arrive", "waiting"]);
  const RESCHEDULE_MAX_DAYS_AHEAD = 2;

  document.addEventListener("DOMContentLoaded", () => {
    setRescheduleDateRange(getRescheduleTodayKey());
    void loadAppointments();
    if (API.readCustomerCache("/pets?archived=0")) void loadDashboardPets();

    scheduleDashboardDataAfterPaint(async () => {
      await loadDashboardPets();
      scheduleDashboardDataIdle(async () => {
        await loadGroomingCapacity();
        setRescheduleDateRange(getRescheduleTodayKey());
      });
    });
  });

  // ── Load & route data ──────────────────────────────────────────────────────

  async function loadDashboardPets() {
    if (dashboardPetsLoadState === "loading" || dashboardPetsLoadState === "loaded") return;
    dashboardPetsLoadState = "loading";

    try {
      await API.loadCustomerData("/pets?archived=0", () => API.getUserPets({ archived: 0 }), (data) => {
        renderMyPetsSummary(data.pets || []);
      });
      dashboardPetsLoadState = "loaded";
    } catch {
      renderDashboardPanelError(myPetsPreviewEl, "Failed to load pets. Please refresh.");
      dashboardPetsLoadState = "error";
    }
  }

  async function loadAppointments({ force = false } = {}) {
    if (appointmentsLoading) return;
    appointmentsLoading = true;
    try {
      await API.loadCustomerData("/booking/history?history_limit=3",
        () => API.getBookingHistory({ historyLimit: 3, force }), renderAppointmentData, { force });
    } catch (error) {
      if (appointmentsLoaded && (error.status === 0 || error.status >= 500)) return;
      renderDashboardPanelError(appointmentsList, "Failed to load schedule. Please try again.");
      renderDashboardPanelError(groomingTrackerEl, "Failed to load grooming status. Please try again.");
      renderDashboardPanelError(groomingHistoryEl, "Failed to load grooming history. Please try again.");
      trackerSection?.classList.remove("hidden");
      upcomingSection?.classList.remove("hidden");
      return;
    } finally {
      appointmentsLoading = false;
    }
  }

  function renderAppointmentData(data) {
    const active = Array.isArray(data?.bookings) ? data.bookings : [];
    const history = Array.isArray(data?.history) ? data.history : [];
    const scheduled = active.filter(isUpcomingAppointment);
    const atClinic = active.filter(b =>
      ["checked_in", "in_progress", "for_payment", "released"].includes(b?.status)
    ).filter(b => b.show_grooming_tracker !== false);

    dashboardBookings = active;
    const visibleGrooming = atClinic.filter((booking) => trackerPets(booking).length);
    setDashboardHierarchy(visibleGrooming.length > 0, scheduled.length > 0);
    if (dashboardPetsLoadState === "loaded") renderMyPetsPreview();

    renderDashboardPanel(appointmentsList, "schedule", () => {
      renderAppointments(scheduled);
    });
    renderDashboardPanel(groomingTrackerEl, "grooming status", () => {
      renderGroomingTracker(visibleGrooming);
    });
    renderDashboardPanel(groomingHistoryEl, "grooming history", () => {
      renderGroomingHistory(history);
    });
    appointmentsLoaded = true;
  }

  function renderDashboardPanel(target, label, render) {
    try {
      render();
    } catch (error) {
      console.error(`Failed to render customer ${label}.`, error);
      renderDashboardPanelError(target, `Failed to display ${label}. Please refresh.`);
    }
  }

  function renderDashboardPanelError(target, message) {
    if (!target) return;
    if (target === appointmentsList) lastAppointmentsMarkup = "";
    if (target === groomingTrackerEl) lastTrackerMarkup = "";
    if (target === myPetsPreviewEl) lastPetsMarkup = "";
    if (target === groomingHistoryEl) lastHistoryMarkup = "";
    if (target === groomingTrackerEl) {
      if (groomingTrackerMetadataEl) groomingTrackerMetadataEl.innerHTML = "";
      lastTrackerMetadataMarkup = "";
    }

    target.innerHTML = `
      <div class="flex min-h-[140px] flex-col items-center justify-center py-[22px] text-center" role="alert">
        <svg class="ph-icon w-9 h-9 mx-auto text-portal-muted-icon" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#warning-circle"></use></svg>
        <p class="mt-3 text-sm text-portal-danger">${escapeDashboardHtml(message)}</p>
      </div>`;
  }

  // ── Appointments section ───────────────────────────────────────────────────

  async function loadGroomingCapacity({ force = false } = {}) {
    if (groomingCapacityLoading) return;
    groomingCapacityLoading = true;

    try {
      await API.loadCustomerData("/booking/grooming-capacity", () => API.getGroomingCapacity({ force }), (data) => {
        renderGroomingCapacity(data);
        groomingCapacityLoaded = true;
      }, { force });
    } catch (error) {
      if (groomingCapacityLoaded && (error.status === 0 || error.status >= 500)) return;
      renderGroomingCapacity(null);
    } finally {
      groomingCapacityLoading = false;
    }
  }

  function getRescheduleTodayKey() {
    return new Intl.DateTimeFormat("en-CA", {
      timeZone: "Asia/Manila",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    }).format(new Date());
  }

  function addDaysToDateKey(dateKey, days) {
    const [year, month, day] = String(dateKey).split("-").map(Number);
    const date = new Date(year, month - 1, day);
    date.setDate(date.getDate() + days);

    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, "0"),
      String(date.getDate()).padStart(2, "0"),
    ].join("-");
  }

  function setRescheduleDateRange(todayKey) {
    if (!rescheduleDate || !todayKey) return;

    rescheduleDate.min = todayKey;
    rescheduleDate.max = addDaysToDateKey(todayKey, RESCHEDULE_MAX_DAYS_AHEAD);
  }

  function renderMyPetsSummary(pets) {
    dashboardPets = Array.isArray(pets) ? pets : [];
    renderMyPetsPreview();
  }

  function petIcon(pet, classes = "h-6 w-6") {
    const icon = String(pet?.species || "").toLowerCase() === "cat" ? "cat" : "dog";
    return `<svg class="ph-icon ${classes}" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#${icon}"></use></svg>`;
  }

  function petAge(birthdate) {
    if (!birthdate) return "";
    const birth = String(birthdate).slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(birth)) return "";
    const [year, month, day] = birth.split("-").map(Number);
    const [todayYear, todayMonth, todayDay] = getRescheduleTodayKey().split("-").map(Number);
    if (birth > getRescheduleTodayKey()) return "";
    const years = todayYear - year - (todayMonth < month || (todayMonth === month && todayDay < day) ? 1 : 0);
    return years > 0 ? `${years} year${years === 1 ? "" : "s"}` : "Under 1 year";
  }

  function renderMyPetsPreview() {
    if (!myPetsPreviewEl) return;
    if (!dashboardPets.length) {
      myPetsPreviewEl.innerHTML = '<p class="py-4 text-sm text-portal-muted">No pets registered yet.</p>';
      return;
    }
    const markup = dashboardPets.slice(0, 3).map((pet) => {
      const breed = pet.breed && !["—", "-"].includes(pet.breed) ? pet.breed : "";
      const details = [breed, petAge(pet.birthdate)].filter(Boolean).join(" · ");
      const matching = dashboardBookings.filter((booking) =>
        (booking.pets || []).some((record) => String(record.pet_id) === String(pet.pet_id))
      );
      const booking = matching.find((booking) => ["checked_in", "in_progress", "for_payment", "released"].includes(booking.status) && booking.show_grooming_tracker !== false)
        || matching.find(isUpcomingAppointment);
      const bookingPet = booking?.pets?.find((record) => String(record.pet_id) === String(pet.pet_id));
      const status = bookingPet?.clinic_referred === true ? null : booking ? getPetGroomingStatus(bookingPet, booking) : null;
      const label = booking && isUpcomingAppointment(booking) ? "Upcoming visit" : status ? getStatusConfig(status).label : "No visits scheduled";
      const tone = status ? getStatusConfig(status).textClass : "text-portal-muted";
      return `<div class="flex flex-wrap items-center gap-3 border-b border-portal-border py-4 first:pt-0 last:border-0 last:pb-0">
        <div class="portal-icon-tile h-11 w-11 rounded-full">${petIcon(pet)}</div>
        <div class="min-w-0 flex-1 [overflow-wrap:anywhere]">
          <p class="font-semibold text-portal-text">${escapeDashboardHtml(pet.pet_name)}</p>
          ${details ? `<p class="mt-1 text-sm text-portal-muted">${escapeDashboardHtml(details)}</p>` : ""}
        </div>
        <span class="text-xs ${tone}">${escapeDashboardHtml(label)}</span>
      </div>`;
    }).join("");
    if (markup !== lastPetsMarkup) {
      myPetsPreviewEl.innerHTML = markup;
      lastPetsMarkup = markup;
    }
  }

  function setDashboardHierarchy(hasGrooming, hasSchedule) {
    upcomingSection?.classList.toggle("hidden", !hasGrooming && !hasSchedule);
  }

  function renderAppointments(bookings) {
    const preview = [...bookings].sort((a, b) =>
      String(a.booking_date || "").localeCompare(String(b.booking_date || "")) ||
      scheduleStartMinutes(a) - scheduleStartMinutes(b)
    ).slice(0, 3);
    const markup = preview.length ? preview.map(buildBookingCard).join("") : `
      <div class="w-full rounded-[14px] border border-portal-border bg-portal-record px-5 py-6 text-center">
        <p class="text-sm font-semibold text-portal-text">No upcoming grooming scheduled</p>
        <p class="mt-2 text-sm leading-relaxed text-portal-muted">Your next grooming visit will appear here once scheduled.</p>
      </div>`;
    // Polls with unchanged schedules must not replace focused action buttons.
    if (lastAppointmentsMarkup === markup) return;
    appointmentsList.innerHTML = markup;
    lastAppointmentsMarkup = markup;
    preview.forEach((booking) => {
      const currentBooking = () => dashboardBookings.find((record) => record.booking_id === booking.booking_id) || booking;
      document.getElementById(`reschedule-${booking.booking_id}`)?.addEventListener("click", () => openRescheduleModal(currentBooking()));
      document.getElementById(`cancel-${booking.booking_id}`)?.addEventListener("click", () => handleCancel(currentBooking()));
    });
  }

  function scheduleStartMinutes(booking) {
    const match = String(booking.time_window?.window_label || "").match(/^(\d{1,2}):(\d{2})\s*([AP]M)/i);
    if (!match) return 24 * 60;
    return (Number(match[1]) % 12 + (match[3].toUpperCase() === "PM" ? 12 : 0)) * 60 + Number(match[2]);
  }

  function serviceLabel(booking) {
    return [...new Set((booking.pets || []).flatMap((pet) =>
      (pet.services || []).map((service) => service.service_name).filter(Boolean)
    ))].join(", ") || "Grooming";
  }

  function buildBookingCard(booking) {
    const status = getStatusConfig(booking.status);
    if (booking.status === "waiting_to_arrive" && booking.arrival_time_passed) status.label = "Arrival time passed";
    const actionable = booking.status === "waiting_to_arrive" && !booking.paid;
    const names = getPetNamesLabel(booking);
    return `<article class="flex flex-wrap items-center gap-4 rounded-[14px] bg-portal-surface-soft p-4 [overflow-wrap:anywhere] mb-3 last:mb-0">
      <div class="portal-icon-tile h-11 w-11 rounded-full">${petIcon(booking.pets?.[0])}</div>
      <div class="min-w-0 flex-1">
        <p class="text-base font-semibold text-portal-text">${escapeDashboardHtml(names)}</p>
        <p class="mt-1 text-sm text-portal-text">${escapeDashboardHtml(serviceLabel(booking))}</p>
        <p class="mt-1 text-sm text-portal-muted">${formatDate(booking.booking_date)} · ${escapeDashboardHtml(booking.time_window?.window_label || "Time to be confirmed")}</p>
        <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ${status.classes}">${escapeDashboardHtml(status.label)}</span>
        <div>${visitSummaryAction(booking, "View drop-off summary")}</div>
      </div>
      ${actionable ? `<div class="flex flex-wrap gap-2 max-[639px]:basis-full">
        <button type="button" id="reschedule-${escapeDashboardHtml(booking.booking_id)}" class="portal-button-secondary min-h-10 whitespace-nowrap px-3 py-2 text-xs" aria-label="Reschedule ${escapeDashboardHtml(names)}">Reschedule</button>
        <button type="button" id="cancel-${escapeDashboardHtml(booking.booking_id)}" class="min-h-10 whitespace-nowrap rounded-2xl border border-portal-border px-3 py-2 text-xs font-semibold text-portal-danger transition-colors hover:bg-portal-danger-soft" aria-label="Cancel ${escapeDashboardHtml(names)}">Cancel</button>
      </div>` : ""}
    </article>`;
  }

  // ── Grooming Tracker section ───────────────────────────────────────────────

  function visitSummaryAction(booking, label) {
    return `<button type="button" id="visit-summary-${escapeDashboardHtml(booking.booking_id)}" data-visit-summary="${escapeDashboardHtml(booking.booking_id)}" class="grooming-summary-action" aria-haspopup="dialog">${label}<span class="sr-only"> for ${escapeDashboardHtml(getPetNamesLabel(booking))}</span></button>`;
  }

  async function openVisitSummary(event) {
    const trigger = event.target.closest("[data-visit-summary]");
    if (!trigger) return;
    const { openGroomingVisitSummary } = await import("./grooming-visit-summary.js?v=20261017&catalog=services-20261017-2&services=20261026");
    openGroomingVisitSummary(trigger.dataset.visitSummary, trigger, (booking) => {
      if (isUpcomingAppointment(booking)) return getStatusConfig("waiting_to_arrive").label;
      if (!["checked_in", "in_progress", "for_payment", "released"].includes(booking.status)) return getStatusConfig(booking.status).label;
      const statuses = trackerPets(booking).map((pet) => getTrackerPetStatus(pet, booking));
      return getStatusConfig(trackerVisitStatus(statuses)).label;
    });
  }

  appointmentsList.addEventListener("click", openVisitSummary);
  groomingTrackerEl.addEventListener("click", openVisitSummary);

  function renderGroomingCapacity(data) {
    groomingQueueLoadingEl?.classList.add("hidden");
    groomingQueueDetailsEl?.classList.remove("hidden");
    groomingQueueDetailsEl?.classList.add("flex");
    const capacity = data?.capacity || {};
    const queue = data?.queue || {};
    const max = toNumber(capacity.max) || 20;
    const used = toNumber(capacity.used);
    const remaining = Math.max(0, toNumber(capacity.remaining ?? (max - used)));
    const percent = Math.max(0, Math.min(100, toNumber(capacity.percent ?? ((used / max) * 100))));
    const queued = toNumber(queue.queued);
    const inProgress = toNumber(queue.in_progress);
    const active = toNumber(queue.active ?? (queued + inProgress));
    const isFull = Boolean(capacity.is_full) || used >= max;
    const isBusy = !isFull && percent >= 80;

    if (!data) {
      groomingQueueSummaryEl.textContent = "Unable to load today’s queue";
      groomingCapacityTextEl.textContent = "Please refresh to check Grooming capacity.";
      groomingCapacityBadgeEl.textContent = "Unavailable";
      groomingCapacityBadgeEl.className = "inline-flex self-start rounded-full bg-portal-active px-3 py-1 text-xs font-semibold text-portal-muted";
      return;
    }

    groomingQueueSummaryEl.textContent = active
      ? `${active} pet${active === 1 ? "" : "s"} currently in the grooming queue`
      : "No pets in the grooming queue right now";
    groomingCapacityTextEl.textContent = isFull
      ? "Grooming capacity is currently full."
      : `${used}/${max} Grooming pets on-site. ${remaining} space${remaining === 1 ? "" : "s"} available now.`;
    groomingCapacityBadgeEl.textContent = isFull ? "Full" : isBusy ? "Nearly Full" : "Open";
    groomingCapacityBadgeEl.className = `inline-flex self-start rounded-full px-3 py-1 text-xs font-semibold ${isFull
      ? "bg-portal-danger-soft text-portal-danger"
      : isBusy ? "bg-portal-warning-soft text-portal-warning"
      : "bg-portal-success-soft text-portal-success"}`;
  }

  function toNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
  }

  function isUpcomingAppointment(booking) {
    return UPCOMING_APPOINTMENT_STATUSES.has(String(booking?.status || "").toLowerCase());
  }

  function getPetNamesLabel(booking) {
    const petNames = (booking.pets || [])
      .map((pet) => pet.pet_name)
      .filter(Boolean)
      .join(", ");

    if (petNames) return petNames;

    const count = booking.number_of_pets || 0;
    return count ? `${count} pet${count === 1 ? "" : "s"}` : "Your pet";
  }

  function getPetGroomingStatus(pet, booking) {
    // Pickup readiness remains booking-controlled, even if one pet has finished.
    if (["for_payment", "released"].includes(booking.status)) return booking.status;
    return pet?.grooming_status || booking.status;
  }

  function trackerPets(booking) {
    return (booking.pets || []).filter((pet) => pet.clinic_referred !== true && pet.grooming_status !== "cancelled");
  }

  function getTrackerPetStatus(pet, booking) {
    const status = getPetGroomingStatus(pet, booking);
    // The API can inherit the visit's in_progress status for an unstarted pet.
    if (status === "in_progress" && pet.grooming_started_timestamp === null && pet.grooming_started_at === null) {
      return "checked_in";
    }
    return status;
  }

  groomingTrackerEl.addEventListener("click", (event) => {
    const button = event.target.closest("[data-tracker-toggle]");
    if (!button) return;
    const id = button.dataset.trackerToggle;
    if (expandedTrackerVisits.has(id)) expandedTrackerVisits.delete(id);
    else expandedTrackerVisits.add(id);
    renderGroomingTracker(trackerVisits);
    document.getElementById(button.id)?.focus({ preventScroll: true });
  });

  function trackerVisitStatus(statuses) {
    const ready = statuses.filter((status) => ["for_payment", "released"].includes(status)).length;
    return ready === statuses.length ? "for_payment"
      : ready || statuses.some((status) => ["in_progress", "grooming_finished"].includes(status)) ? "in_progress" : "checked_in";
  }

  function renderGroomingTracker(bookings) {
    trackerVisits = bookings;
    const metadataMarkup = bookings.length ? buildTrackerMetadata(bookings[0]) : "";
    if (metadataMarkup !== lastTrackerMetadataMarkup) {
      if (groomingTrackerMetadataEl) groomingTrackerMetadataEl.innerHTML = metadataMarkup;
      lastTrackerMetadataMarkup = metadataMarkup;
    }
    const markup = bookings.length ? bookings.map(buildTrackerCard).join("") : `
      <div class="flex min-h-[160px] flex-1 flex-col items-center justify-center rounded-[14px] border border-portal-border bg-portal-record px-5 py-6 text-center">
        <p class="text-sm font-semibold text-portal-text">No grooming in progress</p>
        <p class="mt-2 text-sm leading-relaxed text-portal-muted">Your pet’s status will appear here after clinic check-in.</p>
      </div>`;
    if (lastTrackerMarkup !== markup) {
      groomingTrackerEl.innerHTML = markup;
      lastTrackerMarkup = markup;
    }
    const announcement = bookings.flatMap((booking) => trackerPets(booking).map((pet) =>
      `${pet.pet_name}: ${getStatusConfig(getPetGroomingStatus(pet, booking)).label}. ${petEstimateStatus(pet, booking)}`
    )).join(". ") || "No pets at the clinic right now.";
    if (announcement !== lastGroomingAnnouncement) {
      if (groomingStatusAnnouncement) groomingStatusAnnouncement.textContent = announcement;
      lastGroomingAnnouncement = announcement;
    }
  }

  function buildTrackerMetadata(booking) {
    const paidAt = booking.payment_summary?.paid_at;
    const finishedAt = booking.grooming_finished_timestamp;
    const prePaid = paidAt && finishedAt
      ? new Date(paidAt) < new Date(finishedAt)
      : ["checked_in", "in_progress"].includes(booking.status);
    return `<div class="flex flex-col items-end gap-1.5 text-xs text-portal-muted">
      ${booking.paid ? `<span class="rounded-full bg-portal-success-soft px-2.5 py-1 font-semibold text-portal-success">${prePaid ? "Pre-Paid" : "Paid"}</span>` : ""}
      <span class="[overflow-wrap:anywhere]">Ref. ${escapeDashboardHtml(booking.booking_reference || "")}</span>
    </div>`;
  }

  function buildTrackerCard(booking, index) {
    const pets = trackerPets(booking);
    const statuses = pets.map((pet) => getTrackerPetStatus(pet, booking));
    const readyStatuses = ["for_payment", "released"];
    const ready = statuses.filter((status) => readyStatuses.includes(status)).length;
    const grooming = statuses.filter((status) => status === "in_progress").length;
    const finished = statuses.filter((status) => status === "grooming_finished").length;
    const waiting = pets.length - ready - grooming - finished;
    const status = trackerVisitStatus(statuses);
    const current = { checked_in: 0, in_progress: 1, for_payment: 2 }[status];
    const config = getStatusConfig(status);
    let summary;
    if (ready === pets.length) summary = pets.length === 1 ? "Your pet is ready for pickup" : `All ${pets.length} pets are ready for pickup`;
    else if (waiting === pets.length) summary = pets.length === 1 ? "Your pet is waiting to start grooming" : `All ${pets.length} pets are waiting to start grooming`;
    else if (pets.length === 1 && grooming) summary = "Your pet is currently being groomed";
    else if (pets.length === 2 && grooming) summary = `${grooming} of ${pets.length} pets ${grooming === 1 ? "is" : "are"} currently being groomed`;
    else summary = [[grooming, "grooming"], [ready, "ready"], [finished, "finished"], [waiting, "waiting"]]
      .filter(([count]) => count).map(([count, label]) => `${count} ${label}`).join(" · ");

    const priority = (pet) => ({ for_payment: 0, released: 0, in_progress: 1, grooming_finished: 2 }[getTrackerPetStatus(pet, booking)] ?? 3);
    const orderedPets = pets.length > 4 ? pets.slice().sort((a, b) => priority(a) - priority(b)) : pets;
    const id = escapeDashboardHtml(String(booking.booking_id));
    const expanded = expandedTrackerVisits.has(String(booking.booking_id));
    const visiblePets = expanded ? orderedPets : orderedPets.slice(0, 4);
    const steps = ["Checked In", "Grooming in Progress", "Ready for Pickup"].map((label, index) => {
      const done = index < current;
      const circle = done ? "border-portal-success bg-portal-success text-white"
        : index === current ? "border-portal-primary bg-portal-primary text-white"
        : "border-portal-border bg-portal-surface text-portal-muted";
      const line = index < current ? "bg-portal-success" : "bg-portal-border";
      return `<li class="relative min-w-0 text-center" ${index === current ? 'aria-current="step"' : ""}>
        ${index < 2 ? `<span class="absolute left-1/2 top-5 h-0.5 w-full ${line}" aria-hidden="true"></span>` : ""}
        <span class="relative mx-auto flex h-10 w-10 items-center justify-center rounded-full border-2 text-sm font-bold ${circle}" aria-hidden="true">${done
          ? '<svg class="ph-icon h-5 w-5" viewBox="0 0 256 256"><use href="../../assets/icons/phosphor.svg#check-circle"></use></svg>'
          : index + 1}</span>
        <span class="relative mt-2 block px-1 text-xs leading-snug ${index === current ? "font-semibold text-portal-text" : "text-portal-muted"}">${label}<span class="sr-only">${done ? ", complete" : index === current ? ", current" : ", upcoming"}</span></span>
      </li>`;
    }).join("");
    return `<article class="border-b border-portal-border pb-5 mb-5 last:border-0 last:pb-0 last:mb-0">
        ${index > 0 ? `<div class="mb-4 flex justify-end">${buildTrackerMetadata(booking)}</div>` : ""}
        <div class="flex items-center gap-4">
          <div class="portal-icon-tile rounded-full">${petIcon(pets[0], "h-7 w-7")}</div>
          <div class="min-w-0 [overflow-wrap:anywhere]">
            <h4 class="text-[28px] font-semibold leading-tight text-portal-text max-[639px]:text-2xl">${escapeDashboardHtml(config.label)}</h4>
            <p class="mt-2 text-sm text-portal-muted">${escapeDashboardHtml(summary)}</p>
          </div>
        </div>
        <div class="mt-5 flex items-center justify-between gap-3">
          <h5 class="text-sm font-semibold text-portal-text">Your pets</h5>
          <span class="text-xs text-portal-muted">${pets.length} pet${pets.length === 1 ? "" : "s"}</span>
        </div>
        <div id="tracker-pets-${id}" class="mt-3 grid gap-3 sm:grid-cols-2">${visiblePets.map((pet) => buildTrackerPetCard(pet, booking)).join("")}</div>
        ${pets.length > 4 ? `<div class="mt-3 flex flex-wrap items-center justify-between gap-3">
          <p class="text-xs text-portal-muted">Showing ${visiblePets.length} of ${pets.length} pets</p>
          <button id="tracker-toggle-${id}" type="button" data-tracker-toggle="${id}" aria-controls="tracker-pets-${id}" aria-expanded="${expanded}" class="portal-button-secondary px-4">${expanded ? "Show fewer" : `Show all ${pets.length} pets`}</button>
        </div>` : ""}
        <ol class="mt-6 grid grid-cols-3" aria-label="Queue status for this grooming visit">${steps}</ol>
        <div class="mt-3">${visitSummaryAction({ ...booking, pets }, "View visit details")}</div>
    </article>`;
  }

  function petEstimateStatus(pet, booking) {
    const estimate = pet.grooming_estimate;
    const status = getPetGroomingStatus(pet, booking);
    if (!estimate || ["grooming_finished", "for_payment", "released"].includes(status)) return "";
    const ready = pet.grooming_started_timestamp ? window.GroomingEstimates.readyWindow(estimate, pet.grooming_started_timestamp) : null;
    return ready ? (ready.overdue ? ready.label : `Estimated ready ${ready.label}`) : `Estimated grooming time ${estimate.formatted}`;
  }

  function buildTrackerPetCard(pet, booking) {
    const status = getTrackerPetStatus(pet, booking);
    const config = getStatusConfig(status);
    const label = petEstimateStatus(pet, booking)
      .replace(/^Estimated grooming time /, "Est. grooming: ").replace(/^Estimated ready /, "Est. ready: ");
    const context = status === "checked_in" ? "Waiting to start grooming"
      : status === "in_progress" && pet.grooming_started_at ? `Started at ${pet.grooming_started_at}`
      : status === "grooming_finished" ? "Waiting for the grooming visit to be ready for pickup" : "";
    return `<div data-tracker-pet class="min-w-0 rounded-xl border border-portal-border bg-portal-record p-3 text-sm [overflow-wrap:anywhere]">
      <h6 class="font-semibold text-portal-text">${escapeDashboardHtml(pet.pet_name || "Your pet")}</h6>
      <p class="mt-1 text-xs font-semibold ${config.textClass}">${escapeDashboardHtml(config.label)}</p>
      ${context ? `<p class="mt-2 text-portal-muted">${escapeDashboardHtml(context)}</p>` : ""}
      ${label ? `<p class="mt-1 font-semibold text-portal-text">${escapeDashboardHtml(label)}</p>` : ""}
    </div>`;
  }

  function renderGroomingHistory(history) {
    const markup = history.length
      ? history.slice(0, 3).map(buildHistoryCard).join("")
      : '<p class="py-4 text-sm text-portal-muted">No grooming visits yet.</p>';
    if (markup !== lastHistoryMarkup) {
      groomingHistoryEl.innerHTML = markup;
      lastHistoryMarkup = markup;
    }
  }

  function buildHistoryCard(booking) {
    const badge = booking.paid ? { label: "Paid", classes: "bg-portal-success-soft text-portal-success" }
      : getStatusConfig(booking.status || "archived");
    return `<article class="mb-3 flex flex-wrap items-center gap-3 rounded-[14px] bg-portal-surface-soft p-3 last:mb-0 [overflow-wrap:anywhere]">
      <div class="portal-icon-tile h-10 w-10 rounded-[13px] bg-portal-surface"><svg class="ph-icon h-6 w-6" viewBox="0 0 256 256" aria-hidden="true"><use href="../../assets/icons/phosphor.svg#scissors"></use></svg></div>
      <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-portal-text">${escapeDashboardHtml(getPetNamesLabel(booking))} · ${escapeDashboardHtml(serviceLabel(booking))}</p>
        <p class="mt-1 text-xs text-portal-muted">${formatDate(booking.booking_date)}</p>
      </div>
      <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold ${badge.classes}">${escapeDashboardHtml(badge.label)}</span>
    </article>`;
  }

  function escapeDashboardHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (character) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[character]);
  }

  // ── Status config ──────────────────────────────────────────────────────────

  function getStatusConfig(status) {
    const map = {
      waiting_to_arrive: { label: "Waiting to arrive", classes: "bg-portal-active text-portal-primary" },
      waiting:           { label: "Waiting to arrive", classes: "bg-portal-active text-portal-primary" },
      grooming_finished: { label: "Grooming finished", classes: "bg-portal-success-soft text-portal-success" },
      checked_in:        { label: "Checked In",         classes: "bg-portal-warning-soft text-portal-warning" },
      in_progress:       { label: "Grooming in Progress",        classes: "bg-portal-warning-soft text-portal-warning" },
      for_payment:       { label: "Ready for Pickup",   classes: "bg-portal-success-soft text-portal-success" },
      released:          { label: "Ready for Pickup",   classes: "bg-portal-success-soft text-portal-success" },
      cancelled:         { label: "Cancelled",          classes: "bg-portal-danger-soft text-portal-danger" },
      no_show:           { label: "Expired",            classes: "bg-slate-100 text-portal-muted" },
      expired:           { label: "Expired",            classes: "bg-slate-100 text-portal-muted" },
      archived:          { label: "Completed",          classes: "bg-portal-success-soft text-portal-success" },
    };
    const config = map[status] || { label: status, classes: "bg-portal-active text-portal-muted" };
    return { ...config, textClass: config.classes.split(" ").find((className) => className.startsWith("text-")) };
  }

  // ── Reschedule modal ───────────────────────────────────────────────────────

  rescheduleDate.addEventListener("change", async function () {
    const requestId = ++rescheduleLoadId;
    selectedWindowId = null;
    hideRescheduleMessage();
    enableSubmitIfReady();
    const date = this.value;
    if (!date) return;

    const dateError = getRescheduleDateError(date);
    this.setCustomValidity(dateError || "");
    if (dateError) {
      rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-portal-muted">Choose another date to see available slots.</p>';
      showRescheduleMessage("error", dateError);
      return;
    }

    rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-portal-muted">Loading slots...</p>';
    try {
      const data = await API.getTimeslots(date, activeBookingId);
      if (requestId !== rescheduleLoadId) return;

      renderSlots(data);
    } catch (error) {
      if (requestId !== rescheduleLoadId) return;
      rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-portal-danger">Failed to load slots. Try again.</p>';
    }
  });

  submitRescheduleBtn.addEventListener("click", async () => {
    if (!activeBookingId || !selectedWindowId || !rescheduleDate.value) return;

    const dateError = getRescheduleDateError(rescheduleDate.value);
    if (dateError || isCurrentRescheduleSelection(rescheduleDate.value, selectedWindowId)) {
      showRescheduleMessage(
        "error",
        dateError || "Choose a different date or time slot from the current schedule.",
      );
      enableSubmitIfReady();
      return;
    }

    submitRescheduleBtn.disabled = true;
    submitRescheduleBtn.textContent = "Rescheduling...";
    try {
      const data = await API.rescheduleBooking(activeBookingId, rescheduleDate.value, selectedWindowId);
      showRescheduleMessage(
        "success",
        `Visit rescheduled to ${formatDate(data.booking.booking_date)} at ${data.booking.window}.`,
      );
      submitRescheduleBtn.textContent = "Confirm Reschedule";
      submitRescheduleBtn.disabled = true;
      setTimeout(async () => { closeModal(); await loadAppointments(); }, 1800);
    } catch (error) {
      showRescheduleMessage("error", error.message || "Reschedule failed. Please try again.");
      if (error.code === "grooming_forecast_unavailable") {
        selectedWindowId = null;
        renderSlots(error.data);
        enableSubmitIfReady();
      } else submitRescheduleBtn.disabled = false;
      submitRescheduleBtn.textContent = "Confirm Reschedule";
    }
  });

  closeRescheduleModal.addEventListener("click", closeModal);
  rescheduleModal.addEventListener("click", (e) => { if (e.target === rescheduleModal) closeModal(); });

  async function openRescheduleModal(booking) {
    const requestId = ++rescheduleLoadId;
    activeBookingId  = booking.booking_id;
    activeRescheduleBooking = booking;
    selectedWindowId = null;
    rescheduleBookingRef.textContent =
      `Rescheduling: ${booking.booking_reference} (${booking.reschedule_count ?? 0} of 2 uses)`;
    rescheduleDate.value = "";
    rescheduleDate.setCustomValidity("");
    rescheduleDate.disabled = true;
    rescheduleSlotsContainer.innerHTML = '<p class="text-sm text-portal-muted">Select a date to see available slots.</p>';
    hideRescheduleMessage();
    enableSubmitIfReady();
    rescheduleModal.classList.remove("hidden");
    rescheduleModal.classList.add("flex");

    try {
      const data = await API.getClinicStatus();
      if (requestId !== rescheduleLoadId || activeBookingId !== booking.booking_id) return;

      setRescheduleDateRange(getRescheduleTodayKey());
      rescheduleClinicStatus = {
        stoppedToday: Boolean(data.stopped_today),
        blockedDates: Array.isArray(data.blocked_dates) ? data.blocked_dates : [],
      };
      rescheduleDate.disabled = false;
    } catch {
      if (requestId !== rescheduleLoadId || activeBookingId !== booking.booking_id) return;

      showRescheduleMessage("error", "Could not load the current scheduling rules. Close this window and try again.");
    }
  }

  function closeModal() {
    rescheduleLoadId += 1;
    rescheduleModal.classList.add("hidden");
    rescheduleModal.classList.remove("flex");
    activeBookingId  = null;
    activeRescheduleBooking = null;
    selectedWindowId = null;
    rescheduleDate.disabled = false;
    rescheduleDate.setCustomValidity("");
    submitRescheduleBtn.textContent = "Confirm Reschedule";
  }

  function renderSlots(data) {
    const windows = Array.isArray(data.windows) ? data.windows : [];
    const selectedDate = rescheduleDate.value;
    const bookingDate = String(activeRescheduleBooking?.booking_date || "");
    const sameDate = selectedDate === bookingDate;
    const available = windows.filter((window) =>
      !window.is_closed
      && !isRescheduleSlotPast(window, selectedDate)
      && !isCurrentRescheduleSelection(selectedDate, window.window_id, window.window_label)
    );

    if (!available.length) {
      rescheduleSlotsContainer.innerHTML = `<p class="text-sm text-portal-muted">${
        sameDate
          ? "No other available time slots on this date."
          : "No available time slots on this date."
      }</p>`;
      return;
    }

    rescheduleSlotsContainer.innerHTML = available.map(w => `
      <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 ${w.is_workload_unavailable ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:border-[#315b7e]'} has-[:checked]:border-[#315b7e] has-[:checked]:bg-[#eaf4fb]">
        <input type="radio" name="rescheduleSlot" value="${w.window_id}" class="accent-[#315b7e]" ${w.is_workload_unavailable ? 'disabled' : ''} />
        <span class="text-sm text-portal-text">${escapeRescheduleHtml(w.window_label)}${w.is_workload_unavailable ? ' · Unavailable' : ''}</span>
      </label>`).join("");
    rescheduleSlotsContainer.querySelectorAll('input[name="rescheduleSlot"]').forEach(radio => {
      radio.addEventListener("change", () => {
        selectedWindowId = parseInt(radio.value, 10);
        enableSubmitIfReady();
      });
    });
  }

  function enableSubmitIfReady() {
    const ready = !!rescheduleDate.value
      && !!selectedWindowId
      && !getRescheduleDateError(rescheduleDate.value)
      && !isCurrentRescheduleSelection(rescheduleDate.value, selectedWindowId);
    submitRescheduleBtn.disabled = !ready;
    submitRescheduleBtn.className = ready
      ? "w-full rounded-xl bg-portal-primary px-4 py-3 text-sm font-semibold text-white hover:bg-portal-primary-hover transition"
      : "w-full rounded-xl bg-slate-300 px-4 py-3 text-sm font-semibold text-white cursor-not-allowed transition";
  }

  function showRescheduleMessage(type, text) {
    const styles = { success: "border-green-200 bg-portal-success-soft text-portal-success", error: "border-red-200 bg-portal-danger-soft text-portal-danger" };
    rescheduleMessage.className = `mb-4 rounded-xl border px-4 py-3 text-sm ${styles[type]}`;
    rescheduleMessage.textContent = text;
    rescheduleMessage.classList.remove("hidden");
  }

  function hideRescheduleMessage() {
    rescheduleMessage.classList.add("hidden");
    rescheduleMessage.textContent = "";
  }

  function getRescheduleDateError(dateKey) {
    if (!dateKey) return "";

    const today = rescheduleDate.min || getRescheduleTodayKey();
    const lastAvailableDate = rescheduleDate.max
      || addDaysToDateKey(today, RESCHEDULE_MAX_DAYS_AHEAD);

    if (dateKey < today) {
      return "Past dates are not available for rescheduling.";
    }

    if (dateKey > lastAvailableDate) {
      return "Grooming can be pre-registered up to three days in advance.";
    }

    if (rescheduleClinicStatus.stoppedToday && dateKey === today) {
      return "The clinic is not accepting grooming pre-registrations today.";
    }

    const closure = rescheduleClinicStatus.blockedDates.find((block) =>
      dateKey >= block.start_date && dateKey <= block.end_date
    );
    if (closure) {
      return closure.reason || "The clinic is not accepting grooming pre-registrations on this date.";
    }

    return "";
  }

  function isRescheduleSlotPast(windowData, dateKey) {
    if (windowData.is_past) return true;
    if (dateKey !== getRescheduleTodayKey()) return false;

    const currentTime = new Date();
    const end = new Date(`${dateKey}T${windowData.end_time}+08:00`);
    return windowData.latest_arrival_time ? currentTime > end : currentTime >= end;
  }

  function isCurrentRescheduleSelection(dateKey, windowId, windowLabel = null) {
    if (!activeRescheduleBooking || dateKey !== String(activeRescheduleBooking.booking_date || "")) {
      return false;
    }

    const currentWindowId = activeRescheduleBooking.time_window?.window_id;
    if (currentWindowId !== null && currentWindowId !== undefined) {
      return Number(currentWindowId) === Number(windowId);
    }

    return Boolean(windowLabel)
      && String(activeRescheduleBooking.time_window?.window_label || "") === String(windowLabel);
  }
  function escapeRescheduleHtml(value) {
    return String(value || "").replace(/[&<>"']/g, (character) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[character]);
  }

  function handleCancel(booking) {
    openCancelModal(booking);
  }

  function openCancelModal(booking) {
    cancelTargetBooking = booking;
    cancelBookingRefEl.textContent = booking.booking_reference;
    hideCancelMessage();
    confirmCancelBtn.disabled = false;
    confirmCancelBtn.textContent = "Confirm cancellation";
    cancelModal.classList.remove("hidden");
    cancelModal.classList.add("flex");
  }

  function closeCancelModal() {
    cancelModal.classList.add("hidden");
    cancelModal.classList.remove("flex");
    cancelTargetBooking = null;
  }

  function showCancelMessage(type, text) {
    const styles = { error: "border-red-200 bg-portal-danger-soft text-portal-danger" };
    cancelMessageEl.className = `mb-4 rounded-xl border px-4 py-3 text-sm ${styles[type] || styles.error}`;
    cancelMessageEl.textContent = text;
    cancelMessageEl.classList.remove("hidden");
  }

  function hideCancelMessage() {
    cancelMessageEl.classList.add("hidden");
    cancelMessageEl.textContent = "";
  }

  closeCancelModalBtn.addEventListener("click", closeCancelModal);
  keepBookingBtn.addEventListener("click", closeCancelModal);
  cancelModal.addEventListener("click", (e) => { if (e.target === cancelModal) closeCancelModal(); });

  confirmCancelBtn.addEventListener("click", async () => {
    if (!cancelTargetBooking) return;
    confirmCancelBtn.disabled = true;
    confirmCancelBtn.textContent = "Cancelling...";
    try {
      await API.cancelBooking(cancelTargetBooking.booking_id);
      closeCancelModal();
      await loadAppointments();
    } catch (error) {
      showCancelMessage("error", error.message || "Cancellation failed. Please try again.");
      confirmCancelBtn.disabled = false;
      confirmCancelBtn.textContent = "Confirm cancellation";
    }
  });

  function formatDate(dateStr) {
    if (!dateStr) return "—";
    const d = new Date(dateStr + "T00:00:00");
    return d.toLocaleDateString("en-PH", { year: "numeric", month: "long", day: "numeric" });
  }

  // Expose for coordination with the notification poller (pickup popup sequencing).
  window._refreshAppointments = loadAppointments;
  setInterval(() => {
    if (document.visibilityState === "visible") void loadAppointments({ force: true });
  }, 15000);
  setInterval(() => {
    if (document.visibilityState === "visible") void loadGroomingCapacity({ force: true });
  }, 15000);
})();
