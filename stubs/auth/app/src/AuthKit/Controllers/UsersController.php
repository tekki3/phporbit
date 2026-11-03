<?php

declare(strict_types=1);

namespace App\AuthKit\Controllers;

use App\AuthKit\Accounts;
use App\AuthKit\AuthPage;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class UsersController implements Handler
{
    public function __construct(
        private readonly Accounts $accounts, private readonly AuthPage $page,
        private readonly Authenticator $auth, private readonly Mfa $mfa,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $page = max(1, min(100000, (int) ($request->uri->queryParam('page') ?? '1')));

        return $this->page->render('users', 'User management', [
            'users' => $this->accounts->page($page), 'pageNumber' => $page,
            'enabled' => $this->mfa->enabled($this->auth->user()?->authIdentifier() ?? ''),
        ]);
    }
}
