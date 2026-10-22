const buttons = document.querySelectorAll("[data-pre-registration-button]");
let openingPhoneVerification = false;
let accessState = null;

function setButtonAccess(button, allowed) {
  button.setAttribute("aria-disabled", String(!allowed));
  button.classList.toggle("cursor-not-allowed", !allowed);
  button.classList.toggle("opacity-60", !allowed);
  if (allowed) {
    button.removeAttribute("tabindex"); button.removeAttribute("title");
    return;
  }
  button.setAttribute("tabindex", "-1");
  button.setAttribute("title", "Pre-registration ongoing");
}
function entryMessage(button, text) {
  let notice = document.getElementById("phoneVerificationEntryMessage");
  if (!notice) {
    notice = document.createElement("p");
    notice.id = "phoneVerificationEntryMessage";
    notice.className = "text-sm text-red-700";
    notice.setAttribute("role", "alert");
    button.parentElement.appendChild(notice);
  }
  notice.textContent = text;
}
buttons.forEach((button) => {
  button.addEventListener("click", async (event) => {
    if (button.getAttribute("aria-disabled") === "true") { event.preventDefault(); return; }
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (openingPhoneVerification) return;
    openingPhoneVerification = true;
    try {
      if (!accessState) await loadPreRegistrationAccess();
      if (button.getAttribute("aria-disabled") === "true" || !accessState) return;
      const flags = accessState.sms_verification || { available: false, enforced: false };
      if (!flags.available && !flags.enforced) { window.location.assign(button.href); return; }
      // Reuse the dashboard's existing request, or the API's shared profile cache on other pages.
      const user = button.customerProfileReady ? await button.customerProfileReady : (await API.getMe("customer")).user;
      if (button.getAttribute("aria-disabled") === "true") return;
      if (!user) throw new Error("Unable to check your phone status. Please try again.");
      if (user.phone && user.phone_verification_status === "verified") { window.location.assign(button.href); return; }
      if (!flags.available) throw new Error("SMS verification is unavailable. Please contact the clinic before pre-registering.");
      const { openPhoneVerification } = await import("./customer-phone-verification.js?v=20261022");
      if (button.getAttribute("aria-disabled") !== "true") openPhoneVerification(button, user);
    } catch (error) {
      entryMessage(button, error.message || "Unable to open phone verification. Please try again.");
    } finally { openingPhoneVerification = false; }
  });
});

let accessRequest = null;
async function loadPreRegistrationAccess() {
  if (accessRequest) return accessRequest;
  accessRequest = (async () => {
    try {
      const access = await API.getPreRegistrationAccess();
      accessState = access;
      buttons.forEach((button) => setButtonAccess(button, access.allowed));
    } catch (error) {
      accessState = null;
      buttons.forEach((button) => setButtonAccess(button, false));
      console.error("Failed to check pre-registration access:", error);
    } finally { accessRequest = null; }
  })();
  return accessRequest;
}
function scheduleAccessCheck() {
  const start = () => loadPreRegistrationAccess();
  if (typeof window.requestIdleCallback === "function") { window.requestIdleCallback(start, { timeout: 1200 }); return; }
  window.setTimeout(start, 200);
}
if (buttons.length > 0) {
  const cached = API.readCustomerCache("/pre-registration/access");
  if (cached?.fresh) {
    accessState = cached.data;
    buttons.forEach((button) => setButtonAccess(button, cached.data.allowed));
  }
  window.requestAnimationFrame(scheduleAccessCheck);
  window.setInterval(() => { if (document.visibilityState === "visible") void loadPreRegistrationAccess(); }, 15000);
  document.addEventListener("visibilitychange", () => { if (document.visibilityState === "visible") void loadPreRegistrationAccess(); });
}
