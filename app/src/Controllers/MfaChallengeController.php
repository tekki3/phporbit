<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AuthPage;
use PhpOrbit\Auth\LoginFlow;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class MfaChallengeController implements Handler
{
    public function __construct(private readonly LoginFlow $flow, private readonly AuthPage $page)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->flow->pending()
            ? $this->page->render('auth/challenge', 'Verify your identity')
            : $this->page->back('/login', 'Start a new sign-in before entering an authenticator code.', true);
    }
}
