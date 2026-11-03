<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class SwaggerController implements Handler
{
    public function handle(ServerRequest $request): Response
    {
        return Response::html(<<<'HTML'
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>phporbit API reference</title>
            <link rel="stylesheet" href="/assets/swagger/swagger-ui.css">
            <link rel="stylesheet" href="/assets/css/swagger-shell.css">
        </head>
        <body>
            <nav class="api-nav" aria-label="Documentation"><a href="/">phporbit</a><a href="/api/openapi.json">OpenAPI JSON</a></nav>
            <div id="swagger-ui"></div>
            <noscript>Interactive API documentation requires JavaScript. The OpenAPI JSON is available above.</noscript>
            <script src="/assets/swagger/swagger-ui-bundle.js" defer></script>
            <script src="/assets/js/swagger.js" defer></script>
        </body>
        </html>
        HTML)->withHeader('Content-Security-Policy',
            "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
            . "connect-src 'self'; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    }
}
