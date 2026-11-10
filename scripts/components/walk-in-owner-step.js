const elements = {
  form: document.getElementById("walkInOwnerForm"),
  firstName: document.getElementById("ownerFirstName"),
  lastName: document.getElementById("ownerLastName"),
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
  "walkInSubmissionKey",
  "walkInClinicComplaint",
  "walkInClinicConfirmation",
  "clinicPreselectedPetId",
];
const flowParams = new URLSearchParams(window.location.search);
const requestedType = flowParams.get("flow") || flowParams.get("source") || readOwnerDraft().appointmentType;
const appointmentType = requestedType === "clinic"
  ? "clinic"
  : "grooming";
let customerSearchTimer = null;
let customerSearchRequestId = 0;
let customerSearchResults = [];
let ownerValidationInProgress = false;
let pendingSimilarOwnerValues = null;
let selectedCustomer = null;
let preserveContinuation = false;

/*
  BACKEND TEAMMATE + CLAUDE CODE:
  Owner information is only kept as a browser-side walk-in draft for now.
  Replace this with a persisted customer / walk-in draft record when the
  backend flow is ready.
*/

function readOwnerDraft() {
  try { return JSON.parse(sessionStorage.getItem(WALK_IN_OWNER_STORAGE_KEY)) || {}; }
  catch { return {}; }
}

function normalizeText(value) {
  return String(value || "").trim().replace(/\s+/g, " ");
}

function getAppointmentType() {
  return appointmentType;
}

function initializeWalkInFlow() {
  if (flowParams.get("new") === "1") {
    clearWalkInContinuationDraft();
    sessionStorage.setItem(WALK_IN_OWNER_STORAGE_KEY, JSON.stringify({ appointmentType }));
    flowParams.delete("new");
    window.history.replaceState(null, "", `/admin/walk-in/customer?${flowParams}`);
  }
  const draft = readOwnerDraft();
  if (draft.appointmentType !== appointmentType) return;
  for (const field of ['firstName', 'lastName', 'phone', 'email']) elements[field].value = draft[field] || '';
  preserveContinuation = Boolean(draft.recoveringName);
  if (draft.ownerRecordType && draft.ownerRecordType !== 'new') {
    selectedCustomer = { firstName: draft.firstName, lastName: draft.lastName, phone: draft.phone, email: draft.email,
      recordType: draft.ownerRecordType, id: draft.customerUserId || draft.unregisteredCustomerId, pets: draft.existingPets };
    elements.firstName.readOnly = elements.lastName.readOnly = true;
  }
}

function getFormValues() {
  return {
    firstName: elements.firstName.value,
    lastName: elements.lastName.value,
    phone: PhilippineMobile.unformat(elements.phone.value),
    email: AccountEmail.normalize(elements.email.value),
  };
}

function getOwnerDisplayName(values) {
  return [values.firstName, values.lastName].filter(Boolean).join(" ");
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
  return PhilippineMobile.format(value, 'Not provided');
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
  selectedCustomer = customer;

  const values = {
    firstName: normalizeText(customer.firstName),
    lastName: normalizeText(customer.lastName),
    phone: PhilippineMobile.normalize(customer.phone),
    email: normalizeText(customer.email),
  };

  clearWalkInContinuationDraft();
  saveOwnerDraft(values, customer);
  if (!values.phone) {
    elements.firstName.value = values.firstName;
    elements.lastName.value = values.lastName;
    elements.phone.value = "";
    elements.email.value = values.email;
    elements.firstName.readOnly = elements.lastName.readOnly = true;
    hideSimilarOwnerWarning();
    hideMessage();
    syncNextButtonState();
    elements.phone.focus();
    return;
  }
  window.location.href = "/admin/walk-in/pets";
}

