<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Worker;

use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Log\Level;
use PhpOrbit\Log\Logger;
use PhpOrbit\Log\LogRequests;
use PhpOrbit\Log\StreamLogger;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StructuredLoggingTest extends TestCase
{
    public function test_request_and_application_logs_share_an_id_without_leaking_between_requests(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $logger = new StreamLogger($stream);
        $app = Application::boot(static function (Blueprint $app) use ($logger): void {
            $app->container->singleton(Logger::class, static fn (): Logger => $logger);
            $app->middleware(new LogRequests($logger));
            $app->routes->get('/ok', static function (ServerRequest $request, RequestScope $scope): Response {
                $scope->get(Logger::class)->log(Level::Info, 'application event', [
                    'request_id' => 'forged', 'nested' => ['access_token' => 'secret'],
                ]);
                return Response::text('ok')->withHeader('X-Request-ID', 'handler-controlled');
            });
            $app->routes->get('/fail', static function (): Response {
                throw new RuntimeException('private exception message');
            });
        });
        $first = $app->handle(Requests::of(Method::Get, '/ok?token=private', ['X-Request-ID' => 'client-controlled']));
        $failure = $app->handle(Requests::get('/fail'));
        $second = $app->handle(Requests::get('/ok'));
        $missing = $app->handle(Requests::get('/missing'));
        $logger->log(Level::Info, 'outside request');
        self::assertSame(500, $failure->status->value);
        $firstId = $first->headers->first('X-Request-ID');
        self::assertIsString($firstId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $firstId);
        self::assertNotSame($firstId, $second->headers->first('X-Request-ID'));
        self::assertNotSame($firstId, $missing->headers->first('X-Request-ID'));
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);
        self::assertStringNotContainsString('private', $contents);
        self::assertStringNotContainsString('secret', $contents);
        $lines = array_values(array_filter(explode("\n", $contents)));
        self::assertCount(7, $lines);
        $entries = array_map($this->entry(...), $lines);
        self::assertSame($firstId, $entries[0]['context']['request_id']);
        self::assertSame($firstId, $entries[1]['context']['request_id']);
        self::assertNotSame($firstId, $entries[2]['context']['request_id']);
        self::assertSame('request failed', $entries[2]['message']);
        self::assertSame($second->headers->first('X-Request-ID'), $entries[3]['context']['request_id']);
        self::assertSame($missing->headers->first('X-Request-ID'), $entries[5]['context']['request_id']);
        self::assertSame(404, $entries[5]['context']['status']);
        self::assertSame([], $entries[6]['context']);
        fclose($stream);
    }

    /** @return array{message: string, context: array<array-key, mixed>} */
    private function entry(string $line): array
    {
        $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);
        $message = $entry['message'] ?? null;
        $context = $entry['context'] ?? [];
        self::assertIsString($message);
        self::assertIsArray($context);

        return ['message' => $message, 'context' => $context];
    }
}
