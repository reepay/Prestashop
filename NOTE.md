# Packaging notes — building the install .zip

When creating the `.zip` for PrestaShop's "Upload a module" install flow, exclude
dev-only tooling that PrestaShop never needs at runtime.

## Exclude

| Path | Reason |
|---|---|
| `.git/` | Version control metadata, unrelated to module runtime. |
| `tests/` | PHPUnit test suite — dev-only. |
| `vendor/` | Composer dev dependencies (PHPUnit, etc.). Production code loads classes via `include_once`, not Composer autoload, so this is never needed at runtime. |
| `composer.json`, `composer.lock` | Manifests for the dev tooling above. |
| `phpunit.xml` | PHPUnit configuration — dev-only. |
| `.phpunit.result.cache` | Cache file PHPUnit generates itself — no runtime value. |

## Optional to exclude

| Path | Reason |
|---|---|
| `README.md` | Developer documentation, not required by PrestaShop. |
| `LICENSE` | Doesn't affect module behavior either way. |
| `config.xml`, `config_da.xml` | PrestaShop regenerates these automatically on install/configure. Safe to include or omit. |

## Must keep

`api/`, `classes/` (includes `WebhookSignatureVerifier.php`, `WebhookSecretManager.php`,
`WebhookAuthenticator.php`, `AdminOrderContentPresenter.php`), `controllers/`, `sql/`,
`translations/`, `upgrade/`, `views/`, `index.php`, both `logo` files, and `reepay.php`.

## Zip structure

The `.zip` must contain a single top-level `reepay/` folder wrapping everything above —
not the folder's contents zipped directly — so that extracting it produces
`reepay/reepay.php`. PrestaShop's installer expects that exact structure; a flattened
zip will fail to install.
