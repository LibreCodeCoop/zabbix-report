<?php
namespace App\Repository;

use Exception;
use PDO;

class ZabbixReportRepository
{
    private $conn;
    private $filter;
    public function __construct(array $params)
    {
        $this->conn = $params['conn'];
        $this->filter = isset($params['filter'])?$params['filter']:null;
    }

    public function getQueryConsolidado()
    {
        if ($columns = $this->filter->get('columns')) {
            foreach ($columns as $column) {
                $cols[$column['name']] = $column['search']['value'];
            }
            if (isset($cols['downtime'])) {
                list($cols['downtime'], $cols['downtime-time']) = explode(' ', $cols['downtime'] . ' ');
            }
            if (isset($cols['uptime'])) {
                list($cols['uptime'], $cols['uptime-time']) = explode(' ', $cols['uptime'] . ' ');
            }
            if ($this->filter->get('search')) {
                parse_str($this->filter->get('search')['value'], $body);
                $cols = array_merge($cols, $body);
            }
        } elseif (!empty($this->filter->get('downtime'))) {
            $cols = $this->filter->all();
        }
        if(!isset($cols) || !$this->getValue($cols, 'uptime') || !$this->getValue($cols, 'downtime')) {
            return;
        }

        $value = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue($cols, 'downtime'). ' 00:00:00');
        if ($value) {
            if ($this->getValue($cols, 'downtime-time')) {
                $startTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue($cols, 'downtime') . ' ' . $this->getValue($cols, 'downtime-time').':00');
            } else {
                $startTime = $value;
            }
        }
        if (empty($startTime)) {
            return;
        }
        $value = \DateTime::createFromFormat('Y-m-d', $this->getValue($cols, 'uptime'));
        if ($value) {
            if ($this->getValue($cols, 'uptime-time')) {
                $recoveryTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue($cols, 'uptime') . ' ' . $this->getValue($cols, 'uptime-time').':59');
            } else {
                $recoveryTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue($cols, 'uptime') . ' 23:59:59');
            }
        }
        if (empty($recoveryTime)) {
            return;
        }

        $decimalPlaces = $_ENV['DECIMAL_PLACES'];
        $q1 = $this->createQueryBuilder();
        $q1->addSelect('host');
        if ($this->getValue($cols, 'item') || !$this->getValue($cols, 'icmp')) {
            $q1->addSelect('onu AS item');
        }
        $q1->addSelect(
            <<<SELECT
            CONCAT(
                CASE WHEN FLOOR(downtime / 3600) > 9 THEN FLOOR(downtime / 3600) ELSE LPAD(FLOOR(downtime / 3600), 2, 0) END,':',
                LPAD(FLOOR((downtime % 3600)/60), 2, 0), ':',
                LPAD(downtime % 60, 2, 0)
            ) AS downtime
            SELECT
        );
        $q1->addSelect("FROM_UNIXTIME(mindatahora, '%Y-%m-%d %H:%i:%s') AS mindatahora");
        $q1->addSelect("FROM_UNIXTIME(maxdatahora, '%Y-%m-%d %H:%i:%s') AS maxdatahora");
        $q1->addSelect("ROUND((downtime * 100 ) / total_time, $decimalPlaces) AS percent_downtime");
        $q1->addSelect(
            <<<SELECT
            CONCAT(
                CASE WHEN FLOOR((total_time - downtime) / 3600) > 9 THEN FLOOR((total_time - downtime) / 3600) ELSE LPAD(FLOOR((total_time - downtime) / 3600), 2, 0) END,':',
                LPAD(FLOOR(((total_time - downtime) % 3600)/60), 2, 0), ':',
                LPAD((total_time - downtime) % 60, 2, 0)
            ) AS uptime
            SELECT
        );
        $q1->addSelect("ROUND(((total_time - downtime) * 100 ) / total_time, $decimalPlaces) AS percent_uptime");

        $q3 = $this->getBaseQuery();
        $q3->addSelect("REGEXP_REPLACE(start.name, \"(.*) (is Down|is Up)\", '\\\\1') AS onu");
        $q3->addSelect("CAST(recovery.clock - start.clock AS UNSIGNED) AS duration");
        $q3->addSelect("start.clock AS start");
        $q3->addSelect("recovery.clock AS recovery");
        $q3->andWhere($q3->expr()->gte('start.clock', ':startTime'));
        $q3->andWhere($q3->expr()->lte('recovery.clock', ':recoveryTime'));
        $q1->setParameter('startTime', $startTime->format('U'));
        $q1->setParameter('recoveryTime', $recoveryTime->format('U'));
        if ($this->getValue($cols, 'host')) {
            $value = substr(trim(strtolower($this->getValue($cols, 'host'))), 0, 30);
            $q3->andWhere(
                $q3->expr()->orX(
                    $q3->expr()->like('LOWER(hosts.host)', ':host'),
                    $q3->expr()->like('LOWER(alert_start.message)', ':host')
                )
            );
            $q1->setParameter('host', '%' . $value . '%');
        }
        if ($this->getValue($cols, 'item')) {
            $value = substr(trim(strtolower($this->getValue($cols, 'item'))), 0, 30);
            $q3->andWhere($q3->expr()->like('LOWER(start.name)', ':startName'));
            $q1->setParameter('startName', '%' . $value . '%');
        }
        if ($this->getValue($cols, 'icmp') == 1) {
            $q3->andWhere("start.name LIKE '%ICMP%'");
            $q3->andWhere("LOWER(start.name) NOT REGEXP 'onu_[0-9/: ]+'");
        } else {
            $q3->andWhere("start.name NOT LIKE '%ICMP%'");
            $q3->andWhere("LOWER(start.name) REGEXP 'onu_[0-9/: ]+'");
        }

        $q2 = $this->createQueryBuilder();
        $q2->select([
            'host',
            'onu',
            'MIN(start) AS mindatahora',
            'MAX(recovery) AS maxdatahora',
            'SUM(duration) AS downtime',
            ':recoveryTime - :startTime AS total_time'
        ]);
        foreach ($q3->getParameters() as $parameter => $value) {
            $q1->setParameter($parameter, $value, $q3->getParameterType($parameter));
        }
        $q2->from("($q3)", 'x');
        $q2->groupBy(['host', 'onu']);
        $q2->addOrderBy('host');
        $q2->addOrderBy('onu');
        $q1->from("($q2)", 'x2');
        return $q1;
    }

    public function getQueryDescritivo()
    {
        if ($columns = $this->filter->get('columns')) {
            foreach ($columns as $column) {
                $cols[$column['name']] = $column['search']['value'];
            }
            if (isset($cols['downtime'])) {
                list($cols['downtime'], $cols['downtime-time']) = explode(' ', $cols['downtime'] . ' ');
            }
            if (isset($cols['uptime'])) {
                list($cols['uptime'], $cols['uptime-time']) = explode(' ', $cols['uptime'] . ' ');
            }
            if ($this->filter->get('search')) {
                parse_str($this->filter->get('search')['value'], $body);
                $cols = array_merge($cols, $body);
            }
        } elseif (!empty($this->filter->get('downtime'))) {
            $cols = $this->filter->all();
        }
        if(!isset($cols) || !$this->getValue($cols, 'uptime') || !$this->getValue($cols, 'downtime')) {
            return;
        }

        $value = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue($cols, 'downtime'). ' 00:00:00');
        if ($value) {
            if ($this->getValue($cols, 'downtime-time')) {
                $startTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue($cols, 'downtime') . ' ' . $this->getValue($cols, 'downtime-time').':00');
            } else {
                $startTime = $value;
            }
        }
        if (empty($startTime)) {
            return;
        }
        $value = \DateTime::createFromFormat('Y-m-d', $this->getValue($cols, 'uptime'));
        if ($value) {
            if ($this->getValue($cols, 'uptime-time')) {
                $recoveryTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue($cols, 'uptime') . ' ' . $this->getValue($cols, 'uptime-time').':59');
            } else {
                $recoveryTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue($cols, 'uptime') . ' 23:59:59');
            }
        }
        if (empty($recoveryTime)) {
            return;
        }

        $q1 = $this->createQueryBuilder();
        $q1->addSelect('host');
        if ($this->getValue($cols, 'item') || !$this->getValue($cols, 'icmp')) {
            $q1->addSelect('onu AS item');
        }
        $q1->addSelect("FROM_UNIXTIME(start, '%Y-%m-%d %H:%i:%s') AS start");
        $q1->addSelect("FROM_UNIXTIME(recovery, '%Y-%m-%d %H:%i:%s') AS recovery");
        $q1->addSelect(
            <<<SELECT
            CONCAT(
                CASE WHEN FLOOR(duration / 3600) > 9 THEN FLOOR(duration / 3600) ELSE LPAD(FLOOR(duration / 3600), 2, 0) END,':',
                LPAD(FLOOR((duration % 3600)/60), 2, 0), ':',
                LPAD(duration % 60, 2, 0)
            ) AS duration
            SELECT
        );

        $q3 = $this->getBaseQuery();
        $q3->addSelect("REGEXP_REPLACE(start.name, \"(.*) (is Down|is Up)\", '\\\\1') AS onu");
        $q3->addSelect("recovery.clock - start.clock AS duration");
        $q3->addSelect("start.clock AS start");
        $q3->addSelect("recovery.clock AS recovery");
        $q3->andWhere($q3->expr()->gte('start.clock', ':startTime'));
        $q3->andWhere($q3->expr()->lte('recovery.clock', ':recoveryTime'));
        $q1->setParameter('startTime', $startTime->format('U'));
        $q1->setParameter('recoveryTime', $recoveryTime->format('U'));
        if ($this->getValue($cols, 'host')) {
            $value = substr(trim(strtolower($this->getValue($cols, 'host'))), 0, 30);
            $q3->andWhere(
                $q3->expr()->orX(
                    $q3->expr()->like('LOWER(hosts.host)', ':host'),
                    $q3->expr()->like('LOWER(alert_start.message)', ':host')
                )
            );
            $q1->setParameter('host', '%' . $value . '%');
        }
        if ($this->getValue($cols, 'item')) {
            $value = substr(trim(strtolower($this->getValue($cols, 'item'))), 0, 30);
            $q3->andWhere($q3->expr()->like('LOWER(start.name)', ':startName'));
            $q1->setParameter('startName', '%' . $value . '%');
        }
        if ($this->getValue($cols, 'icmp') == 1) {
            $q3->andWhere("start.name LIKE '%ICMP%'");
            $q3->andWhere("LOWER(start.name) NOT REGEXP 'onu_[0-9/: ]+'");
        } else {
            $q3->andWhere("start.name NOT LIKE '%ICMP%'");
            $q3->andWhere("LOWER(start.name) REGEXP 'onu_[0-9/: ]+'");
        }

        $q2 = $this->createQueryBuilder();
        $q2->select([
            'host',
            'onu',
            'start',
            'recovery',
            'CAST(recovery - start AS UNSIGNED) AS duration'
        ]);
        foreach ($q3->getParameters() as $parameter => $value) {
            $q1->setParameter($parameter, $value, $q3->getParameterType($parameter));
        }
        $q2->from("($q3)", 'x');
        $q2->addOrderBy('host');
        $q2->addOrderBy('onu');
        $q1->from("($q2)", 'x2');
        return $q1;
    }

    /**
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    private function getBaseQuery(): \Doctrine\DBAL\Query\QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder();
        $queryBuilder
            ->select(
                <<<QUERY
                CASE WHEN hosts.host IS NOT NULL THEN hosts.host
                    WHEN alert_start.message LIKE '%Host:%' THEN TRIM(TRAILING '\\r' FROM TRIM(TRAILING '\\n' FROM REPLACE(REGEXP_SUBSTR(alert_start.message, 'Host:.*\\n'), 'Host: ', '')))
                    WHEN alert_start.message LIKE '%<b>%' THEN REPLACE(REPLACE(REGEXP_SUBSTR(alert_start.message, '<b>.*</b>'), '<b> ', ''), ' </b>', '')
                END AS host
                QUERY
            )
            ->from('events', 'start')
            ->join('start',         'event_recovery', 'er',          'er.eventid = start.eventid')
            ->join('er',            'events',         'recovery',    'recovery.eventid = er.r_eventid')
            ->leftJoin('start',     'alerts',         'alert_start', 'alert_start.eventid = start.eventid AND alert_start.mediatypeid = 5')
            ->leftJoin('start',     'triggers',       'triggers',    'start.objectid = triggers.triggerid')
            ->leftJoin('triggers',  'functions',      'functions',   'functions.triggerid = triggers.triggerid')
            ->leftJoin('functions', 'items',          'items',       'items.itemid = functions.itemid')
            ->leftJoin('items',     'hosts',          'hosts',       'items.hostid = hosts.hostid')
            ->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->eq('start.severity', 5),
                        $queryBuilder->expr()->eq('recovery.severity', 0)
                    ),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->like('start.name', "'%ICMP%'"),
                        $queryBuilder->expr()->comparison('LOWER(start.name)', 'REGEXP', "'onu_[0-9/: ]+'")
                    ),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->andX(
                            $queryBuilder->expr()->isNotNull('hosts.host'),
                            $queryBuilder->expr()->neq('hosts.host', "''")
                        ),
                        $queryBuilder->expr()->like('alert_start.message', "'%Host:%'"),
                        $queryBuilder->expr()->like('alert_start.message', "'%<b>%'")
                    )
                )
            );
        return $queryBuilder;
    }

    public function getBaseReportQuery($filter)
    {
        $cols = $filter->all();
        $q = $this->createQueryBuilder();
        $q
            ->addSelect("start.eventid")
            ->addSelect(
                <<<QUERY
                CASE WHEN hosts.host IS NOT NULL THEN hosts.host
                    WHEN alert_start.message LIKE '%Host:%' THEN TRIM(TRAILING '\\r' FROM TRIM(TRAILING '\\n' FROM REPLACE(REGEXP_SUBSTR(alert_start.message, 'Host:.*\\n'), 'Host: ', '')))
                    WHEN alert_start.message LIKE '%<b>%' THEN REPLACE(REPLACE(REGEXP_SUBSTR(alert_start.message, '<b>.*</b>'), '<b> ', ''), ' </b>', '')
                END AS host
                QUERY
            )
            ->addSelect(
                <<<QUERY
                CASE WHEN start.name LIKE '%ICMP%' OR LOWER(start.name) NOT REGEXP 'onu_[0-9/: ]+' THEN 1
                     ELSE 0
                END as icmp
                QUERY
            )
            ->addSelect("REGEXP_REPLACE(start.name, \"(.*) (is Down|is Up)\", '\\\\1') AS name")
            ->addSelect("FROM_UNIXTIME(start.clock, '%Y-%m-%d') AS start_date")
            ->addSelect("FROM_UNIXTIME(start.clock, '%Y-%m-%d %H:%i:%s') AS start_time")
            ->addSelect("FROM_UNIXTIME(recovery.clock, '%Y-%m-%d') AS recovery_date")
            ->addSelect("FROM_UNIXTIME(recovery.clock, '%Y-%m-%d %H:%i:%s') AS recovery_time")
            ->addSelect("FROM_UNIXTIME(start.clock,'%w') AS weekday")
            ->from('events',        'start')
            ->leftJoin('start',         'event_recovery', 'er',          'er.eventid = start.eventid')
            ->leftJoin('er',            'events',         'recovery',    'recovery.eventid = er.r_eventid')
            ->leftJoin('start',     'alerts',         'alert_start', 'alert_start.eventid = start.eventid AND alert_start.mediatypeid = 5')
            ->leftJoin('start',     'triggers',       'triggers',    'start.objectid = triggers.triggerid')
            ->leftJoin('triggers',  'functions',      'functions',   'functions.triggerid = triggers.triggerid')
            ->leftJoin('functions', 'items',          'items',       'items.itemid = functions.itemid')
            ->leftJoin('items',     'hosts',          'hosts',       'items.hostid = hosts.hostid')
            ->where(
                $q->expr()->andX(
                    $q->expr()->orX(
                        $q->expr()->eq('start.severity', 5),
                        $q->expr()->eq('recovery.severity', 0)
                    ),
                    $q->expr()->orX(
                        $q->expr()->like('start.name', "'%ICMP%'"),
                        $q->expr()->comparison('LOWER(start.name)', 'REGEXP', "'onu_[0-9/: ]+'")
                    ),
                    $q->expr()->orX(
                        $q->expr()->andX(
                            $q->expr()->isNotNull('hosts.host'),
                            $q->expr()->neq('hosts.host', "''")
                        ),
                        $q->expr()->like('alert_start.message', "'%Host:%'"),
                        $q->expr()->like('alert_start.message', "'%<b>%'")
                    ),
                    $q->expr()->orX(
                        $q->expr()->andX(
                            $q->expr()->gte('start.clock', ':startTime'),
                            $q->expr()->lte('recovery.clock', ':recoveryTime'),
                        ),
                        $q->expr()->andX(
                            $q->expr()->isNull('recovery.clock'),
                            $q->expr()->lte('start.clock', ':recoveryTime')
                        )
                    )
                )
            );
        $startTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue($cols, 'downtime'));
        $recoveryTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue($cols, 'uptime'));
        $q->setParameter('startTime', $startTime->format('U'));
        $q->setParameter('recoveryTime', $recoveryTime->format('U'));
        return $q;
    }

    private function getValue($columns, $key)
    {
        if (isset($columns[$key]['search'])) {
            return $columns[$key]['search'];
        }
        if (isset($columns[$key])) {
            return $columns[$key];
        }
    }

    /**
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    private function createQueryBuilder()
    {
        return $this->conn->createQueryBuilder();
    }

    public function getAllHosts()
    {
        return $this->getBaseQuery()
            ->groupBy('host')
            ->orderBy('host')
            ->execute()
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getAllItemsByHost(string $host): array
    {
        $q = $this->getBaseQuery();
        $q->select("TRIM(REGEXP_SUBSTR(start.name, 'onu_[0-9/: ]+')) AS onu")
            ->where(
                $q->expr()->andX(
                    $q->expr()->orX(
                        $q->expr()->eq('start.severity', 5),
                        $q->expr()->eq('recovery.severity', 0)
                    ),
                    $q->expr()->orX(
                        $q->expr()->like('LOWER(hosts.host)', ':host'),
                        $q->expr()->like('LOWER(alert_start.message)', ':host')
                    )
                )
            )
            ->groupBy('onu')
            ->having(
                $q->expr()->andX(
                    $q->expr()->neq('onu', "''"),
                    $q->expr()->isNotNull('onu')
                )
            );
        $q->setParameter('host', '%'.substr(trim(strtolower($host)), 0, 30).'%');
        return $q->execute()
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function saveDailyReport($row)
    {
        $insert = <<<QUERY
            INSERT INTO {$_ENV['DB_NAME_SUMMARY']}.base
            (eventid, host, icmp, name, multidate, start_date, start_time, recovery_date, recovery_time, weekday)
            VALUES
            (:eventid, :host, :icmp, :name, :multidate, :start_date, :start_time, :recovery_date, :recovery_time, :weekday)
            QUERY;
        $conn = $this->conn->getWrappedConnection();
        try {
            $stmt = $conn->prepare($insert);
            foreach ($row as $key => $value) {
                $row[':'.$key] = $value;
                unset($row[$key]);
            }
            $stmt->execute($row);
        } catch (Exception $e) { }
    }
}