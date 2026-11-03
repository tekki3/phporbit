<?php

declare(strict_types=1);

namespace App\AuthKit\Controllers;

use App\AuthKit\AuthPage;
use App\AuthKit\Registration;
use InvalidArgumentException;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class RegisterSubmitController implements Handler
{
    public function __construct(private readonly AuthPage $page, private readonly Registration $registration)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            $password = $request->form('password') ?? '';
            if ($password !== $request->form('password_confirmation')) {
                throw new InvalidArgumentException('The passwords do not match.');
            }
            $this->registration->request($request->form('email') ?? '', $request->form('name') ?? '', $password);
        } catch (InvalidArgumentException | AuthenticationFailed $exception) {
            return $this->page->back('/register', $exception->getMessage(), true);
        }

        return $this->page->back('/register', 'If registration is available for this address, a verification link will be sent. Check your inbox or submit again to request a new link.');
    }
}
