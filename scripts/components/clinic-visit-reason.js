import { mountClinicPatientReasons } from "./clinic-visit-reason-fields.js?owner-session=20261111";
import {
  readClinicVisitDraft,
  requireCustomerSession,
  updateClinicVisitDraft,
} from "../services/clinic-visit-service.js?owner-session=20261111";

async function init() {
  if (!await requireCustomerSession("/sign-in")) return;

  const draft = readClinicVisitDraft();
  if (!draft.patients?.length) {
    window.location.replace("/account/registration/clinic/pets?flow=clinic");
    return;
  }

  const fields = mountClinicPatientReasons(
    document.getElementById("clinicVisitReasonFields"),
    draft.patients,
    (patients) => updateClinicVisitDraft({ patients }),
  );
  document.getElementById("clinicVisitReasonForm").addEventListener("submit", (event) => {
    event.preventDefault();
    if (!fields.validate()) return;
    window.location.href = "/account/registration/clinic/review";
  });
}

init();
