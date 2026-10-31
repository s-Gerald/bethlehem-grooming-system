import { escapeHtml, formatPetSizeLabel, formatPetTypeLabel } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";
import { renderPetGroomerSelection } from "./pet-groomer-selection.js?services=20261026&registration=fcfs-20261027-v1";
import { groomingDurationValue } from "./grooming-estimates.js?v=20261031&estimate-ui=20261031-v2";
import {
  calculatePetSelectionPricing, getGroomingPricePresentation, formatPriceOption,
  getAlaCarteServiceById, getAlaCarteServicesByPetType, getPackageById, getPackageAlaCarteRules,
  getPackagesByPetType, getGroomingPreferences, requiresSpecialInstructions, specialInstructionsError,
} from "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026&registration=fcfs-20261027-v1";

export function renderHaircutPreference(pet, selection) {
  const choices = pet.petType === "cat" ? [] : getGroomingPreferences(selection.servicePackage);
  if (!choices.length) return "";
  return `<fieldset class="grooming-preference-selection">
    <legend>Haircut Preference <span class="text-red-500">*</span></legend>
    <div class="grooming-preference-chips">${choices.map((option) => `<label class="grooming-preference-chip">
      <input type="radio" name="grooming_preference_${escapeHtml(pet.id)}" required
        data-role="grooming-preference" data-pet-id="${escapeHtml(pet.id)}" value="${escapeHtml(option.value)}"
        ${selection.groomingPreference === option.value ? "checked" : ""}>
      <span>${escapeHtml(option.label)}</span></label>`).join("")}</div>
  </fieldset>`;
}

function renderCatHaircutOption(pet, selection) {
  return `<label class="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-3 text-sm font-semibold text-[#2f4b66]">
    <input type="checkbox" data-role="haircut-requested" data-pet-id="${escapeHtml(pet.id)}"
      aria-controls="special_instructions_${escapeHtml(pet.id)}" class="h-4 w-4 accent-[#315b7e]"
      ${selection.haircutRequested ? "checked" : ""}>Include haircut</label>`;
}

function renderSpecialInstructions(pet, selection, maxLength) {
  const required = requiresSpecialInstructions(selection);
  const id = `special_instructions_${escapeHtml(pet.id)}`;
  return `<div>
    <label for="${id}" data-role="instructions-label" class="mb-3 block text-base font-semibold text-[#2f4b66]">
      ${instructionsLabel(required)}
    </label>
    <textarea id="${id}" data-role="special-instructions" data-pet-id="${escapeHtml(pet.id)}"
      rows="4" maxlength="${maxLength}" ${required ? 'required aria-required="true"' : 'aria-required="false"'}
      class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm outline-none focus:border-[#315b7e] focus:ring-2 focus:ring-[#315b7e]/20"
    >${escapeHtml(selection.specialInstructions || "")}</textarea>
    <p id="${id}_error" data-role="instructions-error" class="mt-2 text-sm text-red-700" role="alert" hidden></p>
  </div>`;
}

function instructionsLabel(required) {
  return `Special Instructions ${required ? '<span class="text-red-500">*</span>' : '<span class="font-normal text-slate-500">(optional)</span>'}`;
}

export function focusSpecialInstructions(card) {
  const field = card?.querySelector('[data-role="special-instructions"]');
  if (!field) return;
  field.focus({ preventScroll: true });
  field.scrollIntoView({ behavior: globalThis.matchMedia?.("(prefers-reduced-motion: reduce)").matches ? "instant" : "smooth", block: "center" });
}

export function updateSpecialInstructions(card, pet, selection, showError = false) {
  const field = card.querySelector('[data-role="special-instructions"]');
  const error = card.querySelector('[data-role="instructions-error"]');
  const required = requiresSpecialInstructions(selection);
  card.querySelector('[data-role="instructions-label"]').innerHTML = instructionsLabel(required);
  field.required = required;
  field.setAttribute("aria-required", String(required));
  const message = showError ? specialInstructionsError(pet, selection) : "";
  error.textContent = message;
  error.hidden = !message;
  field.setAttribute("aria-invalid", String(Boolean(message)));
  if (message) field.setAttribute("aria-describedby", error.id);
  else field.removeAttribute("aria-describedby");
  return message;
}

export function validateSpecialInstructions(container, pets, selections) {
  let firstInvalid;
  for (const pet of pets) {
    const selection = selections.find((item) => item.petId === pet.id);
    const card = container.querySelector(`[data-pet-card="${CSS.escape(pet.id)}"]`);
    if (card && updateSpecialInstructions(card, pet, selection, true)) firstInvalid ||= card;
  }
  if (firstInvalid) focusSpecialInstructions(firstInvalid);
  return !firstInvalid;
}

