# GetYourBD WHMCS .bd Domain Integration

This package contains a WHMCS addon module plus a WHMCS registrar module for selling `.bd` family domains through the GetYourBD Partner API. The repository is public.

## What It Includes

- Adds `.bd`, `.com.bd`, `.net.bd`, `.org.bd`, `.edu.bd`, `.ac.bd`, `.gov.bd`, `.mil.bd`, `.info.bd`, `.বাংলা`, `.co.bd`, `.tv.bd`, `.id.bd`, `.sch.bd`, and `.ai.bd` WHOIS lookup entries to `resources/domains/whois.json`.
- Removes that WHOIS entry again when the addon module is deactivated.
- Adds WHMCS additional domain fields for GetYourBD-required NID and document information.
- Adds secure customer document uploads and persists registry data through checkout and manual order acceptance.
- Provisions BDT and USD registration and renewal pricing for sellable `.bd` TLDs from the GetYourBD pricing API.
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

   It also creates or updates BDT and USD domain pricing for the supported sellable TLDs. BDT and USD must exist in **System Settings > Currencies** before activation.

3. In WHMCS Admin, go to **System Settings > Domain Registrars**, activate **GetYourBD Partner API**, and configure:

   - Partner User ID
   - Partner Password
   - Default nameservers if your order form may not supply nameservers. The defaults are `ns1.btcl.com.bd`, `ns2.btcl.com.bd`, and `ns3.btcl.com.bd`.
   - Document handling options

4. Review **Addons > GetYourBD .bd Domain Integration**. You can sync prices from GetYourBD, manually override BDT/USD prices, and apply saved prices to WHMCS.

5. Review the generated BDT and USD pricing in **System Settings > Domain Pricing**.

## Pricing Provisioning

On activation, the addon fetches BDT prices from:

```text
https://getyour.com.bd/api/v1/tld-prices
```

It then fetches the USD to BDT rate from:

```text
https://v6.exchangerate-api.com/v6/240281cd706e38e31942a3f6/latest/USD
```

USD prices are calculated as `BDT price / BDT per USD` and rounded to 2 decimals.

The addon stores API prices and editable applied prices in `mod_getyourbd_tld_prices`, then writes WHMCS `tbldomainpricing` and `tblpricing` records for both BDT and USD. It enables 1-5 year registration and renewal pricing by multiplying the 1-year amount by the term length. Transfer pricing is disabled.

The addon page includes:

- Sync from GetYourBD API.
- Manual BDT and USD price overrides per TLD.
- Apply Prices to WHMCS.

The addon does not provision pricing for `.ac.bd`, `.gov.bd`, or `.mil.bd`.


## Customer Checkout Fields

The addon registers these additional domain fields for supported `.bd` TLDs:

- `NID`
- `NID Full Name`
- `Mobile Number`
- `NID Document`
- `Registration Document`

The module replaces the document text fields with file upload controls on the WHMCS domain configuration page. Customers can upload JPG, JPEG, PNG, or PDF documents up to 15 MB. Files are stored below the configured WHMCS attachments directory and referenced by an opaque token.

The checkout hook validates NID Full Name, NID, Bangladesh mobile format, and the required NID document. After checkout, the module stores the values in `mod_getyourbd_domain_data` and mirrors them to WHMCS domain additional fields so they remain available when an admin manually accepts the order.

Administrators can replace the NID document or upload an optional registration document from the WHMCS domain management page. Open the client domain in **Clients > Domain Registrations** and use the **GetYourBD Registration Documents** panel. Admin uploads are saved immediately to the same secure storage and domain additional fields used by the registrar request.

GetYourBD orders expose only the first three nameserver fields. Registration periods from 1-5 years are available from the domain search result when the corresponding WHMCS pricing rows are enabled.

## Registration Flow

WHMCS handles the cart, invoice, and payment. Once WHMCS decides to send a registration command, `getyourbd_RegisterDomain()` builds the partner API payload:

- `domain`
- `nameServers[0..2]`
- `fullName`
- `nidFullName`
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

BDT/USD domain pricing, saved overrides, and order log records are intentionally retained for audit/history and to avoid disrupting existing domains or invoices.

## Renewal

Renewal is intentionally not implemented yet because the partner API repository currently documents only domain order creation. Add `getyourbd_RenewDomain()` after the renewal endpoint and required payload are available.

## References

- WHMCS Registrar Modules: https://developers.whmcs.com/domain-registrars/
- WHMCS Addon activation/deactivation: https://developers.whmcs.com/addon-modules/installation-uninstallation/
- WHMCS WHOIS custom file: https://docs.whmcs.com/9-0/domains/whois-servers/
- WHMCS custom domain fields: https://docs.whmcs.com/9-0/domains/pricing-and-configuration/custom-domain-fields/
- GetYourBD Partner API: https://github.com/ASTGD/getyourbdpartnerapi
