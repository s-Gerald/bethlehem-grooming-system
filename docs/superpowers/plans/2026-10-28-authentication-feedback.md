# Authentication Feedback Implementation Plan

**Goal:** Make countdowns, pending-registration navigation, and email feedback accurate while preserving authentication security.

**Architecture:** Extend the existing VerificationCode formatter, AuthenticationResend policy presentation, browser-bound registration credentials, and delivery receipts. Keep pending signup, emailed links, and login OTP as distinct states.

**Tech Stack:** Laravel 12/PHP, plain JavaScript, existing Node VM regressions and PHPUnit.

**Spec:** User-attached “Unified Authentication Countdown, Magic-Link Navigation, and Duplicate Registration UX Fix”.

## Constraints

- Preserve quotas, expiry, locking, real session authorization, existing blue/white styling, and production URLs.
- No migration, new dependency, deployment, browser submissions, or manual functional testing.
- User selected Option 2: uniform public signup responses, with account-dependent work encrypted on the existing durable queue. Specific status requires pre-existing browser-bound authorization or email proof.

## Review focus

- Countdown drift, expiry, refresh, duplicate submissions, and cleanup.
- Duplicate signup must not discard valid credentials or overwrite another registration.
- Queued, sent, failed, and resumed registrations must render different truthful states.
- Expired links must not be presented as usable.
- Focus/pageshow/broadcast events must preserve handoff without duplicate session requests.

## Tasks

- [x] Update existing frontend regression tests for compact durations, deadline expiry, and Sign-In 429 button recovery; verify failures before fixes and passing regressions afterward.
- [x] Extend existing PHP tests for uniform public outcomes, encrypted work/replay, authorized resume, unchanged details/link/quota, expired link guidance, and credential rejection. Preserve server policy limits.
- [x] Update existing signup/session tests for credential retention, delivery states, minimal navigation, session request deduplication and back/forward timer lifecycle. Refresh affected asset versions and shared phone formatter consumers.
- [x] Complete final broader regressions/build and record results in `docs/customer-authentication.md`: 1,845 PHP tests (8 MySQL skips), 85/85 frontend scripts, final focused checks and production build passed. Real pending recovery card inspected in ChatGPT browser without entering data or submitting forms. No schema changes, commits, pushes or deployment.
