# PROJECT AUDIT: Medical Store Management Web Application

**Date:** 2026-11-16  
**Auditor:** Senior Open-Source Maintainer & DevSecOps Engineer  
**Project Path:** `C:\Users\75098\.gemini\antigravity\scratch\medical-store`  
**Target GitHub Repository:** `jagmohan-kurmi/medical-store`  
**Open-Source Target Program:** OpenAI Codex for Open Source  

---

## 1. Executive Summary

The project is a lightweight, high-performance, single-tenant web application designed for retail pharmacies and medical stores in India. It manages Point of Sale (POS) billing, FEFO-based batch and expiry management, Schedule H/H1/X compliance, GST invoice generation (A4 and 80mm thermal), customer credit ledgers, supplier accounts, and multimodal AI-assisted supplier bill digitization (powered by Google Gemini Vision).

The architecture is deliberately zero-dependency on the backend (pure native PHP 8.1+ with PDO MySQL/MariaDB) and zero-build on the frontend (vanilla ES6+ JavaScript, native CSS, modern SVG/HTML5). This makes it exceptionally fast, lightweight, and deployable on virtually any shared or cloud hosting environment (LAMP/LEMP).

---

## 2. Codebase & Directory Structure

```
medical-store/
├── .gitignore                   # Ignores config.php, storage/, dist/
├── .hostinger/
│   └── site.json                # Hostinger deployment metadata (domain, user)
├── README.md                    # Existing informal project documentation
├── Reset Demo Data.bat          # Windows batch script to wipe & re-seed local database
├── Start Demo.bat               # Windows batch script to launch local PHP server + MariaDB
├── Stop Demo.bat                # Windows batch script to terminate local processes
├── app/
│   ├── ai_ocr.php               # Gemini Vision bill OCR, text extraction, fuzzy catalog matching
│   ├── bootstrap.php            # Security headers, CSP, session initialization, config loader
│   ├── demo.php                 # Schema auto-migrator and sample store seeder
│   ├── helpers.php              # Database PDO wrapper, CSRF helpers, auth guards, formatting
│   └── layout.php               # Shared HTML header, sidebar, navigation, flash banners, footer
├── config/
│   ├── config.example.php       # Sanitized environment template
│   ├── config.php               # Local development config (contains dev credentials & API key)
│   └── config.production.php    # Server production config (contains prod credentials & API key)
├── database/
│   ├── migration_v2.sql         # Customer ledger, sales returns, shortage book schema
│   └── schema.sql               # Core relational schema (users, medicines, batches, sales, etc.)
├── public_html/                 # Public web root (only directory accessible by web server)
│   ├── .htaccess                # Apache routing, security restrictions
│   ├── api.php                  # JSON API: customer autocomplete, substitute finder, barcode lookup
│   ├── bill-file.php            # Secure file streamer for scanned supplier bills
│   ├── billing.php              # POS billing interface with FEFO batch selection
│   ├── boot.php                 # Web root entry bootstrap loader
│   ├── customers.php            # Customer directory, credit management, payment collection
│   ├── index.php                # Operational dashboard (metrics, low stock, expiring soon)
│   ├── invoice.php              # Tax invoice view, A4 & 80mm thermal print layouts, UPI QR
│   ├── login.php                # Authentication page (password verification & demo switcher)
│   ├── logout.php               # Session destruction & signout
│   ├── medicines.php            # Drug catalog management, salt name, schedule, rack locations
│   ├── ocr.php                  # Supplier bill upload and OCR processing controller
│   ├── purchases.php            # Inward supplier invoices, batch creation, payment recording
│   ├── reports.php              # Sales, tax split (CGST/SGST), margin, and fast-moving analytics
│   ├── returns.php              # Customer sales returns with stock reversal
│   ├── sales.php                # Sales history, bill reprint, cancellation
│   ├── settings.php             # Store settings (GSTIN, DL, margins, printer format, UPI ID)
│   ├── setup.php                # Initial web installer (token-guarded)
│   ├── shortage.php             # Out-of-stock / customer demand tracking book
│   ├── stock.php                # Current inventory, batch expiry inspection, write-offs
│   ├── supplier-return.php      # Damaged/expired stock return to distributors
│   ├── suppliers.php            # Distributor directory, ledger balance, payment history
│   ├── users.php                # Staff user management (Admin vs. Counter Staff)
│   └── assets/
│       ├── app.css              # Custom responsive stylesheet
│       ├── app.js               # POS hotkeys, client-side calculations, autocomplete, dialogs
│       ├── favicon.svg          # SVG icon
│       └── qrcode.min.js        # Client-side QR generation for dynamic UPI payment
└── scripts/
    ├── build-deploy.ps1         # PowerShell deployment packager
    ├── reset-demo.php           # CLI script to reset demo database
    └── seed-demo.php            # CLI script to seed sample data
```

---

## 3. Technology Stack & Architecture Analysis

