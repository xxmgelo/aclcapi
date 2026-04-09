CREATE DATABASE IF NOT EXISTS aclcapi_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE aclcapi_db;

CREATE TABLE IF NOT EXISTS removed_students (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL DEFAULT "",
  program VARCHAR(150) NOT NULL DEFAULT "",
  year_level VARCHAR(50) NOT NULL DEFAULT "",
  gmail VARCHAR(150) NOT NULL DEFAULT "",
  removed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_removed_student_id (student_id)
);

CREATE TABLE IF NOT EXISTS admins (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(120) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  avatar LONGTEXT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admin_username (username),
  UNIQUE KEY uniq_admin_email (email)
);


CREATE TABLE IF NOT EXISTS bse_students (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  program VARCHAR(150) NOT NULL DEFAULT '',
  year_level VARCHAR(50) NOT NULL DEFAULT '',
  gmail VARCHAR(150) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_bse_student_id (student_id)
);

CREATE TABLE IF NOT EXISTS bsis_students (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  program VARCHAR(150) NOT NULL DEFAULT '',
  year_level VARCHAR(50) NOT NULL DEFAULT '',
  gmail VARCHAR(150) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_bsis_student_id (student_id)
);

-- Update existing admin name if present (change Bebén Magbanua to Ms. Amy Alpay)
-- Run this after importing or against your live database if the admin row already exists.
UPDATE admins SET full_name = 'Ms. Amy Alpay' WHERE full_name LIKE '%BEBEN%' OR full_name LIKE '%Magbanua%';


