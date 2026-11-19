// One underlying input drives six visual cells; codes never leave this input.
window.VerificationCode = {
  maskEmail(email) {
    const [local, domain] = String(email || "").split("@");
    return local && domain ? `${Array.from(local)[0]}***@${domain}` : "your email address";
  },
  formatCountdown(seconds, clock = false) {
    const remaining = Math.max(0, Math.ceil(Number(seconds) || 0));
    if (!Number.isFinite(remaining) || !remaining) return "";
    const minutes = Math.floor(remaining / 60);
    const tail = `${String(remaining % 60).padStart(2, "0")}s`;
    if (minutes >= 60) return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, "0")}m ${tail}`;
    if (clock) return minutes + ":" + String(remaining % 60).padStart(2, "0");
    return minutes ? `${minutes}m ${tail}` : `${remaining}s`;
  },
  cooldownMarkup(seconds, codes = false) {
    const time = this.formatCountdown(seconds, true);
    if (!time) return '';
    const reason = codes ? 'Too many incorrect codes.' : 'Too many attempts.';
    return `${reason} Try again in <span class="auth-cooldown-time">${time}</span>.`;
  },
  // This only renders an existing timer. Status regions change at its boundaries.
  renderCooldown(element, seconds, codes = false, status = null) {
    const markup = this.cooldownMarkup(seconds, codes);
    const active = Boolean(markup);
    if (status && active !== Boolean(element.authCooldownActive)) {
      status.textContent = active
        ? (codes ? 'Too many incorrect codes. ' : 'Too many attempts. ') + `Try again in ${this.formatCountdown(seconds)}.`
        : 'You can try again now.';
    }
    element.setAttribute?.('aria-live', 'off');
    element.setAttribute?.('role', 'presentation');
    if (active) element.classList?.remove('hidden');
    element.authCooldownActive = active;
    element.hidden = !active;
    element.innerHTML = markup;
  },
  startCountdown(seconds, render) {
    const until = Date.now() + seconds * 1000;
    const timer = window.setInterval(tick, 1000);
    function tick() {
      const remaining = Math.max(0, Math.ceil((until - Date.now()) / 1000));
      render(remaining);
      if (!remaining) window.clearInterval(timer);
    }
    tick();
    return () => window.clearInterval(timer);
  },
  bind(codeInput, codeCells, onChange = () => {}) {
    function updateCodeCells() {
      const digits = codeInput.value;
      codeCells.forEach((cell, index) => {
        cell.textContent = digits[index] || "";
        cell.classList.toggle("is-active", document.activeElement === codeInput && index === Math.min(digits.length, 5));
      });
    }

    codeInput.addEventListener("input", () => {
      codeInput.value = codeInput.value.replace(/\D/g, "").slice(0, 6);
      onChange();
      updateCodeCells();
    });
    codeInput.addEventListener("focus", () => {
      codeInput.setSelectionRange(codeInput.value.length, codeInput.value.length);
      updateCodeCells();
    });
    codeInput.addEventListener("blur", updateCodeCells);
    codeInput.addEventListener("click", () => codeInput.setSelectionRange(codeInput.value.length, codeInput.value.length));
    codeInput.addEventListener("paste", (event) => {
      const digits = event.clipboardData?.getData("text").replace(/\D/g, "").slice(0, 6);
      if (!digits) return;
      event.preventDefault();
      codeInput.value = digits;
      onChange();
      updateCodeCells();
    });

    updateCodeCells();
    return {
      clear() { codeInput.value = ""; updateCodeCells(); onChange(); },
      refresh: updateCodeCells,
    };
  },
};
