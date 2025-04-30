-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1:3308
-- Tiempo de generación: 25-04-2025 a las 08:51:32
-- Versión del servidor: 9.1.0
-- Versión de PHP: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `sistema_de_venta`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `products`
--

DROP TABLE IF EXISTS `products`;
CREATE TABLE IF NOT EXISTS `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `image_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int NOT NULL DEFAULT '0',
  `category_id` int DEFAULT NULL,
  `store_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_products_category` (`category_id`),
  KEY `fk_products_store` (`store_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `products`
--

INSERT INTO `products` (`id`, `name`, `description`, `image_path`, `price`, `stock`, `category_id`, `store_id`, `created_at`, `updated_at`) VALUES
(2, 'Inka cola 600ml', 'Gaseosa', NULL, 3.50, 10, 6, 2, '2025-04-25 05:49:34', '2025-04-25 08:49:26'),
(3, 'chelitas', 'chelas', 'uploads/product_images/product_680b4c3d445fe3.66581170.jpg', 7.90, 19, 1, 2, '2025-04-25 08:47:57', '2025-04-25 08:49:26');

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_products_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
