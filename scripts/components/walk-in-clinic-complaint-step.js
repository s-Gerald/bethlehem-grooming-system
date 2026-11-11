import { renderWalkInClinicConsentStep } from "./walk-in-clinic-consent-step.js?pet-weight=pet-weight-size-20261107-v1&session=walk-in-20261021&clinic-roles=20261024&nav=walk-in-home-20261025&registration=fcfs-20261027-v1&pet-care=20261027-v1&registration-actions=20261101-v1&urls=20261103&owner-name=20261108&body-condition=20261110-v2&name-policy=20261110&short-notes=500-20261110&owner-session=20261111";
import { mountClinicPatientReasons } from "./clinic-visit-reason-fields.js?registration=fcfs-20261027-v1&urls=20261103&short-notes=500-20261110&owner-session=20261111";

import { validateClinicVisitReason } from "../services/clinic-visit-service.js?owner-session=20261111";

const WALK_IN_CLINIC_COMPLAINT_KEY = "walkInClinicComplaint";


function getMarkup() {
  return `
    <main class="registration-step mx-auto max-w-6xl px-4 py-8 md:px-6 lg:px-8">
      <section data-session-public class="mb-6">
        <a id="clinicComplaintBackTopBtn" href="/admin/dashboard"
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
              <div class="registration-actions">
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

function patientRows(pets) {
  const draft = restoreDraft();
  const saved = Array.isArray(draft.patients) ? draft.patients : draft.pet ? [draft] : [];
  const previous = new Map(saved.filter((row) => row?.pet?.id).map((row) => [String(row.pet.id), row]));
  return pets.map((pet) => {
    const { commonConcerns, chiefComplaint } = previous.get(String(pet.id)) ?? {};
    return { pet, commonConcerns, chiefComplaint };
  });
}

function savePatients(patients) {
  const draft = restoreDraft();
  const termsAgreed = draft.termsAgreed === true && JSON.stringify(draft.patients) === JSON.stringify(patients);
  sessionStorage.setItem(WALK_IN_CLINIC_COMPLAINT_KEY, JSON.stringify({ patients, termsAgreed }));
}

function showConsent(pets, patients) {
  renderWalkInClinicConsentStep({
    patients,
    onBack: () => {
      window.history.replaceState(null, "", "/admin/walk-in/pets?step=clinic-complaint");
      renderWalkInClinicComplaintStep({ pets });
    },
  });
}

export function restoreWalkInClinicStep(pets) {
  const step = new URLSearchParams(window.location.search).get("step");
  if (step === "clinic-consent") {
    const patients = patientRows(pets);
    if (patients.every(({ commonConcerns, chiefComplaint }) => !validateClinicVisitReason(commonConcerns, chiefComplaint))) {
      savePatients(patients);
      showConsent(pets, patients);
      return;
    }
  }
  if (step === "clinic-complaint" || step === "clinic-consent") renderWalkInClinicComplaintStep({ pets });
}

export async function renderWalkInClinicComplaintStep({ pets = [] } = {}) {
  if (!pets.length || !await API.waitForSessionVerification()) return;
  document.title = "Walk-in Clinic — Reason for Visit";
  document.body.className = "min-h-screen bg-[#f6fafd] text-slate-800";
  document.querySelector("main").outerHTML = getMarkup();

  const patients = patientRows(pets);
  const fields = mountClinicPatientReasons(document.getElementById("clinicComplaintFields"), patients, savePatients);
  document.getElementById("clinicComplaintForm").addEventListener("submit", (event) => {
    event.preventDefault();
    if (!fields.validate()) return;
    window.history.pushState(null, "", "/admin/walk-in/pets?step=clinic-consent");
    showConsent(pets, patients);
  });
  document.getElementById("clinicComplaintBackBtn").addEventListener("click", () => {
    window.location.href = "/admin/walk-in/pets";
  });
}
