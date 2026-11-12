# Domain Reseller API – HostBill Domain Module

A HostBill domain registrar module for the [Domain Reseller API](https://domain-reseller-api.de) by
Wolfscastle. Register, transfer, renew and manage domains (including `.de`, `.eu` and `.it`) directly
from HostBill.

API documentation: <https://domain-reseller-api.de/dokumentation>

## Features

| Area | What the module does |
| --- | --- |
| **Registration** | Registers domains for the HostBill period (1–10 years) with 2–6 nameservers, or with the managed DNS when no nameservers are given |
| **Transfer** | Transfers with the EPP/auth code |
| **Renewal** | Paid renewals via `POST /domains/{domain}/renew` for the HostBill period; the new expiry date is written back |
| **Contacts** | Creates owner/admin/tech handles from the client or domain contacts (`person`, or `organization` when a company name is set); editing updates the handles, admin/tech get their own handle when they differ from the owner |
| **Nameservers** | Custom nameservers or the managed DNS (`ns1/ns2.domain-reseller-api.de`), switching in both directions |
| **Glue records** | Register, modify and delete child nameservers (hosts below the domain) |
| **Registrar lock** | Read and set the transfer lock where the TLD supports it |
| **ID protection** | WHOIS privacy for OpenProvider TLDs, otherwise the WHOIS protection flag of the owner contact |
| **DNS management** | Record management on the managed DNS (enabling it switches the domain to the managed nameservers) |
| **EPP code** | Retrieves the auth code |
| **Sync** | Status and expiry date synchronisation |
| **Pricing** | TLD price import with the bookable term range (e.g. `.ai` from 2 years) |
| **Operations** | Sandbox mode, request/response logging with masked secrets |

## Requirements

- HostBill with PHP 7.4 or newer (`curl`, `json`; `mbstring` recommended).
- An account at [domain-reseller-api.de](https://domain-reseller-api.de) with an API key.
  Restricted keys need the scopes `domains`, `contacts`, `dns` and `pricing`.
- A funded wallet: registrations, transfers and renewals are charged to the wallet of the account.

## Installation

1. Download `domain-reseller-api-hostbill-<version>.zip` from the
   [latest GitHub release](https://github.com/WolfcastleDE/domain-reseller-api-hostbill/releases/latest).
2. Extract the ZIP into your HostBill root directory. It contains `includes/`, so the module ends up in
   `<hostbill>/includes/modules/Domain/DomainResellerApi/`.
3. In HostBill go to **Settings → Modules → Domain Modules** and activate **Domain Reseller API**.

## Configuration

| Field | Description |
| --- | --- |
| API URL | `https://domain-reseller-api.de` (only change when instructed) |
| API Key | Your `drapi_…` key. *Test Connection* calls an authenticated endpoint, so a wrong key is reported |
| Sandbox Mode | Sends `x-sandbox: true`: no real registrations, no charges |

Then assign the module to your TLDs and import the prices (*TLD pricing import*). Prices are **net EUR**
prices as charged by the API: `register`/`renew` per year at the minimum term, `transfer` as the total of a
transfer-in (`transferyears`, 2 for `.ai`), `setup` as an additional one-time fee (e.g. `.de`).

### Notes

- **Renewals are driven by HostBill.** After a registration or completed transfer the module switches the
  API's own auto-renew off, so a domain is never renewed twice. Suspending a domain keeps it off as well.
- **Minimum terms.** The API rejects terms a TLD does not offer (e.g. 1 year for `.ai`) with a clear
  error. Configure the periods of such TLDs accordingly (the pricing import returns `minyears`/`maxyears`).
- **Phone numbers** are converted to the format required by the registries (`+49.301234567`).
  Contacts with an invalid phone number, e-mail or country are rejected before calling the API.
- **Street and house number** are split from the first address line (`Musterstraße 12a`, `12 Main St`);
  the second address line is sent as address extension. The state is passed as entered (the API converts
  it to the ISO 3166-2 code for `.it`).
- **`.it` RegCode.** If the contact data contains a field `pin` (e.g. a client custom field with that
  variable name), it is sent as the nic.it RegCode (ID document number for persons, VAT ID/tax code for
  organisations).
- **ID protection.** Enabling it confirms the WHOIS privacy terms on behalf of the registrant
  (`acceptWppTerms`) – make sure your terms of service cover this.
- **DNS management** requires the managed DNS. Saving DNS records for a domain with custom nameservers
  switches it to `ns1/ns2.domain-reseller-api.de`.

## Development

```bash
docker build -t drapi-test-hostbill .
docker run --rm drapi-test-hostbill vendor/bin/phpunit
```

Pushes to `main` are tested and released automatically; the version is derived from the conventional
commit messages since the last tag (`fix:` → patch, `feat:` → minor, `!`/`BREAKING CHANGE` → major).

## Deutsch

HostBill-Domainmodul für die [Domain Reseller API](https://domain-reseller-api.de) von Wolfscastle:
Registrierung, Transfer, Verlängerung, Kontakte, Nameserver, Glue-Records, Transfersperre,
WHOIS-Privacy, DNS-Verwaltung, Auth-Code, Synchronisierung und Preisimport.

**Installation:** `domain-reseller-api-hostbill-<version>.zip` aus dem
[neuesten GitHub-Release](https://github.com/WolfcastleDE/domain-reseller-api-hostbill/releases/latest)
herunterladen und im HostBill-Hauptverzeichnis entpacken (das Modul landet in
`includes/modules/Domain/DomainResellerApi/`). Danach unter **Settings → Modules → Domain Modules**
**Domain Reseller API** aktivieren und API-URL, API-Key und ggf. den Sandbox-Modus eintragen.

**Wichtig:** Verlängerungen steuert HostBill (`POST /renew` beim Bezahlen der Rechnung); das Auto-Renew der
API schaltet das Modul deshalb ab. TLDs mit Mindestlaufzeit (z. B. `.ai` ab 2 Jahren) entsprechend
konfigurieren. Für `.it` kann der RegCode über ein Kontaktfeld `pin` übergeben werden.

Dokumentation der API: <https://domain-reseller-api.de/dokumentation>

## License

[MIT](LICENSE) © Wolfscastle
