# Frisbii Pay
Lastest version: 1.3.8.1

## Information
Compatible with Prestashop versions: 8, 9

## Installation
1. Download the .zip file from https://github.com/reepay/Prestashop/releases
2. Log in to your Prestashop Administrator panel 
3. Go to **Modules** -> **Module Manager** -> "Upload the module"
4. Once installed, go to **Module Manager** Find "Frisbii Payments" -> Click "Configure"
5. Configure the plugin settings (API key, checkout type, etc.) and click **Save**

## Requirements
 - with Prestashop versions >= 8.x (also might work on lower versions but has not been tested)
 - php >= 8.0

## Last Changelog
v 1.3.8.1
- [Security] - The webhook endpoint now verifies an HMAC-SHA256 signature from Frisbii before processing any event. Requests that are malformed, unsigned, or signed with an invalid signature are rejected before any cart or order logic runs, closing a gap that previously let a forged webhook request create or modify an order.
- [Feature] - The webhook signing secret is cached for 10 minutes and refreshed automatically (once) if a signature check fails, so Frisbii rotating the secret no longer requires manual intervention. If the secret cannot be retrieved at all, the webhook fails closed and rejects the event.
- [Fix] - The cached webhook secret is now invalidated automatically whenever the private API key is changed in the module configuration, since a new key can belong to a different Frisbii account.
- [Refactor] - Extracted the admin order panel's display logic (card logo lookup, refund control state, dashboard link) into a separate, unit-tested class, and removed a dead code path that queried the Frisbii API for an order event timeline that was never actually displayed.
- [Docs] - Added `note.md` with guidance on excluding dev-only files (tests, vendor, composer files) when packaging the module `.zip` for installation.
- [Fix] - Replaced the timing-based workaround for the webhook/confirmation order-creation race (the 5-second sleep plus pre-lock `orderExists()` check added in 1.3.8) with an atomic, database-backed lock (MySQL `GET_LOCK()`/`RELEASE_LOCK()`) shared by both entry points via a deterministic per-cart lock key. The order-existence check and `validateOrder()` now run inside the same lock, the lock is released on every success, redirect, and exception path, and the confirmation redirect resolves the real order id even when the webhook creates the order first. The webhook now returns HTTP 503 (retryable) if the lock cannot be acquired within a bounded timeout, instead of failing silently; the fixed 5-second sleeps in both controllers have been removed.