import "../auth/verification-code.js";

// Local UI preview only. No SMS operations, persistence, or verification result.
let dialog;
let trigger;
let codeControl;

function field(id) {
  return dialog.querySelector(`#phoneVerification${id}`);
}

function message(text, input = null) {
  field("Message").textContent = text;
  field("Message").hidden = !text;
  input?.setAttribute("aria-invalid", String(Boolean(text)));
}

function maskPhone(phone) {
  return `${phone.slice(0, 4)} •••• ${phone.slice(-3)}`;
}

function setPreviewState() {
  const state = field("State").value;
  const loading = state === "loading";
  field("Verify").disabled = loading || field("Code").value.length !== 6;
  field("Verify").setAttribute("aria-busy", String(loading));
  field("VerifyLabel").textContent = loading ? "Verifying… (preview)" : "Verify and continue";
  if (loading) field("Spinner").removeAttribute("hidden");
  else field("Spinner").setAttribute("hidden", "");
  message(state === "error" ? "SMS verification is unavailable. No number has been verified." : "");
  field("Code").setAttribute("aria-invalid", String(state === "error"));
}

function createDialog() {
  const stylesheet = document.createElement("link");
  stylesheet.rel = "stylesheet";
  stylesheet.href = new URL("../../css/components/verification-code.css?v=phone-preview-20261021", import.meta.url).href;
  document.head.appendChild(stylesheet);

  dialog = document.createElement("dialog");
  dialog.id = "phoneVerificationDialog";
  dialog.className = "phone-verification-dialog";
  dialog.setAttribute("aria-labelledby", "phoneVerificationTitle");
  dialog.setAttribute("aria-describedby", "phoneVerificationNotice");
  dialog.setAttribute("closedby", "none");
  dialog.innerHTML = `
    <button id="phoneVerificationClose" type="button" class="phone-verification-close" aria-label="Close phone verification">
      <svg width="20" height="20" viewBox="0 0 256 256" aria-hidden="true"><use href="../../assets/icons/phosphor.svg#x"></use></svg>
    </button>
    <div class="verification-heading">
      <h2 id="phoneVerificationTitle">Verify Phone Number</h2>
      <p id="phoneVerificationDescription"></p>
      <p id="phoneVerificationNumber" class="verification-recipient"></p>
    </div>
    <p id="phoneVerificationNotice" class="phone-verification-notice">Local preview — SMS verification is unavailable. No code is sent or phone number saved.</p>
    <form id="phoneVerificationForm" novalidate>
      <div id="phoneVerificationEntry">
        <div id="phoneVerificationPhoneField">
          <label for="phoneVerificationPhone" class="phone-verification-label">Phone Number</label>
          <input id="phoneVerificationPhone" class="phone-verification-phone" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="09XXXXXXXXX" maxlength="11" pattern="09[0-9]{9}" aria-describedby="phoneVerificationMessage" />
        </div>
        <button id="phoneVerificationNext" type="submit" class="verification-primary">Preview code input</button>
      </div>
      <div id="phoneVerificationOtp" hidden>
        <label for="phoneVerificationCode" class="sr-only">6-digit phone verification code</label>
        <div class="verification-code-cells">
          <input id="phoneVerificationCode" class="verification-code-input" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" aria-describedby="phoneVerificationNotice phoneVerificationNumber phoneVerificationMessage" />
          ${'<span class="verification-code-cell" aria-hidden="true"></span>'.repeat(6)}
        </div>
        <button id="phoneVerificationVerify" type="submit" class="verification-primary" disabled>
          <svg id="phoneVerificationSpinner" class="animate-spin" width="18" height="18" viewBox="0 0 256 256" aria-hidden="true" hidden><use href="../../assets/icons/phosphor.svg#spinner-gap"></use></svg>
          <span id="phoneVerificationVerifyLabel">Verify and continue</span>
        </button>
        <div class="verification-resend">
          <button id="phoneVerificationResend" type="button" class="verification-link">Resend code</button>
        </div>
        <div class="phone-verification-state">
          <label for="phoneVerificationState">Preview state</label>
          <select id="phoneVerificationState">
            <option value="ready">Ready</option>
            <option value="loading">Loading</option>
            <option value="error">Error</option>
          </select>
        </div>
      </div>
      <p id="phoneVerificationMessage" class="verification-message" role="alert" hidden></p>
    </form>
    <div class="phone-verification-actions">
      <button id="phoneVerificationCancel" type="button" class="verification-link">Cancel</button>
      <button id="phoneVerificationContinue" type="button" class="verification-link">Continue to pre-registration</button>
    </div>`;
  document.body.appendChild(dialog);

  codeControl = window.VerificationCode.bind(field("Code"), dialog.querySelectorAll(".verification-code-cell"), () => {
    field("Code").setAttribute("aria-invalid", "false");
    setPreviewState();
  });
  dialog.addEventListener("cancel", (event) => event.preventDefault());
  dialog.addEventListener("close", () => trigger?.focus());
  field("Close").addEventListener("click", () => dialog.close());
  field("Cancel").addEventListener("click", () => dialog.close());
  field("State").addEventListener("change", setPreviewState);
  function previewCodeInput() {
    const phone = field("Phone").value.trim();
    if (!/^09\d{9}$/.test(phone)) {
      message("Enter a valid Philippine mobile number (09XXXXXXXXX).", field("Phone"));
      field("Phone").focus();
      return;
    }
    field("Phone").setAttribute("aria-invalid", "false");
    field("Title").textContent = "Verify Phone Number";
    field("Description").textContent = "Preview the 6-digit verification code input for";
    field("Number").textContent = maskPhone(phone);
    field("Number").hidden = false;
    field("Entry").hidden = true;
    field("Next").type = "button";
    field("Otp").hidden = false;
    message("");
    field("Code").focus();
  }
  field("Form").addEventListener("submit", (event) => {
    event.preventDefault();
    if (!field("Entry").hidden) {
      previewCodeInput();
      return;
    }
    message("SMS verification is unavailable. No number has been verified.");
  });
  field("Resend").addEventListener("click", () => {
    message("SMS verification is unavailable. No code has been sent.");
  });
  field("Continue").addEventListener("click", () => {
    if (trigger.getAttribute("aria-disabled") === "true") {
      message("Pre-registration ongoing. You cannot start another pre-registration.");
      return;
    }
    // Navigation only; never emit verification success or alter account data.
    dialog.close();
    window.location.assign(trigger.href);
  });
}

export function openPhoneVerificationPreview(button, user) {
  // Defense in depth: direct imports cannot expose this UI on a deployed host.
  if (!["localhost", "127.0.0.1", "[::1]"].includes(window.location.hostname)
      || new URLSearchParams(window.location.search).get("smsPreview") !== "1") return;
  if (!dialog) createDialog();
  if (dialog.open) return;
  trigger = button;
  const phone = String(user.phone ?? "").trim();
  field("State").value = "ready";
  codeControl.clear();
  field("Phone").value = phone;
  field("Phone").setAttribute("aria-invalid", "false");
  field("Title").textContent = phone ? "Verify Phone Number" : "Add Phone Number";
  field("Description").textContent = phone ? "Confirm your number to preview the code input." : "Enter a Philippine mobile number.";
  field("Number").textContent = phone ? maskPhone(phone) : "";
  field("Number").hidden = !phone;
  field("PhoneField").hidden = Boolean(phone);
  field("Entry").hidden = false;
  field("Next").type = "submit";
  field("Otp").hidden = true;
  message("");
  dialog.showModal();
  (phone ? field("Next") : field("Phone")).focus();
}
