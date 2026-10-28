import "../auth/verification-code.js?v=auth-feedback-20261028";
import "../utils/philippine-mobile.js?v=mobile-format-20261029";

let dialog, trigger, codeControl, phoneControl, timer;
let busy = false, challenge = null, resendAt = 0, expiresAt = 0, session = 0;
let navigate = true, canVerify = false, challengeStatus = null;
let step = "entry", candidatePhone = "", maskedDestination = "", replacing = false;
let allowedPrefixes = [];

function field(id) { return dialog.querySelector(`#phoneVerification${id}`); }
function phoneMessage(text = "") {
  field("PhoneError").textContent = text;
  field("PhoneError").hidden = !text;
  field("Phone").setAttribute("aria-invalid", String(Boolean(text)));
}
function phoneValidation(phone) {
  phone = window.PhilippineMobile.unformat(phone);
  const error = window.PhilippineMobile.formatError(phone);
  if (error) return error;
  return allowedPrefixes.includes(phone.slice(0, 4)) ? "" : "SMS verification isn't supported for this number.";
}
function message(text, input = null, success = false) {
  field("Message").textContent = text;
  field("Message").hidden = !text;
  field("Message").setAttribute("role", success ? "status" : "alert");
  input?.setAttribute("aria-invalid", String(Boolean(text)));
}
function maskPhone(phone) { return `${phone.slice(0, 4)} •••• ${phone.slice(-3)}`; }
function updateControls() {
  let moveExpiredFocus = false;
  if (canVerify && Date.now() >= expiresAt && !busy) {
    moveExpiredFocus = document.activeElement === field("Code");
    canVerify = false; challengeStatus = "expired";
    codeControl.clear();
    if (step === "challenge") showChallenge();
  }
  const waiting = Math.max(0, Math.ceil((resendAt - Date.now()) / 1000));
  const locked = challengeStatus === "locked";
  field("Next").disabled = busy || locked || waiting > 0 || Boolean(phoneValidation(field("Phone").value));
  field("Next").textContent = busy ? "Please wait…" : waiting ? `Send Code in ${window.VerificationCode.formatCountdown(waiting)}` : "Send Code";
  field("Next").setAttribute("aria-busy", String(busy));
  field("Phone").disabled = busy;
  field("Password").disabled = busy;
  field("Code").disabled = busy;
  field("Verify").disabled = busy || !canVerify || field("Code").value.length !== 6 || Date.now() >= expiresAt;
  field("Verify").setAttribute("aria-busy", String(busy));
  field("VerifyLabel").textContent = busy ? "Please wait…" : "Verify and Continue";
  field("Spinner").hidden = !busy;
  field("Resend").disabled = busy || waiting > 0 || locked;
  field("Resend").textContent = waiting ? `Resend Code in ${window.VerificationCode.formatCountdown(waiting)}` : "Resend Code";
  const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
  field("Expiry").textContent = canVerify && remaining ? `Code expires in ${window.VerificationCode.formatCountdown(remaining)}` : "";
  field("Entry").hidden = step !== "entry";
  field("Recipient").hidden = step === "entry";
  field("Otp").hidden = step === "entry" || !canVerify;
  field("Recovery").hidden = step === "entry" || canVerify;
  field("Change").hidden = false;
  field("Change").disabled = busy;
  field("Back").hidden = step !== "entry" || !canVerify;
  field("Back").disabled = busy;
  field("Retry").disabled = busy || locked || waiting > 0;
  field("Retry").setAttribute("aria-busy", String(busy));
  field("Retry").textContent = busy ? "Please wait…" : waiting
    ? `Try Again in ${window.VerificationCode.formatCountdown(waiting)}` : "Try Again";
  if (moveExpiredFocus && dialog.open) focusStep();
}
function setTiming(data) {
  challenge = data.challenge_id;
  challengeStatus = data.status || "active";
  canVerify = data.can_verify !== false && challengeStatus === "active" && data.expires_in > 0;
  resendAt = Date.now() + Math.max(0, Number(data.resend_after)) * 1000;
  expiresAt = Date.now() + Math.max(0, Number(data.expires_in)) * 1000;
  window.clearInterval(timer);
  timer = window.setInterval(updateControls, 1000);
}
function closeDialog() { dialog.close(); }
function applyCooldown(error) {
  const seconds = Number(error.data?.resend_after ?? error.retryAfter);
  if (seconds > 0) {
    resendAt = Date.now() + seconds * 1000;
    window.clearInterval(timer); timer = window.setInterval(updateControls, 1000);
  }
}
function showError(error) {
  applyCooldown(error);
  const passwordError = error.errors?.current_password?.[0];
  const phoneError = error.errors?.phone?.[0];
  const text = passwordError || phoneError || error.message || "Unable to connect. Please try again.";
  if (phoneError) {
    if (step === "challenge") showPhoneEntry(true);
    message(""); phoneMessage(phoneError); return;
  }
  if (error.data?.code === "attempts_exceeded" || error.code === "attempts_exceeded") {
    challengeStatus = "locked"; canVerify = false;
    if (step === "challenge") showChallenge();
  }
  message(text, passwordError ? field("Password") : challenge ? field("Code") : field("Phone"));
}
function showChallenge() {
  step = "challenge";
  phoneMessage();
  const unavailable = !canVerify && !["expired", "locked"].includes(challengeStatus);
  field("Title").textContent = unavailable ? "Verification Unavailable" : "Verify Phone Number";
  field("Description").textContent = unavailable ? (challengeStatus === "failed"
    ? "SMS could not be sent. Try again or use another number." : "SMS could not be confirmed. Try again or use another number.")
    : canVerify ? "Enter the 6-digit code sent to" : "";
  field("Number").textContent = maskedDestination;
  field("Number").hidden = false;
  field("Next").type = "button";
  field("PasswordField").hidden = true;
  field("Password").value = "";
  message(challengeStatus === "locked" ? "Too many attempts. Try again later."
    : challengeStatus === "expired" ? "Code expired. Send a new code." : "");
}
function showPhoneEntry(force = false) {
  if (busy && force !== true) return;
  step = "entry";
  field("Title").textContent = "Change Phone Number";
  field("Description").textContent = "Enter your mobile number to continue.";
  phoneControl.setValue(candidatePhone);
  phoneMessage();
  field("PasswordField").hidden = !replacing;
  field("Password").value = "";
  field("Next").type = "submit";
  codeControl.clear();
  message(challengeStatus === "locked" ? "Too many attempts. Try again later." : "");
  updateControls(); field("Phone").focus();
}
function focusStep() {
  (step === "entry" ? field("Phone") : canVerify ? field("Code")
    : field("Retry").disabled ? field("Change") : field("Retry")).focus();
}
function confirmedSend(data, phone = candidatePhone) {
  if (data?.success !== true || !data.challenge_id || !Number.isFinite(data.expires_in) || !Number.isFinite(data.resend_after)) {
    throw new Error("SMS sending could not be confirmed. Please try again.");
  }
  setTiming(data);
  candidatePhone = phone;
  maskedDestination = data.masked_destination || (phone ? maskPhone(phone) : maskedDestination);
  replacing = replacing || data.replacement === true;
  showChallenge();
  codeControl.clear();
  if (data.replacement) window.dispatchEvent(new CustomEvent("customer-phone:pending"));
}
async function requestCode(resend = false) {
  if (busy || challengeStatus === "locked" || Date.now() < resendAt) return;
  const phone = resend || step === "challenge" ? candidatePhone : window.PhilippineMobile.unformat(field("Phone").value);
  if (!resend && phoneValidation(phone)) {
    message(""); phoneMessage(phoneValidation(phone)); field("Phone").focus(); return;
  }
  if (!resend && challenge && canVerify && phone === candidatePhone && Date.now() < expiresAt) {
    showChallenge(); updateControls(); focusStep(); return;
  }
  if (!resend && replacing && !field("Password").value) {
    message("Enter your current password.", field("Password")); field("Password").focus(); return;
  }
  const currentSession = session;
  busy = true; message(""); updateControls();
  try {
    const data = resend ? await API.resendPhoneVerification(challenge)
      : await API.sendPhoneVerification(phone, replacing ? field("Password").value : null);
    if (session !== currentSession || !dialog.open) return;
    confirmedSend(data, phone);
  } catch (error) {
    if (session !== currentSession || !dialog.open) return;
    if (error.data?.challenge) {
      confirmedSend(error.data.challenge, phone);
    } else if (![403, 409, 422, 429].includes(error.status)) {
      let recovered;
      if (error.status === 0) {
        // Recover a possibly accepted send once. Never retry the paid request.
        try { ({ challenge: recovered } = await API.getPhoneVerificationState()); }
        catch { /* Keep an explicit retry available if recovery is also offline. */ }
      }
      if (session !== currentSession || !dialog.open) return;
      if (recovered) confirmedSend(recovered, recovered.challenge_id === challenge ? candidatePhone : phone);
      else {
        challenge = null; canVerify = false; challengeStatus = "uncertain";
        candidatePhone = phone;
        maskedDestination = phone ? maskPhone(phone) : maskedDestination;
        applyCooldown(error); showChallenge();
      }
    } else showError(error);
  } finally {
    if (session === currentSession && dialog.open) {
      field("Password").value = "";
      busy = false; updateControls(); focusStep();
    }
  }
}
function createDialog() {
  const stylesheet = document.createElement("link");
  stylesheet.rel = "stylesheet";
  stylesheet.href = new URL("../../css/components/verification-code.css?v=phone-eligibility-20261028", import.meta.url).href;
  document.head.appendChild(stylesheet);
  dialog = document.createElement("dialog");
  dialog.id = "phoneVerificationDialog";
  dialog.className = "phone-verification-dialog";
  dialog.setAttribute("aria-labelledby", "phoneVerificationTitle");
  dialog.setAttribute("aria-describedby", "phoneVerificationDescription");
  dialog.setAttribute("closedby", "none");
  dialog.innerHTML = `
    <button id="phoneVerificationClose" type="button" class="phone-verification-close" aria-label="Close phone verification">
      <svg width="20" height="20" viewBox="0 0 256 256" aria-hidden="true"><use href="../../assets/icons/phosphor.svg#x"></use></svg>
    </button>
    <div class="verification-heading">
      <h2 id="phoneVerificationTitle">Verify Phone Number</h2>
      <p id="phoneVerificationDescription"></p>
      <div id="phoneVerificationRecipient" class="phone-verification-recipient" hidden>
        <p id="phoneVerificationNumber" class="verification-recipient"></p>
        <button id="phoneVerificationChange" type="button" class="verification-link">Change number</button>
      </div>
    </div>
    <form id="phoneVerificationForm" novalidate>
      <div id="phoneVerificationEntry">
        <div id="phoneVerificationPhoneField">
          <label for="phoneVerificationPhone" class="phone-verification-label">Phone Number</label>
          <input id="phoneVerificationPhone" class="phone-verification-phone" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="09XX XXX XXXX" maxlength="13" aria-invalid="false" aria-describedby="phoneVerificationPhoneError" />
          <p id="phoneVerificationPhoneError" class="verification-message phone-verification-error" aria-live="polite" aria-atomic="true" hidden></p>
        </div>
        <div id="phoneVerificationPasswordField" class="phone-verification-password" hidden>
          <label for="phoneVerificationPassword" class="phone-verification-label">Current Password</label>
          <input id="phoneVerificationPassword" class="phone-verification-phone" type="password" autocomplete="current-password" maxlength="255" aria-describedby="phoneVerificationMessage" />
        </div>
        <button id="phoneVerificationNext" type="submit" class="verification-primary">Send Code</button>
        <button id="phoneVerificationBack" type="button" class="verification-link phone-verification-back" hidden>Back to code</button>
      </div>
      <div id="phoneVerificationRecovery" hidden>
        <button id="phoneVerificationRetry" type="button" class="verification-primary">Try Again</button>
      </div>
      <div id="phoneVerificationOtp" hidden>
        <label for="phoneVerificationCode" class="sr-only">6-digit phone verification code</label>
        <div class="verification-code-cells">
          <input id="phoneVerificationCode" class="verification-code-input" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" aria-describedby="phoneVerificationNumber phoneVerificationMessage" />
          ${'<span class="verification-code-cell" aria-hidden="true"></span>'.repeat(6)}
        </div>
        <button id="phoneVerificationVerify" type="submit" class="verification-primary" disabled>
          <svg id="phoneVerificationSpinner" class="animate-spin" width="18" height="18" viewBox="0 0 256 256" aria-hidden="true" hidden><use href="../../assets/icons/phosphor.svg#spinner-gap"></use></svg>
          <span id="phoneVerificationVerifyLabel">Verify and continue</span>
        </button>
        <div class="verification-resend">
          <p id="phoneVerificationExpiry"></p>
          <button id="phoneVerificationResend" type="button" class="verification-link">Resend Code</button>
        </div>
      </div>
      <p id="phoneVerificationMessage" class="verification-message" role="alert" hidden></p>
    </form>
    <div class="phone-verification-actions">
      <button id="phoneVerificationCancel" type="button" class="verification-link">Cancel</button>
    </div>`;
  document.body.appendChild(dialog);
  codeControl = window.VerificationCode.bind(field("Code"), dialog.querySelectorAll(".verification-code-cell"), () => {
    message(""); field("Code").setAttribute("aria-invalid", "false"); updateControls();
  });
  dialog.addEventListener("cancel", (event) => event.preventDefault());
  dialog.addEventListener("close", () => {
    session++; busy = false; window.clearInterval(timer); codeControl.clear(); field("Password").value = ""; trigger?.focus();
  });
  field("Close").addEventListener("click", closeDialog);
  phoneControl = window.PhilippineMobile.bind(field("Phone"), {
    required: false, errorId: 'phoneVerificationPhoneError', getError: phoneValidation,
    onError: phoneMessage, onChange: updateControls,
  });
  field("Cancel").addEventListener("click", closeDialog);
  field("Change").addEventListener("click", showPhoneEntry);
  field("Back").addEventListener("click", () => {
    if (busy || !canVerify || Date.now() >= expiresAt) return;
    showChallenge(); updateControls(); focusStep();
  });
  field("Retry").addEventListener("click", () => {
    if (busy || challengeStatus === "locked" || Date.now() < resendAt) return;
    if (!challenge && (!candidatePhone || replacing)) { showPhoneEntry(); return; }
    return requestCode(Boolean(challenge));
  });
  field("Form").addEventListener("submit", async (event) => {
    event.preventDefault();
    if (busy) return;
    const currentSession = session;
    if (step === "entry") return requestCode();
    if (field("Verify").disabled || !challenge) return;
    busy = true; message(""); updateControls();
    try {
      const data = await API.verifyPhoneVerification(challenge, field("Code").value);
      if (session !== currentSession || !dialog.open) return;
      if (data?.success !== true) throw new Error("Phone verification could not be confirmed.");
      message(data.message || "Phone number verified.", null, true);
      // The API invalidates profile/access caches after server confirmation.
      let user;
      try { ({ user } = await API.getMe("customer", { force: true })); }
      catch { /* Verification is committed; the next page retries the profile read. */ }
      if (session !== currentSession || !dialog.open) return;
      if (user) {
        trigger.customerProfileReady = Promise.resolve(user);
        window.dispatchEvent(new CustomEvent("customer-phone:verified", { detail: { user, message: data.message } }));
      }
      const destination = navigate && trigger.getAttribute("aria-disabled") !== "true" ? trigger.href : null;
      closeDialog();
      if (destination) window.location.assign(destination);
    } catch (error) {
      if (session === currentSession && dialog.open) showError(error);
    } finally {
      if (session === currentSession && dialog.open) {
        busy = false;
        updateControls();
        if (!canVerify && step === "challenge") focusStep();
      }
    }
  });
  field("Resend").addEventListener("click", () => { if (challenge) return requestCode(true); });
}

