-- SMNS PHP SQL dump
-- Generated: 2026-02-14T11:20:15+00:00

CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year_name` varchar(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('active','inactive','completed') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `year_name` (`year_name`),
  KEY `idx_year_name` (`year_name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `academic_years` (`id`,`year_name`,`start_date`,`end_date`,`status`,`created_at`,`updated_at`) VALUES ('1','2022/2023','2022-09-01','2023-06-30','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `academic_years` (`id`,`year_name`,`start_date`,`end_date`,`status`,`created_at`,`updated_at`) VALUES ('2','2023/2024','2023-09-01','2024-06-30','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `academic_years` (`id`,`year_name`,`start_date`,`end_date`,`status`,`created_at`,`updated_at`) VALUES ('3','2024/2025','2024-09-01','2025-06-30','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `academic_years` (`id`,`year_name`,`start_date`,`end_date`,`status`,`created_at`,`updated_at`) VALUES ('4','2025/2026','2025-09-01','2026-06-30','inactive','2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_module` (`module`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_activity_user_date` (`user_id`,`created_at`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=196 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('1','1','login','authentication','Admin logged in successfully','192.168.1.100','Mozilla/5.0','2026-02-12 21:04:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('2','11','login','authentication','Student logged in successfully','192.168.1.101','Mozilla/5.0','2026-02-12 21:04:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('3','3','login','authentication','Lecturer logged in successfully','192.168.1.102','Mozilla/5.0','2026-02-12 21:04:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('4','1','create','students','Created new student: STD2024001','192.168.1.100','Mozilla/5.0','2026-02-12 21:04:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('5','9','create','payments','Recorded payment PAY-2025-001','192.168.1.103','Mozilla/5.0','2026-02-12 21:04:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('6','3','submit','results','Submitted results for BTH102','192.168.1.102','Mozilla/5.0','2026-02-12 21:04:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('7','1','approve','results','Approved results for BTH102','192.168.1.100','Mozilla/5.0','2026-02-12 21:04:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('8','1','publish','results','Published results for Semester 1','192.168.1.100','Mozilla/5.0','2026-02-12 21:04:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('9','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:04:49');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('10','1','logout','authentication','User logged out','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:04:49');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('11','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:05:02');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('12','9','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:05:07');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('13','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:06:07');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('14','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:07:24');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('15','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:07:38');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('16','1','logout','authentication','User logged out','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:07:38');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('17','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:08:08');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('18','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:10:22');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('19','11','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:10:39');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('20','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:11:53');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('21','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:14:29');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('22','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:14:39');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('23','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:17:41');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('24','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:19:03');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('25','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:19:34');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('26','11','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:20:15');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('27','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:22:52');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('28','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:25:52');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('29','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:26:39');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('30','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:28:34');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('31','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 21:31:04');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('32','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:09:56');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('33','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:12:05');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('34','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:25:49');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('35','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:25:57');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('36','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:26:06');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('37','11','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:33:31');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('38','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:33:46');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('39','11','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:43:28');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('40','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:48:46');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('41','11','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:55:22');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('42','1','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:55:49');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('43','11','login','authentication','User logged in successfully','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 22:57:14');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('44','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 23:05:55');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('45','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 23:06:13');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('46','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 23:55:14');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('47','10','login','authentication','User logged in to finance module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-12 23:58:11');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('48','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:16:33');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('49','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:16:48');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('50','3','logout','authentication','User logged out from lecturer','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:17:05');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('51','10','logout','authentication','User logged out from finance','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:17:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('52','9','login','authentication','User logged in to finance module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:25:05');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('53','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:29:30');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('54','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:29:35');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('55','9','logout','authentication','User logged out from finance','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:29:53');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('56','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:52:04');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('57','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:52:16');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('58','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:53:55');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('59','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:54:00');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('60','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:56:58');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('61','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 01:57:07');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('62','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:00:51');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('63','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:30:34');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('64','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:33:39');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('65','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:33:43');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('66','10','login','authentication','User logged in to finance module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:34:03');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('67','10','logout','authentication','User logged out from finance','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:34:07');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('68','10','login','authentication','User logged in to finance module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:35:46');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('69','10','logout','authentication','User logged out from finance','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:35:53');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('70','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:37:23');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('71','3','logout','authentication','User logged out from lecturer','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:37:28');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('72','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:38:00');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('73','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 02:38:05');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('74','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 03:02:11');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('75','10','login','authentication','User logged in to finance module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 03:02:34');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('76','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 03:02:46');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('77','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:21:36');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('78','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:22:20');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('79','3','logout','authentication','User logged out from lecturer','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:23:18');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('80','10','login','authentication','User logged in to finance module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:23:59');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('81','10','logout','authentication','User logged out from finance','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:24:42');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('82','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:37:48');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('83','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:38:00');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('84','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:51:59');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('85','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:54:04');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('86','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:57:45');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('87','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:57:50');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('88','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:59:51');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('89','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 09:59:56');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('90','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:01:33');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('91','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:01:54');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('92','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:02:09');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('93','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:05:34');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('94','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:05:38');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('95','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:09:33');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('96','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:09:38');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('97','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:18:30');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('98','3','logout','authentication','User logged out from lecturer','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:19:11');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('99','10','login','authentication','User logged in to finance module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:24:18');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('100','10','logout','authentication','User logged out from finance','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:25:01');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('101','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:25:39');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('102','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:25:43');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('103','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:27:12');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('104','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 10:28:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('105','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 11:26:50');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('106','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 12:10:48');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('107','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 12:19:28');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('108','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 12:19:42');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('109','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 12:59:00');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('110','10','login','authentication','User logged in to finance module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 12:59:29');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('111','3','logout','authentication','User logged out from lecturer','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 13:00:27');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('112','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 13:00:50');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('113','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 13:00:56');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('114','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 13:01:17');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('115','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:08:53');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('116','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:09:07');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('117','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:11:23');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('118','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:11:30');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('119','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:26:48');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('120','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:26:57');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('121','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:27:35');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('122','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:34:04');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('123','3','logout','authentication','User logged out from lecturer','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:41:17');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('124','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:41:38');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('125','3','logout','authentication','User logged out from lecturer','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:41:50');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('126','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:42:08');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('127','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:48:42');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('128','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:53:27');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('129','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:53:47');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('130','3','logout','authentication','User logged out from lecturer','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:53:59');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('131','10','logout','authentication','User logged out from finance','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:54:12');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('132','11','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 15:54:28');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('133','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 18:16:45');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('134','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 18:22:31');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('135','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 18:29:22');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('136','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 18:29:38');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('137','3','logout','authentication','User logged out from lecturer','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 18:34:57');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('138','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 18:35:08');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('139','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 18:35:29');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('140','3','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 18:44:59');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('141','10','login','authentication','User logged in to finance module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 18:48:40');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('142','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 18:52:43');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('143','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 19:13:06');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('144','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 19:40:30');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('145','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 20:00:44');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('146','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 20:53:27');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('147','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 20:53:54');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('148','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 21:15:15');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('149','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 21:15:24');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('150','42','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 23:01:53');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('151','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 23:09:26');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('152','42','login','authentication','User logged in to lecturer module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 23:32:39');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('153','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-13 23:33:35');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('154','39','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:08:52');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('155','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:13:45');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('156','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:17:55');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('157','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:22:15');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('158','39','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:23:44');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('159','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:24:21');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('160','39','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:29:42');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('161','39','logout','authentication','User logged out from student','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:29:47');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('162','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:30:30');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('163','10','login','authentication','User logged in to finance module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:32:39');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('164','10','logout','authentication','User logged out from finance','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:32:51');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('165','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 00:41:10');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('166','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 08:03:17');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('167','1','logout','authentication','User logged out from admin','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 09:40:00');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('168','39','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 09:42:22');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('169','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 09:42:59');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('170','39','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 09:48:30');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('171','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 09:49:13');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('172','39','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 10:43:58');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('173','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 11:01:33');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('174','39','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 12:51:25');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('175','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 12:54:16');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('176','11','login','authentication','User logged in to student module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:29:21');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('177','1','login','authentication','User logged in to admin module','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:29:57');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('178','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:30:41');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('179','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:31:04');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('180','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:35:58');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('181','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:36:15');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('182','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:36:24');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('183','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:36:31');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('184','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:45:13');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('185','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:45:33');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('186','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:51:49');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('187','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:52:32');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('188','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:53:11');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('189','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:53:26');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('190','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:54:14');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('191','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 13:55:26');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('192','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 14:12:09');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('193','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 14:14:20');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('194','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 14:15:19');
INSERT INTO `activity_logs` (`id`,`user_id`,`action`,`module`,`description`,`ip_address`,`user_agent`,`created_at`) VALUES ('195','1','health_check','system','System health check performed','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0','2026-02-14 14:20:08');

CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `admins_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admins` (`id`,`user_id`,`first_name`,`last_name`,`phone`,`email`,`created_at`,`updated_at`) VALUES ('1','1','System','Administrator','+1234567890','admin@seminary.edu','2026-02-12 21:03:50','2026-02-12 21:03:50');
INSERT INTO `admins` (`id`,`user_id`,`first_name`,`last_name`,`phone`,`email`,`created_at`,`updated_at`) VALUES ('2','2','Peter','Anderson','+256700000002','admin2@seminary.edu','2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `target_audience` enum('all','students','lecturers','finance','admin') DEFAULT 'all',
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_target_audience` (`target_audience`),
  KEY `idx_status` (`status`),
  KEY `idx_dates` (`start_date`,`end_date`),
  CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `announcements` (`id`,`title`,`content`,`target_audience`,`priority`,`start_date`,`end_date`,`status`,`created_by`,`created_at`,`updated_at`) VALUES ('1','Welcome to Semester 2, 2024/2025','Welcome back to the new semester! We wish you success in your studies. Please ensure all fees are paid by the deadline.','all','high','2025-01-15','2025-01-31','active','1','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `announcements` (`id`,`title`,`content`,`target_audience`,`priority`,`start_date`,`end_date`,`status`,`created_by`,`created_at`,`updated_at`) VALUES ('2','Library Hours Extended','The library will now be open until 10 PM on weekdays to support your studies during the semester.','students','normal','2025-01-20','2025-06-30','active','1','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `announcements` (`id`,`title`,`content`,`target_audience`,`priority`,`start_date`,`end_date`,`status`,`created_by`,`created_at`,`updated_at`) VALUES ('3','Course Registration Deadline','Reminder: Course registration closes on January 20, 2025. Please complete your registration.','students','urgent','2025-01-10','2025-01-20','active','1','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `announcements` (`id`,`title`,`content`,`target_audience`,`priority`,`start_date`,`end_date`,`status`,`created_by`,`created_at`,`updated_at`) VALUES ('4','Results Submission Reminder','All lecturers are reminded to submit results by December 10, 2025 for timely processing.','lecturers','high','2024-11-01','2024-12-10','inactive','1','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `announcements` (`id`,`title`,`content`,`target_audience`,`priority`,`start_date`,`end_date`,`status`,`created_by`,`created_at`,`updated_at`) VALUES ('5','Fee Payment Deadline','Tuition fees are due by February 15, 2025. Please make arrangements to clear your balance.','students','urgent','2025-02-01','2025-02-15','active','1','2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `course_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lecturer_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `assigned_date` date NOT NULL,
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_assignment` (`lecturer_id`,`course_id`,`semester_id`),
  KEY `idx_lecturer` (`lecturer_id`),
  KEY `idx_course` (`course_id`),
  KEY `idx_semester` (`semester_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `course_assignments_ibfk_1` FOREIGN KEY (`lecturer_id`) REFERENCES `lecturers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_assignments_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_assignments_ibfk_3` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('1','1','3','6','2025-01-10','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('2','1','6','6','2025-01-10','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('3','2','1','6','2025-01-10','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('4','2','14','6','2025-01-10','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('5','3','16','6','2025-01-10','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('6','3','22','6','2025-01-10','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('7','4','5','6','2025-01-10','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('8','4','12','6','2025-01-10','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('9','5','23','6','2025-01-10','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('10','6','8','6','2025-01-10','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('11','1','2','5','2024-09-01','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('12','2','4','5','2024-09-01','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('13','3','7','5','2024-09-01','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('14','4','10','5','2024-09-01','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('15','5','19','5','2024-09-01','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('16','6','20','5','2024-09-01','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('17','6','1','6','2026-02-13','completed','2026-02-13 21:36:04','2026-02-13 21:36:04');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('18','1','5','6','2026-02-13','completed','2026-02-13 21:38:13','2026-02-13 21:38:13');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('19','5','1','6','2026-02-13','completed','2026-02-13 21:41:07','2026-02-13 21:41:07');
INSERT INTO `course_assignments` (`id`,`lecturer_id`,`course_id`,`semester_id`,`assigned_date`,`status`,`created_at`,`updated_at`) VALUES ('20','2','49','6','2026-02-13','active','2026-02-13 22:22:28','2026-02-13 22:22:28');

CREATE TABLE `course_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `registration_date` date NOT NULL,
  `status` enum('pending','approved','rejected','dropped') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_registration` (`student_id`,`course_id`,`semester_id`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_student` (`student_id`),
  KEY `idx_course` (`course_id`),
  KEY `idx_semester` (`semester_id`),
  KEY `idx_status` (`status`),
  KEY `idx_registrations_student_semester` (`student_id`,`semester_id`,`status`),
  CONSTRAINT `course_registrations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_registrations_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_registrations_ibfk_3` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_registrations_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('1','1','3','6','2025-01-08','approved','1','2025-01-09 10:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('2','1','6','6','2025-01-08','approved','1','2025-01-09 10:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('3','1','14','6','2025-01-08','approved','1','2025-01-09 10:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('4','1','16','6','2025-01-08','approved','1','2025-01-09 10:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('5','2','3','6','2025-01-08','approved','1','2025-01-09 10:30:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('6','2','6','6','2025-01-08','approved','1','2025-01-09 10:30:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('7','2','14','6','2025-01-08','approved','1','2025-01-09 10:30:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('8','2','16','6','2025-01-08','approved','1','2025-01-09 10:30:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('9','3','3','6','2025-01-08','approved','1','2025-01-09 11:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('10','3','6','6','2025-01-08','approved','1','2025-01-09 11:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('11','3','14','6','2025-01-08','approved','1','2025-01-09 11:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('12','3','16','6','2025-01-08','approved','1','2025-01-09 11:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('13','1','2','5','2024-08-20','approved','1','2024-08-21 10:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('14','1','4','5','2024-08-20','approved','1','2024-08-21 10:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('15','1','7','5','2024-08-20','approved','1','2024-08-21 10:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('16','1','10','5','2024-08-20','approved','1','2024-08-21 10:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('17','2','2','5','2024-08-20','approved','1','2024-08-21 10:30:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('18','2','4','5','2024-08-20','approved','1','2024-08-21 10:30:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('19','2','7','5','2024-08-20','approved','1','2024-08-21 10:30:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('20','2','10','5','2024-08-20','approved','1','2024-08-21 10:30:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('21','3','2','5','2024-08-20','approved','1','2024-08-21 11:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('22','3','4','5','2024-08-20','approved','1','2024-08-21 11:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('23','3','7','5','2024-08-20','approved','1','2024-08-21 11:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_registrations` (`id`,`student_id`,`course_id`,`semester_id`,`registration_date`,`status`,`approved_by`,`approved_date`,`remarks`,`created_at`,`updated_at`) VALUES ('24','3','10','5','2024-08-20','approved','1','2024-08-21 11:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `course_units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `unit_number` int(11) NOT NULL,
  `unit_title` varchar(200) NOT NULL,
  `unit_description` text DEFAULT NULL,
  `learning_outcomes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_course_id` (`course_id`),
  CONSTRAINT `course_units_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `course_units` (`id`,`course_id`,`unit_number`,`unit_title`,`unit_description`,`learning_outcomes`,`created_at`,`updated_at`) VALUES ('1','1','1','Introduction to the Bible','Overview of biblical structure and composition','Understand the organization and themes of Scripture','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_units` (`id`,`course_id`,`unit_number`,`unit_title`,`unit_description`,`learning_outcomes`,`created_at`,`updated_at`) VALUES ('2','1','2','Methods of Biblical Interpretation','Various approaches to reading and understanding the Bible','Apply basic hermeneutical principles','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_units` (`id`,`course_id`,`unit_number`,`unit_title`,`unit_description`,`learning_outcomes`,`created_at`,`updated_at`) VALUES ('3','1','3','The Biblical Canon','Formation and authority of the biblical books','Explain the development of the biblical canon','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_units` (`id`,`course_id`,`unit_number`,`unit_title`,`unit_description`,`learning_outcomes`,`created_at`,`updated_at`) VALUES ('4','2','1','Pentateuch Overview','Introduction to the first five books of the Bible','Identify key themes in the Pentateuch','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_units` (`id`,`course_id`,`unit_number`,`unit_title`,`unit_description`,`learning_outcomes`,`created_at`,`updated_at`) VALUES ('5','2','2','Historical Books','Survey of Joshua through Esther','Trace Israel\'s history through the biblical narrative','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_units` (`id`,`course_id`,`unit_number`,`unit_title`,`unit_description`,`learning_outcomes`,`created_at`,`updated_at`) VALUES ('6','2','3','Wisdom Literature','Study of Job, Psalms, Proverbs, Ecclesiastes, Song of Songs','Appreciate Hebrew wisdom traditions','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `course_units` (`id`,`course_id`,`unit_number`,`unit_title`,`unit_description`,`learning_outcomes`,`created_at`,`updated_at`) VALUES ('7','2','4','The Prophets','Overview of major and minor prophets','Understand prophetic messages and their contexts','2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_code` varchar(20) NOT NULL,
  `course_name` varchar(200) NOT NULL,
  `credit_hours` int(11) NOT NULL DEFAULT 3,
  `program_id` int(11) NOT NULL,
  `level_year` tinyint(4) DEFAULT 1,
  `semester_offered` tinyint(4) NOT NULL COMMENT '1=Semester 1, 2=Semester 2, 3=Both',
  `prerequisites` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_code` (`course_code`),
  KEY `idx_course_code` (`course_code`),
  KEY `idx_program` (`program_id`),
  KEY `idx_level` (`level_year`),
  KEY `idx_status` (`status`),
  CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('1','BTH101','Introduction to Biblical Studies','3','1','1','1',NULL,'Overview of the Bible structure, themes, and interpretation methods','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('2','BTH102','Old Testament Survey','3','1','1','1',NULL,'Comprehensive study of the Old Testament books','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('3','BTH103','New Testament Survey','3','1','1','2',NULL,'Comprehensive study of the New Testament books','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('4','BTH104','Christian Theology I','3','1','1','1',NULL,'Introduction to systematic theology and doctrine','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('5','BTH105','Church History I','3','1','1','2',NULL,'Early church through medieval period','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('6','BTH106','Hermeneutics','3','1','1','2','BTH101','Principles of biblical interpretation','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('7','BTH107','Greek I','3','1','1','1',NULL,'Introduction to Biblical Greek','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('8','BTH108','Hebrew I','3','1','1','2',NULL,'Introduction to Biblical Hebrew','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('9','BTH201','Christian Theology II','3','1','2','1','BTH104','Advanced systematic theology','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('10','BTH202','Church History II','3','1','2','1','BTH105','Reformation to modern era','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('11','BTH203','Pentateuch','3','1','2','1','BTH102','Detailed study of the first five books of the Bible','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('12','BTH204','Gospels','3','1','2','2','BTH103','Synoptic Gospels and John','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('13','BTH205','Greek II','3','1','2','1','BTH107','Intermediate Biblical Greek','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('14','BTH206','Hebrew II','3','1','2','2','BTH108','Intermediate Biblical Hebrew','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('15','BTH207','Christian Ethics','3','1','2','2',NULL,'Moral theology and ethical decision making','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('16','BTH208','Philosophy of Religion','3','1','2','1',NULL,'Philosophical approaches to religious belief','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('17','BTH301','Pauline Epistles','3','1','3','1','BTH204','Study of Paul\'s letters','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('18','BTH302','Prophetic Literature','3','1','3','1','BTH203','Major and minor prophets','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('19','BTH303','Systematic Theology','3','1','3','2','BTH201','Advanced doctrinal studies','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('20','BTH304','World Religions','3','1','3','1',NULL,'Comparative study of major world religions','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('21','BTH305','Homiletics','3','1','3','2',NULL,'Principles of sermon preparation and delivery','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('22','BTH306','Pastoral Care','3','1','3','2',NULL,'Ministry of counseling and care','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('23','BTH307','Missiology','3','1','3','1',NULL,'Theology and practice of missions','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('24','BTH308','Apologetics','3','1','3','2','BTH208','Defense of the Christian faith','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('25','BTH401','Advanced Hermeneutics','3','1','4','1','BTH106','Advanced interpretive methods','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('26','BTH402','Contemporary Theology','3','1','4','1','BTH303','Modern theological movements','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('27','BTH403','Research Methods','3','1','4','1',NULL,'Academic research and writing','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('28','BTH404','Senior Thesis','6','1','4','2','BTH403','Independent research project','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('29','BTH405','Ecclesiology','3','1','4','1',NULL,'Doctrine of the church','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('30','BTH406','Eschatology','3','1','4','2','BTH303','Study of end times theology','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('31','MDIV501','Advanced Biblical Exegesis','3','3','1','1',NULL,'In-depth biblical interpretation','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('32','MDIV502','Pastoral Leadership','3','3','1','1',NULL,'Church leadership and administration','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('33','MDIV503','Advanced Homiletics','3','3','1','2',NULL,'Advanced preaching techniques','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('34','MDIV504','Spiritual Formation','3','3','1','2',NULL,'Personal and congregational spiritual growth','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('35','MDIV505','Church Planting','3','3','2','1',NULL,'Principles of starting new churches','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('36','MDIV506','Advanced Pastoral Care','3','3','2','1',NULL,'Clinical pastoral counseling','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('37','MDIV507','Liturgy and Worship','3','3','2','2',NULL,'Theology and practice of worship','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('38','MDIV508','Field Education','6','3','2','2',NULL,'Supervised ministry practicum','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('39','DPT101','Bible Survey','3','4','1','1',NULL,'Overview of the entire Bible','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('40','DPT102','Basic Theology','3','4','1','1',NULL,'Foundational Christian doctrines','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('41','DPT103','Ministry Skills','3','4','1','2',NULL,'Practical ministry competencies','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('42','DPT104','Pastoral Ministry','3','4','1','2',NULL,'Introduction to pastoral work','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('43','DPT201','Preaching Basics','3','4','2','1',NULL,'Introduction to preaching','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('44','DPT202','Church Administration','3','4','2','1',NULL,'Managing church operations','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('45','DPT203','Evangelism','3','4','2','2',NULL,'Sharing the Gospel effectively','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('46','DPT204','Ministry Practicum','3','4','2','2',NULL,'Hands-on ministry experience','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('47','THT034','SCIENCE','2','5','2','2','','','active','2026-02-13 22:06:29','2026-02-13 22:06:29');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('48','THB034','SCIENCE','2','5','2','2','','','active','2026-02-13 22:08:59','2026-02-13 22:08:59');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('49','KHB034','SCIENCE','2','5','2','2','','','active','2026-02-13 22:09:27','2026-02-13 22:09:27');
INSERT INTO `courses` (`id`,`course_code`,`course_name`,`credit_hours`,`program_id`,`level_year`,`semester_offered`,`prerequisites`,`description`,`status`,`created_at`,`updated_at`) VALUES ('50','LHB034','SCIENCE','2','5','2','2','','','active','2026-02-13 22:14:46','2026-02-13 22:14:46');

CREATE TABLE `email_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_name` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `variables` text DEFAULT NULL COMMENT 'JSON array of available variables',
  `category` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `template_name` (`template_name`),
  KEY `idx_template_name` (`template_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `email_templates` (`id`,`template_name`,`subject`,`body`,`variables`,`category`,`created_at`,`updated_at`) VALUES ('1','welcome_email','Welcome to {{institution_name}}','Dear {{full_name}},\r\n\r\nWelcome to {{institution_name}}! Your account has been created successfully.\r\n\r\nYour login credentials are:\r\nUsername: {{username}}\r\nPassword: {{password}}\r\n\r\nPlease login and change your password immediately.\r\n\r\nBest regards,\r\n{{institution_name}} Administration','[\"institution_name\", \"full_name\", \"username\", \"password\"]','authentication','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `email_templates` (`id`,`template_name`,`subject`,`body`,`variables`,`category`,`created_at`,`updated_at`) VALUES ('2','password_reset','Password Reset Request','Dear {{full_name}},\r\n\r\nYou have requested to reset your password. Click the link below to reset your password:\r\n\r\n{{reset_link}}\r\n\r\nThis link will expire in 1 hour. If you did not request this, please ignore this email.\r\n\r\nBest regards,\r\n{{institution_name}} Administration','[\"full_name\", \"reset_link\", \"institution_name\"]','authentication','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `email_templates` (`id`,`template_name`,`subject`,`body`,`variables`,`category`,`created_at`,`updated_at`) VALUES ('3','results_published','Semester Results Published','Dear {{student_name}},\r\n\r\nYour results for {{semester_name}}, {{academic_year}} have been published.\r\n\r\nSemester GPA: {{semester_gpa}}\r\nCumulative GPA: {{cumulative_gpa}}\r\n\r\nLogin to view your complete results.\r\n\r\nBest regards,\r\n{{institution_name}} Administration','[\"student_name\", \"semester_name\", \"academic_year\", \"semester_gpa\", \"cumulative_gpa\", \"institution_name\"]','academic','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `email_templates` (`id`,`template_name`,`subject`,`body`,`variables`,`category`,`created_at`,`updated_at`) VALUES ('4','payment_receipt','Payment Receipt','Dear {{student_name}},\r\n\r\nThank you for your payment.\r\n\r\nReceipt Number: {{receipt_number}}\r\nAmount Paid: {{amount}}\r\nPayment Date: {{payment_date}}\r\nPayment Method: {{payment_method}}\r\nCurrent Balance: {{balance}}\r\n\r\nBest regards,\r\n{{institution_name}} Finance Department','[\"student_name\", \"receipt_number\", \"amount\", \"payment_date\", \"payment_method\", \"balance\", \"institution_name\"]','finance','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `email_templates` (`id`,`template_name`,`subject`,`body`,`variables`,`category`,`created_at`,`updated_at`) VALUES ('5','fee_reminder','Fee Payment Reminder','Dear {{student_name}},\r\n\r\nThis is a reminder that you have an outstanding balance of {{balance}} for {{semester_name}}.\r\n\r\nDue Date: {{due_date}}\r\n\r\nPlease make payment at your earliest convenience to avoid any inconvenience.\r\n\r\nBest regards,\r\n{{institution_name}} Finance Department','[\"student_name\", \"balance\", \"semester_name\", \"due_date\", \"institution_name\"]','finance','2026-02-12 21:03:49','2026-02-12 21:03:49');

CREATE TABLE `fees_structure` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fee_name` varchar(200) NOT NULL,
  `fee_type` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `program_id` int(11) DEFAULT NULL,
  `level_year` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `mandatory` enum('yes','no') DEFAULT 'yes',
  `due_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_program` (`program_id`),
  KEY `idx_semester` (`semester_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fees_structure_ibfk_1` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fees_structure_ibfk_2` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('1','Tuition Fee - BTH Year 1','Tuition','1500000.00','1','1','6','yes','2025-02-15','Semester tuition for Bachelor of Theology Year 1','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('2','Tuition Fee - BTH Year 2','Tuition','1500000.00','1','2','6','yes','2025-02-15','Semester tuition for Bachelor of Theology Year 2','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('3','Tuition Fee - BTH Year 3','Tuition','1500000.00','1','3','6','yes','2025-02-15','Semester tuition for Bachelor of Theology Year 3','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('4','Tuition Fee - BTH Year 4','Tuition','1500000.00','1','4','6','yes','2025-02-15','Semester tuition for Bachelor of Theology Year 4','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('5','Tuition Fee - MDIV Year 1','Tuition','2000000.00','3','1','6','yes','2025-02-15','Semester tuition for Master of Divinity Year 1','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('6','Tuition Fee - MDIV Year 2','Tuition','2000000.00','3','2','6','yes','2025-02-15','Semester tuition for Master of Divinity Year 2','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('7','Tuition Fee - DPT Year 1','Tuition','1000000.00','4','1','6','yes','2025-02-15','Semester tuition for Diploma Year 1','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('8','Tuition Fee - DPT Year 2','Tuition','1000000.00','4','2','6','yes','2025-02-15','Semester tuition for Diploma Year 2','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('9','Library Fee','Library','50000.00',NULL,NULL,'6','yes','2025-02-15','Library access and services','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('10','Medical Fee','Medical','100000.00',NULL,NULL,'6','yes','2025-02-15','Medical services and health insurance','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('11','IT Fee','Technology','75000.00',NULL,NULL,'6','yes','2025-02-15','Computer lab and internet access','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('12','Registration Fee','Administrative','150000.00',NULL,NULL,'6','yes','2025-01-31','Semester registration fee','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('13','Student Activities Fee','Activities','50000.00',NULL,NULL,'6','no','2025-02-28','Student clubs and activities','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `fees_structure` (`id`,`fee_name`,`fee_type`,`amount`,`program_id`,`level_year`,`semester_id`,`mandatory`,`due_date`,`description`,`status`,`created_at`,`updated_at`) VALUES ('14','Exam Fee','Examination','100000.00',NULL,NULL,'6','yes','2025-05-15','Examination administration','active','2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `finance_staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `finance_staff_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `finance_staff` (`id`,`user_id`,`first_name`,`last_name`,`phone`,`email`,`created_at`,`updated_at`) VALUES ('1','9','Grace','Nakato','+256700000201','finance1@seminary.edu','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `finance_staff` (`id`,`user_id`,`first_name`,`last_name`,`phone`,`email`,`created_at`,`updated_at`) VALUES ('2','10','Samuel','Musoke','+256700000202','finance2@seminary.edu','2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_letter` varchar(5) NOT NULL,
  `min_mark` decimal(5,2) NOT NULL,
  `max_mark` decimal(5,2) NOT NULL,
  `grade_point` decimal(3,2) NOT NULL,
  `description` varchar(100) DEFAULT NULL,
  `pass_status` enum('pass','fail') NOT NULL DEFAULT 'pass',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_grade_letter` (`grade_letter`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `grades` (`id`,`grade_letter`,`min_mark`,`max_mark`,`grade_point`,`description`,`pass_status`,`created_at`,`updated_at`) VALUES ('1','A','80.00','100.00','4.00','Excellent','pass','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `grades` (`id`,`grade_letter`,`min_mark`,`max_mark`,`grade_point`,`description`,`pass_status`,`created_at`,`updated_at`) VALUES ('2','B+','75.00','79.99','3.50','Very Good','pass','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `grades` (`id`,`grade_letter`,`min_mark`,`max_mark`,`grade_point`,`description`,`pass_status`,`created_at`,`updated_at`) VALUES ('3','B','70.00','74.99','3.00','Good','pass','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `grades` (`id`,`grade_letter`,`min_mark`,`max_mark`,`grade_point`,`description`,`pass_status`,`created_at`,`updated_at`) VALUES ('4','C+','65.00','69.99','2.50','Above Average','pass','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `grades` (`id`,`grade_letter`,`min_mark`,`max_mark`,`grade_point`,`description`,`pass_status`,`created_at`,`updated_at`) VALUES ('5','C','60.00','64.99','2.00','Average','pass','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `grades` (`id`,`grade_letter`,`min_mark`,`max_mark`,`grade_point`,`description`,`pass_status`,`created_at`,`updated_at`) VALUES ('6','D+','55.00','59.99','1.50','Below Average','pass','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `grades` (`id`,`grade_letter`,`min_mark`,`max_mark`,`grade_point`,`description`,`pass_status`,`created_at`,`updated_at`) VALUES ('7','D','50.00','54.99','1.00','Pass','pass','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `grades` (`id`,`grade_letter`,`min_mark`,`max_mark`,`grade_point`,`description`,`pass_status`,`created_at`,`updated_at`) VALUES ('8','F','0.00','49.99','0.00','Fail','fail','2026-02-12 21:03:49','2026-02-12 21:03:49');

CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `fee_structure_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fee_structure_id` (`fee_structure_id`),
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_items_ibfk_2` FOREIGN KEY (`fee_structure_id`) REFERENCES `fees_structure` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `invoice_items` (`id`,`invoice_id`,`fee_structure_id`,`description`,`amount`,`created_at`) VALUES ('1','1','2','Tuition Fee - BTH Year 2','1500000.00','2026-02-12 21:04:19');
INSERT INTO `invoice_items` (`id`,`invoice_id`,`fee_structure_id`,`description`,`amount`,`created_at`) VALUES ('2','1','9','Library Fee','50000.00','2026-02-12 21:04:19');
INSERT INTO `invoice_items` (`id`,`invoice_id`,`fee_structure_id`,`description`,`amount`,`created_at`) VALUES ('3','1','10','Medical Fee','100000.00','2026-02-12 21:04:19');
INSERT INTO `invoice_items` (`id`,`invoice_id`,`fee_structure_id`,`description`,`amount`,`created_at`) VALUES ('4','1','11','IT Fee','75000.00','2026-02-12 21:04:19');
INSERT INTO `invoice_items` (`id`,`invoice_id`,`fee_structure_id`,`description`,`amount`,`created_at`) VALUES ('5','1','12','Registration Fee','150000.00','2026-02-12 21:04:19');
INSERT INTO `invoice_items` (`id`,`invoice_id`,`fee_structure_id`,`description`,`amount`,`created_at`) VALUES ('6','1','14','Exam Fee','100000.00','2026-02-12 21:04:19');

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `student_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','partial','paid','overdue') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `idx_invoice_number` (`invoice_number`),
  KEY `idx_student` (`student_id`),
  KEY `idx_semester` (`semester_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('1','INV-2025-001','1','6','2025000.00','1500000.00','525000.00','2025-02-15','partial','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('2','INV-2025-002','2','6','2025000.00','2025000.00','0.00','2025-02-15','paid','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('3','INV-2025-003','3','6','2025000.00','1000000.00','1025000.00','2025-02-15','partial','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('4','INV-2025-004','29','6','2025000.00','0.00','2025000.00','2025-02-15','pending','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('5','INV-2025-005','30','6','2025000.00','500000.00','1525000.00','2025-02-15','partial','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('6','INV-2025-006','4','6','2025000.00','2025000.00','0.00','2025-02-15','paid','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('7','INV-2025-007','5','6','2025000.00','1000000.00','1025000.00','2025-02-15','partial','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('8','INV-2025-008','10','6','2025000.00','0.00','2025000.00','2025-02-15','pending','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('9','INV-2025-009','6','6','2025000.00','2025000.00','0.00','2025-02-15','paid','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('10','INV-2025-010','7','6','2025000.00','1500000.00','525000.00','2025-02-15','partial','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('11','INV-2025-011','8','6','2025000.00','2025000.00','0.00','2025-02-15','paid','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('12','INV-2025-012','9','6','2025000.00','1200000.00','825000.00','2025-02-15','partial','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('13','INV-2025-013','21','6','2475000.00','2000000.00','475000.00','2025-02-15','partial','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('14','INV-2025-014','22','6','2475000.00','2475000.00','0.00','2025-02-15','paid','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('15','INV-2025-015','23','6','2475000.00','1500000.00','975000.00','2025-02-15','partial','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('16','INV-2025-016','24','6','2475000.00','0.00','2475000.00','2025-02-15','pending','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('17','INV-2025-017','25','6','1525000.00','1525000.00','0.00','2025-02-15','paid','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('18','INV-2025-018','26','6','1525000.00','800000.00','725000.00','2025-02-15','partial','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('19','INV-2025-019','27','6','1525000.00','1525000.00','0.00','2025-02-15','paid','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `invoices` (`id`,`invoice_number`,`student_id`,`semester_id`,`total_amount`,`amount_paid`,`balance`,`due_date`,`status`,`created_at`,`updated_at`) VALUES ('20','INV-2025-020','28','6','1525000.00','500000.00','1025000.00','2025-02-15','partial','2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `lecturers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `lecturer_id` varchar(20) NOT NULL,
  `title` varchar(20) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `qualifications` text DEFAULT NULL,
  `specialization` varchar(200) DEFAULT NULL,
  `office_location` varchar(100) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','retired') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `national_id` varchar(50) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `employment_type` varchar(50) DEFAULT 'Full-time',
  `employment_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `lecturer_id` (`lecturer_id`),
  KEY `idx_lecturer_id` (`lecturer_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `lecturers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `lecturers` (`id`,`user_id`,`lecturer_id`,`title`,`first_name`,`middle_name`,`gender`,`date_of_birth`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`created_at`,`updated_at`,`national_id`,`designation`,`employment_type`,`employment_date`) VALUES ('1','3','LEC001',NULL,'Robert','James',NULL,NULL,'Johnson','+256700000101','johnson@seminary.edu','Theology','PhD in Biblical Studies','Old Testament','Office 101',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19',NULL,NULL,'Full-time',NULL);
INSERT INTO `lecturers` (`id`,`user_id`,`lecturer_id`,`title`,`first_name`,`middle_name`,`gender`,`date_of_birth`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`created_at`,`updated_at`,`national_id`,`designation`,`employment_type`,`employment_date`) VALUES ('2','4','LEC002',NULL,'Margaret','Anne',NULL,NULL,'Williams','+256700000102','williams@seminary.edu','Theology','PhD in Systematic Theology','Systematic Theology','Office 102',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19',NULL,NULL,'Full-time',NULL);
INSERT INTO `lecturers` (`id`,`user_id`,`lecturer_id`,`title`,`first_name`,`middle_name`,`gender`,`date_of_birth`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`created_at`,`updated_at`,`national_id`,`designation`,`employment_type`,`employment_date`) VALUES ('3','5','LEC003',NULL,'Daniel','Paul',NULL,NULL,'Brown','+256700000103','brown@seminary.edu','Pastoral Studies','MDiv, PhD in Pastoral Care','Pastoral Counseling','Office 103',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19',NULL,NULL,'Full-time',NULL);
INSERT INTO `lecturers` (`id`,`user_id`,`lecturer_id`,`title`,`first_name`,`middle_name`,`gender`,`date_of_birth`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`created_at`,`updated_at`,`national_id`,`designation`,`employment_type`,`employment_date`) VALUES ('4','6','LEC004',NULL,'Elizabeth','Grace',NULL,NULL,'Davis','+256700000104','davis@seminary.edu','Theology','PhD in Church History','Church History','Office 104',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19',NULL,NULL,'Full-time',NULL);
INSERT INTO `lecturers` (`id`,`user_id`,`lecturer_id`,`title`,`first_name`,`middle_name`,`gender`,`date_of_birth`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`created_at`,`updated_at`,`national_id`,`designation`,`employment_type`,`employment_date`) VALUES ('5','7','LEC005',NULL,'Matthew','John',NULL,NULL,'Miller','+256700000105','miller@seminary.edu','Ministry','PhD in Missiology','Missions and Evangelism','Office 105',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19',NULL,NULL,'Full-time',NULL);
INSERT INTO `lecturers` (`id`,`user_id`,`lecturer_id`,`title`,`first_name`,`middle_name`,`gender`,`date_of_birth`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`created_at`,`updated_at`,`national_id`,`designation`,`employment_type`,`employment_date`) VALUES ('6','8','LEC006',NULL,'Rebecca','Faith',NULL,NULL,'Wilson','+256700000106','wilson@seminary.edu','Theology','PhD in New Testament','New Testament Studies','Office 106',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19',NULL,NULL,'Full-time',NULL);
INSERT INTO `lecturers` (`id`,`user_id`,`lecturer_id`,`title`,`first_name`,`middle_name`,`gender`,`date_of_birth`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`created_at`,`updated_at`,`national_id`,`designation`,`employment_type`,`employment_date`) VALUES ('7','42','LEC-2026-001','','BARIMUSI','','Male',NULL,'GERALD','','gera@insitution.edu','SCI','Bachelor&#039;s Degree','','','uploads/lecturers/LEC-2026-001.png','active','2026-02-13 22:52:46','2026-02-13 22:52:46','','Assistant Lecturer','Full-time','2026-02-13');

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','error') DEFAULT 'info',
  `read_status` enum('read','unread') DEFAULT 'unread',
  `link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_read_status` (`read_status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `notifications` (`id`,`user_id`,`title`,`message`,`type`,`read_status`,`link`,`created_at`,`read_at`) VALUES ('1','11','Results Published','Your results for Semester 1, 2024/2025 have been published. Login to view.','success','read','/student/results.php','2026-02-12 21:04:19','2026-02-13 00:26:23');
INSERT INTO `notifications` (`id`,`user_id`,`title`,`message`,`type`,`read_status`,`link`,`created_at`,`read_at`) VALUES ('2','11','Payment Received','Your payment of UGX 1,000,000 has been received. Receipt: RCP-2025-001','success','read','/student/fees.php','2026-02-12 21:04:19',NULL);
INSERT INTO `notifications` (`id`,`user_id`,`title`,`message`,`type`,`read_status`,`link`,`created_at`,`read_at`) VALUES ('3','12','Results Published','Your results for Semester 1, 2024/2025 have been published. Login to view.','success','read','/student/results.php','2026-02-12 21:04:19',NULL);
INSERT INTO `notifications` (`id`,`user_id`,`title`,`message`,`type`,`read_status`,`link`,`created_at`,`read_at`) VALUES ('4','3','Results Approved','Your submitted results for BTH102 have been approved.','success','read','/lecturer/results.php','2026-02-12 21:04:19',NULL);
INSERT INTO `notifications` (`id`,`user_id`,`title`,`message`,`type`,`read_status`,`link`,`created_at`,`read_at`) VALUES ('5','1','Results Submitted','Prof. Johnson has submitted results for BTH102 for approval.','info','read','/admin/results.php','2026-02-12 21:04:19',NULL);
INSERT INTO `notifications` (`id`,`user_id`,`title`,`message`,`type`,`read_status`,`link`,`created_at`,`read_at`) VALUES ('6','11','Fee Reminder','You have an outstanding balance of UGX 525,000. Please make payment by February 15, 2025.','warning','read','/student/fees.php','2026-02-12 21:04:19','2026-02-13 00:26:23');

CREATE TABLE `password_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `password_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `password_history` (`id`,`user_id`,`password_hash`,`created_at`) VALUES ('1','11','$2y$10$gNU6LOfA3SRt41AsnXlRrucrnpOW69ljrfFo5P/TGVA/FQzvEG7Va','2026-02-13 13:00:13');
INSERT INTO `password_history` (`id`,`user_id`,`password_hash`,`created_at`) VALUES ('2','39','$2y$10$1.FPW4NiOtwfQIcHgJWoq.RH/OsgXRYE5/hLO3qyViI4a8eX4mdy.','2026-02-14 00:11:03');
INSERT INTO `password_history` (`id`,`user_id`,`password_hash`,`created_at`) VALUES ('3','39','$2y$10$US/1E5khyBr.aGax07HuC.njsi9s4gaQxW.4wDZFtKM1kuuUl6RQm','2026-02-14 00:12:43');

CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` varchar(50) NOT NULL,
  `student_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','bank_transfer','mobile_money','cheque','card') NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `received_by` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_id` (`payment_id`),
  UNIQUE KEY `receipt_number` (`receipt_number`),
  KEY `invoice_id` (`invoice_id`),
  KEY `received_by` (`received_by`),
  KEY `idx_payment_id` (`payment_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_semester` (`semester_id`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_payments_student_date` (`student_id`,`payment_date`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`received_by`) REFERENCES `finance_staff` (`id`),
  CONSTRAINT `payments_ibfk_4` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('1','PAY-2025-001','1','1','1000000.00','2025-01-20','bank_transfer','BNK123456','1','6','First installment','RCP-2025-001','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('2','PAY-2025-002','1','1','500000.00','2025-02-05','mobile_money','MM789012','1','6','Second installment','RCP-2025-002','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('3','PAY-2025-003','2','2','2025000.00','2025-01-18','bank_transfer','BNK234567','1','6','Full payment','RCP-2025-003','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('4','PAY-2025-004','3','3','1000000.00','2025-01-25','cash',NULL,'1','6','Partial payment','RCP-2025-004','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('5','PAY-2025-005','30','5','500000.00','2025-02-01','mobile_money','MM345678','2','6','Initial payment','RCP-2025-005','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('6','PAY-2025-006','4','6','2025000.00','2025-01-15','bank_transfer','BNK345678','1','6','Full payment','RCP-2025-006','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('7','PAY-2025-007','5','7','1000000.00','2025-01-22','bank_transfer','BNK456789','2','6','Partial payment','RCP-2025-007','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('8','PAY-2025-008','6','9','2025000.00','2025-01-17','bank_transfer','BNK567890','1','6','Full payment','RCP-2025-008','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('9','PAY-2025-009','7','10','1500000.00','2025-01-28','mobile_money','MM456789','2','6','Partial payment','RCP-2025-009','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('10','PAY-2025-010','8','11','2025000.00','2025-01-16','bank_transfer','BNK678901','1','6','Full payment','RCP-2025-010','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('11','PAY-2025-011','9','12','1200000.00','2025-01-30','cash',NULL,'2','6','Partial payment','RCP-2025-011','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('12','PAY-2025-012','21','13','2000000.00','2025-01-19','bank_transfer','BNK789012','1','6','Partial payment','RCP-2025-012','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('13','PAY-2025-013','22','14','2475000.00','2025-01-14','bank_transfer','BNK890123','1','6','Full payment','RCP-2025-013','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('14','PAY-2025-014','23','15','1500000.00','2025-01-26','mobile_money','MM567890','2','6','Partial payment','RCP-2025-014','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('15','PAY-2025-015','25','17','1525000.00','2025-01-21','bank_transfer','BNK901234','1','6','Full payment','RCP-2025-015','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('16','PAY-2025-016','26','18','800000.00','2025-01-29','cash',NULL,'2','6','Partial payment','RCP-2025-016','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('17','PAY-2025-017','27','19','1525000.00','2025-01-23','bank_transfer','BNK012345','1','6','Full payment','RCP-2025-017','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `payments` (`id`,`payment_id`,`student_id`,`invoice_id`,`amount`,`payment_date`,`payment_method`,`reference_number`,`received_by`,`semester_id`,`notes`,`receipt_number`,`created_at`,`updated_at`) VALUES ('18','PAY-2025-018','28','20','500000.00','2025-02-02','mobile_money','MM678901','2','6','Partial payment','RCP-2025-018','2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_name` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_name` (`permission_name`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('1','view_own_profile','student','read','View own student profile','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('2','edit_own_profile','student','update','Edit own contact information','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('3','view_own_results','results','read','View own academic results','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('4','view_own_fees','finance','read','View own fees and payments','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('5','register_courses','courses','create','Register for courses','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('6','view_assigned_courses','courses','read','View assigned courses','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('7','view_class_list','students','read','View students in assigned courses','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('8','enter_results','results','create','Enter student results','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('9','submit_results','results','update','Submit results for approval','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('10','view_submitted_results','results','read','View submitted results','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('11','view_all_payments','finance','read','View all payment records','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('12','record_payment','finance','create','Record student payments','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('13','generate_invoices','finance','create','Generate student invoices','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('14','view_financial_reports','reports','read','View financial reports','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('15','manage_students','students','all','Full student management','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('16','manage_lecturers','lecturers','all','Full lecturer management','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('17','manage_courses','courses','all','Full course management','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('18','manage_results','results','all','Full results management','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('19','manage_users','users','all','Full user management','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('20','manage_settings','settings','all','Manage system settings','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('21','view_all_reports','reports','read','View all system reports','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('22','approve_results','results','approve','Approve submitted results','2026-02-12 21:04:19');
INSERT INTO `permissions` (`id`,`permission_name`,`module`,`action`,`description`,`created_at`) VALUES ('23','publish_results','results','publish','Publish approved results','2026-02-12 21:04:19');

CREATE TABLE `programs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_code` varchar(20) NOT NULL,
  `program_name` varchar(200) NOT NULL,
  `department` varchar(100) NOT NULL,
  `duration_years` int(11) NOT NULL DEFAULT 3,
  `total_credits_required` int(11) NOT NULL DEFAULT 120,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `program_code` (`program_code`),
  KEY `idx_program_code` (`program_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `programs` (`id`,`program_code`,`program_name`,`department`,`duration_years`,`total_credits_required`,`description`,`status`,`created_at`,`updated_at`) VALUES ('1','BTH','Bachelor of Theology','Theology','4','120','Four-year undergraduate program in Biblical and Theological Studies','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `programs` (`id`,`program_code`,`program_name`,`department`,`duration_years`,`total_credits_required`,`description`,`status`,`created_at`,`updated_at`) VALUES ('2','MTH','Master of Theology','Theology','2','60','Two-year graduate program in advanced theological studies','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `programs` (`id`,`program_code`,`program_name`,`department`,`duration_years`,`total_credits_required`,`description`,`status`,`created_at`,`updated_at`) VALUES ('3','MDIV','Master of Divinity','Divinity','3','90','Three-year professional ministry preparation program','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `programs` (`id`,`program_code`,`program_name`,`department`,`duration_years`,`total_credits_required`,`description`,`status`,`created_at`,`updated_at`) VALUES ('4','DPT','Diploma in Pastoral Theology','Pastoral Studies','2','60','Two-year diploma program in pastoral ministry','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `programs` (`id`,`program_code`,`program_name`,`department`,`duration_years`,`total_credits_required`,`description`,`status`,`created_at`,`updated_at`) VALUES ('5','CMS','Certificate in Ministry Studies','Ministry','1','30','One-year certificate program in basic ministry skills','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `programs` (`id`,`program_code`,`program_name`,`department`,`duration_years`,`total_credits_required`,`description`,`status`,`created_at`,`updated_at`) VALUES ('6','PHD','Doctor of Philosophy in Theology','Theology','4','80','Four-year doctoral research program','active','2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `assignment_marks` decimal(5,2) DEFAULT 0.00,
  `test_marks` decimal(5,2) DEFAULT 0.00,
  `midterm_marks` decimal(5,2) DEFAULT 0.00,
  `final_exam_marks` decimal(5,2) DEFAULT 0.00,
  `participation_marks` decimal(5,2) DEFAULT 0.00,
  `total_marks` decimal(5,2) DEFAULT 0.00,
  `grade` varchar(5) DEFAULT NULL,
  `grade_points` decimal(3,2) DEFAULT NULL,
  `status` enum('draft','submitted','approved','published') DEFAULT 'draft',
  `entered_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `submitted_date` datetime DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `published_date` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_result` (`student_id`,`course_id`,`semester_id`),
  KEY `entered_by` (`entered_by`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_student` (`student_id`),
  KEY `idx_course` (`course_id`),
  KEY `idx_semester` (`semester_id`),
  KEY `idx_status` (`status`),
  KEY `idx_grade` (`grade`),
  KEY `idx_results_student_semester` (`student_id`,`semester_id`,`status`),
  CONSTRAINT `results_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `results_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `results_ibfk_3` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `results_ibfk_4` FOREIGN KEY (`entered_by`) REFERENCES `lecturers` (`id`),
  CONSTRAINT `results_ibfk_5` FOREIGN KEY (`approved_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('1','1','2','5','18.00','15.00','22.00','68.00','5.00','128.00',NULL,NULL,'published','1','1','2024-12-10 14:00:00','2024-12-12 10:00:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('2','1','4','5','20.00','18.00','24.00','72.00','5.00','139.00',NULL,NULL,'published','2','1','2024-12-10 15:00:00','2024-12-12 10:30:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('3','1','7','5','16.00','14.00','20.00','62.00','4.00','116.00',NULL,NULL,'published','3','1','2024-12-11 14:00:00','2024-12-12 11:00:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('4','1','10','5','19.00','17.00','23.00','70.00','5.00','134.00',NULL,NULL,'published','4','1','2024-12-11 15:00:00','2024-12-12 11:30:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('5','2','2','5','19.00','18.00','24.00','75.00','5.00','141.00',NULL,NULL,'published','1','1','2024-12-10 14:00:00','2024-12-12 10:00:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('6','2','4','5','20.00','19.00','25.00','78.00','5.00','147.00',NULL,NULL,'published','2','1','2024-12-10 15:00:00','2024-12-12 10:30:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('7','2','7','5','17.00','16.00','22.00','68.00','5.00','128.00',NULL,NULL,'published','3','1','2024-12-11 14:00:00','2024-12-12 11:00:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('8','2','10','5','20.00','18.00','24.00','74.00','5.00','141.00',NULL,NULL,'published','4','1','2024-12-11 15:00:00','2024-12-12 11:30:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('9','3','2','5','17.00','16.00','21.00','64.00','4.00','122.00',NULL,NULL,'published','1','1','2024-12-10 14:00:00','2024-12-12 10:00:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('10','3','4','5','18.00','17.00','23.00','70.00','5.00','133.00',NULL,NULL,'published','2','1','2024-12-10 15:00:00','2024-12-12 10:30:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('11','3','7','5','15.00','14.00','19.00','58.00','4.00','110.00',NULL,NULL,'published','3','1','2024-12-11 14:00:00','2024-12-12 11:00:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `results` (`id`,`student_id`,`course_id`,`semester_id`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`approved_by`,`submitted_date`,`approved_date`,`published_date`,`remarks`,`created_at`,`updated_at`) VALUES ('12','3','10','5','18.00','16.00','22.00','67.00','4.00','127.00',NULL,NULL,'published','4','1','2024-12-11 15:00:00','2024-12-12 11:30:00','2024-12-15 09:00:00',NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_permission` (`role_id`,`permission_id`),
  KEY `idx_role` (`role_id`),
  KEY `idx_permission` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('1','1','22','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('2','1','17','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('3','1','16','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('4','1','18','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('5','1','20','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('6','1','15','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('7','1','19','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('8','1','23','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('16','2','8','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('17','2','9','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('18','2','6','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('19','2','7','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('20','2','10','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('23','3','5','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('24','3','4','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('25','3','1','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('26','3','3','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('30','4','13','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('31','4','12','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('32','4','11','2026-02-12 21:04:19');
INSERT INTO `role_permissions` (`id`,`role_id`,`permission_id`,`created_at`) VALUES ('33','4','14','2026-02-12 21:04:19');

CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`,`role_name`,`description`,`created_at`,`updated_at`) VALUES ('1','admin','System Administrator with full access','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `roles` (`id`,`role_name`,`description`,`created_at`,`updated_at`) VALUES ('2','lecturer','Lecturer with course and results management access','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `roles` (`id`,`role_name`,`description`,`created_at`,`updated_at`) VALUES ('3','student','Student with limited access to own records','2026-02-12 21:03:49','2026-02-12 21:03:49');
INSERT INTO `roles` (`id`,`role_name`,`description`,`created_at`,`updated_at`) VALUES ('4','finance','Finance staff with payment management access','2026-02-12 21:03:49','2026-02-12 21:03:49');

CREATE TABLE `scheduled_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `report_type` enum('enrollment','financial','staff','system') NOT NULL,
  `frequency` enum('weekly','monthly') NOT NULL,
  `schedule_value` int(11) DEFAULT NULL,
  `time_of_day` time NOT NULL DEFAULT '00:00:00',
  `recipients` text NOT NULL,
  `filters` text DEFAULT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE `semesters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year_id` int(11) NOT NULL,
  `semester_name` varchar(50) NOT NULL,
  `semester_number` tinyint(4) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `registration_start_date` date DEFAULT NULL,
  `registration_end_date` date DEFAULT NULL,
  `status` enum('active','inactive','completed') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_academic_year` (`academic_year_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `semesters_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `semesters` (`id`,`academic_year_id`,`semester_name`,`semester_number`,`start_date`,`end_date`,`registration_start_date`,`registration_end_date`,`status`,`created_at`,`updated_at`) VALUES ('1','1','Semester 1','1','2022-09-01','2022-12-15','2022-08-15','2022-09-10','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `semesters` (`id`,`academic_year_id`,`semester_name`,`semester_number`,`start_date`,`end_date`,`registration_start_date`,`registration_end_date`,`status`,`created_at`,`updated_at`) VALUES ('2','1','Semester 2','2','2023-01-15','2023-06-30','2023-01-05','2023-01-20','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `semesters` (`id`,`academic_year_id`,`semester_name`,`semester_number`,`start_date`,`end_date`,`registration_start_date`,`registration_end_date`,`status`,`created_at`,`updated_at`) VALUES ('3','2','Semester 1','1','2023-09-01','2023-12-15','2023-08-15','2023-09-10','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `semesters` (`id`,`academic_year_id`,`semester_name`,`semester_number`,`start_date`,`end_date`,`registration_start_date`,`registration_end_date`,`status`,`created_at`,`updated_at`) VALUES ('4','2','Semester 2','2','2024-01-15','2024-06-30','2024-01-05','2024-01-20','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `semesters` (`id`,`academic_year_id`,`semester_name`,`semester_number`,`start_date`,`end_date`,`registration_start_date`,`registration_end_date`,`status`,`created_at`,`updated_at`) VALUES ('5','3','Semester 1','1','2024-09-01','2024-12-15','2024-08-15','2024-09-10','completed','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `semesters` (`id`,`academic_year_id`,`semester_name`,`semester_number`,`start_date`,`end_date`,`registration_start_date`,`registration_end_date`,`status`,`created_at`,`updated_at`) VALUES ('6','3','Semester 2','2','2025-01-15','2025-06-30','2025-01-05','2025-01-20','active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `semesters` (`id`,`academic_year_id`,`semester_name`,`semester_number`,`start_date`,`end_date`,`registration_start_date`,`registration_end_date`,`status`,`created_at`,`updated_at`) VALUES ('7','4','Semester 1','1','2025-09-01','2025-12-15','2025-08-15','2025-09-10','inactive','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `semesters` (`id`,`academic_year_id`,`semester_name`,`semester_number`,`start_date`,`end_date`,`registration_start_date`,`registration_end_date`,`status`,`created_at`,`updated_at`) VALUES ('8','4','Semester 2','2','2026-01-15','2026-06-30','2026-01-05','2026-01-20','inactive','2026-02-12 21:04:19','2026-02-12 21:04:19');

CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_category` (`category`),
  KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('1','institution_name','Seminary Institution','general','Name of the institution','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('2','institution_email','info@seminary.edu','general','Institution email address','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('3','institution_phone','+1234567890','general','Institution phone number','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('4','institution_address','123 Seminary Street','general','Institution physical address','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('5','academic_year_format','YYYY/YYYY','academic','Format for academic year display','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('6','student_id_prefix','STD','academic','Prefix for student ID generation','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('7','min_credit_hours','12','academic','Minimum credit hours per semester','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('8','max_credit_hours','21','academic','Maximum credit hours per semester','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('9','pass_mark','50','academic','Minimum passing mark','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('10','results_submission_deadline_days','14','academic','Days before end of semester for results submission','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('11','timezone','UTC','system','System timezone','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('12','date_format','Y-m-d','system','Date format for display','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('13','session_timeout','3600','system','Session timeout in seconds','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('14','max_login_attempts','5','security','Maximum failed login attempts before lockout','2026-02-12 21:03:49');
INSERT INTO `settings` (`id`,`setting_key`,`setting_value`,`category`,`description`,`updated_at`) VALUES ('15','account_lockout_duration','30','security','Account lockout duration in minutes','2026-02-12 21:03:49');

CREATE TABLE `student_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `total_fees` decimal(10,2) DEFAULT 0.00,
  `total_paid` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) DEFAULT 0.00,
  `last_payment_date` date DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_semester` (`student_id`,`semester_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_semester` (`semester_id`),
  KEY `idx_balance` (`balance`),
  CONSTRAINT `student_balances_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_balances_ibfk_2` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('1','1','6','2025000.00','1500000.00','525000.00','2025-02-05','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('3','2','6','2025000.00','2025000.00','0.00','2025-01-18','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('4','3','6','2025000.00','1000000.00','1025000.00','2025-01-25','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('5','30','6','2025000.00','500000.00','1525000.00','2025-02-01','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('6','4','6','2025000.00','2025000.00','0.00','2025-01-15','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('7','5','6','2025000.00','1000000.00','1025000.00','2025-01-22','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('8','6','6','2025000.00','2025000.00','0.00','2025-01-17','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('9','7','6','2025000.00','1500000.00','525000.00','2025-01-28','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('10','8','6','2025000.00','2025000.00','0.00','2025-01-16','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('11','9','6','2025000.00','1200000.00','825000.00','2025-01-30','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('12','21','6','2475000.00','2000000.00','475000.00','2025-01-19','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('13','22','6','2475000.00','2475000.00','0.00','2025-01-14','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('14','23','6','2475000.00','1500000.00','975000.00','2025-01-26','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('15','25','6','1525000.00','1525000.00','0.00','2025-01-21','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('16','26','6','1525000.00','800000.00','725000.00','2025-01-29','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('17','27','6','1525000.00','1525000.00','0.00','2025-01-23','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('18','28','6','1525000.00','500000.00','1025000.00','2025-02-02','2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('28','10','6','2025000.00','0.00','2025000.00',NULL,'2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('32','24','6','2475000.00','0.00','2475000.00',NULL,'2026-02-12 21:04:19');
INSERT INTO `student_balances` (`id`,`student_id`,`semester_id`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`updated_at`) VALUES ('37','29','6','2025000.00','0.00','2025000.00',NULL,'2026-02-12 21:04:19');

CREATE TABLE `student_gpas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `semester_gpa` decimal(3,2) DEFAULT NULL,
  `cumulative_gpa` decimal(3,2) DEFAULT NULL,
  `total_credits_attempted` int(11) DEFAULT 0,
  `total_credits_earned` int(11) DEFAULT 0,
  `total_grade_points` decimal(10,2) DEFAULT 0.00,
  `academic_standing` enum('Good Standing','Probation','Suspension') DEFAULT 'Good Standing',
  `calculated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_semester` (`student_id`,`semester_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_semester` (`semester_id`),
  CONSTRAINT `student_gpas_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_gpas_ibfk_2` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `student_gpas` (`id`,`student_id`,`semester_id`,`semester_gpa`,`cumulative_gpa`,`total_credits_attempted`,`total_credits_earned`,`total_grade_points`,`academic_standing`,`calculated_at`) VALUES ('1','1','5','0.00','0.00',NULL,NULL,NULL,'Suspension','2026-02-12 21:04:19');
INSERT INTO `student_gpas` (`id`,`student_id`,`semester_id`,`semester_gpa`,`cumulative_gpa`,`total_credits_attempted`,`total_credits_earned`,`total_grade_points`,`academic_standing`,`calculated_at`) VALUES ('2','2','5','0.00','0.00',NULL,NULL,NULL,'Suspension','2026-02-12 21:04:19');
INSERT INTO `student_gpas` (`id`,`student_id`,`semester_id`,`semester_gpa`,`cumulative_gpa`,`total_credits_attempted`,`total_credits_earned`,`total_grade_points`,`academic_standing`,`calculated_at`) VALUES ('3','3','5','0.00','0.00',NULL,NULL,NULL,'Suspension','2026-02-12 21:04:19');

CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `admission_number` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `title` varchar(10) DEFAULT NULL,
  `date_of_birth` date NOT NULL,
  `national_id` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `guardian_name` varchar(255) DEFAULT NULL,
  `guardian_relation` varchar(100) DEFAULT NULL,
  `guardian_phone` varchar(50) DEFAULT NULL,
  `guardian_email` varchar(150) DEFAULT NULL,
  `emergency_contact_name` varchar(200) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `emergency_contact_relationship` varchar(50) DEFAULT NULL,
  `program_id` int(11) NOT NULL,
  `level_year` int(11) NOT NULL DEFAULT 1,
  `specialization` varchar(200) DEFAULT NULL,
  `qualifications` text DEFAULT NULL,
  `entry_year` int(11) NOT NULL,
  `entry_semester_id` int(11) DEFAULT NULL,
  `entry_mode` varchar(100) DEFAULT NULL,
  `enrollment_type` varchar(50) DEFAULT 'Day',
  `photo` varchar(255) DEFAULT NULL,
  `status` enum('active','graduated','withdrawn','suspended') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `student_id` (`student_id`),
  UNIQUE KEY `admission_number` (`admission_number`),
  KEY `entry_semester_id` (`entry_semester_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_program` (`program_id`),
  KEY `idx_status` (`status`),
  KEY `idx_level` (`level_year`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `students_ibfk_2` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`),
  CONSTRAINT `students_ibfk_3` FOREIGN KEY (`entry_semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('1','11','STD2024001',NULL,'John','Paul','Doe',NULL,'2001-03-15',NULL,'Male','+256700100001','john.doe@student.seminary.edu','123 Main Street','Kampala','Uganda',NULL,NULL,NULL,NULL,'Mary Doe','+256700100002','Mother','1','2',NULL,NULL,'2023','3',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('2','12','STD2024002',NULL,'Jane','Marie','Smith',NULL,'2002-05-20',NULL,'Female','+256700100003','jane.smith@student.seminary.edu','456 Oak Avenue','Kampala','Uganda',NULL,NULL,NULL,NULL,'Robert Smith','+256700100004','Father','1','2',NULL,NULL,'2023','3',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('3','13','STD2024003',NULL,'Michael','David','Johnson',NULL,'2001-07-10',NULL,'Male','+256700100005','michael.johnson@student.seminary.edu','789 Pine Road','Entebbe','Uganda',NULL,NULL,NULL,NULL,'Susan Johnson','+256700100006','Mother','1','2',NULL,NULL,'2023','3',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('4','14','STD2024004',NULL,'Sarah','Grace','Williams',NULL,'2002-09-25',NULL,'Female','+256700100007','sarah.williams@student.seminary.edu','321 Elm Street','Jinja','Uganda',NULL,NULL,NULL,NULL,'James Williams','+256700100008','Father','1','1',NULL,NULL,'2024','5',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('5','15','STD2024005',NULL,'David','Samuel','Brown',NULL,'2001-11-30',NULL,'Male','+256700100009','david.brown@student.seminary.edu','654 Maple Drive','Kampala','Uganda',NULL,NULL,NULL,NULL,'Ruth Brown','+256700100010','Mother','1','1',NULL,NULL,'2024','5',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('6','16','STD2024006',NULL,'Emily','Faith','Davis',NULL,'2002-02-14',NULL,'Female','+256700100011','emily.davis@student.seminary.edu','987 Cedar Lane','Mbarara','Uganda',NULL,NULL,NULL,NULL,'Peter Davis','+256700100012','Father','1','3',NULL,NULL,'2022','1',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('7','17','STD2024007',NULL,'James','Matthew','Miller',NULL,'2001-04-18',NULL,'Male','+256700100013','james.miller@student.seminary.edu','147 Birch Court','Kampala','Uganda',NULL,NULL,NULL,NULL,'Anna Miller','+256700100014','Mother','1','3',NULL,NULL,'2022','1',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('8','18','STD2024008',NULL,'Mary','Elizabeth','Wilson',NULL,'2002-06-22',NULL,'Female','+256700100015','mary.wilson@student.seminary.edu','258 Walnut Place','Gulu','Uganda',NULL,NULL,NULL,NULL,'Thomas Wilson','+256700100016','Father','1','4',NULL,NULL,'2021','1',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('9','19','STD2024009',NULL,'Robert','Joseph','Moore',NULL,'2001-08-05',NULL,'Male','+256700100017','robert.moore@student.seminary.edu','369 Spruce Way','Kampala','Uganda',NULL,NULL,NULL,NULL,'Linda Moore','+256700100018','Mother','1','4',NULL,NULL,'2021','1',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('10','20','STD2024010',NULL,'Jennifer','Anne','Taylor',NULL,'2002-10-12',NULL,'Female','+256700100019','jennifer.taylor@student.seminary.edu','741 Ash Boulevard','Fort Portal','Uganda',NULL,NULL,NULL,NULL,'Michael Taylor','+256700100020','Father','1','1',NULL,NULL,'2024','5',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('11','21','STD2024011',NULL,'William','Peter','Anderson',NULL,'1998-01-15',NULL,'Male','+256700100021','william.anderson@student.seminary.edu','852 Hickory Street','Kampala','Uganda',NULL,NULL,NULL,NULL,'Helen Anderson','+256700100022','Spouse','3','1',NULL,NULL,'2024','5',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('12','22','STD2024012',NULL,'Linda','Rose','Thomas',NULL,'1999-03-20',NULL,'Female','+256700100023','linda.thomas@student.seminary.edu','963 Willow Road','Kampala','Uganda',NULL,NULL,NULL,NULL,'George Thomas','+256700100024','Spouse','3','1',NULL,NULL,'2024','5',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('13','23','STD2024013',NULL,'Richard','Andrew','Jackson',NULL,'1997-05-25',NULL,'Male','+256700100025','richard.jackson@student.seminary.edu','159 Cherry Lane','Mbale','Uganda',NULL,NULL,NULL,NULL,'Dorothy Jackson','+256700100026','Mother','3','2',NULL,NULL,'2023','3',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('14','24','STD2024014',NULL,'Patricia','Lynn','White',NULL,'1998-07-30',NULL,'Female','+256700100027','patricia.white@student.seminary.edu','357 Poplar Avenue','Kampala','Uganda',NULL,NULL,NULL,NULL,'Christopher White','+256700100028','Spouse','3','2',NULL,NULL,'2023','3',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('15','25','STD2024015',NULL,'Charles','Edward','Harris',NULL,'2000-02-10',NULL,'Male','+256700100029','charles.harris@student.seminary.edu','468 Beech Drive','Kampala','Uganda',NULL,NULL,NULL,NULL,'Nancy Harris','+256700100030','Mother','4','1',NULL,NULL,'2024','5',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('16','26','STD2024016',NULL,'Barbara','Jean','Martin',NULL,'2001-04-15',NULL,'Female','+256700100031','barbara.martin@student.seminary.edu','579 Sycamore Court','Masaka','Uganda',NULL,NULL,NULL,NULL,'Donald Martin','+256700100032','Father','4','1',NULL,NULL,'2024','5',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('17','27','STD2024017',NULL,'Joseph','Daniel','Thompson',NULL,'2000-06-20',NULL,'Male','+256700100033','joseph.thompson@student.seminary.edu','680 Redwood Place','Kampala','Uganda',NULL,NULL,NULL,NULL,'Betty Thompson','+256700100034','Mother','4','2',NULL,NULL,'2023','3',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('18','28','STD2024018',NULL,'Susan','Michelle','Garcia',NULL,'2001-08-25',NULL,'Female','+256700100035','susan.garcia@student.seminary.edu','791 Magnolia Way','Arua','Uganda',NULL,NULL,NULL,NULL,'Paul Garcia','+256700100036','Father','4','2',NULL,NULL,'2023','3',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('19','29','STD2024019',NULL,'Thomas','Charles','Martinez',NULL,'2001-10-30',NULL,'Male','+256700100037','thomas.martinez@student.seminary.edu','802 Dogwood Street','Kampala','Uganda',NULL,NULL,NULL,NULL,'Carol Martinez','+256700100038','Mother','1','2',NULL,NULL,'2023','3',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('20','30','STD2024020',NULL,'Jessica','Nicole','Robinson',NULL,'2002-12-05',NULL,'Female','+256700100039','jessica.robinson@student.seminary.edu','913 Cypress Road','Soroti','Uganda',NULL,NULL,NULL,NULL,'Steven Robinson','+256700100040','Father','1','2',NULL,NULL,'2023','3',NULL,'Day',NULL,'active','2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('21','38','2026-STU-001','ADM-2023-0001','KASOZI','','RAYMOND',NULL,'2000-02-13',NULL,'Male','0777439939','karimkigenyi0@gmail.com','','KAMPALA','UGANDA','Nakamya Sarah','Mother','0783101414','','Barimusi Moses','0756014689',NULL,'2','1',NULL,NULL,'2023','7','Direct Entry','Day',NULL,'active','2026-02-13 14:35:45','2026-02-13 14:35:45');
INSERT INTO `students` (`id`,`user_id`,`student_id`,`admission_number`,`first_name`,`middle_name`,`last_name`,`title`,`date_of_birth`,`national_id`,`gender`,`phone`,`email`,`address`,`city`,`country`,`guardian_name`,`guardian_relation`,`guardian_phone`,`guardian_email`,`emergency_contact_name`,`emergency_contact_phone`,`emergency_contact_relationship`,`program_id`,`level_year`,`specialization`,`qualifications`,`entry_year`,`entry_semester_id`,`entry_mode`,`enrollment_type`,`photo`,`status`,`created_at`,`updated_at`) VALUES ('22','39','2026-STU-002','ADM-2023-0002','KASOZI','','RAYMOND','','2000-02-13','','Male','0777439939','karimkigenyi1@gmail.com','','KAMPALA','UGANDA','Nakamya Sarah','Mother','0783101414','','','','','2','1','','','2023','7','Direct Entry','Day','uploads/students/student_22_1771016156.png','active','2026-02-13 14:41:08','2026-02-13 23:55:56');

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','lecturer','finance','admin') NOT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `failed_login_attempts` int(11) DEFAULT 0,
  `account_locked_until` datetime DEFAULT NULL,
  `require_password_change` tinyint(1) DEFAULT 0,
  `password_reset_token` varchar(100) DEFAULT NULL,
  `password_reset_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('1','admin','admin@seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','admin','active','2026-02-14 13:29:57','0',NULL,'0',NULL,NULL,'2026-02-12 21:03:50','2026-02-14 13:29:57');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('2','admin2','admin2@seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','admin','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('3','prof.johnson','johnson@seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','lecturer','active','2026-02-13 18:44:59','0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-13 18:44:59');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('4','prof.williams','williams@seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','lecturer','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('5','prof.brown','brown@seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','lecturer','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('6','prof.davis','davis@seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','lecturer','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('7','prof.miller','miller@seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','lecturer','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('8','prof.wilson','wilson@seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','lecturer','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('9','finance1','finance1@seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','finance','active','2026-02-13 01:25:05','0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-13 01:25:05');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('10','finance2','finance2@seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','finance','active','2026-02-14 00:32:39','0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-14 00:32:39');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('11','std001','john.doe@student.seminary.edu','$2y$10$gNU6LOfA3SRt41AsnXlRrucrnpOW69ljrfFo5P/TGVA/FQzvEG7Va','student','active','2026-02-14 13:29:21','0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-14 13:29:21');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('12','std002','jane.smith@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('13','std003','michael.johnson@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('14','std004','sarah.williams@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('15','std005','david.brown@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('16','std006','emily.davis@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('17','std007','james.miller@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('18','std008','mary.wilson@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('19','std009','robert.moore@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('20','std010','jennifer.taylor@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('21','std011','william.anderson@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('22','std012','linda.thomas@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('23','std013','richard.jackson@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('24','std014','patricia.white@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('25','std015','charles.harris@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('26','std016','barbara.martin@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('27','std017','joseph.thompson@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('28','std018','susan.garcia@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('29','std019','thomas.martinez@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('30','std020','jessica.robinson@student.seminary.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','student','active',NULL,'0',NULL,'0',NULL,NULL,'2026-02-12 21:04:19','2026-02-12 21:04:19');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('31','raymondkasozi','raymond.kasozi@studmc.kiu.ac.ug','$2y$10$XxAPl5kbtVkYGMObtr2k6OF9t6Finue0mGnKjDW2GAaoP2xC3RsGi','student','',NULL,'0',NULL,'1',NULL,NULL,'2026-02-13 13:57:36','2026-02-13 13:57:36');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('38','karimkigenyi0','karimkigenyi0@gmail.com','$2y$10$cswtp6zRS45w599VwrQe5Oc/TJKh0F5lvw/XIJTS5esNn6ZbRjhZO','student','',NULL,'0',NULL,'1',NULL,NULL,'2026-02-13 14:35:45','2026-02-13 14:35:45');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('39','karimkigenyi1','karimkigenyi1@gmail.com','$2y$10$US/1E5khyBr.aGax07HuC.njsi9s4gaQxW.4wDZFtKM1kuuUl6RQm','student','active','2026-02-14 12:51:25','0',NULL,'0',NULL,NULL,'2026-02-13 14:41:08','2026-02-14 12:51:25');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('40','kemi','kemi@institution.edu','$2y$10$U3jojdoOG9i8WNWdPFytXu/OUb1kX.ZuOS./ZvXcmsUkZnC7Gn64.','lecturer','active',NULL,'0',NULL,'1',NULL,NULL,'2026-02-13 22:34:17','2026-02-13 22:34:17');
INSERT INTO `users` (`id`,`username`,`email`,`password_hash`,`role`,`status`,`last_login`,`failed_login_attempts`,`account_locked_until`,`require_password_change`,`password_reset_token`,`password_reset_expires`,`created_at`,`updated_at`) VALUES ('42','gera','gera@insitution.edu','$2y$10$dVXVUMhoYMhtQSi7vLcsBe0/ClrWxBb6WiLV.QhROjYP9ia8cydXe','lecturer','active','2026-02-13 23:32:39','0',NULL,'1',NULL,NULL,'2026-02-13 22:52:46','2026-02-13 23:32:39');

-- WARNING: could not retrieve CREATE TABLE for `vw_lecturer_details`

INSERT INTO `vw_lecturer_details` (`id`,`lecturer_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('1','LEC001','3','Robert James Johnson','Robert','James','Johnson','+256700000101','johnson@seminary.edu','Theology','PhD in Biblical Studies','Old Testament','Office 101',NULL,'active','prof.johnson','johnson@seminary.edu','active');
INSERT INTO `vw_lecturer_details` (`id`,`lecturer_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('2','LEC002','4','Margaret Anne Williams','Margaret','Anne','Williams','+256700000102','williams@seminary.edu','Theology','PhD in Systematic Theology','Systematic Theology','Office 102',NULL,'active','prof.williams','williams@seminary.edu','active');
INSERT INTO `vw_lecturer_details` (`id`,`lecturer_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('3','LEC003','5','Daniel Paul Brown','Daniel','Paul','Brown','+256700000103','brown@seminary.edu','Pastoral Studies','MDiv, PhD in Pastoral Care','Pastoral Counseling','Office 103',NULL,'active','prof.brown','brown@seminary.edu','active');
INSERT INTO `vw_lecturer_details` (`id`,`lecturer_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('4','LEC004','6','Elizabeth Grace Davis','Elizabeth','Grace','Davis','+256700000104','davis@seminary.edu','Theology','PhD in Church History','Church History','Office 104',NULL,'active','prof.davis','davis@seminary.edu','active');
INSERT INTO `vw_lecturer_details` (`id`,`lecturer_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('5','LEC005','7','Matthew John Miller','Matthew','John','Miller','+256700000105','miller@seminary.edu','Ministry','PhD in Missiology','Missions and Evangelism','Office 105',NULL,'active','prof.miller','miller@seminary.edu','active');
INSERT INTO `vw_lecturer_details` (`id`,`lecturer_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('6','LEC006','8','Rebecca Faith Wilson','Rebecca','Faith','Wilson','+256700000106','wilson@seminary.edu','Theology','PhD in New Testament','New Testament Studies','Office 106',NULL,'active','prof.wilson','wilson@seminary.edu','active');
INSERT INTO `vw_lecturer_details` (`id`,`lecturer_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`phone`,`email`,`department`,`qualifications`,`specialization`,`office_location`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('7','LEC-2026-001','42','BARIMUSI  GERALD','BARIMUSI','','GERALD','','gera@insitution.edu','SCI','Bachelor&#039;s Degree','','','uploads/lecturers/LEC-2026-001.png','active','gera','gera@insitution.edu','active');

-- WARNING: could not retrieve CREATE TABLE for `vw_student_details`

INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('1','STD2024001','11','John Paul Doe','John','Paul','Doe','2001-03-15','Male','+256700100001','john.doe@student.seminary.edu','123 Main Street','Kampala','Uganda','1','BTH','Bachelor of Theology','Theology','2','2023',NULL,'active','std001','john.doe@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('2','STD2024002','12','Jane Marie Smith','Jane','Marie','Smith','2002-05-20','Female','+256700100003','jane.smith@student.seminary.edu','456 Oak Avenue','Kampala','Uganda','1','BTH','Bachelor of Theology','Theology','2','2023',NULL,'active','std002','jane.smith@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('3','STD2024003','13','Michael David Johnson','Michael','David','Johnson','2001-07-10','Male','+256700100005','michael.johnson@student.seminary.edu','789 Pine Road','Entebbe','Uganda','1','BTH','Bachelor of Theology','Theology','2','2023',NULL,'active','std003','michael.johnson@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('4','STD2024004','14','Sarah Grace Williams','Sarah','Grace','Williams','2002-09-25','Female','+256700100007','sarah.williams@student.seminary.edu','321 Elm Street','Jinja','Uganda','1','BTH','Bachelor of Theology','Theology','1','2024',NULL,'active','std004','sarah.williams@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('5','STD2024005','15','David Samuel Brown','David','Samuel','Brown','2001-11-30','Male','+256700100009','david.brown@student.seminary.edu','654 Maple Drive','Kampala','Uganda','1','BTH','Bachelor of Theology','Theology','1','2024',NULL,'active','std005','david.brown@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('6','STD2024006','16','Emily Faith Davis','Emily','Faith','Davis','2002-02-14','Female','+256700100011','emily.davis@student.seminary.edu','987 Cedar Lane','Mbarara','Uganda','1','BTH','Bachelor of Theology','Theology','3','2022',NULL,'active','std006','emily.davis@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('7','STD2024007','17','James Matthew Miller','James','Matthew','Miller','2001-04-18','Male','+256700100013','james.miller@student.seminary.edu','147 Birch Court','Kampala','Uganda','1','BTH','Bachelor of Theology','Theology','3','2022',NULL,'active','std007','james.miller@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('8','STD2024008','18','Mary Elizabeth Wilson','Mary','Elizabeth','Wilson','2002-06-22','Female','+256700100015','mary.wilson@student.seminary.edu','258 Walnut Place','Gulu','Uganda','1','BTH','Bachelor of Theology','Theology','4','2021',NULL,'active','std008','mary.wilson@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('9','STD2024009','19','Robert Joseph Moore','Robert','Joseph','Moore','2001-08-05','Male','+256700100017','robert.moore@student.seminary.edu','369 Spruce Way','Kampala','Uganda','1','BTH','Bachelor of Theology','Theology','4','2021',NULL,'active','std009','robert.moore@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('10','STD2024010','20','Jennifer Anne Taylor','Jennifer','Anne','Taylor','2002-10-12','Female','+256700100019','jennifer.taylor@student.seminary.edu','741 Ash Boulevard','Fort Portal','Uganda','1','BTH','Bachelor of Theology','Theology','1','2024',NULL,'active','std010','jennifer.taylor@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('19','STD2024019','29','Thomas Charles Martinez','Thomas','Charles','Martinez','2001-10-30','Male','+256700100037','thomas.martinez@student.seminary.edu','802 Dogwood Street','Kampala','Uganda','1','BTH','Bachelor of Theology','Theology','2','2023',NULL,'active','std019','thomas.martinez@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('20','STD2024020','30','Jessica Nicole Robinson','Jessica','Nicole','Robinson','2002-12-05','Female','+256700100039','jessica.robinson@student.seminary.edu','913 Cypress Road','Soroti','Uganda','1','BTH','Bachelor of Theology','Theology','2','2023',NULL,'active','std020','jessica.robinson@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('21','2026-STU-001','38','KASOZI  RAYMOND','KASOZI','','RAYMOND','2000-02-13','Male','0777439939','karimkigenyi0@gmail.com','','KAMPALA','UGANDA','2','MTH','Master of Theology','Theology','1','2023',NULL,'active','karimkigenyi0','karimkigenyi0@gmail.com','');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('22','2026-STU-002','39','KASOZI  RAYMOND','KASOZI','','RAYMOND','2000-02-13','Male','0777439939','karimkigenyi1@gmail.com','','KAMPALA','UGANDA','2','MTH','Master of Theology','Theology','1','2023','uploads/students/student_22_1771016156.png','active','karimkigenyi1','karimkigenyi1@gmail.com','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('11','STD2024011','21','William Peter Anderson','William','Peter','Anderson','1998-01-15','Male','+256700100021','william.anderson@student.seminary.edu','852 Hickory Street','Kampala','Uganda','3','MDIV','Master of Divinity','Divinity','1','2024',NULL,'active','std011','william.anderson@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('12','STD2024012','22','Linda Rose Thomas','Linda','Rose','Thomas','1999-03-20','Female','+256700100023','linda.thomas@student.seminary.edu','963 Willow Road','Kampala','Uganda','3','MDIV','Master of Divinity','Divinity','1','2024',NULL,'active','std012','linda.thomas@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('13','STD2024013','23','Richard Andrew Jackson','Richard','Andrew','Jackson','1997-05-25','Male','+256700100025','richard.jackson@student.seminary.edu','159 Cherry Lane','Mbale','Uganda','3','MDIV','Master of Divinity','Divinity','2','2023',NULL,'active','std013','richard.jackson@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('14','STD2024014','24','Patricia Lynn White','Patricia','Lynn','White','1998-07-30','Female','+256700100027','patricia.white@student.seminary.edu','357 Poplar Avenue','Kampala','Uganda','3','MDIV','Master of Divinity','Divinity','2','2023',NULL,'active','std014','patricia.white@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('15','STD2024015','25','Charles Edward Harris','Charles','Edward','Harris','2000-02-10','Male','+256700100029','charles.harris@student.seminary.edu','468 Beech Drive','Kampala','Uganda','4','DPT','Diploma in Pastoral Theology','Pastoral Studies','1','2024',NULL,'active','std015','charles.harris@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('16','STD2024016','26','Barbara Jean Martin','Barbara','Jean','Martin','2001-04-15','Female','+256700100031','barbara.martin@student.seminary.edu','579 Sycamore Court','Masaka','Uganda','4','DPT','Diploma in Pastoral Theology','Pastoral Studies','1','2024',NULL,'active','std016','barbara.martin@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('17','STD2024017','27','Joseph Daniel Thompson','Joseph','Daniel','Thompson','2000-06-20','Male','+256700100033','joseph.thompson@student.seminary.edu','680 Redwood Place','Kampala','Uganda','4','DPT','Diploma in Pastoral Theology','Pastoral Studies','2','2023',NULL,'active','std017','joseph.thompson@student.seminary.edu','active');
INSERT INTO `vw_student_details` (`id`,`student_id`,`user_id`,`full_name`,`first_name`,`middle_name`,`last_name`,`date_of_birth`,`gender`,`phone`,`email`,`address`,`city`,`country`,`program_id`,`program_code`,`program_name`,`department`,`level_year`,`entry_year`,`photo`,`status`,`username`,`user_email`,`user_status`) VALUES ('18','STD2024018','28','Susan Michelle Garcia','Susan','Michelle','Garcia','2001-08-25','Female','+256700100035','susan.garcia@student.seminary.edu','791 Magnolia Way','Arua','Uganda','4','DPT','Diploma in Pastoral Theology','Pastoral Studies','2','2023',NULL,'active','std018','susan.garcia@student.seminary.edu','active');

-- WARNING: could not retrieve CREATE TABLE for `vw_student_finances`

INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('1','STD2024001','John Doe','6','Semester 2','2024/2025','2025000.00','1500000.00','525000.00','2025-02-05','Has Balance');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('2','STD2024002','Jane Smith','6','Semester 2','2024/2025','2025000.00','2025000.00','0.00','2025-01-18','Fully Paid');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('3','STD2024003','Michael Johnson','6','Semester 2','2024/2025','2025000.00','1000000.00','1025000.00','2025-01-25','Has Balance');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('4','STD2024004','Sarah Williams','6','Semester 2','2024/2025','2025000.00','2025000.00','0.00','2025-01-15','Fully Paid');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('5','STD2024005','David Brown','6','Semester 2','2024/2025','2025000.00','1000000.00','1025000.00','2025-01-22','Has Balance');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('6','STD2024006','Emily Davis','6','Semester 2','2024/2025','2025000.00','2025000.00','0.00','2025-01-17','Fully Paid');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('7','STD2024007','James Miller','6','Semester 2','2024/2025','2025000.00','1500000.00','525000.00','2025-01-28','Has Balance');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('8','STD2024008','Mary Wilson','6','Semester 2','2024/2025','2025000.00','2025000.00','0.00','2025-01-16','Fully Paid');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('9','STD2024009','Robert Moore','6','Semester 2','2024/2025','2025000.00','1200000.00','825000.00','2025-01-30','Has Balance');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('21','2026-STU-001','KASOZI RAYMOND','6','Semester 2','2024/2025','2475000.00','2000000.00','475000.00','2025-01-19','Has Balance');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('22','2026-STU-002','KASOZI RAYMOND','6','Semester 2','2024/2025','2475000.00','2475000.00','0.00','2025-01-14','Fully Paid');
INSERT INTO `vw_student_finances` (`student_id`,`student_number`,`student_name`,`semester_id`,`semester_name`,`academic_year`,`total_fees`,`total_paid`,`balance`,`last_payment_date`,`payment_status`) VALUES ('10','STD2024010','Jennifer Taylor','6','Semester 2','2024/2025','2025000.00','0.00','2025000.00',NULL,'Has Balance');

-- WARNING: could not retrieve CREATE TABLE for `vw_student_results`

INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('1','1','STD2024001','John Doe','2','BTH102','Old Testament Survey','3','5','Semester 1','2024/2025','18.00','15.00','22.00','68.00','5.00','128.00',NULL,NULL,'published','1','Robert Johnson','2024-12-10 14:00:00','2024-12-12 10:00:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('2','1','STD2024001','John Doe','4','BTH104','Christian Theology I','3','5','Semester 1','2024/2025','20.00','18.00','24.00','72.00','5.00','139.00',NULL,NULL,'published','2','Margaret Williams','2024-12-10 15:00:00','2024-12-12 10:30:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('3','1','STD2024001','John Doe','7','BTH107','Greek I','3','5','Semester 1','2024/2025','16.00','14.00','20.00','62.00','4.00','116.00',NULL,NULL,'published','3','Daniel Brown','2024-12-11 14:00:00','2024-12-12 11:00:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('4','1','STD2024001','John Doe','10','BTH202','Church History II','3','5','Semester 1','2024/2025','19.00','17.00','23.00','70.00','5.00','134.00',NULL,NULL,'published','4','Elizabeth Davis','2024-12-11 15:00:00','2024-12-12 11:30:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('5','2','STD2024002','Jane Smith','2','BTH102','Old Testament Survey','3','5','Semester 1','2024/2025','19.00','18.00','24.00','75.00','5.00','141.00',NULL,NULL,'published','1','Robert Johnson','2024-12-10 14:00:00','2024-12-12 10:00:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('6','2','STD2024002','Jane Smith','4','BTH104','Christian Theology I','3','5','Semester 1','2024/2025','20.00','19.00','25.00','78.00','5.00','147.00',NULL,NULL,'published','2','Margaret Williams','2024-12-10 15:00:00','2024-12-12 10:30:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('7','2','STD2024002','Jane Smith','7','BTH107','Greek I','3','5','Semester 1','2024/2025','17.00','16.00','22.00','68.00','5.00','128.00',NULL,NULL,'published','3','Daniel Brown','2024-12-11 14:00:00','2024-12-12 11:00:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('8','2','STD2024002','Jane Smith','10','BTH202','Church History II','3','5','Semester 1','2024/2025','20.00','18.00','24.00','74.00','5.00','141.00',NULL,NULL,'published','4','Elizabeth Davis','2024-12-11 15:00:00','2024-12-12 11:30:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('9','3','STD2024003','Michael Johnson','2','BTH102','Old Testament Survey','3','5','Semester 1','2024/2025','17.00','16.00','21.00','64.00','4.00','122.00',NULL,NULL,'published','1','Robert Johnson','2024-12-10 14:00:00','2024-12-12 10:00:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('10','3','STD2024003','Michael Johnson','4','BTH104','Christian Theology I','3','5','Semester 1','2024/2025','18.00','17.00','23.00','70.00','5.00','133.00',NULL,NULL,'published','2','Margaret Williams','2024-12-10 15:00:00','2024-12-12 10:30:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('11','3','STD2024003','Michael Johnson','7','BTH107','Greek I','3','5','Semester 1','2024/2025','15.00','14.00','19.00','58.00','4.00','110.00',NULL,NULL,'published','3','Daniel Brown','2024-12-11 14:00:00','2024-12-12 11:00:00','2024-12-15 09:00:00');
INSERT INTO `vw_student_results` (`id`,`student_id`,`student_number`,`student_name`,`course_id`,`course_code`,`course_name`,`credit_hours`,`semester_id`,`semester_name`,`academic_year`,`assignment_marks`,`test_marks`,`midterm_marks`,`final_exam_marks`,`participation_marks`,`total_marks`,`grade`,`grade_points`,`status`,`entered_by`,`lecturer_name`,`submitted_date`,`approved_date`,`published_date`) VALUES ('12','3','STD2024003','Michael Johnson','10','BTH202','Church History II','3','5','Semester 1','2024/2025','18.00','16.00','22.00','67.00','4.00','127.00',NULL,NULL,'published','4','Elizabeth Davis','2024-12-11 15:00:00','2024-12-12 11:30:00','2024-12-15 09:00:00');

