import { formatBookingSchedule } from "../services/booking-format-service.js";

/**
 * Booking Calendar Component
 *
 * Handles the arrival step (Grooming Step 3) after pets and services:
 * - Calendar display and date selection
 * - Time slot selection fetched from GET /api/timeslots?date=
 * - PH time validation and clinic closure blocking
 * - 3-day booking window
 *
 * Stores selected schedule in sessionStorage as:
 * { date, time, startHour, endHour, window_id }
 * window_id is required by the booking submission payload.
 */

const MAX_BOOKING_DAYS_AHEAD = 3;

// =========================
// STATE
// =========================

const state = {
  currentMonth: null,
  selectedDateKey: null,
  selectedSlot: null,   // { window_id, window_label, start_time, end_time }
  timeslots: [],        // API response for the selected date
  clinicStatus: {
    stoppedToday: false,
    blockedDates: [],   // [{ id, start_date, end_date, reason }]
    availability: {
      clinic: null,
      grooming: null,
    },
  },
  options: {
    service: "grooming",
    dateOnly: false,
    storageKey: "bookingSchedule",
    dateField: "date",
    nextPath: "./grooming-pre-registration.html?step=review",
    fetchTimeslots: (dateKey) => API.getTimeslots(dateKey),
    saveSelection: null,
    clearSelection: null,
    onDateSelected: null,
    onNext: null,
  },
};

// =========================
// ELEMENTS
// =========================

const elements = {
  monthLabel: document.getElementById("monthLabel"),
  prevMonthBtn: document.getElementById("prevMonthBtn"),
  nextMonthBtn: document.getElementById("nextMonthBtn"),
  calendarGrid: document.getElementById("calendarGrid"),
  timeSlots: document.getElementById("timeSlots"),
  selectedScheduleText: document.getElementById("selectedScheduleText"),
  nextStepBtn: document.getElementById("nextStepBtn"),
  clinicNotice: document.getElementById("clinicNotice"),
  operatingHoursText: document.getElementById("operatingHoursText"),
  arrivalWindowNotice: document.getElementById("arrivalWindowNotice"),
};

// =========================
// TIMEZONE HELPERS
// =========================

function getManilaNowParts() {
  const currentDate = new Date();
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  }).formatToParts(currentDate);

  const result = {};
  for (const part of parts) {
    if (part.type !== "literal") result[part.type] = part.value;
  }

  return {
    year: Number(result.year),
    month: Number(result.month),
    day: Number(result.day),
    hour: Number(result.hour),
    minute: Number(result.minute),
    second: Number(result.second),
  };
}

function getTodayKeyInManila() {
  const now = getManilaNowParts();
  return `${now.year}-${String(now.month).padStart(2, "0")}-${String(now.day).padStart(2, "0")}`;
}

function getCurrentMinutesInManila() {
  const now = getManilaNowParts();
  return now.hour * 60 + now.minute + now.second / 60;
}

// =========================
// DATE HELPERS
// =========================

