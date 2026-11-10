import { limitMessage, tooLong } from "../utils/short-notes.js?v=500-20261110";

import {
  BOOKING_STEP_THREE_KEY,
  formatPetSizeLabel,
  normalizePetSize,
  normalizePetType,
} from "./booking-draft-service.js?flow=grooming-order-20261016&registration=fcfs-20261027-v1";
import { formatPhpAmount, formatPriceOption } from "./grooming-price-format.js?v=20261101";
export { formatPhpAmount, formatPriceOption };

export const GROOMING_PACKAGES = [];
export const ALA_CARTE_SERVICES = [];
const PACKAGE_MAP = new Map();
const ALA_CARTE_MAP = new Map();
let catalogueLoaded = false;
let groomingPreferences = {};
let activeCatalogueRequest;

export const GROOMING_AVAILABILITY_ERROR = "Unable to check current grooming availability. Please try again.";

export function applyGroomingCatalogue(catalogue, preferences = null) {
  if (!Array.isArray(catalogue) || catalogue.some((service) =>
    !service.id || !service.name || !['package', 'ala_carte'].includes(service.kind)
    || !service.priceOptions?.length || service.priceOptions.some((option) =>
      !Number.isFinite(option.minAmount) || option.minAmount <= 0 ||
      !['fixed', 'range', 'starting_at'].includes(option.pricingType)))) {
    throw new Error('Grooming services are unavailable. Please retry.');
  }
  const services = catalogue.map((service) => ({ ...service,
    descriptionItems: [...(service.descriptionItems || [])],
    includedAlaCarteServiceIds: [...(service.includedAlaCarteServiceIds || [])],
    priceOptions: service.priceOptions.map((option) => ({ ...option,
      pricingType: option.pricingType === 'starting_at' ? 'plus' : option.pricingType })),
  }));
  groomingPreferences = preferences || Object.fromEntries(services.map(service => [service.id,
    Object.fromEntries((service.haircutOptions || []).filter(cut => cut.isActive).map(cut => [cut.id, cut.name]))]));
  GROOMING_PACKAGES.splice(0, GROOMING_PACKAGES.length, ...services.filter((service) => service.kind === 'package' && service.isActive !== false));
  ALA_CARTE_SERVICES.splice(0, ALA_CARTE_SERVICES.length, ...services.filter((service) => service.kind === 'ala_carte' && service.isActive !== false));
  PACKAGE_MAP.clear();
  ALA_CARTE_MAP.clear();
  GROOMING_PACKAGES.forEach((service) => PACKAGE_MAP.set(service.id, service));
  ALA_CARTE_SERVICES.forEach((service) => ALA_CARTE_MAP.set(service.id, service));
  catalogueLoaded = true;
  return services;
}

export async function loadGroomingCatalogue({includeInactive = false} = {}) {
  if (includeInactive) {
    const response = await API.adminRequest('GET', '/grooming/services?include_inactive=1', null, {force: true, cache: 'no-store'});
    return applyGroomingCatalogue(response.data, response.groomingPreferences);
  }
  activeCatalogueRequest ??= API.getGroomingCatalogue()
    .then(response => applyGroomingCatalogue(response.data, response.groomingPreferences))
    .finally(() => { activeCatalogueRequest = null; });
  return activeCatalogueRequest;
}

export function selectedPricingSignature(draft, selections) {
  return JSON.stringify(buildBookingReviewPayload(draft, selections).items.map((item) =>
    item.pricing.lineItems.map((line) => ({ id: line.serviceId, size: item.pet.size,
      options: line.pricing.selectedPriceOption || line.pricing.availablePriceOptions }))));
}

export function formatAmountRange(summary) {
  if (!summary) {
    return "";
  }

  if (summary.maxAmount === null) {
    return `${formatPhpAmount(summary.minAmount)}+`;
  }

  if (summary.minAmount === summary.maxAmount && !summary.isEstimate) {
    return formatPhpAmount(summary.minAmount);
  }

  if (summary.minAmount === summary.maxAmount) {
    return `${formatPhpAmount(summary.minAmount)} estimate`;
  }

  return `${formatPhpAmount(summary.minAmount)} - ${formatPhpAmount(summary.maxAmount)}`;
}

