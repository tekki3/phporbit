<?php

declare(strict_types=1);

namespace App\AuthKit\Controllers;

use App\AuthKit\Accounts;
use App\AuthKit\AuthPage;
use InvalidArgumentException;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class ProfileUpdateController implements Handler
{
    public function __construct(private readonly Accounts $accounts, private readonly AuthPage $page, private readonly Authenticator $auth)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            $user = $this->auth->user();
            if ($user === null) {
                return Response::redirect('/login');
            }
            $this->accounts->rename($user->authIdentifier(), $request->form('name') ?? '');
        } catch (InvalidArgumentException $exception) {
            return $this->page->back('/account', $exception->getMessage(), true);
        }

        return $this->page->back('/account', 'Profile updated.');
    }
}
