const elements = {
  form: document.getElementById("walkInOwnerForm"),
  firstName: document.getElementById("ownerFirstName"),
  lastName: document.getElementById("ownerLastName"),
  middleInitial: document.getElementById("ownerMiddleInitial"),
  phone: document.getElementById("ownerPhoneNumber"),
  email: document.getElementById("ownerEmailAddress"),
  message: document.getElementById("walkInOwnerMessage"),
  nextButton: document.getElementById("walkInOwnerNextBtn"),
  nextButtonLabel: document.getElementById("walkInOwnerNextLabel"),
  similarOwnerWarning: document.getElementById("similarOwnerWarning"),
  similarOwnerWarningMatches: document.getElementById("similarOwnerWarningMatches"),
  reviewSimilarOwnerButton: document.getElementById("reviewSimilarOwnerBtn"),
  continueSimilarOwnerButton: document.getElementById("continueSimilarOwnerBtn"),
  existingCustomerSearch: document.getElementById("existingCustomerSearch"),
  existingCustomerSearchStatus: document.getElementById("existingCustomerSearchStatus"),
  existingCustomerResults: document.getElementById("existingCustomerResults"),
  fieldErrors: {
    firstName: document.getElementById("ownerFirstNameError"),
    lastName: document.getElementById("ownerLastNameError"),
    phone: document.getElementById("ownerPhoneNumberError"),
    email: document.getElementById("ownerEmailAddressError"),
  },
};

const WALK_IN_OWNER_STORAGE_KEY = "walkInOwnerStep";
const WALK_IN_FLOW_STORAGE_KEYS = [
  "walkInPetStep",
  "walkInConsentStep",
  "walkInReviewStep",
  "walkInBookingConfirmation",
  "walkInClinicComplaint",
  "walkInClinicConfirmation",
  "clinicPreselectedPetId",
];
const flowParams = new URLSearchParams(window.location.search);
const appointmentType = (flowParams.get("flow") || flowParams.get("source")) === "clinic"
  ? "clinic"
  : "grooming";
let hasSubmittedOnce = false;
let customerSearchTimer = null;
let customerSearchRequestId = 0;
let customerSearchResults = [];
let ownerValidationInProgress = false;
let pendingSimilarOwnerValues = null;

/*
  BACKEND TEAMMATE + CLAUDE CODE:
  Owner information is only kept as a browser-side walk-in draft for now.
  Replace this with a persisted customer / walk-in draft record when the
  backend flow is ready.
*/

function normalizeText(value) {
  return String(value || "").trim().replace(/\s+/g, " ");
}

function normalizeMiddleInitial(value) {
  return normalizeText(value).replace(/\.+$/, "").toUpperCase();
}

function normalizePhoneNumber(value) {
  const rawValue = normalizeText(value);
  const digits = rawValue.replace(/\D/g, "");

  if (/^63\d{10}$/.test(digits)) {
    return `0${digits.slice(2)}`;
  }

  if (/^9\d{9}$/.test(digits)) {
    return `0${digits}`;
  }

  return rawValue;
}

function isValidPhoneNumber(value) {
  const digits = String(value || "").replace(/\D/g, "");
  return digits.length === 11 && digits.startsWith("09");
}

