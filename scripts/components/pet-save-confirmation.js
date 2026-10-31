// Shared acknowledgment for My Pets and both online registration flows.
export function createPetSaveConfirmation({ form, button, warning, initialLabel }) {
  let acknowledged = null;
  let saving = false;

  function render() {
    warning.hidden = acknowledged === null;
    button.textContent = saving ? "Saving..." : acknowledged === null ? initialLabel : "Confirm";
    if (acknowledged === null) button.removeAttribute("aria-describedby");
    else button.setAttribute("aria-describedby", warning.id);
  }

  function reset() {
    acknowledged = null;
    render();
  }

  form.addEventListener("input", reset);
  form.addEventListener("change", reset);
  form.addEventListener("reset", reset);
  reset();

  return {
    reset,
    get saving() { return saving; },
    // Compare actual values too: silent programmatic updates cannot submit stale consent.
    confirm(values) {
      if (saving) return false;
      const snapshot = JSON.stringify(values);
      if (snapshot === acknowledged) return true;
      acknowledged = snapshot;
      render();
      return false;
    },
    setSaving(value) {
      saving = value;
      button.disabled = value;
      button.setAttribute("aria-busy", String(value));
      if (!value) acknowledged = null;
      render();
    },
  };
}
