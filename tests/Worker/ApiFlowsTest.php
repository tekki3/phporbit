<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Worker;

use App\Auth\DatabaseUserProvider;
use App\Support\ApiDefinition;
use PhpOrbit\Api\ApiResponses;
use PhpOrbit\Api\RateLimiter;
use PhpOrbit\Api\Tokens;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Auth\SecurityState;
use PhpOrbit\Auth\Totp;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Crypto\Key;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Log\Logger;
use PhpOrbit\Log\StreamLogger;
use PhpOrbit\Security\CsrfMiddleware;
use PhpOrbit\Session\Session;
use PhpOrbit\Session\SessionMiddleware;
use PhpOrbit\Tests\Support\ArraySessionStore;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ApiFlowsTest extends TestCase
{
    private Application $app;
    private Connection $db;
    private DatabaseUserProvider $users;
    private Tokens $tokens;
    private Mfa $mfa;
    /** @var resource */
    private $logs;

    protected function setUp(): void
    {
        $logs = fopen('php://temp', 'w+b');
        self::assertIsResource($logs);
        $this->logs = $logs;
        $this->db = Connection::sqlite(':memory:');
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [] as $path) {
            $migration = require $path;
            self::assertInstanceOf(Migration::class, $migration);
            $migration->up($this->db);
        }
        $hasher = new PasswordHasher();
        $this->users = new DatabaseUserProvider($this->db, $hasher);
        $this->users->create('ada@example.test', 'correct-horse-battery', 'Ada');
        $this->users->create('bob@example.test', 'another-long-password', 'Bob');
        $state = new SecurityState($this->db);
        $key = Key::generate();
        $this->mfa = new Mfa($this->db, $state, new Encrypter($key));
        $this->tokens = new Tokens($this->db, $this->users, $state, $key, 'test', 'api');
        $this->app = Application::boot(function (Blueprint $app) use ($hasher, $state): void {
            $app->container->singleton(UserProvider::class, fn (): UserProvider => $this->users);
            $app->container->singleton(Tokens::class, fn (): Tokens => $this->tokens);
            $app->container->singleton(Mfa::class, fn (): Mfa => $this->mfa);
            $app->container->singleton(SecurityState::class, static fn (): SecurityState => $state);
            $app->container->singleton(RateLimiter::class, fn (): RateLimiter => new RateLimiter($this->db));
            $app->container->singleton(Logger::class, fn (): Logger => new StreamLogger($this->logs));
            $app->container->scoped(Authenticator::class, fn (RequestScope $s): Authenticator => new Authenticator(
                $s->get(Session::class), $this->users, $hasher,
            ));
            $app->middleware(new ApiResponses(), new SessionMiddleware(new ArraySessionStore()), new CsrfMiddleware());
            ApiDefinition::register($app->routes);
            $app->routes->get('/api/boom', static function (): Response {
                throw new \RuntimeException('Do not expose database credentials.');
            });
        });
    }

    protected function tearDown(): void
    {
        fclose($this->logs);
    }

    public function test_unexpected_api_failures_are_opaque_logged_and_do_not_break_later_requests(): void
    {
        $failure = $this->call(Method::Get, '/api/boom');
        self::assertSame(500, $failure->status->value);
        self::assertSame('application/problem+json', $failure->headers->first('Content-Type'));
        self::assertStringNotContainsString('database credentials', $failure->body);
        rewind($this->logs);
        $logged = stream_get_contents($this->logs);
        self::assertIsString($logged);
        self::assertStringContainsString('RuntimeException', $logged);
        self::assertStringNotContainsString('database credentials', $logged);
        self::assertSame(200, $this->login()->status->value);
    }

    public function test_authorization_is_not_read_from_queries_cookies_or_ambiguous_headers(): void
    {
        $token = $this->token($this->login());
        self::assertSame(401, $this->call(Method::Get, '/api/v1/me?access_token=' . rawurlencode($token))->status->value);
        self::assertSame(401, $this->app->handle(Requests::of(Method::Get, '/api/v1/me', cookies: ['access_token' => $token]))->status->value);
        foreach (['Basic ' . $token, 'Bearer  ' . $token, 'Bearer ' . $token . ', Bearer ' . $token] as $value) {
            $response = $this->app->handle(Requests::of(Method::Get, '/api/v1/me', ['Authorization' => $value]));
            self::assertSame(401, $response->status->value);
        }
        $request = Requests::get('/api/v1/me');
        $request = new \PhpOrbit\Http\ServerRequest(
            $request->method, $request->uri,
            $request->headers->add('Authorization', 'Bearer ' . $token)->add('Authorization', 'Bearer ' . $token),
        );
        self::assertSame(401, $this->app->handle($request)->status->value);
    }

    public function test_json_login_bearer_authentication_cookie_rejection_and_revocation(): void
    {
        $response = $this->login();
        self::assertSame(200, $response->status->value, $response->body);
        $token = $this->token($response);
        self::assertSame(401, $this->call(Method::Get, '/api/v1/me')->status->value);
        $ada = $this->call(Method::Get, '/api/v1/me', $token);
        self::assertSame(['id' => '1', 'name' => 'Ada'], json_decode($ada->body, true));
        $bob = $this->users->findByEmail('bob@example.test');
        self::assertNotNull($bob);
        $personal = $this->tokens->personal($bob, 'Bob CLI', ['profile:read']);
        self::assertSame(['id' => '2', 'name' => 'Bob'], json_decode($this->call(Method::Get, '/api/v1/me', $personal->value)->body, true));
        self::assertSame(401, $this->call(Method::Get, '/api/v1/me')->status->value, 'Principal must not leak into guests.');
        self::assertSame(204, $this->call(Method::Delete, '/api/v1/token', $token)->status->value);
        self::assertSame(401, $this->call(Method::Get, '/api/v1/me', $token)->status->value);
        self::assertSame(200, $this->call(Method::Get, '/api/v1/me', $personal->value)->status->value);
        $narrow = $this->tokens->personal($bob, 'No scopes');
        $forbidden = $this->call(Method::Get, '/api/v1/me', $narrow->value);
        self::assertSame(403, $forbidden->status->value);
        self::assertStringContainsString('insufficient_scope', $forbidden->headers->first('WWW-Authenticate') ?? '');
    }

    public function test_mfa_is_required_and_a_recovery_code_is_consumed_once(): void
    {
        $secret = Totp::secret();
        $codes = $this->mfa->enable('1', $secret, Totp::code($secret, time()));
        self::assertSame(401, $this->login()->status->value);
        $success = $this->login($codes[0]);
        self::assertSame(200, $success->status->value, $success->body);
        self::assertSame(401, $this->login($codes[0])->status->value);
        self::assertSame(200, $this->call(Method::Get, '/api/v1/me', $this->token($success))->status->value);
    }

    public function test_account_wide_login_limit_has_exact_threshold_and_retry_after(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->call(Method::Post, '/api/v1/token', body: ['email' => 'ada@example.test', 'password' => 'wrong']);
            self::assertSame(401, $response->status->value);
        }
        $response = $this->login();
        self::assertSame(429, $response->status->value);
        self::assertGreaterThan(0, (int) $response->headers->first('Retry-After'));
        self::assertSame('application/problem+json', $response->headers->first('Content-Type'));
    }

    public function test_unknown_versions_and_methods_are_json_not_html(): void
    {
        $missing = $this->call(Method::Get, '/api/v2/me');
        self::assertSame(404, $missing->status->value);
        self::assertSame('application/problem+json', $missing->headers->first('Content-Type'));
        $method = $this->call(Method::Get, '/api/v1/token');
        self::assertSame(405, $method->status->value);
        self::assertStringContainsString('POST', $method->headers->first('Allow') ?? '');
        self::assertSame('no-store', $method->headers->first('Cache-Control'));
        self::assertSame(404, $this->call(Method::Post, '/api/v2/me')->status->value);
        self::assertSame(405, $this->call(Method::Post, '/api/v1/me')->status->value);
    }

    public function test_authenticated_rate_limit_is_per_account_across_tokens(): void
    {
        $ada = $this->users->findByEmail('ada@example.test');
        $bob = $this->users->findByEmail('bob@example.test');
        self::assertNotNull($ada);
        self::assertNotNull($bob);
        $first = $this->tokens->personal($ada, 'First', ['profile:read']);
        $second = $this->tokens->personal($ada, 'Second', ['profile:read']);
        $other = $this->tokens->personal($bob, 'Bob', ['profile:read']);
        for ($i = 0; $i < 60; $i++) {
            $response = $this->call(Method::Get, '/api/v1/me', $i % 2 === 0 ? $first->value : $second->value);
            self::assertSame(200, $response->status->value);
            self::assertSame((string) (59 - $i), $response->headers->first('X-RateLimit-Remaining'));
        }
        self::assertSame(429, $this->call(Method::Get, '/api/v1/me', $second->value)->status->value);
        self::assertSame(200, $this->call(Method::Get, '/api/v1/me', $other->value)->status->value);
    }

    private function login(string $code = ''): Response
    {
        return $this->call(Method::Post, '/api/v1/token', body: [
            'email' => 'ada@example.test', 'password' => 'correct-horse-battery', 'code' => $code,
        ]);
    }

    /** @param array<string, string>|null $body */
    private function call(Method $method, string $path, ?string $bearer = null, ?array $body = null): Response
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
        return $this->app->handle(Requests::of($method, $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    private function token(Response $response): string
    {
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $token = $data['access_token'] ?? null;
        self::assertIsString($token);
        return $token;
    }
}