| Layer | Technology | Details |
|---|---|---|
| **Runtime** | PHP 8.1+ | Uses `declare(strict_types=1)`, native PDO, OpenSSL, cURL, Fileinfo |
| **Database** | MySQL 8.0+ / MariaDB 10.6+ | Relational schema with foreign keys, index optimization, UTF8mb4 |
| **Web Server** | Apache / LiteSpeed / Nginx | `.htaccess` configured for security headers, blocking private directories |
| **Frontend** | Vanilla ES6+ & CSS3 | No framework runtime, zero node build step, CSS variables, native modals |
| **Integrations** | Google Gemini API (Vision) | Multimodal OCR parsing supplier invoices into structured JSON |
| **Barcode/QR** | `qrcode.min.js` | Generates NPCI Bharat UPI QR codes with bill amounts on invoices |
| **Package Managers**| None (Self-contained) | Zero Composer dependencies, zero NPM dependencies |

---

## 4. Modules & Functionality Assessment

1. **POS Billing Engine:**
   - Search by brand or salt (generic) name.
   - Automatic FEFO (First-Expiry-First-Out) batch allocation.
   - Support for loose units (strips/tablets/ml).
   - Real-time stock depletion and sale recording within database transactions.
   - Schedule H, H1, X regulatory check (enforces doctor's name on prescription medicines).
   - Multi-mode settlements: Cash, UPI (with on-screen/printed dynamic QR), Card, Credit.

2. **Inventory & Expiry Control:**
   - Batch-level tracking (batch number, expiry date, purchase rate, MRP, available quantity).
   - Threshold alerts: Expiring soon (configurable days, default 90), Expired, Low stock.
   - Stock adjustments (damage, breakage, physical count corrections) with admin audit trail.
   - Shortage book logging customer requests for out-of-stock medicines.

3. **Inward Purchases & Distributor Accounts:**
   - Multi-line invoice data entry with automatic margin and GST calculation.
   - Duplicate invoice protection per supplier.
   - Running ledger with debits, credits, and payment tracking.
   - Supplier returns for damaged/expired goods.

4. **AI Bill Digitization (Gemini Vision):**
   - Supports JPEG, PNG, WebP, and PDF invoices up to 10MB.
   - Prompts Gemini Vision to extract invoice header, line items, batch, expiry, rates, and tax.
   - Fuzzy matching algorithms map extracted text to existing catalog medicines and suppliers.
   - Secure server-side storage outside `public_html`.

5. **Customer Credit & Ledger:**
   - Customer profile, credit limits, phone-based lookup.
   - Partial payments, balance tracking, and sale returns.

6. **Reporting & Compliance:**
   - GST tax liability breakdown (0%, 5%, 12%, 18%, 28%).
   - Daily, weekly, monthly sales reports with CSV export.
   - Profitability analysis (Gross margin estimation based on weighted purchase cost).

---

## 5. Security & Privacy Audit Findings (Preliminary)

| Severity | Issue | Location | Remediation Required |
|---|---|---|---|
| **CRITICAL** | Hardcoded API Key (`gemini_api_key`) and database passwords in uncommitted local files | `config/config.php`, `config/config.production.php` | Keep ignored from git; rotate exposed Gemini key; document environment variable configuration (`.env`). |
| **HIGH** | `demo_mode` authentication bypass | `public_html/login.php` | When `demo_mode` is enabled, any visitor can log in as admin with `?demo=admin`. Must default to `false` and require explicit flag. |
| **MEDIUM** | Setup script exposed if token is predictable | `public_html/setup.php` | Must be locked permanently after initial user creation or deleted in production. |
| **MEDIUM** | Private Hostinger configuration files | `.hostinger/site.json`, `scripts/build-deploy.ps1` | Contains personal domain (`med.jagmohankurmi.com`) and username (`u581547057`). Must be excluded from public repository. |
| **LOW** | Missing automated test suite | Whole repository | No CI/CD, no unit tests or linting to prevent regressions during open-source contributions. |
| **LOW** | Missing Open Source License | Root directory | No `LICENSE` file; legal ownership and usage rights undefined. |

---

## 6. Open-Source Suitability Assessment

### What CAN Safely Become Open Source:
- All core application logic in `app/` (`bootstrap.php`, `helpers.php`, `layout.php`, `ai_ocr.php`).
- Public interface files in `public_html/` (PHP controllers, `app.css`, `app.js`).
- Database migration and schema scripts in `database/`.
- Sanitized configuration template `config/config.example.php` (with placeholders).
- Documentation, architecture diagrams, contributing guides, and tests.

### What MUST Remain Private / Excluded:
- `config/config.php` and `config/config.production.php` (live secrets, DB passwords, API keys).
- `.hostinger/` folder (account username and personal hosting metadata).
- `scripts/build-deploy.ps1` (hardcoded deployment script referencing personal domains).
- `storage/bills/*` (uploaded supplier bill documents or customer invoices).
- `dist/` directory (contains bundled deployment artifacts with embedded production config).

---

## 7. Recommended Target Repository Structure

To conform to modern PHP open-source standards and ensure eligibility for **OpenAI Codex for Open Source**:

```
medical-store/
├── .github/
│   ├── ISSUE_TEMPLATE/
│   │   ├── bug_report.md
│   │   └── feature_request.md
│   ├── PULL_REQUEST_TEMPLATE.md
│   └── workflows/
│       ├── ci.yml                 # Linting, syntax check, PHPStan, PHPUnit
│       ├── codeql.yml             # GitHub CodeQL static security analysis
│       └── dependabot.yml         # Dependency security updates
├── .editorconfig                  # Code formatting standard
├── .gitignore                     # Comprehensive Git ignore rules
├── CHANGELOG.md                   # Version release notes
├── CODE_OF_CONDUCT.md             # Contributor Covenant standard
├── CONTRIBUTING.md                # Development & contribution guidelines
├── LICENSE                        # Open-source license (e.g. MIT or Apache-2.0)
├── README.md                      # Comprehensive production-grade documentation
├── SECURITY.md                    # Vulnerability reporting policy
├── PROJECT_AUDIT.md               # Complete architectural discovery report
├── SECURITY_AUDIT.md              # OWASP/CWE static security analysis
├── CODEX_APPLICATION_READINESS.md # OpenAI Codex evaluation & application responses
├── app/                           # Core backend application logic
├── config/
│   └── config.example.php         # Clean configuration template
├── database/                      # SQL schema & migrations
├── docs/                          # Architecture & developer documentation
│   ├── ARCHITECTURE.md
│   ├── DATABASE.md
│   └── API.md
├── public_html/                   # Web root
├── tests/                         # Automated test suite (Unit & Integration)
│   ├── bootstrap.php
│   ├── HelpersTest.php
│   └── SchemaTest.php
└── phpunit.xml                    # PHPUnit configuration
```

---

## 8. Prioritized Implementation Plan

1. **Phase 1: Complete Project Discovery** *(Completed with this audit)*.
2. **Phase 2: Security & Privacy Hardening**:
   - Scrub and prevent leaking of credentials, domain names, and personal usernames.
   - Enforce environment variable support (`getenv()` / `.env`) alongside configuration files.
   - Generate `SECURITY_AUDIT.md`.
3. **Phase 3: Repository Architecture & Open-Source Governance**:
   - Create standard GitHub community files (`CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `LICENSE`, `CHANGELOG.md`).
   - Add `.editorconfig`, issue templates, PR template.
4. **Phase 4: Code Quality & Modernization**:
   - Verify input sanitization and strict typing across all controllers.
   - Retain 100% existing functionality without breakingHostinger compatibility.
5. **Phase 5: Automated Testing Infrastructure**:
   - Add PHPUnit test suite covering critical financial calculations (GST splitting, FEFO logic, discount ceilings, amount-in-words).
   - Set up GitHub Actions CI workflow to run syntax checks and tests on every push.
6. **Phase 6: Professional Technical Documentation**:
   - Rewrite `README.md` with architectural diagrams, feature list, deployment instructions, and verified tech stack.
   - Add `docs/` folder with architectural blueprints.
7. **Phase 7: GitHub Security Setup**:
   - Add CodeQL workflow and Dependabot configuration.
8. **Phase 8: OpenAI Codex for Open Source Readiness**:
   - Complete `CODEX_APPLICATION_READINESS.md` with truthful, non-fabricated responses tailored to program criteria.
9. **Phase 9: Pre-Publication Verification Checklist**.
10. **Phase 10: Remote Publication to GitHub** *(Awaiting explicit user approval)*.

---

## 9. Questions Requiring Your Approval

Before proceeding to Phase 2 and modifying/adding files to the project, please confirm:

1. **Open Source License:** Which license do you prefer?
   - **MIT License** (Recommended: Most popular, permissive, great for OpenAI Codex eligibility).
   - **Apache 2.0** (Permissive with explicit patent grant).
   - **GNU AGPLv3** (Strong copyleft: requires anyone modifying the web app to publish their source code).

2. **Personal Deployment Artifacts:**
   - Can we exclude `.hostinger/site.json` and `scripts/build-deploy.ps1` from the public Git repository to protect your personal domain (`med.jagmohankurmi.com`) and hosting account username?

3. **Gemini API Key:**
   - The local `config.php` has a Gemini API key. We will keep `config.php` gitignored and only provide `config.example.php` with placeholder `YOUR_GEMINI_API_KEY_HERE`. Do you agree?

4. **Target Repository Name:**
   - Confirm target repo name: `medical-store` on `https://github.com/jagmohan-kurmi`.

---
*End of Phase 1 Discovery Report. Awaiting user confirmation to proceed to Phase 2.*