function toDateKey(year, monthIndex, day) {
  return `${year}-${String(monthIndex + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

function dateKeyToLocalDate(dateKey) {
  const [year, month, day] = dateKey.split("-").map(Number);
  return new Date(year, month - 1, day);
}

function isPastDate(dateKey) {
  return dateKey < getTodayKeyInManila();
}

function isToday(dateKey) {
  return dateKey === getTodayKeyInManila();
}

function isBeyondBookingWindow(dateKey) {
  const todayParts = getManilaNowParts();
  const today = new Date(todayParts.year, todayParts.month - 1, todayParts.day);
  const target = dateKeyToLocalDate(dateKey);
  const diffDays = Math.floor((target - today) / (1000 * 60 * 60 * 24));
  return diffDays >= MAX_BOOKING_DAYS_AHEAD;
}

// =========================
// CLINIC CLOSURE HELPERS
// =========================

/**
 * Returns the clinic block object if this date is blocked by the admin,
 * or null if the date is open.
 */
function getClinicBlock(dateKey) {
  const today = getTodayKeyInManila();

  if (state.clinicStatus.stoppedToday && dateKey === today) {
    return { type: "stop_today", start: today, end: today, reason: null };
  }

  for (const block of state.clinicStatus.blockedDates) {
    if (dateKey >= block.start_date && dateKey <= block.end_date) {
      return { type: "blocked_date", start: block.start_date, end: block.end_date, reason: block.reason };
    }
  }

  return null;
}

/**
 * Returns the customer-facing message for a clinic-blocked date.
 */
function getClinicBlockMessage(dateKey) {
  const block = getClinicBlock(dateKey);
  if (!block) return null;

  if (block.type === "stop_today") {
    return "We're taking a short break — check back tomorrow!";
  }

  // If the admin wrote a reason, show it
  if (block.reason) return block.reason;

  if (block.start === block.end) {
    return "We're closed on this date.";
  }

  const fmt = (dk) =>
    new Intl.DateTimeFormat("en-PH", { month: "short", day: "numeric" }).format(
      dateKeyToLocalDate(dk),
    );
  return `We're on a short break ${fmt(block.start)}–${fmt(block.end)}.`;
}

/**
 * Updates the notice banner above the calendar when the clinic is stopped today.
 */
function updateClinicNotice() {
  if (!elements.clinicNotice) return;

  if (state.clinicStatus.stoppedToday) {
    elements.clinicNotice.textContent =
      "The clinic is not accepting pre-registrations for today.";
    elements.clinicNotice.classList.remove("hidden");
  } else {
    elements.clinicNotice.classList.add("hidden");
  }
}

function isDateDisabled(dateKey) {
  return (
    isPastDate(dateKey) ||
    isBeyondBookingWindow(dateKey) ||
    getClinicBlock(dateKey) !== null
  );
}

// =========================
// SLOT HELPERS
// =========================

/**
 * Parse "HH:MM:SS" time string and return total minutes since midnight.
 */
function timeStringToMinutes(timeStr) {
  const [h, m, s = 0] = timeStr.split(":").map(Number);
  return h * 60 + m + s / 60;
}

function getActiveAvailability() {
  return state.clinicStatus.availability?.[state.options.service] || null;
}

function updateOperatingHoursText() {
  if (!elements.operatingHoursText) return;

  const availability = getActiveAvailability();
  if (!availability) return;

  const serviceLabel =
    state.options.service === "clinic" ? "Clinic" : "Pet grooming";
  elements.operatingHoursText.textContent =
    `• ${serviceLabel} hours: ${availability.operating_hours_label}`;
}

/**
 * Check if a slot from the API should be disabled for the selected date.
 * For today, a preferred arrival window remains available until its end time.
 */
function isSlotDisabled(slot) {
  if (slot.is_closed || slot.is_past || isDateDisabled(state.selectedDateKey)) return true;

  if (isToday(state.selectedDateKey)) {
    const currentMinutes = getCurrentMinutesInManila();
    const slotEndMinutes = timeStringToMinutes(slot.end_time);
    if (currentMinutes >= slotEndMinutes) return true;
  }

  return false;
}

function clearArrivalSelection() {
  state.selectedSlot = null;
  if (state.options.clearSelection) state.options.clearSelection();
  else if (state.options.storageKey === "bookingSchedule" && state.selectedDateKey) {
    sessionStorage.setItem(state.options.storageKey, JSON.stringify({ date: state.selectedDateKey }));
  }
  else sessionStorage.removeItem(state.options.storageKey);
}

function updateArrivalWindowNotice() {
  if (!elements.arrivalWindowNotice) return;
  const message = sessionStorage.getItem(`${state.options.storageKey}ArrivalError`);
  elements.arrivalWindowNotice.textContent = message || "";
  elements.arrivalWindowNotice.classList.toggle("hidden", !message);
}

// =========================
// FETCH CLINIC STATUS
// =========================

