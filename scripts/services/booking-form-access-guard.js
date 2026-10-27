const ACCESS_MESSAGE_KEY = "bethlehem.preRegistrationAccessMessage";

export async function getPreRegistrationAccess() {
  return API.getPreRegistrationAccess();
}

export function setPreRegistrationLinkAccess(link, allowed, message = "") {
  if (!link) return;

  link.setAttribute("aria-disabled", String(!allowed));
  link.classList.toggle("pointer-events-none", !allowed);
  link.classList.toggle("cursor-not-allowed", !allowed);
  link.classList.toggle("opacity-60", !allowed);

  if (allowed) {
    link.removeAttribute("tabindex");
    link.removeAttribute("title");
  } else {
    link.setAttribute("tabindex", "-1");
    link.setAttribute("title", message);
  }
}

export async function enforceBookingFormAccessGuard(options = {}) {
  const dashboardPath = options.dashboardPath || "./dashboard.html";

  // Entry decisions require a server check even when a recent header result exists.
  const access = await API.getPreRegistrationAccess({ force: true });
  const phoneRequired = access.sms_verification?.enforced && access.phone_verification_required;
  if (access.allowed && !phoneRequired) {
    return false;
  }

  try {
    sessionStorage.setItem(ACCESS_MESSAGE_KEY, access.message || (phoneRequired ? "Open Online Registration to verify your phone number." : ""));
  } catch (error) {
    console.error("Failed to save the pre-registration access message:", error);
  }

  window.location.replace(dashboardPath);
  return true;
}

export async function initBookingFormAccessGuard(options = {}) {
  const dashboardPath = options.dashboardPath || "./dashboard.html";
  let blockedNow = false;

  try {
    blockedNow = await enforceBookingFormAccessGuard({ dashboardPath });
  } catch (error) {
    console.error("Failed to check pre-registration access:", error);
    window.location.replace(dashboardPath);
    blockedNow = true;
  }

  window.addEventListener("pageshow", async (event) => {
    if (event.persisted) {
      try {
        await enforceBookingFormAccessGuard({ dashboardPath });
      } catch (error) {
        console.error("Failed to recheck pre-registration access:", error);
        window.location.replace(dashboardPath);
      }
    }
  });

  return blockedNow;
}
