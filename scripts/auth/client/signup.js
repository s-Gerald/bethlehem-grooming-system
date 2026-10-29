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

  if (!signupForm || !phoneInput || !emailInput) return;

  let recoveryToken = "";
  let busy = false;
  const context = window.SignupContext;
  const continuation = document.getElementById("signupContinuation");
  const continueButton = document.getElementById("signupContinue");
  const editButton = document.getElementById("signupEdit");
  const params = new URLSearchParams(window.location.search);
  if (params.get("review") === "1") {
    try {
      const saved = JSON.parse(sessionStorage.getItem("pendingSignupDetails") || "null");
      const expiresAt = Number(sessionStorage.getItem("pendingRegistrationRecoveryUntil"));
      recoveryToken = sessionStorage.getItem("pendingRegistrationRecoveryToken") || "";
      if (!saved?.email || !recoveryToken || !Number.isFinite(expiresAt)
          || expiresAt <= Date.now() || expiresAt > Date.now() + 600000) throw new Error("Expired review");
      const proposed = context.draft(saved.email);
      for (const id of ["firstName", "lastName", "username", "email", "phone"]) {
        document.getElementById(id).value = typeof saved[id] === "string" ? saved[id] : "";
      }
      for (const [id, value] of Object.entries(proposed)) document.getElementById(id).value = value;
      passwordInput.value = "";
      confirmPasswordInput.value = "";
      emailInput.readOnly = true;
      document.getElementById("signupTitle").textContent = "Review your details";
      document.getElementById("signupDescription").textContent = Object.keys(proposed).length
        ? "Your proposed changes are shown. Confirm your details and choose a password."
        : "Email confirmed. Review your details and choose a password.";
      document.getElementById("signupBtnLabel").textContent = "Complete registration";
    } catch {
      window.location.replace("./verify-email.html?recover=1");
      return;
    }
  }
  const touched = new Set();
  for (const id of ["firstName", "lastName", "username", "phone"]) {
    document.getElementById(id).addEventListener("input", () => touched.add(id));
  }
  function returningContext() { return !recoveryToken && context.read(emailInput.value); }
  function renderContinuation() {
    const returning = !!returningContext();
    continuation.classList.toggle("hidden", !returning);
    submitButton.classList.toggle("hidden", returning);
    return returning;
  }
  function continueVerification() {
    const pending = returningContext();
    if (!pending) { renderContinuation(); return; }
    try {
      sessionStorage.setItem("pendingVerificationEmail", pending.email);
      sessionStorage.setItem("pendingVerificationState", "resumed");
      sessionStorage.setItem("pendingVerificationRegistrationUntil", String(pending.expiresAt));
    } catch { /* The waiting screen can restore non-secret context. */ }
    window.location.replace("./verify-email.html");
  }
  continueButton.addEventListener("click", () => { if (!busy) continueVerification(); });
  editButton.addEventListener("click", async () => {
    if (busy || !returningContext()) return;
    const values = {};
    for (const id of ["firstName", "lastName", "username", "phone"]) {
      const value = document.getElementById(id).value.trim();
      if (value || touched.has(id)) values[id] = value;
    }
    const email = emailInput.value.trim().toLowerCase();
    setBusyState(true);
    try {
      await API.requestRegistrationReview(email);
      context.propose(email, values);
      continueVerification();
    } catch (error) {
      showMessage(error.message || "Couldn't continue. Try again.");
    } finally { setBusyState(false); }
  });
  emailInput.addEventListener("input", renderContinuation);
  emailInput.addEventListener("change", renderContinuation);
  window.addEventListener("pageshow", renderContinuation);
  window.addEventListener("focus", renderContinuation);
  window.addEventListener("storage", renderContinuation);
  renderContinuation();

  const fieldIds = {first_name: "firstName", last_name: "lastName", email: "email", username: "username",
    phone: "phone", password: "password", password_confirmation: "confirmPassword"};
  const fieldErrors = new Map();
  for (const id of ["username", "phone", "password", "confirmPassword"]) {
    const input = document.getElementById(id);
    const feedback = document.createElement("p");
    feedback.id = `${id}ServerError`;
    feedback.className = "hidden mt-2 text-sm text-red-700";
    feedback.setAttribute("role", "alert");
    input.parentElement.appendChild(feedback);
    fieldErrors.set(id, feedback);
    const described = input.getAttribute("aria-describedby") || "";
    input.setAttribute("aria-describedby", `${described} ${feedback.id}`.trim());
    input.addEventListener("input", () => {
      feedback.textContent = "";
      feedback.classList.add("hidden");
      input.setAttribute("aria-invalid", "false");
      clearMessage();
    });
  }

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
    required: false, errorId: 'phoneServerError', onChange: clearMessage,
  });

  signupForm.addEventListener("submit", async function (e) {
    e.preventDefault();
    if (busy) return;
    if (renderContinuation()) { continuation.focus(); return; }

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
    for (const id of ["firstName", "lastName", "email", "username", "phone", "password"]) document.getElementById(id).setAttribute("aria-invalid", "false");

    if (!emailField.validate()) { emailInput.focus(); return; }

    if (!phoneField.validate()) {
      phoneInput.focus();
      return;
    }

    if (!signupForm.checkValidity()) {
      signupForm.reportValidity();
      return;
    }

    if (password !== confirmPassword) {
      showMessage("Passwords do not match.");
      return;
    }

    if (password.length < 8) {
      showMessage("Password must be at least 8 characters long.");
      return;
    }

    setBusyState(true);

    try {
      const payload = {
        first_name:            firstName,
        last_name:             lastName,
        username:              username || null,
        email:                 email || null,
        phone:                 phone || null,
        password,
        password_confirmation: confirmPassword,
      };
      const data = recoveryToken
        ? await API.updatePendingRegistration(payload, recoveryToken)
        : await API.register(payload);

      if (recoveryToken && data?.token && data?.user?.role === "customer") {
        context.clear(email);
        for (const key of ["pendingRegistrationRecoveryToken", "pendingRegistrationRecoveryUntil", "pendingRegistrationEditToken", "pendingSignupDetails", "pendingVerificationEmail", "pendingVerificationPhone", "pendingVerificationResendUntil", "pendingVerificationRegistrationUntil", "pendingVerificationState", "pendingVerificationLinkUntil", "pendingVerificationResendLimited", "pendingVerificationDeliveryFailed", "pending_registration_email_delivery"]) {
          try { sessionStorage.removeItem(key); } catch { /* session already established */ }
        }
        window.location.replace("./dashboard.html");
        return;
      }

      if (data?.requires_verification) {
        if (!data.registration_token && data.registration_state !== "requested") {
          showMessage("Registration couldn't continue. No new verification email was confirmed. Try another email or sign in.");
          setBusyState(false);
          return;
        }
        const pendingContext = context.remember(email);
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
        window.location.replace("./verify-email.html");
        return;
      }

      // Registration never implies authentication, including when talking to
      // an older backend response that omits requires_verification.
      window.location.replace("./sign-in.html");
    } catch (error) {
      if (error.errors) {
        const [field, firstError] = Object.entries(error.errors)[0];
        if (field === 'first_name' || field === 'last_name') {
          const input = document.getElementById(field === 'first_name' ? 'firstName' : 'lastName');
          HumanName.showError(input, [].concat(firstError)[0]); input.focus(); setBusyState(false); return;
        }
        if (field === "email") {
          if (emailField.setError(Array.isArray(firstError) ? firstError[0] : firstError, email)) emailInput.focus();
          setBusyState(false);
          return;
        }
        const id = fieldIds[field];
        if (id === 'phone' && PhilippineMobile.unformat(phoneInput.value) !== phone) { setBusyState(false); return; }
        const feedback = fieldErrors.get(id);
        const text = Array.isArray(firstError) ? firstError[0] : firstError;
        if (id === 'phone') phoneField.setError(text);
        else if (feedback) { feedback.textContent = text; feedback.classList.remove("hidden"); }
        else showMessage(text);
        if (id) {
          const input = document.getElementById(id);
          input.setAttribute("aria-invalid", "true");
          input.focus();
        }
      } else {
        showMessage(error.message || "Couldn't create your account. Please try again.");
      }
      setBusyState(false);
    }
  });

  function setBusyState(isBusy) {
    busy = isBusy;
    submitButton.disabled = isBusy;
    continueButton.disabled = isBusy;
    editButton.disabled = isBusy;
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
