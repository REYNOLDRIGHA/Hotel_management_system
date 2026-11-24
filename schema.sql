CREATE DATABASE IF NOT EXISTS hms_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE hms_db;

SET NAMES utf8mb4;
SET time_zone = '+03:00';

-- 1. Users & Roles (FR-001, FR-002, NFR-003)
CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM(
        'Administrator',
        'Hotel Manager',
        'Receptionist',
        'Housekeeping Supervisor',
        'Housekeeper',
        'Accountant / Cashier',
        'Guest'
    ) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Room Types & Rooms (FR-006, FR-007, BR-008)
CREATE TABLE room_types (
    room_type_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    capacity TINYINT UNSIGNED NOT NULL,
    base_rate DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE rooms (
    room_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_type_id INT UNSIGNED NOT NULL,
    room_number VARCHAR(20) NOT NULL UNIQUE,
    floor TINYINT NOT NULL DEFAULT 1,
    status ENUM(
        'Available',
        'Reserved',
        'Occupied',
        'Dirty',
        'Cleaning',
        'Out of Service'
    ) NOT NULL DEFAULT 'Available',
    CONSTRAINT fk_rooms_room_type
        FOREIGN KEY (room_type_id) REFERENCES room_types(room_type_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 3. Guests & Loyalty Account (FR-015, FR-018, FR-033, BR-005)
CREATE TABLE guests (
    guest_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    full_name VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(150) NULL,
    id_type ENUM('National ID', 'Passport') NULL,
    id_number VARCHAR(50) NULL,
    loyalty_points INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_guests_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4. Reservations (FR-010, FR-011, FR-012)
CREATE TABLE reservations (
    reservation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    confirmation_code VARCHAR(25) NOT NULL UNIQUE,
    guest_id INT UNSIGNED NOT NULL,
    room_type_id INT UNSIGNED NOT NULL,
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    guests_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('Pending', 'Confirmed', 'Checked-In', 'Cancelled', 'Completed') NOT NULL DEFAULT 'Confirmed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_res_dates CHECK (check_out_date > check_in_date),
    CONSTRAINT fk_res_guest
        FOREIGN KEY (guest_id) REFERENCES guests(guest_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_res_room_type
        FOREIGN KEY (room_type_id) REFERENCES room_types(room_type_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 5. Active / Completed Stays (FR-014, FR-016, FR-017)
CREATE TABLE stays (
    stay_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT UNSIGNED NULL,
    guest_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NOT NULL,
    check_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    check_out_at DATETIME NULL,
    status ENUM('Active', 'Checked-Out') NOT NULL DEFAULT 'Active',
    CONSTRAINT fk_stays_reservation
        FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_stays_guest
        FOREIGN KEY (guest_id) REFERENCES guests(guest_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_stays_room
        FOREIGN KEY (room_id) REFERENCES rooms(room_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 6. Stay Nights — Blocks overlapping bookings per room & night (FR-012, BR-001)
CREATE TABLE stay_nights (
    stay_night_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stay_id INT UNSIGNED NULL,
    reservation_id INT UNSIGNED NULL,
    room_id INT UNSIGNED NOT NULL,
    night DATE NOT NULL,
    CONSTRAINT uq_room_night UNIQUE (room_id, night),
    CONSTRAINT fk_sn_stay
        FOREIGN KEY (stay_id) REFERENCES stays(stay_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sn_reservation
        FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sn_room
        FOREIGN KEY (room_id) REFERENCES rooms(room_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 7. Folio Charges & Payments (FR-021, FR-022, FR-023, BR-006)
CREATE TABLE charges (
    charge_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stay_id INT UNSIGNED NOT NULL,
    description VARCHAR(150) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    posted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_charge_amount CHECK (amount > 0),
    CONSTRAINT fk_charges_stay
        FOREIGN KEY (stay_id) REFERENCES stays(stay_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payments (
    payment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stay_id INT UNSIGNED NOT NULL,
    method ENUM('Cash', 'Card', 'Mobile Money') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference_no VARCHAR(80) NULL,
    paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_payment_amount CHECK (amount > 0),
    CONSTRAINT fk_payments_stay
        FOREIGN KEY (stay_id) REFERENCES stays(stay_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 8. Loyalty Points Ledger (FR-033, FR-034, FR-035, FR-036, FR-037)
CREATE TABLE loyalty_transactions (
    txn_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    guest_id INT UNSIGNED NOT NULL,
    stay_id INT UNSIGNED NULL,
    txn_type ENUM('EARN', 'REDEEM', 'REVERSAL') NOT NULL,
    points INT NOT NULL,
    notes VARCHAR(200) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_lt_guest
        FOREIGN KEY (guest_id) REFERENCES guests(guest_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_lt_stay
        FOREIGN KEY (stay_id) REFERENCES stays(stay_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- 9. Housekeeping & Maintenance Tasks (FR-026, FR-027, FR-028, BR-008)
CREATE TABLE housekeeping_tasks (
    task_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    assigned_to INT UNSIGNED NULL,
    task_type ENUM('Cleaning', 'Maintenance') NOT NULL DEFAULT 'Cleaning',
    status ENUM('Pending', 'In Progress', 'Completed') NOT NULL DEFAULT 'Pending',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    CONSTRAINT fk_ht_room
        FOREIGN KEY (room_id) REFERENCES rooms(room_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ht_user
        FOREIGN KEY (assigned_to) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ==========================================
-- SAMPLE SEED DATA FOR QUICK WEEKEND TESTING
-- ==========================================

-- Password for seeded staff accounts below is: Password123!
-- Hash generated with password_hash('Password123!', PASSWORD_BCRYPT)
INSERT INTO users (full_name, username, email, password_hash, role) VALUES
('System Admin', 'admin', 'admin@hotel.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator'),
('Front Desk Reception', 'reception', 'reception@hotel.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Receptionist'),
('Housekeeping Lead', 'housekeeper', 'housekeeper@hotel.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Housekeeper'),
('Hotel Manager', 'manager', 'manager@hotel.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Hotel Manager');

INSERT INTO room_types (name, capacity, base_rate) VALUES
('Standard Single', 1, 4500.00),
('Deluxe Double', 2, 7500.00),
('Executive Suite', 4, 14000.00);

INSERT INTO rooms (room_type_id, room_number, floor, status) VALUES
(1, '101', 1, 'Available'),
(1, '102', 1, 'Available'),
(2, '201', 2, 'Available'),
(2, '202', 2, 'Dirty'),
(3, '301', 3, 'Available');

INSERT INTO guests (full_name, phone, email, id_type, id_number, loyalty_points) VALUES
('Amina Wanjiku', '+254712345678', 'amina@example.com', 'National ID', '34567890', 120),
('David Kamau', '+254723456789', 'david@example.com', 'Passport', 'AK987654', 0);