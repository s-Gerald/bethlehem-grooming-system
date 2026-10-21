# Customer phone verification preparation

## Local preview

Open `http://127.0.0.1:8000/pages/client/dashboard.html?smsPreview=1`, then select Pre-register with an eligible customer whose phone is missing or unverified. The same opt-in works on `localhost` and IPv6 loopback. Deployed hostnames ignore the parameter.

The modal confirms a saved number or accepts an unsaved `09XXXXXXXXX` number, then displays the shared six-cell OTP input. The Preview state selector exposes loading and error presentation. Verify and Resend explain that SMS is unavailable; no SMS request, countdown, persistence, or successful verification occurs. Continue to pre-registration performs the existing navigation and rechecks the button's current access decision. Close and Cancel dismiss the native modal; Escape and backdrop clicks do not.

Normal navigation and all existing grooming/clinic access rules remain in effect. Verified customers continue directly. Phone verification is not an authorization requirement in this phase.

## Account status and performance

`GET /api/me` returns `user.phone_verification_status`: `not_added`, `unverified`, or `verified`. It derives status from the loaded authenticated user's phone and `phone_verified_at`, without another query. No phone always means `not_added`, even with a stale timestamp. Settings consumes its existing profile request. The dashboard supplies its existing profile promise to the preview; it adds no profile/access requests, polling, or timers. The phone component, OTP utility, and shared CSS load only when the opt-in preview is opened.

## Future provider integration

`services.sms_verification.available` and `.enforced` are both fixed `false`. They document the inactive state; changing these values alone enables no functionality. No new environment variables, migrations, queue commands, or deployment steps are required for this phase. The existing nullable-phone migration and signup/email verification are unchanged.

Before enabling real SMS verification:

- Select a provider and configure its credentials and delivery mechanism on the backend.
- Add authenticated send, resend, and verify actions with server-side expiry, cooldowns, rate limits, and attempt limits. Never reuse email delivery or authentication-completion endpoints.
- Bind each challenge to the authenticated user, exact normalized phone number, and verification attempt. Enforce uniqueness of nonempty numbers before sending and atomically when persisting a verified number.
- Persist the phone and `phone_verified_at` only after proof of ownership; sending alone does not verify. Reset verified status if the number changes. Never trust frontend flags or browser storage.
- Report real SMS availability separately from account phone status. Only a genuine successful send may display “Enter the 6-digit code sent to” and start a server-derived resend cooldown; reuse `VerificationCode.formatCountdown` for display.
- Replace the preview integration with a genuine server-confirmed flow, refresh/invalidate the existing profile cache after successful verification, and enforce any required verification on the grooming and clinic submission endpoints. The dashboard is not an authorization boundary.
- Keep the local preview and its Continue action separate from production enforcement. Never route an enforced verification flow into preview continuation or its navigation fallback.

Deployment of a real provider will require its secure server configuration and any provider-specific queue/worker setup. Determine exact commands after selecting the provider and implementing those backend actions. No Hostinger or production changes were made for this preparation.
