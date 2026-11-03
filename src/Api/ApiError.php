<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\Status;
use RuntimeException;

final class ApiError extends RuntimeException
{
    public function __construct(public readonly Status $status, string $message)
    {
        parent::__construct($message);
    }

    public function response(): Response
    {
        return Response::json([
            'type' => 'about:blank', 'title' => $this->getMessage(), 'status' => $this->status->value,
        ], $this->status)->withHeader('Content-Type', 'application/problem+json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
