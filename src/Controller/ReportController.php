<?php
namespace App\Controller;

use App\Repository\ReportRepository;
use Doctrine\DBAL\Driver\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends AbstractController
{
    /**
     * @var Connection
     */
    private $conn;
    public function show(Connection $conn, Request $request, $slug)
    {
        $this->conn = $conn;
        if (method_exists($this, $slug)) {
            return $this->$slug($request);
        } else {
            throw $this->createNotFoundException('Relatório inexistente');
        }
    }

    private function consolidado(Request $request)
    {
        if ($request->request->get('formato') == 'csv') {
            return $this->view('csv', $request);
        }
        $sql = 'SELECT host FROM ('.$this->getBaseQuery($this->conn).') x GROUP BY host ORDER BY host';
        $parameters['hosts'] = $this->conn->executeQuery($sql)
            ->fetchAll(\PDO::FETCH_COLUMN);
        $host = $request->request->get('host');
        $parameters['items'] = [];
        if ($host) {
            foreach ($this->getAllItemsByHost($host) as $item) {
                $parameters['items'][] = [
                    'value' => $item,
                    'selected' => $request->request->get('item') == $item
                ];
            }
        }
        if ($request->request->count()) {
            $parameters['viewHtml'] = $this->view('html', $request);
        }
        return $this->render('report/consolidado.html.twig', $parameters);
    }

    /**
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    private function getBaseQuery(): \Doctrine\DBAL\Query\QueryBuilder
    {
        $queryBuilder = $this->conn->createQueryBuilder();
        $queryBuilder
            ->select(
                <<<QUERY
                CASE WHEN hosts.host IS NOT NULL THEN hosts.host
                            WHEN alert_start.message LIKE '%Host:%' THEN TRIM(TRAILING '\\r' FROM TRIM(TRAILING '\\n' FROM REPLACE(REGEXP_SUBSTR(alert_start.message, 'Host:.*\\n'), 'Host: ', '')))
                            WHEN alert_start.message LIKE '%<b>%' THEN REPLACE(REPLACE(REGEXP_SUBSTR(alert_start.message, '<b>.*</b>'), '<b> ', ''), ' </b>', '')
                        END AS host
                QUERY,
                "TRIM(REGEXP_SUBSTR(start.name, 'onu_[0-9/: ]+')) AS onu",
                "recovery.clock - start.clock AS duration"
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

    public function getQuery()
    {
        $decimalPlaces = $_ENV['DECIMAL_PLACES'];
        $sql = <<<QUERY
            SELECT host,
            QUERY;
        if (!empty($_POST['item']) || empty($_POST['icmp']) || $_POST['icmp'] == 0) {
            $sql.= "       onu AS item,\n";
        }
        $sql.= <<<QUERY
                CONCAT(
                    CASE WHEN FLOOR(downtime / 3600) > 9 THEN FLOOR(downtime / 3600) ELSE LPAD(FLOOR(downtime / 3600), 2, 0) END,':',
                    LPAD(FLOOR((downtime % 3600)/60), 2, 0), ':',
                    LPAD(downtime % 60, 2, 0)
                ) AS downtime,
                ROUND((downtime * 100 ) / total_time, $decimalPlaces) AS percent_downtime,
                CONCAT(
                    CASE WHEN FLOOR((total_time - downtime) / 3600) > 9 THEN FLOOR((total_time - downtime) / 3600) ELSE LPAD(FLOOR((total_time - downtime) / 3600), 2, 0) END,':',
                    LPAD(FLOOR(((total_time - downtime) % 3600)/60), 2, 0), ':',
                    LPAD((total_time - downtime) % 60, 2, 0)
                ) AS uptime,
                ROUND(((total_time - downtime) * 100 ) / total_time, $decimalPlaces) AS percent_uptime
            FROM (
                    SELECT host,
            QUERY;
        $sql.= <<<QUERY
                onu,
                SUM(duration) AS downtime,
                ? - ? AS total_time
            FROM (
                {$this->getBaseQuery()}
            QUERY;

        $value = \DateTime::createFromFormat('Y-m-d H:i:s', $_POST['start-date']. ' 00:00:00');
        if ($value) {
            if (!empty($_POST['start-time'])) {
                $startTime = \DateTime::createFromFormat('Y-m-d H:i:s', $_POST['start-date'] . ' ' . $_POST['start-time'].':00');
            } else {
                $startTime = $value;
            }
        }
        if ($startTime && !empty($_POST['recovery-date'])) {
            $value = \DateTime::createFromFormat('Y-m-d', $_POST['recovery-date']);
            if ($value) {
                if (!empty($_POST['recovery-time'])) {
                    $recoveryTime = \DateTime::createFromFormat('Y-m-d H:i:s', $_POST['recovery-date'] . ' ' . $_POST['recovery-time'].':59');
                } else {
                    $recoveryTime = \DateTime::createFromFormat('Y-m-d H:i:s', $_POST['recovery-date'] . ' 23:59:59');
                }
            }
        }
        if (!$startTime || !$recoveryTime) {
            return;
        }
        $sql.= "\n  AND start.clock >= ?";
        $sql.= "\n  AND recovery.clock <= ?";
        $params[] = $startTime->format('U');
        $params[] = $recoveryTime->format('U');
        array_unshift($params, $recoveryTime->format('U'), $startTime->format('U'));
        if (!empty($_POST['host'])) {
            $value = substr(trim(strtolower($_POST['host'])), 0, 30);
            $sql.= "\n  AND (LOWER(hosts.host) LIKE ? OR LOWER(alert_start.message) LIKE ?)";
            $params[] = '%' . $value . '%';
            $params[] = '%' . $value . '%';
        }
        if (!empty($_POST['item'])) {
            $value = substr(trim(strtolower($_POST['item'])), 0, 30);
            $sql.= "\n  AND LOWER(start.name) LIKE ?";
            $params[] = '%' . $value . '%';
        }
        if (!empty($_POST['icmp']) && $_POST['icmp'] == 1) {
            $sql.= "\n  AND start.name LIKE '%ICMP%'";
            $sql.= "\n  AND LOWER(start.name) NOT REGEXP 'onu_[0-9/: ]+'";
        } else {
            $sql.= "\n  AND start.name NOT LIKE '%ICMP%'";
            $sql.= "\n  AND LOWER(start.name) REGEXP 'onu_[0-9/: ]+'";
        }
        $sql.= <<<QUERY
                ) x
            GROUP BY host, onu
            ORDER BY host, onu
            ) x2
            QUERY;
        return ['sql' => $sql, 'params' => $params];
    }

    public function view(string $format, Request $request)
    {
        $toRun = $this->getQuery();
        if (!$toRun) {
            return;
        }
        $this->stmt = $this->conn->executeQuery($toRun['sql'], $toRun['params']);
        switch ($format)
        {
            case 'csv':
                return $this->viewCsv($request);
                break;
            case 'html':
                return $this->viewHtml();
        }
    }

    private function viewCsv(Request $request)
    {
        $response = new Response();
        $response->headers->set('Content-type', 'text/csv');
        $response->headers->set('Cache-Control', 'private');
        // $response->headers->set('Content-length', $attachmentSize);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . date('Ymd_His') . '";');
        $response->sendHeaders();

        $out = fopen('php://memory', 'r+');

        $delimiter = $request->request->get('separador') == ';' ? ';' : ',';
        $row = $this->stmt->fetch(\PDO::FETCH_ASSOC);
        fputcsv($out, array_keys($row), $delimiter);
        fputcsv($out, $row, $delimiter);
        while ($row = $this->stmt->fetch(\PDO::FETCH_ASSOC)) {
            fputcsv($out, $row, $delimiter);
        }
        rewind($out);
        $csvString = stream_get_contents($out);
        fclose($out);

        $response->setContent($csvString);
        return $response;
    }

    private function viewHtml()
    {
        $data['rows'] = $this->stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$data['rows']) {
            return 'Sem resultados';
        } else {
            return $this->renderView('report/tabela.html.twig', $data);
        }
    }
}