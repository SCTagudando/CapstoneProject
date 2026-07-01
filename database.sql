
DROP DATABASE IF EXISTS sfive_resort;
CREATE DATABASE sfive_resort CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sfive_resort;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    role ENUM('customer', 'admin') DEFAULT 'customer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Cottages table (added category + images columns)
CREATE TABLE cottages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category ENUM('Bahay Kubo','Open Cottage','Kubo Premium') DEFAULT 'Bahay Kubo',
    description TEXT,
    price_per_night DECIMAL(10,2) NOT NULL,
    capacity INT NOT NULL,
    images TEXT DEFAULT NULL,       -- JSON array of filenames e.g. ["front.jpg","interior.jpg"]
    amenities TEXT,
    is_available TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Cottage images table (stores each uploaded photo per cottage)
CREATE TABLE cottage_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cottage_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    label VARCHAR(50) DEFAULT 'Photo',
    sort_order INT DEFAULT 0,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cottage_id) REFERENCES cottages(id) ON DELETE CASCADE
);

-- Reservations table
CREATE TABLE reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_code VARCHAR(20) UNIQUE NOT NULL,
    guest_name VARCHAR(100) NOT NULL,
    guest_email VARCHAR(100) NOT NULL,
    guest_phone VARCHAR(20) NOT NULL,
    cottage_id INT NOT NULL,
    check_in DATE NOT NULL,
    check_out DATE NOT NULL,
    num_guests INT NOT NULL,
    special_requests TEXT,
    total_price DECIMAL(10,2) NOT NULL,
    status ENUM('Pending', 'Confirmed', 'Cancelled') DEFAULT 'Pending',
    payment_method ENUM('Pay at Resort','GCash') DEFAULT 'Pay at Resort',
    payment_status ENUM('Unpaid','Pending Verification','Paid') DEFAULT 'Unpaid',
    paymongo_link_id VARCHAR(100) DEFAULT NULL,
    paymongo_checkout_url TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cottage_id) REFERENCES cottages(id)
);

-- GCash payments table
CREATE TABLE gcash_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    reference_number VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    sender_name VARCHAR(100) NOT NULL,
    sender_number VARCHAR(20) NOT NULL,
    proof_image VARCHAR(255),
    status ENUM('Pending','Verified','Rejected') DEFAULT 'Pending',
    notes TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id)
);

-- =============================================
-- SEED: Default Admin
-- =============================================
INSERT INTO users (name, email, password, role) VALUES
('Admin', 'admin@sfive.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
-- Default password: password

-- =============================================
-- 5 Bahay Kubo (Fan only)
-- =============================================
INSERT INTO cottages (name, category, description, price_per_night, capacity, amenities) VALUES
('Bahay Kubo 1', 'Bahay Kubo', 'A cozy authentic bamboo kubo with natural ventilation and a relaxing veranda. Perfect for small families or couples who love a simple Filipino countryside vibe.', 800.00, 4, 'Electric Fan, Veranda, Mosquito net, Outdoor seating, Garden view'),
('Bahay Kubo 2', 'Bahay Kubo', 'Surrounded by lush tropical plants, Bahay Kubo 2 offers a peaceful retreat with natural breezes. Ideal for guests who enjoy waking up to the sounds of nature.', 800.00, 4, 'Electric Fan, Veranda, Mosquito net, Outdoor seating, Garden view'),
('Bahay Kubo 3', 'Bahay Kubo', 'Nestled near the garden path, this kubo features traditional bamboo construction with hammock space outside. A true Filipino countryside experience.', 800.00, 4, 'Electric Fan, Veranda, Mosquito net, Hammock, Garden path access'),
('Bahay Kubo 4', 'Bahay Kubo', 'Overlooking the resort grounds, Bahay Kubo 4 gives guests a wide open view of the greenery while enjoying the cool natural breeze from the highlands.', 800.00, 4, 'Electric Fan, Veranda, Mosquito net, Resort view, Outdoor bench'),
('Bahay Kubo 5', 'Bahay Kubo', 'The most private of our kubos, tucked away for guests who want quiet and solitude. Great for couples or solo travelers.', 800.00, 3, 'Electric Fan, Veranda, Mosquito net, Private garden, BBQ grill access');

-- =============================================
-- 2 Open Cottages (Events)
-- =============================================
INSERT INTO cottages (name, category, description, price_per_night, capacity, amenities) VALUES
('Open Cottage 1 — Fiesta Hall', 'Open Cottage', 'Our largest open-air event venue perfect for birthdays, weddings, reunions, and fiestas. Wide covered area with open garden surroundings for up to 60 guests.', 3500.00, 60, 'Open-air covered hall, Long tables & chairs, BBQ grill stations, Sound system ready, Outdoor lighting, Garden surroundings, Parking access'),
('Open Cottage 2 — Garden Pavilion', 'Open Cottage', 'An elegant open pavilion surrounded by tropical flowers and bamboo accents. Perfect for intimate garden weddings, debut parties, or corporate events up to 40 guests.', 2800.00, 40, 'Open pavilion, Garden backdrop, BBQ grill area, Tables & chairs included, String lights, Floral surroundings, Event signage space');

-- =============================================
-- 5 Kubo Premium (Aircon + Good beds)
-- =============================================
INSERT INTO cottages (name, category, description, price_per_night, capacity, amenities) VALUES
('Kubo Premium 1', 'Kubo Premium', 'Experience Filipino heritage in luxury. This premium kubo features a split-type air conditioner, a king-size bed with premium linens, and a private veranda with garden view.', 2500.00, 2, 'Split-type Aircon, King bed, Premium bedding, Private bathroom, Hot & cold shower, Veranda, Mini ref, Garden view'),
('Kubo Premium 2', 'Kubo Premium', 'Ideal for couples or small families. Features two queen beds, full aircon comfort, private shower room, and cozy bamboo-styled interior with modern touches.', 2800.00, 4, 'Split-type Aircon, 2 Queen beds, Premium bedding, Private bathroom, Hot & cold shower, Veranda, Mini ref, Smart TV'),
('Kubo Premium 3', 'Kubo Premium', 'One of our most popular premium cottages with king bed, sofa area, and private bathroom. Split aircon keeps it cool even on the hottest days.', 2600.00, 3, 'Split-type Aircon, King bed, Sofa area, Private bathroom, Hot & cold shower, Mini ref, Veranda, Nature sound'),
('Kubo Premium 4', 'Kubo Premium', 'Perfect for small families. Two bedroom setup with dual queen beds, central aircon, spacious bathroom with hot shower, and Smart TV.', 3200.00, 5, 'Central Aircon, 2 Bedroom setup, 2 Queen beds, Hot & cold shower, Bathroom, Mini ref, Smart TV, Veranda'),
('Kubo Premium 5 — The Suite', 'Kubo Premium', 'Our flagship premium kubo — The Suite. King bed, lounging area, jacuzzi shower, premium linens, mini bar, and panoramic resort views. The ultimate S-Five experience.', 4000.00, 2, 'Split-type Aircon, King bed, Premium bedding, Jacuzzi shower, Mini bar, Smart TV, Panoramic resort view, Veranda, Breakfast option');