<?php
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes) {
    $routes->add('index', '/')
        ->controller([App\Controller\IndexController::class, 'index']);
    $routes->add('report', '/report/')
        ->controller([App\Controller\ReportController::class, 'index']);
    $routes->add('report_show', '/report/{slug}')
        ->controller([App\Controller\ReportController::class, 'show']);
};