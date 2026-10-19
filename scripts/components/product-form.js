/* Shared add-product field behavior used by Inventory and Stock In. */
window.ProductForm = (() => {
  const unitOptions = [
    ["pc", "Piece (pc)"], ["tablet", "Tablet"], ["capsule", "Capsule"],
    ["bottle", "Bottle"], ["vial", "Vial"], ["ampoule", "Ampoule"], ["syringe", "Syringe"],
    ["pipette", "Pipette"], ["tube", "Tube"], ["sachet", "Sachet"],
    ["pack", "Pack"], ["box", "Box"], ["can", "Can"], ["bag", "Bag"],
    ["dose kit", "Dose Kit"], ["other", "Other…"],
  ].map(([value, label]) => ({ value, label }));
  const categoryOptions = [
    ["medicine", "Medicine"], ["vaccine", "Vaccine"], ["food", "Food"],
    ["grooming_supply", "Grooming Supply"], ["pet_shop", "Pet Shop"], ["miscellaneous", "Miscellaneous"],
  ].map(([value, label]) => ({ value, label }));
  const medicineTypeOptions = [
    ["deworming", "Deworming"], ["heartworm_prevention", "Heartworm Prevention"],
    ["heartworm_treatment", "Heartworm Treatment"], ["mange_treatment", "Mange Treatment"],
    ["general_medication", "General Medication"],
  ].map(([value, label]) => ({ value, label }));
  const isClinic = form => ["medicine", "vaccine"].includes(form.category);
  const isWeightBased = form => form.category === "medicine" && form.pricing_method === "weight_based";
  const validMoney = value => /^\d+(?:\.\d{1,2})?$/.test(String(value))
    && Number(value) >= 0.01 && Number(value) <= 999999.99;

  const validBoundary = value => /^\d+(?:\.\d{0,2})?$/.test(String(value))
    && Number(value) > 0 && Number(value) <= 999999.99;

  const nextRangeMinimum = value => String((Math.round(Number(value) * 100) + 10) / 100);

  const rangeBand = range => range.band || (Number(range.min_weight_kg) === 0 ? "below" : range.max_weight_kg == null ? "above" : "normal");

  function scheduleRanges(form) {
    return form.weight_prices.map(range => ({
      band: range.band,
      min_weight_kg: range.band === "below" ? "0" : String(range.min_weight_kg ?? "").trim(),
      max_weight_kg: range.band === "above" ? null : String(range.band === "below" ? range.min_weight_kg ?? "" : range.max_weight_kg ?? "").trim(),
      price: String(range.price ?? "").trim(),
    }));
  }

  function rangeLabel(range, final = true, inclusive = false, mode = "threshold") {
    const min = Number(range.min_weight_kg);
    const max = range.max_weight_kg === "" || range.max_weight_kg == null ? null : Number(range.max_weight_kg);
    if (mode === "catalog") {
      if (min === 0) return `Below ${max} kg`;
      return max === null ? `Above ${min} kg` : `${min}–${max} kg`;
    }
    if (max === null) return !inclusive && final && min > 0 ? `${min} kg and above` : "Maximum weight not configured";
    if (!inclusive && min === 0) return `Below ${max} kg`;
    if (!inclusive && !final) return `${min} to below ${max} kg`;
    return inclusive ? `${min} kg to ${max} kg` : `${min}–${max} kg`;
  }

  function weightInputError(value, required) {
    const weight = String(value ?? "").trim();
    if (!weight) return required;
    if (/^\d+\.\d{3,}$/.test(weight)) return "Use no more than 2 decimal places.";
    return validBoundary(weight) ? "" : "Enter a valid weight.";
  }

  function weightFieldError(form, index, field) {
    const range = form.weight_prices[index];
    if (field === "price") {
      if (String(range.price ?? "").trim() === "") return "Enter a price.";
      return validMoney(String(range.price).trim()) ? "" : "Enter a valid price.";
    }
    if (field === "max" && range.band !== "normal") return "";
    const value = field === "max" ? range.max_weight_kg : range.min_weight_kg;
    const error = weightInputError(value, field === "max" ? "Enter a maximum weight."
      : range.band === "normal" ? "Enter a minimum weight." : "Enter a threshold weight.");
    if (error) return error;
    const min = Number(range.min_weight_kg), max = Number(range.max_weight_kg);
    const previous = form.weight_prices[index - 1], next = form.weight_prices[index + 1];
    if (field === "max") {
      if (validBoundary(String(range.min_weight_kg).trim()) && max <= min) return "Maximum must be greater than minimum.";
      if (next && validBoundary(String(next.min_weight_kg).trim()) && max > Number(next.min_weight_kg))
        return "Maximum cannot pass the next range.";
    } else if (range.band === "below") {
      if (next && validBoundary(String(next.min_weight_kg).trim()) && min > Number(next.min_weight_kg))
        return "Below threshold cannot pass the next range.";
    } else if (range.band === "above") {
      if (previous?.band === "normal" && validBoundary(String(previous.max_weight_kg).trim()) && min < Number(previous.max_weight_kg))
        return "Above threshold cannot precede the last maximum.";
      if (previous?.band === "below" && min < Number(previous.min_weight_kg)) return "Above threshold cannot precede the Below threshold.";
    } else if (previous) {
      if (previous.band === "normal" && validBoundary(String(previous.max_weight_kg).trim())
        && Math.round(min * 100) < Math.round(Number(previous.max_weight_kg) * 100) + 10)
        return `Must be greater than ${Number(previous.max_weight_kg)} kg. Minimum allowed: ${nextRangeMinimum(previous.max_weight_kg)} kg.`;
      if (validBoundary(String(previous.min_weight_kg).trim())) {
        if (previous.band === "below" && min < Number(previous.min_weight_kg)) return "Minimum cannot precede the Below threshold.";
        if (previous.band === "normal" && min <= Number(previous.min_weight_kg)) return "Minimum must be greater than the previous minimum.";
      }
    }
    return "";
  }

  function scheduleError(form) {
    if (!form.weight_prices.length) return "Add a weight range.";
    if (form.weight_prices.length > 100) return "Use at most 100 weight ranges.";
    if (form.weight_prices.some((range, index) => !["below", "normal", "above"].includes(range.band)
      || (range.band === "below" && index !== 0) || (range.band === "above" && index !== form.weight_prices.length - 1)))
      return "Keep Below first and Above last.";
    return "";
  }

  function checkWeightSchedule(form) {
    form.scheduleError = scheduleError(form);
    form.weight_prices.forEach((range, index) => {
      range.weightError = weightFieldError(form, index, "min");
      range.maximumError = weightFieldError(form, index, "max");
      range.priceError = weightFieldError(form, index, "price");
    });
    return !form.scheduleError && !form.weight_prices.some(range => range.weightError || range.maximumError || range.priceError);
  }

  function validateRanges(ranges) {
    if (!Array.isArray(ranges)) return "Add a weight range.";
    const form = { weight_prices: ranges.map(range => ({...range, band: rangeBand(range),
      min_weight_kg: rangeBand(range) === "below" ? range.max_weight_kg : range.min_weight_kg})) };
    return scheduleError(form) || form.weight_prices.map((_, index) =>
      weightFieldError(form, index, "min") || weightFieldError(form, index, "max") || weightFieldError(form, index, "price")).find(Boolean) || "";
  }

  function focusLastThreshold(section) {
    const inputs = section.querySelectorAll("[data-normal-minimum]");
    inputs[inputs.length - 1]?.focus();
  }

  function focusWeightError(section) {
    const target = section?.querySelector('[aria-invalid="true"]') || section?.querySelector("button");
    target?.focus();
  }

  function addBelowRange(form) {
    if (form.weight_prices.some(range => range.band === "below")) return;
    form.weight_prices.unshift({ band: "below", min_weight_kg: form.weight_prices[0]?.min_weight_kg ?? "", price: "" });
    form.scheduleError = "";
  }

  function addAboveRange(form) {
    if (form.weight_prices.some(range => range.band === "above")) return;
    form.weight_prices.push({ band: "above", min_weight_kg: form.weight_prices.at(-1)?.max_weight_kg ?? "", price: "" });
    form.scheduleError = "";
  }

  function addRange(form) {
    const above = form.weight_prices.findIndex(range => range.band === "above");
    const index = above < 0 ? form.weight_prices.length : above;
    const previous = form.weight_prices[index - 1];
    const minimum = previous?.band === "normal" && validBoundary(String(previous.max_weight_kg).trim())
      ? nextRangeMinimum(previous.max_weight_kg) : "";
    form.weight_prices.splice(index, 0,
      { band: "normal", min_weight_kg: minimum, max_weight_kg: "", price: "" });
    form.scheduleError = "";
  }

  function removeRange(form, index) {
    form.weight_prices.splice(index, 1);
    form.scheduleError = "";
  }

  function editableRanges(item) {
    const ranges = (item.weight_prices || []).map(range => ({
      band: Number(range.min_weight_kg) === 0 ? "below" : range.max_weight_kg == null && item.weight_pricing_mode === "catalog" ? "above" : "normal",
      min_weight_kg: Number(range.min_weight_kg) === 0 ? range.max_weight_kg ?? "" : range.min_weight_kg,
      max_weight_kg: range.max_weight_kg, price: range.price,
    }));
    // An older open tier includes its minimum; catalog Above rows exclude it.
    // Keep that boundary price with a one-hundredth normal band at the same price.
    if (item.weight_pricing_mode === "threshold" && ranges.at(-1)?.band === "normal" && ranges.at(-1).max_weight_kg == null) {
      const open = ranges.pop();
      const [whole, fraction = ""] = String(open.min_weight_kg).split(".");
      const end = String((Number(whole) * 100 + Number(fraction.padEnd(2, "0")) + 1) / 100);
      ranges.push({...open, max_weight_kg: end}, {...open, band: "above", min_weight_kg: end});
    }
    return ranges;
  }

  function clinicFields(item) {
    return {
      medicine_type: item.medicine_type || "",
      strength_concentration: item.category === "medicine" ? item.strength_concentration || "" : "",
      pricing_method: item.pricing_method || "flat",
      weight_prices: editableRanges(item),
      savedWeightPricing: item.category === "medicine" && item.pricing_method === "weight_based",
    };
  }

  const standardUnits = unitOptions.filter(({ value }) => value !== "other").map(({ value }) => value);

  function empty(values = {}) {
    return {
      item_id: null,
      item_name: "",
      barcode: "",
      category: "",
      unit: "",
      customUnit: "",
      medicine_type: "", strength_concentration: "",
      pricing_method: "weight_based", weight_prices: [], savedWeightPricing: false,
      scheduleError: "",
      description: "",
      unit_cost: "",
      selling_price: "",
      reorder_level: "",
      ...values,
    };
  }

  function normalizeBarcode(value) {
    return String(value ?? "").replace(/\D/g, "").slice(0, 13);
  }

  function normalizeReorderLevel(value) {
    const reorderLevel = String(value ?? "");
    return /^\d+\.0+$/.test(reorderLevel)
      ? reorderLevel.slice(0, reorderLevel.indexOf("."))
      : reorderLevel;
  }

  function unitFields(unit) {
    return standardUnits.includes(unit)
      ? { unit, customUnit: "" }
      : { unit: unit ? "other" : "", customUnit: unit ?? "", legacyUnit: unit ?? "" };
  }

  function validate(form) {
    if (!form.item_name.trim()) return "Product name is required.";
    if (!form.category) return "Category is required.";
    if (!form.unit) return "Unit is required.";
    if (form.unit === "other" && !form.customUnit.trim()) return "Custom unit is required.";
    if (form.unit === "other" && /^(kg|g|mg|mcg|l|ml|kilogram|gram|milligram|microgram|liter|litre|milliliter|millilitre)$/i.test(form.customUnit.trim()) && form.customUnit.trim() !== form.legacyUnit) return "Choose a physical inventory unit.";

    const barcode = String(form.barcode ?? "");
    const unitCost = String(form.unit_cost ?? "");
    const sellingPrice = String(form.selling_price ?? "");
    const reorderLevel = String(form.reorder_level ?? "");

    if (barcode !== "" && !/^[0-9]{1,13}$/.test(barcode)) return "Barcode must contain 1 to 13 digits";
    if (!validMoney(unitCost)) return "Unit cost must be a valid amount";
    if (!isWeightBased(form) && !validMoney(sellingPrice)) return "Selling price must be a valid amount";
    if (!/^\d+$/.test(reorderLevel) || Number(reorderLevel) > 99999999) return "Minimum stock must be a whole number";
    if (form.category === "medicine") {
      if (String(form.strength_concentration || "").length > 150) return "Product strength must be at most 150 characters.";
      if (!medicineTypeOptions.some(option => option.value === form.medicine_type)) return "Medicine type is required.";
      if (!["flat", "weight_based"].includes(form.pricing_method)) return "Pricing method is required.";
      if (isWeightBased(form)) return validateRanges(scheduleRanges(form));
    }
    return "";
  }

  function payload(form) {
    return {
      item_name: form.item_name.trim(),
      barcode: String(form.barcode ?? "") || null,
      category: form.category,
      unit: form.unit === "other" ? form.customUnit.trim() : form.unit,
      description: form.description.trim() || null,
      unit_cost: parseFloat(form.unit_cost),
      selling_price: isWeightBased(form) ? null : parseFloat(form.selling_price),
      medicine_type: form.category === "medicine" ? form.medicine_type : null,
      strength_concentration: form.category === "medicine" ? String(form.strength_concentration || "").trim() || null : null,
      pricing_method: form.category === "medicine" ? form.pricing_method : "flat",
      weight_pricing_mode: "catalog",
      weight_prices: isWeightBased(form) ? scheduleRanges(form) : [],
      reorder_level: parseInt(form.reorder_level, 10),
    };
  }

  function openBarcodeScanner(owner, readerId, onDecoded) {
    if (owner.scannerActive) return;
    owner.scannerActive = true;
    const generation = owner._scannerGeneration = (owner._scannerGeneration || 0) + 1;
    if (typeof Html5Qrcode === "undefined") {
      // The Inventory shell shares one script promise, including concurrent starts.
      loadInventoryScript('scanner').then(() => {
        if (!owner.scannerActive || generation !== owner._scannerGeneration) return;
        startCamera();
      }).catch(() => {
        if (!owner.scannerActive || generation !== owner._scannerGeneration) return;
        owner.scannerActive = false;
        alert("Camera scanner could not load. Please try again.");
      });
      return;
    }
    startCamera();

    function startCamera() {
      requestAnimationFrame(() => requestAnimationFrame(() => {
        if (!owner.scannerActive || generation !== owner._scannerGeneration) return;
        const config = { fps: 10, qrbox: { width: 260, height: 120 } };
        const start = (constraints) => {
          if (!owner.scannerActive || generation !== owner._scannerGeneration) return Promise.resolve();
          owner._scanner = new Html5Qrcode(readerId);
          const scanner = owner._scanner;
          return scanner.start(constraints, config, onDecoded, () => {}).then(() => {
            if (!owner.scannerActive || owner._scanner !== scanner) return scanner.stop().catch(() => {});
          });
        };

        start({
          facingMode: { ideal: "environment" },
          advanced: [{ focusMode: "continuous" }],
        }).catch(() => start({ facingMode: "environment" })).catch(() => {
          if (!owner.scannerActive || generation !== owner._scannerGeneration) return;
          owner.scannerActive = false;
          alert("Camera not available. Make sure the app is served on localhost or HTTPS, and that camera permission is granted.");
        });
      }));
    }
  }

  function closeBarcodeScanner(owner) {
    owner._scannerGeneration = (owner._scannerGeneration || 0) + 1;
    if (owner._scanner) {
      // Navigation may close the scanner before camera startup has completed.
      try { owner._scanner.stop().catch(() => {}); } catch { /* startup will stop on completion */ }
      owner._scanner = null;
    }
    owner.scannerActive = false;
  }

  return { addBelowRange, addAboveRange, addRange, checkWeightSchedule, focusLastThreshold, focusWeightError, removeRange, scheduleRanges, rangeLabel, weightFieldError, clinicFields, isClinic, isWeightBased, medicineTypeOptions, validateRanges, categoryOptions, closeBarcodeScanner, empty, normalizeBarcode, normalizeReorderLevel, openBarcodeScanner, payload, unitFields, unitOptions, validate };
})();
