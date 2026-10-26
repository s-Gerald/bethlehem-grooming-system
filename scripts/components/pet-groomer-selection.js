import { escapeHtml } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";

let available = [];

export async function loadPetGroomers() {
  available = [];
  const response = await API.getPetGroomers();
  available = response.pet_groomers;
  return available;
}

export function normalizePetGroomerSelections(selections) {
  for (const selection of selections) {
    if (available.length === 1) selection.groomerId = available[0].id;
    else if (!available.some((person) => person.id === Number(selection.groomerId))) selection.groomerId = null;
  }
}

export function hasPetGroomerSelection(selection, walkIn = false) {
  return available.length > 0 && ((!walkIn && selection?.groomerId === null && available.length > 1)
    || available.some((person) => person.id === Number(selection?.groomerId)));
}

export function petGroomerLabel(selection) {
  if (selection?.groomerId === null) return "Any available groomer";
  return available.find((person) => person.id === Number(selection?.groomerId))?.first_name || "Unavailable";
}

export function renderPetGroomerSelection(pet, selection, walkIn = false) {
  const legend = '<legend class="mb-3 text-base font-semibold text-[#2f4b66]">Pet Groomer <span class="text-red-500">*</span></legend>';
  if (!available.length) return `<fieldset class="mb-6 min-w-0">${legend}<p role="status" class="text-sm text-red-700">No Pet Groomer available.</p></fieldset>`;
  if (available.length === 1) return `<fieldset class="mb-6 min-w-0">${legend}<p class="text-sm font-semibold text-[#315b7e]">${escapeHtml(available[0].first_name)}</p></fieldset>`;
  const options = [...(walkIn ? [] : [{ id: null, first_name: "Any available groomer" }]), ...available];
  return `<fieldset class="mb-6 min-w-0">${legend}<div class="flex flex-wrap gap-2">${options.map((person) => `
    <label class="flex min-w-0 max-w-full cursor-pointer items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm text-[#315b7e] transition hover:border-[#315b7e] focus-within:ring-2 focus-within:ring-[#315b7e]/20">
      <input type="radio" name="pet_groomer_${escapeHtml(pet.id)}" required data-role="pet-groomer" data-pet-id="${escapeHtml(pet.id)}" value="${person.id ?? ''}" class="h-4 w-4 shrink-0 accent-[#315b7e]" ${selection.groomerId === person.id ? 'checked' : ''} />
      <span class="min-w-0 break-words">${escapeHtml(person.first_name)}</span>
    </label>`).join('')}</div></fieldset>`;
}
