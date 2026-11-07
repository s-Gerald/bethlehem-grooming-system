// Connected to pages/client/signup.html
// Depends on: api.js (loaded before this script in the HTML)

document.addEventListener("DOMContentLoaded", () => {
  const signupForm          = document.getElementById("signupForm");
  const signupMessage       = document.getElementById("signupMessage");
  const submitButton        = document.getElementById("signupSubmit");
  const usernameInput       = document.getElementById("username");
  const phoneInput          = document.getElementById("phone");
  const emailInput          = document.getElementById("email");
  const passwordInput       = document.getElementById("password");
  const confirmPasswordInput = document.getElementById("confirmPassword");
  const termsInput          = document.getElementById("terms");

  if (!signupForm || !phoneInput || !emailInput) return;

  let recoveryToken = "";
  let busy = false;
  const context = window.SignupContext;
  const params = new URLSearchParams(window.location.search);
  if (params.get("review") === "1") {
    try {
      const saved = JSON.parse(sessionStorage.getItem("pendingSignupDetails") || "null");
      const expiresAt = Number(sessionStorage.getItem("pendingRegistrationRecoveryUntil"));
      recoveryToken = sessionStorage.getItem("pendingRegistrationRecoveryToken") || "";
      if (!saved?.email || !recoveryToken || !Number.isFinite(expiresAt)
          || expiresAt <= Date.now() || expiresAt > Date.now() + 600000) throw new Error("Expired review");
      for (const id of ["firstName", "lastName", "username", "email", "phone"]) {
        document.getElementById(id).value = typeof saved[id] === "string" ? saved[id] : "";
      }
      passwordInput.value = "";
      confirmPasswordInput.value = "";
      emailInput.readOnly = true;
      document.getElementById("signupTitle").textContent = "Review your details";
      document.getElementById("signupDescription").textContent = "Email confirmed. Review your details and choose a password.";
      document.getElementById("signupBtnLabel").textContent = "Complete registration";
    } catch {
      window.location.replace("/verify-email?recover=1");
      return;
    }
  }
  let replacement = recoveryToken ? null : context.fresh();
  function resetForm() {
    if (recoveryToken) return;
    for (const id of ["firstName", "lastName", "username", "email", "phone", "password", "confirmPassword"]) {
      const input = document.getElementById(id);
      input.value = "";
      input.setAttribute("aria-invalid", "false");
    }
    termsInput.checked = false;
  }
  resetForm();

  const fieldIds = {first_name: "firstName", last_name: "lastName", email: "email", username: "username",
    phone: "phone", password: "password", password_confirmation: "confirmPassword", terms_accepted: "terms"};
  const fieldErrors = new Map();
  for (const id of ["username", "phone", "password", "confirmPassword", "terms"]) {
    const input = document.getElementById(id);
    const feedback = document.createElement("p");
    feedback.id = `${id}ServerError`;
    feedback.className = "hidden mt-2 text-sm text-red-700";
    feedback.setAttribute("role", "alert");
    const feedbackContainer = id === "terms" ? document.getElementById("termsAgreement") : input.parentElement;
    feedbackContainer.appendChild(feedback);
    fieldErrors.set(id, feedback);
    const described = input.getAttribute("aria-describedby") || "";
    input.setAttribute("aria-describedby", `${described} ${feedback.id}`.trim());
    input.addEventListener("input", () => {
      feedback.textContent = "";
      feedback.classList.add("hidden");
      input.setAttribute("aria-invalid", "false");
    });
  }

  const credentialBindings = {
    username: CredentialPolicy.bind(usernameInput, { feedback: fieldErrors.get('username'), validate: () => CredentialPolicy.username(usernameInput.value.trim(), false) }),
    password: CredentialPolicy.bind(passwordInput, { feedback: fieldErrors.get('password'), validate: () => CredentialPolicy.password(passwordInput.value) }),
    confirmPassword: CredentialPolicy.bind(confirmPasswordInput, { feedback: fieldErrors.get('confirmPassword'), validate: () => CredentialPolicy.confirmation(confirmPasswordInput.value, passwordInput.value) }),
  };
  passwordInput.addEventListener('input', () => credentialBindings.confirmPassword.refresh());
  signupForm.noValidate = true;

  const originalEmail = recoveryToken ? emailInput.value : "";
  const emailField = AccountEmail.bind(emailInput, {
    isUnchanged: () => Boolean(originalEmail) && emailInput.value.trim().toLowerCase() === originalEmail.trim().toLowerCase(),
  });

  // Independent toggle — each button only controls its own field and icons.
  const toggleMap = [
    {
      button:  document.getElementById("togglePasswordVisibility"),
      input:   passwordInput,
      closed:  ".password-icon-closed",
      open:    ".password-icon-open",
    },
    {
      button:  document.getElementById("toggleConfirmPasswordVisibility"),
      input:   confirmPasswordInput,
      closed:  ".password-icon-closed",
      open:    ".password-icon-open",
    },
  ];

  toggleMap.forEach(({ button, input, closed, open }) => {
    if (!button || !input) return;
    button.addEventListener("click", function () {
      const show      = input.type === "password";
      const nextLabel = show ? "Hide password" : "Show password";

      input.type = show ? "text" : "password";
      this.setAttribute("aria-label", nextLabel);
      this.setAttribute("title", nextLabel);

      const iconClosed = this.querySelector(closed);
      const iconOpen   = this.querySelector(open);
      if (iconClosed && iconOpen) {
        iconClosed.classList.toggle("hidden", show);
        iconOpen.classList.toggle("hidden", !show);
      }
    });
  });

  const phoneField = PhilippineMobile.bind(phoneInput, {
    required: false, errorId: 'phoneServerError',
  });

  const conflictArea = document.getElementById("signupConflict");
  const conflictMessage = document.getElementById("signupConflictMessage");
  const recoveryLink = document.getElementById("signupConflictRecovery");
  const footer = document.getElementById("signupFooter");
  const conflicts = new Set();
  const revisions = new Map();
  let journey = 0;
  function renderConflict() {
    const field = conflicts.has("email") ? "email" : conflicts.has("phone") ? "phone" : "";
    conflictArea.classList.toggle("hidden", !field);
    footer.classList.toggle("hidden", !!field);
    recoveryLink.classList.toggle("hidden", field !== "email");
    if (conflicts.has("phone")) phoneInput.classList.add("!border-red-400");
    conflictMessage.textContent = field === "email" ? "An account with this email already exists."
      : field === "phone" ? "An account with this mobile number already exists." : "";
    for (const input of [emailInput, phoneInput]) {
      const ids = (input.getAttribute("aria-describedby") || "").split(" ").filter(id => id && id !== "signupConflictMessage");
      if (conflicts.has(input.id)) ids.push("signupConflictMessage");
      input.setAttribute("aria-describedby", ids.join(" "));
    }
  }
  for (const id of Object.values(fieldIds)) {
    document.getElementById(id).addEventListener("input", () => {
      revisions.set(id, (revisions.get(id) || 0) + 1);
      if (conflicts.delete(id)) {
        document.getElementById(id).setAttribute("aria-invalid", "false");
        renderConflict();
      }
    });
  }
  recoveryLink.addEventListener("click", () => {
    if (!conflicts.has("email")) return;
    try { sessionStorage.setItem("pendingPasswordResetEmail", emailInput.value.trim()); } catch { /* Prefill is optional. */ }
  });
  // Input utilities also validate on blur. Reapply a confirmed account conflict.
  for (const input of [emailInput, phoneInput]) input.addEventListener("blur", () => {
    if (conflicts.has(input.id)) {
      input.setAttribute("aria-invalid", "true");
      if (input === phoneInput) input.classList.add("!border-red-400");
    }
  });
  renderConflict();
  if (recoveryToken) context.bindExits(() => { journey++; });
  window.addEventListener("pagehide", () => { journey++; });
  window.addEventListener("pageshow", event => {
    if (!event.persisted || recoveryToken) return;
    replacement = context.fresh() || replacement;
    resetForm();
    conflicts.clear();
    renderConflict();
    clearMessage();
    emailField.clear();
    phoneField.clear();
    for (const id of ["firstName", "lastName"]) HumanName.reset(document.getElementById(id));
    for (const feedback of fieldErrors.values()) { feedback.textContent = ""; feedback.classList.add("hidden"); }
    setBusyState(false);
  });

  signupForm.addEventListener("submit", async function (e) {
    e.preventDefault();
    if (busy) return;

    if (!termsInput.checked) {
      const feedback = fieldErrors.get("terms");
      feedback.textContent = "Please agree to the Terms and Conditions.";
      feedback.classList.remove("hidden");
      termsInput.setAttribute("aria-invalid", "true");
      termsInput.focus();
      return;
    }

    const nameInputs = ['firstName', 'lastName'].map(id => document.getElementById(id));
    const validNames = nameInputs.map(input => HumanName.finish(input));
    if (validNames.includes(false)) { nameInputs[validNames.indexOf(false)].focus(); return; }

    const firstName      = document.getElementById("firstName").value.trim();
    const lastName       = document.getElementById("lastName").value.trim();
    const username       = usernameInput ? usernameInput.value.trim() : "";
    const email          = AccountEmail.normalize(emailInput.value);
    const phone          = PhilippineMobile.unformat(phoneInput.value);
    const password       = passwordInput.value;
    const confirmPassword = confirmPasswordInput.value;

    clearMessage();

    if (!emailField.validate()) { emailInput.focus(); return; }

    if (!phoneField.validate()) {
      phoneInput.focus();
      return;
    }

    let invalidCredential;
    for (const [id, binding] of Object.entries(credentialBindings)) {
      if (!binding.validate()) invalidCredential ||= document.getElementById(id);
    }
    if (invalidCredential) { invalidCredential.focus(); return; }

    if (!signupForm.checkValidity()) {
      signupForm.reportValidity();
      return;
    }

    setBusyState(true);
    const submittedRevisions = new Map(revisions);
    const currentJourney = journey;

    try {
      const payload = {
        first_name:            firstName,
        last_name:             lastName,
        username:              username || null,
        email:                 email || null,
        phone:                 phone || null,
        password,
        password_confirmation: confirmPassword,
        terms_accepted:         termsInput.checked,
        registration_token: replacement?.email?.toLowerCase() === email.toLowerCase() ? replacement.token : undefined,
      };
      const data = recoveryToken
        ? await API.updatePendingRegistration(payload, recoveryToken)
        : await API.register(payload);

      if (currentJourney !== journey) return;
      if (recoveryToken && data?.token && data?.user?.role === "customer") {
        context.clear(email);
        for (const key of ["pendingRegistrationRecoveryToken", "pendingRegistrationRecoveryUntil", "pendingRegistrationEditToken", "pendingSignupDetails", "pendingVerificationEmail", "pendingVerificationPhone", "pendingVerificationResendUntil", "pendingVerificationRegistrationUntil", "pendingVerificationState", "pendingVerificationLinkUntil", "pendingVerificationResendLimited", "pendingVerificationDeliveryFailed", "pending_registration_email_delivery"]) {
          try { sessionStorage.removeItem(key); } catch { /* session already established */ }
        }
        window.location.replace("/account");
        return;
      }

      if (data?.requires_verification) {
        if (!data.registration_token) {
          showMessage("Registration couldn't continue. No new verification email was confirmed. Try another email or sign in.");
          setBusyState(false);
          return;
        }
        const pendingContext = context.remember(email, data.registration_expires_at);
        try {
          sessionStorage.setItem("pendingVerificationRegistrationUntil", String(pendingContext.expiresAt));
          sessionStorage.setItem("pendingVerificationEmail", data.email ?? email);
          sessionStorage.setItem("pendingVerificationPhone", phone);
          sessionStorage.removeItem("pendingRegistrationRecoveryToken");
          if (data.registration_token) {
            sessionStorage.setItem("pendingRegistrationEditToken", data.registration_token);
          }
          sessionStorage.setItem("pendingVerificationState", data.registration_state === "requested" ? "requested"
            : data.registration_state === "resumed" ? "resumed" : data.email_delivery_queued === false ? "failed" : "queued");
          sessionStorage.setItem("pendingVerificationLinkUntil", String(Date.parse(data.verification_link_expires_at) || 0));
          sessionStorage.setItem("pendingVerificationResendUntil", String(Date.now() + (data.resend_after || 0) * 1000));
          sessionStorage.setItem("pendingVerificationResendLimited", data.resend_limited ? "1" : "0");
          if (data.email_delivery_queued === false && data.registration_state !== "resumed") {
            sessionStorage.setItem("pendingVerificationDeliveryFailed", "1");
          } else {
            sessionStorage.removeItem("pendingVerificationDeliveryFailed");
          }
        } catch { /* delivery and verification remain recoverable by email */ }
        window.location.replace("/verify-email");
        return;
      }

      // Registration never implies authentication, including when talking to
      // an older backend response that omits requires_verification.
      window.location.replace("/sign-in");
    } catch (error) {
      if (currentJourney !== journey) return;
      if (error.errors) {
        let firstInput;
        for (const [field, messages] of Object.entries(error.errors)) {
          const id = fieldIds[field];
          const input = id && document.getElementById(id);
          if (input && (revisions.get(id) || 0) !== (submittedRevisions.get(id) || 0)) continue;
          if (id === "email" && AccountEmail.normalize(emailInput.value) !== email) continue;
          if (id === "phone" && PhilippineMobile.unformat(phoneInput.value) !== phone) continue;
          const text = [].concat(messages)[0];
          if ((field === "email" || field === "phone") && error.data?.conflicts?.[field] === "account_exists") {
            conflicts.add(id);
            input.setAttribute("aria-invalid", "true");
            continue;
          }
          if (field === "first_name" || field === "last_name") HumanName.showError(input, text);
          else if (id === "email") emailField.setError(text, email);
          else if (id === "phone") phoneField.setError(text);
          else if (credentialBindings[id]) credentialBindings[id].setError(text);
          else if (fieldErrors.has(id)) {
            fieldErrors.get(id).textContent = text;
            fieldErrors.get(id).classList.remove("hidden");
          } else showMessage(text);
          if (input) { input.setAttribute("aria-invalid", "true"); firstInput ||= input; }
        }
        renderConflict();
        if (conflicts.size) conflictArea.focus();
        else firstInput?.focus();
      } else {
        showMessage(error.message || "Couldn't create your account. Please try again.");
      }
      setBusyState(false);
    }
  });

  function setBusyState(isBusy) {
    busy = isBusy;
    submitButton.disabled = isBusy;
    signupForm.setAttribute("aria-busy", String(isBusy));
    const label = document.getElementById("signupBtnLabel");
    if (label) label.textContent = isBusy ? "Creating your account..." : recoveryToken ? "Complete registration" : "Create Account";
  }

  function clearMessage() {
    signupMessage.className = "mt-5 hidden rounded-xl border px-4 py-3 text-sm";
    signupMessage.textContent = "";
  }

  function showMessage(text) {
    signupMessage.className = "mt-5 rounded-xl border px-4 py-3 text-sm border-red-200 bg-red-50 text-red-700";
    signupMessage.textContent = text;
  }
});
