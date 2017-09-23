# ************************************************************
# Sequel Pro SQL dump
# Version 4004
#
# http://www.sequelpro.com/
# http://code.google.com/p/sequel-pro/
#
# Host: 127.0.0.1 (MySQL 5.6.10)
# Database: directus2
# Generation Time: 2014-07-30 23:13:17 +0000
# ************************************************************


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


# Dump of table directus_activity
# ------------------------------------------------------------

CREATE TABLE `directus_activity` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(100) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `identifier` varchar(100) DEFAULT NULL,
  `table_name` varchar(100) NOT NULL DEFAULT '',
  `row_id` int unsigned DEFAULT NULL,
  `user` int unsigned NOT NULL DEFAULT '0',
  `data` text,
  `delta` text NOT NULL,
  `parent_id` int unsigned DEFAULT NULL,
  `parent_table` varchar(100) DEFAULT NULL,
  `parent_changed` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Did the top-level record in the change set alter (scalar values/many-to-one relationships)? Or only the data within its related foreign collection records? (*toMany)',
  `datetime` datetime DEFAULT NULL,
  `logged_ip` varchar(20) DEFAULT NULL,
  `user_agent` varchar(256) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Contains history of revisions';

# Dump of table directus_bookmarks
# ------------------------------------------------------------

CREATE TABLE `directus_bookmarks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user` int unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `icon_class` varchar(255) DEFAULT NULL,
  `active` tinyint(1) DEFAULT NULL,
  `section` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


# Dump of table directus_columns
# ------------------------------------------------------------

