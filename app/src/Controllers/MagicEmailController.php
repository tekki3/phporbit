<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AuthEmails;
use App\Support\AuthPage;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class MagicEmailController implements Handler
{
    public function __construct(private readonly AuthEmails $emails, private readonly AuthPage $page)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        $this->emails->request($request->form('email') ?? '', 'magic-login');

        return $this->page->back('/magic', 'If an account exists and requests are permitted, a sign-in link will be sent. Check your inbox.');
    }
}
