# Registration Pairing Implementation Plan

**Goal:** Automatically authenticate the initiating customer registration tab after deliberate email verification, requiring pairing only in a separate session.

**Architecture:** Extend pending registrations and the current magic-link/session services. A foreign verification POST requests a six-digit, five-minute challenge (capped by link and registration deadlines); five failed attempts exhaust it. Only the bound initiating context can read its display code or redeem approval. A bounded encrypted completion receipt allows retrying the same session after a lost response. Explicit review invalidates pairing and retains the existing fresh-password fallback.

**Tech stack:** Laravel 12, existing database transactions and Sanctum cookies, vanilla JavaScript.

**Spec:** User attachment, “Implement Scenario B: Cross-Browser Email Verification with Automatic Customer Login.”

## Constraints

- Customer sign-up only; preserve sign-in OTP, terms, uniqueness, queue, origin and cookie protections.
- No manual browser form entry; the user handles manual functional tests.
- Follow existing Bethlehem verification card styles and concise supplied copy.
- Continue implementation in this session under AGENTS.md; no workflow approval, commits or worktree required.

## Tasks

- [x] Add failing backend tests for foreign challenge, bound status, approval and idempotent redemption; update only review tests that now require an explicit review action.
- [x] Add pending pairing/deadline/approval/completion fields in an additive migration and model. Implement a registration-specific service used by verification, bound status and redemption routes. Reuse CustomerRegistrationService::complete, preserving the pending receipt only for redemption. Reset challenge state transactionally on successful issuance/review; rejected queues roll back.
- [x] Verify wrong/missing/expired/exhausted codes, wrong origin/binding, replay, canceled/replaced/resend links, existing accounts, fresh-password recovery, inactive/revoked sessions and lost responses with focused PHPUnit coverage.
- [x] Add failing frontend behavior tests for polling, visibility, bounded requests, missing channels, server validation before redirect, and foreign approval without login.
- [x] Extend API helpers and the existing verification scripts/card with pairing input and bound display. Poll at five seconds while visible; pause while hidden, recheck on focus, and stop on completion/expiry/cancellation. BroadcastChannel remains only a hint.
- [x] Run affected backend/frontend tests, build and inspect accessible desktop/mobile rendering through navigation only. Document deployment migration/worker requirements and limits of local validation.

## Review focus

- A lost redemption response must reissue the same cookie, never a second token.
- An email link alone must not authorize the abandoned registration tab.
- Resend failure must retain the old challenge/link; successful resend invalidates approval.
- Review must revoke the initiating binding, even after a pairing challenge.
- Hidden/restored pages and unavailable tab messaging must never bypass backend session validation.
