import {
  breedCoatCatalogueReady,
  getCoatOptions,
} from "./breed-coat-catalogue.js?pet-fields=20261030-v1";

export function createBreedCoatCombobox({
  root,
  breedInput,
  petTypeInput,
  input,
  listbox,
  toggleButton,
  errorElement,
}) {
  if (!root || !breedInput || !petTypeInput || !input || !listbox || !toggleButton) {
    throw new Error("Breed coat combobox elements are incomplete.");
  }

  let isOpen = false;
  let shouldShowValidation = false;
  let previousBreed = breedInput.value;
  let previousPetType = petTypeInput.value;
  let isDisabled = false;
  let preservedValue = input.value;
  const requirementIndicator = input.labels?.[0]?.querySelector("[data-fur-requirement]");
  if (errorElement?.id) input.setAttribute("aria-describedby", errorElement.id);

  function getOptions() {
    return getCoatOptions(breedInput.value, petTypeInput.value);
  }

  function getValidationMessage() {
    const selectedCoat = input.value;

    if (!selectedCoat) {
      return "";
    }

    if (selectedCoat === preservedValue) return "";
    if (!getOptions().includes(selectedCoat)) {
      return `Selected fur type is not available for ${breedInput.value || "this breed"}.`;
    }

    return "";
  }

  function showValidation(message = getValidationMessage()) {
    shouldShowValidation = true;
    const hasError = Boolean(message);

    input.setAttribute("aria-invalid", String(hasError));
    input.classList.toggle("border-red-400", hasError);
    input.classList.toggle("focus:border-red-500", hasError);
    input.classList.toggle("focus:ring-red-500/20", hasError);

    if (errorElement) {
      errorElement.textContent = message;
      errorElement.classList.toggle("hidden", !hasError);
    }

    return !hasError;
  }

  function clearValidation() {
    shouldShowValidation = false;
    input.setAttribute("aria-invalid", "false");
    input.classList.remove(
      "border-red-400",
      "focus:border-red-500",
      "focus:ring-red-500/20",
    );

    if (errorElement) {
      errorElement.textContent = "";
      errorElement.classList.add("hidden");
    }
  }

  function buildOption(coat) {
    const button = document.createElement("button");
    button.type = "button";
    button.className =
      "breed-coat-combobox-option block w-full px-4 py-2.5 text-left text-sm text-slate-700 transition hover:bg-[#edf5fc] focus:bg-[#edf5fc] focus:outline-none";
    button.setAttribute("role", "option");
    button.setAttribute("aria-selected", String(input.value === coat));
    button.dataset.coatValue = coat;
    button.textContent = coat;
    return button;
  }

  function renderOptions() {
    listbox.innerHTML = "";
    getOptions().forEach((coat) => listbox.appendChild(buildOption(coat)));
  }

  function setOpen(nextOpen) {
    isOpen = Boolean(nextOpen && !isDisabled && !input.disabled && getOptions().length > 0);
    listbox.classList.toggle("hidden", !isOpen);
    input.setAttribute("aria-expanded", String(isOpen));
    toggleButton.setAttribute("aria-expanded", String(isOpen));

    if (isOpen) {
      renderOptions();
    }
  }

  function notifyValueChange() {
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function selectValue(coat) {
    if (isDisabled || !getOptions().includes(coat)) {
      return;
    }

    input.value = coat;
    clearValidation();
    setOpen(false);
    input.focus();
    notifyValueChange();
  }

  function setDisabled(disabled) {
    isDisabled = Boolean(disabled);
    input.disabled = isDisabled || getOptions().length === 0;
    toggleButton.disabled = input.disabled;
    input.setAttribute("aria-disabled", String(input.disabled));
    toggleButton.setAttribute("aria-disabled", String(toggleButton.disabled));
    if (input.disabled) setOpen(false);
  }

  function update({ preserveValue = false } = {}) {
    const previousValue = input.value;
    const options = getOptions();
    const speciesChanged = petTypeInput.value !== previousPetType;
    input.required = false;
    input.setAttribute("aria-required", "false");
    if (requirementIndicator) {
      requirementIndicator.textContent = "(optional)";
      requirementIndicator.classList.remove("text-red-500");
      requirementIndicator.classList.add("text-slate-400");
    }
    if (preserveValue) preservedValue = previousValue;
    if (!preserveValue && !isDisabled) {
      preservedValue = "";
      input.value = !speciesChanged && options.includes(previousValue) ? previousValue : "";
    }
    previousBreed = breedInput.value;
    previousPetType = petTypeInput.value;
    input.placeholder = petTypeInput.value ? "Select fur / coat type" : "Select species first";
    setDisabled(isDisabled);

    if (options.length === 0) {
      setOpen(false);
    } else if (isOpen) {
      renderOptions();
    }

    if (shouldShowValidation) {
      showValidation();
    } else {
      clearValidation();
    }

    if (input.value !== previousValue) notifyValueChange();
  }

  function handleDependencyChange() {
    if (breedInput.value === previousBreed && petTypeInput.value === previousPetType) return;
    update();
  }

  function reset() {
    input.value = "";
    clearValidation();
    setOpen(false);
    update({ autoSelect: false });
  }

  function focusOption(offset) {
    const options = [...listbox.querySelectorAll(".breed-coat-combobox-option")];

    if (options.length === 0) {
      return;
    }

    const activeIndex = options.indexOf(document.activeElement);
    const nextIndex = activeIndex < 0
      ? (offset > 0 ? 0 : options.length - 1)
      : (activeIndex + offset + options.length) % options.length;

    options[nextIndex].focus();
  }

  root.addEventListener("click", (event) => {
    const option = event.target.closest("[data-coat-value]");

    if (option) {
      selectValue(option.dataset.coatValue);
    }
  });

  input.addEventListener("click", () => setOpen(true));
  input.addEventListener("keydown", (event) => {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      setOpen(true);
      focusOption(1);
      return;
    }

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      setOpen(true);
      focusOption(event.key === "ArrowDown" ? 1 : -1);
      return;
    }

    if (event.key === "Escape") {
      setOpen(false);
    }
  });

  listbox.addEventListener("keydown", (event) => {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      focusOption(event.key === "ArrowDown" ? 1 : -1);
      return;
    }

    if (event.key === "Escape") {
      setOpen(false);
      input.focus();
    }
  });

  toggleButton.addEventListener("click", () => {
    setOpen(!isOpen);
    input.focus();
  });

  document.addEventListener("pointerdown", (event) => {
    if (!root.contains(event.target)) {
      setOpen(false);
    }
  });

  breedInput.addEventListener("input", handleDependencyChange);
  breedInput.addEventListener("change", handleDependencyChange);
  petTypeInput.addEventListener("input", handleDependencyChange);
  petTypeInput.addEventListener("change", handleDependencyChange);
  input.form?.addEventListener("reset", () => window.setTimeout(reset));
  breedCoatCatalogueReady.then(() => {
    update({ preserveValue: true });
  });

  update({ preserveValue: true, autoSelect: false });

  return {
    getValidationMessage,
    setDisabled,
    reset,
    showValidation,
    update,
  };
}
