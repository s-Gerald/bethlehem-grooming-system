import {
  clearClinicVisitDraft,
  requireCustomerSession,
} from "../services/clinic-visit-service.js?pet-weight=pet-weight-size-20261011-v3";
import {
  getPreRegistrationAccess,
  setPreRegistrationLinkAccess,
} from "../services/booking-form-access-guard.js?v=phone-20261022";
import { clearGroomingDraft } from "../services/booking-draft-service.js?flow=grooming-order-20261016";

if (requireCustomerSession("./sign-in.html")) {
  const groomingOption = document.getElementById("groomingOption");
  const clinicVisitOption = document.getElementById("clinicVisitOption");
  const accessMessage = document.getElementById("preRegistrationAccessMessage");

  groomingOption?.addEventListener("click", clearGroomingDraft);

  clinicVisitOption?.addEventListener("click", () => {
    clearClinicVisitDraft();
  });

  try {
    const access = { ...await getPreRegistrationAccess() };
    if (access.allowed && access.sms_verification?.enforced && access.phone_verification_required) {
      access.allowed = false;
      access.message = "Use Pre-register on your dashboard to verify your phone number.";
    }
    setPreRegistrationLinkAccess(groomingOption, access.allowed, access.message);
    setPreRegistrationLinkAccess(clinicVisitOption, access.allowed, access.message);

    if (!access.allowed && accessMessage) {
      accessMessage.textContent = access.message;
      accessMessage.classList.remove("hidden");
    }
  } catch (error) {
    const unavailableMessage =
      "We could not verify pre-registration availability. Please refresh and try again.";
    setPreRegistrationLinkAccess(groomingOption, false, unavailableMessage);
    setPreRegistrationLinkAccess(clinicVisitOption, false, unavailableMessage);
    if (accessMessage) {
      accessMessage.textContent = unavailableMessage;
      accessMessage.classList.remove("hidden");
    }
    console.error("Failed to check pre-registration access:", error);
  }
}
