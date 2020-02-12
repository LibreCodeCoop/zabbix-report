<?php
namespace App\Controller;

use App\Adapter\DBALAdapter;
use Doctrine\DBAL\Connection;
use Omines\DataTablesBundle\Column\TextColumn;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends AbstractController
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

    private function descritivo()
    {

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
            'label' => 'Host',
        ]);
        $table->add('item', TextColumn::class, ['field' => 'item', 'label' => 'Item']);
        $table->add('downtime', TextColumn::class, ['field' => 'downtime', 'label' => 'offline', 'orderable' => false]);
        $table->add('percent_downtime', TextColumn::class, ['field' => 'percent_downtime', 'label' => '% offline']);
        $table->add('uptime', TextColumn::class, ['field' => 'uptime', 'label' => 'online', 'orderable' => false]);
        $table->add('percent_uptime', TextColumn::class, ['field' => 'percent_uptime', 'label' => '% online']);
        $table->createAdapter(DBALAdapter::class, [
            'query' => function($state) {
                return $this->getQuery($state);
            },
            'connection' => $this->conn
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

        $sql = 'SELECT host FROM ('.$this->getBaseQuery($this->conn).') x GROUP BY host ORDER BY host';
        $parameters['hosts'] = $this->conn->executeQuery($sql)
            ->fetchAll(\PDO::FETCH_COLUMN);
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
            ->leftJoin('start', 'event_recovery', 'er', 'er.eventid = start.eventid')
            ->leftJoin('er', 'events', 'recovery', 'recovery.eventid = er.r_eventid')
            ->leftJoin('start', 'alerts', 'alert_start', 'alert_start.eventid = start.eventid AND alert_start.mediatypeid = 5')
            ->leftJoin('start', 'triggers', 'triggers', 'start.objectid = triggers.triggerid')
            ->leftJoin('triggers', 'functions', 'functions', 'functions.triggerid = triggers.triggerid')
            ->leftJoin('functions', 'items', 'items', 'items.itemid = functions.itemid')
            ->leftJoin('items', 'hosts', 'hosts', 'items.hostid = hosts.hostid')
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
        $value = '%'.substr(trim(strtolower($host)), 0, 30).'%';
        $queryBuilder = $this->getBaseQuery();
        $queryBuilder->select("TRIM(REGEXP_SUBSTR(start.name, 'onu_[0-9/: ]+')) AS onu")
            ->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->eq('start.severity', 5),
                        $queryBuilder->expr()->eq('recovery.severity', 0)
                    ),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->like('LOWER(hosts.host)', '?'),
                        $queryBuilder->expr()->like('LOWER(alert_start.message)', '?')
                    )
                )
            )
            ->groupBy('onu')
            ->having(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->neq('onu', "''"),
                    $queryBuilder->expr()->isNotNull('onu')
                )
            );
        return $this->conn->executeQuery($queryBuilder, [$value, $value])
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

    public function getQuery()
    {
        if ($columns = $this->request->get('columns')) {
            foreach ($columns as $column) {
                $cols[$column['name']] = $column['search']['value'];
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
        $q3->addSelect("recovery.clock - start.clock AS duration");
        $q3->andWhere($q3->expr()->gte('start.clock', '?'));
        $q3->andWhere($q3->expr()->lte('recovery.clock', '?'));
        $q1->setParameter(2, $startTime->format('U'));
        $q1->setParameter(3, $recoveryTime->format('U'));
        if ($this->getValue($cols, 'host')) {
            $value = substr(trim(strtolower($this->getValue($cols, 'host'))), 0, 30);
            $q3->andWhere(
                $q3->expr()->orX(
                    $q3->expr()->like('LOWER(hosts.host)', '?'),
                    $q3->expr()->like('LOWER(alert_start.message)', '?')
                )
            );
            $q1->setParameter(4, '%' . $value . '%');
            $q1->setParameter(5, '%' . $value . '%');
        }
        if ($this->getValue($cols, 'item')) {
            $value = substr(trim(strtolower($this->getValue($cols, 'item'))), 0, 30);
            $q3->andWhere($q3->expr()->like('LOWER(start.name)', '?'));
            $q1->setParameter(6, '%' . $value . '%');
        }
        if ($this->getValue($cols, 'icmp') == 1) {
            $q3->andWhere("start.name LIKE '%ICMP%'");
            $q3->andWhere("LOWER(start.name) NOT REGEXP 'onu_[0-9/: ]+'");
        } else {
            $q3->andWhere("start.name NOT LIKE '%ICMP%'");
            $q3->andWhere("LOWER(start.name) REGEXP 'onu_[0-9/: ]+'");
        }

        $q2 = $this->createQueryBuilder();
        $q2->select(['host', 'onu', 'SUM(duration) AS downtime', '? - ? AS total_time']);
        $q1->setParameter(0, $recoveryTime->format('U'));
        $q1->setParameter(1, $startTime->format('U'));
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
        $stmt = $this->conn->executeQuery($qb, $qb->getParameters());
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