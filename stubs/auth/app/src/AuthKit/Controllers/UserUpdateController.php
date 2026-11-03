<?php

declare(strict_types=1);

namespace App\AuthKit\Controllers;

use App\AuthKit\Accounts;
use App\AuthKit\AccountSecurity;
use App\AuthKit\AuthPage;
use InvalidArgumentException;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class UserUpdateController implements Handler
{
    public function __construct(
        private readonly Accounts $accounts, private readonly AccountSecurity $security, private readonly AuthPage $page,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            $actor = $this->security->confirm($request);
            $this->accounts->manage($actor->authIdentifier(), (int) ($request->attribute('id') ?? '0'), $request->form('action') ?? '');
        } catch (AuthenticationFailed | InvalidArgumentException $exception) {
            return $this->page->back('/account/users', $exception->getMessage(), true);
        }

        return $this->page->back('/account/users', 'User updated. Their existing sessions have been revoked.');
    }
}
