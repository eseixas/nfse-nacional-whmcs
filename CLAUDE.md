# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WHMCS addon for issuing Brazilian NFS-e (Nota Fiscal de Serviço Eletrônica) using the Padrão Nacional (SefinNacional SPED v1.00) REST API with mTLS authentication via A1 ICP-Brasil digital certificates.

There is no build step, linter, or test suite — this is a PHP addon deployed directly into a WHMCS installation.

## Deployment

Copy two folders into the WHMCS root:
- `modules/addons/nfse_nacional/` — the addon itself
- `includes/hooks/nfse_nacional_hooks.php` — WHMCS hooks (auto-loaded by WHMCS)

Then activate via WHMCS Admin → Settings → Addon Modules → NFSE Nacional.

## Architecture

### Entry Points

- **`modules/addons/nfse_nacional/nfse_nacional.php`** — WHMCS addon entry point. Defines `nfse_nacional_config()`, `nfse_nacional_activate()`, `nfse_nacional_output()`, and `nfse_nacional_sidebar()`. The `_output()` function also runs DB migrations on every request and routes to `NfseController`.
- **`includes/hooks/nfse_nacional_hooks.php`** — Registers three WHMCS hooks: `InvoiceCreation`, `InvoicePaid`, and `AdminAreaPage` (for the invoice widget).

### Core Library (`modules/addons/nfse_nacional/lib/`)

| File | Responsibility |
|------|----------------|
| `NfseController.php` | Admin UI: dashboard, exportar, log, ver_nfse, download_xml, produtos, upload_cert, diagnostico |
| `NfseService.php` | Orchestrates emission flow: fetches invoice from WHMCS, builds XML, signs, calls API, persists result |
| `NfseXmlBuilder.php` | Generates the DPS XML document (SPED v1.00 schema) |
| `NfseSigner.php` | RSA-SHA256 + C14N Exclusive WithComments signing |
| `NfseApiClient.php` | REST client for SefinNacional API (mTLS via cURL) |
| `CertManager.php` | Manages cert.pfx storage/retrieval from `certs/` directory |
| `NfseDiagnostico.php` | Tests DNS, TCP:443, and HTTPS reachability to API endpoints |

### Data Flow

1. Hook fires (InvoicePaid/InvoiceCreation) or admin clicks "Emitir" → `NfseService::emitirParaFatura($invoiceId)`
2. `NfseXmlBuilder` creates DPS XML from WHMCS invoice + product config (`mod_nfse_nacional_produtos`)
3. `NfseSigner` signs the XML
4. `NfseApiClient` POSTs to `sefin[.producaorestrita].nfse.gov.br/SefinNacional/` with mTLS
5. Result (NFS-e number, verification code, XML) saved to `mod_nfse_nacional`

### Database Tables

- `mod_nfse_nacional` — one row per invoice; tracks status, XML sent/received, `n_dps` (DPS number), `n_dfse`, `codigo_verificacao`
- `mod_nfse_nacional_produtos` — per-product service code overrides (LC 116, municipal/national codes, NBS)
- `mod_nfse_nacional_log` — operation log (info/success/error/warning)

Migrations run automatically in `nfse_nacional_output()` on every admin page load, so no manual migration step is needed when deploying updates.

### Key Behaviors

- **Emission modes** (config `emissao_automatica`): `manual`, `invoice` (on creation), `paid` (on payment). WHMCS dropdown returns `"key=label"` format — always use `nfse_nacional_normalizar_modo()` to extract the key.
- **Duplicate prevention**: Before emitting, checks for existing `emitida` or `pendente` status for the same `invoice_id`.
- **Debug mode**: When `debug_ativo` is set, writes `debug_*.xml/txt` files in the addon directory. Must be disabled in production.
- **Certificate storage**: `certs/cert.pfx` is gitignored. The `certs/` directory is protected with `.htaccess` (`Deny from all`).
- **API environments**: `producao_restrita` → `sefin.producaorestrita.nfse.gov.br`, `producao` → `sefin.nfse.gov.br`.
- **Known API bug**: NFS-e cancellation via `POST /nfse/{chave}/eventos` returns HTTP 500 from Receita Federal in some scenarios — manual cancellation via Emissor Nacional web portal is required.

### Widget (AdminAreaPage hook)

The invoice-page widget is injected via jQuery using a multi-strategy DOM insertion approach to support WHMCS 7, 8, and 9 layouts. It uses a CSRF token stored in `$_SESSION['nfse_nacional_csrf']`.
