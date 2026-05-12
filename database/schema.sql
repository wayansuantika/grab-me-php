SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_admins_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer','therapist','admin') NOT NULL DEFAULT 'customer',
    phone VARCHAR(40) NULL,
    status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS therapists (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    bio TEXT NULL,
    specialty VARCHAR(255) NULL,
    experience_years TINYINT UNSIGNED NOT NULL DEFAULT 0,
    rating DECIMAL(3,2) NOT NULL DEFAULT 5.00,
    photo_url VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_therapists_user (user_id),
    CONSTRAINT fk_therapists_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_service_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    image_url VARCHAR(255) NULL,
    duration_minutes INT NOT NULL DEFAULT 60,
    price DECIMAL(12,2) NOT NULL,
    is_addon TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_services_category (category_id),
    CONSTRAINT fk_services_category FOREIGN KEY (category_id) REFERENCES service_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coverage_areas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    coverage_group ENUM('A','B') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_coverage_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS therapist_services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    therapist_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_therapist_service (therapist_id, service_id),
    CONSTRAINT fk_ts_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id) ON DELETE CASCADE,
    CONSTRAINT fk_ts_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS therapist_coverage_areas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    therapist_id BIGINT UNSIGNED NOT NULL,
    area_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_therapist_area (therapist_id, area_id),
    CONSTRAINT fk_tca_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id) ON DELETE CASCADE,
    CONSTRAINT fk_tca_area FOREIGN KEY (area_id) REFERENCES coverage_areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS therapist_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    therapist_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '0=Monday ... 6=Sunday',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_schedule_therapist_day (therapist_id, day_of_week),
    CONSTRAINT fk_schedule_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_code VARCHAR(30) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    therapist_id BIGINT UNSIGNED NOT NULL,
    area_id BIGINT UNSIGNED NOT NULL,
    booking_date DATE NOT NULL,
    booking_time TIME NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(50) NOT NULL,
    customer_address VARCHAR(255) NULL,
    notes TEXT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    booking_status ENUM('pending_payment','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending_payment',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_booking_code (booking_code),
    KEY idx_booking_user (user_id),
    KEY idx_booking_therapist (therapist_id),
    KEY idx_booking_date_time (booking_date, booking_time),
    CONSTRAINT fk_booking_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_booking_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id),
    CONSTRAINT fk_booking_area FOREIGN KEY (area_id) REFERENCES coverage_areas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    item_type ENUM('service','addon') NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    qty INT NOT NULL DEFAULT 1,
    total_price DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_booking_items_booking (booking_id),
    CONSTRAINT fk_booking_items_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_items_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    provider_payment_id VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'idr',
    status ENUM('pending','succeeded','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
    raw_payload LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_payments_booking (booking_id),
    KEY idx_payments_provider_id (provider, provider_payment_id),
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    therapist_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_reviews_booking (booking_id),
    CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_reviews_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(80) NOT NULL,
    target_id BIGINT UNSIGNED DEFAULT NULL,
    target_type VARCHAR(40) DEFAULT NULL,
    details LONGTEXT NULL COMMENT 'JSON payload: source_ip, user_agent, and action-specific details',
    created_at DATETIME NOT NULL,
    KEY idx_admin_logs_admin (admin_id),
    KEY idx_admin_logs_action (action),
    KEY idx_admin_logs_created (created_at),
    CONSTRAINT fk_admin_logs_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(120) NOT NULL,
    setting_value LONGTEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS media_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_media_files_uploaded_by (uploaded_by),
    CONSTRAINT fk_media_files_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO service_categories (name, description, sort_order, is_active, created_at, updated_at) VALUES
('Relax & Wellness Massage', 'Relaxation and stress relief therapies.', 1, 1, NOW(), NOW()),
('Injury & Pain Treatment', 'Recovery and muscle-focused treatments.', 2, 1, NOW(), NOW()),
('Special Therapy', 'Premium specialty treatments.', 3, 1, NOW(), NOW()),
('Optional Add-On Services', 'Add-ons available during checkout only.', 4, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at);

INSERT INTO services (category_id, name, description, duration_minutes, price, is_addon, sort_order, is_active, created_at, updated_at)
SELECT c.id, x.name, x.description, x.duration, x.price, x.is_addon, x.sort_order, 1, NOW(), NOW()
FROM (
    SELECT 'Relax & Wellness Massage' AS category_name, 'Balinese Massage' AS name, 'Traditional Balinese full-body relaxation.' AS description, 60 AS duration, 550000 AS price, 0 AS is_addon, 1 AS sort_order
    UNION ALL SELECT 'Relax & Wellness Massage', 'Aromatherapy Massage', 'Essential oil relaxation therapy.', 60, 600000, 0, 2
    UNION ALL SELECT 'Injury & Pain Treatment', 'Thai Massage', 'Stretching and pressure-point treatment.', 90, 700000, 0, 1
    UNION ALL SELECT 'Injury & Pain Treatment', 'Lymphatic Massage', 'Lymphatic drainage therapy.', 75, 680000, 0, 2
    UNION ALL SELECT 'Injury & Pain Treatment', 'Deep Tissue Massage', 'Muscle-focused deep pressure therapy.', 90, 750000, 0, 3
    UNION ALL SELECT 'Special Therapy', 'Madero Therapy', 'Advanced contour and stimulation treatment.', 75, 850000, 0, 1
    UNION ALL SELECT 'Special Therapy', 'Pregnancy Massage', 'Prenatal comfort massage by trained therapist.', 60, 800000, 0, 2
    UNION ALL SELECT 'Optional Add-On Services', 'Facial', 'Hydration facial add-on.', 30, 250000, 1, 1
    UNION ALL SELECT 'Optional Add-On Services', 'Body Scrub', 'Luxury exfoliation add-on.', 30, 220000, 1, 2
    UNION ALL SELECT 'Optional Add-On Services', 'Manicure & Pedicure', 'Premium hand and foot grooming add-on.', 45, 300000, 1, 3
) x
INNER JOIN service_categories c ON c.name = x.category_name
WHERE NOT EXISTS (
    SELECT 1 FROM services s WHERE s.name = x.name
);

INSERT INTO coverage_areas (name, coverage_group, is_active, created_at, updated_at) VALUES
('Tabanan', 'A', 1, NOW(), NOW()),
('Ubud', 'A', 1, NOW(), NOW()),
('Sanur', 'A', 1, NOW(), NOW()),
('Canggu', 'B', 1, NOW(), NOW()),
('Kuta', 'B', 1, NOW(), NOW()),
('Denpasar', 'B', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at);

INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
SELECT 'System Admin', 'admin@grabmas.local', '$2y$10$2f2djCspL.XXaMIUEjiJreqSEZWbOlMUqJPjA/eqJXmmPRb./ScaW', 'admin', 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@grabmas.local');

INSERT INTO admins (user_id, created_at)
SELECT u.id, NOW()
FROM users u
WHERE u.email = 'admin@grabmas.local'
  AND NOT EXISTS (SELECT 1 FROM admins a WHERE a.user_id = u.id);

-- Seed demo therapist users
INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
SELECT 'Dewi Ayu', 'dewi@grabmas.local', '$2y$10$2f2djCspL.XXaMIUEjiJreqSEZWbOlMUqJPjA/eqJXmmPRb./ScaW', 'therapist', 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'dewi@grabmas.local');

INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
SELECT 'Sari Wulandari', 'sari@grabmas.local', '$2y$10$2f2djCspL.XXaMIUEjiJreqSEZWbOlMUqJPjA/eqJXmmPRb./ScaW', 'therapist', 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'sari@grabmas.local');

INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
SELECT 'Ketut Ratna', 'ketut@grabmas.local', '$2y$10$2f2djCspL.XXaMIUEjiJreqSEZWbOlMUqJPjA/eqJXmmPRb./ScaW', 'therapist', 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'ketut@grabmas.local');

-- Seed therapist profiles
INSERT INTO therapists (user_id, bio, experience_years, rating, photo_url, is_active, created_at, updated_at)
SELECT u.id,
       'Certified Balinese spa therapist specialising in deep tissue and aromatherapy massage with over 6 years of luxury hotel experience.',
       6, 4.9, NULL, 1, NOW(), NOW()
FROM users u WHERE u.email = 'dewi@grabmas.local'
  AND NOT EXISTS (SELECT 1 FROM therapists t WHERE t.user_id = u.id);

INSERT INTO therapists (user_id, bio, experience_years, rating, photo_url, is_active, created_at, updated_at)
SELECT u.id,
       'Experienced in traditional Javanese and Balinese healing techniques. Specialises in prenatal massage and reflexology.',
       4, 4.8, NULL, 1, NOW(), NOW()