export function getGroomingPricePresentation(summary) {
  // The calculated flag preserves variable pricing types, even for equal bounds.
  // Saved summaries without that flag still expose a range or open-ended maximum.
  const isEstimate = Boolean(summary && (
    summary.isEstimate || summary.maxAmount === null || summary.minAmount !== summary.maxAmount
  ));

  return {
    label: isEstimate ? "Estimated Total Price" : "Total Price",
    amount: summary ? formatAmountRange(summary) : "To be confirmed",
    isEstimate,
    disclaimer: isEstimate ? "Final price is confirmed at the clinic." : "",
  };
}

export function getPackagesByPetType(petType) {
  const normalizedType = normalizePetType(petType);
  return GROOMING_PACKAGES.filter(
    (service) => service.petType === normalizedType || service.petType === "all",
  );
}

export function getAlaCarteServicesByPetType(petType) {
  const normalizedType = normalizePetType(petType);
  return ALA_CARTE_SERVICES.filter(
    (service) =>
      service.petType === normalizedType ||
      service.petType === "all" ||
      service.petTypes?.includes(normalizedType),
  );
}

export function getPackageById(serviceId) {
  return PACKAGE_MAP.get(serviceId) || null;
}

function priceOptionForSize(service, size) {
  return service.priceOptions?.find(option => option.sizeKey === size);
}

export function packageSupportsPet(service, pet) {
  const species = normalizePetType(pet?.petType);
  const size = normalizePetSize(pet?.size);
  return Boolean(service && service.isActive !== false
    && (service.petType === species || service.petType === "all")
    && (size ? priceOptionForSize(service, size) : service.priceOptions?.length));
}

export function petGroomingSizeError(pet) {
  return normalizePetSize(pet.size) && !GROOMING_PACKAGES.some(service => packageSupportsPet(service, pet))
    ? `Grooming is currently unavailable for ${formatPetSizeLabel(pet.size)} pets.`
    : "";
}

export function unavailablePackageMessage(pet, selection) {
  if (!selection?.unavailablePackage) return "";
  const name = selection.unavailablePackage.name || "The selected grooming package";
  const who = normalizePetSize(pet.size) ? `${formatPetSizeLabel(pet.size)} pets` : "this pet";
  return `${name} is no longer available for ${who}. Choose another available package for ${pet.petName || "this pet"}.`;
}

export function getPackageAlaCarteRules(packageId) {
  const selectedPackage = getPackageById(packageId);

  return {
    canCombineWithAlaCarte: Boolean(selectedPackage?.allowsAlaCarteServices),
    includedAlaCarteServiceIds: Array.from(
      new Set(
        Array.isArray(selectedPackage?.includedAlaCarteServiceIds)
          ? selectedPackage.includedAlaCarteServiceIds.filter(Boolean)
          : [],
      ),
    ),
  };
}

export function getAlaCarteServiceById(serviceId) {
  return ALA_CARTE_MAP.get(serviceId) || null;
}

export function createEmptyPetServiceSelection(pet) {
  return {
    petId: pet?.id || "",
    servicePackage: "",
    groomingPreference: "",
    groomerId: null,
    haircutRequested: false,
    alaCarteServices: [],
    addOns: [],
    specialInstructions: "",
  };
}

export function sanitizePetServiceSelection(selection) {
  const nextSelection = {
    petId: selection?.petId || "",
    servicePackage: String(selection?.servicePackage || ""),
    groomingPreference: String(selection?.groomingPreference || ""),
    groomerId: selection?.groomerId == null ? null : Number(selection.groomerId),
    haircutRequested: selection?.haircutRequested === true,
    alaCarteServices: Array.from(
      new Set(
        Array.isArray(selection?.alaCarteServices)
          ? selection.alaCarteServices.filter(Boolean)
          : [],
      ),
    ),
    addOns: [],
    specialInstructions:
      typeof selection?.specialInstructions === "string"
        ? selection.specialInstructions
        : "",
  };

  if (catalogueLoaded) {
    if (!PACKAGE_MAP.has(nextSelection.servicePackage)) {
      nextSelection.servicePackage = '';
      nextSelection.groomingPreference = '';
    }
    nextSelection.alaCarteServices = nextSelection.alaCarteServices.filter(id => ALA_CARTE_MAP.has(id));
  }

  const choices = getGroomingPreferences(nextSelection.servicePackage);
  if (catalogueLoaded && !choices.some((choice) => choice.value === nextSelection.groomingPreference)) nextSelection.groomingPreference = "";
  if (nextSelection.servicePackage !== "cat_full_grooming") nextSelection.haircutRequested = false;
  if (nextSelection.servicePackage === "cat_full_grooming" && !nextSelection.haircutRequested) nextSelection.groomingPreference = "";

  if (!nextSelection.servicePackage || nextSelection.alaCarteServices.length === 0) {
    return nextSelection;
  }

  const packageRules = getPackageAlaCarteRules(nextSelection.servicePackage);

  if (!packageRules.canCombineWithAlaCarte) {
    nextSelection.alaCarteServices = [];
    return nextSelection;
  }

  if (packageRules.includedAlaCarteServiceIds.length === 0) {
    return nextSelection;
  }

  const blockedServiceIds = new Set(packageRules.includedAlaCarteServiceIds);
  nextSelection.alaCarteServices = nextSelection.alaCarteServices.filter(
    (serviceId) => !blockedServiceIds.has(serviceId),
  );

  return nextSelection;
}

