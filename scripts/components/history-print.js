import { bethlehemPrintHeader, confirmationFields } from "./confirmation-presentation.js?v=20261115";

function copyRecordContent(nodes, target) {
  for (const child of nodes) {
    if (child.nodeType === Node.TEXT_NODE) {
      target.append(child.cloneNode());
      continue;
    }
    if (child.nodeType !== Node.ELEMENT_NODE || child.hidden || child.style?.display === "none"
        || child.matches("template, admin-loader, [role=alert], a, svg")) continue;

    if (child.matches("button")) {
      const filename = child.querySelector("[data-print-filename]");
      if (filename) {
        const file = document.createElement("span");
        file.textContent = filename.textContent;
        target.append(file);
      }
      continue;
    }

    // Expand the signed snapshot without retaining an interactive disclosure.
    const tag = child.matches("details") ? "div" : child.matches("summary") ? "h6" : child.localName;
    const element = document.createElement(tag);
    for (const attribute of ["class", "src", "alt", "width", "height", "scope"]) {
      if (child.hasAttribute(attribute)) element.setAttribute(attribute, child.getAttribute(attribute));
    }
    if (child.matches("[data-print-payment]")) element.dataset.printPayment = "";
    if (child.matches("summary")) element.textContent = child.dataset.printLabel;
    else copyRecordContent(child.childNodes, element);
    target.append(element);
  }
}

function appendFields(container, fields) {
  container.insertAdjacentHTML("beforeend", confirmationFields(fields.filter(([, value]) => value != null && value !== "")));
}

export async function printHistoryRecord(type, {fields, body, consent, patients, totalPaid}, stillOpen, trigger) {
  if (document.querySelector("[data-history-print]")) return;
  const record = document.createElement("article");
  record.className = "history-print confirmation-page";
  record.dataset.historyPrint = "";
  const title = type === "grooming" ? "GROOMING VISIT RECORD" : "CLINIC VISIT RECORD";
  record.innerHTML = bethlehemPrintHeader(title);
  appendFields(record, fields);

  if (type === "grooming") {
    copyRecordContent(body.childNodes, record);
    const payment = record.querySelector("[data-print-payment]");
    if (payment) record.append(payment);
  } else {
    copyRecordContent([consent], record);
    for (const patient of patients) {
      const section = document.createElement("section");
      section.className = "history-print-patient";
      const name = document.createElement("h2");
      name.textContent = patient.name;
      section.append(name);
      appendFields(section, patient.fields);
      for (const source of patient.sections) copyRecordContent(source.childNodes, section);
      record.append(section);
    }
    if (totalPaid) {
      const payment = document.createElement("section");
      payment.className = "history-record-section";
      payment.innerHTML = "<h2>Payment Summary</h2>";
      appendFields(payment, [["Total paid", totalPaid]]);
      record.append(payment);
    }
  }

  await Promise.all([...record.querySelectorAll("img")].map(image => image.decode().catch(() => {})));
  if (!stillOpen()) return;
  const cleanup = () => {
    record.remove();
    document.body.classList.remove("history-record-printing");
    window.removeEventListener("afterprint", cleanup);
    if (stillOpen()) trigger?.focus({preventScroll: true});
  };
  document.body.append(record);
  document.body.classList.add("history-record-printing");
  window.addEventListener("afterprint", cleanup, {once: true});
  try {
    window.print();
  } catch (error) {
    cleanup();
    throw error;
  }
}
