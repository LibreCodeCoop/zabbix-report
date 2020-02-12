<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class IndexController extends BaseController
{
    public function index()
    {
        return $this->render('index.html.twig');
    }
}