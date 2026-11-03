<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AuthPage;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class MagicLinkController implements Handler
{
    public function __construct(private readonly AuthPage $page)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->page->render('auth/email', 'Sign in by email', ['magic' => true]);
    }
}
