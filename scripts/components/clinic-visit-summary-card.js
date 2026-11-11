import { escapeHtml } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";

export function getClinicVisitSummaryMarkup({ visitType, pet = null, reason = "", concerns, details } = {}) {
  const name = pet?.petName || pet?.name || "Not selected";
  const type = pet?.petType || pet?.species || "Not specified";
  return `<div class="mb-6 grid gap-4 md:grid-cols-2">
    <section class="rounded-2xl border border-slate-200 bg-white p-4">
      <h3 class="text-lg font-semibold text-[#2f4b66]">${escapeHtml(name)}</h3>
      <p class="mt-2 text-sm text-slate-600">${escapeHtml(type)} &middot; ${escapeHtml(pet?.breed || "Breed not specified")}</p>
      ${pet?.weight ? `<p class="mt-2 text-sm text-slate-600">Weight: ${escapeHtml(pet.weight)} kg</p>` : ""}
      ${pet?.color ? `<p class="mt-2 text-sm text-slate-600">Color: ${escapeHtml(pet.color)}</p>` : ""}
      ${pet?.gender ? `<p class="mt-2 text-sm text-slate-600">Sex: ${escapeHtml(pet.gender)}</p>` : ""}
      ${pet?.medicalNotes ? `<p class="note-text mt-2 text-sm text-slate-600">Allergies &amp; Special Care Notes: ${escapeHtml(pet.medicalNotes)}</p>` : ""}
    </section>
    <section class="rounded-2xl border border-slate-200 bg-white p-4">
      <h3 class="text-lg font-semibold text-[#2f4b66]">Reason for Visit</h3>
      ${concerns ? `<dl class="mt-2 space-y-2 text-sm text-slate-600">
        <div><dt class="font-medium">Common Concerns</dt><dd>${escapeHtml(concerns.join(", "))}</dd></div>
        ${details ? `<div><dt class="font-medium">Additional Details</dt><dd class="note-text">${escapeHtml(details)}</dd></div>` : ""}
      </dl>` : `<p class="note-text mt-2 text-sm text-slate-600">${escapeHtml(reason)}</p>`}
      ${visitType ? `<p class="mt-3 text-xs text-slate-500">${escapeHtml(visitType)}</p>` : ""}
    </section>
  </div>`;
}
