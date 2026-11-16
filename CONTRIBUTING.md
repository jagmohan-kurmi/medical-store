# Contributing to Medical Store Management

Thank you for your interest in contributing! This project aims to provide a lightweight, secure, and reliable pharmacy management system for independent retail medical stores.

## Code Principles

1. **Zero Runtime Dependencies:** We deliberately do not use heavy PHP frameworks or build-heavy frontend libraries. Code should remain lightweight, accessible, and run directly on modern PHP 8.1+ and MySQL/MariaDB.
2. **Defensive Programming:**
   - Always declare strict types: `declare(strict_types=1);`
   - Use parameterized queries (`q()`) for all database operations.
   - Escape all HTML output with `e()`.
   - Validate and verify CSRF tokens for state-modifying POST requests.
3. **Regulatory Accuracy:** Changes to tax (GST slabs), prescription medicine schedules (Schedule H, H1, X), and batch expiration rules must adhere to Indian pharmaceutical retail guidelines.

## Development Setup

1. Fork the repository and clone your fork:
   ```bash
   git clone https://github.com/your-username/medical-store.git
   cd medical-store
   ```
2. Copy the sample configuration:
   ```bash
   cp config/config.example.php config/config.php
   ```
3. Set up a local MySQL/MariaDB database and import:
   ```bash
   mysql -u root -p -e "CREATE DATABASE medstore_dev CHARACTER SET utf8mb4;"
   mysql -u root -p medstore_dev < database/schema.sql
   mysql -u root -p medstore_dev < database/migration_v2.sql
   ```
4. Start PHP's built-in web server:
   ```bash
   php -S 127.0.0.1:8095 -t public_html
   ```

## Pull Request Guidelines

- Branch names should be descriptive (e.g., `fix/expiry-alert-calc` or `feat/thermal-receipt-customization`).
- Ensure all automated unit tests pass prior to submitting a pull request.
- Keep commits concise, atomic, and well-described.
