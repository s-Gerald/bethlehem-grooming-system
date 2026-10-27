import { escapeHtml } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";
import { formatBookingDate } from "../services/booking-format-service.js";
import { loadRegistrationAvailability, rememberReviewedServiceDay, takeRegistrationDayNotice } from "../services/registration-service-day.js?v=rolling-20261027-v1&registration-rollover=20261027-v3";

const renders = new WeakMap();

// Supplemental information: one aggregate request on review entry, no polling.
export async function renderRegistrationQueueInformation(root, service) {
  if (!root) return;
  const version = (renders.get(root) || 0) + 1;
  renders.set(root, version);
  rememberReviewedServiceDay(service, null);
  const notice = takeRegistrationDayNotice(service);
  const render = (data = {}) => {
    const count = Number.isInteger(data.waiting_count) && data.waiting_count >= 0 ? data.waiting_count : "Currently unavailable";
    const tile = (icon, label, value) => `<div class="rounded-xl bg-[#eef5fb] p-4">
      <svg class="ph-icon h-5 w-5 text-[#315b7e] mb-2" viewBox="0 0 256 256" aria-hidden="true"><use href="../../assets/icons/phosphor.svg#${icon}"></use></svg>
      <dt class="text-xs text-slate-600">${label}</dt><dd class="mt-1 font-semibold text-[#2f4b66]">${escapeHtml(value)}</dd></div>`;
    root.innerHTML = `<h2 class="text-lg font-bold text-[#2f4b66]">Queue Information</h2>
      ${notice ? `<p role="alert" class="mt-3 text-sm font-medium text-amber-800">${escapeHtml(notice)}</p>` : ""}
      <dl class="mt-4 grid gap-3 sm:grid-cols-3 text-sm">
        ${tile("calendar", "Visit day", data.service_day ? formatBookingDate(data.service_day) : "Unavailable")}
        ${tile("users", "Queue number", "Not assigned")}
        ${tile("clock", "Pets waiting", count)}
      </dl>
      <p class="mt-3 rounded-xl bg-[#eef5fb] px-4 py-3 text-sm text-[#315b7e]">${escapeHtml(data.message && !data.registration_available ? data.message : "Queue numbers are assigned at check-in.")}</p>`;
  };
  render();
  try {
    const data = await loadRegistrationAvailability(service);
    if (renders.get(root) !== version) return null;
    rememberReviewedServiceDay(service, data.registration_available ? data.service_day : null);
    render(data);
    return data;
  } catch {
    if (renders.get(root) === version) render({ message: "Unable to check availability. Try again." });
    return null;
  }
}
