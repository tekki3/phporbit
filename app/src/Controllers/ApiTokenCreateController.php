<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AccountSecurity;
use App\Support\AuthPage;
use InvalidArgumentException;
use PhpOrbit\Api\ApiError;
use PhpOrbit\Api\Tokens;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class ApiTokenCreateController implements Handler
{
    public function __construct(
        private readonly Tokens $tokens, private readonly AccountSecurity $security,
        private readonly Mfa $mfa, private readonly AuthPage $page,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            $user = $this->security->confirm($request);
            $token = $this->tokens->personal($user, $request->form('name') ?? '', ['profile:read']);
        } catch (AuthenticationFailed | InvalidArgumentException | ApiError $error) {
            return $this->page->back('/account/tokens', $error->getMessage(), true);
        }

        return $this->page->render('auth/tokens', 'API token created', [
            'tokens' => $this->tokens->listed($user->authIdentifier()), 'issuedToken' => $token->value,
            'enabled' => $this->mfa->enabled($user->authIdentifier()),
        ]);
    }
}
