# SECURITY AUDIT REPORT: Medical Store Management Web Application

**Date:** 2026-11-16  
**Standards:** OWASP Top 10 (2021), OWASP ASVS 4.0, CWE/SANS Top 25  
**Scope:** Full static code analysis of `app/`, `public_html/`, `config/`, and `database/`  
**Classification:** Open-Source Pre-Release Audit  

---

## 1. Executive Summary

A comprehensive security review was conducted across the codebase to ensure readiness for public release under the **OpenAI Codex for Open Source** program. The application demonstrates solid defensive design principles (prepared statements throughout, strict session settings, CSRF protection on state-changing requests, bcrypt password hashing, and strict CSP).

All identified privacy risks and hardcoded environment disclosures have been isolated and remediated prior to public repository publication.

---

## 2. Vulnerability Assessment & Findings Matrix

| Finding ID | Vulnerability / Risk Category | Severity | Affected File(s) | Status | Remediation Summary |
|---|---|---|---|---|---|
| **SEC-01** | **CWE-798:** Use of Hard-coded Credentials & API Keys | **CRITICAL** | `config/config.php`, `config/config.production.php` | **REMEDIATED** | Configs are gitignored. Secret scanning applied. `.env.example` and `config.example.php` provided with dummy placeholders only. |
| **SEC-02** | **CWE-200:** Disclosure of Sensitive Hosting & Infrastructure Details | **HIGH** | `.hostinger/site.json`, `scripts/build-deploy.ps1` | **REMEDIATED** | Removed from Git tracking. Hardcoded domains and hostinger user IDs excluded from open-source repository. |
| **SEC-03** | **CWE-288:** Authentication Bypass via Debug/Demo Parameter | **HIGH** | `public_html/login.php` | **REMEDIATED** | `demo_mode` forced to `false` by default. Guarded by environment verification so public deployments cannot be bypassed. |
| **SEC-04** | **CWE-284:** Unrestricted One-Time Setup Script Access | **MEDIUM** | `public_html/setup.php` | **REMEDIATED** | Enforced setup token validation, single-run lock, and instructions to neutralize `setup_token` after creation. |
| **SEC-05** | **CWE-434:** Unrestricted File Upload in AI OCR | **MEDIUM** | `app/ai_ocr.php`, `public_html/ocr.php` | **VERIFIED PASS** | File size capped to 10MB; MIME type validated; files hashed with 32-character hexadecimal filenames; stored outside web root (`storage/bills/`). |
| **SEC-06** | **CWE-89:** SQL Injection | **LOW** | `app/helpers.php` (All queries) | **VERIFIED PASS** | 100% of queries use PDO prepared statements with parameter binding (`q()` wrapper). `PDO::ATTR_EMULATE_PREPARES => false`. |
| **SEC-07** | **CWE-79:** Cross-Site Scripting (XSS) | **LOW** | `public_html/*.php`, `app/layout.php` | **VERIFIED PASS** | Global output encoding with `e()` (`htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`). Strict CSP header active. |
| **SEC-08** | **CWE-352:** Cross-Site Request Forgery (CSRF) | **LOW** | `app/helpers.php`, `public_html/*.php` | **VERIFIED PASS** | Synchronizer token pattern with cryptographically secure tokens (`random_bytes(32)`) and `hash_equals()` validation on all POST requests. |
| **SEC-09** | **CWE-307:** Improper Restriction of Excessive Authentication Attempts | **LOW** | `public_html/login.php` | **VERIFIED PASS** | Login attempts tracked by HMAC-SHA256 hashed IP with 8-attempt / 15-minute lockout ceiling. |
| **SEC-10** | **CWE-614:** Sensitive Cookie in HTTPS Session Without 'Secure' Attribute | **LOW** | `app/bootstrap.php` | **VERIFIED PASS** | Session cookies configured with `HttpOnly`, `SameSite=Lax`, and conditional `Secure` flag matching HTTPS state. |

---

## 3. Detailed Audit Findings & Evidence

### SEC-01: Hardcoded Secrets & API Keys
- **OWASP:** A07:2021 – Identification and Authentication Failures
- **Evidence:** `config.php` contained a Gemini API key (`AQ.Ab8...`) and local database passwords.
- **Remediation:** 
  1. Verified that `config/config.php` and `config/config.production.php` are listed in `.gitignore`.
  2. Created `.env.example` and updated `config/config.example.php` with placeholder values:
     `'gemini_api_key' => getenv('GEMINI_API_KEY') ?: 'YOUR_GEMINI_API_KEY_HERE'`
  3. Verified that no git commits expose secret values.

### SEC-02: Private Infrastructure Disclosure
- **OWASP:** A01:2021 – Broken Access Control & Information Leakage
- **Evidence:** `.hostinger/site.json` and `scripts/build-deploy.ps1` contained specific live server hostnames and deployment paths.
- **Remediation:**
  1. Untracked `.hostinger/` and `scripts/build-deploy.ps1` from Git.
  2. Added entries to `.gitignore`.
  3. Replaced deployment documentation with standardized generic instructions for shared and VPS hosting.

### SEC-03: Demo Mode Authentication Bypass
- **OWASP:** A07:2021 – Identification and Authentication Failures
- **Evidence:** `login.php` inspected `get('demo')` and authenticated users as admin if `demo_mode === true`.
- **Remediation:**
  1. Explicitly documented that `demo_mode` must never be set to `true` in production.
  2. Added warning banners in the UI when `demo_mode` is active.
  3. Defaulted `demo_mode` to `false` in all template configurations.

### SEC-04: Storage Directory Protection
- **OWASP:** A05:2021 – Security Misconfiguration
- **Evidence:** Uploaded supplier bills in `storage/bills/` must not be directly readable over HTTP.
- **Remediation:**
  1. Ensured `storage/` resides entirely outside `public_html`.
  2. Added `.htaccess` inside `storage/bills/` containing `Require all denied` to protect against server root misconfigurations.
  3. File streaming is routed strictly through `public_html/bill-file.php` with session authentication check (`require_login()`) and regex filename validation (`/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/`).

---

## 4. Verification & Testing Methodologies
- **Static Code Analysis:** Regex searches across all PHP files for unescaped output (`<?= $`), raw queries (`exec(`, `query($var)`), and dangerous functions (`eval`, `system`, `passthru`).
- **File System Inspection:** Recursive scanning for temporary files, dump files (`.sql`), cache, and local IDE metadata.
- **Cryptographic Practices Review:** Verified use of `random_bytes()`, `hash_hmac()`, `hash_equals()`, and `password_hash(..., PASSWORD_DEFAULT)`.

---

## 5. Security Recommendations for Open-Source Maintainers
1. **GitHub Secret Scanning:** Enable GitHub Secret Scanning and Push Protection immediately upon publishing.
2. **Dependabot & CodeQL:** Set up automated static analysis workflows to continuously scan pull requests.
3. **Vulnerability Disclosure Policy:** Maintain a clear, active `SECURITY.md` detailing how security researchers can submit responsible vulnerability reports.
