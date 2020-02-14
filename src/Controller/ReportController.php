<?php
namespace App\Controller;

use App\Adapter\DBALAdapter;
use Doctrine\DBAL\Connection;
use Omines\DataTablesBundle\Column\TextColumn;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends BaseController
{
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Connection
     */
    private $conn;
    public function show(Connection $conn, Request $request, DataTableFactory $dataTableFactory, $slug)
    {
        $this->conn = $conn;
        if (method_exists($this, $slug)) {
            return $this->$slug($request, $dataTableFactory);
        } else {
            throw $this->createNotFoundException('Relatório inexistente');
        }
    }

    private function descritivo(Request $request, DataTableFactory $dataTableFactory)
    {
        $this->request = $request->request;
        if ($this->request->get('formato') == 'csv') {
            return $this->viewCsv();
        }
        $table = $dataTableFactory->create();
        $table->add('host', TextColumn::class, [
            'field' => 'host',
            'label' => 'Host'
        ]);
        $table->add('item', TextColumn::class, [
            'field' => 'item',
            'label' => 'Item',
            'className' => 'col-item'
        ]);
        $table->add('downtime', TextColumn::class, [
            'field' => 'start',
            'label' => 'offline',
            'className' => 'col-downtime'
        ]);
        $table->add('uptime', TextColumn::class, [
            'field' => 'recovery',
            'label' => 'online',
            'className' => 'col-uptime'
        ]);
        $table->add('duration', TextColumn::class, [
            'field' => 'duration',
            'label' => 'Duração',
        ]);
        $table->createAdapter(DBALAdapter::class, [
            'query' => function($state) {
                return $this->getQueryDescritivo($state);
            }
        ]);
        $table->handleRequest($request);
        if ($table->isCallback()) {
            $content = $table->getResponse()->getContent();
            $obj = json_decode($content);
            if (!empty($obj->options)) {
                foreach ($obj->options->columns as $key => $column) {
                    $obj->options->columns[$key]->name = $column->data;
                }
            }
            $response = JsonResponse::create($obj);
            return $response;
        }
        $parameters['datatable'] = $table;

        $parameters['tableFooter'] = false;

        $q = $this->getBaseQuery()
            ->groupBy('host')
            ->orderBy('host');
        $parameters['hosts'] = $q->execute()->fetchAll(\PDO::FETCH_COLUMN);
        return $this->render('report/consolidado.html.twig', $parameters);
    }

    private function consolidado(Request $request, DataTableFactory $dataTableFactory)
    {
        $this->request = $request->request;
        if ($this->request->get('formato') == 'csv') {
            return $this->viewCsv();
        }
        $table = $dataTableFactory->create();
        $table->add('host', TextColumn::class, [
            'field' => 'host',
            'label' => 'Host'
        ]);
        $table->add('item', TextColumn::class, [
            'field' => 'item',
            'label' => 'Item',
            'className' => 'col-item'
        ]);
        $table->add('mindatahora', TextColumn::class, [
            'field' => 'mindatahora',
            'label' => '< data/hora',
        ]);
        $table->add('downtime', TextColumn::class, [
            'field' => 'downtime',
            'label' => 'offline',
            'orderable' => false,
            'className' => 'col-downtime'
        ]);
        $table->add('percent_downtime', TextColumn::class, ['field' => 'percent_downtime', 'label' => '% offline']);
        $table->add('uptime', TextColumn::class, [
            'field' => 'uptime',
            'label' => 'online',
            'orderable' => false,
            'className' => 'col-uptime'
        ]);
        $table->add('maxdatahora', TextColumn::class, [
            'field' => 'maxdatahora',
            'label' => '> data/hora',
        ]);
        $table->add('percent_uptime', TextColumn::class, ['field' => 'percent_uptime', 'label' => '% online']);
        $table->createAdapter(DBALAdapter::class, [
            'query' => function($state) {
                return $this->getQuery($state);
            }
        ]);
        $table->handleRequest($request);
        if ($table->isCallback()) {
            $content = $table->getResponse()->getContent();
            $obj = json_decode($content);
            if (!empty($obj->options)) {
                foreach ($obj->options->columns as $key => $column) {
                    $obj->options->columns[$key]->name = $column->data;
                }
            }
            $response = JsonResponse::create($obj);
            return $response;
        }
        $parameters['datatable'] = $table;

        $parameters['tableFooter'] = true;

        $q = $this->getBaseQuery()
            ->groupBy('host')
            ->orderBy('host');
        $parameters['hosts'] = $q->execute()->fetchAll(\PDO::FETCH_COLUMN);
        return $this->render('report/consolidado.html.twig', $parameters);
    }

    public function itemAjax(Connection $conn, Request $request)
    {
        $this->conn = $conn;
        $host = $request->get('host');
        $items = [];
        if ($host) {
            $items = $this->getAllItemsByHost($host);
        }
        return JsonResponse::create($items);
    }

    /**
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    private function createQueryBuilder()
    {
        return $this->conn->createQueryBuilder();
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


    public function getAllItemsByHost(string $host)
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

    private function getValue($columns, $key)
    {
        if (isset($columns[$key]['search'])) {
            return $columns[$key]['search'];
        }
        if (isset($columns[$key])) {
            return $columns[$key];
        }
    }

    public function getQueryDescritivo()
    {
        if ($columns = $this->request->get('columns')) {
            foreach ($columns as $column) {
                $cols[$column['name']] = $column['search']['value'];
            }
            if (isset($cols['downtime'])) {
                list($cols['downtime'], $cols['downtime-time']) = explode(' ', $cols['downtime'] . ' ');
            }
            if (isset($cols['uptime'])) {
                list($cols['uptime'], $cols['uptime-time']) = explode(' ', $cols['uptime'] . ' ');
            }
            if ($this->request->get('search')) {
                parse_str($this->request->get('search')['value'], $body);
                $cols = array_merge($cols, $body);
            }
        } elseif (!empty($this->request->get('downtime'))) {
            $cols = $this->request->all();
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

    public function getQuery()
    {
        if ($columns = $this->request->get('columns')) {
            foreach ($columns as $column) {
                $cols[$column['name']] = $column['search']['value'];
            }
            if (isset($cols['downtime'])) {
                list($cols['downtime'], $cols['downtime-time']) = explode(' ', $cols['downtime'] . ' ');
            }
            if (isset($cols['uptime'])) {
                list($cols['uptime'], $cols['uptime-time']) = explode(' ', $cols['uptime'] . ' ');
            }
            if ($this->request->get('search')) {
                parse_str($this->request->get('search')['value'], $body);
                $cols = array_merge($cols, $body);
            }
        } elseif (!empty($this->request->get('downtime'))) {
            $cols = $this->request->all();
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

    private function viewCsv()
    {
        $qb = $this->getQuery();
        if (!$qb) {
            return;
        }

        $response = new Response();
        $response->headers->set('Content-type', 'text/csv');
        $response->headers->set('Cache-Control', 'private');
        // $response->headers->set('Content-length', $attachmentSize);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . date('Ymd_His') . '.csv";');
        $response->sendHeaders();

        $out = fopen('php://memory', 'r+');

        $delimiter = $this->request->get('separador') == ';' ? ';' : ',';
        $stmt = $qb->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            fputcsv($out, array_keys($row), $delimiter);
            fputcsv($out, $row, $delimiter);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                fputcsv($out, $row, $delimiter);
            }
        }
        rewind($out);
        $csvString = stream_get_contents($out);
        fclose($out);

        $response->setContent($csvString);
        return $response;
    }
}