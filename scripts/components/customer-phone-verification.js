import "../auth/verification-code.js";

let dialog, trigger, codeControl, timer;
let busy = false, challenge = null, resendAt = 0, expiresAt = 0, session = 0;

function field(id) { return dialog.querySelector(`#phoneVerification${id}`); }
function message(text, input = null, success = false) {
  field("Message").textContent = text;
  field("Message").hidden = !text;
  field("Message").setAttribute("role", success ? "status" : "alert");
  input?.setAttribute("aria-invalid", String(Boolean(text)));
}
function maskPhone(phone) { return `${phone.slice(0, 4)} •••• ${phone.slice(-3)}`; }
function updateControls() {
  const waiting = Math.max(0, Math.ceil((resendAt - Date.now()) / 1000));
  field("Next").disabled = busy;
  field("Next").textContent = busy ? "Sending…" : "Send code";
  field("Phone").disabled = busy;
  field("Code").disabled = busy;
  field("Verify").disabled = busy || field("Code").value.length !== 6 || Date.now() >= expiresAt;
  field("Verify").setAttribute("aria-busy", String(busy));
  field("VerifyLabel").textContent = busy ? "Verifying…" : "Verify and continue";
  field("Spinner").hidden = !busy;
  field("Resend").disabled = busy || waiting > 0;
  field("Resend").textContent = waiting ? `Resend code in ${window.VerificationCode.formatCountdown(waiting)}` : "Resend code";
  if (challenge && Date.now() >= expiresAt && !busy) message("Code expired. Send a new code.");
}
function setTiming(data) {
  challenge = data.challenge_id;
  resendAt = Date.now() + Math.max(0, Number(data.resend_after)) * 1000;
  expiresAt = Date.now() + Math.max(0, Number(data.expires_in)) * 1000;
  window.clearInterval(timer);
  timer = window.setInterval(updateControls, 1000);
}
function closeDialog() { dialog.close(); }
function showError(error) {
  const seconds = Number(error.data?.resend_after);
  if (seconds > 0) resendAt = Date.now() + seconds * 1000;
  const text = error.errors?.phone?.[0] || error.message || "Unable to connect. Please try again.";
  message(text, challenge ? field("Code") : field("Phone"));
}
function confirmedSend(data) {
  if (data?.success !== true || !data.challenge_id || !Number.isFinite(data.expires_in) || !Number.isFinite(data.resend_after)) {
    throw new Error("SMS sending could not be confirmed. Please try again.");
  }
  setTiming(data);
  field("Title").textContent = "Verify Phone Number";
  field("Description").textContent = "Enter the 6-digit code sent to";
  field("Number").textContent = maskPhone(field("Phone").value);
  field("Number").hidden = false;
  field("Entry").hidden = true;
  field("Next").type = "button";
  field("Otp").hidden = false;
  codeControl.clear();
  message("");
}
function createDialog() {
  const stylesheet = document.createElement("link");
  stylesheet.rel = "stylesheet";
  stylesheet.href = new URL("../../css/components/verification-code.css?v=phone-20261022", import.meta.url).href;
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
      <p id="phoneVerificationNumber" class="verification-recipient" hidden></p>
    </div>
    <form id="phoneVerificationForm" novalidate>
      <div id="phoneVerificationEntry">
        <div id="phoneVerificationPhoneField">
          <label for="phoneVerificationPhone" class="phone-verification-label">Phone Number</label>
          <input id="phoneVerificationPhone" class="phone-verification-phone" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="09XXXXXXXXX" maxlength="11" pattern="09[0-9]{9}" aria-describedby="phoneVerificationMessage" />
        </div>
        <button id="phoneVerificationNext" type="submit" class="verification-primary">Send code</button>
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
          <button id="phoneVerificationResend" type="button" class="verification-link">Resend code</button>
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
    session++; window.clearInterval(timer); field("Code").value = ""; trigger?.focus();
  });
  field("Close").addEventListener("click", closeDialog);
  field("Cancel").addEventListener("click", closeDialog);
  field("Form").addEventListener("submit", async (event) => {
    event.preventDefault();
    if (busy) return;
    const currentSession = session;
    const sending = !field("Entry").hidden;
    const phone = field("Phone").value.trim();
    if (sending && !/^09\d{9}$/.test(phone)) {
      message("Enter a valid Philippine mobile number (09XXXXXXXXX).", field("Phone")); field("Phone").focus(); return;
    }
    if (!sending && (field("Verify").disabled || !challenge)) return;
    busy = true; message(""); updateControls();
    try {
      if (sending) {
        const data = await API.sendPhoneVerification(phone);
        if (session !== currentSession || !dialog.open) return;
        confirmedSend(data);
      } else {
        const data = await API.verifyPhoneVerification(challenge, field("Code").value);
        if (session !== currentSession || !dialog.open) return;
        if (data?.success !== true) throw new Error("Phone verification could not be confirmed.");
        message("Phone number verified.", null, true);
        // The API invalidates profile/access caches after server confirmation.
        let user;
        try { ({ user } = await API.getMe("customer", { force: true })); }
        catch { /* Verification is committed; the next page retries the profile read. */ }
        if (session !== currentSession || !dialog.open) return;
        if (user) {
          trigger.customerProfileReady = Promise.resolve(user);
          window.dispatchEvent(new CustomEvent("customer-phone:verified", { detail: { user } }));
        }
        const destination = trigger.getAttribute("aria-disabled") !== "true" ? trigger.href : null;
        closeDialog();
        if (destination) window.location.assign(destination);
      }
    } catch (error) {
      if (session === currentSession && dialog.open) showError(error);
    } finally {
      busy = false;
      if (session === currentSession && dialog.open) {
        updateControls();
        if (sending && challenge) field("Code").focus();
      }
    }
  });
  field("Resend").addEventListener("click", async () => {
    if (busy || !challenge || Date.now() < resendAt) return;
    const currentSession = session;
    busy = true; message(""); updateControls();
    field("Resend").textContent = "Sending…";
    try {
      const data = await API.resendPhoneVerification(challenge);
      if (session !== currentSession || !dialog.open) return;
      confirmedSend(data);
    } catch (error) {
      if (session === currentSession && dialog.open) showError(error);
    } finally {
      busy = false;
      if (session === currentSession && dialog.open) { updateControls(); field("Code").focus(); }
    }
  });
}

export function openPhoneVerification(button, user) {
  if (!dialog) createDialog();
  if (dialog.open || busy) return;
  session++; trigger = button; challenge = null; resendAt = 0; expiresAt = 0; busy = false;
  codeControl.clear();
  const phone = String(user.phone ?? "").trim();
  field("Phone").value = phone;
  field("Phone").setAttribute("aria-invalid", "false");
  field("PhoneField").hidden = false;
  field("Title").textContent = phone ? "Verify Phone Number" : "Add Phone Number";
  field("Description").textContent = phone ? "Confirm your phone number to receive a code." : "Enter a Philippine mobile number.";
  field("Number").hidden = true;
  field("Entry").hidden = false;
  field("Next").type = "submit";
  field("Otp").hidden = true;
  message(""); updateControls(); dialog.showModal(); field("Phone").focus();
}
