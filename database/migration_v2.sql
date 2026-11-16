-- Migration v2: Customer Ledger, Sales Returns, Shortage Book, Supplier Returns, Barcodes, Settings

-- 1. Customers Table (Udhaar / Credit Management)
CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NOT NULL UNIQUE,
  email VARCHAR(120) NOT NULL DEFAULT '',
  address VARCHAR(255) NOT NULL DEFAULT '',
  doctor_name VARCHAR(100) NOT NULL DEFAULT '',
  credit_limit DECIMAL(10,2) NOT NULL DEFAULT 5000.00,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cust_phone (phone),
  INDEX idx_cust_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Customer Payments Table
CREATE TABLE IF NOT EXISTS customer_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  sale_id INT UNSIGNED NULL,
  pay_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  mode ENUM('Cash','UPI','Card','Bank','Adjustment') NOT NULL DEFAULT 'Cash',
  reference VARCHAR(60) NOT NULL DEFAULT '',
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cp_cust (customer_id, pay_date),
  CONSTRAINT fk_cp_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
  CONSTRAINT fk_cp_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Sales table addition: customer_id
SET @dbname = DATABASE();
SET @tablename = "sales";
SET @columnname = "customer_id";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE sales ADD COLUMN customer_id INT UNSIGNED NULL AFTER bill_no, ADD CONSTRAINT fk_sale_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 4. Sale Returns (Partial & Full Returns with Credit Note)
CREATE TABLE IF NOT EXISTS sale_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_no VARCHAR(20) NULL UNIQUE,
  sale_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  return_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  total_refund DECIMAL(12,2) NOT NULL,
  refund_mode ENUM('Cash','UPI','Credit Adjustment') NOT NULL DEFAULT 'Cash',
  reason VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ret_sale (sale_id),
  CONSTRAINT fk_ret_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_ret_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_ret_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Sale Return Items
CREATE TABLE IF NOT EXISTS sale_return_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_return_id INT UNSIGNED NOT NULL,
  sale_item_id INT UNSIGNED NOT NULL,
  medicine_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NOT NULL,
  qty INT UNSIGNED NOT NULL,
  unit_price DECIMAL(10,4) NOT NULL,
  refund_amount DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_sri_return FOREIGN KEY (sale_return_id) REFERENCES sale_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sri_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_sri_med FOREIGN KEY (medicine_id) REFERENCES medicines(id),
  CONSTRAINT fk_sri_batch FOREIGN KEY (batch_id) REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Shortage Book (Want Book / Demand register)
CREATE TABLE IF NOT EXISTS shortage_book (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  medicine_name VARCHAR(150) NOT NULL,
  generic_name VARCHAR(200) NOT NULL DEFAULT '',
  qty_requested INT UNSIGNED NOT NULL DEFAULT 1,
  customer_name VARCHAR(100) NOT NULL DEFAULT '',
  customer_phone VARCHAR(20) NOT NULL DEFAULT '',
  status ENUM('pending','ordered','arrived','cancelled') NOT NULL DEFAULT 'pending',
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sb_status (status),
  CONSTRAINT fk_sb_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Supplier Returns (Debit Notes for expired/damaged medicine returns)
CREATE TABLE IF NOT EXISTS supplier_returns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_no VARCHAR(20) NULL UNIQUE,
  supplier_id INT UNSIGNED NOT NULL,
  return_date DATE NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(255) NOT NULL DEFAULT 'Expired stock return to distributor',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sret_sup (supplier_id),
  CONSTRAINT fk_sret_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  CONSTRAINT fk_sret_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Supplier Return Items
CREATE TABLE IF NOT EXISTS supplier_return_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_return_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NOT NULL,
  qty INT UNSIGNED NOT NULL,
  rate_per_unit DECIMAL(10,4) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_srit_return FOREIGN KEY (supplier_return_id) REFERENCES supplier_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_srit_batch FOREIGN KEY (batch_id) REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Barcode column in medicines table
SET @col_barcode = "barcode";
SET @prep_barcode = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = "medicines"
      AND COLUMN_NAME = @col_barcode
  ) > 0,
  "SELECT 1",
  "ALTER TABLE medicines ADD COLUMN barcode VARCHAR(50) NOT NULL DEFAULT '' AFTER hsn, ADD INDEX idx_barcode (barcode)"
));
PREPARE alterBarcode FROM @prep_barcode;
EXECUTE alterBarcode;
DEALLOCATE PREPARE alterBarcode;
