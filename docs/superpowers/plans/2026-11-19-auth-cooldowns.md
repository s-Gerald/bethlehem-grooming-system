# Authentication cooldown implementation plan

Goal: ordinary authentication failures and interactive message resends use 60, 180, then at most 300 seconds, independently of OTP expiration.

Architecture: add a focused `App\Support\ProgressiveAuthThrottle` for policy, source-scoped persistent failure state, and successful-issuance pacing. Keep existing transactions, row/cache locks, delivery rollback, fingerprints and login OTP implementation. No schema changes.

1. Add behavioral regressions and reproduce obsolete password/email/OTP limits. Verify with PHP 8.4 PHPUnit and existing Node harnesses.
2. Implement the shared helper; integrate password sign-in, reset, privileged/customer codes, phone, and directly related staff email/recovery codes. State survives challenge replacement/cancellation and resets after 30 minutes of inactivity or successful proof.
3. Replace interactive destination email quotas with shared destination resend progression; preserve source emergency quotas and non-interactive notifications. Replace SMS user/phone quotas with successful-send progression, preserving uncertain-provider retry safety and emergency budgets.
4. Shorten directly related route flood windows; expose authoritative retry metadata and extend existing countdowns without automatic actions or per-second announcements.
5. Run focused tests, then the affected authentication/security suites. Review the complete diff for obsolete hard ceilings, rollback/concurrency issues, Owner profile separation and unrelated changes.

Verification: exercise 5-failure rounds at 60/180/300/300, same-code recovery, replacement/cancellation resistance, successful-proof reset, source isolation, failed-send rollback, cross-purpose email pacing, emergency ceilings, endpoint flood protection, countdown restoration and no automatic sends.
