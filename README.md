# GetYourBD WHMCS .bd Domain Integration

This package contains a WHMCS addon module plus a WHMCS registrar module for selling `.bd` family domains through the GetYourBD Partner API.

## What It Includes

- Adds the `.bd`, `.com.bd`, `.net.bd`, `.org.bd`, `.edu.bd`, `.ac.bd`, `.gov.bd`, `.mil.bd`, `.info.bd`, and `.বাংলা` WHOIS lookup entry to `resources/domains/whois.json`.
- Removes that WHOIS entry again when the addon module is deactivated.
- Adds WHMCS additional domain fields for GetYourBD-required NID and document information.
- Calls `POST https://getyour.com.bd/api/v1/domain/orders` after WHMCS triggers `RegisterDomain`.
- Stores GetYourBD partner order/invoice response metadata in `mod_getyourbd_orders` and appends a short domain admin note.

## Install

1. Copy the repository contents into the WHMCS root directory, preserving paths:

   ```text
   modules/addons/getyourbd
   modules/registrars/getyourbd
   ```

2. In WHMCS Admin, go to **System Settings > Addon Modules** and activate **GetYourBD .bd Domain Integration**.

   Activation updates:

   ```text
   resources/domains/whois.json
   resources/domains/additionalfields.php
   ```

3. In WHMCS Admin, go to **System Settings > Domain Registrars**, activate **GetYourBD Partner API**, and configure:

   - Partner User ID
   - Partner Password
   - API Base URL, normally `https://getyour.com.bd`
   - Default nameservers if your order form may not supply nameservers
   - Document handling options

4. In **System Settings > Domain Pricing**, add the supported `.bd` TLDs and set **Auto Registration** to `GetYourBD Partner API`.

5. Add pricing for registration periods you want to sell.

## Customer Checkout Fields

The addon registers these additional domain fields for supported `.bd` TLDs:

- `GetYourBD NID`
- `GetYourBD Contact Number`
- `GetYourBD NID Document`
- `GetYourBD Registration Document`

The GetYourBD API requires `nid_document` as an actual uploaded multipart file. WHMCS additional domain fields do not provide a native checkout file upload control, so this module accepts document references:

- A server file path.
- A path relative to the configured **Document Base Path**.
- An HTTPS URL, only when **Allow Remote Document URLs** is enabled.

Accepted file extensions are `jpg`, `jpeg`, `png`, and `pdf`.

## Registration Flow

WHMCS handles the cart, invoice, and payment. Once WHMCS decides to send a registration command, `getyourbd_RegisterDomain()` builds the partner API payload:

- `domain`
- `nameServers[0..2]`
- `fullName`
- `nid`
- `nid_document`
- `registration_document`, when supplied
- `email`
- `contactAddress`
- `contactNumber`
- `years`

If the API returns HTTP `201`, the module returns success to WHMCS. Any validation, authentication, rate-limit, or server error is returned as a registrar error for the admin to review.

## Deactivate

Deactivate **GetYourBD .bd Domain Integration** in **System Settings > Addon Modules**. This removes:

- The GetYourBD WHOIS entry from `resources/domains/whois.json`.
- The GetYourBD include block from `resources/domains/additionalfields.php`.

Order log records are intentionally retained in `mod_getyourbd_orders` for audit/history.

## Renewal

Renewal is intentionally not implemented yet because the partner API repository currently documents only domain order creation. Add `getyourbd_RenewDomain()` after the renewal endpoint and required payload are available.

## References

- WHMCS Registrar Modules: https://developers.whmcs.com/domain-registrars/
- WHMCS Addon activation/deactivation: https://developers.whmcs.com/addon-modules/installation-uninstallation/
- WHMCS WHOIS custom file: https://docs.whmcs.com/9-0/domains/whois-servers/
- WHMCS custom domain fields: https://docs.whmcs.com/9-0/domains/pricing-and-configuration/custom-domain-fields/
- GetYourBD Partner API: https://github.com/ASTGD/getyourbdpartnerapi
