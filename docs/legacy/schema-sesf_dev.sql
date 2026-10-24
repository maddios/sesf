-- Live DDL of sesf_dev (structure only, no data), introspected 2026-10-24 on 10.3.39-MariaDB-0+deb10u2
-- Reference for the rewrite. Do not execute against the shared DB.

CREATE TABLE `announcements` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `show_id` smallint(5) unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `text` text NOT NULL,
  `display` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `show_id` (`show_id`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Announcements visible on index page';

CREATE TABLE `car` (
  `user_id` int(10) unsigned DEFAULT 1,
  `has_image` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `is_confirmed` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `model` varchar(30) DEFAULT NULL,
  `year` varchar(5) DEFAULT NULL,
  `body_id` smallint(5) unsigned NOT NULL,
  `type_id` tinyint(3) unsigned NOT NULL,
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nickname` varchar(64) DEFAULT NULL,
  `VIN` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `is_confirmed` (`is_confirmed`),
  KEY `has_image` (`has_image`),
  KEY `type` (`type_id`),
  KEY `body_id` (`body_id`),
  CONSTRAINT `car_body_id_fk` FOREIGN KEY (`body_id`) REFERENCES `car_body` (`id`),
  CONSTRAINT `car_type_id_fk` FOREIGN KEY (`type_id`) REFERENCES `car_type` (`id`),
  CONSTRAINT `car_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1191 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `car2` (
  `car_number` smallint(5) unsigned NOT NULL DEFAULT 1,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `show_id` smallint(6) NOT NULL,
  `has_image` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `is_confirmed` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `model` varchar(30) DEFAULT NULL,
  `year` varchar(5) DEFAULT NULL,
  `body_id` smallint(5) unsigned NOT NULL,
  `type_id` tinyint(3) unsigned NOT NULL,
  `judging_type_id` tinyint(3) unsigned DEFAULT NULL,
  `id` int(10) unsigned NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `car_body` (
  `id` smallint(5) unsigned NOT NULL,
  `name` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `car_body_show` (
  `body_id` smallint(5) unsigned NOT NULL,
  `show_id` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`body_id`,`show_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `car_show` (
  `id` smallint(6) NOT NULL AUTO_INCREMENT,
  `short_name` varchar(20) NOT NULL,
  `name` varchar(60) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `schedule` text DEFAULT NULL,
  `is_schedule_pdf` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `short_description` varchar(1024) DEFAULT NULL,
  `price_car` float(6,2) DEFAULT NULL,
  `price_adult` float(6,2) DEFAULT NULL,
  `price_child` float(6,2) DEFAULT NULL,
  `date_start` date DEFAULT NULL,
  `date_end` date DEFAULT NULL,
  `date_active` datetime DEFAULT NULL,
  `date_registration_deactivation` datetime DEFAULT NULL,
  `is_active` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `is_judging_released` tinyint(1) unsigned DEFAULT 0,
  `waiver_id` varchar(32) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `has_image` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `primary_sponsor_id` int(10) unsigned DEFAULT NULL,
  `shirt_stock_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `short_name` (`short_name`),
  KEY `date_active` (`date_active`),
  KEY `car_show_primary_sponsor_id_fk` (`primary_sponsor_id`),
  CONSTRAINT `car_show_primary_sponsor_id_fk` FOREIGN KEY (`primary_sponsor_id`) REFERENCES `sponsor` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `car_show_judge` (
  `user_id` int(10) unsigned NOT NULL,
  `show_id` smallint(6) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`show_id`),
  KEY `car_show_judge_show_id_fk` (`show_id`),
  CONSTRAINT `car_show_judge_show_id_fk` FOREIGN KEY (`show_id`) REFERENCES `car_show` (`id`),
  CONSTRAINT `car_show_judge_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `car_type` (
  `id` tinyint(3) unsigned NOT NULL,
  `name` varchar(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `content` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `text` longtext NOT NULL,
  `type_id` varchar(200) NOT NULL,
  `show_id` smallint(5) unsigned DEFAULT NULL,
  `date_edited` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_id` (`type_id`,`show_id`)
) ENGINE=InnoDB AUTO_INCREMENT=127 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `content_type` (
  `id` varchar(200) NOT NULL,
  `name` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `email_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `to` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `hash` char(40) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `hash` (`hash`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `expense` (
  `id` mediumint(9) NOT NULL AUTO_INCREMENT,
  `description` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `amount` float(8,2) DEFAULT NULL,
  `show_id` smallint(5) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `id` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=341 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci PACK_KEYS=0 COMMENT='maintains SESF expenses for expenses.php';

CREATE TABLE `gallery` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `show_id` smallint(6) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `type_id` int(10) unsigned NOT NULL,
  `is_enabled` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `default_item_id` int(10) unsigned DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `is_private` enum('0','1') NOT NULL DEFAULT '1',
  `title` varchar(256) NOT NULL,
  `sort_order` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `gallery_parent_id_foreign` (`parent_id`),
  KEY `gallery_type_id_foreign` (`type_id`),
  KEY `gallery_show_id_foreign` (`show_id`),
  KEY `gallery_user_id_foreign` (`user_id`),
  KEY `gallery_default_item_id_foreign` (`default_item_id`),
  CONSTRAINT `gallery_default_item_id_foreign` FOREIGN KEY (`default_item_id`) REFERENCES `gallery_item` (`id`),
  CONSTRAINT `gallery_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `gallery` (`id`),
  CONSTRAINT `gallery_show_id_foreign` FOREIGN KEY (`show_id`) REFERENCES `car_show` (`id`),
  CONSTRAINT `gallery_type_id_foreign` FOREIGN KEY (`type_id`) REFERENCES `gallery_type` (`id`),
  CONSTRAINT `gallery_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `gallery_item` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `type_id` int(10) unsigned NOT NULL,
  `title` varchar(256) NOT NULL,
  `original_name` varchar(256) DEFAULT NULL,
  `sort_order` int(10) unsigned DEFAULT NULL,
  `date_taken` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `gallery_item_type_id_foreign` (`type_id`),
  KEY `gallery_item_user_id_foreign` (`user_id`),
  CONSTRAINT `gallery_item_type_id_foreign` FOREIGN KEY (`type_id`) REFERENCES `gallery_item_type` (`id`),
  CONSTRAINT `gallery_item_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5854 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `gallery_item_type` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(32) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `gallery_item_xref` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int(10) unsigned DEFAULT NULL,
  `gallery_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(256) DEFAULT NULL,
  `sort_order` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `gallery_item_xref_item_id_foreign` (`item_id`),
  KEY `gallery_item_xref_gallery_id_foreign` (`gallery_id`),
  KEY `gallery_item_xref_user_id_foreign` (`user_id`),
  CONSTRAINT `gallery_item_xref_gallery_id_foreign` FOREIGN KEY (`gallery_id`) REFERENCES `gallery` (`id`),
  CONSTRAINT `gallery_item_xref_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `gallery_item` (`id`),
  CONSTRAINT `gallery_item_xref_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5854 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `gallery_type` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(32) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `guestbook` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `car` varchar(50) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `visible` tinyint(1) NOT NULL DEFAULT 0,
  `date` datetime DEFAULT NULL,
  `ip` varchar(20) NOT NULL DEFAULT '0.0.0.0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=767 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci PACK_KEYS=0;

CREATE TABLE `image` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `directory` int(11) NOT NULL,
  `imagename` varchar(255) NOT NULL,
  `path` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `display` tinyint(3) unsigned NOT NULL,
  `date` datetime NOT NULL,
  `views` int(11) NOT NULL DEFAULT 0,
  `is_video` tinyint(1) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `image_parent_directory_imagename_uk` (`directory`,`imagename`)
) ENGINE=InnoDB AUTO_INCREMENT=7496 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `image_directory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_directory` int(10) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `default_image_id` int(11) DEFAULT NULL,
  `display` tinyint(1) NOT NULL DEFAULT 1,
  `section` tinyint(4) DEFAULT 1,
  `sort_order` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `image_directory_parent_directory_name_uk` (`parent_directory`,`name`),
  KEY `image_directory_display_key` (`display`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `ip_banlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(16) DEFAULT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `num_bans` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) DEFAULT 'default',
  `payload` text DEFAULT NULL,
  `attempts` tinyint(3) unsigned DEFAULT 0,
  `reserved` tinyint(1) DEFAULT 0,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned DEFAULT NULL,
  `created_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `judging_form` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL,
  `type_id` tinyint(3) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `jf_type_id_fk` (`type_id`),
  CONSTRAINT `jf_type_id_fk` FOREIGN KEY (`type_id`) REFERENCES `judging_type` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `judging_form_question` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int(10) unsigned NOT NULL,
  `type` varchar(64) DEFAULT NULL,
  `title` varchar(512) DEFAULT NULL,
  `description` varchar(512) DEFAULT NULL,
  `page` smallint(5) unsigned DEFAULT NULL,
  `page_name` varchar(32) DEFAULT NULL,
  `sort_order` smallint(5) unsigned DEFAULT 1,
  `valueMin` smallint(5) unsigned DEFAULT 1,
  `valueMax` smallint(5) unsigned DEFAULT 10,
  `valueStep` smallint(5) unsigned DEFAULT 1,
  `has_quarter` tinyint(1) unsigned DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `jfq_form_id_fk` (`form_id`),
  CONSTRAINT `jfq_form_id_fk` FOREIGN KEY (`form_id`) REFERENCES `judging_form` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `judging_show_body` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `judging_type_id` tinyint(3) unsigned NOT NULL,
  `car_body_id` smallint(5) unsigned NOT NULL,
  `car_show_id` smallint(6) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `judging_show_body_judging_type_id_fk` (`judging_type_id`),
  KEY `judging_show_body_car_body_id_fk` (`car_body_id`),
  KEY `judging_show_body_car_show_id_fk` (`car_show_id`),
  CONSTRAINT `judging_show_body_car_body_id_fk` FOREIGN KEY (`car_body_id`) REFERENCES `car_body` (`id`),
  CONSTRAINT `judging_show_body_car_show_id_fk` FOREIGN KEY (`car_show_id`) REFERENCES `car_show` (`id`),
  CONSTRAINT `judging_show_body_judging_type_id_fk` FOREIGN KEY (`judging_type_id`) REFERENCES `judging_type` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `judging_type` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `location` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `address1` varchar(60) NOT NULL,
  `address2` varchar(60) DEFAULT NULL,
  `city` varchar(60) NOT NULL,
  `state` varchar(10) NOT NULL,
  `zip` varchar(10) NOT NULL,
  `lattitude` double DEFAULT NULL,
  `longitude` double DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=389 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `login_email_signin` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `code_hash` varchar(64) NOT NULL,
  `redirect_path` varchar(1024) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `request_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `login_email_signin_user_id_index` (`user_id`),
  KEY `login_email_signin_expires_at_index` (`expires_at`),
  KEY `login_email_signin_token_hash_index` (`token_hash`),
  KEY `login_email_signin_code_hash_index` (`code_hash`),
  CONSTRAINT `login_email_signin_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `migrations` (
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `password_reminder` (
  `email` varchar(128) DEFAULT NULL,
  `token` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `payment_method` (
  `id` tinyint(3) unsigned NOT NULL,
  `name` varchar(10) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `paypal_payment` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `item_name` varchar(130) NOT NULL DEFAULT 'joi',
  `receiver_email` varchar(125) DEFAULT NULL,
  `item_number` varchar(130) NOT NULL DEFAULT '0',
  `quantity` smallint(6) NOT NULL DEFAULT 0,
  `invoice` varchar(25) NOT NULL DEFAULT '0',
  `custom` varchar(60) DEFAULT NULL,
  `payment_status` set('Completed','Pending','Failed','Denied') NOT NULL DEFAULT 'Failed',
  `pending_reason` set('echeck','intl','verify','address','upgrade','unilateral','other') NOT NULL DEFAULT 'other',
  `mc_gross` float NOT NULL DEFAULT 0,
  `mc_fee` float NOT NULL DEFAULT 0,
  `payment_type` set('echeck','instant') NOT NULL DEFAULT 'instant',
  `payment_date` varchar(50) NOT NULL DEFAULT '0',
  `txn_id` varchar(20) NOT NULL DEFAULT '0',
  `payer_email` varchar(125) DEFAULT NULL,
  `payer_status` set('verified','unverified','intl_verified') NOT NULL DEFAULT 'unverified',
  `txn_type` varchar(128) NOT NULL DEFAULT 'subscr_payment',
  `first_name` varchar(35) DEFAULT NULL,
  `last_name` varchar(60) DEFAULT NULL,
  `address_city` varchar(60) DEFAULT NULL,
  `address_street` varchar(60) DEFAULT NULL,
  `address_state` varchar(60) DEFAULT NULL,
  `address_zip` varchar(15) DEFAULT NULL,
  `address_country` varchar(60) DEFAULT NULL,
  `address_status` set('confirmed','unconfirmed') NOT NULL DEFAULT 'unconfirmed',
  `subscr_date` varchar(50) NOT NULL DEFAULT '0',
  `period1` varchar(20) NOT NULL DEFAULT 'UNK',
  `period2` varchar(20) NOT NULL DEFAULT 'UNK',
  `period3` varchar(20) NOT NULL DEFAULT 'UNK',
  `amount1` float NOT NULL DEFAULT 0,
  `amount2` float NOT NULL DEFAULT 0,
  `amount3` float NOT NULL DEFAULT 0,
  `recurring` tinyint(4) NOT NULL DEFAULT 1,
  `reattempt` tinyint(4) NOT NULL DEFAULT 0,
  `retry_at` varchar(50) DEFAULT NULL,
  `recur_times` smallint(6) NOT NULL DEFAULT 0,
  `username` varchar(25) DEFAULT NULL,
  `PASSWORD` varchar(20) DEFAULT NULL,
  `subscr_id` varchar(20) DEFAULT NULL,
  `entirepost` text DEFAULT NULL,
  `paypal_verified` set('VERIFIED','INVALID') NOT NULL DEFAULT 'INVALID',
  `verify_sign` varchar(125) DEFAULT NULL,
  `num_cart_items` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `registration_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `txn_type` (`txn_type`),
  KEY `payment_status` (`payment_status`),
  KEY `pending_reason` (`pending_reason`),
  KEY `payer_status` (`payer_status`),
  KEY `payment_type` (`payment_type`),
  KEY `retry_at` (`retry_at`),
  KEY `receiver_email` (`receiver_email`),
  KEY `date` (`created_at`),
  KEY `paypal_payment_registration_id_fk` (`registration_id`),
  CONSTRAINT `paypal_payment_registration_id_fk` FOREIGN KEY (`registration_id`) REFERENCES `show_registration` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2778 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci PACK_KEYS=0 COMMENT='Recieve posts FROM paypal servers';

CREATE TABLE `shirt` (
  `user_id` int(10) unsigned NOT NULL,
  `registration_id` int(10) unsigned NOT NULL,
  `type_id` tinyint(3) unsigned NOT NULL,
  `show_id` smallint(5) unsigned NOT NULL,
  `price` float(6,2) DEFAULT NULL,
  `is_handed_out` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  KEY `type_id` (`type_id`),
  KEY `user_id` (`show_id`),
  KEY `shirt_registration_id` (`registration_id`),
  CONSTRAINT `shirt_registration_id` FOREIGN KEY (`registration_id`) REFERENCES `show_registration` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1451 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `shirt_adhoc_sale` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `show_id` smallint(6) NOT NULL,
  `created_by_user_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shirt_adhoc_sale_show_id` (`show_id`),
  KEY `shirt_adhoc_sale_created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `shirt_adhoc_sale_created_by_user_id` FOREIGN KEY (`created_by_user_id`) REFERENCES `user` (`id`),
  CONSTRAINT `shirt_adhoc_sale_show_id` FOREIGN KEY (`show_id`) REFERENCES `car_show` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `shirt_adhoc_sale_item` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` int(10) unsigned NOT NULL,
  `type_id` smallint(6) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shirt_adhoc_sale_item_sale_id` (`sale_id`),
  KEY `shirt_adhoc_sale_item_type_id` (`type_id`),
  CONSTRAINT `shirt_adhoc_sale_item_sale_id` FOREIGN KEY (`sale_id`) REFERENCES `shirt_adhoc_sale` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shirt_adhoc_sale_item_type_id` FOREIGN KEY (`type_id`) REFERENCES `shirt_type` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `shirt_type` (
  `id` smallint(6) NOT NULL AUTO_INCREMENT,
  `name` varchar(30) NOT NULL,
  `price` float(6,2) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_agenda` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `text` text DEFAULT NULL,
  `is_visible` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `is_pdf` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `sort_order` smallint(5) unsigned DEFAULT 0,
  `show_id` smallint(6) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `type_id` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `show_id` (`show_id`),
  KEY `show_agenda_type_id_fk` (`type_id`),
  CONSTRAINT `show_agenda_show_id_fk` FOREIGN KEY (`show_id`) REFERENCES `car_show` (`id`),
  CONSTRAINT `show_agenda_type_id_fk` FOREIGN KEY (`type_id`) REFERENCES `show_agenda_type` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_agenda_type` (
  `id` tinyint(3) unsigned NOT NULL,
  `name` varchar(64) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_extra` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `is_enabled` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `show_id` smallint(6) NOT NULL,
  `name` varchar(64) NOT NULL,
  `description` text NOT NULL,
  `auth_key` varchar(64) DEFAULT NULL,
  `price` double(10,2) NOT NULL DEFAULT 0.00,
  `type_id` tinyint(3) unsigned NOT NULL,
  `min_value` int(10) unsigned NOT NULL DEFAULT 1,
  `max_value` int(10) unsigned NOT NULL DEFAULT 100,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `auth_key` (`auth_key`),
  KEY `show_extra_show_id_fk` (`show_id`),
  KEY `show_extra_type_id_fk` (`type_id`),
  CONSTRAINT `show_extra_show_id_fk` FOREIGN KEY (`show_id`) REFERENCES `car_show` (`id`),
  CONSTRAINT `show_extra_type_id_fk` FOREIGN KEY (`type_id`) REFERENCES `show_extra_type` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_extra_registration` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `show_extra_id` int(10) unsigned NOT NULL,
  `registration_id` int(10) unsigned NOT NULL,
  `num_extras` smallint(5) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `show_extra_id` (`show_extra_id`,`registration_id`),
  KEY `show_extra_registration_registration_id_fk` (`registration_id`),
  CONSTRAINT `show_extra_registration_registration_id_fk` FOREIGN KEY (`registration_id`) REFERENCES `show_registration` (`id`) ON DELETE CASCADE,
  CONSTRAINT `show_extra_registration_show_extra_id_fk` FOREIGN KEY (`show_extra_id`) REFERENCES `show_extra` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=260 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_extra_type` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_location` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `location_id` int(10) unsigned DEFAULT NULL,
  `show_id` smallint(5) unsigned DEFAULT NULL,
  `name` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `is_primary` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `address1` varchar(60) NOT NULL,
  `address2` varchar(60) DEFAULT NULL,
  `city` varchar(60) NOT NULL,
  `state` varchar(10) NOT NULL,
  `zip` varchar(10) NOT NULL,
  `lattitude` double DEFAULT NULL,
  `longitude` double DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_preferences` (
  `show_id` smallint(6) NOT NULL,
  `has_free_shirts_for_guests` tinyint(3) unsigned DEFAULT 0,
  PRIMARY KEY (`show_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_registration` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `show_id` smallint(6) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `waiver_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `amount_owed` float(6,2) NOT NULL DEFAULT 0.00,
  `amount_received` float(6,2) NOT NULL DEFAULT 0.00,
  `amount_received_actual` float(6,2) unsigned DEFAULT NULL,
  `donation` int(10) unsigned NOT NULL DEFAULT 0,
  `is_paid` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `payment_method_id` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `is_checked_in` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `date_checked_in` datetime DEFAULT NULL,
  `discount` float(6,2) NOT NULL DEFAULT 0.00,
  `discount_note` text DEFAULT NULL,
  `is_judge` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `has_signed` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `invoice_id` smallint(5) unsigned NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `arrival_date` date DEFAULT NULL,
  `arrival_time` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_id` (`invoice_id`,`show_id`),
  UNIQUE KEY `show_id_user_id` (`show_id`,`user_id`),
  KEY `is_paid` (`is_paid`),
  KEY `show_registration_user_id_fk` (`user_id`),
  KEY `show_registration_waiver_id_fk` (`waiver_id`),
  CONSTRAINT `show_registration_show_id_fk` FOREIGN KEY (`show_id`) REFERENCES `car_show` (`id`) ON DELETE CASCADE,
  CONSTRAINT `show_registration_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `show_registration_waiver_id_fk` FOREIGN KEY (`waiver_id`) REFERENCES `waiver` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1436 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_registration_back` (
  `id` int(10) unsigned NOT NULL DEFAULT 0,
  `show_id` smallint(6) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `amount_owed` float(6,2) NOT NULL DEFAULT 0.00,
  `amount_received` float(6,2) NOT NULL DEFAULT 0.00,
  `amount_received_actual` float(6,2) unsigned DEFAULT NULL,
  `is_paid` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `payment_method_id` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `discount` float(6,2) NOT NULL DEFAULT 0.00,
  `discount_note` text DEFAULT NULL,
  `is_judge` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `invoice_id` smallint(5) unsigned NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_registration_car` (
  `car_id` int(10) unsigned NOT NULL,
  `registration_id` int(10) unsigned NOT NULL,
  `judging_type_id` tinyint(3) unsigned DEFAULT NULL,
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `car_id` (`car_id`,`registration_id`),
  KEY `registration_car_registration_id_fk` (`registration_id`),
  KEY `show_registration_car_judging_type_id_fk` (`judging_type_id`),
  CONSTRAINT `registration_car_car_id_fk` FOREIGN KEY (`car_id`) REFERENCES `car` (`id`) ON DELETE CASCADE,
  CONSTRAINT `registration_car_registration_id_fk` FOREIGN KEY (`registration_id`) REFERENCES `show_registration` (`id`) ON DELETE CASCADE,
  CONSTRAINT `show_registration_car_judging_type_id_fk` FOREIGN KEY (`judging_type_id`) REFERENCES `judging_type` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1396 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_registration_car_judging` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `question_id` int(10) unsigned NOT NULL,
  `show_registration_car_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `data` varchar(64) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `srcj_show_registration_car_id_fk` (`show_registration_car_id`),
  KEY `srcj_user_id_fk` (`user_id`),
  KEY `srcj_question_id_fk` (`question_id`),
  CONSTRAINT `srcj_question_id_fk` FOREIGN KEY (`question_id`) REFERENCES `judging_form_question` (`id`),
  CONSTRAINT `srcj_show_registration_car_id_fk` FOREIGN KEY (`show_registration_car_id`) REFERENCES `show_registration_car` (`id`),
  CONSTRAINT `srcj_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=426 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_registration_car_judging_image` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `answer_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `srcji_answer_id_fk` (`answer_id`),
  CONSTRAINT `srcji_answer_id_fk` FOREIGN KEY (`answer_id`) REFERENCES `show_registration_car_judging` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `show_sponsor` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `show_id` smallint(6) NOT NULL,
  `sponsor_id` int(10) unsigned NOT NULL,
  `is_primary` tinyint(1) unsigned DEFAULT 0,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `type_id` tinyint(3) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `show_id` (`show_id`,`sponsor_id`),
  KEY `show_sponsor_sponsor_id_fk` (`sponsor_id`),
  KEY `show_sponsor_type_id_fk` (`type_id`),
  CONSTRAINT `show_sponsor_show_id_fk` FOREIGN KEY (`show_id`) REFERENCES `car_show` (`id`),
  CONSTRAINT `show_sponsor_sponsor_id_fk` FOREIGN KEY (`sponsor_id`) REFERENCES `sponsor` (`id`),
  CONSTRAINT `show_sponsor_type_id_fk` FOREIGN KEY (`type_id`) REFERENCES `sponsor_type` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=192 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_sponsor2` (
  `id` int(10) unsigned NOT NULL,
  `show_id` smallint(6) NOT NULL,
  `sponsor_id` int(10) unsigned NOT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`show_id`,`sponsor_id`),
  KEY `show_sponsor_sponsor_id_fk` (`sponsor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `show_sponsor_bak` (
  `id` int(10) unsigned NOT NULL DEFAULT 0,
  `show_id` smallint(6) NOT NULL,
  `sponsor_id` int(10) unsigned NOT NULL,
  `is_primary` tinyint(1) unsigned DEFAULT 0,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `type_id` tinyint(3) unsigned DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `special` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `show_id` smallint(5) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `adult_only` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `registered_only` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `paid_only` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `max_entries` smallint(5) unsigned DEFAULT NULL,
  `date_start` datetime DEFAULT NULL,
  `date_end` datetime DEFAULT NULL,
  `amount` float(6,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `special_show_id` (`show_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `special_entry` (
  `special_id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `guest_number` int(10) unsigned DEFAULT NULL,
  `date_entered` datetime DEFAULT NULL,
  `is_paid` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `date_paid` datetime DEFAULT NULL,
  `amount_paid` float(6,2) DEFAULT NULL,
  UNIQUE KEY `special_entry_uk` (`special_id`,`user_id`,`guest_number`),
  KEY `special_entry_is_paid` (`is_paid`),
  KEY `special_entry_user_id` (`user_id`),
  KEY `special_entry_guest_number` (`guest_number`),
  KEY `special_entry_date_entered` (`date_entered`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `sponsor` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `URL` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `short_description` varchar(512) DEFAULT NULL,
  `is_enabled` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `internal_notes` text DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='sponsors table listing shown in sponsors.php';

CREATE TABLE `sponsor2` (
  `name` varchar(255) DEFAULT NULL,
  `URL` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `short_description` varchar(512) DEFAULT NULL,
  `type_id` tinyint(3) unsigned DEFAULT NULL,
  `has_image` tinyint(1) unsigned DEFAULT 0,
  `is_visible` tinyint(1) unsigned DEFAULT 0,
  `internal_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `sponsor_bak` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `show_id` smallint(6) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `URL` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `short_description` varchar(512) DEFAULT NULL,
  `type_id` tinyint(3) unsigned DEFAULT NULL,
  `has_image` tinyint(1) unsigned DEFAULT 0,
  `is_visible` tinyint(1) unsigned DEFAULT 0,
  `internal_notes` text DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `show_id` (`show_id`),
  KEY `sponsor_type_fk` (`type_id`),
  CONSTRAINT `sponsor_show_id_fk` FOREIGN KEY (`show_id`) REFERENCES `car_show` (`id`),
  CONSTRAINT `sponsor_type_fk` FOREIGN KEY (`type_id`) REFERENCES `sponsor_type` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='sponsors table listing shown in sponsors.php';

CREATE TABLE `sponsor_type` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `sponsor_type_bak` (
  `id` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `name` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `user` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `date_registered` datetime NOT NULL,
  `first` varchar(20) DEFAULT NULL,
  `last` varchar(20) DEFAULT NULL,
  `email` varchar(60) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `phone2` varchar(15) DEFAULT NULL,
  `location_id` int(10) unsigned NOT NULL,
  `is_confirmed` tinyint(4) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `heard_from` varchar(500) DEFAULT NULL,
  `password` varchar(64) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `date_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `type_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `has_image` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `address1` varchar(60) NOT NULL,
  `address2` varchar(60) DEFAULT NULL,
  `city` varchar(60) NOT NULL,
  `state` varchar(10) NOT NULL,
  `zip` varchar(10) NOT NULL,
  `lattitude` double DEFAULT NULL,
  `longitude` double DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `confirm_info` (`is_confirmed`),
  KEY `date` (`date_registered`),
  KEY `location_id` (`location_id`),
  KEY `user_user_type_id` (`type_id`),
  CONSTRAINT `user_type_id_fk` FOREIGN KEY (`type_id`) REFERENCES `user_type` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=869 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci PACK_KEYS=0;

CREATE TABLE `user_guest` (
  `user_id` int(10) unsigned NOT NULL,
  `first` varchar(60) NOT NULL DEFAULT '',
  `last` varchar(60) NOT NULL DEFAULT '',
  `show_id` smallint(6) NOT NULL,
  `waiver_id` int(10) unsigned DEFAULT NULL,
  `type_id` tinyint(3) unsigned NOT NULL,
  `has_signed` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `signature_image` blob DEFAULT NULL,
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `registration_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `email` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `has_signed` (`has_signed`),
  KEY `user_guest_show_id_fk` (`show_id`),
  KEY `user_guest_type_id_fk` (`type_id`),
  KEY `user_guest_user_id_fk` (`user_id`),
  KEY `show_registration_registration_id_fk` (`registration_id`),
  KEY `user_guest_waiver_id_fk` (`waiver_id`),
  CONSTRAINT `show_registration_registration_id_fk` FOREIGN KEY (`registration_id`) REFERENCES `show_registration` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_guest_show_id_fk` FOREIGN KEY (`show_id`) REFERENCES `car_show` (`id`),
  CONSTRAINT `user_guest_type_id_fk` FOREIGN KEY (`type_id`) REFERENCES `user_guest_type` (`id`),
  CONSTRAINT `user_guest_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`),
  CONSTRAINT `user_guest_waiver_id_fk` FOREIGN KEY (`waiver_id`) REFERENCES `waiver` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1129 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `user_guest_type` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(30) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `user_preferences` (
  `user_id` int(10) unsigned NOT NULL,
  `show_car_on_attendees_page` tinyint(1) NOT NULL DEFAULT 1,
  `allow_future_show_notifications` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `allow_system_email` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `show_login_welcome` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `allow_private_message` tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `send_upcoming_show_notification` (`allow_future_show_notifications`),
  KEY `show_car_on_attendees_page` (`show_car_on_attendees_page`),
  CONSTRAINT `user_preferences_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=805 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `user_preferences_bak` (
  `user_id` int(10) unsigned NOT NULL,
  `show_car_on_attendees_page` tinyint(1) NOT NULL DEFAULT 1,
  `allow_future_show_notifications` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `allow_system_email` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `id` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `show_login_welcome` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `allow_private_message` tinyint(1) unsigned NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `user_type` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `waiver` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(32) NOT NULL,
  `waiver_id` varchar(32) DEFAULT NULL,
  `show_registration_id` int(10) unsigned DEFAULT NULL,
  `guest_id` int(10) unsigned DEFAULT NULL,
  `adhoc_show_id` int(10) unsigned DEFAULT NULL,
  `request_waiver_url` varchar(512) DEFAULT NULL,
  `has_signed` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `waiver_data` text DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `has_pdf` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  KEY `waiver_show_registration_id_fk` (`show_registration_id`),
  KEY `waiver_guest_id_fk` (`guest_id`),
  CONSTRAINT `waiver_guest_id_fk` FOREIGN KEY (`guest_id`) REFERENCES `user_guest` (`id`) ON DELETE CASCADE,
  CONSTRAINT `waiver_show_registration_id_fk` FOREIGN KEY (`show_registration_id`) REFERENCES `show_registration` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1410 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

