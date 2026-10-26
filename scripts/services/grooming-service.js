import "./grooming-estimate-engine.js?v=20261016";
import {
  BOOKING_STEP_THREE_KEY,
  normalizePetSize,
  normalizePetType,
} from "./booking-draft-service.js?flow=grooming-order-20261016";

export const GROOMING_PACKAGES = [];
export const ALA_CARTE_SERVICES = [];
const PACKAGE_MAP = new Map();
const ALA_CARTE_MAP = new Map();
let catalogueLoaded = false;

export function applyGroomingCatalogue(catalogue) {
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
  const response = includeInactive
    ? await API.adminRequest('GET', '/grooming/services?include_inactive=1', null, {force: true, cache: 'no-store'})
    : await API.getGroomingCatalogue();
  globalThis.GroomingEstimates.configure(response.estimateRules);
  return applyGroomingCatalogue(response.data);
}

export function selectedPricingSignature(draft, selections) {
  return JSON.stringify(buildBookingReviewPayload(draft, selections).items.map((item) =>
    item.pricing.lineItems.map((line) => ({ id: line.serviceId, size: item.pet.size,
      options: line.pricing.selectedPriceOption || line.pricing.availablePriceOptions }))));
}

export function formatPhpAmount(amount) {
  return `₱${Number(amount || 0).toLocaleString("en-PH")}`;
}

export function formatPriceOption(priceOption) {
  if (!priceOption) {
    return "";
  }

  if (priceOption.pricingType === "plus") {
    return `${formatPhpAmount(priceOption.minAmount)}+`;
  }

  if (priceOption.pricingType === "range") {
    return `${formatPhpAmount(priceOption.minAmount)}–${formatPhpAmount(priceOption.maxAmount)}`;
  }

  return formatPhpAmount(priceOption.minAmount);
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

export function normalizeStepThreeDraft(rawDraft, bookingDraft) {
  const pets = Array.isArray(bookingDraft?.pets) ? bookingDraft.pets : [];
  const defaultSelectionMap = new Map(
    pets.map((pet) => [pet.id, createEmptyPetServiceSelection(pet)]),
  );

  if (rawDraft && Array.isArray(rawDraft.petSelections)) {
    rawDraft.petSelections.forEach((selection) => {
      if (!selection?.petId || !defaultSelectionMap.has(selection.petId)) {
        return;
      }

      defaultSelectionMap.set(
        selection.petId,
        sanitizePetServiceSelection({
          ...selection,
          petId: selection.petId,
          servicePackage: String(selection.servicePackage || ""),
          alaCarteServices: Array.from(
            new Set(
              Array.isArray(selection.alaCarteServices)
                ? selection.alaCarteServices.filter(Boolean)
                : [],
            ),
          ),
          addOns: Array.from(
            new Set(
              Array.isArray(selection.addOns) ? selection.addOns.filter(Boolean) : [],
            ),
          ),
          specialInstructions:
            typeof selection.specialInstructions === "string"
              ? selection.specialInstructions
              : "",
        }),
      );
    });
  } else if (rawDraft) {
    const firstPet = pets[0];

    if (firstPet) {
      defaultSelectionMap.set(
        firstPet.id,
        sanitizePetServiceSelection({
          petId: firstPet.id,
          servicePackage: String(rawDraft.servicePackage || ""),
          alaCarteServices: Array.from(
            new Set(
              Array.isArray(rawDraft.alaCarteServices)
                ? rawDraft.alaCarteServices.filter(Boolean)
                : [],
            ),
          ),
          addOns: Array.from(
            new Set(
              Array.isArray(rawDraft.addOns) ? rawDraft.addOns.filter(Boolean) : [],
            ),
          ),
          specialInstructions:
            typeof rawDraft.specialInstructions === "string"
              ? rawDraft.specialInstructions
              : "",
        }),
      );
    }
  }

  return pets.map((pet) => {
    const selection = defaultSelectionMap.get(pet.id) || createEmptyPetServiceSelection(pet);
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

export function buildStepThreeDraftPayload(petSelections) {
  return {
    version: 2,
    petSelections: petSelections.map((selection) => {
      const sanitizedSelection = sanitizePetServiceSelection(selection);

      return {
        petId: sanitizedSelection.petId,
        servicePackage: sanitizedSelection.servicePackage,
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
    const selectedPriceOption = availablePriceOptions.find(
      (priceOption) => priceOption.sizeKey === normalizedSize,
    );

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

    const aggregatePricing = summarizePriceOptions(availablePriceOptions);

    return {
      ...aggregatePricing,
      displayPrice: formatAmountRange(aggregatePricing),
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

    if (selectedPackage) {
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
      groomingEstimate: estimatePetGrooming(selection, pet),
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
  return globalThis.GroomingEstimates.preferences(packageId);
}

export function hasRequiredGroomingPreference(selection) {
  const choices = getGroomingPreferences(selection?.servicePackage);
  return !choices.length || choices.some((choice) => choice.value === selection?.groomingPreference);
}

export function hasCompletedRequiredServiceSelection(pet, selection) {
  if (!pet || !hasRequiredGroomingPreference(selection)) return false;
  if (!getAlaCarteServicesByPetType(pet.petType).length) return Boolean(selection?.servicePackage);
  return calculatePetSelectionPricing(selection, pet).hasSelection;
}

export function estimatePetGrooming(selection, pet) {
  return globalThis.GroomingEstimates.calculate(selection, normalizePetSize(pet?.size));
}

export function requiresSpecialInstructions(selection) {
  return (selection?.servicePackage === "deluxe_dog_grooming" && selection.groomingPreference === "custom_hairstyle")
    || (selection?.servicePackage === "cat_full_grooming" && selection.haircutRequested === true);
}

export function specialInstructionsError(pet, selection) {
  if (!requiresSpecialInstructions(selection) || selection.specialInstructions?.trim()) return "";
  return selection.haircutRequested
    ? `Describe the requested haircut for ${pet.petName || "this pet"}.`
    : `Describe the custom hairstyle for ${pet.petName || "this pet"}.`;
}

export function updatePetServiceSelection(selection, target) {
  const role = target.dataset.role;
  if (role === "grooming-preference") selection.groomingPreference = target.value;
  if (role === "haircut-requested") selection.haircutRequested = target.checked;
  if (role === "service-package") selection.servicePackage = target.value;
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