export function normalizeStepThreeDraft(rawDraft, bookingDraft, { keepUnavailablePackages = false } = {}) {
  const pets = Array.isArray(bookingDraft?.pets) ? bookingDraft.pets : [];
  const byPet = new Map((Array.isArray(rawDraft?.petSelections) ? rawDraft.petSelections : [])
    .filter(selection => selection?.petId).map(selection => [String(selection.petId), selection]));
  return pets.map((pet, index) => {
    const raw = byPet.get(String(pet.id)) || (!Array.isArray(rawDraft?.petSelections) && index === 0 ? {
      servicePackage: rawDraft?.servicePackage,
      alaCarteServices: rawDraft?.alaCarteServices,
      specialInstructions: rawDraft?.specialInstructions,
    } : null);
    if (!raw) return createEmptyPetServiceSelection(pet);
    const service = getPackageById(raw.servicePackage);
    const unavailable = keepUnavailablePackages && catalogueLoaded && raw.servicePackage && !packageSupportsPet(service, pet);
    const selection = sanitizePetServiceSelection({
      ...raw, petId: pet.id, servicePackage: unavailable ? "" : raw.servicePackage,
    });
    if (unavailable) {
      selection.unavailablePackage = { id: raw.servicePackage, name: service?.name || raw.servicePackageName };
    }
    if (catalogueLoaded) {
      if (!getPackagesByPetType(pet.petType).some((service) => service.id === selection.servicePackage)) {
        selection.servicePackage = "";
        selection.groomingPreference = "";
        selection.haircutRequested = false;
      }
      const supported = new Set(getAlaCarteServicesByPetType(pet.petType).map((service) => service.id));
      selection.alaCarteServices = selection.alaCarteServices.filter((id) => supported.has(id));
    }
    return selection;
  });
}

export function buildStepThreeDraftPayload(petSelections, { keepUnavailablePackages = false } = {}) {
  return {
    version: 2,
    petSelections: petSelections.map((selection) => {
      const sanitizedSelection = sanitizePetServiceSelection(selection);
      const unavailable = keepUnavailablePackages ? selection.unavailablePackage : null;
      const packageName = keepUnavailablePackages
        ? unavailable?.name || getPackageById(sanitizedSelection.servicePackage)?.name
        : "";

      return {
        petId: sanitizedSelection.petId,
        servicePackage: unavailable?.id || sanitizedSelection.servicePackage,
        ...(packageName ? { servicePackageName: packageName } : {}),
        groomingPreference: sanitizedSelection.groomingPreference,
        groomerId: sanitizedSelection.groomerId,
        haircutRequested: sanitizedSelection.haircutRequested,
        alaCarteServices: [...sanitizedSelection.alaCarteServices],
        addOns: [...sanitizedSelection.addOns],
        specialInstructions: sanitizedSelection.specialInstructions.trim(),
      };
    }),
  };
}

export function summarizePriceOptions(priceOptions) {
  let minimumAmount = Number.POSITIVE_INFINITY;
  let maximumAmount = 0;
  let hasMaximumAmount = false;
  let hasUnboundedMaximum = false;
  let hasVariablePricing = false;

  priceOptions.forEach((priceOption) => {
    if (!Number.isFinite(priceOption.minAmount)) {
      return;
    }

    minimumAmount = Math.min(minimumAmount, priceOption.minAmount);
    hasVariablePricing =
      hasVariablePricing || priceOption.pricingType !== "fixed";

    if (priceOption.maxAmount === null) {
      hasUnboundedMaximum = true;
      return;
    }

    maximumAmount = Math.max(maximumAmount, priceOption.maxAmount);
    hasMaximumAmount = true;
  });

  return {
    minAmount:
      minimumAmount === Number.POSITIVE_INFINITY ? 0 : minimumAmount,
    maxAmount: hasUnboundedMaximum
      ? null
      : hasMaximumAmount
        ? maximumAmount
        : 0,
    isEstimate: hasVariablePricing || hasUnboundedMaximum,
    hasUnboundedMaximum,
  };
}