async function loadClinicStatus() {
  try {
    const data = await API.getClinicStatus();
    state.clinicStatus = {
      stoppedToday: Boolean(data.stopped_today),
      blockedDates: Array.isArray(data.blocked_dates) ? data.blocked_dates : [],
      availability: {
        clinic: data.availability?.clinic || null,
        grooming: data.availability?.grooming || null,
      },
    };
  } catch {
    // Non-fatal — fall back to no restrictions
    state.clinicStatus = {
      stoppedToday: false,
      blockedDates: [],
      availability: { clinic: null, grooming: null },
    };
  }
}

// =========================
// FETCH TIMESLOTS FROM API
// =========================

async function fetchTimeslots(dateKey) {
  elements.timeSlots.innerHTML =
    `<p class="col-span-full text-sm text-slate-400">Loading available times...</p>`;

  try {
    const data = await state.options.fetchTimeslots(dateKey);
    state.timeslots = data.windows || [];

    if (data.availability) {
      state.clinicStatus.availability[state.options.service] =
        data.availability;
      updateOperatingHoursText();
    }
  } catch {
    state.timeslots = [];

    elements.timeSlots.innerHTML =
      `<p class="col-span-full text-sm text-red-500">Could not load time slots. Please try again.</p>`;
    return false;
  }

  return true;
}

// =========================
// RENDER CALENDAR
// =========================

function renderMonthHeader() {
  elements.monthLabel.textContent = new Intl.DateTimeFormat("en-PH", {
    month: "long",
    year: "numeric",
  }).format(state.currentMonth);
}

function createEmptyCell() {
  const emptyCell = document.createElement("div");
  emptyCell.className = "h-12";
  return emptyCell;
}

function createDateButton({ day, dateKey, disabled, selected }) {
  const button = document.createElement("button");
  button.type = "button";
  button.textContent = day;

  const clinicBlock = getClinicBlock(dateKey);

  let disabledClass = "bg-slate-200 text-slate-400 cursor-not-allowed";
  if (disabled && clinicBlock) {
    // Amber tint for clinic closures so customers can tell them apart from past dates
    disabledClass = "bg-[#fef9c3] text-[#854d0e] cursor-not-allowed";
  }

  button.className = [
    "h-12 w-full rounded-xl text-sm font-semibold transition",
    disabled
      ? disabledClass
      : "bg-white text-slate-700 shadow-sm hover:bg-[#d8eafb]",
    selected ? "ring-2 ring-[#315b7e] bg-[#b9d7f1] text-[#1f3d58]" : "",
  ].join(" ");

  const tooltip = clinicBlock ? getClinicBlockMessage(dateKey) : "";
  if (tooltip) button.title = tooltip;

  if (disabled) {
    button.disabled = true;
  } else {
    button.addEventListener("click", async () => {
      state.selectedDateKey = dateKey;
      state.selectedSlot    = null;
      sessionStorage.removeItem(`${state.options.storageKey}ArrivalError`);
      updateArrivalWindowNotice();

      if (!state.options.dateOnly && state.options.storageKey === "bookingSchedule") {
        sessionStorage.removeItem(state.options.storageKey);
      }
      state.options.onDateSelected?.(dateKey);
      renderCalendarGrid();
      updateSelectedSchedule();

      if (state.options.dateOnly) {
        persistDateOnlySelection();
        updateSelectedSchedule();
        return;
      }

      const ok = await fetchTimeslots(dateKey);
      if (ok) renderTimeSlots();
    });
  }

  return button;
}

function renderCalendarGrid() {
  const year = state.currentMonth.getFullYear();
  const monthIndex = state.currentMonth.getMonth();
  const firstWeekday = new Date(year, monthIndex, 1).getDay();
  const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();

  elements.calendarGrid.innerHTML = "";

  for (let i = 0; i < firstWeekday; i++) {
    elements.calendarGrid.appendChild(createEmptyCell());
  }

  for (let day = 1; day <= daysInMonth; day++) {
    const dateKey = toDateKey(year, monthIndex, day);
    elements.calendarGrid.appendChild(
      createDateButton({
        day,
        dateKey,
        disabled: isDateDisabled(dateKey),
        selected: state.selectedDateKey === dateKey,
      }),
    );
  }
}

