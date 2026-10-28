# Human Names Implementation Plan

**Goal:** Apply one name policy to all editable human names and allow password-confirmed customer name editing.

**Architecture:** A shared browser utility and Laravel support class use the same policy data and conformance fixtures. Normalize only submitted/edited names, never hydrated historical records. Use the existing customer cache revisions to reject obsolete profile responses.

**Scope:** The user's system-wide name specification in this chat. Preserve middle initials, duplicate matching, account security, phone/email verification, and unrelated cached data. No migrations or automatic historical rewrites.

1. [x] Add conformance fixtures and failing normalization/validation tests; implement shared policy in `scripts/utils/human-name.js`, `app/Support/HumanName.php`, and `app/Rules/ValidHumanName.php`. Verify JS/PHP agreement, Unicode, numeric rejection, composition, and error clearing.
2. [x] Trace and integrate signup/recovery, owner registration, Add Customer, staff creation/profile, and named groomers. Update existing regression harnesses and obsolete casing assertions. Verify focused workflow regressions.
3. [x] Add `PATCH /customer/profile/name` under existing authenticated/account/session middleware with customer role and throttling. Whitelist fields, verify the current password inside the transaction, update only names, return the existing `/me` identity shape. Verify authorization, incorrect/missing password, and unrelated fields.
4. [x] Add inline Edit name / Save changes / Cancel in the existing Personal Information card. Preserve draft names on password errors, clear secrets on completion/cancel, synchronize avatar/name and profile cache without refetching. Verify UI behavior and stale in-flight request handling.
5. [x] Run frontend regressions, relevant Laravel tests, and production build. Review the diff and document Hostinger redeployment steps and any environment limitations.

**Review focus:** unchanged historical casing; Unicode numerals and controls; IME composition; older profile requests after mutation; password failure followed by retry; cancellation with no network request.

**UI:** Modify `pages/client/settings.html` and its controller in place, keeping the established portal controls, two-column name layout, blue primary action, inline errors, password reveal, and responsive spacing. Other affected pages receive input attributes and the shared script only; no visual redesign.
