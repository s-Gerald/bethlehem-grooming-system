const COLORS = [
  "Black", "White", "Brown", "Gray", "Cream", "Golden", "Tan", "Orange",
  "Black & White", "Brown & White", "Tricolor", "Brindle", "Light Brown",
  "Dark Brown", "Light Gray", "Dark Gray", "Blue-gray", "Sable", "Fawn",
  "Chocolate", "Red", "Silver", "Tabby", "Calico", "Tortoiseshell",
];
const controls = new WeakMap();
const normalize = value => String(value ?? "").trim().replace(/\s+/gu, " ");

export function createColorCombobox({ input, listbox, errorElement }) {
  if (controls.has(input)) return controls.get(input);
  let open = false, activeIndex = -1, legacyValue = null;
  input.maxLength = 50;
  input.setAttribute("role", "combobox");
  input.setAttribute("aria-autocomplete", "list");
  input.setAttribute("aria-haspopup", "listbox");
  input.setAttribute("aria-controls", listbox.id);
  input.setAttribute("aria-expanded", "false");
  input.setAttribute("autocomplete", "off");
  input.setAttribute("aria-describedby", [input.getAttribute("aria-describedby"), errorElement.id].filter(Boolean).join(" "));
  listbox.setAttribute("role", "listbox");
  listbox.setAttribute("aria-label", "Color suggestions");
  listbox.setAttribute("popover", "manual");
  // The top layer escapes modal overflow. Older browsers use a body portal.
  if (!listbox.showPopover) document.body.appendChild(listbox);

  function getValue() {
    return legacyValue !== null && input.value === legacyValue ? legacyValue : normalize(input.value);
  }

  function getValidationMessage() {
    if (legacyValue !== null && input.value === legacyValue) return "";
    const value = getValue();
    if (!value) return "";
    if ([...value].length > 50) return "Color must be 50 characters or fewer.";
    if (/\p{N}/u.test(value)) return "Color cannot contain numbers.";
    if (!/\p{L}/u.test(value) || /[^\p{L}\p{M} &/\-]/u.test(value)) {
      return "Enter a color using letters, spaces, -, / or &.";
    }
    return "";
  }

  function showValidation(message = getValidationMessage()) {
    input.setAttribute("aria-invalid", String(Boolean(message)));
    input.classList.toggle("border-red-400", Boolean(message));
    errorElement.textContent = message;
    errorElement.classList.toggle("hidden", !message);
    return !message;
  }

  function renderOptions() {
    const query = normalize(input.value).toLocaleLowerCase();
    const matches = COLORS.filter(color => color.toLowerCase().includes(query));
    matches.sort((a, b) => Number(b.toLowerCase().startsWith(query)) - Number(a.toLowerCase().startsWith(query)));
    listbox.innerHTML = "";
    activeIndex = -1;
    input.removeAttribute("aria-activedescendant");
    matches.forEach((color, index) => {
      const option = document.createElement("div");
      option.id = `${listbox.id}-${index}`;
      option.className = "color-combobox-option";
      option.dataset.colorValue = color;
      option.setAttribute("role", "option");
      option.setAttribute("aria-selected", "false");
      option.textContent = color;
      listbox.appendChild(option);
    });
    listbox.scrollTop = 0;
    return matches.length > 0;
  }

  function position() {
    const rect = input.getBoundingClientRect();
    if (!rect.width) { close(); return; }
    const height = Math.min(160, listbox.children.length * 40) + 2;
    const below = window.innerHeight - rect.bottom - 8;
    const above = rect.top - 8;
    const placeAbove = below < height && above > below;
    const available = Math.max(0, placeAbove ? above : below);
    const visibleHeight = Math.min(height, available);
    Object.assign(listbox.style, {
      left: `${rect.left}px`, width: `${rect.width}px`,
      top: `${placeAbove ? rect.top - visibleHeight - 4 : rect.bottom + 4}px`,
      maxHeight: `${visibleHeight}px`,
    });
  }

  function outside(event) {
    if (event.target !== input && !listbox.contains(event.target)) close();
  }
  function scroll(event) {
    if (event.target !== listbox && !listbox.contains(event.target)) position();
  }
  function close() {
    if (open) {
      listbox.hidePopover?.();
      document.removeEventListener("pointerdown", outside);
      document.removeEventListener("scroll", scroll, true);
      window.removeEventListener("resize", position);
    }
    open = false;
    listbox.classList.add("hidden");
    input.setAttribute("aria-expanded", "false");
    input.removeAttribute("aria-activedescendant");
    activeIndex = -1;
  }
  function show() {
    if (input.disabled || !renderOptions()) { close(); return; }
    if (!open) {
      listbox.classList.remove("hidden");
      listbox.showPopover?.();
      document.addEventListener("pointerdown", outside);
      document.addEventListener("scroll", scroll, true);
      window.addEventListener("resize", position);
    }
    open = true;
    input.setAttribute("aria-expanded", "true");
    position();
  }

  function select(value) {
    input.value = value;
    input.focus();
    close();
    showValidation();
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
    close();
  }
  function setValue(value, { preserveLegacy = false } = {}) {
    input.value = value ?? "";
    legacyValue = preserveLegacy ? input.value : null;
    close();
    showValidation("");
  }
  function reset() { setValue(""); }

  input.addEventListener("focus", show);
  input.addEventListener("click", show);
  input.addEventListener("input", () => {
    const original = input.value;
    const cleaned = original.replace(/\p{N}/gu, "");
    if (original !== cleaned) {
      const caret = input.selectionStart;
      input.value = cleaned;
      if (caret !== null) {
        const position = original.slice(0, caret).replace(/\p{N}/gu, "").length;
        input.setSelectionRange(position, position);
      }
      // Alpine may have observed the original event before this listener.
      input.dispatchEvent(new Event("input", { bubbles: true }));
    }
    showValidation(original !== cleaned ? "Color cannot contain numbers." : getValidationMessage());
    show();
  });
  input.addEventListener("blur", () => {
    input.value = getValue();
    showValidation();
    close();
    input.dispatchEvent(new Event("change", { bubbles: true }));
  });
  input.addEventListener("keydown", event => {
    if (event.isComposing) return;
    if (event.key === "Escape") {
      if (open) {
        event.preventDefault();
        event.stopPropagation();
      }
      close();
      return;
    }
    if (event.key === "Tab") { close(); return; }
    if (event.key === "Enter" && open && activeIndex >= 0) {
      event.preventDefault();
      select(listbox.children[activeIndex].dataset.colorValue);
    } else if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (!open) show();
      const options = [...listbox.children];
      if (!open || !options.length) return;
      const direction = event.key === "ArrowDown" ? 1 : -1;
      activeIndex = activeIndex < 0 ? (direction > 0 ? 0 : options.length - 1)
        : (activeIndex + direction + options.length) % options.length;
      options.forEach((option, index) => option.setAttribute("aria-selected", String(index === activeIndex)));
      const option = options[activeIndex];
      input.setAttribute("aria-activedescendant", option.id);
      // Scroll only the list, never the surrounding modal or document.
      if (option.offsetTop < listbox.scrollTop) listbox.scrollTop = option.offsetTop;
      else if (option.offsetTop + option.offsetHeight > listbox.scrollTop + listbox.clientHeight) {
        listbox.scrollTop = option.offsetTop + option.offsetHeight - listbox.clientHeight;
      }
    }
  });
  listbox.addEventListener("pointerdown", event => {
    if (event.target.closest("[data-color-value]")) event.preventDefault();
  });
  listbox.addEventListener("click", event => {
    const option = event.target.closest("[data-color-value]");
    if (option && !input.disabled) select(option.dataset.colorValue);
  });
  const control = { getValue, getValidationMessage, showValidation, setValue, reset, close };
  controls.set(input, control);
  close();
  return control;
}
