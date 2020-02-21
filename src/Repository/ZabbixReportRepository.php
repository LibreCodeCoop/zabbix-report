<?php
namespace App\Repository;

use Exception;
use Symfony\Component\Yaml\Yaml;

class ZabbixReportRepository
{
    private $conn;
    private $filter;
    public function __construct(array $params)
    {
        $this->conn = $params['conn'];
        $this->filter = isset($params['filter'])?$params['filter']:null;
        $this->config = Yaml::parse(file_get_contents(__DIR__.'../../../config/dead_dates.yaml'));
    }

    public function getQueryConsolidado()
    {
        list($startTime, $recoveryTime) = $this->getStartRecoveryTime();

        $decimalPlaces = $_ENV['DECIMAL_PLACES'];

        $q = $this->createQueryBuilder();
        if ($this->getValue('item') || !$this->getValue('icmp')) {
            $q
                ->addSelect('name AS item');
        }
        $q->addSelect('host')
            ->addSelect(
                <<<SELECT
                CASE WHEN start_time > :endNotWorkTime THEN start_datetime
                    ELSE CONCAT(start_date, ' ', :endNotWorkTime)
                    END AS start
                SELECT
            )
            ->addSelect(
                <<<SELECT
                CASE WHEN recovery_time > :startNotWorkTime OR recovery_time = '00:00:00' THEN CONCAT(start_date, ' ', :startNotWorkTime)
                    ELSE recovery_datetime
                    END AS recovery
                SELECT
            )
            ->from($_ENV['DB_NAME_SUMMARY'] . '.base')
            ->andWhere($q->expr()->gte('start_datetime', ':startTime'))
            ->andWhere($q->expr()->lte('recovery_datetime', ':recoveryTime'))
            ->setParameter('startTime', $startTime->format('Y-m-d H:i:s'))
            ->setParameter('recoveryTime', $recoveryTime->format('Y-m-d H:i:s'))
            ->andWhere($q->expr()->eq('icmp', ':icmp'))
            ->setParameter('icmp', $this->getValue('icmp') == 1 ? 1 : 0)
            ->andWhere($q->expr()->notIn('weekday',':weekDays'))
            ->setParameter('weekDays', $this->config['weekday'], \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
            ->andWhere($q->expr()->notIn('start_date', ':notWorkDay'))
            ->setParameter('notWorkDay', $this->config['notWorkDay'], \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
            ->andWhere(
                $q->expr()->orX(
                    $q->expr()->andX(
                        $q->expr()->gte('start_time', ':endNotWorkTime'),
                        $q->expr()->lte('start_time', ':startNotWorkTime'),
                    ),
                    $q->expr()->andX(
                        $q->expr()->gte('recovery_time', ':endNotWorkTime'),
                        $q->expr()->lte('recovery_time', ':startNotWorkTime'),
                    )
                )
            )
            ->setParameter('endNotWorkTime', $this->config['endNotWorkTime'])
            ->setParameter('startNotWorkTime', $this->config['startNotWorkTime']);
        if ($this->getValue('host')) {
            $q->andWhere($q->expr()->eq('host', ':host'));
            $q->setParameter('host', $this->getValue('host'));
        }
        if ($this->getValue('item')) {
            $q->andWhere($q->expr()->eq('name', ':item'));
            $q->setParameter('item', $this->getValue('item'));
        }

        $q2 = $this->createQueryBuilder();
        $q2->addSelect('host');
        if ($this->getValue('item') || !$this->getValue('icmp')) {
            $q2->addSelect('item')
                ->addGroupBy('item');
        }
        $q2->addSelect('MIN(start) mindatahora');
        $q2->addSelect('MAX(recovery) maxdatahora');
        $q2->addSelect('SUM(TIMESTAMPDIFF(SECOND, start, recovery)) AS duration');
        $q2->from("($q)", 'x')
            ->addGroupBy(['host']);
        foreach ($q->getParameters() as $parameter => $value) {
            $q2->setParameter($parameter, $value, $q->getParameterType($parameter));
        }

        $q3 = $this->createQueryBuilder();
        $q3->addSelect('host');
        if ($this->getValue('item') || !$this->getValue('icmp')) {
            $q3->addSelect('item');
        }
        $q3->addSelect(
            <<<SELECT
            CONCAT(
                CASE WHEN FLOOR(duration / 3600) > 9 THEN FLOOR(duration / 3600) ELSE LPAD(FLOOR(duration / 3600), 2, 0) END,':',
                LPAD(FLOOR((duration % 3600)/60), 2, 0), ':',
                LPAD(duration % 60, 2, 0)
            ) AS downtime
            SELECT
        );
        $q3->addSelect('mindatahora');
        $q3->addSelect('maxdatahora');
        $q3->addSelect("ROUND((duration * 100 ) / :totalTime, $decimalPlaces) AS percent_downtime");
        $q3->addSelect(
            <<<SELECT
            CONCAT(
                CASE WHEN FLOOR(duration / 3600) > 9 THEN FLOOR(duration / 3600) ELSE LPAD(FLOOR(duration / 3600), 2, 0) END,':',
                LPAD(FLOOR((duration % 3600)/60), 2, 0), ':',
                LPAD(duration % 60, 2, 0)
            ) AS offline
            SELECT
        );
        $q3->addSelect(
            <<<SELECT
            CONCAT(
                CASE WHEN FLOOR((:totalTime - duration) / 3600) > 9 THEN FLOOR((:totalTime - duration) / 3600) ELSE LPAD(FLOOR((:totalTime - duration) / 3600), 2, 0) END,':',
                LPAD(FLOOR(((:totalTime - duration) % 3600)/60), 2, 0), ':',
                LPAD((:totalTime - duration) % 60, 2, 0)
            ) AS online
            SELECT
        );
        $q3->addSelect("ROUND(((:totalTime - duration) * 100 ) / :totalTime, $decimalPlaces) AS percent_uptime");
        $q3->setParameter('totalTime', $this->totalSeconds($startTime, $recoveryTime));
        $q3->from("($q2)", 'x');
        foreach ($q2->getParameters() as $parameter => $value) {
            $q3->setParameter($parameter, $value, $q2->getParameterType($parameter));
        }
        return $q3;
    }

    public function getQueryDescritivo()
    {
        list($startTime, $recoveryTime) = $this->getStartRecoveryTime();

        $q = $this->createQueryBuilder();
        if ($this->getValue('item') || !$this->getValue('icmp')) {
            $q->addSelect('name AS item');
        }
        $q->addSelect('host')
            ->addSelect(
                <<<SELECT
                CASE WHEN start_time > :endNotWorkTime THEN start_datetime
                    ELSE CONCAT(start_date, ' ', :endNotWorkTime)
                    END AS start
                SELECT
            )
            ->addSelect(
                <<<SELECT
                CASE WHEN recovery_time > :startNotWorkTime OR recovery_time = '00:00:00' THEN CONCAT(start_date, ' ', :startNotWorkTime)
                    ELSE recovery_datetime
                    END AS recovery
                SELECT
            )
            ->from($_ENV['DB_NAME_SUMMARY'] . '.base')
            ->andWhere($q->expr()->gte('start_datetime', ':startTime'))
            ->andWhere($q->expr()->lte('recovery_datetime', ':recoveryTime'))
            ->setParameter('startTime', $startTime->format('Y-m-d H:i:s'))
            ->setParameter('recoveryTime', $recoveryTime->format('Y-m-d H:i:s'))
            ->andWhere($q->expr()->eq('icmp', ':icmp'))
            ->setParameter('icmp', $this->getValue('icmp') == 1 ? 1 : 0)
            ->andWhere($q->expr()->notIn('weekday',':weekDays'))
            ->setParameter('weekDays', $this->config['weekday'], \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
            ->andWhere($q->expr()->notIn('start_date', ':notWorkDay'))
            ->setParameter('notWorkDay', $this->config['notWorkDay'], \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
            ->andWhere(
                $q->expr()->orX(
                    $q->expr()->andX(
                        $q->expr()->gte('start_time', ':endNotWorkTime'),
                        $q->expr()->lte('start_time', ':startNotWorkTime'),
                    ),
                    $q->expr()->andX(
                        $q->expr()->gte('recovery_time', ':endNotWorkTime'),
                        $q->expr()->lte('recovery_time', ':startNotWorkTime'),
                    )
                )
            )
            ->setParameter('endNotWorkTime', $this->config['endNotWorkTime'])
            ->setParameter('startNotWorkTime', $this->config['startNotWorkTime']);
        if ($this->getValue('host')) {
            $q->andWhere($q->expr()->eq('host', ':host'));
            $q->setParameter('host', $this->getValue('host'));
        }
        if ($this->getValue('item')) {
            $q->andWhere($q->expr()->eq('name', ':item'));
            $q->setParameter('item', $this->getValue('item'));
        }

        $q2 = $this->createQueryBuilder();
        if ($this->getValue('item') || !$this->getValue('icmp')) {
            $q2->addSelect('item');
        }
        $q2->addSelect('host')
            ->addSelect('start')
            ->addSelect('recovery')
            ->addSelect(
                <<<SELECT
                CONCAT(
                    CASE WHEN FLOOR(TIMESTAMPDIFF(SECOND, start, recovery) / 3600) > 9 THEN FLOOR(TIMESTAMPDIFF(SECOND, start, recovery) / 3600)
                        ELSE LPAD(FLOOR(TIMESTAMPDIFF(SECOND, start, recovery) / 3600), 2, 0)
                        END,':',
                    LPAD(FLOOR((TIMESTAMPDIFF(SECOND, start, recovery) % 3600)/60), 2, 0), ':',
                    LPAD(TIMESTAMPDIFF(SECOND, start, recovery) % 60, 2, 0)
                ) AS duration
                SELECT
            );
        $q2->from("($q)", 'x');
        foreach ($q->getParameters() as $parameter => $value) {
            $q2->setParameter($parameter, $value, $q->getParameterType($parameter));
        }
        return $q2;
    }

    private function setCols()
    {
        if ($columns = $this->filter->get('columns')) {
            foreach ($columns as $column) {
                $this->cols[$column['name']] = $column['search']['value'];
            }
            if (isset($this->cols['downtime'])) {
                list($this->cols['downtime'], $this->cols['downtime-time']) = explode(' ', $this->cols['downtime'] . ' ');
            }
            if (isset($this->cols['uptime'])) {
                list($this->cols['uptime'], $this->cols['uptime-time']) = explode(' ', $this->cols['uptime'] . ' ');
            }
            if ($this->filter->get('search')) {
                parse_str($this->filter->get('search')['value'], $body);
                $this->cols = array_merge($this->cols, $body);
            }
        } elseif (!empty($this->filter->get('downtime'))) {
            $this->cols = $this->filter->all();
        }
        if(!isset($this->cols) || !$this->getValue('uptime') || !$this->getValue('downtime')) {
            throw new Exception('Valores de filtro inválidos');
        }
    }

    private function getStartRecoveryTime()
    {
        $this->setCols();
        $startTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue('downtime'). ' 00:00:00');
        if ($startTime) {
            if ($this->getValue('downtime-time')) {
                $startTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue('downtime') . ' ' . $this->getValue('downtime-time').':00');
            }
        }
        if (empty($startTime)) {
            throw new Exception('Data início inválida');
        }
        $recoveryTime = \DateTime::createFromFormat('Y-m-d', $this->getValue('uptime'));
        if ($recoveryTime) {
            if ($this->getValue('uptime-time')) {
                $recoveryTime = \DateTime::createFromFormat('Y-m-d H:i:s', $this->getValue('uptime') . ' ' . $this->getValue('uptime-time').':00');
            } else {
                $recoveryTime->add(new \DateInterval('P1D'))->setTime(0,0,0);
            }
        }
        if (empty($recoveryTime)) {
            throw new Exception('Data fim inválida');
        }
        return [$startTime, $recoveryTime];
    }

    public function getBaseReportQuery($filter)
    {
        $this->filter = $filter;
        list($startTime, $recoveryTime) = $this->getStartRecoveryTime();
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
            ->addSelect("FROM_UNIXTIME(start.clock, '%Y-%m-%d %H:%i:%s') AS start_datetime")
            ->addSelect("FROM_UNIXTIME(start.clock, '%H:%i:%s') AS start_time")
            ->addSelect("FROM_UNIXTIME(recovery.clock, '%Y-%m-%d') AS recovery_date")
            ->addSelect("FROM_UNIXTIME(recovery.clock, '%Y-%m-%d %H:%i:%s') AS recovery_datetime")
            ->addSelect("FROM_UNIXTIME(recovery.clock, '%H:%i:%s') AS recovery_time")
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
        $q->setParameter('startTime', $startTime->format('U'));
        $q->setParameter('recoveryTime', $recoveryTime->format('U'));
        return $q;
    }

    private function getValue($key)
    {
        if (isset($this->cols[$key]['search'])) {
            return $this->cols[$key]['search'];
        }
        if (isset($this->cols[$key])) {
            return $this->cols[$key];
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
        return $this->createQueryBuilder()
            ->select('host')
            ->from($_ENV['DB_NAME_SUMMARY'].'.base')
            ->groupBy('host')
            ->orderBy('host')
            ->execute()
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getAllItemsByHost(string $host): array
    {
        $q = $this->createQueryBuilder();
        $q->select("TRIM(REGEXP_SUBSTR(name, 'onu_[0-9/: ]+')) AS text")
            ->addSelect('name AS value')
            ->from($_ENV['DB_NAME_SUMMARY'].'.base')
            ->andWhere(
                $q->expr()->eq('host', ':host'),
                $q->expr()->eq('icmp', 0)
            )
            ->groupBy('name')
            ->orderBy('text')
            ->having(
                $q->expr()->andX(
                    $q->expr()->neq('name', "''"),
                    $q->expr()->isNotNull('name')
                )
            );
        $q->setParameter('host', $host);
        return $q->execute()
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function saveDailyReport($row)
    {
        $insert = "INSERT INTO {$_ENV['DB_NAME_SUMMARY']}.base\n(";
        $insert.= implode(', ', array_keys($row)) . ") VALUES\n(:";
        $insert.= implode(', :', array_keys($row)) . ")";

        $conn = $this->conn->getWrappedConnection();
        try {
            foreach ($row as $key => $value) {
                $row[':'.$key] = $value;
                unset($row[$key]);
            }
            $stmt = $conn->prepare($insert);
            $stmt->execute($row);
        } catch (Exception $e) { }
    }

    private function totalSeconds(\DateTime $start, \DateTime $stop): int
    {
        if ($stop->format('H:i:s') == '00:00:00') {
            $stop->sub(new \DateInterval('P1D'));
        }
        $seconds = 0;
        $stopDate = $stop->format('Y-m-d');
        do {
            $startDate = $start->format('Y-m-d');
            if ($startDate == $stopDate) {
                $start = clone $stop;
            }
            if (in_array($start->format('w'), $this->config['weekday']) || in_array($start->format('Y-m-d'), $this->config['notWorkDay'])) {
                $start->add(new \DateInterval('P1D'))->setTime(0,0,0);
                continue;
            }
            $startTime = $start->format('H:i:s');
            if ($startTime < $this->config['endNotWorkTime']) {
                $startTime = $this->config['endNotWorkTime'];
            }
            $stopTime = $startTime;
            if ($stopTime < $this->config['startNotWorkTime']) {
                $stopTime = $this->config['startNotWorkTime'];
            }
            $startTime = \DateTime::createFromFormat('Y-m-d H:i:s', $startDate . ' '. $startTime);
            $stopTime = \DateTime::createFromFormat('Y-m-d H:i:s', $startDate . ' '. $stopTime);
            $seconds+= $stopTime->getTimestamp() - $startTime->getTimestamp();
            $start->add(new \DateInterval('P1D'))->setTime(0,0,0);
        } while($start <= $stop);
        return $seconds;
    }
}