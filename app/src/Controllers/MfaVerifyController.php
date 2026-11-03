<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AuthPage;
use PhpOrbit\Auth\LoginFlow;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class MfaVerifyController implements Handler
{
    public function __construct(private readonly LoginFlow $flow, private readonly AuthPage $page)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        if (!$this->flow->complete(trim($request->form('code') ?? ''))) {
            return $this->page->back('/mfa', 'Code invalid, already used, expired or temporarily rate-limited. Try an unused code or sign in again.', true);
        }

        return $this->page->signedIn();
    }
}
