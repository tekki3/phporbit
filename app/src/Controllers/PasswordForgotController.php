<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AuthPage;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class PasswordForgotController implements Handler
{
    public function __construct(private readonly AuthPage $page)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->page->render('auth/email', 'Reset your password', ['magic' => false]);
    }
}
