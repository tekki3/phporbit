<?php

declare(strict_types=1);

namespace App\Support;

use App\Controllers\Api;
use PhpOrbit\Api\OpenApi;
use PhpOrbit\Api\VersionedRoutes;
use PhpOrbit\Http\Method;
use PhpOrbit\Routing\RouteCollection;

final class ApiDefinition
{
    public static function register(RouteCollection $routes): OpenApi
    {
        $api = new VersionedRoutes($routes, 1, 'phporbit demo API');
        $problem = ['description' => 'Problem response', 'content' => ['application/problem+json' => [
            'schema' => ['type' => 'object', 'required' => ['type', 'title', 'status'], 'properties' => [
                'type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'],
            ]],
        ]]];
        $api->add(Method::Post, '/token', Api\LoginController::class, 'api.v1.token', [
            'summary' => 'Exchange password and optional MFA code for a 15-minute JWT',
            'description' => 'No browser session is granted. If MFA is enrolled, an unused TOTP or recovery code is mandatory.',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'required' => ['email', 'password'], 'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 191],
                    'password' => ['type' => 'string', 'format' => 'password', 'maxLength' => 72],
                    'code' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Required when MFA is enabled.'],
                ],
            ]]]],
            'responses' => ['200' => ['description' => 'JWT issued', 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'required' => ['access_token', 'token_type', 'expires_in'],
                'properties' => ['access_token' => ['type' => 'string'], 'token_type' => ['type' => 'string', 'enum' => ['Bearer']],
                    'expires_in' => ['type' => 'integer', 'example' => 900]],
            ]]]], '400' => $problem, '401' => $problem, '413' => $problem, '415' => $problem, '422' => $problem, '429' => $problem],
        ], authenticated: false, limit: 30);
        $api->add(Method::Get, '/me', Api\MeController::class, 'api.v1.me', [
            'summary' => 'Read the authenticated profile',
            'responses' => ['200' => ['description' => 'Current profile', 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'required' => ['id', 'name'],
                'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string']],
            ]]]], '401' => $problem, '403' => $problem, '429' => $problem],
        ], scopes: ['profile:read']);
        $api->add(Method::Delete, '/token', Api\RevokeController::class, 'api.v1.revoke', [
            'summary' => 'Revoke the currently presented token',
            'responses' => ['204' => ['description' => 'Token revoked'], '401' => $problem, '429' => $problem],
        ]);

        return \PhpOrbit\Api\RouteFiles::document(dirname(__DIR__, 2) . '/api', $api->document());
    }

    public static function document(): OpenApi
    {
        return self::register(new RouteCollection());
    }
}
