-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 13, 2026 at 09:35 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cpirental`
--

-- --------------------------------------------------------

--
-- Table structure for table `barangay_coordinates`
--

CREATE TABLE `barangay_coordinates` (
  `id` int(11) NOT NULL,
  `barangay` varchar(100) NOT NULL,
  `city` varchar(100) NOT NULL,
  `latitude` decimal(10,6) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `zone_id` int(11) NOT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangay_coordinates`
--

INSERT INTO `barangay_coordinates` (`id`, `barangay`, `city`, `latitude`, `longitude`, `zone_id`, `last_updated`) VALUES
(1, 'Barangay Sikatuna Village', 'Quezon City', 14.676000, 121.043700, 3, '2026-02-13 06:26:21'),
(2, 'Baranggay Sikatuna Village', 'Quezon CIty', 14.676000, 121.043700, 3, '2026-02-13 06:26:40'),
(3, 'Barangay Tuktukan', 'Taguig CIty', 14.517600, 121.050900, 10, '2026-02-13 06:55:29');

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `classification` enum('GOVERNMENT','PRIVATE') NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `main_signatory` varchar(50) NOT NULL,
  `signatory_position` varchar(50) DEFAULT NULL,
  `main_number` varchar(20) NOT NULL,
  `main_address` text NOT NULL,
  `tin_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE','SUSPENDED') DEFAULT 'ACTIVE',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`id`, `classification`, `company_name`, `main_signatory`, `signatory_position`, `main_number`, `main_address`, `tin_number`, `email`, `status`, `created_at`, `updated_at`, `created_by`) VALUES
(1, 'PRIVATE', 'Copieronline Philippines Inc.', 'Aries A. Matias', 'Operations Manager', '09055262659', '165 Kamias Road, Barangay Sikatuna Village, Quezon City, 1101', '007-260-956-000', 'salesmanager@copieronlineph.com', 'ACTIVE', '2026-02-10 21:56:06', '2026-02-10 21:56:06', NULL),
(2, 'GOVERNMENT', 'NATIONAL MUSEUM OF THE PHILIPPINES', 'RESTY D. MORANCIL', 'TWG', '(+63-2) 8298-1100', 'Padre Burgos Avenue, Manila 1000', '', '', 'ACTIVE', '2026-02-10 22:18:02', '2026-02-10 22:18:02', NULL),
(3, 'GOVERNMENT', 'Benigno Ninoy Aquino High School', 'Felix T. Bunagan', 'Principal III', '0977-066-5013', 'Aguho Street, Comembo Makati City', '005-042-424-000', '', 'ACTIVE', '2026-02-10 22:20:06', '2026-02-10 22:20:06', NULL),
(4, 'PRIVATE', 'Accupoll INC.', 'Diana Lyn A. Alambra', 'Research Director', '09296883450', 'Blue Bell Bldg. 102 Kalayaan Ave. Brgy Cental Quezon City', '000-665-090-000-0', '', 'ACTIVE', '2026-02-10 22:21:01', '2026-02-10 22:21:01', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `contracts`
--

CREATE TABLE `contracts` (
  `id` int(11) NOT NULL,
  `contract_number` varchar(50) NOT NULL,
  `contract_start` date DEFAULT NULL,
  `contract_end` date DEFAULT NULL,
  `client_id` int(11) NOT NULL,
  `type_of_contract` enum('UMBRELLA','SINGLE CONTRACT') NOT NULL,
  `has_colored_machines` enum('YES','NO') NOT NULL,
  `mono_rate` decimal(10,2) NOT NULL,
  `color_rate` decimal(10,2) DEFAULT NULL,
  `excess_monorate` decimal(10,2) NOT NULL,
  `excess_colorrate` decimal(10,2) DEFAULT NULL,
  `mincopies_mono` int(11) NOT NULL,
  `mincopies_color` int(11) DEFAULT NULL,
  `spoilage` decimal(5,2) NOT NULL,
  `minimum_monthly_charge` decimal(10,2) DEFAULT NULL,
  `collection_processing_period` int(11) NOT NULL,
  `collection_date` int(11) DEFAULT NULL,
  `vatable` enum('YES','NO') NOT NULL,
  `contract_file` varchar(255) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') DEFAULT 'ACTIVE',
  `datecreated` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `createdby` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contracts`
--

