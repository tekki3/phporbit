<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AccountSecurity;
use App\Support\AuthPage;
use PhpOrbit\Api\Tokens;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class ApiTokenRevokeController implements Handler
{
    public function __construct(
        private readonly Tokens $tokens, private readonly AccountSecurity $security, private readonly AuthPage $page,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            $user = $this->security->confirm($request);
            $this->tokens->revoke($user->authIdentifier(), $request->attribute('id') ?? '');
        } catch (AuthenticationFailed $error) {
            return $this->page->back('/account/tokens', $error->getMessage(), true);
        }

        return $this->page->back('/account/tokens', 'API token revoked.');
    }
}
