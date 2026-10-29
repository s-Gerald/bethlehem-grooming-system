import { mountClinicVisitReasonFields } from "./clinic-visit-reason-fields.js?registration=fcfs-20261027-v1";
import {
  readClinicVisitDraft,
  requireCustomerSession,
  updateClinicVisitDraft,
} from "../services/clinic-visit-service.js?pet-weight=pet-weight-size-20261011-v3&registration=fcfs-20261027-v1&remember=20261029";

async function init() {
  if (!await requireCustomerSession("./sign-in.html")) return;

  const draft = readClinicVisitDraft();
  if (!draft.pet?.id) {
    window.location.replace("./booking-pet-details.html?flow=clinic");
    return;
  }

  const fields = mountClinicVisitReasonFields(
    document.getElementById("clinicVisitReasonFields"),
    draft.pet.petName,
    draft.commonConcerns,
    draft.chiefComplaint,
  );
  document.getElementById("clinicVisitReasonForm").addEventListener("submit", (event) => {
    event.preventDefault();
    if (!fields.validate()) return;
    updateClinicVisitDraft({
      commonConcerns: fields.concerns(),
      chiefComplaint: fields.details.value.trim(),
    });
    window.location.href = "./clinic-visit-summary.html";
  });
}

init();
