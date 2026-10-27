import { renderWalkInClinicConsentStep } from "./walk-in-clinic-consent-step.js?pet-weight=pet-weight-size-20261011-v3&session=walk-in-20261021&clinic-roles=20261024&nav=walk-in-home-20261025&registration=fcfs-20261027-v1&pet-care=20261027-v1";
import { mountClinicVisitReasonFields } from "./clinic-visit-reason-fields.js?registration=fcfs-20261027-v1";

const WALK_IN_CLINIC_COMPLAINT_KEY = "walkInClinicComplaint";

let state = { pet: null, fields: null };

function getMarkup() {
  return `
    <main class="mx-auto max-w-6xl px-4 py-8 md:px-6 lg:px-8">
      <section data-session-public class="mb-6">
        <a id="clinicComplaintBackTopBtn" href="./dashboard.html"
          class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-slate-700">
          &larr; Back to Home
        </a>
        <div class="mt-4"><h1 class="text-3xl font-bold text-[#2f4b66]">Reason for Visit</h1></div>
        <div class="mt-6">
          <div class="mb-2 flex items-center justify-between text-xs font-medium text-slate-500">
            <span>Step 3 of 4</span><span>75% Complete</span>
          </div>
          <div class="h-2 w-full rounded-full bg-slate-200">
            <div class="h-2 rounded-full bg-[#315b7e]" style="width: 75%"></div>
          </div>
        </div>
      </section>
      <section data-session-surface class="rounded-3xl bg-[#cfe3f6] p-6 shadow-lg md:p-8">
        <div class="mx-auto max-w-5xl">
          <section class="rounded-3xl bg-white/90 p-5 shadow-sm md:p-6">
            <form id="clinicComplaintForm" class="space-y-5" novalidate>
              <div id="clinicComplaintFields"></div>
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <button id="clinicComplaintBackBtn" type="button"
                  class="inline-flex items-center justify-center rounded-2xl border border-[#315b7e] px-5 py-3 text-sm font-semibold text-[#315b7e] transition hover:bg-[#edf5fc]">Back</button>
                <button type="submit"
                  class="inline-flex items-center justify-center rounded-2xl bg-[#315b7e] px-6 py-3 text-sm font-semibold text-white transition hover:bg-[#274864]">Next Step</button>
              </div>
            </form>
          </section>
        </div>
      </section>
    </main>
  `;
}

function restoreDraft() {
  try {
    return JSON.parse(sessionStorage.getItem(WALK_IN_CLINIC_COMPLAINT_KEY) || "{}");
  } catch {
    return {};
  }
}

function handleSubmit(event) {
  event.preventDefault();
  if (!state.fields.validate()) return;

  const commonConcerns = state.fields.concerns();
  const chiefComplaint = state.fields.details.value.trim();
  sessionStorage.setItem(WALK_IN_CLINIC_COMPLAINT_KEY,
    JSON.stringify({ commonConcerns, chiefComplaint, pet: state.pet }));

  window.history.pushState(null, "", "./walk-in-pet-details.html?step=clinic-consent");
  renderWalkInClinicConsentStep({
    pet: state.pet,
    commonConcerns,
    chiefComplaint,
    onBack: () => {
      window.history.pushState(null, "", "./walk-in-pet-details.html?step=clinic-complaint");
      renderWalkInClinicComplaintStep({ pet: state.pet });
    },
  });
}

export async function renderWalkInClinicComplaintStep({ pet = null } = {}) {
  if (!await API.waitForSessionVerification()) return;
  state = { pet, fields: null };
  document.title = "Walk-in Clinic — Reason for Visit";
  document.body.className = "min-h-screen bg-[#f6fafd] text-slate-800";
  document.querySelector("main").outerHTML = getMarkup();

  const draft = restoreDraft();
  state.fields = mountClinicVisitReasonFields(
    document.getElementById("clinicComplaintFields"),
    pet?.petName,
    draft.commonConcerns,
    draft.chiefComplaint,
  );
  document.getElementById("clinicComplaintForm").addEventListener("submit", handleSubmit);
  document.getElementById("clinicComplaintBackBtn").addEventListener("click", () => {
    window.location.href = "./walk-in-pet-details.html";
  });
}
