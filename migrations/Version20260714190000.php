<?php

declare(strict_types=1);

namespace DoctrineMigrations\Integration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Baseline schema for integration module from s.controleonline.com";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS=0');
        $this->addSql('CREATE TABLE IF NOT EXISTS `integration` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `queue_status_id` int(11) NOT NULL,
  `body` longtext CHARACTER SET utf8 NOT NULL,
  `headers` longtext CHARACTER SET utf8,
  `queue_name` varchar(190) CHARACTER SET utf8 NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `device_id` int(11) DEFAULT NULL,
  `people_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `retry` int(11) NOT NULL DEFAULT \'0\',
  PRIMARY KEY (`id`),
  KEY `IDX_75EA56E0FB7336F0` (`queue_name`),
  KEY `queue_status_id` (`queue_status_id`),
  KEY `user_id` (`user_id`),
  KEY `device_id` (`device_id`),
  KEY `people_id` (`people_id`),
  CONSTRAINT `integration_ibfk_1` FOREIGN KEY (`queue_status_id`) REFERENCES `status` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `integration_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `integration_ibfk_3` FOREIGN KEY (`device_id`) REFERENCES `device` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `integration_ibfk_4` FOREIGN KEY (`people_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=563255 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS=0');
        $this->addSql('DROP TABLE IF EXISTS `integration`');
        $this->addSql('SET FOREIGN_KEY_CHECKS=1');
    }
}
