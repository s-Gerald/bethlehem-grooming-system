import { escapeHtml } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";
import { formatBookingDate } from "../services/booking-format-service.js";

// Supplemental information: one aggregate request on review entry, no polling.
export async function renderRegistrationQueueInformation(root, service) {
  if (!root) return;
  const render = (data = {}) => {
    const count = Number.isInteger(data.waiting_count) ? data.waiting_count : "Currently unavailable";
    root.innerHTML = `<h2 class="text-lg font-bold text-[#2f4b66]">Queue Information</h2>
      <dl class="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
        <div><dt class="text-slate-500">Valid service day</dt><dd class="mt-1 font-semibold text-[#2f4b66]">${escapeHtml(data.service_day ? formatBookingDate(data.service_day) : "Confirmed on submission")}</dd></div>
        <div><dt class="text-slate-500">Pets currently waiting</dt><dd class="mt-1 font-semibold text-[#2f4b66]">${escapeHtml(count)}</dd></div>
        <div><dt class="text-slate-500">Your queue number</dt><dd class="mt-1 font-semibold text-[#2f4b66]">Not assigned</dd></div>
      </dl>
      ${data.accepting_check_ins === false ? '<p class="mt-3 text-sm text-slate-500">The queue is available during service hours on your valid service day.</p>' : ""}
      <p class="mt-4 text-sm text-slate-600">Your queue number is assigned when you check in at the clinic. Online registration does not reserve a place.</p>`;
  };
  render();
  try {
    const response = await fetch(`${API.getBaseUrl()}/pre-registration/queue-info?service=${encodeURIComponent(service)}`, { cache: "no-store", headers: { Accept: "application/json" } });
    if (response.ok) render(await response.json());
  } catch { /* The review and submission remain available. */ }
}