export async function openPhoneVerification(button, user, options = {}) {
  if (!dialog) createDialog();
  if (dialog.open || busy) return;
  const previousChallenge = challenge, previousCandidate = candidatePhone;
  session++; trigger = button; challenge = null; resendAt = 0; expiresAt = 0; busy = true;
  allowedPrefixes = [];
  navigate = options.navigate !== false; canVerify = false; challengeStatus = null;
  step = "entry"; candidatePhone = ""; maskedDestination = "";
  const currentSession = session;
  codeControl.clear();
  const phone = String(user.phone ?? "").trim();
  phoneControl.setValue(phone);
  phoneMessage();
  field("PhoneField").hidden = false;
  replacing = Boolean(phone && user.phone_verification_status === "verified");
  field("PasswordField").hidden = !replacing;
  field("Password").value = "";
  field("Password").setAttribute("aria-invalid", "false");
  field("Title").textContent = replacing ? "Change Phone Number" : phone ? "Verify Phone Number" : "Add Phone Number";
  field("Description").textContent = replacing ? `Current number: ${maskPhone(phone)}` : "Enter your mobile number to continue.";
  if (replacing) field("Phone").value = "";
  field("Number").hidden = true;
  field("Entry").hidden = false;
  field("Next").type = "submit";
  field("Otp").hidden = true;
  message(""); updateControls(); dialog.showModal();
  try {
    const { challenge: saved, allowed_prefixes: prefixes } = await API.getPhoneVerificationState();
    if (session !== currentSession || !dialog.open) return;
    allowedPrefixes = Array.isArray(prefixes) ? prefixes : [];
    if (saved) confirmedSend(saved, saved.challenge_id === previousChallenge ? previousCandidate
      : !replacing && phone && maskPhone(phone) === saved.masked_destination ? phone : "");
    else if (window.PhilippineMobile.unformat(field("Phone").value).length === 11) phoneMessage(phoneValidation(field("Phone").value));
  } catch (error) {
    if (session === currentSession && dialog.open) { showError(error); closeDialog(); throw error; }
  } finally {
    if (session === currentSession && dialog.open) {
      busy = false; updateControls(); focusStep();
    }
  }
}
