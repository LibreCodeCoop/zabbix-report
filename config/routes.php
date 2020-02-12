<?php
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes) {
    $routes->add('index', '/')
        ->controller([App\Controller\IndexController::class, 'index']);
    $routes->add('report', '/report/')
        ->controller([App\Controller\ReportController::class, 'index']);
    $routes->add('report_list_item', '/report/item')
        ->controller([App\Controller\ReportController::class, 'itemAjax']);
    $routes->add('report_show', '/report/{slug}')
        ->controller([App\Controller\ReportController::class, 'show']);
    $routes->add('report1_show', '/report1/{slug}')
        ->controller([App\Controller\Report1Controller::class, 'show']);
};