export function evaluateServicePricing(service, petSize) {
  if (!service) {
    return {
      minAmount: 0,
      maxAmount: 0,
      isEstimate: false,
      displayPrice: formatPhpAmount(0),
      selectedPriceOption: null,
      availablePriceOptions: [],
      missingSize: false,
      unmatchedSize: false,
      hasClinicConfirmedAdjustment: false,
    };
  }

  const availablePriceOptions = Array.isArray(service.priceOptions)
    ? service.priceOptions
    : [];

  if (service.kind === "package") {
    const normalizedSize = normalizePetSize(petSize);
    const selectedPriceOption = priceOptionForSize(service, normalizedSize);

    if (selectedPriceOption) {
      const isEstimate = selectedPriceOption.pricingType !== "fixed";

      return {
        minAmount: selectedPriceOption.minAmount,
        maxAmount: selectedPriceOption.maxAmount,
        isEstimate,
        displayPrice: formatPriceOption(selectedPriceOption),
        selectedPriceOption,
        availablePriceOptions,
        missingSize: false,
        unmatchedSize: false,
        hasClinicConfirmedAdjustment:
          selectedPriceOption.pricingType === "plus",
      };
    }

    const aggregatePricing = summarizePriceOptions(normalizedSize ? [] : availablePriceOptions);

    return {
      ...aggregatePricing,
      displayPrice: normalizedSize ? "" : formatAmountRange(aggregatePricing),
      selectedPriceOption: null,
      availablePriceOptions,
      missingSize: !normalizedSize,
      unmatchedSize: Boolean(normalizedSize),
      hasClinicConfirmedAdjustment:
        aggregatePricing.hasUnboundedMaximum || aggregatePricing.isEstimate,
    };
  }

  const singleOption = availablePriceOptions[0] || null;

  if (!singleOption) {
    return {
      minAmount: 0,
      maxAmount: 0,
      isEstimate: false,
      displayPrice: formatPhpAmount(0),
      selectedPriceOption: null,
      availablePriceOptions,
      missingSize: false,
      unmatchedSize: false,
      hasClinicConfirmedAdjustment: false,
    };
  }

  return {
    minAmount: singleOption.minAmount,
    maxAmount: singleOption.maxAmount,
    isEstimate: singleOption.pricingType !== "fixed",
    displayPrice: formatPriceOption(singleOption),
    selectedPriceOption: singleOption,
    availablePriceOptions,
    missingSize: false,
    unmatchedSize: false,
    hasClinicConfirmedAdjustment: singleOption.pricingType === "plus",
  };
}

export function mergePricingSummaries(pricingSummaries) {
  let minAmount = 0;
  let maxAmount = 0;
  let hasOpenEndedMaximum = false;
  let isEstimate = false;

  pricingSummaries.forEach((summary) => {
    if (!summary) {
      return;
    }

    minAmount += summary.minAmount || 0;
    isEstimate = isEstimate || Boolean(summary.isEstimate);

    if (summary.maxAmount === null) {
      hasOpenEndedMaximum = true;
      return;
    }

    maxAmount += summary.maxAmount || 0;
  });

  return {
    minAmount,
    maxAmount: hasOpenEndedMaximum ? null : maxAmount,
    isEstimate: isEstimate || hasOpenEndedMaximum,
  };
}

export function calculatePetSelectionPricing(selection, pet) {
  selection = sanitizePetServiceSelection(selection);
  const lineItems = [];

  if (selection?.servicePackage) {
    const selectedPackage = getPackageById(selection.servicePackage);

    if (packageSupportsPet(selectedPackage, pet)) {
      lineItems.push({
        serviceId: selectedPackage.id,
        serviceName: selectedPackage.name,
        kind: selectedPackage.kind,
        service: selectedPackage,
        pricing: evaluateServicePricing(selectedPackage, pet?.size),
      });
    }
  }

  if (Array.isArray(selection?.alaCarteServices)) {
    selection.alaCarteServices.forEach((serviceId) => {
      const alaCarteService = getAlaCarteServiceById(serviceId);

      if (!alaCarteService) {
        return;
      }

      lineItems.push({
        serviceId: alaCarteService.id,
        serviceName: alaCarteService.name,
        kind: alaCarteService.kind,
        service: alaCarteService,
        pricing: evaluateServicePricing(alaCarteService, pet?.size),
      });
    });
  }

  return {
    lineItems,
    total: mergePricingSummaries(lineItems.map((item) => item.pricing)),
    hasSelection: lineItems.length > 0,
  };
}

