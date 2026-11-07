# Authentication and registration reliability

Goal: preserve one active session per account, isolate independent browser profiles, and complete verify-first registration on its original browser.

Architecture: retain Sanctum sessions, account row locks, remembered-device rotation, and the pending registration's two browser-bound credentials. Fix ownership and response ordering in the existing middleware, services and API client. Keep existing signup polling and visual structure.

1. Reproduce cookie response races and remembered-account fallback in `IndependentAuthenticationSessionsTest.php`; fix `UseAccountSessionCookie.php` and `RememberedDeviceService.php`. Verify different-account isolation, successful-OTP-only replacement, obsolete logout, and restore concurrency.
2. Reproduce persistent/temporary cross-tab switches and delayed authentication in `frontend-auth-session-regression.cjs`; fix the existing `scripts/api.js` session source and transition guards. Use only nonsecret profile metadata; retain HttpOnly credentials and session persistence policy. Verify isolated profiles, mixed tabs, stale logout and responses.
3. Reproduce device-clock expiry and transient verification failures in `customer-signup-verification-regression.cjs`; fix the existing signup context/poller and verification error handling. Verify backend cross-device approval, distinct deadlines, resend ordering and idempotent finalization in `VerifyFirstRegistrationTest.php` and `EmailVerificationTest.php`.
4. Run focused authentication, registration, lock and security regressions; document deployment and production evidence. No new database schema or longer security deadline is planned.

Review focus: delayed Set-Cookie responses, older temporary tabs, failed OTP while already authenticated, device clock skew, and lost finalization responses. Production configuration is unverified until read directly; never infer it from local defaults.
