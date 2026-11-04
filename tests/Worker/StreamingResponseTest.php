<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Worker;

use Generator;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\TransactionGuard;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StreamingResponseTest extends TestCase
{
    public function test_scope_remains_open_during_emission_and_closes_before_the_next_request(): void
    {
        $scopes = [];
        $released = [];
        $app = Application::boot(static function (Blueprint $app) use (&$scopes, &$released): void {
            $app->routes->get('/stream/{name}', static function (ServerRequest $request, RequestScope $scope) use (&$scopes, &$released): Response {
                $name = $request->attribute('name') ?? '';
                $scopes[] = $scope;
                $scope->onClose(static function () use (&$released, $name): void { $released[] = $name; });
                return Response::stream(static function () use ($name, $scope): Generator {
                    if ($scope->isClosed()) {
                        throw new RuntimeException('Scope closed too early.');
                    }
                    yield $name;
                    yield '!';
                })->withHeader('X-Test', 'preserved');
            });
        });
        $output = [];
        foreach (['Ada', 'Bob'] as $name) {
            $app->respond(Requests::get('/stream/' . $name), static function (Response $response) use (&$output, &$scopes): void {
                self::assertNotNull($response->stream);
                $scope = end($scopes);
                self::assertInstanceOf(RequestScope::class, $scope);
                self::assertFalse($scope->isClosed());
                self::assertSame('preserved', $response->headers->first('X-Test'));
                $output[] = implode('', iterator_to_array($response->stream->chunks()));
            });
            $scope = end($scopes);
            self::assertInstanceOf(RequestScope::class, $scope);
            self::assertTrue($scope->isClosed());
        }
        self::assertSame(['Ada!', 'Bob!'], $output);
        self::assertSame(['Ada', 'Bob'], $released);
        self::assertSame('Ada!', $app->handle(Requests::get('/stream/Ada'))->body, 'handle() collects within scope.');
    }

    public function test_head_and_bodyless_statuses_never_start_producers(): void
    {
        $calls = 0;
        $app = Application::boot(static function (Blueprint $app) use (&$calls): void {
            $stream = static function () use (&$calls): Generator { $calls++; yield 'never'; };
            $app->routes->get('/stream', static fn (): Response => Response::stream($stream));
            $app->routes->get('/empty', static fn (): Response => Response::stream($stream, \PhpOrbit\Http\Status::NoContent));
        });
        self::assertSame('', $app->handle(Requests::of(\PhpOrbit\Http\Method::Head, '/stream'))->body);
        self::assertSame('', $app->handle(Requests::get('/empty'))->body);
        self::assertSame(0, $calls);
    }

    public function test_stream_setup_failures_return_500_before_emission(): void
    {
        $closed = false;
        $app = Application::boot(static function (Blueprint $app) use (&$closed): void {
            $app->routes->get('/stream', static function (ServerRequest $request, RequestScope $scope) use (&$closed): Response {
                $scope->onClose(static function () use (&$closed): void { $closed = true; });
                return Response::stream(static function (): iterable {
                    throw new RuntimeException('secret provider error');
                });
            });
        });
        $response = $app->handle(Requests::get('/stream'));
        self::assertSame(500, $response->status->value);
        self::assertStringNotContainsString('secret', $response->body);
        self::assertTrue($closed);
    }

    public function test_partial_failures_and_disconnects_dispose_the_scope_and_lazy_resources(): void
    {
        foreach ([false, true] as $disconnect) {
            $closed = false;
            $producerClosed = false;
            $app = Application::boot(static function (Blueprint $app) use (&$closed, &$producerClosed): void {
                $app->routes->get('/stream', static function (ServerRequest $request, RequestScope $scope) use (&$closed, &$producerClosed): Response {
                    $scope->onClose(static function () use (&$closed): void { $closed = true; });
                    return Response::stream(static function () use (&$producerClosed, $scope): Generator {
                        try {
                            yield 'first';
                            throw new RuntimeException('upstream stopped');
                        } finally {
                            self::assertFalse($scope->isClosed(), 'Producer cleanup must run before scope disposal.');
                            $producerClosed = true;
                        }
                    });
                });
            });
            try {
                $app->respond(Requests::get('/stream'), static function (Response $response) use ($disconnect): void {
                    self::assertNotNull($response->stream);
                    foreach ($response->stream->chunks() as $chunk) {
                        self::assertSame('first', $chunk);
                        if ($disconnect) {
                            throw new RuntimeException('client disconnected');
                        }
                    }
                });
                self::fail('Stream errors must not report success.');
            } catch (RuntimeException $error) {
                self::assertContains($error->getMessage(), ['client disconnected', 'upstream stopped']);
            }
            self::assertTrue($closed);
            self::assertTrue($producerClosed);
        }
    }

    public function test_transactions_opened_by_lazy_streams_are_rolled_back(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $database = new Connection($pdo);
        $reports = [];
        $app = Application::boot(static function (Blueprint $app) use ($database, $pdo, &$reports): void {
            $app->container->singleton(Connection::class, static fn (): Connection => $database);
            $app->middleware(new TransactionGuard(static function (string $message) use (&$reports): void { $reports[] = $message; }));
            $app->routes->get('/stream', static fn (): Response => Response::stream(static function () use ($pdo): Generator {
                $pdo->beginTransaction();
                yield 'first';
            }));
        });
        self::assertSame('first', $app->handle(Requests::get('/stream'))->body);
        self::assertFalse($database->rollBackIfOpen());
        self::assertCount(1, $reports);
    }

    public function test_sessions_are_readable_but_cannot_be_mutated_after_stream_response_creation(): void
    {
        $store = new \PhpOrbit\Tests\Support\ArraySessionStore();
        $app = Application::boot(static function (Blueprint $app) use ($store): void {
            $app->middleware(new \PhpOrbit\Session\SessionMiddleware($store));
            $app->routes->get('/stream', static function (ServerRequest $request, RequestScope $scope): Response {
                $session = $scope->get(\PhpOrbit\Session\Session::class);
                $session->set('name', 'Ada');
                return Response::stream(static function () use ($session): Generator {
                    yield $session->get('name') ?? '';
                    $session->set('name', 'Bob');
                });
            });
        });
        try {
            $app->respond(Requests::get('/stream'), static function (Response $response): void {
                self::assertStringContainsString('orbit_session=', $response->headers->first('Set-Cookie') ?? '');
                self::assertNotNull($response->stream);
                foreach ($response->stream->chunks() as $chunk) {
                    self::assertSame('Ada', $chunk);
                }
            });
            self::fail('Late session changes must fail rather than being silently lost.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('before returning a streaming response', $error->getMessage());
        }
        self::assertSame([['name' => 'Ada']], $store->all());
    }

    public function test_upload_files_survive_until_stream_completion_and_are_cleaned_on_disconnect(): void
    {
        foreach ([false, true] as $disconnect) {
            $path = tempnam(sys_get_temp_dir(), 'orbit-stream-upload-');
            self::assertIsString($path);
            file_put_contents($path, 'uploaded bytes');
            $file = new \PhpOrbit\Http\Upload\UploadedFile('document', 'file.txt', 'text/plain', 14,
                \PhpOrbit\Http\Upload\UploadError::None, $path);
            $app = Application::boot(static function (Blueprint $app): void {
                $app->routes->get('/stream', static function (ServerRequest $request): Response {
                    $file = $request->file('document');
                    self::assertNotNull($file);
                    return Response::stream(static function () use ($file): Generator {
                        self::assertTrue($file->isValid());
                        yield 'first';
                        self::assertTrue($file->isValid());
                        yield 'last';
                    });
                });
            });
            $request = Requests::get('/stream');
            $request = new ServerRequest($request->method, $request->uri, $request->headers, files: ['document' => $file]);
            try {
                $app->respond($request, static function (Response $response) use ($disconnect): void {
                    self::assertNotNull($response->stream);
                    foreach ($response->stream->chunks() as $chunk) {
                        if ($disconnect) {
                            throw new RuntimeException('client disconnected');
                        }
                    }
                });
            } catch (RuntimeException $error) {
                self::assertTrue($disconnect);
                self::assertSame('client disconnected', $error->getMessage());
            } finally {
                self::assertFileDoesNotExist($path);
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
