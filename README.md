# Frisbii Pay
Lastest version: 1.3.8

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

## Changelog
v 1.3.8
- [Feature] - Added support for Prestashop 9.1.x.
- [Fix] - Fixed "Attempt to read property 'alert_emails' on null" error when saving the module configuration; standardized Reepay API error responses when cURL fails or returns invalid JSON, removed duplicate curl_exec() calls during webhook updates, and added null checks/validation for incomplete or unexpected API responses.
- [Fix] - Fixed webhook not updating on local site environments. Frisbii does not accept webhook URLs using http://localhost; a public test site should be used instead.
- [Fix] - Fixed Frisbii Payments status always showing "Not Authenticated". The authentication check previously required both account name and email, but per the Frisbii API schema only the name is required. Authentication now relies on the account name only; the email is still displayed when available but no longer affects authentication status.
- [Fix] - Fixed HTTP 500 error on the payment confirmation page caused by a race condition between the Reepay webhook and the payment confirmation redirect. Added a 5-second delay before calling orderExists() to give the webhook time to create the order, and wrapped validateOrder() in a try/catch (PrestaShopException) to prevent a fatal error when the webhook creates the order first.
- [Docs] - Updated Readme with detailed step-by-step installation guide for Joomla.