FROM users u WHERE u.email = 'sari@grabmas.local'
  AND NOT EXISTS (SELECT 1 FROM therapists t WHERE t.user_id = u.id);

INSERT INTO therapists (user_id, bio, experience_years, rating, photo_url, is_active, created_at, updated_at)
SELECT u.id,
       'Holistic wellness practitioner trained in Balinese boreh body scrub, hot stone therapy, and lomi lomi massage.',
       5, 4.7, NULL, 1, NOW(), NOW()
FROM users u WHERE u.email = 'ketut@grabmas.local'
  AND NOT EXISTS (SELECT 1 FROM therapists t WHERE t.user_id = u.id);

-- Seed therapist coverage areas (all areas for all demo therapists)
INSERT INTO therapist_coverage_areas (therapist_id, area_id, created_at)
SELECT t.id, ca.id, NOW()
FROM therapists t
JOIN users u ON u.id = t.user_id
JOIN coverage_areas ca ON 1=1
WHERE u.email IN ('dewi@grabmas.local','sari@grabmas.local','ketut@grabmas.local')
  AND NOT EXISTS (
      SELECT 1 FROM therapist_coverage_areas x
      WHERE x.therapist_id = t.id AND x.area_id = ca.id
  );

-- Seed therapist services (all services for all demo therapists)
INSERT INTO therapist_services (therapist_id, service_id, created_at)
SELECT t.id, s.id, NOW()
FROM therapists t
JOIN users u ON u.id = t.user_id
JOIN services s ON 1=1
WHERE u.email IN ('dewi@grabmas.local','sari@grabmas.local','ketut@grabmas.local')
  AND NOT EXISTS (
      SELECT 1 FROM therapist_services x
      WHERE x.therapist_id = t.id AND x.service_id = s.id
  );

-- Seed therapist schedules (Mon–Sun, 09:00–21:00)
INSERT INTO therapist_schedules (therapist_id, day_of_week, start_time, end_time, is_available, created_at, updated_at)
SELECT t.id, d.n, '09:00:00', '21:00:00', 1, NOW(), NOW()
FROM therapists t
JOIN users u ON u.id = t.user_id
JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
      UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
WHERE u.email IN ('dewi@grabmas.local','sari@grabmas.local','ketut@grabmas.local')
  AND NOT EXISTS (
      SELECT 1 FROM therapist_schedules x
      WHERE x.therapist_id = t.id AND x.day_of_week = d.n
  );

INSERT INTO settings (setting_key, setting_value, updated_at) VALUES
('company_name', 'GrabMas Luxury Spa', NOW()),
('company_whatsapp', '+62XXXXXXXXXX', NOW()),
('booking_notice', 'Please book at least 2 hours in advance.', NOW()),
('open_hours', '{"start":"09:00","end":"21:00"}', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at);
