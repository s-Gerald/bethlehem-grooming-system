import {
  OTHER_BREED,
  UNKNOWN_BREED,
  breedCoatCatalogueReady,
  getMixedBreed,
  getMatchingBreeds,
  hasLetter,
  isMixedBreed,
} from "./breed-coat-catalogue.js?pet-fields=20261026-v1";

export function createBreedCombobox({
  root,
  input,
  listbox,
  toggleButton,
  errorElement,
  petTypeInput,
  getPetType = () => petTypeInput?.value || "",
}) {
  if (!root || !input || !listbox || !toggleButton) {
    throw new Error("Breed combobox elements are incomplete.");
  }

  let isCustomEntry = false;
  let isOpen = false;
  let isDisabled = input.disabled || toggleButton.disabled;
  let shouldShowValidation = false;
  let previousPetType = getPetType();
  let activeIndex = -1;
  listbox.classList.add("breed-suggestion-list");
  input.setAttribute("aria-haspopup", "listbox");
  const requirementIndicator = input.labels?.[0]?.querySelector("[data-breed-requirement]");
  if (errorElement?.id) input.setAttribute("aria-describedby", errorElement.id);

  function getValidationMessage() {
    if (isCustomEntry && !hasLetter(input.value.trim())) {
      return "Please enter a breed with at least one letter.";
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

  function updateRequirement() {
    input.setAttribute("aria-required", String(isCustomEntry));
    if (requirementIndicator) {
      requirementIndicator.textContent = isCustomEntry ? "*" : "(optional)";
      requirementIndicator.classList.toggle("text-red-500", isCustomEntry);
      requirementIndicator.classList.toggle("text-slate-400", !isCustomEntry);
    }
  }

  function notifyValueChange() {
    updateRequirement();
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function buildOption(value, label = value) {
    const button = document.createElement("button");
    button.type = "button";
    button.className =
      "breed-combobox-option block w-full px-4 py-2.5 text-left text-sm text-slate-700 transition hover:bg-[#edf5fc] focus:bg-[#edf5fc] focus:outline-none";
    button.setAttribute("role", "option");
    button.tabIndex = -1;
    button.id = `${listbox.id || input.id || "breed"}-option-${listbox.children.length}`;
    button.setAttribute("aria-selected", String(input.value === value));
    button.dataset.breedValue = value;
    button.title = label;
    button.textContent = label;
    return button;
  }

  function renderOptions() {
    listbox.innerHTML = "";
    activeIndex = -1;
    input.removeAttribute("aria-activedescendant");

    if (!isCustomEntry) {
      listbox.appendChild(buildOption(getMixedBreed(getPetType())));
      listbox.appendChild(buildOption(UNKNOWN_BREED));
      listbox.appendChild(buildOption(OTHER_BREED));
      return;
    }

    const query = input.value.trim();

    const matches = getMatchingBreeds(query, getPetType());
    matches.forEach((breed) => listbox.appendChild(buildOption(breed)));
    if (hasLetter(query) && matches.length === 0) {
      const emptyState = document.createElement("p");
      emptyState.className = "px-4 py-2.5 text-sm text-slate-400";
      emptyState.textContent = "No matching breeds";
      listbox.appendChild(emptyState);
    }

    listbox.appendChild(buildOption(getMixedBreed(getPetType())));
    listbox.appendChild(buildOption(UNKNOWN_BREED));
  }

  function setOpen(nextOpen) {
    isOpen = Boolean(nextOpen && !isDisabled);
    listbox.classList.toggle("hidden", !isOpen);
    input.setAttribute("aria-expanded", String(isOpen));
    toggleButton.setAttribute("aria-expanded", String(isOpen));

    if (isOpen) {
      renderOptions();
      listbox.scrollTop = 0;
    } else {
      activeIndex = -1;
      input.removeAttribute("aria-activedescendant");
    }
  }

  function selectValue(value) {
    if (isDisabled) return;
    if (value === OTHER_BREED) {
      isCustomEntry = true;
      input.readOnly = false;
      input.value = "";
      input.placeholder = "Type a breed";
      clearValidation();
      setOpen(true);
      input.focus();
      notifyValueChange();
      return;
    }

    input.value = value;

    if (isMixedBreed(value) || value === UNKNOWN_BREED) {
      isCustomEntry = false;
      input.readOnly = true;
      input.placeholder = "Select breed";
    }

    clearValidation();
    setOpen(false);
    input.focus();
    notifyValueChange();
  }

  function setValue(value) {
    previousPetType = getPetType();
    input.value = value || "";
    isCustomEntry = Boolean(input.value && !isMixedBreed(input.value) && input.value !== UNKNOWN_BREED);
    input.readOnly = !isCustomEntry;
    updateRequirement();
    setDisabled(!previousPetType);
    clearValidation();
    setOpen(false);
  }

  function reset() {
    setValue("");
  }

  function handleSpeciesChange() {
    if (getPetType() === previousPetType) return;
    reset();
    notifyValueChange();
  }

  function setDisabled(disabled) {
    isDisabled = Boolean(disabled);
    input.disabled = isDisabled;
    toggleButton.disabled = isDisabled;
    input.setAttribute("aria-disabled", String(isDisabled));
    toggleButton.setAttribute("aria-disabled", String(isDisabled));
    if (isDisabled) setOpen(false);
    input.placeholder = isDisabled ? "Select pet type first" : (isCustomEntry ? "Type a breed" : "Select breed");
  }

  function focusOption(offset) {
    const options = [...listbox.querySelectorAll(".breed-combobox-option")];

    if (options.length === 0) {
      return;
    }

    const nextIndex = activeIndex < 0
      ? (offset > 0 ? 0 : options.length - 1)
      : (activeIndex + offset + options.length) % options.length;

    activeIndex = nextIndex;
    options.forEach((option, index) => option.setAttribute("aria-selected", String(index === activeIndex)));
    input.setAttribute("aria-activedescendant", options[nextIndex].id);
    options[nextIndex].scrollIntoView({ block: "nearest" });
  }

  listbox.addEventListener("pointerdown", (event) => {
    if (event.target.closest("[data-breed-value]")) event.preventDefault();
  });

  root.addEventListener("click", (event) => {
    if (isDisabled) return;
    const option = event.target.closest("[data-breed-value]");

    if (option) {
      selectValue(option.dataset.breedValue);
    }
  });

  input.addEventListener("click", () => setOpen(true));
  input.addEventListener("input", () => {
    if (!isCustomEntry) {
      return;
    }

    setOpen(true);

    if (shouldShowValidation) {
      showValidation();
    }
  });

  input.addEventListener("keydown", (event) => {
    if (event.key === "Enter" && isOpen && activeIndex >= 0) {
      event.preventDefault();
      const option = listbox.querySelectorAll(".breed-combobox-option")[activeIndex];
      if (option) selectValue(option.dataset.breedValue);
      return;
    }
    if (input.readOnly && (event.key === "Enter" || event.key === " ")) {
      event.preventDefault();
      if (!isOpen) setOpen(true);
      focusOption(1);
      return;
    }

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (!isOpen) setOpen(true);
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
  root.addEventListener("focusout", (event) => {
    if (!root.contains(event.relatedTarget)) setOpen(false);
  });

  input.form?.addEventListener("reset", () => window.setTimeout(reset));
  petTypeInput?.addEventListener("input", handleSpeciesChange);
  petTypeInput?.addEventListener("change", handleSpeciesChange);
  breedCoatCatalogueReady.then(() => {
    if (isOpen) {
      renderOptions();
    }
  });
  setValue(input.value);

  return {
    getValidationMessage,
    reset,
    setDisabled,
    setValue,
    showValidation,
  };
}
