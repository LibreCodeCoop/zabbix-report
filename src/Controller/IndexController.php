<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class IndexController extends AbstractController
{
    public function index()
    {
        $reports = [
            [
                'name' => 'consolidado de eventos',
                'slug' => 'consolidado'
            ],
            [
                'name' => 'descritivo de eventos',
                'slug' => 'descritivo'
            ]
        ];

        return $this->render('index.html.twig', [
            'reports' => $reports,
        ]);
    }
}