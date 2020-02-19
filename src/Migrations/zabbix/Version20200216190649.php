<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20200216190649 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Create database and table';
    }

    public function up(Schema $schema) : void
    {
        $reportDatabase = $_ENV['DB_NAME_SUMMARY'];
        $defaultDatabase = $_ENV['DB_NAME'];
        $this->addSql(
            <<<SQL
            CREATE DATABASE {$reportDatabase};
            CREATE TABLE {$reportDatabase}.`base` (
                `eventid` bigint(20) unsigned NOT NULL,
                `host` varchar(128) COLLATE utf8_bin NOT NULL,
                `name` varchar(2048) COLLATE utf8_bin NOT NULL,
                `icmp` tinyint(4) NOT NULL,
                `multidate` tinyint(4) NOT NULL,
                `start_date` date NOT NULL,
                `start_time` datetime NOT NULL,
                `recovery_date` date NOT NULL,
                `recovery_time` datetime NOT NULL,
                `weekday` tinyint(4) NOT NULL,
                PRIMARY KEY (`eventid`,`start_time`,`recovery_time`),
                KEY `base_host_IDX` (`host`) USING BTREE,
                KEY `base_name_IDX` (`name`(1024)) USING BTREE,
                KEY `base_icmp_IDX` (`icmp`) USING BTREE,
                KEY `base_start_date_IDX` (`start_date`) USING BTREE,
                KEY `base_start_time_IDX` (`start_time`) USING BTREE,
                KEY `base_recovery_date_IDX` (`recovery_date`) USING BTREE,
                KEY `base_recovery_time_IDX` (`recovery_time`) USING BTREE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
            USE {$defaultDatabase};
            SQL
        );

    }

    public function down(Schema $schema) : void
    {
        $this->addSql(
            <<<SQL
            DROP DATABASE zabbix_report;
            SQL
        );
    }
}
