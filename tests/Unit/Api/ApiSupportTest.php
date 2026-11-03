<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Api;

use App\Support\ApiDefinition;
use PhpOrbit\Api\ApiError;
use PhpOrbit\Api\ApiMigration;
use PhpOrbit\Api\JsonBody;
use PhpOrbit\Api\RateLimiter;
use PhpOrbit\Api\VersionedRoutes;
use PhpOrbit\Database\Connection;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Routing\RouteCollection;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ApiSupportTest extends TestCase
{
    public function test_limiter_allows_exactly_the_configured_number_and_resets_expired_windows(): void
    {
        $db = Connection::sqlite(':memory:');
        (new ApiMigration())->up($db);
        $limiter = new RateLimiter($db);
        self::assertSame(1, $limiter->consume('user:a', 2)->remaining);
        self::assertSame(0, $limiter->consume('user:a', 2)->remaining);
        self::assertFalse($limiter->consume('user:a', 2)->allowed);
        self::assertTrue($limiter->consume('user:b', 2)->allowed);
        $db->query('api_rate_windows')->where('bucket_key', '=', hash('sha256', 'user:a'))->update(['resets_at' => time()]);
        self::assertTrue($limiter->consume('user:a', 2)->allowed);
        $schema = new ApiMigration();
        $schema->down($db);
        $schema->up($db);
        self::assertTrue($limiter->consume('user:a', 2)->allowed);
    }

    public function test_json_fields_are_checked_without_scalar_coercion(): void
    {
        $body = JsonBody::fromRequest(Requests::of(Method::Post, '/api/v1/token',
            ['Content-Type' => 'application/json; charset=utf-8'], '{"email":"ada@example.test","number":42}'));
        self::assertSame('ada@example.test', $body->string('email'));
        self::assertSame('', $body->string('code', required: false));
        $this->expectException(ApiError::class);
        $body->string('number');
    }

    public function test_concurrent_processes_cannot_exceed_a_shared_rate_limit(): void
    {
        $path = sys_get_temp_dir() . '/orbit-api-rate-' . bin2hex(random_bytes(6)) . '.sqlite';
        $db = Connection::sqlite($path);
        (new ApiMigration())->up($db);
        $code = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . '$db=PhpOrbit\\Database\\Connection::sqlite(' . var_export($path, true) . ');'
            . '$limiter=new PhpOrbit\\Api\\RateLimiter($db);'
            . 'fgetc(STDIN); $accepted=0; for($i=0;$i<5;$i++){if($limiter->consume("shared",7,60)->allowed){$accepted++;}} echo $accepted;';
        $workers = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $process = proc_open([PHP_BINARY, '-r', $code], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [$process, $pipes]) {
                fwrite($pipes[0], "\n");
                fclose($pipes[0]);
            }
            $accepted = 0;
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors === false ? 'Cannot read worker errors.' : $errors);
                self::assertIsString($output);
                self::assertMatchesRegularExpression('/^[0-5]$/D', $output);
                $accepted += (int) $output;
            }
            self::assertSame(7, $accepted, 'Exactly seven out of twenty concurrent attempts must succeed.');
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_json_rejects_wrong_type_invalid_syntax_non_objects_and_oversized_bodies(): void
    {
        foreach ([
            ['text/plain', '{}', 100, 415],
            ['application/json', '[]', 100, 400],
            ['application/json', '{', 100, 400],
            ['application/json', '{"text":"too long"}', 2, 413],
        ] as [$type, $body, $max, $status]) {
            try {
                JsonBody::fromRequest(Requests::of(Method::Post, '/', ['Content-Type' => $type], $body), $max);
                self::fail('Invalid JSON input was accepted.');
            } catch (ApiError $error) {
                self::assertSame($status, $error->status->value);
            }
        }
    }

    public function test_versions_drive_real_routes_and_openapi_paths_from_one_declaration(): void
    {
        $routes = new RouteCollection();
        $v1 = new VersionedRoutes($routes, 1);
        $v2 = new VersionedRoutes($routes, 2);
        $operation = ['summary' => 'Read a note', 'responses' => ['200' => ['description' => 'A note']]];
        $handler = static fn (): Response => Response::json(['ok' => true]);
        $v1->add(Method::Get, '/notes/{id:\d+}', $handler, 'api.v1.note', $operation, scopes: ['notes:read']);
        $v2->add(Method::Get, '/notes/{id:\d+}', $handler, 'api.v2.note', $operation);
        $router = $routes->compile();
        self::assertSame('/api/v1/notes/42', $router->urlFor('api.v1.note', ['id' => 42]));
        self::assertSame('/api/v2/notes/42', $router->urlFor('api.v2.note', ['id' => 42]));
        $document = $v1->document()->document();
        self::assertSame('3.0.3', $document['openapi']);
        self::assertIsArray($document['paths']);
        $path = $document['paths']['/api/v1/notes/{id}'];
        self::assertIsArray($path);
        $get = $path['get'];
        self::assertIsArray($get);
        self::assertSame([['bearerAuth' => []]], $get['security']);
        self::assertSame(['notes:read'], $get['x-required-scopes']);
        self::assertSame([['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], $get['parameters']);
        foreach ($router->routes() as $route) {
            self::assertTrue($route->csrfExempt);
        }
    }

    public function test_demo_openapi_documents_login_profile_and_revocation(): void
    {
        $doc = ApiDefinition::document()->document();
        self::assertIsArray($doc['paths']);
        self::assertSame(['/api/v1/token', '/api/v1/me'], array_keys($doc['paths']));
        $token = $doc['paths']['/api/v1/token'];
        self::assertIsArray($token);
        self::assertIsArray($token['post']);
        self::assertIsArray($token['delete']);
        self::assertSame([], $token['post']['security']);
        self::assertSame([['bearerAuth' => []]], $token['delete']['security']);
    }

    public function test_explicit_path_parameter_schemas_are_preserved_without_duplicates(): void
    {
        $api = new VersionedRoutes(new RouteCollection());
        $parameter = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']];
        $api->add(Method::Get, '/notes/{id:\d+}', static fn (): Response => Response::json([]), 'note', [
            'parameters' => [$parameter], 'responses' => ['200' => ['description' => 'A note']],
        ]);
        $paths = $api->document()->document()['paths'];
        self::assertIsArray($paths);
        $path = $paths['/api/v1/notes/{id}'];
        self::assertIsArray($path);
        $operation = $path['get'];
        self::assertIsArray($operation);
        self::assertSame([$parameter], $operation['parameters']);
    }

    public function test_empty_api_documents_are_refused_instead_of_emitting_an_invalid_paths_array(): void
    {
        $api = new VersionedRoutes(new RouteCollection());
        $this->expectException(\InvalidArgumentException::class);
        $api->document();
    }
}
