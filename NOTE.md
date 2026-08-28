# Packaging notes — building the install .zip

When creating the `.zip` for PrestaShop's "Upload a module" install flow, exclude
dev-only tooling that PrestaShop never needs at runtime.

## Running PHPUnit locally — keep `vendor/` renamed when not testing

PrestaShop's core (`ContainerBuilder::loadModulesAutoloader()`) auto-includes
`vendor/autoload.php` for every **installed** module, even outside of testing. Composer's
generated autoloader always registers itself with `prepend = true`, so if this module's
`vendor/` is present, its bundled `nikic/php-parser` (pulled in by PHPUnit) shadows
PrestaShop core's own copy — breaking core module-parsing code that depends on a different
`nikic/php-parser` API version and causing a 500 error on pages like module configure.

To avoid this, keep the folder named `vendor.phpunit-test` by default, and only rename it
to `vendor` for the duration of a test run:

1. Rename `vendor.phpunit-test` → `vendor`
2. Run the PHPUnit suite
3. Rename `vendor` → `vendor.phpunit-test` again immediately after

### Commands

From `modules/reepay/`:

```bash
# 1. Rename vendor.phpunit-test -> vendor (Windows/PowerShell)
Rename-Item vendor.phpunit-test vendor

# 2. Run the suite
vendor/bin/phpunit

# 2a. Run a single test file
vendor/bin/phpunit tests/WebhookSignatureVerifierTest.php

# 2b. Run a single test method
vendor/bin/phpunit --filter testMethodName tests/WebhookSignatureVerifierTest.php

# 3. Rename vendor -> vendor.phpunit-test again immediately after
Rename-Item vendor vendor.phpunit-test
```

Bash equivalent for steps 1 and 3: `mv vendor.phpunit-test vendor` and `mv vendor vendor.phpunit-test`.

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
