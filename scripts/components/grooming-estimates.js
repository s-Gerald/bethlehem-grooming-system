import "../services/grooming-forecast-presentation.js?v=20261031&estimate-ui=20261031-v2";
import { normalizePetSize } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";
import { hasRequiredGroomingPreference } from "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026&registration=fcfs-20261027-v1";

const presentation = globalThis.GroomingForecastPresentation;

export function groomingDurationValue(selection, duration = null, pending = false) {
  if (!selection?.servicePackage && !selection?.alaCarteServices?.length) return "Select a service";
  if (!hasRequiredGroomingPreference(selection)) return "Select a grooming preference";
  return pending ? "Calculating…" : presentation.duration(duration);
}

export function confirmClosingWarning(forecast, staff = false, trigger = document.activeElement) {
  const dialog = document.createElement("dialog");
  dialog.setAttribute("aria-labelledby", "groomingClosingTitle");
  dialog.setAttribute("aria-describedby", "groomingClosingMessage");
  dialog.className = "w-[calc(100%_-_2rem)] max-w-lg rounded-2xl border border-amber-300 bg-white p-5 text-slate-800 shadow-xl backdrop:bg-slate-900/40 sm:p-6";
  dialog.style.margin = "auto";
  const completion = forecast.completion_at && !["delayed", "uncertain", "unavailable"].includes(forecast.status)
    ? presentation.time(forecast.completion_at, forecast.generated_at) : "Uncertain";
  dialog.innerHTML = `<h3 id="groomingClosingTitle" class="flex items-start gap-2 text-lg font-semibold text-amber-900">
      <svg class="ph-icon mt-1 h-5 w-5 shrink-0" viewBox="0 0 256 256" aria-hidden="true"><use href="../../assets/icons/phosphor.svg#warning"></use></svg>Grooming May Extend Past Closing</h3>
    <p id="groomingClosingMessage" class="mt-3 text-sm leading-6 text-slate-700">${staff
      ? "Based on the current queue, this visit may finish after grooming closes."
      : `Based on the current queue, if your ${forecast.pets.length > 1 ? "pets checked" : "pet checked"} in now, grooming may finish after closing. This estimate may change before check-in.`}</p>
    <dl class="mt-4 space-y-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
      <div class="flex flex-wrap justify-between gap-2"><dt>Estimated completion</dt><dd class="font-semibold">${completion}</dd></div>
      <div class="flex flex-wrap justify-between gap-2"><dt>Grooming closes</dt><dd class="font-semibold">${presentation.time(forecast.closing_at, forecast.generated_at)}</dd></div></dl>
    <p class="mt-3 text-xs leading-5 text-slate-500">Grooming may not be able to start today.</p>
    <div class="mt-5 flex flex-col gap-3">
      <button type="button" data-review-services autofocus class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#315b7e]">Review Services</button>
      <button type="button" data-continue-review class="rounded-xl bg-[#315b7e] px-4 py-3 text-sm font-semibold text-white hover:bg-[#274a67] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#315b7e]">Continue to Review</button>
    </div>`;
  return new Promise(resolve => {
    dialog.querySelector("[data-review-services]").addEventListener("click", () => dialog.close("review"));
    dialog.querySelector("[data-continue-review]").addEventListener("click", () => dialog.close("continue"));
    dialog.addEventListener("close", () => {
      const continued = dialog.returnValue === "continue";
      dialog.remove();
      if (trigger?.isConnected) trigger.focus({ preventScroll: true });
      resolve(continued);
    }, { once: true });
    document.body.append(dialog);
    dialog.showModal();
  });
}

export function createGroomingEstimateFlow({ getPets, getSelections, container, getContainer = () => container, walkIn = false,
  isCurrent = () => true,
  requestForecast = (payload) => API.getGroomingForecast(payload, walkIn),
  confirmWarning = (forecast, trigger) => confirmClosingWarning(forecast, walkIn, trigger),
  onContinue, onBusy = () => {}, onUnavailable = () => {
    sessionStorage.setItem("groomingForecastNotice", "Grooming time estimates are temporarily unavailable. You can continue with this registration.");
  },
}) {
  let busy = false, advanced = false, generation = 0, timer, pendingNext;
  const payload = (durationsOnly = false) => ({ durations_only: durationsOnly,
    pets: getPets().map(pet => {
      const selected = getSelections().find(item => String(item.petId) === String(pet.id)) || {};
      return { pet_id: walkIn ? (pet.petId || null) : (/^[1-9]\d*$/.test(String(pet.id)) ? Number(pet.id) : null),
        species: String(pet.petType).toLowerCase(), size: normalizePetSize(pet.size) || null,
        ...(pet.weight !== "" && pet.weight != null ? { weight: Number(pet.weight) } : {}),
        groomer_id: selected.groomerId ?? null, grooming_preference: selected.groomingPreference || null,
        services: { package: selected.servicePackage || null, ala_carte: selected.alaCarteServices || [] } };
    }),
  });
  const signature = () => JSON.stringify(payload());
  function display(forecast, pending = false) {
    getPets().forEach((pet, index) => {
      const node = getContainer()?.querySelector(`[data-grooming-duration="${CSS.escape(String(pet.id))}"]`);
      const selected = getSelections().find(item => String(item.petId) === String(pet.id));
      if (!node) return;
      node.querySelector('[data-grooming-duration-value]').textContent = groomingDurationValue(selected, forecast?.pets?.[index]?.duration, pending);
    });
  }
  return {
    get busy() { return busy || advanced; },
    reset() { generation++; advanced = false; busy = false; pendingNext = null; clearTimeout(timer); onBusy(false); },
    selectionChanged() {
      const revision = ++generation, key = signature();
      clearTimeout(timer);
      display(null, true);
      timer = setTimeout(async () => {
        try {
          const forecast = await requestForecast(payload(true));
          if (revision === generation && key === signature() && isCurrent()) display(forecast);
        } catch { if (revision === generation && key === signature() && isCurrent()) display(null); }
      }, 250);
    },
    async next() {
      if (busy || advanced || !isCurrent()) return;
      const trigger = globalThis.document?.activeElement;
      const operation = pendingNext = {};
      busy = true; onBusy(true); clearTimeout(timer);
      const revision = ++generation, key = signature();
      try {
        let forecast;
        try { forecast = await requestForecast(payload()); }
        catch { if (revision === generation && key === signature() && isCurrent()) onUnavailable(); }
        if (revision !== generation || key !== signature() || !isCurrent()) return;
        if (forecast?.closing_risk && !await confirmWarning(forecast, trigger)) return;
        if (revision !== generation || key !== signature() || !isCurrent()) return;
        advanced = true;
        onContinue();
      } finally {
        if (pendingNext === operation) {
          busy = false; pendingNext = null; onBusy(advanced);
          if (!advanced && isCurrent() && trigger?.isConnected) trigger.focus({ preventScroll: true });
        }
      }
    },
  };
}