export function renderServiceSelections(container, pets, selections, walkIn = false, petId = null) {
  const active = document.activeElement;
  const focus = container.contains(active) ? { role: active.dataset.role, petId: active.dataset.petId, value: active.value } : null;
  const markup = (pet, index) => renderPetServiceCard(pet, selections.find((item) => item.petId === pet.id), index, walkIn);
  if (petId) {
    const index = pets.findIndex((pet) => pet.id === petId);
    const card = container.querySelector(`[data-pet-card="${CSS.escape(petId)}"]`);
    if (card && index !== -1) card.outerHTML = markup(pets[index], index);
  } else container.innerHTML = pets.map(markup).join("");
  if (focus?.role) {
    [...container.querySelectorAll("input[data-role]")].find((input) => input.dataset.role === focus.role && input.dataset.petId === focus.petId && input.value === focus.value)?.focus({ preventScroll: true });
  }
}

export function renderPetServiceCard(pet, selection, index, walkIn = false) {
  const petPricing = calculatePetSelectionPricing(selection, pet);
  const selectionSummary = getPetSelectionSummary(selection, petPricing);
  const packageCards = getPackagesByPetType(pet.petType)
    .map((service) => renderPackageCard(pet, selection, service))
    .join("");
  const alaCarteServices = getAlaCarteServicesByPetType(pet.petType);

  return `
    <article
      data-pet-card="${escapeHtml(pet.id)}"
      class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-sm md:p-6"
    >
      <div class="mb-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pet ${
              index + 1
            }</p>
            <div class="mt-1 space-y-1">
              <h3 class="text-2xl font-bold text-[#2f4b66]">${escapeHtml(
                pet.petName || "Unnamed Pet",
              )}</h3>
              <p class="text-base font-semibold text-slate-600">${escapeHtml(
                formatPetTypeLabel(pet.petType),
              )} · ${escapeHtml(pet.breed || "Breed not specified")}</p>
            </div>
          </div>
          <span class="shrink-0 rounded-full bg-[#edf5fc] px-3 py-1 text-xs font-semibold text-[#315b7e]">
            ${escapeHtml(formatPetSizeLabel(pet.size))} · ${pet.sizeVerified ? "Clinic verified" : "Estimated from weight"}
          </span>
        </div>
        ${pet.sizeVerified ? "" : '<p class="mt-2 text-xs text-slate-500">Size may be confirmed by clinic staff at check-in.</p>'}
      </div>

      ${renderPetGroomerSelection(pet, selection, walkIn)}
      <section class="rounded-2xl border border-[#b8cadb] bg-[#f4f8fc] p-4 md:p-5" aria-label="Select Services">
        <h4 class="mb-4 text-base font-semibold text-[#2f4b66]">Select Services <span class="text-red-500">*</span></h4>
        <fieldset class="min-w-0">
          <legend class="mb-3 text-sm font-semibold text-[#2f4b66]">Package Options</legend>
        <div class="grid gap-4 md:grid-cols-2">
          ${packageCards}
        </div>
        </fieldset>
        ${renderHaircutPreference(pet, selection)}

      ${
        alaCarteServices.length > 0
          ? `
            <section class="mt-8">
              <div class="mb-3">
                <h4 class="text-base font-semibold text-[#2f4b66]">A la Carte Menu</h4>
              </div>

              <div class="space-y-2">
                ${alaCarteServices
                  .map((service) =>
                    renderAlaCarteCard(pet, selection, service),
                  )
                  .join("")}
              </div>
            </section>
          `
          : ""
      }

      </section>

      <div data-grooming-duration="${escapeHtml(pet.id)}" class="mt-3" aria-live="polite" aria-atomic="true">
        <p class="text-xs text-slate-500">Estimated grooming time</p>
        <p data-grooming-duration-value class="mt-1 text-sm font-semibold text-[#2f4b66]">${escapeHtml(groomingDurationValue(selection, null, true))}</p>
      </div>

      <section class="mt-8 grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(260px,0.55fr)] md:items-stretch">
        ${renderSpecialInstructions(pet, selection, walkIn ? 1000 : 500)}

        <aside
          data-role="selection-summary"
          aria-live="polite"
          class="flex flex-col rounded-2xl border border-[#b8cadb] bg-[#f4f8fc] p-4"
        >
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
            ${selectionSummary.price ? escapeHtml(selectionSummary.priceLabel) : "Selected Package &amp; Price"}
          </p>
          <p class="mt-2 text-base font-semibold leading-relaxed text-[#2f4b66]">
            ${escapeHtml(selectionSummary.label)}
          </p>
          ${
            selectionSummary.price
              ? `<p class="mt-auto pt-4 text-right text-lg font-bold text-[#2f4b66]">
                  ${escapeHtml(selectionSummary.price)}
                </p>`
              : ""
          }
        </aside>
      </section>
    </article>
  `;
}

