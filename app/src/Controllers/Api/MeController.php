<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth\User;
use PhpOrbit\Api\Principal;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class MeController implements Handler
{
    public function __construct(private readonly Principal $principal)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        $user = $this->principal->user;
        if (!$user instanceof User) {
            throw new \LogicException('The profile endpoint requires the application user provider.');
        }
        return Response::json([
            'id' => $user->authIdentifier(), 'name' => $user->displayName,
        ]);
    }
}
