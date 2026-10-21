// Connected to pages/client/verify-email.html
// Preserves already-issued signup links and the separate login confirmation flow.

document.addEventListener("DOMContentLoaded", () => {
  const stateVerifying = document.getElementById("stateVerifying");
  const stateSuccess   = document.getElementById("stateSuccess");
  const statePending   = document.getElementById("statePending");
  const stateLoginCode = document.getElementById("stateLoginCode");
  const stateError     = document.getElementById("stateError");
  const resendSection  = document.getElementById("resendSection");
  const successTitle   = document.getElementById("successTitle");
  const successMessage = document.getElementById("successMessage");
  const errorTitle     = document.getElementById("errorTitle");
  const errorMessage   = document.getElementById("errorMessage");
  const loginCodeEmail = document.getElementById("loginCodeEmail");
  const loginCodeForm  = document.getElementById("loginCodeForm");
  const loginCodeInput = document.getElementById("loginCode");
  const loginCodeSubmit = document.getElementById("loginCodeSubmit");
  const loginCodeMessage = document.getElementById("loginCodeMessage");
  const resendEmail    = document.getElementById("resendEmail");
  const resendForm     = document.getElementById("resendForm");
  const resendSubmit   = document.getElementById("resendSubmit");
  const resendMessage  = document.getElementById("resendMessage");
  const loginCodeResendBtn       = document.getElementById("loginCodeResendBtn");
  const loginCodeResendCountdown = document.getElementById("loginCodeResendCountdown");

  let resendCodeTimer = null;
  let resendUntil = 0;
  let loginBusy = false;
  let stopDeliveryCheck = () => {};

  function checkDelivery(receipt) {
    stopDeliveryCheck();
    if (!receipt) return;
    stopDeliveryCheck = window.AuthenticationEmailDelivery.watch(receipt, (status) => {
      const text = window.AuthenticationEmailDelivery.failureMessage(status);
      if (text) {
        showLoginCodeMessage(text, false);
      }
    });
  }

  function showState(name) {
    [stateVerifying, stateSuccess, statePending, stateLoginCode, stateError]
      .forEach((element) => element.classList.add("hidden"));
    const element = {
      verifying: stateVerifying,
      success: stateSuccess,
      pending: statePending,
      loginCode: stateLoginCode,
      error: stateError,
    }[name];
    if (element) element.classList.remove("hidden");
  }

  const fragmentParams = new URLSearchParams(window.location.hash.slice(1));
  const queryParams = new URLSearchParams(window.location.search);
  const verificationToken = fragmentParams.get("token")
    || queryParams.get("token");
  const loginCodeMode = queryParams.get("mode") === "login";
  if (!verificationToken && !loginCodeMode) return;

  if (verificationToken) {
    // Authentication links are credentials. Remove them from the address bar
    // and browser history before making any network request.
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete("token");
    cleanUrl.hash = "";
    history.replaceState(
      null,
      "",
      `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`,
    );
  }

  if (verificationToken) {
    document.getElementById("statePending").classList.add("hidden");
    showState("verifying");

    (async () => {
      try {
        await API.verifyEmail(verificationToken);
        showState("success");
        setTimeout(() => {
          window.location.replace("./dashboard.html");
        }, 1500);
      } catch (error) {
        showState("error");
        if (error.expired) {
          errorMessage.textContent = "This verification link has expired. Enter your email below to get a new one.";
          if (error.email) resendEmail.value = error.email;
        } else {
          errorMessage.textContent = error.message
            || "The verification link is invalid or has already been used.";
        }
        resendSection.classList.remove("hidden");
      }
    })();
  } else if (loginCodeMode) {
    document.title = "Verify your sign-in | Bethlehem Animal Clinic";

    if (!API.hasPendingLoginConfirmation()) {
      errorTitle.textContent = "Sign-In Request Unavailable";
      errorMessage.textContent = "This browser no longer has a pending sign-in. Please return to sign in and try again.";
      showState("error");
    } else {
      const email = API.getPendingLoginConfirmationEmail();
      loginCodeEmail.textContent = window.VerificationCode.maskEmail(email);
      showState("loginCode");
      loginCodeInput.focus();
      let deadline = 0;
      try { deadline = Number(sessionStorage.getItem("pendingLoginResendUntil")) || 0; } catch { /* UX only */ }
      startResendCountdown(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)));
      checkDelivery(API.getEmailDeliveryToken("login"));
    }
  }

  const loginOtp = window.VerificationCode.bind(loginCodeInput,
    [...stateLoginCode.querySelectorAll(".verification-code-cell")], () => {
      loginCodeSubmit.disabled = loginBusy || loginCodeInput.value.length !== 6;
      clearLoginCodeMessage();
    });

  loginCodeForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearLoginCodeMessage();

    const code = loginCodeInput.value;
    if (loginBusy || !/^\d{6}$/.test(code)) return;

    setLoginCodeBusy(true);

    try {
      const response = await API.confirmLoginCode(code);
      stopDeliveryCheck();
      successTitle.textContent = "Sign-in verified";
      successMessage.textContent = "Redirecting to your account…";
      showState("success");
      setTimeout(() => {
        window.location.replace(
          ["admin", "staff"].includes(response?.user?.role)
            ? "../admin/dashboard.html"
            : "./dashboard.html",
        );
      }, 750);
    } catch (error) {
      if (error.expired || error.status === 403) {
        errorTitle.textContent = "Sign-In Confirmation Failed";
        errorMessage.textContent = error.message
          || "This sign-in request is no longer available. Please sign in again.";
        showState("error");
      } else {
        showLoginCodeMessage(error.status === 429 ? "Too many attempts. Try again later."
          : error.status === 422 ? "Invalid or expired code." : "Couldn't verify the code. Try again.");
      }
    } finally {
      setLoginCodeBusy(false);
    }
  });

  function startResendCountdown(seconds = 60) {
    clearInterval(resendCodeTimer);
    resendUntil = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
    try { sessionStorage.setItem("pendingLoginResendUntil", String(resendUntil)); } catch { /* UX only */ }
    function tick() {
      const remaining = Math.max(0, Math.ceil((resendUntil - Date.now()) / 1000));
      loginCodeResendBtn.disabled = remaining > 0 || loginBusy;
      loginCodeResendBtn.classList.toggle("hidden", remaining > 0);
      loginCodeResendCountdown.classList.toggle("hidden", remaining === 0);
      loginCodeResendCountdown.textContent = `Resend in ${window.VerificationCode.formatCountdown(remaining)}`;
      if (!remaining) {
        clearInterval(resendCodeTimer);
        resendCodeTimer = null;
      }
    }
    resendCodeTimer = setInterval(tick, 1000);
    tick();
  }

  loginCodeResendBtn.addEventListener("click", async () => {
    if (loginCodeResendBtn.disabled || loginBusy) return;
    clearLoginCodeMessage();
    stopDeliveryCheck();
    loginBusy = true;
    loginOtp.clear();
    loginCodeInput.focus();
    loginCodeResendBtn.disabled = true;
    loginCodeResendBtn.textContent = "Sending...";
    loginCodeSubmit.disabled = true;
    try {
      const result = await API.resendLoginCode();
      startResendCountdown(result?.resend_after ?? 60);
      checkDelivery(result?.email_delivery_token);
    } catch (error) {
      if (error.status === 429) {
        startResendCountdown(error.retryAfter || 60);
        showLoginCodeMessage("Too many attempts. Try again later.", false);
      } else if (error.expired || error.status === 422 || error.status === 403) {
        errorTitle.textContent = "Sign-in expired";
        errorMessage.textContent = error.message || "Please sign in again.";
        showState("error");
      } else {
        showLoginCodeMessage("Couldn't send the code. Try again.", false);
      }
    } finally {
      loginBusy = false;
      loginCodeResendBtn.textContent = "Resend code";
      loginCodeResendBtn.disabled = Date.now() < resendUntil;
      loginCodeSubmit.disabled = loginCodeInput.value.length !== 6;
    }
  });

  resendForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearResendMessage();

    const email = resendEmail.value.trim();
    if (!email) return;

    setResendBusy(true);

    try {
      const result = await API.resendVerification(email);
      sessionStorage.setItem("pendingVerificationEmail", email);
      sessionStorage.setItem("pendingVerificationResendUntil", String(Date.now() + (result?.resend_after ?? 45) * 1000));
      window.location.replace("./verify-email.html");
    } catch (error) {
      if (error.status === 429) {
        showResendMessage("error", error.message || "Too many requests. Please wait before trying again.");
      } else {
        showResendMessage("error", error.message || "Failed to resend. Please try again.");
      }
    } finally {
      setResendBusy(false);
    }
  });

  function setResendBusy(busy) {
    resendSubmit.disabled = busy;
    const label = document.getElementById("resendBtnLabel");
    if (label) label.textContent = busy ? "Sending…" : "Resend Verification Email";
  }

  function setLoginCodeBusy(busy) {
    loginBusy = busy;
    loginCodeInput.disabled = busy;
    loginCodeResendBtn.disabled = busy || Date.now() < resendUntil;
    loginCodeSubmit.disabled = busy || loginCodeInput.value.length !== 6;
    const label = document.getElementById("loginCodeBtnLabel");
    if (label) label.textContent = busy ? "Verifying..." : "Verify and continue";
  }

  function showLoginCodeMessage(text, invalid = true) {
    loginCodeMessage.className = "verification-message";
    loginCodeMessage.textContent = text;
    loginCodeInput.setAttribute("aria-invalid", invalid ? "true" : "false");
  }

  function clearLoginCodeMessage() {
    loginCodeMessage.className = "hidden";
    loginCodeMessage.textContent = "";
    loginCodeInput.setAttribute("aria-invalid", "false");
  }

  function showResendMessage(type, text) {
    const styles = {
      success: "border-green-200 bg-green-50 text-green-700",
      error: "border-red-200 bg-red-50 text-red-700",
    };
    resendMessage.className = `mt-3 rounded-2xl border px-4 py-3 text-sm ${styles[type] || styles.error}`;
    resendMessage.textContent = text;
    resendMessage.classList.remove("hidden");
  }

  function clearResendMessage() {
    resendMessage.className = "mt-3 hidden rounded-2xl border px-4 py-3 text-sm";
    resendMessage.textContent = "";
  }
});
