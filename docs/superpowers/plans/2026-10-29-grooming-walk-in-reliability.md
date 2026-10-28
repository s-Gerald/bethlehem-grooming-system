# Grooming walk-in reliability implementation plan

**Goal:** Prevent walk-ins returning to Incoming, preserve historical references and queue ordering, and make retries safe.

**Architecture:** Keep DailyPetQueue's shared intake lock and transaction. Add a daily grooming counter row seeded from existing references/queues on first use; reserve identifiers under that lock. Persist successful keyed submission responses in the same transaction. Keep existing identity, consent, capacity and payment rules.

**Constraints:** Existing PHP ^8.2 production compatibility; PHP 8.4 tests; no dependencies, redesign, data deletion or production deployment. Browser inspection is limited to the supplied admin appointments page, without manual functional testing.

## Tasks

- [x] Extend existing board and GroomingAdministrationAuthorizationTest regressions: reject persisted walk-in origin using either booking_type or walkin_id; verify unchanged records on 422, online Undo, Cancel and Undo Start.
- [x] Add additive migration and grooming sequence service, used by WalkinController and online check-in plus DailyPetQueue pet allocation. Seed from all historical references including canceled/archived rows; counters survive online Undo. Verify repeat visits and >999 suffixes.
- [x] Add optional UUID submission key compatibility, persist actor/hash/response atomically, replay identical retries and reject changed payload/key reuse. Guard frontend submission before async confirmation and retain draft/signature/key after errors.
- [x] Extend existing isolated MySQL concurrency runner for counter contention and keyed retries. Run relevant PHP/frontend regressions, Pint and production build.
- [x] Back up local database, inspect user 15 and stable booking/relationship IDs from the confirmed duplicate WI-20261029-001 log, and migrate after verification. Do not edit history when the generator fix suffices. Document deployment and verified diagnosis.

## Review focus

- Either persisted origin indicator must prohibit Undo, even when the other differs.
- Historical cleared queue numbers must never make a WI reference reusable.
- Online Undo must release capacity while subsequent intake gets fresh numbers.
- Lost response retries must create one visit; changed payload requires a new attempt.
- Failed validation must roll back sequence, pets, consent and submission receipt together.