CREATE TABLE `directus_columns` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `table_name` varchar(64) NOT NULL DEFAULT '',
  `column_name` varchar(64) NOT NULL DEFAULT '',
  `data_type` varchar(64) DEFAULT NULL,
  `ui` varchar(64) DEFAULT NULL,
  `relationship_type` varchar(20) DEFAULT NULL,
  `related_table` varchar(64) DEFAULT NULL,
  `junction_table` varchar(64) DEFAULT NULL,
  `junction_key_left` varchar(64) DEFAULT NULL,
  `junction_key_right` varchar(64) DEFAULT NULL,
  `hidden_input` tinyint(1) NOT NULL DEFAULT '0',
  `hidden_list` tinyint(1) NOT NULL DEFAULT '0',
  `required` tinyint(1) NOT NULL DEFAULT '0',
  `sort` int DEFAULT NULL,
  `comment` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `table-column` (`table_name`,`column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

LOCK TABLES `directus_columns` WRITE;
/*!40000 ALTER TABLE `directus_columns` DISABLE KEYS */;

INSERT INTO `directus_columns` (`id`, `table_name`, `column_name`, `data_type`, `ui`, `hidden_input`, `hidden_list`, `required`, `relationship_type`, `related_table`, `junction_table`, `junction_key_left`, `junction_key_right`, `sort`, `comment`)
VALUES
  (1,'directus_users','group',NULL,'many_to_one',0,0,0,'MANYTOONE','directus_groups',NULL,NULL,'group_id',NULL,''),
  (2,'directus_users','avatar_file_id','INT','single_file',0,0,0,'MANYTOONE','directus_files',NULL,NULL,'avatar_file_id',NULL,'');

/*!40000 ALTER TABLE `directus_columns` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table directus_files
# ------------------------------------------------------------

CREATE TABLE `directus_files` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `active` tinyint(1) DEFAULT '1',
  `name` varchar(255) DEFAULT NULL,
  `url` varchar(2000) DEFAULT NULL,
  `title` varchar(255) DEFAULT '',
  `location` varchar(200) DEFAULT NULL,
  `caption` text,
  `type` varchar(50) DEFAULT '',
  `charset` varchar(50) DEFAULT '',
  `tags` varchar(255) DEFAULT '',
  `width` int unsigned DEFAULT '0',
  `height` int unsigned DEFAULT '0',
  `size` int unsigned DEFAULT '0',
  `embed_id` varchar(200) DEFAULT NULL,
  `user` int unsigned NOT NULL,
  `date_uploaded` datetime DEFAULT NULL,
  `storage_adapter` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Directus Files Storage';



# Dump of table directus_groups
# ------------------------------------------------------------

CREATE TABLE `directus_groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `restrict_to_ip_whitelist` TEXT DEFAULT NULL,
  `show_activity` tinyint(1) NOT NULL DEFAULT '1',
  `show_messages` tinyint(1) NOT NULL DEFAULT '1',
  `show_users` tinyint(1) NOT NULL DEFAULT '1',
  `show_files` tinyint(1) NOT NULL DEFAULT '1',
  `nav_override` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

LOCK TABLES `directus_groups` WRITE;
/*!40000 ALTER TABLE `directus_groups` DISABLE KEYS */;

INSERT INTO `directus_groups` (`id`, `name`, `description`, `restrict_to_ip_whitelist`, `show_activity`, `show_messages`, `show_users`, `show_files`, `nav_override`)
VALUES
  (1,'Administrator',NULL,NULL,1,1,1,1,NULL);

/*!40000 ALTER TABLE `directus_groups` ENABLE KEYS */;
UNLOCK TABLES;



# Dump of table directus_messages
# ------------------------------------------------------------

CREATE TABLE `directus_messages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `from` int unsigned DEFAULT NULL,
  `subject` varchar(100) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `datetime` TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `attachment` int unsigned DEFAULT NULL,
  `response_to` int unsigned DEFAULT NULL,
  `comment_metadata` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;



# Dump of table directus_messages_recipients
# ------------------------------------------------------------

CREATE TABLE `directus_messages_recipients` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `message_id` int unsigned NOT NULL,
  `recipient` int unsigned NOT NULL,
  `read` tinyint(1) NOT NULL,
  `group` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;



# Dump of table directus_preferences
# ------------------------------------------------------------

CREATE TABLE `directus_preferences` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user` int unsigned DEFAULT NULL,
  `table_name` varchar(64) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `columns_visible` varchar(300) DEFAULT NULL,
  `sort` varchar(64) DEFAULT 'id',
  `sort_order` varchar(5) DEFAULT 'asc',
  `status` varchar(5) DEFAULT '3',
  `search_string` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user` (`user`,`table_name`,`title`),
  UNIQUE KEY `pref_title_constraint` (`user`,`table_name`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

# Dump of table directus_privileges
# ------------------------------------------------------------

CREATE TABLE `directus_privileges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `table_name` varchar(255) CHARACTER SET latin1 NOT NULL,
  `group_id` int unsigned NOT NULL,
  `read_field_blacklist` varchar(1000) CHARACTER SET latin1 DEFAULT NULL,
  `write_field_blacklist` varchar(1000) CHARACTER SET latin1 DEFAULT NULL,
  `nav_listed` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=not listed in navs, 1=listed in navs',
  `allow_view` tinyint(1) DEFAULT '0' COMMENT '0=no viewing, 1=view your records, 2=view everyones records',
  `allow_add` tinyint(1) DEFAULT '0' COMMENT '0=no adding, 1=add records',
  `allow_edit` tinyint(1) DEFAULT '0' COMMENT '0=no editing, 1=edit your records, 2=edit everyones records',
  `allow_delete` tinyint(1) DEFAULT '0' COMMENT '0=no deleting, 1=delete your records, 2=delete everyones records',
  `allow_alter` tinyint(1) DEFAULT '0' COMMENT '0=no altering, 1=allow altering',
  `status_id` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'NULL=permissions apply to records with any status, [0,1,2,etc]=permissions apply to records with this status',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

LOCK TABLES `directus_privileges` WRITE;
/*!40000 ALTER TABLE `directus_privileges` DISABLE KEYS */;

INSERT INTO `directus_privileges` (`id`, `table_name`, `group_id`, `read_field_blacklist`, `write_field_blacklist`, `nav_listed`, `allow_view`, `allow_add`, `allow_edit`, `allow_delete`, `allow_alter`, `status_id`)
VALUES
  (1,'directus_activity',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (2,'directus_columns',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (3,'directus_groups',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (4,'directus_files',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (5,'directus_messages',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (6,'directus_preferences',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (7,'directus_privileges',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (8,'directus_settings',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (9,'directus_tables',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (10,'directus_ui',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (11,'directus_users',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (12,'directus_messages_recipients',1,NULL,NULL,1,2,1,2,2,1,NULL),
  (13,'directus_bookmarks',1,NULL,NULL,1,2,1,2,2,1,NULL);

/*!40000 ALTER TABLE `directus_privileges` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table directus_settings
# ------------------------------------------------------------

CREATE TABLE `directus_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `collection` varchar(250) DEFAULT NULL,
  `name` varchar(250) DEFAULT NULL,
  `value` varchar(250) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `Unique Collection and Name` (`collection`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

LOCK TABLES `directus_settings` WRITE;
/*!40000 ALTER TABLE `directus_settings` DISABLE KEYS */;

INSERT INTO `directus_settings` (`id`, `collection`, `name`, `value`)
VALUES
  (1,'global','cms_user_auto_sign_out','60'),
  (2,'global','project_name','Directus'),
  (3,'global','project_url','http://examplesite.dev/'),
  (4,'global','rows_per_page','200'),
  (5,'files','thumbnail_quality','100'),
  (6,'files','thumbnail_size','200'),
  (7,'global','cms_thumbnail_url',''),
  (8,'files','file_naming','file_id'),
  (9,'files','thumbnail_crop_enabled','1'),
  (10,'files','youtube_api_key','');

/*!40000 ALTER TABLE `directus_settings` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table directus_tables
# ------------------------------------------------------------

CREATE TABLE `directus_tables` (
  `table_name` varchar(64) NOT NULL DEFAULT '',
  `hidden` tinyint(1) NOT NULL DEFAULT '0',
  `single` tinyint(1) NOT NULL DEFAULT '0',
  `footer` tinyint(1) DEFAULT '0',
  `list_view` varchar(200) DEFAULT NULL,
  `column_groupings` varchar(255) DEFAULT NULL,
  `primary_column` varchar(255) DEFAULT NULL,
  `user_create_column` varchar(64) DEFAULT NULL,
  `user_update_column` varchar(64) DEFAULT NULL,
  `date_create_column` varchar(64) DEFAULT NULL,
  `date_update_column` varchar(64) DEFAULT NULL,
  `filter_column_blacklist` text,
  PRIMARY KEY (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

LOCK TABLES `directus_tables` WRITE;
/*!40000 ALTER TABLE `directus_tables` DISABLE KEYS */;

INSERT INTO `directus_tables` (`table_name`, `hidden`, `single`, `footer`, `list_view`, `column_groupings`, `primary_column`, `user_create_column`, `user_update_column`, `date_create_column`, `date_update_column`, `filter_column_blacklist`)
VALUES
  ('directus_messages_recipients',1,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
  ('directus_bookmarks',1,0,0,NULL,NULL,NULL,'user',NULL,NULL,NULL,NULL),
  ('directus_files',1,0,0,NULL,NULL,NULL,'user',NULL,NULL,NULL,NULL),
  ('directus_preferences',1,0,0,NULL,NULL,NULL,'user',NULL,NULL,NULL,NULL),
  ('directus_users',1,0,0,NULL,NULL,NULL,'id',NULL,NULL,NULL,NULL);

/*!40000 ALTER TABLE `directus_tables` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table directus_ui
# ------------------------------------------------------------

CREATE TABLE `directus_ui` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `table_name` varchar(64) DEFAULT NULL,
  `column_name` varchar(64) DEFAULT NULL,
  `ui_name` varchar(200) DEFAULT NULL,
  `name` varchar(200) DEFAULT NULL,
  `value` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique` (`table_name`,`column_name`,`ui_name`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `directus_ui` (`table_name`, `column_name`, `ui_name`, `name`, `value`)
VALUES
  ('directus_users','avatar_file_id', 'single_file', 'allowed_filetypes', 'image/*');

# Dump of table directus_users
# ------------------------------------------------------------

CREATE TABLE `directus_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `active` tinyint(1) DEFAULT '1',
  `first_name` varchar(50) DEFAULT '',
  `last_name` varchar(50) DEFAULT '',
  `email` varchar(255) DEFAULT '',
  `password` varchar(255) DEFAULT '',
  `salt` varchar(255) DEFAULT '',
  `token` varchar(255) NOT NULL,
  `access_token` varchar(255) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT '',
  `reset_expiration` datetime DEFAULT NULL,
  `position` varchar(255) DEFAULT '',
  `email_messages` tinyint(1) DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `last_access` datetime DEFAULT NULL,
  `last_page` varchar(255) DEFAULT '',
  `ip` varchar(50) DEFAULT '',
  `group` int DEFAULT NULL,
  `avatar` varchar(500) DEFAULT NULL,
  `avatar_file_id` int DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(2) DEFAULT NULL,
  `zip` varchar(10) DEFAULT NULL,
  `language` varchar(8) DEFAULT 'en',
  `timezone` varchar(32) DEFAULT 'America/New_York',
  PRIMARY KEY (`id`),
  UNIQUE KEY `directus_users_email_unique` (`email`),
  UNIQUE KEY `directus_users_token_unique` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

LOCK TABLES `directus_users` WRITE;
/*!40000 ALTER TABLE `directus_users` DISABLE KEYS */;

INSERT INTO `directus_users` (`id`, `active`, `first_name`, `last_name`, `email`, `password`, `salt`, `token`, `reset_token`, `reset_expiration`, `position`, `email_messages`, `last_login`, `last_access`, `last_page`, `ip`, `group`, `avatar`, `location`, `phone`, `address`, `city`, `state`, `zip`, `language`)
VALUES
  (1,1,'','','admin@example.com','1202c7d0d07308471bc9118bf13647d225c625e8','5329e597d9afa','','',NULL,'',1,'2014-07-30 18:58:24','2014-07-30 18:59:00','{\"path\":\"tables/1\",\"route\":\"entry\"}','',1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'en');

/*!40000 ALTER TABLE `directus_users` ENABLE KEYS */;
UNLOCK TABLES;



/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
