<?php
namespace App\Command;

use App\Repository\ZabbixReportRepository;
use Doctrine\Bundle\DoctrineBundle\Command\DoctrineCommand;
use Doctrine\Bundle\DoctrineBundle\Command\Proxy\DoctrineCommandHelper;
use LogicException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

class ReportSyncCommand extends DoctrineCommand
{
    protected static $defaultName = 'app:report-sync';

    protected function configure()
    {
        $this
            ->setDescription('Sincroniza tabelas de relatórios')
            ->setHelp('Este comando sincroniza as tabelas de relatórios')
            ->setDefinition([
                new InputOption('start-date', null, InputOption::VALUE_OPTIONAL, 'Data inicial'),
                new InputOption('end-date', null, InputOption::VALUE_OPTIONAL, 'Data final'),
                new InputOption('date', null, InputOption::VALUE_OPTIONAL, 'Data exata'),
                new InputOption('connection', null, InputOption::VALUE_OPTIONAL, 'The connection to use for this command')
            ]);
    }

    private function getDatesFromInput($input)
    {
        $date = $input->getOption('date');
        $startDate = $input->getOption('start-date');
        if ($date) {
            $date = \DateTime::createFromFormat('Y-m-d H:i:s', $date . ' 00:00:00');
            if (!$date) {
                throw new LogicException('Data inválida');
            }
            $dates[] = [
                'sart' => $date,
                'end' => \DateTime::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' 00:00:00')
            ];
        } elseif($startDate) {
            $startDate = \DateTime::createFromFormat('Y-m-d H:i:s', $startDate . ' 00:00:00');
            $endDate = $input->getOption('end-date');
            if ($endDate) {
                $endDate = \DateTime::createFromFormat('Y-m-d H:i:s', $endDate . ' 24:00:00');
                if (!$endDate) {
                    throw new LogicException('Data fim inválida');
                }
            } else {
                $endDate = \DateTime::createFromFormat('Y-m-d H:i:s', date('Y-m-d') . ' 00:00:00');
            }
            while ($startDate->format('Y-m-d') < $endDate->format('Y-m-d')) {
                $dates[] = [
                    'start' => clone $startDate,
                    'end' => $endDate
                ];
                $startDate = $startDate->add(new \DateInterval('P1D'));
            }
        } else {
            $date = \DateTime::createFromFormat('Y-m-d H:i:s', date('Y-m-d 00:00:00'));
            $date = $date->sub(new \DateInterval('P1D'));
            $dates[] = [
                'start' => $date,
                'end' => \DateTime::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' 24:00:00')
            ];
        }
        return $dates;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dates = $this->getDatesFromInput($input);

        DoctrineCommandHelper::setApplicationConnection($this->getApplication(), $input->getOption('connection'));
        $conn = $this->getHelper('db')->getConnection();
        $report = new ZabbixReportRepository(['conn' => $conn]);

        $progressBar = new ProgressBar($output, count($dates));
        $progressBar->start();
        $filter = new ParameterBag();
        foreach ($dates as $range) {
            $filter->set('downtime', $range['start']->format('Y-m-d 00:00:00'));
            $filter->set('uptime', (clone $range['start'])->add(new \DateInterval('P1D'))->format('Y-m-d H:i:s'));
            $stmt = $report->getBaseReportQuery($filter)->execute();
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $start = \DateTime::createFromFormat('Y-m-d H:i:s', $row['start_time']);
                $recovery = \DateTime::createFromFormat('Y-m-d H:i:s', $row['recovery_time']);
                $row['multidate'] = !$recovery || $start->format('Y-m-d') < $recovery->format('Y-m-d') ? 1 : 0;
                if (!$recovery) {
                    $recovery = clone $range['end'];
                }
                do {
                    $endCurrentDay = (clone $start)->add(new \DateInterval('P1D'))->setTime(0,0,0);
                    if ($endCurrentDay->format('Y-m-d') >= $recovery->format('Y-m-d')) {
                        $row['recovery_date'] = $recovery->format('Y-m-d');
                        $row['recovery_time'] = $recovery->format('Y-m-d H:i:s');
                        $row['duration'] = $recovery->getTimestamp() - $start->getTimestamp();
                    } else {
                        $row['recovery_date'] = $endCurrentDay->format('Y-m-d');
                        $row['recovery_time'] = $endCurrentDay->format('Y-m-d H:i:s');
                        $row['duration'] = $endCurrentDay->getTimestamp() - $start->getTimestamp();
                    }
                    $report->saveDailyReport($row);
                    $start->add(new \DateInterval('P1D'))->setTime(0,0,0);
                    $row['start_date'] = $start->format('Y-m-d');
                    $row['start_time'] = $start->format('Y-m-d H:i:s');
                    $row['weekday'] = $start->format('w');
                } while ($row['multidate'] && $start < $recovery);
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $output->writeln('Dados sincronizados com sucesso!');
        return 0;
    }
}
