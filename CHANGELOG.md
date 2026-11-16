# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-16

### Added
- **POS Billing Engine:** Real-time billing with brand/generic medicine search, automatic First-Expiry-First-Out (FEFO) batch allocation, and loose-unit selling.
- **Regulatory Controls:** Schedule H, H1, and X prescription enforcement requiring doctor's name on invoices.
- **GST Invoicing:** Dual layout support for A4 tax invoices and 80mm thermal receipt roll printers with CGST/SGST itemized breakdown and legal amount-in-words converter.
- **Dynamic UPI Payment:** Integrated dynamic Bharat UPI QR code generation on invoices for instant customer settlement.
- **Inventory & Expiry Control:** Batch tracking, configurable expiration alerts (90 days default), low-stock warnings, shortage book for customer demand, and stock write-offs with audit logs.
- **Multimodal AI OCR:** Google Gemini Vision integration to scan and parse supplier invoices (PDF/Image) into purchase line items with fuzzy catalog matching.
- **Ledger Management:** Customer credit balance tracking with partial payments, sales returns, and distributor account ledgers.
- **Automated Testing Suite:** Native unit tests verifying calculations, number-to-words currency formatting, and date formatting.
- **CI/CD Workflows:** GitHub Actions workflows for continuous integration testing and CodeQL static security analysis.
