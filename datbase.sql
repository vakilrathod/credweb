-- Create Database
CREATE DATABASE IF NOT EXISTS loan_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE loan_db;

-- Create Leads Table
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id VARCHAR(100) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    mobile_number VARCHAR(10) NOT NULL,
    email VARCHAR(255) NOT NULL,
    pan VARCHAR(10) NOT NULL,
    dob DATE NOT NULL,
    pincode VARCHAR(6) NOT NULL,
    monthly_income INT NOT NULL,
    credit_score_class TINYINT NULL,
    employment_status TINYINT NOT NULL COMMENT '1=Salaried, 2=Self Employed',
    employer_name VARCHAR(255) NULL,
    office_pincode VARCHAR(6) NULL,
    business_registration_type TINYINT NULL COMMENT '1-8',
    residence_type TINYINT NULL COMMENT '1=Rented, 2=Owned',
    business_turnover TINYINT NULL COMMENT '1-4',
    business_years TINYINT NULL COMMENT '1-3',
    business_account TINYINT NULL COMMENT '1=Yes, 2=No',
    consumer_consent_ip VARCHAR(45) NOT NULL,
    api_response TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lead_id (lead_id),
    INDEX idx_mobile (mobile_number),
    INDEX idx_email (email),
    INDEX idx_pan (pan),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create Offers Table (optional - to store offers received)
CREATE TABLE IF NOT EXISTS offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id VARCHAR(100) NOT NULL,
    lender_id INT NOT NULL,
    lender_name VARCHAR(255) NOT NULL,
    lender_logo VARCHAR(500) NULL,
    offer_amount_up_to DECIMAL(12,2) NULL,
    offer_tenure VARCHAR(100) NULL,
    offer_interest_rate VARCHAR(100) NULL,
    offer_processing_fees VARCHAR(255) NULL,
    status VARCHAR(100) NULL,
    offer_link TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lead_id (lead_id),
    INDEX idx_lender_id (lender_id),
    FOREIGN KEY (lead_id) REFERENCES leads(lead_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;