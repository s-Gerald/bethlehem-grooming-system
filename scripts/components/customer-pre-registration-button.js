const buttons = document.querySelectorAll("[data-pre-registration-button]");

function setButtonAccess(button, allowed) {
  button.setAttribute("aria-disabled", String(!allowed));
  button.classList.toggle("cursor-not-allowed", !allowed);
  button.classList.toggle("opacity-60", !allowed);

  if (allowed) {
    button.removeAttribute("tabindex");
    button.removeAttribute("title");
    return;
  }

  button.setAttribute("tabindex", "-1");
  button.setAttribute("title", "Pre-registration ongoing");
}

let openingPhonePreview = false;

buttons.forEach((button) => {
  button.addEventListener("click", async (event) => {
    if (button.getAttribute("aria-disabled") === "true") {
      event.preventDefault();
      return;
    }

    // Preview only: this must never become a production verification gate.
    const localPreview = button.hasAttribute("data-phone-verification-preview")
      && ["localhost", "127.0.0.1", "[::1]"].includes(window.location.hostname)
      && new URLSearchParams(window.location.search).get("smsPreview") === "1";
    if (!localPreview || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

    event.preventDefault();
    if (openingPhonePreview) return;
    openingPhonePreview = true;
    try {
      // The dashboard supplies its existing profile request; never fetch again here.
      const user = await button.customerProfileReady;
      if (button.getAttribute("aria-disabled") === "true") return;
      if (!user || (user.phone && user.phone_verification_status === "verified")) {
        window.location.assign(button.href);
        return;
      }
      const { openPhoneVerificationPreview } = await import("./customer-phone-verification.js?v=20261021-2");
      if (button.getAttribute("aria-disabled") !== "true") openPhoneVerificationPreview(button, user);
    } catch (error) {
      console.error("Unable to open the local phone verification preview:", error);
      if (button.getAttribute("aria-disabled") !== "true") window.location.assign(button.href);
    } finally {
      openingPhonePreview = false;
    }
  });
});

let accessRequest = null;

async function loadPreRegistrationAccess() {
  if (accessRequest) return accessRequest;

  accessRequest = (async () => {
    try {
      const access = await API.getPreRegistrationAccess();
      buttons.forEach((button) => setButtonAccess(button, access.allowed));
    } catch (error) {
      buttons.forEach((button) => setButtonAccess(button, false));
      console.error("Failed to check pre-registration access:", error);
    } finally {
      accessRequest = null;
    }
  })();

  return accessRequest;
}

function scheduleAccessCheck() {
  const start = () => loadPreRegistrationAccess();

  if (typeof window.requestIdleCallback === "function") {
    window.requestIdleCallback(start, { timeout: 1200 });
    return;
  }

  window.setTimeout(start, 200);
}

if (buttons.length > 0) {
  const cached = API.readCustomerCache("/pre-registration/access");
  if (cached?.fresh) buttons.forEach((button) => setButtonAccess(button, cached.data.allowed));
  window.requestAnimationFrame(scheduleAccessCheck);
  window.setInterval(() => {
    if (document.visibilityState === "visible") void loadPreRegistrationAccess();
  }, 15000);
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") void loadPreRegistrationAccess();
  });
}
