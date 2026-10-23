# QR Payment UI and Upload Improvements

**Goal:** Simplify the existing Grooming modal, preserve local receipt OCR with image/PDF upload, and improve central QR image cropping and settings feedback.

**Architecture:** Keep the current settlement, verification, reference constraints, private storage, migration, caching and API identifiers. Extend the existing QR modal state, OCR service and settings component. Use lazy local Cropper.js/jsQR for account QR editing and PDF.js only for staff-selected PDFs; Tesseract remains the OCR engine.

**Scope:** Grooming and Settings only, with consistent active staff labels in success/history. No gateways, Clinic/POS collection interfaces, schema changes, private environment edits, commits or deployment. The user owns actual localhost uploads and workflow testing.

## Steps and verification

1. Extend existing configuration tests for incomplete/disabled/enabled states, per-field activation errors and persisted preview reconciliation. Extend existing JS settings tests for crop approval/cancel and upload of the approved result. Confirm failing behavior, then implement.
2. Add `qr-image-editor.js` with square crop suggestion, quiet-zone padding, manual adjustment, zoom, final preview, readable-result validation and cleanup. Load Cropper/jsQR only on image selection. Add meaningful geometry/decoding/export tests against controlled QR pixels, without source-text assertions.
3. Extend existing OCR and modal tests for receipt selection/drop/paste, file indication, errors/manual fallback, recipient comparison and bounded PDF processing. Implement full-page PDF rendering without cropping receipts. Confirm actual recognition against controlled image/PDF fixtures.
4. Update the existing HTML surfaces with compact scan disclosure, essential editable fields, secondary source/notes and collapsed admin exceptions. Add scoped styles following Bethlehem's existing blue/white palette and modal scrolling. Rename display labels while keeping `qrph` identifiers and historical behavior.
5. Run focused regressions, full `php artisan test --compact`, `npm test`, `npm run build`, and isolated MySQL QR tests. Review authorization, concurrency, Cash/invoice/history coverage and remove utilities made obsolete by these changes.

## Implementation boundaries

- Only verified QR records settle. OCR never sets verification, and Amount Received never derives from Total Due.
- Receipt files remain local. Image/PDF processing has file, dimension/page and time limits; full receipt content is retained during recognition and discarded afterward.
- Account QR changes use an administrator-approved result. A decoder establishes readability, not official ownership or payment-network eligibility.
- Cropper editing is inline; suitable originals can be used unchanged. Failed/cancelled edits preserve the previously approved image.
- Settings save reconciles the returned persisted configuration plus its authenticated image, without a redundant configuration GET. Failed enabling preserves edits and returns specific field errors.
- No lazy processing initializes during unrelated navigation; temporary URLs, workers, canvases and cropper instances are released.

Writing-plans and Hallmark are advisory under AGENTS.md. Implement in this session without an approval gate or unrelated workflow changes.
