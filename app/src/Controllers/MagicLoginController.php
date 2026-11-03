<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AuthPage;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\EmailTokens;
use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\LoginFlow;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;

final class MagicLoginController implements Handler
{
    public function __construct(
        private readonly EmailTokens $tokens, private readonly LoginFlow $flow,
        private readonly Session $session, private readonly AuthPage $page,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $token = $this->session->get('_magic_token') ?? '';
        $this->session->remove('_magic_token');
        $user = null;
        try {
            $this->tokens->consume($token, 'magic-login', static function (Identity $identity) use (&$user): void {
                $user = $identity;
            });
        } catch (AuthenticationFailed $exception) {
            return $this->page->back('/magic', $exception->getMessage(), true);
        }
        if ($user === null) {
            throw new AuthenticationFailed('The sign-in link did not identify an account.');
        }
        $this->flow->begin($user);

        return $this->flow->pending() ? Response::redirect('/mfa') : $this->page->signedIn();
    }
}