// =========================
// RENDER SLOTS (from API)
// =========================

function renderTimeSlots() {
  elements.timeSlots.innerHTML = "";

  if (state.timeslots.length === 0) {
    elements.timeSlots.innerHTML =
      `<p class="col-span-full text-sm text-slate-400">No arrival windows available for this date.</p>`;
    return;
  }

  state.timeslots.forEach((slot) => {
    const disabled = isSlotDisabled(slot);
    const selected = state.selectedSlot?.window_id === slot.window_id;

    const button = document.createElement("button");
    button.type = "button";
    button.innerHTML = `
      <span class="block font-semibold">${slot.window_label}</span>
    `;

    button.className = [
      "rounded-2xl border px-4 py-3 text-sm transition text-left",
      disabled
        ? "border-slate-300 bg-slate-200 text-slate-400 cursor-not-allowed"
        : "border-[#a8c8e6] bg-[#edf6fd] text-slate-700 hover:bg-[#dbeefe]",
      selected ? "ring-2 ring-[#315b7e] border-[#315b7e] bg-[#edf6fd]" : "",
    ].join(" ");

    button.setAttribute("aria-pressed", String(selected));
    if (disabled) {
      button.disabled = true;
    } else {
      button.addEventListener("click", () => {
        if (isSlotDisabled(slot)) {
          clearArrivalSelection();
          renderTimeSlots();
          updateSelectedSchedule();
          return;
        }
        state.selectedSlot = slot;
        saveArrivalSelection(slot);

        sessionStorage.removeItem(`${state.options.storageKey}ArrivalError`);
        updateArrivalWindowNotice();

        renderTimeSlots();
        updateSelectedSchedule();
      });
    }

    elements.timeSlots.appendChild(button);
  });
}

function saveArrivalSelection(slot) {
  const selection = {
    date: state.selectedDateKey,
    time: slot.window_label,
    scheduleText: formatBookingSchedule(state.selectedDateKey, slot.window_label),
    window_id: slot.window_id,
    start_time: slot.start_time,
    end_time: slot.end_time,
  };
  if (typeof state.options.saveSelection === "function") {
    state.options.saveSelection(selection, slot);
  } else {
    sessionStorage.setItem(state.options.storageKey, JSON.stringify(selection));
  }
}

function updateSelectedSchedule() {
  if (state.options.dateOnly) {
    if (!state.selectedDateKey) {
      elements.selectedScheduleText.textContent = "Please select a clinic visit date.";
      elements.nextStepBtn.disabled = true;
      elements.nextStepBtn.classList.add("opacity-50", "cursor-not-allowed");
      return;
    }

    elements.selectedScheduleText.textContent = formatDateOnly(state.selectedDateKey);
    elements.nextStepBtn.disabled = false;
    elements.nextStepBtn.classList.remove("opacity-50", "cursor-not-allowed");
    return;
  }

  if (!state.selectedDateKey || !state.selectedSlot) {
    elements.selectedScheduleText.textContent = "Please select a date and arrival window.";
    elements.nextStepBtn.disabled = true;
    elements.nextStepBtn.classList.add("opacity-50", "cursor-not-allowed");
    return;
  }

  elements.selectedScheduleText.textContent = formatBookingSchedule(
    state.selectedDateKey,
    state.selectedSlot.window_label,
  );

  elements.nextStepBtn.disabled = false;
  elements.nextStepBtn.classList.remove("opacity-50", "cursor-not-allowed");
}

function formatDateOnly(dateKey) {
  const date = dateKeyToLocalDate(dateKey);

  return new Intl.DateTimeFormat("en-PH", {
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric",
  }).format(date);
}