INSERT INTO `contracts` (`id`, `contract_number`, `contract_start`, `contract_end`, `client_id`, `type_of_contract`, `has_colored_machines`, `mono_rate`, `color_rate`, `excess_monorate`, `excess_colorrate`, `mincopies_mono`, `mincopies_color`, `spoilage`, `minimum_monthly_charge`, `collection_processing_period`, `collection_date`, `vatable`, `contract_file`, `status`, `datecreated`, `updated_at`, `createdby`) VALUES
(2, 'RCN-2026-P001-000001', '2025-02-01', '2026-02-28', 1, 'SINGLE CONTRACT', 'NO', 0.56, NULL, 0.75, NULL, 6000, NULL, 2.00, 3360.00, 10, 16, 'YES', 'uploads/contracts/1770831026_omnibus.pdf,uploads/contracts/1770831041_page_3.pdf', 'ACTIVE', '2026-02-11 22:14:42', '2026-02-13 13:38:18', NULL),
(3, 'RCN-2026-G001-000002', '2025-08-01', '2026-03-31', 2, 'UMBRELLA', 'YES', 1.00, 4.00, 1.00, 4.00, 195000, 27300, 2.00, NULL, 30, 15, 'YES', 'uploads/contracts/1770824444_Step Ahead Company Inc_Pantum 7105.pdf,uploads/contracts/1770831079_CATALOGUE.pdf', 'ACTIVE', '2026-02-11 23:40:44', '2026-02-12 01:25:33', NULL),
(4, 'RCN-2026-P002-000003', '2025-01-01', '2025-12-31', 4, 'SINGLE CONTRACT', 'YES', 0.56, 4.40, 0.56, 4.40, 1500, 800, 2.00, NULL, 10, 15, 'YES', 'uploads/contracts/1770831784_Ouotation - Copier Online.pdf', 'ACTIVE', '2026-02-12 01:43:04', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `contract_machines`
--

CREATE TABLE `contract_machines` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `machine_type` enum('MONOCHROME','COLOR') NOT NULL,
  `machine_model` varchar(100) NOT NULL,
  `machine_brand` varchar(100) NOT NULL,
  `machine_serial_number` varchar(100) NOT NULL,
  `machine_number` varchar(100) NOT NULL,
  `mono_meter_start` int(11) NOT NULL,
  `color_meter_start` int(11) DEFAULT NULL,
  `building_number` varchar(50) NOT NULL,
  `street_name` varchar(100) NOT NULL,
  `barangay` varchar(100) NOT NULL,
  `city` varchar(100) NOT NULL,
  `zone_id` int(11) NOT NULL,
  `zone_number` int(11) NOT NULL,
  `area_center` varchar(100) NOT NULL,
  `reading_date` int(11) NOT NULL,
  `reading_date_remarks` varchar(50) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `dr_pos_files` text DEFAULT NULL,
  `dr_pos_file_count` int(11) DEFAULT 0,
  `status` enum('ACTIVE','INACTIVE') DEFAULT 'ACTIVE',
  `datecreated` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `createdby` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contract_machines`
--

INSERT INTO `contract_machines` (`id`, `contract_id`, `client_id`, `department`, `machine_type`, `machine_model`, `machine_brand`, `machine_serial_number`, `machine_number`, `mono_meter_start`, `color_meter_start`, `building_number`, `street_name`, `barangay`, `city`, `zone_id`, `zone_number`, `area_center`, `reading_date`, `reading_date_remarks`, `comments`, `dr_pos_files`, `dr_pos_file_count`, `status`, `datecreated`, `updated_at`, `createdby`) VALUES
(2, 2, 1, 'Sales Department', 'MONOCHROME', 'CANON', 'IR2525', 'MKL5269520', 'CPI2569', 500, NULL, '165', 'Kamias', 'Sikatuna Village', 'Quezon City', 3, 3, 'Quezon City Central (Tandang Sora)', 6, 'aligned reading date', '', NULL, 0, 'ACTIVE', '2026-02-11 22:31:33', '2026-02-13 11:23:20', NULL),
(3, 3, 2, 'Office of the Director General', 'COLOR', 'Sindoh', 'D410', 'NHU52698232', 'CPI-9162', 403987, 178270, '1000', 'Padre Burgos Avenue', 'Barangay 660', 'Manila CIty', 7, 7, 'Manila City (Rizal Park / City Hall)', 9, 'aligned reading date', '', 'uploads/dr_pos/1770832548_698cc2a465a02_RESPONSE_FOR_FINAL_DEMAND_RECONCILED_HO_WITH_RENEWAL_AND_PULLOUT.pdf', 1, 'ACTIVE', '2026-02-12 00:01:54', '2026-02-13 11:22:19', NULL),
(4, 3, 2, 'Board of Trustees Secretariat', 'COLOR', 'Sindoh', 'D410', 'SFT52399532', 'CPI-9212', 107849, 124812, '1000', 'Padre Burgos Avenue', 'Barangay 660', 'Manila CIty', 7, 7, 'Manila City (Rizal Park / City Hall)', 9, 'aligned reading date', '', NULL, 0, 'ACTIVE', '2026-02-12 00:01:54', '2026-02-13 11:22:33', NULL),
(5, 3, 2, 'Office of the Director IIs ', 'COLOR', 'Sindoh', 'D410', 'SFT3698571', 'CPI-9164', 561447, 114935, '1000', 'Padre Burgos Avenue', 'Barangay 660', 'Manila CIty', 7, 7, 'Manila City (Rizal Park / City Hall)', 9, 'aligned reading date', '', NULL, 0, 'ACTIVE', '2026-02-12 00:01:54', '2026-02-13 11:22:42', NULL),
(6, 4, 4, 'IT', 'COLOR', 'KONICA MINOLTA', 'BIZHUB C458', 'LOK5256987', 'CPI9876', 600, 300, '102', 'Kalayaan', 'Barangay Central', 'Quezon CIty', 3, 3, 'Quezon City Central (Tandang Sora)', 5, 'aligned reading date', '', 'uploads/dr_pos/dr_pos_4_1_1770832793_698cc399b560f_01.22.2026_QUOTE_PERF_Copieronline_BIR_Revenue_Reg_5_550_671.00.pdf', 1, 'ACTIVE', '2026-02-12 01:59:53', '2026-02-13 12:55:39', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `zoning_zone`
--

CREATE TABLE `zoning_zone` (
  `id` int(11) NOT NULL,
  `zone_number` int(11) NOT NULL,
  `area_center` varchar(100) NOT NULL,
  `reading_date` int(11) DEFAULT NULL,
  `latitude` decimal(10,6) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `zoning_zone`
--

INSERT INTO `zoning_zone` (`id`, `zone_number`, `area_center`, `reading_date`, `latitude`, `longitude`, `created_at`) VALUES
(1, 1, 'Quezon City North (Fairview Area)', 3, 14.733526, 121.040209, '2026-01-28 15:22:00'),
(2, 2, 'CaMaNaVa Area', 4, 14.664369, 120.961845, '2026-01-28 15:22:00'),
(3, 3, 'Quezon City Central (Tandang Sora)', 5, 14.674216, 121.046854, '2026-01-28 15:22:00'),
(4, 4, 'Quezon City South / East (Diliman)', 6, 14.636491, 121.048407, '2026-01-28 15:22:00'),
(5, 5, 'Pasig / Marikina Area', 7, 14.594300, 121.073470, '2026-01-28 15:22:00'),
(6, 6, 'San Juan Area', 8, 14.604399, 121.032494, '2026-01-28 15:22:00'),
(7, 7, 'Manila City (Rizal Park / City Hall)', 9, 14.592141, 120.980258, '2026-01-28 15:22:00'),
(8, 8, 'Makati City (Ayala Center)', 10, 14.554406, 121.019612, '2026-01-28 15:22:00'),
(9, 9, 'Pasay (NAIA Area)', 11, 14.527321, 120.999184, '2026-01-28 15:22:00'),
(10, 10, 'Taguig (BGC Area)', 12, 14.533303, 121.051541, '2026-01-28 15:22:00'),
(11, 11, 'Parañaque Area', 13, 14.490035, 121.022068, '2026-01-28 15:22:00'),
(12, 12, 'Alabang Area', 14, 14.423069, 121.023166, '2026-01-28 15:22:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `barangay_coordinates`
--
ALTER TABLE `barangay_coordinates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_location` (`barangay`,`city`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_name` (`company_name`),
  ADD KEY `idx_classification` (`classification`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `contract_number` (`contract_number`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `contract_machines`
--
ALTER TABLE `contract_machines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contract_id` (`contract_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `zone_id` (`zone_id`);

--
-- Indexes for table `zoning_zone`
--
ALTER TABLE `zoning_zone`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `barangay_coordinates`
--
ALTER TABLE `barangay_coordinates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `contracts`
--
ALTER TABLE `contracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `contract_machines`
--
ALTER TABLE `contract_machines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `zoning_zone`
--
ALTER TABLE `zoning_zone`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `contracts`
--
ALTER TABLE `contracts`
  ADD CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`);

--
-- Constraints for table `contract_machines`
--
ALTER TABLE `contract_machines`
  ADD CONSTRAINT `contract_machines_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`),
  ADD CONSTRAINT `contract_machines_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  ADD CONSTRAINT `contract_machines_ibfk_3` FOREIGN KEY (`zone_id`) REFERENCES `zoning_zone` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
