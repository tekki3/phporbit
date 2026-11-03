<?php

declare(strict_types=1);

namespace App\AuthKit\Controllers;

use App\AuthKit\AuthPage;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class RegisterController implements Handler
{
    public function __construct(private readonly AuthPage $page)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->page->render('register', 'Create an account');
    }
}
