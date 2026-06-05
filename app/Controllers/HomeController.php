<?php

namespace App\Controllers;

use Lily\Http\Request;
use Lily\Http\Response;

class HomeController
{
    public function index(Request $request): Response
    {
        return new Response('Welcome to the decoupled Lily Framework! Architecture is now PSR-4 compliant.');
    }
}
