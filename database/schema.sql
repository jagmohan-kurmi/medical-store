-- Medical Store Management - MySQL / MariaDB schema
-- Stock is tracked in UNITS (single tablet / bottle / tube). A medicine's pack_size says
-- how many units make one pack (strip). Purchase rate and MRP are stored PER PACK.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash CHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(50) PRIMARY KEY,
  svalue TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(100) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(120) NOT NULL DEFAULT '',
  gstin VARCHAR(20) NOT NULL DEFAULT '',
  dl_no VARCHAR(60) NOT NULL DEFAULT '',
  address VARCHAR(255) NOT NULL DEFAULT '',
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS medicines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  generic_name VARCHAR(200) NOT NULL DEFAULT '',
  manufacturer VARCHAR(150) NOT NULL DEFAULT '',
  category VARCHAR(40) NOT NULL DEFAULT 'Tablet',
  schedule ENUM('OTC','H','H1','X') NOT NULL DEFAULT 'OTC',
  hsn VARCHAR(10) NOT NULL DEFAULT '3004',
  barcode VARCHAR(50) NOT NULL DEFAULT '',
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 12.00,
  pack_size INT UNSIGNED NOT NULL DEFAULT 10,
  unit_label VARCHAR(20) NOT NULL DEFAULT 'Tab',
  rack VARCHAR(30) NOT NULL DEFAULT '',
  reorder_level INT UNSIGNED NOT NULL DEFAULT 20,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name),
  INDEX idx_generic (generic_name),
  INDEX idx_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NULL,
  invoice_no VARCHAR(50) NOT NULL DEFAULT '',
  invoice_date DATE NOT NULL,
  taxable DECIMAL(12,2) NOT NULL DEFAULT 0,
  gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  round_off DECIMAL(8,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  scanned_total DECIMAL(12,2) NULL,
  bill_file VARCHAR(100) NOT NULL DEFAULT '',
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_purchase_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchase_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  medicine_id INT UNSIGNED NOT NULL,
  purchase_id INT UNSIGNED NULL,
  batch_no VARCHAR(40) NOT NULL,
  expiry_date DATE NOT NULL,
  mrp DECIMAL(10,2) NOT NULL,
  purchase_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
  disc_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  cost_rate DECIMAL(10,4) NOT NULL DEFAULT 0,
  packs INT UNSIGNED NOT NULL DEFAULT 0,
  free_packs INT UNSIGNED NOT NULL DEFAULT 0,
  qty_in INT UNSIGNED NOT NULL,
  qty_available INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_med_exp (medicine_id, expiry_date),
  INDEX idx_expiry (expiry_date),
  CONSTRAINT fk_batch_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id),
  CONSTRAINT fk_batch_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  pay_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  mode ENUM('Cash','UPI','Bank','Cheque','Adjustment') NOT NULL DEFAULT 'Cash',
  reference VARCHAR(60) NOT NULL DEFAULT '',
  purchase_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sup_date (supplier_id, pay_date),
  CONSTRAINT fk_pay_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_pay_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_adjustments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id INT UNSIGNED NOT NULL,
  qty_change INT NOT NULL,
  reason VARCHAR(100) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_adj_batch FOREIGN KEY (batch_id) REFERENCES batches(id),
  CONSTRAINT fk_adj_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_no VARCHAR(20) NULL UNIQUE,
  customer_id INT UNSIGNED NULL,
  bill_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  customer_name VARCHAR(100) NOT NULL DEFAULT '',
  customer_phone VARCHAR(20) NOT NULL DEFAULT '',
  doctor_name VARCHAR(100) NOT NULL DEFAULT '',
  gross DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  taxable DECIMAL(12,2) NOT NULL DEFAULT 0,
  gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  round_off DECIMAL(6,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_mode ENUM('Cash','UPI','Card','Credit') NOT NULL DEFAULT 'Cash',
  status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  INDEX idx_date (bill_date),
  INDEX idx_phone (customer_phone),
  CONSTRAINT fk_sale_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_sale_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id INT UNSIGNED NOT NULL,
  medicine_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NOT NULL,
  qty INT UNSIGNED NOT NULL,
  unit_price DECIMAL(10,4) NOT NULL,
  unit_cost DECIMAL(10,4) NOT NULL DEFAULT 0,
  gross DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  gst_rate DECIMAL(5,2) NOT NULL,
  taxable DECIMAL(12,2) NOT NULL,
  gst_amount DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_item_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id),
  CONSTRAINT fk_item_batch FOREIGN KEY (batch_id) REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
