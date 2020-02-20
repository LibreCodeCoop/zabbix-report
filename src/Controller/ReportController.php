<?php
namespace App\Controller;

use App\Adapter\DBALAdapter;
use App\Repository\ZabbixReportRepository;
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
    /**
     * @var ZabbixReportRepository
     */
    public function show(Connection $conn, Request $request, DataTableFactory $dataTableFactory, $slug)
    {
        $this->conn = $conn;
        if (method_exists($this, $slug)) {
            $this->report = new ZabbixReportRepository(['conn' => $this->conn, 'filter' => $request]);
            return $this->$slug($request, $dataTableFactory);
        } else {
            throw $this->createNotFoundException('Relatório inexistente');
        }
    }

    private function descritivo(Request $request, DataTableFactory $dataTableFactory)
    {
        $this->request = $request->request;
        if ($this->request->get('formato') == 'csv') {
            return $this->viewCsv('descritivo');
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
            'query' => function($state) use($request) {
                $report = new ZabbixReportRepository(['conn' => $this->conn, 'filter' => $request]);
                try {
                    return $report->getQueryDescritivo();
                } catch (\Throwable $th) {
                }
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

        $parameters['hosts'] = $this->report->getAllHosts();
        return $this->render('report/consolidado.html.twig', $parameters);
    }

    private function consolidado(Request $request, DataTableFactory $dataTableFactory)
    {
        $this->request = $request->request;
        if ($this->request->get('formato') == 'csv') {
            return $this->viewCsv('consolidado');
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
            'field' => 'offline',
            'label' => 'offline',
            'className' => 'col-downtime'
        ]);
        $table->add('percent_downtime', TextColumn::class, [
            'field' => 'percent_downtime',
            'label' => '% offline',
            'className' => 'col-percent-downtime'
        ]);
        $table->add('uptime', TextColumn::class, [
            'field' => 'online',
            'label' => 'online',
            'className' => 'col-uptime'
        ]);
        $table->add('percent_uptime', TextColumn::class, [
            'field' => 'percent_uptime',
            'label' => '% online',
            'className' => 'col-percent-uptime'
        ]);
        $table->add('maxdatahora', TextColumn::class, [
            'field' => 'maxdatahora',
            'label' => '> data/hora',
        ]);
        $table->createAdapter(DBALAdapter::class, [
            'query' => function($state) use($request) {
                $report = new ZabbixReportRepository(['conn' => $this->conn, 'filter' => $request]);
                try {
                    return $report->getQueryConsolidado();
                } catch (\Throwable $th) {
                }
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

        $parameters['hosts'] = $this->report->getAllHosts();
        return $this->render('report/consolidado.html.twig', $parameters);
    }

    public function itemAjax(Connection $conn, Request $request)
    {
        $report = new ZabbixReportRepository(['conn' => $conn, 'filter' => $request]);
        $host = $request->get('host');
        $items = [];
        if ($host) {
            $items = $report->getAllItemsByHost($host);
        }
        return JsonResponse::create($items);
    }

    private function viewCsv($reportName)
    {
        $report = new ZabbixReportRepository(['conn' => $this->conn, 'filter' => $this->request]);
        $qb = call_user_func([$report, 'getQuery' . ucfirst($reportName)]);
        if (!$qb) {
            return;
        }

        $response = new Response();
        $response->headers->set('Content-type', 'text/csv');
        $response->headers->set('Cache-Control', 'private');
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