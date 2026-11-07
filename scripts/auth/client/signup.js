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
  let attempt = context.read(), attemptEmail = "", attemptStarted = Boolean(attempt);
  const defaultDescription = document.getElementById("signupDescription").textContent;

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
  function showForm(description = defaultDescription) {
    signupForm.classList.remove("hidden");
    document.getElementById("signupTitle").textContent = "Sign Up";
    document.getElementById("signupDescription").textContent = description;
    setBusyState(false);
  }
  const waiting = window.SignupVerification.create({
    onApproved: () => finish(),
    onEdit() {
      restorePasswords();
      showForm();
      clearMessage();
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
    document.getElementById("signupTitle").textContent = "Check your email";
    document.getElementById("signupDescription").textContent = "Open the verification link sent to your email to finish creating your account.";
    setBusyState(false);
    clearPasswords();
    return waiting.start(attempt, attemptEmail, data);
  }
  async function finish() {
    if (finalizing || departed || !attempt) return;
    if (!submitted) {
      showForm("Email verified. Re-enter your details to finish creating your account.");
      return;
    }
    finalizing = true;
    setBusyState(true);
    document.getElementById("signupTitle").textContent = "Creating your account...";
    document.getElementById("signupDescription").textContent = "Your email is verified.";
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
        : "Re-enter your details to continue. Your verification link still works.");
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
  const conflictMessage = document.getElementById("signupConflictMessage");
  const recoveryLink = document.getElementById("signupConflictRecovery");
  const footer = document.getElementById("signupFooter");
  const conflicts = new Set();
  const revisions = new Map();
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
      clearMessage();
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

    setBusyState(true);
    lastSubmittedRevisions = new Map(revisions);
    lastSubmittedEmail = email;
    lastSubmittedPhone = phone;

    try {
      submitted = {
        first_name: firstName, last_name: lastName, username: username || null, email, phone: phone || null,
        password, password_confirmation: confirmPassword, terms_accepted: termsInput.checked,
      };
      let data;
      if (attempt && !attemptEmail) {
        const restored = await API.registrationStatus(attempt.token);
        attemptEmail = restored.email;
      }
      if (attempt && attemptEmail === email && attemptStarted) {
        data = await API.registrationStatus(attempt.token);
      } else {
        if (!attempt || attemptEmail !== email) attempt = context.create();
        attemptEmail = email;
        attemptStarted = false;
        data = await API.register({email, registration_token: attempt.token});
      }
      if (departed) return;
      attemptStarted = true;
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
  function showErrors(error) {
    if (error.errors) {
      let firstInput;
      for (const [field, messages] of Object.entries(error.errors)) {
        const id = fieldIds[field];
        const input = id && document.getElementById(id);
        if (input && (revisions.get(id) || 0) !== (lastSubmittedRevisions.get(id) || 0)) continue;
        if (id === "email" && AccountEmail.normalize(emailInput.value) !== lastSubmittedEmail) continue;
        if (id === "phone" && PhilippineMobile.unformat(phoneInput.value) !== lastSubmittedPhone) continue;
        const text = [].concat(messages)[0];
        if ((field === "email" || field === "phone") && error.data?.conflicts?.[field] === "account_exists") {
          conflicts.add(id);
          input.setAttribute("aria-invalid", "true");
          continue;
        }
        if (field === "first_name" || field === "last_name") HumanName.showError(input, text);
        else if (id === "email") emailField.setError(text, lastSubmittedEmail);
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
