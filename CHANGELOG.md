## Changelog
[Unrelease]
- [Security] - The webhook endpoint now verifies an HMAC-SHA256 signature from Frisbii before processing any event. Requests that are malformed, unsigned, or signed with an invalid signature are rejected before any cart or order logic runs, closing a gap that previously let a forged webhook request create or modify an order.
- [Feature] - The webhook signing secret is cached for 10 minutes and refreshed automatically (once) if a signature check fails, so Frisbii rotating the secret no longer requires manual intervention. If the secret cannot be retrieved at all, the webhook fails closed and rejects the event.
- [Fix] - The cached webhook secret is now invalidated automatically whenever the private API key is changed in the module configuration, since a new key can belong to a different Frisbii account.
- [Refactor] - Extracted the admin order panel's display logic (card logo lookup, refund control state, dashboard link) into a separate, unit-tested class, and removed a dead code path that queried the Frisbii API for an order event timeline that was never actually displayed.
- [Docs] - Added `note.md` with guidance on excluding dev-only files (tests, vendor, composer files) when packaging the module `.zip` for installation.


v 1.3.8
- [Feature] - Added support for Prestashop 9.1.x.
- [Fix] - Fixed "Attempt to read property 'alert_emails' on null" error when saving the module configuration; standardized Reepay API error responses when cURL fails or returns invalid JSON, removed duplicate curl_exec() calls during webhook updates, and added null checks/validation for incomplete or unexpected API responses.
- [Fix] - Fixed webhook not updating on local site environments. Frisbii does not accept webhook URLs using http://localhost; a public test site should be used instead.
- [Fix] - Fixed Frisbii Payments status always showing "Not Authenticated". The authentication check previously required both account name and email, but per the Frisbii API schema only the name is required. Authentication now relies on the account name only; the email is still displayed when available but no longer affects authentication status.
- [Fix] - Fixed HTTP 500 error on the payment confirmation page caused by a race condition between the Reepay webhook and the payment confirmation redirect. Added a 5-second delay before calling orderExists() to give the webhook time to create the order, and wrapped validateOrder() in a try/catch (PrestaShopException) to prevent a fatal error when the webhook creates the order first.
- [Docs] - Updated Readme with detailed step-by-step installation guide for Prestashop.