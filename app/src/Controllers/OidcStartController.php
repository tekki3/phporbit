<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AuthPage;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Oidc\OidcClient;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class OidcStartController implements Handler
{
    public function __construct(private readonly OidcClient $client, private readonly AuthPage $page)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            return Response::redirect($this->client->start($request->attribute('provider') ?? ''))
                ->withHeader('Cache-Control', 'no-store');
        } catch (AuthenticationFailed $exception) {
            return $this->page->back('/login', $exception->getMessage(), true);
        }
    }
}
