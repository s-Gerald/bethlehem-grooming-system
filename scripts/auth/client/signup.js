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

  let busy = false, finalizing = false, departed = false, submitted = null;
  const context = window.SignupContext;
  let attempt = context.read(), attemptEmail = "";
  const title = document.getElementById("signupTitle");
  const description = document.getElementById("signupDescription");
  const defaultDescription = description.textContent;

  function clearPasswords() {
    passwordInput.value = "";
    confirmPasswordInput.value = "";
  }
  function restorePasswords() {
    if (!submitted) return;
    passwordInput.value = submitted.password;
    confirmPasswordInput.value = submitted.password_confirmation;
    submitted = null;
  }
  function clearAttempt() {
    attempt = null;
    attemptEmail = "";
    context.clear();
  }
  function showForm(helper = defaultDescription) {
    signupForm.classList.remove("hidden");
    title.textContent = "Sign Up";
    description.textContent = helper;
    setBusyState(false);
  }
  const waiting = window.SignupVerification.create({
    onApproved: () => finish(),
    onEdit() {
      restorePasswords();
      showForm();
      clearMessage();
      title.focus();
    },
    onExpired(message = "Registration expired. Start again.") {
      restorePasswords();
      clearAttempt();
      showForm();
      showMessage(message);
    },
  });
  function showWaiting(data = {}) {
    signupForm.classList.add("hidden");
    title.textContent = "Check your email";
    description.textContent = "Open the verification link sent to";
    setBusyState(false);
    clearPasswords();
    const result = waiting.start(attempt, attemptEmail, data);
    title.focus();
    return result;
  }
  async function finish() {
    if (finalizing || departed || !attempt) return;
    if (!submitted) {
      showForm("Email verified. Re-enter your details to finish creating your account.");
      return;
    }
    finalizing = true;
    setBusyState(true);
    title.textContent = "Creating your account...";
    description.textContent = "Your email is verified.";
    try {
      const result = await API.finalizeRegistration(submitted, attempt.token);
      if (departed) return;
      if (!result?.token || result?.user?.role !== "customer") throw new Error("Could not complete registration. Try again.");
      submitted = null;
      clearPasswords();
      context.clear();
      waiting.stop();
      departed = true;
      window.location.replace("/account");
    } catch (error) {
      if (departed) return;
      restorePasswords();
      if (["registration_expired", "registration_unavailable"].includes(error.code)) {
        clearAttempt();
        showForm();
      } else showForm("Your email is verified. Update your details and try again.");
      showErrors(error);
    } finally {
      finalizing = false;
      if (!departed) setBusyState(false);
    }
  }
  // Refresh/mobile eviction keeps only the attempt credential. Fetch its email
  // through both bound credentials and ask for the missing form/password again.
  if (attempt) {
    setBusyState(true);
    clearPasswords();
    API.registrationStatus(attempt.token).then(async data => {
      if (departed) return;
      attemptEmail = data.email;
      emailInput.value = data.email;
      attempt = context.save({...attempt, expiresAt: Date.parse(data.registration_expires_at) || attempt.expiresAt});
      if (data.state === "completed") {
        const result = await API.finalizeRegistration({}, attempt.token);
        if (departed) return;
        if (result?.token && result?.user?.role === "customer") {
          context.clear();
          departed = true;
          window.location.replace("/account");
          return;
        }
      }
      showForm(data.state === "approved" ? "Email verified. Re-enter your details to finish creating your account."
        : "Re-enter your details to continue.");
    }).catch(error => {
      if (departed) return;
      if (["registration_expired", "registration_unavailable"].includes(error.code)) clearAttempt();
      showForm();
      showMessage(error.message || "Connection lost. Re-enter your details and try again.");
    });
  }

  const fieldIds = {first_name: "firstName", last_name: "lastName", email: "email", username: "username",
    phone: "phone", password: "password", password_confirmation: "confirmPassword", terms_accepted: "terms"};
  const fieldErrors = new Map();
  for (const id of ["username", "phone", "password", "confirmPassword", "terms"]) {
    const input = document.getElementById(id);
    const feedback = document.createElement("p");
    feedback.id = `${id}ServerError`;
    feedback.className = "hidden mt-2 text-sm text-red-700";
    feedback.setAttribute("role", "alert");
    if (input === passwordInput || input === confirmPasswordInput) {
      input.parentElement.insertAdjacentElement("afterend", feedback);
    } else {
      const feedbackContainer = id === "terms" ? document.getElementById("termsAgreement") : input.parentElement;
      feedbackContainer.appendChild(feedback);
    }
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

  const emailField = AccountEmail.bind(emailInput);

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
  const recoveryLink = document.getElementById("signupConflictRecovery");
  const footer = document.getElementById("signupFooter");
  const serverErrors = new Map();
  const revisions = new Map();
  function renderConflict() {
    const emailConflict = serverErrors.get("email")?.accountExists;
    const hasConflict = emailConflict || serverErrors.get("phone")?.accountExists;
    conflictArea.classList.toggle("hidden", !hasConflict);
    footer.classList.toggle("hidden", Boolean(hasConflict));
    recoveryLink.classList.toggle("hidden", !emailConflict);
  }
  for (const id of Object.values(fieldIds)) {
    const input = document.getElementById(id);
    input.addEventListener("input", () => {
      clearMessage();
      revisions.set(id, (revisions.get(id) || 0) + 1);
      if (id === "password") {
        serverErrors.delete("confirmPassword");
        revisions.set("confirmPassword", (revisions.get("confirmPassword") || 0) + 1);
      }
      if (serverErrors.delete(id)) renderConflict();
    });
    input.addEventListener("blur", () => {
      const feedback = serverErrors.get(id);
      if (feedback) showFieldError(input, feedback.message);
    });
  }
  recoveryLink.addEventListener("click", () => {
    if (!serverErrors.get("email")?.accountExists) return;
    try { sessionStorage.setItem("pendingPasswordResetEmail", emailInput.value.trim()); } catch { /* Prefill is optional. */ }
  });
  renderConflict();
  window.addEventListener('pagehide',()=>{
    departed=true;submitted=null;clearPasswords();waiting.stop();
  });
  window.addEventListener('pageshow',event=>{
    if(!event.persisted)return;
    departed=false;submitted=null;clearPasswords();
    showForm('Re-enter your password to continue creating your account.');
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

    serverErrors.clear();
    renderConflict();
    setBusyState(true);
    lastSubmittedRevisions = new Map(revisions);
    lastSubmittedEmail = email;
    lastSubmittedPhone = phone;

    try {
      submitted = {
        first_name: firstName, last_name: lastName, username: username || null, email, phone: phone || null,
        password, password_confirmation: confirmPassword, terms_accepted: termsInput.checked,
      };
      if (attempt && !attemptEmail) {
        const restored = await API.registrationStatus(attempt.token);
        attemptEmail = restored.email;
      }
      if (!attempt || attemptEmail !== email) attempt = context.create();
      attemptEmail = email;
      const data = await API.register({...submitted, registration_token: attempt.token});
      if (departed) return;
      attempt = context.save({...attempt,
        expiresAt: Date.parse(data.registration_expires_at) || attempt.expiresAt,
        resendUntil: data.resend_after === undefined ? attempt.resendUntil
          : Date.now() + window.AuthenticationResend.seconds(data) * 1000,
      });
      if (data.state === "approved" || data.state === "completed") { await finish(); return; }
      if (!data.requires_verification && data.state !== "pending") throw new Error("Could not start verification. Try again.");
      showWaiting(data);
    } catch (error) {
      if (departed) return;
      submitted = null;
      if (["registration_expired", "registration_unavailable"].includes(error.code)) clearAttempt();
      showErrors(error);
      setBusyState(false);
    }
  });

  let lastSubmittedRevisions = new Map(), lastSubmittedEmail = "", lastSubmittedPhone = "";
  function showFieldError(input, message) {
    const id = input.id;
    if (id === "firstName" || id === "lastName") HumanName.showError(input, message);
    else if (id === "email") emailField.setError(message, lastSubmittedEmail);
    else if (id === "phone") phoneField.setError(message);
    else if (credentialBindings[id]) credentialBindings[id].setError(message);
    else {
      const feedback = fieldErrors.get(id);
      feedback.textContent = message;
      feedback.classList.remove("hidden");
    }
    input.setAttribute("aria-invalid", "true");
  }

  function showErrors(error) {
    if (error.errors) {
      let firstInput;
      for (const [field, messages] of Object.entries(error.errors)) {
        const id = fieldIds[field];
        const input = id && document.getElementById(id);
        if (input && (revisions.get(id) || 0) !== (lastSubmittedRevisions.get(id) || 0)) continue;
        if (id === "email" && AccountEmail.normalize(emailInput.value) !== lastSubmittedEmail) continue;
        if (id === "phone" && PhilippineMobile.unformat(phoneInput.value) !== lastSubmittedPhone) continue;
        const message = [].concat(messages)[0];
        if (!input) { showMessage(message); continue; }
        serverErrors.set(id, {message, accountExists: error.data?.conflicts?.[field] === "account_exists"});
        showFieldError(input, message);
        firstInput ||= input;
      }
      renderConflict();
      firstInput?.focus();
    } else {
      showMessage(error.message || "Couldn't create your account. Please try again.");
    }
  }

  function setBusyState(isBusy) {
    busy = isBusy;
    submitButton.disabled = isBusy;
    signupForm.setAttribute("aria-busy", String(isBusy));
    const label = document.getElementById("signupBtnLabel");
    if (label) label.textContent = isBusy ? "Please wait..." : "Create Account";
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