export function buildBookingReviewPayload(bookingDraft, petSelections) {
  const pets = Array.isArray(bookingDraft?.pets) ? bookingDraft.pets : [];

  const items = pets.map((pet) => {
    const rawSelection =
      petSelections.find((petSelection) => petSelection.petId === pet.id) ||
      createEmptyPetServiceSelection(pet);
    const selection = sanitizePetServiceSelection(rawSelection);
    const pricing = calculatePetSelectionPricing(selection, pet);

    return {
      pet,
      selection,
      pricing,
    };
  });

  const totalPricing = mergePricingSummaries(
    items.map((item) => item.pricing.total),
  );
  const notices = [];

  const hasDisplayedPlusPrice = items.some((item) =>
    item.pricing.lineItems.some(
      (lineItem) => {
        const selectedPriceOption = lineItem.pricing.selectedPriceOption;

        if (selectedPriceOption) {
          return selectedPriceOption.pricingType === "plus";
        }

        return lineItem.pricing.availablePriceOptions.some(
          (priceOption) => priceOption.pricingType === "plus",
        );
      },
    ),
  );

  if (hasDisplayedPlusPrice) {
    notices.push("The price is finalized at the clinic.");
  }

  return {
    storageKey: BOOKING_STEP_THREE_KEY,
    bookingDate: bookingDraft?.bookingDate || "",
    bookingTime: bookingDraft?.bookingTime || "",
    items,
    totalPricing,
    notices,
    isEstimate: totalPricing.isEstimate || notices.length > 0,
  };
}

export function getGroomingPreferences(packageId) {
  return Object.entries(groomingPreferences[packageId] || {}).map(([value, label]) => ({ value, label }));
}

export function hasRequiredGroomingPreference(selection) {
  if (selection?.servicePackage === "cat_full_grooming" && !selection.haircutRequested) return true;
  const choices = getGroomingPreferences(selection?.servicePackage);
  return !choices.length || choices.some((choice) => choice.value === selection?.groomingPreference);
}

export function hasCompletedRequiredServiceSelection(pet, selection) {
  if (!pet || !selection || selection.unavailablePackage || !hasRequiredGroomingPreference(selection)) return false;
  if (selection.servicePackage) return packageSupportsPet(getPackageById(selection.servicePackage), pet);
  const alaCarte = getAlaCarteServicesByPetType(pet.petType);
  return alaCarte.some(service => selection.alaCarteServices?.includes(service.id));
}

export function canSelectGroomingPackage(pet, target) {
  return !target.disabled && packageSupportsPet(getPackageById(target.value), pet);
}

export function requiresSpecialInstructions(selection) {
  return (selection?.servicePackage === "deluxe_dog_grooming" && selection.groomingPreference === "custom_hairstyle")
    || (selection?.servicePackage === "cat_full_grooming" && selection.haircutRequested === true);
}

export function specialInstructionsError(pet, selection) {
  if (tooLong(selection?.specialInstructions)) return limitMessage;
  if (!requiresSpecialInstructions(selection) || selection.specialInstructions?.trim()) return "";
  return selection.haircutRequested
    ? `Describe the requested haircut for ${pet.petName || "this pet"}.`
    : `Describe the custom hairstyle for ${pet.petName || "this pet"}.`;
}

export function updatePetServiceSelection(selection, target) {
  const role = target.dataset.role;
  if (role === "grooming-preference") selection.groomingPreference = target.value;
  if (role === "haircut-requested") selection.haircutRequested = target.checked;
  if (role === "service-package") {
    if (selection.servicePackage !== target.value) selection.groomingPreference = "";
    selection.servicePackage = target.value;
  }
  if (role === "ala-carte") {
    const ids = new Set(selection.alaCarteServices);
    target.checked ? ids.add(target.value) : ids.delete(target.value);
    selection.alaCarteServices = [...ids];
    if (selection.servicePackage && !getPackageAlaCarteRules(selection.servicePackage).canCombineWithAlaCarte) selection.servicePackage = "";
  }
  Object.assign(selection, sanitizePetServiceSelection(selection));
  return (role === "grooming-preference" && target.value === "custom_hairstyle")
    || (role === "haircut-requested" && target.checked);
}
