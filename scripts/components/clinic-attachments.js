// ── Shared attachment helpers ─────────────────────────────────────────────────

async function downloadClinicAttachment(apptId, attachment, result) {
  result ??= await API.clinicDownloadAttachment(apptId, attachment.id);
  const objectUrl = URL.createObjectURL(result.blob);
  const link = document.createElement("a");
  link.href = objectUrl;
  link.download = result.fileName || attachment.file_name || "clinic-attachment";
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}

function clinicAttachmentImageType(attachment, result) {
  const type = [result?.blob.type, result?.contentType, attachment.file_type]
    .map(value => (value || "").split(";")[0].trim().toLowerCase())
    .find(value => value && value !== "application/octet-stream" && value !== "binary/octet-stream");
  if (type === "image/png") return type;
  if (type === "image/jpeg" || type === "image/jpg") return "image/jpeg";
  if (type) return "";

  // Uploads validate both the MIME and extension. Only fall back for generic or missing types.
  const filename = attachment.file_name || result?.fileName || "";
  if (/\.png$/i.test(filename)) return "image/png";
  if (/\.jpe?g$/i.test(filename)) return "image/jpeg";
  return "";
}

let closeClinicImagePreview;

async function viewClinicAttachment(apptId, attachment, trigger = document.activeElement) {
  if (/\.dcm$/i.test(attachment.file_name || "")) return downloadClinicAttachment(apptId, attachment);
  if (clinicAttachmentImageType(attachment)) return previewClinicImage(apptId, attachment, trigger);

  const result = await API.clinicDownloadAttachment(apptId, attachment.id);
  if (clinicAttachmentImageType(attachment, result)) return previewClinicImage(apptId, attachment, trigger, result);
  return downloadClinicAttachment(apptId, attachment, result);
}

function previewClinicImage(apptId, attachment, trigger, result) {
  closeClinicImagePreview?.();
  const dialog = document.createElement("dialog");
  dialog.className = "clinic-image-preview m-auto rounded-2xl bg-white p-0 shadow-2xl";
  dialog.setAttribute("aria-labelledby", "clinic-image-preview-title");
  dialog.innerHTML = `
    <div class="clinic-image-preview-layout">
      <header class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
        <h2 id="clinic-image-preview-title" class="min-w-0 py-2 text-sm font-bold text-[#2f4b66]"></h2>
        <button type="button" autofocus aria-label="Close image preview" class="shrink-0 rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50">
          <svg class="ph-icon h-5 w-5" aria-hidden="true" viewBox="0 0 256 256" fill="currentColor" focusable="false"><use href="/assets/icons/phosphor.svg?v=admin-icons-20261018#x"></use></svg>
        </button>
      </header>
      <div class="clinic-image-preview-stage bg-slate-50 p-2">
        <p role="status" class="text-sm text-slate-600">Loading image…</p>
        <p role="alert" hidden class="text-sm text-red-700"></p>
        <img hidden>
      </div>
      <footer class="flex justify-end border-t border-slate-200 px-4 py-3">
        <button type="button" disabled class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-[#315b7e] hover:bg-slate-50 disabled:opacity-50">Download Original</button>
      </footer>
    </div>`;
  const [closeButton, downloadButton] = dialog.querySelectorAll("button");
  const loading = dialog.querySelector('[role="status"]');
  const errorMessage = dialog.querySelector('[role="alert"]');
  const image = dialog.querySelector("img");
  const filename = attachment.file_name || result?.fileName || attachment.label || "Clinic image";
  dialog.querySelector("h2").textContent = filename;
  image.alt = filename;

  let objectUrl;
  let closed = false;
  return new Promise(resolve => {
    function close() {
      if (closed) return;
      closed = true;
      dialog.close();
      dialog.remove();
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      window.removeEventListener("pagehide", close);
      closeClinicImagePreview = null;
      resolve();
      // The calling record re-enables its attachment button when the preview resolves.
      requestAnimationFrame(() => {
        if (!closeClinicImagePreview) trigger?.focus({preventScroll: true});
      });
    }

    closeButton.addEventListener("click", close);
    dialog.addEventListener("close", close);
    dialog.addEventListener("cancel", event => {
      event.preventDefault();
      close();
    });
    dialog.addEventListener("keydown", event => {
      // Let the browser cancel this dialog without reaching the underlying record's Escape handler.
      if (event.key === "Escape") event.stopPropagation();
      if (event.key !== "Tab") return;
      const last = downloadButton.disabled ? closeButton : downloadButton;
      if (event.shiftKey ? event.target === closeButton : event.target === last) {
        event.preventDefault();
        (event.shiftKey ? last : closeButton).focus();
      }
    });
    downloadButton.addEventListener("click", async () => {
      try {
        await downloadClinicAttachment(apptId, attachment, result);
      } catch {
        errorMessage.textContent = "Could not download this attachment.";
        errorMessage.hidden = false;
      }
    });

    async function load() {
      try {
        result ??= await API.clinicDownloadAttachment(apptId, attachment.id);
        if (closed) return;
        const type = clinicAttachmentImageType(attachment, result);
        if (!type) {
          await downloadClinicAttachment(apptId, attachment, result);
          close();
          return;
        }
        downloadButton.disabled = false;
        const blob = result.blob.type === type ? result.blob : result.blob.slice(0, result.blob.size, type);
        objectUrl = URL.createObjectURL(blob);
        image.src = objectUrl;
        await image.decode();
        if (!closed) image.hidden = false;
      } catch {
        if (closed) return;
        errorMessage.textContent = "Could not load this image.";
        errorMessage.hidden = false;
      } finally {
        loading.hidden = true;
      }
    }

    document.body.appendChild(dialog);
    dialog.showModal();
    closeClinicImagePreview = close;
    window.addEventListener("pagehide", close);
    load();
  });
}
