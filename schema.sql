CREATE TABLE IF NOT EXISTS uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ocr_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    upload_id INT NOT NULL,
    json_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (upload_id) REFERENCES uploads(id)
);

CREATE TABLE IF NOT EXISTS generated_sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    upload_id INT NOT NULL,
    html_code MEDIUMTEXT,
    public_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (upload_id) REFERENCES uploads(id)
);

CREATE TABLE IF NOT EXISTS ocr_edits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    upload_id INT NOT NULL,
    edited_text TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (upload_id) REFERENCES uploads(id)
);

CREATE TABLE IF NOT EXISTS naics_classifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    upload_id INT NOT NULL,
    naics_code VARCHAR(10),
    title VARCHAR(255),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (upload_id) REFERENCES uploads(id)
);

CREATE TABLE IF NOT EXISTS domain_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    upload_id INT NOT NULL,
    suggestion VARCHAR(255) NOT NULL,
    availability TINYINT(1) DEFAULT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (upload_id) REFERENCES uploads(id)
);

CREATE TABLE IF NOT EXISTS domain_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    registrar_id VARCHAR(255),
    purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INT DEFAULT NULL,
    registrant_first_name VARCHAR(100),
    registrant_last_name VARCHAR(100),
    registrant_address1 VARCHAR(255),
    registrant_city VARCHAR(100),
    registrant_state VARCHAR(100),
    registrant_postal_code VARCHAR(20),
    registrant_country VARCHAR(2),
    registrant_phone VARCHAR(50),
    registrant_email VARCHAR(255),
    domain_id VARCHAR(255),
    order_id VARCHAR(255),
    transaction_id VARCHAR(255),
    charged_amount DECIMAL(10,4)
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    is_admin TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS billing_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    stripe_customer_id VARCHAR(255) NOT NULL,
    stripe_subscription_id VARCHAR(255) NOT NULL,
    plan_type VARCHAR(50),
    status VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS website_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    upload_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (upload_id) REFERENCES uploads(id)
);

