-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 25, 2026 at 07:15 AM
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
-- Database: `monday_com_crm`
--

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `name`, `email`, `phone`, `website`, `address`, `industry`, `created_by`, `created_at`) VALUES
(1, 'TCS', 'info@tcs.com', '9876543210', 'tcs.com', 'Chennai', 'IT', 2, '2026-02-18 10:49:28'),
(2, 'ABC Jewellery Updated', 'newabc@gmail.com', '9999999999', 'www.newabc.com', 'Chennai', 'Jewellery', 2, '2026-02-18 10:59:31'),
(3, 'XYZ Software', 'contact@xyzsoft.com', '9123456780', 'www.xyzsoft.com', 'Bangalore', 'Software', 2, '2026-02-18 10:59:31'),
(4, 'Sri Mobiles', 'srimobiles@gmail.com', '9012345678', 'www.srimobiles.com', 'Coimbatore', 'Electronics', 2, '2026-02-18 10:59:31'),
(5, 'Classic Furniture', 'classic@gmail.com', '9988776655', 'www.classicfurniture.com', 'Madurai', 'Furniture', 2, '2026-02-18 10:59:31');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `created_from_lead_id` int(11) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `name`, `phone`, `email`, `source_id`, `created_from_lead_id`, `assigned_to`, `created_at`) VALUES
(1, 'Ragul', '9888543210', 'ragul@gmail.com', 3, 1, 2, '2026-02-20 11:59:14'),
(2, 'Kumar', '9000011111', 'kumar@gmail.com', 6, NULL, 3, '2026-02-20 15:02:25'),
(6, 'Sriram', '9888553210', 'sriram@gmail.com', 3, 4, 2, '2026-02-21 06:11:53'),
(7, 'mani', '9000011121', 'mani@gmail.com', 6, NULL, 4, '2026-02-23 05:16:05');

-- --------------------------------------------------------

--
-- Table structure for table `deals`
--

