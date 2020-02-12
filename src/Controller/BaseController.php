<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class BaseController extends AbstractController
{
    protected function render(string $view, array $parameters = [], Response $response = null): Response
    {
        $parameters['menuItems'] = [
            [
                'name' => 'consolidado de eventos',
                'slug' => 'consolidado'
            ],
            [
                'name' => 'descritivo de eventos',
                'slug' => 'descritivo'
            ]
        ];
        return parent::render($view, $parameters, $response);
    }
}