-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 09, 2026 at 04:07 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `sfive_resort`
--

-- --------------------------------------------------------

--
-- Table structure for table `cottages`
--

CREATE TABLE `cottages` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` enum('Bahay Kubo','Open Cottage','Kubo Premium') DEFAULT 'Bahay Kubo',
  `description` text DEFAULT NULL,
  `price_per_night` decimal(10,2) NOT NULL,
  `capacity` int(11) NOT NULL,
  `images` text DEFAULT NULL,
  `amenities` text DEFAULT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cottages`
--

INSERT INTO `cottages` (`id`, `name`, `category`, `description`, `price_per_night`, `capacity`, `images`, `amenities`, `is_available`, `created_at`) VALUES
(1, 'Cottage 1', 'Bahay Kubo', 'A cozy authentic bamboo kubo with natural ventilation and a relaxing veranda. Perfect for small families or couples who love a simple Filipino countryside vibe.', 300.00, 6, NULL, 'Garden view', 1, '2026-03-30 04:07:33'),
(2, 'Cottage 5', 'Bahay Kubo', 'Surrounded by lush tropical plants, Bahay Kubo 2 offers a peaceful retreat with natural breezes. Ideal for guests who enjoy waking up to the sounds of nature.', 1200.00, 15, NULL, 'Karaoke', 1, '2026-03-30 04:07:33'),
(3, 'Cottage 2', 'Bahay Kubo', 'Nestled near the garden path, this kubo features traditional bamboo construction with hammock space outside. A true Filipino countryside experience.', 500.00, 10, NULL, ', Garden path access', 1, '2026-03-30 04:07:33'),
(4, 'Cottage 3', 'Bahay Kubo', 'Overlooking the resort grounds, Bahay Kubo 4 gives guests a wide open view of the greenery while enjoying the cool natural breeze from the highlands.', 800.00, 15, NULL, 'Resort view, Outdoor bench', 1, '2026-03-30 04:07:33'),
(5, 'Cottage 4', 'Bahay Kubo', 'The most private of our kubos, tucked away for guests who want quiet and solitude. Great for couples or solo travelers.', 800.00, 15, NULL, 'Private garden, BBQ grill access', 1, '2026-03-30 04:07:33'),
(6, 'Open Pavillion', 'Open Cottage', 'Our largest open-air event venue perfect for birthdays, weddings, reunions, and fiestas. Wide covered area with open garden surroundings for up to 60 guests.', 2800.00, 40, NULL, 'Our elegant pavilion offers a spacious, open-air venue perfect for weddings, parties, and special gatherings. Surrounded by lush greenery, it provides a relaxing ambiance with modern amenities for a seamless and memorable event experience.', 1, '2026-03-30 04:07:33'),
(8, 'Kubo w/ Room1', 'Kubo Premium', 'Experience Filipino heritage in luxury. This premium kubo features a split-type air conditioner, a king-size bed with premium linens, and a private veranda with garden view.', 1300.00, 4, NULL, 'Our elegant pavilion offers a spacious, open-air venue perfect for weddings, parties, and special gatherings. Surrounded by lush greenery, it provides a relaxing ambiance with modern amenities for a seamless and memorable event experience.', 1, '2026-03-30 04:07:33'),
(9, 'Kubo w/ Room 2', 'Kubo Premium', 'Ideal for couples or small families. Features two queen beds, full aircon comfort, private shower room, and cozy bamboo-styled interior with modern touches.', 2800.00, 2, NULL, 'Private bathroom, Wall Fan, 2 beds', 1, '2026-03-30 04:07:33');

-- --------------------------------------------------------

--
-- Table structure for table `cottage_images`
--

CREATE TABLE `cottage_images` (
  `id` int(11) NOT NULL,
  `cottage_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `label` varchar(50) DEFAULT 'Photo',
  `sort_order` int(11) DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cottage_images`
--

INSERT INTO `cottage_images` (`id`, `cottage_id`, `filename`, `label`, `sort_order`, `uploaded_at`) VALUES
(1, 1, 'cottage_1_thumb_1774844189.jpg', 'Thumbnail', 0, '2026-03-30 04:16:29'),
(2, 2, 'cottage_2_thumb_1774844354.jpg', 'Thumbnail', 0, '2026-03-30 04:19:14'),
(3, 3, 'cottage_3_thumb_1774844582.jpg', 'Thumbnail', 0, '2026-03-30 04:23:02'),
(4, 4, 'cottage_4_thumb_1774844647.jpg', 'Thumbnail', 0, '2026-03-30 04:24:07'),
(5, 5, 'cottage_5_thumb_1774844714.jpg', 'Thumbnail', 0, '2026-03-30 04:25:14'),
(7, 9, 'cottage_9_thumb_1774847912.jpg', 'Thumbnail', 0, '2026-03-30 05:18:32'),
(9, 6, 'cottage_6_thumb_1774848749.jpg', 'Thumbnail', 0, '2026-03-30 05:32:29'),
(10, 8, 'cottage_8_thumb_1774848909.jpg', 'Thumbnail', 0, '2026-03-30 05:35:09');

-- --------------------------------------------------------

--
-- Table structure for table `gcash_payments`
--

CREATE TABLE `gcash_payments` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `reference_number` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `sender_name` varchar(100) NOT NULL,
  `sender_number` varchar(20) NOT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Verified','Rejected') DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `booking_code` varchar(20) NOT NULL,
  `guest_name` varchar(100) NOT NULL,
  `guest_email` varchar(100) NOT NULL,
  `guest_phone` varchar(20) NOT NULL,
  `cottage_id` int(11) NOT NULL,
  `check_in` date NOT NULL,
  `check_out` date NOT NULL,
  `num_guests` int(11) NOT NULL,
  `special_requests` text DEFAULT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `status` enum('Pending','Confirmed','Cancelled') DEFAULT 'Pending',
  `payment_method` enum('Pay at Resort','GCash') DEFAULT 'Pay at Resort',
  `payment_status` enum('Unpaid','Pending Verification','Paid') DEFAULT 'Unpaid',
  `paymongo_link_id` varchar(100) DEFAULT NULL,
  `paymongo_checkout_url` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('customer','admin') DEFAULT 'customer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `role`, `created_at`) VALUES
(1, 'Admin', 'admin@sfive.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 'admin', '2026-03-30 04:07:33');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cottages`
--
ALTER TABLE `cottages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cottage_images`
--
ALTER TABLE `cottage_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cottage_id` (`cottage_id`);

--
-- Indexes for table `gcash_payments`
--
ALTER TABLE `gcash_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reservation_id` (`reservation_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `booking_code` (`booking_code`),
  ADD KEY `cottage_id` (`cottage_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cottages`
--
ALTER TABLE `cottages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `cottage_images`
--
ALTER TABLE `cottage_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `gcash_payments`
--
ALTER TABLE `gcash_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cottage_images`
--
ALTER TABLE `cottage_images`
  ADD CONSTRAINT `cottage_images_ibfk_1` FOREIGN KEY (`cottage_id`) REFERENCES `cottages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `gcash_payments`
--
ALTER TABLE `gcash_payments`
  ADD CONSTRAINT `gcash_payments_ibfk_1` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`);

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`cottage_id`) REFERENCES `cottages` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