function validateOwner(values) {
  const errors = {};

  if (!selectedCustomer) {
    for (const [field, type] of [['firstName', 'first_name'], ['lastName', 'last_name']]) {
      const error = HumanName.validate(values[field], { type });
      if (error) errors[field] = error;
    }
  }

  const unchangedPhone = selectedCustomer?.phone && values.phone === PhilippineMobile.normalize(selectedCustomer.phone);
  const phoneError = unchangedPhone ? '' : PhilippineMobile.validate(values.phone);
  if (phoneError) errors.phone = phoneError;

  const unchangedEmail = selectedCustomer && values.email === AccountEmail.normalize(selectedCustomer.email);
  const emailError = unchangedEmail ? '' : AccountEmail.validate(values.email, { required: false, maxLength: 255 });
  if (emailError) errors.email = emailError;

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

function setFieldError(fieldName, message, options) {
  const errorElement = elements.fieldErrors[fieldName];

  if (!errorElement) {
    return;
  }

  if (['firstName', 'lastName'].includes(fieldName)) {
    HumanName.showError(elements[fieldName], message, options);
    return;
  }

  if (fieldName === 'email') {
    elements.email.setAttribute('aria-invalid', String(Boolean(message)));
    AccountEmail.showError(elements.email, message);
  }
  if (fieldName === 'phone') PhilippineMobile.showError(elements.phone, message);
  if (message) {
    errorElement.textContent = message;
    errorElement.className = "mt-2 text-sm text-red-600";
    return;
  }

  errorElement.textContent = "";
  errorElement.className = "mt-2 hidden text-sm text-red-600";
}

function renderValidationErrors(errors) {
  const requestedFields = Object.keys(elements.fieldErrors);

  requestedFields.forEach(fieldName => setFieldError(fieldName, errors[fieldName] || ''));
  const firstError = requestedFields.find(name => errors[name]);
  if (firstError) elements[firstError]?.focus();

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
  const isReady = !ownerValidationInProgress;

  elements.nextButton.disabled = !isReady;
  elements.nextButton.setAttribute("aria-disabled", String(!isReady));
  elements.nextButton.classList.toggle("opacity-50", !isReady);
  elements.nextButton.classList.toggle("cursor-not-allowed", !isReady);

  return isReady;
}

function handleInput(event) {
  hideSimilarOwnerWarning();
  if ([elements.firstName, elements.lastName].includes(event.target)) {
    selectedCustomer = null;
    elements.firstName.readOnly = elements.lastName.readOnly = false;
  }
  if (event.target === elements.email) {
    setFieldError('email', '');
  }
  hideMessage();
  syncNextButtonState();
}

function continueToPetStep(values, confirmSimilarName = false) {
  elements.firstName.value = values.firstName;
  elements.lastName.value = values.lastName;
  elements.phone.value = PhilippineMobile.format(values.phone);
  elements.email.value = values.email;

  if (!preserveContinuation) clearWalkInContinuationDraft();
  saveOwnerDraft({
    ...values,
    confirmSimilarName: Boolean(confirmSimilarName),
  });

  window.location.href = "/admin/walk-in/pets";
}

async function validateNewOwnerAndContinue(values, confirmSimilarName = false) {
  setOwnerValidationInProgress(true);
  hideMessage();

  try {
    await API.validateWalkInNewOwner({
      first_name: values.firstName,
      last_name: values.lastName,
      phone: values.phone,
      email: values.email || null,
      confirm_similar_name: Boolean(confirmSimilarName),
    });
    if (JSON.stringify(getFormValues()) !== JSON.stringify(values)) return;
    hideSimilarOwnerWarning();
    continueToPetStep(values, confirmSimilarName);
  } catch (error) {
    if (JSON.stringify(getFormValues()) !== JSON.stringify(values)) return;
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
      if (fieldName) setFieldError(fieldName, [].concat(messages)[0] || "Check this field.", { server: true });
    });
    const first = Object.keys(error.errors || {}).find(field => fieldMap[field]);
    if (first) elements[fieldMap[first]]?.focus();
    else showMessage(error.message || 'Owner information could not be checked. Please try again.', 'error');
  } finally {
    setOwnerValidationInProgress(false);
  }
}

async function handleSubmit(event) {
  event.preventDefault();
  if (ownerValidationInProgress) return;

  if (!selectedCustomer) {
    elements.firstName.value = HumanName.normalize(elements.firstName.value);
    elements.lastName.value = HumanName.normalize(elements.lastName.value);
  }

  const values = getFormValues();
  const errors = validateOwner(values);

  if (Object.keys(errors).length > 0) {
    renderValidationErrors(errors);
    syncNextButtonState();
    return;
  }

  if (selectedCustomer) {
    clearWalkInContinuationDraft();
    saveOwnerDraft(values, selectedCustomer);
    window.location.href = "/admin/walk-in/pets";
    return;
  }

  await validateNewOwnerAndContinue(values);
}

function bindEvents() {
  elements.form.addEventListener("submit", handleSubmit);
  for (const [field, type] of [['firstName', 'first_name'], ['lastName', 'last_name']]) {
    elements[field].setAttribute('data-human-name', type);
    HumanName.bind(elements[field], message => {
      const error = elements.fieldErrors[field];
      error.textContent = message;
      error.className = `mt-2 ${message ? '' : 'hidden '}text-sm text-red-600`;
      elements[field].classList.toggle('border-red-400', Boolean(message));
    });
  }
  AccountEmail.bind(elements.email, { required: false, maxLength: 255, errorId: 'ownerEmailAddressError',
    isUnchanged: () => selectedCustomer && AccountEmail.normalize(elements.email.value) === AccountEmail.normalize(selectedCustomer.email),
  });
  PhilippineMobile.bind(elements.phone, {
    errorId: 'ownerPhoneNumberError',
    isUnchanged: () => selectedCustomer?.phone && PhilippineMobile.normalize(elements.phone.value) === PhilippineMobile.normalize(selectedCustomer.phone),
    onChange: () => handleInput({ target: elements.phone }),
  });

  [
    elements.firstName,
    elements.lastName,
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
  document.getElementById("walkInOwnerBackLink").setAttribute(
    "href", isClinic ? "/admin/clinic" : "/admin/grooming",
  );
  if (!await API.waitForSessionVerification()) {
    return;
  }

  initializeWalkInFlow();
  bindEvents();
  const draft = readOwnerDraft();
  let firstInvalid;
  for (const field of ['firstName', 'lastName']) {
    const saved = draft.nameErrors?.[field];
    if (saved && saved.value === elements[field].value) {
      setFieldError(field, saved.message, { server: true });
      firstInvalid ||= elements[field];
    }
  }
  if (draft.nameErrors) {
    delete draft.nameErrors;
    sessionStorage.setItem(WALK_IN_OWNER_STORAGE_KEY, JSON.stringify(draft));
  }
  firstInvalid?.focus();
  hideMessage();
  syncNextButtonState();
});
