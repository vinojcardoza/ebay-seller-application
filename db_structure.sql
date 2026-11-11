-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:8889
-- Generation Time: Nov 10, 2026 at 03:07 PM
-- Server version: 5.7.39
-- PHP Version: 8.3.0

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `others_business`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` bigint(20) NOT NULL,
  `title` varchar(191) NOT NULL,
  `parent_id` int(11) DEFAULT '0',
  `menu_order` int(11) DEFAULT '0',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `custom_skus`
--

CREATE TABLE `custom_skus` (
  `id` bigint(20) NOT NULL,
  `category` bigint(20) DEFAULT NULL,
  `sku` varchar(191) NOT NULL,
  `shipping_service` varchar(191) DEFAULT NULL,
  `postage_cost` decimal(50,2) NOT NULL,
  `package_cost` decimal(50,2) NOT NULL,
  `status` int(11) DEFAULT '1',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `custom_sku_products`
--

CREATE TABLE `custom_sku_products` (
  `id` bigint(20) NOT NULL,
  `custom_sku` bigint(20) DEFAULT NULL,
  `product` bigint(20) DEFAULT NULL,
  `price` decimal(50,2) NOT NULL,
  `fees` decimal(50,2) NOT NULL,
  `quantity` int(11) DEFAULT '1',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `feedbacks`
--

CREATE TABLE `feedbacks` (
  `id` bigint(20) NOT NULL,
  `ebay_user_id` longtext,
  `comment_text` longtext,
  `comment_time` datetime DEFAULT NULL,
  `comment_type` varchar(191) DEFAULT NULL,
  `item_id` varchar(191) DEFAULT NULL,
  `item_title` longtext,
  `item_price` decimal(50,2) DEFAULT NULL,
  `feedback_id` longtext,
  `transaction_id` longtext,
  `order_line_item_id` longtext,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` bigint(20) NOT NULL,
  `platform` varchar(191) NOT NULL,
  `order_type` varchar(191) NOT NULL DEFAULT 'Purchase',
  `order_reference` varchar(191) NOT NULL,
  `order_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `packaging_cost` decimal(50,2) NOT NULL,
  `shipping_company` varchar(191) DEFAULT NULL,
  `shipping_service` varchar(191) DEFAULT NULL,
  `tracking_code` varchar(191) DEFAULT NULL,
  `shipping_status` int(11) DEFAULT '0',
  `shipping_datetime` datetime DEFAULT NULL,
  `shipping_delivery_datetime` datetime DEFAULT NULL,
  `postage_cost` decimal(50,2) NOT NULL,
  `ebay_user_id` longtext,
  `full_name` longtext,
  `email` longtext,
  `phone` longtext,
  `street1` longtext,
  `street2` longtext,
  `city` longtext,
  `state` longtext,
  `postcode` longtext,
  `country` longtext,
  `country_name` longtext,
  `status` int(11) NOT NULL DEFAULT '1',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `additional_notes` longtext,
  `updated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` bigint(20) NOT NULL,
  `product` bigint(20) DEFAULT NULL,
  `order_id` bigint(20) DEFAULT NULL,
  `item_cost` decimal(50,2) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `sold_price` decimal(50,2) DEFAULT NULL,
  `fees_deductions` decimal(50,2) DEFAULT NULL,
  `total_sold_price` decimal(50,2) DEFAULT NULL,
  `additional_notes` longtext,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` bigint(20) NOT NULL,
  `category` bigint(20) DEFAULT NULL,
  `sku` varchar(191) NOT NULL,
  `barcode_upc` varchar(191) NOT NULL,
  `code` varchar(191) NOT NULL,
  `title` varchar(191) NOT NULL,
  `low_stock` int(11) DEFAULT '10',
  `price` decimal(50,2) NOT NULL,
  `fees` decimal(50,2) NOT NULL,
  `shipping_service` varchar(191) DEFAULT NULL,
  `package_cost` decimal(50,2) NOT NULL,
  `postage_cost` decimal(50,2) NOT NULL,
  `promo_percentage` decimal(50,2) NOT NULL DEFAULT '7.50',
  `multi_buy_2` int(11) DEFAULT '2',
  `multi_buy_3` int(11) DEFAULT '5',
  `multi_buy_4` int(11) DEFAULT '10',
  `image` longtext,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT NULL,
  `status` int(11) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `id` bigint(20) NOT NULL,
  `supplier` bigint(20) DEFAULT NULL,
  `product` bigint(20) DEFAULT NULL,
  `purchased_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `quantity` int(11) NOT NULL,
  `total_price` decimal(50,2) NOT NULL,
  `status` int(11) NOT NULL,
  `received_date` datetime DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` bigint(20) NOT NULL,
  `supplier_name` varchar(191) NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` bigint(20) NOT NULL,
  `order_id` bigint(20) DEFAULT NULL,
  `transaction_datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `transaction_title` varchar(191) DEFAULT NULL,
  `transaction_id` varchar(191) DEFAULT NULL,
  `expense` decimal(50,2) NOT NULL,
  `income` decimal(50,2) NOT NULL,
  `notes` longtext,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) NOT NULL,
  `role` int(11) NOT NULL DEFAULT '50',
  `hash` varchar(191) NOT NULL,
  `email` varchar(191) NOT NULL,
  `login` varchar(191) NOT NULL,
  `password` varchar(191) NOT NULL,
  `full_name` varchar(191) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `custom_skus`
--
ALTER TABLE `custom_skus`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku_unique` (`sku`),
  ADD KEY `IDX_867E916764C19C1` (`category`);

--
-- Indexes for table `custom_sku_products`
--
ALTER TABLE `custom_sku_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_4B60D644A229C385` (`custom_sku`),
  ADD KEY `IDX_4B60D644D34A04AD` (`product`);

--
-- Indexes for table `feedbacks`
--
ALTER TABLE `feedbacks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_62809DB0D34A04AD` (`product`),
  ADD KEY `IDX_62809DB08D9F6D38` (`order_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku_unique` (`sku`),
  ADD KEY `IDX_B3BA5A5A64C19C1` (`category`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_AA6431FE9B2A6C7E` (`supplier`),
  ADD KEY `IDX_AA6431FED34A04AD` (`product`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_EAA81A4C8D9F6D38` (`order_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unq_email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `custom_skus`
--
ALTER TABLE `custom_skus`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `custom_sku_products`
--
ALTER TABLE `custom_sku_products`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feedbacks`
--
ALTER TABLE `feedbacks`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `custom_skus`
--
ALTER TABLE `custom_skus`
  ADD CONSTRAINT `FK_867E916764C19C1` FOREIGN KEY (`category`) REFERENCES `categories` (`id`);

--
-- Constraints for table `custom_sku_products`
--
ALTER TABLE `custom_sku_products`
  ADD CONSTRAINT `FK_4B60D644A229C385` FOREIGN KEY (`custom_sku`) REFERENCES `custom_skus` (`id`),
  ADD CONSTRAINT `FK_4B60D644D34A04AD` FOREIGN KEY (`product`) REFERENCES `products` (`id`);

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `FK_62809DB08D9F6D38` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `FK_62809DB0D34A04AD` FOREIGN KEY (`product`) REFERENCES `products` (`id`);

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `FK_B3BA5A5A64C19C1` FOREIGN KEY (`category`) REFERENCES `categories` (`id`);

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `FK_AA6431FED34A04AD` FOREIGN KEY (`product`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `FK_AA6431FEF52233F6` FOREIGN KEY (`supplier`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `FK_EAA81A4C8D9F6D38` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
