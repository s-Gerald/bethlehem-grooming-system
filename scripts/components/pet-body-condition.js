const conditions = { thin: "Thin", ideal: "Ideal", overweight: "Overweight", obese: "Obese" };

export function renderBodyConditionChip(value, verified = false) {
  if (!Object.hasOwn(conditions, value)) return "";
  return `<span class="pet-body-condition">${conditions[value]}${verified
    ? ' <span aria-hidden="true">✓</span><span class="sr-only"> Body condition verified</span>'
    : ""}</span>`;
}

if (globalThis.customElements && !customElements.get("pet-body-condition")) {
  let nextGroup = 0;
  customElements.define("pet-body-condition", class extends HTMLElement {
    static observedAttributes = ["data-value", "data-verified-value", "data-disabled"];

    get value() { return this.getAttribute("data-value") || ""; }
    set value(value) {
      this.explicit = false;
      this.setAttribute("data-value", value || "");
    }

    connectedCallback() {
      if (this.firstElementChild) return;
      const group = `pet-body-condition-${++nextGroup}`;
      this.innerHTML = `<fieldset class="pet-condition-field">
        <legend>Body Condition <span class="text-slate-500 font-normal">(optional)</span>
          <span class="pet-body-condition" data-condition-verified hidden>Verified</span>
        </legend>
        <div class="pet-condition-options">${Object.entries(conditions).map(([value, label]) =>
          `<label><input type="radio" name="${group}" value="${value}"><span>${label}</span></label>`
        ).join("")}</div>
        <button type="button" data-condition-clear aria-label="Clear body condition" hidden>Clear</button>
      </fieldset>`;
      this.addEventListener("click", event => {
        if (event.target.matches('input[type="radio"]')) this.select(event.target.value);
        if (event.target.matches("[data-condition-clear]")) {
          this.select("");
          this.querySelector("input").focus();
          this.dispatchEvent(new Event("change", { bubbles: true }));
        }
      });
      this.addEventListener("change", event => {
        if (event.target.matches('input[type="radio"]') && event.target.value !== this.value) {
          this.select(event.target.value);
        }
      });
      this.attributeChangedCallback();
    }

    attributeChangedCallback() {
      const clear = this.querySelector("[data-condition-clear]");
      if (!clear) return;
      this.querySelector("fieldset").disabled = this.getAttribute("data-disabled") === "true";
      for (const input of this.querySelectorAll("input")) input.checked = input.value === this.value;
      clear.hidden = !this.value;
      this.querySelector("[data-condition-verified]").hidden = !this.value || this.value !== this.getAttribute("data-verified-value");
    }

    select(value) {
      this.setAttribute("data-value", value);
      this.explicit = true;
      this.dispatchEvent(new CustomEvent("body-condition-selected", { detail: value || null, bubbles: true }));
    }
  });
}
