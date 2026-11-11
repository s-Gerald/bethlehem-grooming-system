import {
  CLINIC_CONCERNS,
  validateClinicVisitReason,
} from "../services/clinic-visit-service.js?pet-weight=pet-weight-size-20261107-v1&registration=fcfs-20261027-v1&remember=20261029&urls=20261103&short-notes=500-20261110";

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

export function mountClinicVisitReasonFields(container, petName, initialConcerns = [], initialDetails = "") {
  const selected = new Set((Array.isArray(initialConcerns) ? initialConcerns : [])
    .filter((concern) => CLINIC_CONCERNS.includes(concern)));
  container.innerHTML = `
    <h2 class="mb-5 text-2xl font-bold text-[#2f4b66]">What brings ${escapeHtml(petName || "your pet")} in today?</h2>
    <fieldset class="mb-5" aria-describedby="clinicConcernHint clinicConcernError">
      <legend class="text-sm font-semibold text-slate-700">Common concerns <span class="text-red-500">*</span></legend>
      <p id="clinicConcernHint" class="mt-1 text-sm text-slate-500">Select all that apply.</p>
      <div class="mt-3 flex flex-wrap gap-2">
        ${CLINIC_CONCERNS.map((concern) => `
          <button type="button" data-clinic-concern="${escapeHtml(concern)}" aria-pressed="false"
            class="rounded-full border border-[#91aeca] bg-white px-4 py-2 text-sm font-medium text-[#315b7e] transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#315b7e]">
            ${escapeHtml(concern)}
          </button>
        `).join("")}
      </div>
      <p id="clinicConcernError" class="mt-2 hidden text-sm text-red-600" role="alert"></p>
    </fieldset>
    <div>
      <label for="clinicVisitDetails" class="mb-2 block text-sm font-medium text-slate-700">Tell us more</label>
      <textarea id="clinicVisitDetails" name="chief_complaint" rows="5" data-short-note
        placeholder="Describe when it started, how often it happens, or anything else the clinic should know."
        class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-[#315b7e] focus:ring-2 focus:ring-[#315b7e]/20"></textarea>
    </div>
  `;

  const buttons = [...container.querySelectorAll("[data-clinic-concern]")];
  const details = container.querySelector("#clinicVisitDetails");
  const error = container.querySelector("#clinicConcernError");
  details.value = initialDetails || "";

  function sync() {
    buttons.forEach((button) => {
      const active = selected.has(button.dataset.clinicConcern);
      button.setAttribute("aria-pressed", String(active));
      button.classList.toggle("bg-[#315b7e]", active);
      button.classList.toggle("text-white", active);
      button.classList.toggle("border-[#315b7e]", active);
      button.classList.toggle("bg-white", !active);
      button.classList.toggle("text-[#315b7e]", !active);
      button.classList.toggle("border-[#91aeca]", !active);
      button.classList.toggle("hover:bg-[#274864]", active);
      button.classList.toggle("hover:bg-[#edf5fc]", !active);
    });
  }

  function showError(message) {
    error.textContent = message;
    error.classList.toggle("hidden", !message);
  }

  buttons.forEach((button) => button.addEventListener("click", () => {
    const concern = button.dataset.clinicConcern;
    if (selected.has(concern)) selected.delete(concern);
    else selected.add(concern);
    sync();
    if (selected.size) showError("");
  }));
  sync();

  return {
    details,
    concerns: () => CLINIC_CONCERNS.filter((concern) => selected.has(concern)),
    validate() {
      if (globalThis.ShortNotes?.validate(details) === false) return false;
      const message = validateClinicVisitReason(this.concerns(), details.value);
      showError(message);
      if (message) (selected.size ? details : buttons[0]).focus();
      return !message;
    },
  };
}

export function mountClinicPatientReasons(container, patients, save) {
  let active = patients.findIndex(({ commonConcerns, chiefComplaint }) => validateClinicVisitReason(commonConcerns, chiefComplaint));
  if (active < 0 && patients.length === 1) active = 0;
  let fields;

  function capture() {
    if (!fields || active < 0) return;
    patients[active] = {
      ...patients[active], commonConcerns: fields.concerns(), chiefComplaint: fields.details.value.trim(),
    };
    save(patients);
  }

  function render() {
    container.innerHTML = patients.map(({ pet, commonConcerns, chiefComplaint }, index) => {
      const editing = active === index;
      const valid = !validateClinicVisitReason(commonConcerns, chiefComplaint);
      return `<section class="mb-3 rounded-2xl border border-slate-200 bg-white p-4">
        ${patients.length > 1 ? `<button type="button" data-reason-patient="${index}" aria-expanded="${editing}"
          class="flex w-full flex-wrap items-center justify-between gap-2 text-left text-sm text-[#315b7e]">
          <span class="font-semibold">${escapeHtml(pet.petName)}</span>
          <span>${editing ? "Close reason" : valid ? "Reason added · Edit reason" : "Reason required · Add reason"}</span>
        </button>` : ""}
        ${editing ? '<div class="mt-4" data-active-reason></div>' : ""}
      </section>`;
    }).join("");
    const editor = container.querySelector("[data-active-reason]");
    fields = editor ? mountClinicVisitReasonFields(
      editor, patients[active].pet.petName, patients[active].commonConcerns, patients[active].chiefComplaint,
    ) : null;
    editor?.addEventListener("input", capture);
    editor?.addEventListener("click", (event) => {
      if (event.target.closest("[data-clinic-concern]")) capture();
    });
    container.querySelectorAll("[data-reason-patient]").forEach((button) => button.addEventListener("click", () => {
      capture();
      const index = Number(button.dataset.reasonPatient);
      active = index === active ? -1 : index;
      render();
      container.querySelector(`[data-reason-patient="${index}"]`)?.focus();
    }));
  }

  render();
  return {
    validate() {
      capture();
      const invalid = patients.findIndex(({ commonConcerns, chiefComplaint }) => validateClinicVisitReason(commonConcerns, chiefComplaint));
      if (invalid === -1) return true;
      if (invalid !== active) {
        active = invalid;
        render();
      }
      fields.validate();
      return false;
    },
  };
}