function isValidEmail(value) {
  const email = normalizeText(value);

  if (!email) {
    return true;
  }

  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function getAppointmentType() {
  return appointmentType;
}

function initializeWalkInFlow() {
  if (flowParams.get("new") === "1") {
    clearWalkInContinuationDraft();
    sessionStorage.setItem(WALK_IN_OWNER_STORAGE_KEY, JSON.stringify({ appointmentType }));
    flowParams.delete("new");
    window.history.replaceState(null, "", `./walk-in-booking.html?${flowParams}`);
  }
}

function getFormValues() {
  return {
    firstName: normalizeText(elements.firstName.value),
    lastName: normalizeText(elements.lastName.value),
    middleInitial: normalizeMiddleInitial(elements.middleInitial.value),
    phone: normalizePhoneNumber(elements.phone.value),
    email: normalizeText(elements.email.value),
  };
}

function getOwnerDisplayName(values) {
  return [
    values.firstName,
    values.middleInitial ? `${values.middleInitial}.` : "",
    values.lastName,
  ]
    .filter(Boolean)
    .join(" ");
}

function clearWalkInContinuationDraft() {
  WALK_IN_FLOW_STORAGE_KEYS.forEach((key) => {
    sessionStorage.removeItem(key);
  });
}

function saveOwnerDraft(values, selectedCustomer = null) {
  sessionStorage.setItem(
    WALK_IN_OWNER_STORAGE_KEY,
    JSON.stringify({
      ...values,
      fullName: getOwnerDisplayName(values),
      bookingType: "walk_in",
      appointmentType: getAppointmentType(),
      ownerRecordType: selectedCustomer?.recordType || "new",
      customerUserId: selectedCustomer?.recordType === "registered" ? selectedCustomer.id : null,
      unregisteredCustomerId: selectedCustomer?.recordType === "unregistered" ? selectedCustomer.id : null,
      existingPets: Array.isArray(selectedCustomer?.pets) ? selectedCustomer.pets : [],
    }),
  );
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function formatPhone(value) {
  const digits = String(value || "").replace(/\D/g, "");
  return digits.length === 11
    ? `${digits.slice(0, 4)}-${digits.slice(4, 7)}-${digits.slice(7)}`
    : String(value || "Not provided");
}

function renderCustomerSearchResults() {
  if (!elements.existingCustomerResults) return;

  elements.existingCustomerResults.innerHTML = customerSearchResults.map((customer, index) => `
    <button
      type="button"
      data-customer-result-index="${index}"
      class="w-full rounded-2xl border border-slate-200 bg-white p-4 text-left transition hover:border-[#7fa2c9] hover:bg-[#f7fbff] focus:outline-none focus:ring-2 focus:ring-[#315b7e]/20"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="font-semibold text-[#2f4b66]">${escapeHtml(customer.fullName)}</p>
          <p class="mt-1 text-sm text-slate-500">${escapeHtml(formatPhone(customer.phone))}${customer.email ? ` &middot; ${escapeHtml(customer.email)}` : ""}</p>
        </div>
        <span class="rounded-full px-2.5 py-1 text-xs font-medium ${customer.recordType === "unregistered" ? "bg-[#eaf4fb] text-[#315b7e]" : "bg-green-100 text-green-700"}">
          ${customer.recordType === "unregistered" ? "Unregistered" : "Active"}
        </span>
      </div>
      <p class="mt-2 text-xs text-slate-500">${Array.isArray(customer.pets) ? customer.pets.length : 0} saved pet${customer.pets?.length === 1 ? "" : "s"} &middot; Select customer</p>
    </button>
  `).join("");
}

function setCustomerSearchStatus(message) {
  if (!elements.existingCustomerSearchStatus) return;
  elements.existingCustomerSearchStatus.textContent = message;
  elements.existingCustomerSearchStatus.classList.toggle("hidden", !message);
}

async function searchExistingCustomers() {
  const query = normalizeText(elements.existingCustomerSearch?.value);
  const requestId = ++customerSearchRequestId;

  if (query.length < 2) {
    customerSearchResults = [];
    renderCustomerSearchResults();
    setCustomerSearchStatus("");
    return;
  }

  setCustomerSearchStatus("Searching customers...");
  try {
    const data = await API.searchWalkInCustomers(query);
    if (requestId !== customerSearchRequestId) return;
    customerSearchResults = data.customers || [];
    renderCustomerSearchResults();
    setCustomerSearchStatus(customerSearchResults.length
      ? `${customerSearchResults.length} customer${customerSearchResults.length === 1 ? "" : "s"} found.`
      : "No active or unregistered customers matched your search.");
  } catch (error) {
    if (requestId !== customerSearchRequestId) return;
    customerSearchResults = [];
    renderCustomerSearchResults();
    setCustomerSearchStatus(error.message || "Customer search failed. Please try again.");
  }
}

function handleExistingCustomerSearchInput() {
  window.clearTimeout(customerSearchTimer);
  customerSearchTimer = window.setTimeout(searchExistingCustomers, 300);
}

function selectExistingCustomer(customer) {
  if (!customer) return;

  const values = {
    firstName: normalizeText(customer.firstName),
    lastName: normalizeText(customer.lastName),
    middleInitial: normalizeMiddleInitial(customer.middleName),
    phone: normalizePhoneNumber(customer.phone),
    email: normalizeText(customer.email),
  };

  clearWalkInContinuationDraft();
  saveOwnerDraft(values, customer);
  window.location.href = "./walk-in-pet-details.html";
}

function validateOwner(values) {
  const errors = {};

  if (!values.firstName) {
    errors.firstName = "First name is required.";
  }

  if (!values.lastName) {
    errors.lastName = "Last name is required.";
  }

  if (!values.phone) {
    errors.phone = "Phone number is required.";
  } else if (!isValidPhoneNumber(values.phone)) {
    errors.phone = "Phone number must be 11 digits and start with 09.";
  }

  if (values.email && !isValidEmail(values.email)) {
    errors.email = "Enter a valid email address or leave it blank.";
  }

  return errors;
}

function showMessage(message, variant = "default") {
  const styleMap = {
    default: "border-[#9ebedf] bg-white/80 text-slate-600",
    success: "border-emerald-200 bg-emerald-50 text-emerald-700",
    error: "border-red-200 bg-red-50 text-red-700",
  };

  elements.message.textContent = message;
  elements.message.className = `rounded-2xl border px-4 py-3 text-sm ${
    styleMap[variant] || styleMap.default
  }`;
}

function hideMessage() {
  elements.message.textContent = "";
  elements.message.className = "hidden rounded-2xl border px-4 py-3 text-sm";
}

function setFieldError(fieldName, message) {
  const errorElement = elements.fieldErrors[fieldName];

  if (!errorElement) {
    return;
  }

  if (message) {
    errorElement.textContent = message;
    errorElement.className = "mt-2 text-sm text-red-600";
    return;
  }

  errorElement.textContent = "";
  errorElement.className = "mt-2 hidden text-sm text-red-600";
}

function renderValidationErrors(values, { showAll = false } = {}) {
  const errors = validateOwner(values);
  const requestedFields = Object.keys(elements.fieldErrors);

  requestedFields.forEach((fieldName) => {
    const hasValue = Boolean(values[fieldName] || "");
    const shouldShow = showAll || hasSubmittedOnce || hasValue;
    setFieldError(fieldName, shouldShow ? errors[fieldName] || "" : "");
  });

  const firstError = requestedFields.map((fieldName) => errors[fieldName]).find(Boolean);

  if (showAll || (hasSubmittedOnce && firstError)) {
    showMessage(firstError || "Please complete the highlighted fields.", "error");
    return;
  }

  hideMessage();
}

function hideSimilarOwnerWarning() {
  pendingSimilarOwnerValues = null;
  elements.similarOwnerWarning?.classList.add("hidden");
  if (elements.similarOwnerWarningMatches) {
    elements.similarOwnerWarningMatches.innerHTML = "";
  }
}

function showSimilarOwnerWarning(values, customers = []) {
  pendingSimilarOwnerValues = values;

  if (elements.similarOwnerWarningMatches) {
    elements.similarOwnerWarningMatches.innerHTML = customers.slice(0, 3).map((customer) => `
      <div class="rounded-xl border border-amber-100 bg-amber-50/70 px-3 py-2">
        <p class="text-sm font-medium text-[#2f4b66]">${escapeHtml(customer.fullName || "Similar customer")}</p>
        <p class="mt-0.5 text-xs text-slate-600">${escapeHtml(formatPhone(customer.phone))} &middot; ${escapeHtml(customer.status || "Customer")}</p>
      </div>
    `).join("");
  }

  elements.similarOwnerWarning?.classList.remove("hidden");
  elements.continueSimilarOwnerButton?.focus();
}

function setOwnerValidationInProgress(inProgress) {
  ownerValidationInProgress = inProgress;
  if (elements.nextButtonLabel) {
    elements.nextButtonLabel.textContent = inProgress ? "Checking..." : "Next Step";
  }
  syncNextButtonState();
}

function syncNextButtonState() {
  const values = getFormValues();
  const errors = validateOwner(values);
  const isReady = Object.keys(errors).length === 0 && !ownerValidationInProgress;

  elements.nextButton.disabled = !isReady;
  elements.nextButton.setAttribute("aria-disabled", String(!isReady));
  elements.nextButton.classList.toggle("opacity-50", !isReady);
  elements.nextButton.classList.toggle("cursor-not-allowed", !isReady);

  return isReady;
}

function handleInput(event) {
  hideSimilarOwnerWarning();
  const values = getFormValues();
  const fieldName = event.target?.name;

  if (fieldName === "phone") {
    elements.phone.value = normalizePhoneNumber(elements.phone.value);
  }

  renderValidationErrors(values, { showAll: false });
  syncNextButtonState();
}

function continueToPetStep(values, confirmSimilarName = false) {
  elements.firstName.value = values.firstName;
  elements.lastName.value = values.lastName;
  elements.middleInitial.value = values.middleInitial;
  elements.phone.value = values.phone;
  elements.email.value = values.email;

  clearWalkInContinuationDraft();
  saveOwnerDraft({
    ...values,
    confirmSimilarName: Boolean(confirmSimilarName),
  });

  window.location.href = "./walk-in-pet-details.html";
}

async function validateNewOwnerAndContinue(values, confirmSimilarName = false) {
  setOwnerValidationInProgress(true);
  hideMessage();

  try {
    await API.validateWalkInNewOwner({
      first_name: values.firstName,
      last_name: values.lastName,
      middle_name: values.middleInitial || null,
      phone: values.phone,
      email: values.email || null,
      confirm_similar_name: Boolean(confirmSimilarName),
    });
    hideSimilarOwnerWarning();
    continueToPetStep(values, confirmSimilarName);
  } catch (error) {
    if (error.code === "similar_customer_name") {
      showSimilarOwnerWarning(values, error.data?.similarCustomers || []);
      return;
    }

    const fieldMap = {
      first_name: "firstName",
      last_name: "lastName",
      phone: "phone",
      email: "email",
    };
    Object.entries(error.errors || {}).forEach(([field, messages]) => {
      const fieldName = fieldMap[field];
      if (fieldName) setFieldError(fieldName, messages?.[0] || "Check this field.");
    });
    showMessage(
      error.errors
        ? "Please review the highlighted owner information."
        : (error.message || "Owner information could not be checked. Please try again."),
      "error",
    );
  } finally {
    setOwnerValidationInProgress(false);
  }
}

async function handleSubmit(event) {
  event.preventDefault();
  if (ownerValidationInProgress) return;
  hasSubmittedOnce = true;

  const values = getFormValues();
  const errors = validateOwner(values);

  if (Object.keys(errors).length > 0) {
    renderValidationErrors(values, { showAll: true });
    syncNextButtonState();
    return;
  }

  await validateNewOwnerAndContinue(values);
}

function bindEvents() {
  elements.form.addEventListener("submit", handleSubmit);

  [
    elements.firstName,
    elements.lastName,
    elements.middleInitial,
    elements.phone,
    elements.email,
  ].forEach((input) => {
    input.addEventListener("input", handleInput);
  });

  elements.existingCustomerSearch?.addEventListener("input", handleExistingCustomerSearchInput);
  elements.existingCustomerResults?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-customer-result-index]");
    if (!button) return;
    selectExistingCustomer(customerSearchResults[Number(button.dataset.customerResultIndex)]);
  });
  elements.reviewSimilarOwnerButton?.addEventListener("click", () => {
    hideSimilarOwnerWarning();
    elements.firstName.focus();
  });
  elements.continueSimilarOwnerButton?.addEventListener("click", () => {
    if (!pendingSimilarOwnerValues || ownerValidationInProgress) return;
    const values = { ...pendingSimilarOwnerValues };
    validateNewOwnerAndContinue(values, true);
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !elements.similarOwnerWarning?.classList.contains("hidden")) {
      hideSimilarOwnerWarning();
      elements.firstName.focus();
    }
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  const isClinic = appointmentType === "clinic";
  document.getElementById("walkInPageTitle").textContent = isClinic ? "Clinic walk-in" : "Grooming walk-in";
  document.querySelectorAll("[data-walk-in-back-link]").forEach((link) => {
    link.setAttribute("href", isClinic ? "./clinic.html" : "./appointments.html");
  });
  if (!await API.waitForSessionVerification()) {
    return;
  }

  initializeWalkInFlow();
  bindEvents();
  renderValidationErrors(getFormValues(), { showAll: false });
  syncNextButtonState();
});