CREATE TABLE `deals` (
  `id` int(11) NOT NULL,
  `title` varchar(150) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `value` decimal(12,2) DEFAULT NULL,
  `stage` enum('prospect','negotiation','won','lost') DEFAULT NULL,
  `owner` int(11) DEFAULT NULL,
  `expected_close_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deals`
--

INSERT INTO `deals` (`id`, `title`, `customer_id`, `value`, `stage`, `owner`, `expected_close_date`, `created_at`) VALUES
(9, 'mani', 7, 45000.00, 'prospect', 4, '2026-04-10', '2026-02-23 05:47:09');

-- --------------------------------------------------------

--
-- Table structure for table `deals_lost`
--

CREATE TABLE `deals_lost` (
  `id` int(11) NOT NULL,
  `original_deal_id` int(11) DEFAULT NULL,
  `title` varchar(150) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `value` decimal(12,2) DEFAULT NULL,
  `stage` enum('lost') NOT NULL DEFAULT 'lost',
  `owner` int(11) DEFAULT NULL,
  `expected_close_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `lost_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `lost_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deals_lost`
--

INSERT INTO `deals_lost` (`id`, `original_deal_id`, `title`, `customer_id`, `value`, `stage`, `owner`, `expected_close_date`, `created_at`, `lost_at`, `lost_reason`) VALUES
(1, 7, 'Sriram', 6, 45000.00, 'lost', 2, '2026-04-10', '2026-02-21 06:23:26', '2026-02-21 06:24:46', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `deals_won`
--

CREATE TABLE `deals_won` (
  `id` int(11) NOT NULL,
  `original_deal_id` int(11) DEFAULT NULL,
  `title` varchar(150) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `value` decimal(12,2) DEFAULT NULL,
  `stage` enum('won') NOT NULL DEFAULT 'won',
  `owner` int(11) DEFAULT NULL,
  `expected_close_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `won_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deals_won`
--

INSERT INTO `deals_won` (`id`, `original_deal_id`, `title`, `customer_id`, `value`, `stage`, `owner`, `expected_close_date`, `created_at`, `won_at`) VALUES
(1, 4, 'Ragul', 1, 35000.00, 'won', 2, '2026-03-15', '2026-02-20 16:34:56', '2026-02-21 05:39:12'),
(2, 6, 'Ragul', 1, 45000.00, 'won', 2, '2026-04-10', '2026-02-21 06:23:17', '2026-02-21 06:24:33');

-- --------------------------------------------------------

--
-- Table structure for table `followups`
--

CREATE TABLE `followups` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `type` enum('call','whatsapp','meeting','email') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `next_followup_date` date DEFAULT NULL,
  `status` enum('pending','done') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `followups`
--

INSERT INTO `followups` (`id`, `customer_id`, `employee_id`, `type`, `notes`, `next_followup_date`, `status`, `created_at`) VALUES
(1, 1, 2, 'call', 'Call back tomorrow', '2026-02-22', 'done', '2026-02-21 07:25:38');

-- --------------------------------------------------------

--
-- Table structure for table `leads`
--

CREATE TABLE `leads` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('new','assigned','contacted','qualified','lost') DEFAULT 'new',
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leads`
--

INSERT INTO `leads` (`id`, `name`, `phone`, `email`, `source_id`, `product_id`, `message`, `status`, `assigned_to`, `created_at`) VALUES
(1, 'Ragul', '9888543210', 'Ragul@gmail.com', 3, 6, 'Interested. Please call me.', 'contacted', 2, '2026-02-20 08:09:47'),
(2, 'sriram', '9888553210', 'sriram@gmail.com', 3, 19, 'Interested. Please call me.', 'assigned', 3, '2026-02-20 10:47:25'),
(4, 'Sriram', '9888553210', 'sriram@gmail.com', 3, 19, 'Interested', 'lost', 2, '2026-02-20 11:20:12'),
(5, 'Arun', '9876543210', 'arun@gmail.com', 2, 6, 'Looking for pricing details', 'new', 3, '2026-02-20 11:20:12'),
(6, 'Priya', '9789012345', 'priya@gmail.com', 1, 19, 'Please share brochure', 'new', 3, '2026-02-20 11:20:12'),
(7, 'Karthik', '9123456780', 'karthik@gmail.com', 3, 6, 'Interested', 'lost', 2, '2026-02-20 11:20:12');

-- --------------------------------------------------------

--
-- Table structure for table `leads_lost`
--

CREATE TABLE `leads_lost` (
  `id` int(11) NOT NULL,
  `original_lead_id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` varchar(30) DEFAULT 'lost',
  `assigned_to` int(11) DEFAULT NULL,
  `moved_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leads_lost`
--

INSERT INTO `leads_lost` (`id`, `original_lead_id`, `name`, `phone`, `email`, `source_id`, `product_id`, `message`, `status`, `assigned_to`, `moved_at`) VALUES
(1, 7, 'Karthik', '9123456780', 'karthik@gmail.com', 3, 6, 'Interested', 'lost', 2, '2026-02-21 06:12:52'),
(2, 4, 'Sriram', '9888553210', 'sriram@gmail.com', 3, 19, 'Interested', 'lost', 2, '2026-02-23 06:53:25');

-- --------------------------------------------------------

--
-- Table structure for table `lead_sources`
--

CREATE TABLE `lead_sources` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lead_sources`
--

INSERT INTO `lead_sources` (`id`, `name`, `description`, `status`, `created_at`) VALUES
(1, 'Instagram', 'Leads from Instagram', 'active', '2026-02-19 05:09:22'),
(2, 'Facebook', 'Leads from Facebook', 'active', '2026-02-19 05:09:22'),
(3, 'Website', 'Leads from company website', 'active', '2026-02-19 05:09:22'),
(4, 'Google Ads', 'Paid ads leads', 'active', '2026-02-19 05:09:22'),
(5, 'Referral', 'Referred by existing customer', 'active', '2026-02-19 05:09:22'),
(6, 'Walk-in', 'Direct store visit', 'active', '2026-02-19 05:09:22');

-- --------------------------------------------------------

--
-- Table structure for table `notes`
--

CREATE TABLE `notes` (
  `id` int(11) NOT NULL,
  `entity_type` enum('lead','customer','product') DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notes`
--

INSERT INTO `notes` (`id`, `entity_type`, `entity_id`, `note`, `created_by`, `created_at`) VALUES
(1, 'customer', 1, 'Customer called, wants discount', 2, '2026-02-21 07:24:57');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `product_img` varchar(255) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `type` enum('physical','digital','service') DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `description`, `price`, `product_img`, `category_id`, `type`, `is_public`, `created_at`) VALUES
(1, 'iPhone 15', 'Apple mobile', 55000.00, NULL, 1, 'physical', 1, '2026-02-19 06:29:12'),
(2, 'samsung', 'Apple mobile', 55000.00, NULL, 1, 'physical', 1, '2026-02-19 06:32:40'),
(3, 'realme', 'Apple mobile', 45000.00, NULL, 1, 'physical', 1, '2026-02-19 06:34:17'),
(4, 'redmi', 'Apple mobile', 45000.00, NULL, 1, 'physical', 1, '2026-02-19 06:39:23'),
(5, 'redmi', 'Apple mobile', 55000.00, '/uploads/products/1771483383_samples_640×426.jpg', 1, 'physical', 1, '2026-02-19 06:43:02'),
(6, 'Laptop', 'High performance laptop', 65000.00, NULL, 1, 'physical', 1, '2026-02-20 05:43:20'),
(7, 'CRM Software', 'Subscription CRM', 1999.00, NULL, 2, 'service', 1, '2026-02-20 05:43:20'),
(8, 'Laptop', 'High performance laptop', 65000.00, NULL, 1, 'physical', 1, '2026-02-20 06:03:06'),
(9, 'CRM Software', 'Subscription CRM', 1999.00, NULL, 2, 'service', 1, '2026-02-20 06:03:06'),
(10, 'moto', 'Apple mobile', 55000.00, '/uploads/products/1771567469_samples_640×426.jpg', 1, 'physical', 1, '2026-02-20 06:04:28'),
(11, 'Laptop', 'High performance laptop', 65000.00, NULL, 1, 'physical', 1, '2026-02-20 06:08:38'),
(12, 'CRM Software', 'Subscription CRM', 1999.00, NULL, 2, 'service', 1, '2026-02-20 06:08:38'),
(13, 'Laptop', 'High performance laptop', 65000.00, NULL, 1, 'physical', 1, '2026-02-20 06:14:19'),
(14, 'CRM Software', 'Subscription CRM', 1999.00, NULL, 2, 'service', 1, '2026-02-20 06:14:19'),
(15, 'Laptop', 'High performance laptop', 65000.00, NULL, 1, 'physical', 1, '2026-02-20 06:14:27'),
(16, 'CRM Software', 'Subscription CRM', 1999.00, NULL, 2, 'service', 1, '2026-02-20 06:14:27'),
(17, 'Laptop', 'High performance laptop', 65000.00, NULL, 1, 'physical', 1, '2026-02-20 06:14:45'),
(19, 'Laptop', 'High performance laptop', 65000.00, '/uploads/products/1771571392_699808c0984d7_laptop_main.jpg.png', 1, 'physical', 1, '2026-02-20 07:09:52'),
(20, 'CRM Software', 'Subscription CRM', 1999.00, '/uploads/products/1771571392_699808c09eb6e_crm_main.png.jpeg', 2, 'service', 1, '2026-02-20 07:09:52');

-- --------------------------------------------------------

--
-- Table structure for table `product_categories`
--

CREATE TABLE `product_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_categories`
--

INSERT INTO `product_categories` (`id`, `name`) VALUES
(1, 'Electronics'),
(2, 'Mobiles'),
(3, 'Furniture'),
(4, 'Jewellery'),
(5, 'Software'),
(6, 'Services');

-- --------------------------------------------------------

--
-- Table structure for table `product_images`
--

CREATE TABLE `product_images` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `position` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_images`
--

INSERT INTO `product_images` (`id`, `product_id`, `image_url`, `position`) VALUES
(1, 3, '/uploads/products/1771482857_samples_640×426.jpg', 1),
(2, 4, '/uploads/products/1771483163_samples_640×426.jpg', 1),
(3, 5, '/uploads/products/1771483383_samples_640×426.jpg', 1),
(4, 10, '/uploads/products/1771567469_samples_640×426.jpg', 1),
(5, 19, '/uploads/products/1771571392_699808c0984d7_laptop_main.jpg.png', 1),
(6, 19, '/uploads/products/1771571392_699808c099eff_laptop_1.jpg.jpg', 2),
(7, 19, '/uploads/products/1771571392_699808c09ac6b_laptop_2.jpg.jpeg', 3),
(8, 20, '/uploads/products/1771571392_699808c09eb6e_crm_main.png.jpeg', 1),
(9, 20, '/uploads/products/1771571392_699808c09fb52_crm_1.png.png', 2);

-- --------------------------------------------------------

--
-- Table structure for table `quotations`
--

CREATE TABLE `quotations` (
  `id` int(11) NOT NULL,
  `quotation_no` varchar(50) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `quote_date` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_type` enum('none','percent','flat') NOT NULL DEFAULT 'none',
  `discount_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `status` enum('draft','sent','accepted','rejected','expired') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quotation_items`
--

CREATE TABLE `quotation_items` (
  `id` int(11) NOT NULL,
  `quotation_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `qty` decimal(12,2) NOT NULL DEFAULT 1.00,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('admin','manager','sales') DEFAULT 'sales',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password`, `role`, `created_at`) VALUES
(1, 'Qads', 'Qads@mail.com', '9876543210', '$2y$10$OD5ZZJN571KrYeKS4rU5q.rxKS1jiQMOhe2HybnP4flhlvwyNy5sa', 'admin', '2026-02-18 10:06:49'),
(2, 'RagulRaj', 'RagulRaj@mail.com', '9886543210', '$2y$10$zrEJkXtbSq5R6nLaZV.HpemlH4wQd1JFrD0Q83h4GHPSdlyrfTZku', 'sales', '2026-02-18 10:18:43'),
(3, 'siva', 'siva@mail.com', '9886643210', '$2y$10$qcMTnEtLTFWFeFVwpuptjuIF9PL9t8leEEWtNlIOcaSXd.Kv95jhG', 'sales', '2026-02-20 10:49:13'),
(4, 'Bala', 'Bala@mail.com', '9886553210', '$2y$10$.V/pH54.eZ0mJd/zMga5JuvvBe/Vm8NWcXzmWXxXX6aISv3H5B7ce', 'sales', '2026-02-23 05:04:43');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `source_id` (`source_id`),
  ADD KEY `created_from_lead_id` (`created_from_lead_id`),
  ADD KEY `assigned_to` (`assigned_to`);

--
-- Indexes for table `deals`
--
ALTER TABLE `deals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `owner` (`owner`);

--
-- Indexes for table `deals_lost`
--
ALTER TABLE `deals_lost`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `owner` (`owner`),
  ADD KEY `original_deal_id` (`original_deal_id`);

--
-- Indexes for table `deals_won`
--
ALTER TABLE `deals_won`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `owner` (`owner`),
  ADD KEY `original_deal_id` (`original_deal_id`);

--
-- Indexes for table `followups`
--
ALTER TABLE `followups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `leads`
--
ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `source_id` (`source_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `assigned_to` (`assigned_to`);

--
-- Indexes for table `leads_lost`
--
ALTER TABLE `leads_lost`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `original_lead_id` (`original_lead_id`),
  ADD KEY `source_id` (`source_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `assigned_to` (`assigned_to`);

--
-- Indexes for table `lead_sources`
--
ALTER TABLE `lead_sources`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notes`
--
ALTER TABLE `notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `product_categories`
--
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `quotations`
--
ALTER TABLE `quotations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `quotation_no` (`quotation_no`);

--
-- Indexes for table `quotation_items`
--
ALTER TABLE `quotation_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quotation_id` (`quotation_id`),
  ADD KEY `product_id` (`product_id`);

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
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `deals`
--
ALTER TABLE `deals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `deals_lost`
--
ALTER TABLE `deals_lost`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `deals_won`
--
ALTER TABLE `deals_won`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `followups`
--
ALTER TABLE `followups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `leads`
--
ALTER TABLE `leads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `leads_lost`
--
ALTER TABLE `leads_lost`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `lead_sources`
--
ALTER TABLE `lead_sources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `notes`
--
ALTER TABLE `notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `quotations`
--
ALTER TABLE `quotations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotation_items`
--
ALTER TABLE `quotation_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`source_id`) REFERENCES `lead_sources` (`id`),
  ADD CONSTRAINT `customers_ibfk_2` FOREIGN KEY (`created_from_lead_id`) REFERENCES `leads` (`id`),
  ADD CONSTRAINT `customers_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`);

--
-- Constraints for table `deals`
--
ALTER TABLE `deals`
  ADD CONSTRAINT `deals_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `deals_ibfk_2` FOREIGN KEY (`owner`) REFERENCES `users` (`id`);

--
-- Constraints for table `followups`
--
ALTER TABLE `followups`
  ADD CONSTRAINT `followups_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `followups_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `leads`
--
ALTER TABLE `leads`
  ADD CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`source_id`) REFERENCES `lead_sources` (`id`),
  ADD CONSTRAINT `leads_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `leads_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`);

--
-- Constraints for table `notes`
--
ALTER TABLE `notes`
  ADD CONSTRAINT `notes_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`);

--
-- Constraints for table `product_images`
--
ALTER TABLE `product_images`
  ADD CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quotation_items`
--
ALTER TABLE `quotation_items`
  ADD CONSTRAINT `fk_qitems_quote` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
