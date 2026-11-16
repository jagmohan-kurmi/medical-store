# Medical Store Management System

[![CI](https://github.com/jagmohan-kurmi/medical-store/actions/workflows/ci.yml/badge.svg)](https://github.com/jagmohan-kurmi/medical-store/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL / MariaDB](https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)

A lightweight, secure, and production-ready open-source pharmacy and medical store management web application built for independent retail chemists and pharmacies.

---

## 1. Problem Statement

Retail pharmacies in emerging markets face several operational and regulatory hurdles:
- **Stringent Regulatory Compliance:** Legal mandates to record prescribing doctors for restricted medicines (Schedule H, H1, X).
- **Expiry Losses:** Substantial financial losses due to expired medicines without automated First-Expiry-First-Out (FEFO) dispensing.
- **Manual Invoicing Overhead:** Tedious manual entry of high-density distributor paper bills containing dozens of batches and expiry dates.
- **Bloated Software Solutions:** Most commercial software suites are closed-source, require costly subscriptions, impose heavy hardware requirements, or lock business data into proprietary formats.

**Medical Store Management** solves this by providing a self-hostable, zero-dependency, ultra-fast web application running on standard shared hosting or local offline servers.

---

## 2. Key Features

- **High-Speed POS Billing:** Fast keyboard-driven sales flow (`F2` search, `F9` save). Search by brand or active salt name, automatic FEFO batch allocation, and support for loose-unit sales.
- **Schedule H / H1 / X Enforcement:** Built-in drug regulatory compliance requiring the prescribing doctor's name prior to completing sales of controlled substances.
- **Dual Invoice Layouts with Dynamic UPI:**
  - Standard GST A4 invoice layout.
  - Compact 80mm thermal receipt roll layout.
  - Automatic dynamic Bharat UPI QR code generation embedding the exact invoice amount for contactless settlement.
- **Multimodal AI Bill Digitization (Google Gemini Vision):** Upload phone photos or PDFs of distributor invoices; AI automatically extracts supplier info, invoice numbers, batches, expiration dates, rates, and taxes, with fuzzy catalog matching.
- **Inventory & Expiry Control:** Batch tracking, configurable expiration threshold alerts (default 90 days), shortage demand register, and logged stock write-offs.
- **Customer & Supplier Ledgers:** Complete credit balance management, partial payment recording, and distributor purchase ledger.
- **GST Tax Reports:** Full breakdown of CGST and SGST liabilities across 0%, 5%, 12%, 18%, and 28% slabs with CSV data export.

---

## 3. Technology Stack

- **Backend:** Pure PHP 8.1+ (Strict types enabled, zero third-party framework overhead).
- **Database:** MySQL 8.0+ / MariaDB 10.6+ (Relational schema with foreign keys and transactions).
- **Frontend:** Vanilla JavaScript (ES6+) and modern responsive CSS3 (No Node.js build step needed).
- **AI Integration:** Google Gemini 1.5 Flash / Pro via REST API for multimodal invoice OCR.
- **QR Code Engine:** `qrcode.min.js` (MIT license, client-side generation).

---

## 4. Architecture Overview

```
medical-store/
├── app/               # Core business logic, OCR, security helpers (Protected)
├── config/            # Environment configurations (Outside web root)
├── database/          # Relational SQL schemas and migrations
├── docs/              # Architectural diagrams and database specs
├── public_html/       # Public web document root (Front controllers & static assets)
│   ├── assets/        # Stylesheets, scripts, icons
│   └── *.php          # Endpoint controllers
├── storage/           # Uploaded supplier invoices (Non-executable, protected)
└── tests/             # Automated test suite
```

Detailed technical specifications are available in:
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- [docs/DATABASE.md](docs/DATABASE.md)

---

## 5. Getting Started & Installation

### Prerequisites
- PHP 8.1 or higher with `pdo_mysql`, `openssl`, `mbstring`, and `fileinfo` extensions.
- MySQL 8.0+ or MariaDB 10.6+.
- Web server (Apache, LiteSpeed, or Nginx).

### Local Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/jagmohan-kurmi/medical-store.git
   cd medical-store
   ```

2. **Configure Environment:**
   Copy the sample configuration file:
   ```bash
   cp config/config.example.php config/config.php
   ```
   Edit `config/config.php` and supply your database credentials and a 64-character hex application key:
   ```bash
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

3. **Import Database Schema:**
   ```bash
   mysql -u your_user -p your_database < database/schema.sql
   mysql -u your_user -p your_database < database/migration_v2.sql
   ```

4. **Start Web Server:**
   ```bash
   php -S 127.0.0.1:8095 -t public_html
   ```
   Open `http://127.0.0.1:8095` in your browser.

5. **Initial Store Setup:**
   If `setup_token` was configured, visit `http://127.0.0.1:8095/setup.php?token=YOUR_TOKEN` to create the initial Administrator account.

---

## 6. Running Automated Tests

Run the built-in test suite to verify financial calculations, amount-to-words currency formatting, date helpers, and tax logic:

```bash
php tests/run_tests.php
```

To run syntax linting across all PHP files:
```bash
find app public_html scripts tests -name "*.php" -exec php -l {} +
```

---

## 7. Security Principles

- **Prepared Statements Everywhere:** All database interactions use PDO prepared statements to eliminate SQL injection vulnerabilities.
- **Content Security Policy (CSP):** Strict security headers (`default-src 'self'`) prevent malicious script injection.
- **Secure File Ingestion:** Uploaded bills are hashed with 32-character hexadecimal identifiers, stored outside the web document root (`storage/bills/`), and restricted by `.htaccess`.
- **Brute-Force Protection:** Login rate-limiting tracks hashed IP addresses and locks out repeated failed attempts.

Detailed security audit results are available in [SECURITY_AUDIT.md](SECURITY_AUDIT.md).

---

## 8. Development Roadmap

- [x] POS Billing with FEFO batching and loose units.
- [x] Schedule H/H1/X compliance checks.
- [x] Multimodal AI supplier invoice OCR (Gemini Vision).
- [x] Dynamic UPI QR code on invoices.
- [x] Customer credit ledger and distributor payments.
- [ ] Barcode label generator for retail medicine racks.
- [ ] Offline-first IndexedDB cache with background sync.
- [ ] WhatsApp invoice sharing integration via Webhooks.

---

## 9. Contributing

Contributions are welcomed! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

---

## 10. License & Maintainer

Distributed under the **MIT License**. See [LICENSE](LICENSE) for more information.

- **Author & Maintainer:** Jagmohan Kurmi ([@jagmohan-kurmi](https://github.com/jagmohan-kurmi))
- **Email:** 7509879289.jp@gmail.com