export function renderPackageCard(pet, selection, service) {
  const isChecked = selection.servicePackage === service.id;
  const selectedSizeLabel = formatPetSizeLabel(pet.size);

  return `
    <div class="flex h-full flex-col gap-3">
      <label
        class="service-card ${isChecked ? "service-card--selected" : ""} flex h-full cursor-pointer items-start gap-3 rounded-2xl border border-[#91aeca] bg-white p-4 transition hover:border-[#315b7e] hover:shadow-sm"
      >
        <input
          type="radio"
          name="service_package_${escapeHtml(pet.id)}"
          value="${escapeHtml(service.id)}"
          data-role="service-package"
          data-pet-id="${escapeHtml(pet.id)}"
          class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]"
          ${isChecked ? "checked" : ""}
        />
        <div class="service-card__content">
          <h4 class="font-semibold text-[#2f4b66]">${escapeHtml(service.name)}</h4>
          <ul class="mt-2 space-y-1 text-sm text-slate-600">
            ${service.descriptionItems
              .filter((item) => service.id !== "cat_full_grooming" || !/^haircut\s*\(if requested\)$/i.test(item.trim()))
              .map((item) => `<li>&bull; ${escapeHtml(item)}</li>`)
              .join("")}
          </ul>
          <div class="service-card__pricing">
            <span class="service-card__pricing-label">Size &amp; Price</span>
            <div class="service-card__price-grid">
              ${service.priceOptions
                .map((priceOption) => renderPricePill(priceOption, selectedSizeLabel))
                .join("")}
            </div>
          </div>
        </div>
      </label>
      ${isChecked && service.id === "cat_full_grooming" ? renderCatHaircutOption(pet, selection) : ""}
    </div>
  `;
}

function renderPricePill(priceOption, selectedSizeLabel) {
  const shouldHighlight = priceOption.label === selectedSizeLabel;

  return `
    <div class="service-card__price-pill ${shouldHighlight ? "service-card__price-pill--active" : ""}">
      <span class="service-card__price-size">${escapeHtml(priceOption.label)}</span>
      <span class="service-card__price-value">${escapeHtml(
        formatPriceOption(priceOption),
      )}</span>
    </div>
  `;
}

export function renderAlaCarteCard(pet, selection, service) {
  const isChecked = selection.alaCarteServices.includes(service.id);
  const packageAlaCarteRules = getPackageAlaCarteRules(selection.servicePackage);
  const isDisabled =
    Boolean(selection.servicePackage) &&
    packageAlaCarteRules.canCombineWithAlaCarte &&
    packageAlaCarteRules.includedAlaCarteServiceIds.includes(service.id);
  const labelClasses = [
    isChecked ? "service-option--selected" : "",
    "flex items-center gap-3 rounded-xl border px-3 py-2 text-sm transition",
    isDisabled
      ? "cursor-not-allowed border-slate-200 bg-slate-50 text-slate-400"
      : "cursor-pointer border-slate-200 text-slate-600 hover:border-[#315b7e] hover:bg-slate-50",
  ]
    .filter(Boolean)
    .join(" ");
  const titleClasses = isDisabled ? "font-semibold text-slate-400" : "font-semibold text-[#2f4b66]";
  const priceClasses = isDisabled ? "mt-1 block text-sm text-slate-400" : "mt-1 block text-sm text-slate-500";

  return `
    <label
      class="${labelClasses}"
    >
      <input
        type="checkbox"
        value="${escapeHtml(service.id)}"
        data-role="ala-carte"
        data-pet-id="${escapeHtml(pet.id)}"
        class="h-4 w-4 shrink-0 accent-[#315b7e]"
        ${isDisabled ? "disabled" : ""}
        ${isChecked ? "checked" : ""}
      />
      <div class="flex min-w-0 flex-1 items-center justify-between gap-3">
        <div class="min-w-0">
          <span class="${titleClasses}">${escapeHtml(service.name)}</span>
          <span class="${priceClasses}">${escapeHtml(
            formatPriceOption(service.priceOptions[0]),
          )}</span>
        </div>
        ${
          isDisabled
            ? `<span class="ml-auto shrink-0 rounded-full bg-slate-100 px-2 py-1 text-right text-xs font-medium text-slate-400">Included in Package</span>`
            : ""
        }
      </div>
    </label>
  `;
}

function getPetSelectionSummary(selection, petPricing) {
  if (!petPricing.hasSelection) {
    return {
      label: "No service selected yet.",
      price: "",
    };
  }

  const alaCarteLabels = selection.alaCarteServices
    .map((serviceId) => getAlaCarteServiceById(serviceId)?.name)
    .filter(Boolean);
  const presentation = getGroomingPricePresentation(petPricing.total);
  const price = presentation.amount;

  if (selection.servicePackage) {
    const selectedPackage = getPackageById(selection.servicePackage);
    const label =
      alaCarteLabels.length > 0
        ? `${selectedPackage?.name || "Selected package"} + ${alaCarteLabels.join(", ")}`
        : selectedPackage?.name || "Selected package";

    return { label, price, priceLabel: presentation.label };
  }

  if (alaCarteLabels.length > 0) {
    return {
      label: alaCarteLabels.join(", "),
      price,
      priceLabel: presentation.label,
    };
  }

  return {
    label: "No service selected yet.",
    price: "",
  };
}


export function renderGroomingPreference(selection) {
  const choice = getGroomingPreferences(selection?.servicePackage).find((item) => item.value === selection?.groomingPreference);
  return choice ? `<p class="grooming-preference-selection">Grooming preference: <strong>${escapeHtml(choice.label)}</strong></p>` : "";
}