function persistDateOnlySelection() {
  let existingDraft = {};

  try {
    existingDraft = JSON.parse(
      sessionStorage.getItem(state.options.storageKey) || "{}",
    );
  } catch {
    existingDraft = {};
  }

  sessionStorage.setItem(
    state.options.storageKey,
    JSON.stringify({
      ...existingDraft,
      [state.options.dateField]: state.selectedDateKey,
    }),
  );
}

// =========================
// MONTH NAVIGATION
// =========================

function canGoToPreviousMonth() {
  const now = getManilaNowParts();
  return !(
    state.currentMonth.getFullYear() === now.year &&
    state.currentMonth.getMonth() === now.month - 1
  );
}

function bindEvents() {
  elements.prevMonthBtn.addEventListener("click", () => {
    if (!canGoToPreviousMonth()) return;
    state.currentMonth = new Date(
      state.currentMonth.getFullYear(),
      state.currentMonth.getMonth() - 1,
      1,
    );
    renderCalendarGrid();
    renderMonthHeader();
  });

  elements.nextMonthBtn.addEventListener("click", () => {
    state.currentMonth = new Date(
      state.currentMonth.getFullYear(),
      state.currentMonth.getMonth() + 1,
      1,
    );
    renderCalendarGrid();
    renderMonthHeader();
  });

  elements.nextStepBtn.addEventListener("click", () => {
    if (!state.selectedDateKey || (!state.options.dateOnly && !state.selectedSlot)) return;
    if (!state.options.dateOnly && isSlotDisabled(state.selectedSlot)) {
      clearArrivalSelection();
      sessionStorage.setItem(`${state.options.storageKey}ArrivalError`,
        "Your arrival window is no longer available. Please choose another date or time.");
      updateArrivalWindowNotice();
      renderTimeSlots();
      updateSelectedSchedule();
      return;
    }
    if (state.options.onNext) state.options.onNext();
    else window.location.href = state.options.nextPath;
  });
}

export async function refreshBookingCalendar() {
  await loadClinicStatus();
  if (state.selectedDateKey && isDateDisabled(state.selectedDateKey)) {
    state.selectedDateKey = null;
    state.selectedSlot = null;
    if (!state.options.dateOnly) clearArrivalSelection();
  }
  if (state.selectedDateKey && !state.options.dateOnly) {
    state.selectedSlot = null;
    const ok = await fetchTimeslots(state.selectedDateKey);
    if (ok) {
      let saved = null;
      try {
        saved = JSON.parse(sessionStorage.getItem(state.options.storageKey) || "null");
      } catch {
        clearArrivalSelection();
      }
      state.selectedSlot = state.timeslots.find((slot) =>
        slot.window_id === (saved?.window_id ?? saved?.windowId) && !isSlotDisabled(slot)
      ) || null;
      if (!state.selectedSlot) clearArrivalSelection();
      else saveArrivalSelection(state.selectedSlot);
      renderTimeSlots();
    }
  } else if (!state.options.dateOnly) {
    elements.timeSlots.innerHTML = "";
  }
  renderMonthHeader();
  renderCalendarGrid();
  updateClinicNotice();
  updateOperatingHoursText();
  updateSelectedSchedule();
  updateArrivalWindowNotice();
}

// =========================
// INIT
// =========================

export async function initBookingCalendar(options = {}) {
  state.options = {
    ...state.options,
    ...options,
  };

  const now = getManilaNowParts();
  state.currentMonth = new Date(now.year, now.month - 1, 1);

  try {
    const savedDraft = JSON.parse(
      sessionStorage.getItem(state.options.storageKey) || "{}",
    );
    state.selectedDateKey = savedDraft[state.options.dateField] || null;
    if (state.selectedDateKey) {
      const selectedDate = dateKeyToLocalDate(state.selectedDateKey);
      state.currentMonth = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
    }
  } catch {
    state.selectedDateKey = null;
  }

  // Clear any previously selected date if it is now clinic-blocked
  if (state.selectedDateKey && isDateDisabled(state.selectedDateKey)) {
    state.selectedDateKey = null;
    state.selectedSlot    = null;
  }

  bindEvents();
  await refreshBookingCalendar();
}
