<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Worker;

use App\Auth\DatabaseUserProvider;
use App\Support\AuthEmails;
use Closure;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\EmailTokens;
use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Auth\Oidc\ExternalAccounts;
use PhpOrbit\Auth\Oidc\OidcHttp;
use PhpOrbit\Auth\Oidc\OidcProviders;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Auth\SecurityState;
use PhpOrbit\Auth\Totp;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Config\Environment;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Crypto\Key;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Log\Level;
use PhpOrbit\Log\Logger;
use PhpOrbit\Mail\ArrayMailer;
use PhpOrbit\Security\CsrfMiddleware;
use PhpOrbit\Session\Session;
use PhpOrbit\Session\SessionMiddleware;
use PhpOrbit\Tests\Support\ArraySessionStore;
use PhpOrbit\Tests\Support\OidcFixture;
use PhpOrbit\Tests\Support\Requests;
use PhpOrbit\View\TemplateEngine;
use PHPUnit\Framework\TestCase;

final class AuthFlowsTest extends TestCase
{
    private Application $app;
    private Connection $database;
    private DatabaseUserProvider $users;
    private SecurityState $state;
    private ArrayMailer $mail;
    private OidcFixture $http;
    private string $views;
    /** @var array<string, string> */
    private array $cookies = [];
    private string $csrf = '';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->database = Connection::sqlite(':memory:');
        foreach (glob($root . '/database/migrations/*.php') ?: [] as $path) {
            $migration = require $path;
            self::assertInstanceOf(Migration::class, $migration);
            $migration->up($this->database);
        }
        $hasher = new PasswordHasher();
        $this->users = new DatabaseUserProvider($this->database, $hasher);
        $this->users->create('ada@example.test', 'correct-horse-battery', 'Ada');
        $this->state = new SecurityState($this->database);
        $this->mail = new ArrayMailer();
        $this->http = new OidcFixture();
        $this->views = sys_get_temp_dir() . '/orbit-auth-views-' . bin2hex(random_bytes(6));
        $view = new TemplateEngine($root . '/app/templates', $this->views, shared: [
            'appUrl' => 'https://app.example.test', 'hasDocs' => false, 'sapi' => 'test', 'phpVersion' => PHP_VERSION,
        ]);
        $encrypter = new Encrypter(Key::generate());
        $sessions = new ArraySessionStore();
        $env = Environment::fromArray(['APP_URL' => 'https://app.example.test', 'MAIL_DRIVER' => 'smtp']);
        $logger = new class implements Logger {
            public function log(Level $level, string $message, array $context = []): void
            {
            }
        };
        $define = require $root . '/app/routes.php';
        self::assertInstanceOf(Closure::class, $define);
        $this->app = Application::boot(function (Blueprint $app) use ($hasher, $view, $encrypter, $sessions, $define, $env, $logger): void {
            $app->container->singleton(Connection::class, fn (): Connection => $this->database);
            $app->container->singleton(PasswordHasher::class, static fn (): PasswordHasher => $hasher);
            $app->container->singleton(UserProvider::class, fn (): UserProvider => $this->users);
            $app->container->singleton(SecurityState::class, fn (): SecurityState => $this->state);
            $app->container->singleton(Mfa::class, fn (): Mfa => new Mfa($this->database, $this->state, $encrypter));
            $app->container->singleton(Encrypter::class, static fn (): Encrypter => $encrypter);
            $app->container->singleton(LoginThrottle::class, fn (): LoginThrottle => new LoginThrottle($this->database));
            $app->container->singleton(TemplateEngine::class, static fn (): TemplateEngine => $view);
            $app->container->singleton(OidcHttp::class, fn (): OidcHttp => $this->http);
            $app->container->singleton(OidcProviders::class, fn (): OidcProviders => new OidcProviders([$this->http->provider('google')]));
            $app->container->scoped(Authenticator::class, fn (RequestScope $scope): Authenticator => new Authenticator(
                $scope->get(Session::class), $this->users, $hasher,
                fn (Identity $user): string => hash('sha256', $user->passwordHash() . ':' . $this->state->version($user->authIdentifier())),
            ));
            $app->container->scoped(AuthEmails::class, fn (RequestScope $scope): AuthEmails => new AuthEmails(
                $scope->get(EmailTokens::class), $this->users, $scope->get(LoginThrottle::class), $this->mail, $env, $logger,
            ));
            $define($app->routes, false);
            $app->middleware(new SessionMiddleware($sessions), new CsrfMiddleware());
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->views . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->views)) {
            rmdir($this->views);
        }
    }

    public function test_enroll_sign_out_magic_link_and_recovery_challenge_do_not_leak_between_visitors(): void
    {
        $codes = $this->enroll();
        $this->post('/logout');
        $this->get('/magic');
        $requested = $this->post('/magic', ['email' => 'ada@example.test']);
        self::assertSame('/magic', $requested->headers->first('Location'));
        $message = $this->mail->sent()[0];
        self::assertNotNull($message->textBody);
        preg_match('~https://app\.example\.test(/magic/confirm\?token=[a-f0-9]{64})~', $message->textBody, $match);
        self::assertSame(2, count($match));
        self::assertIsString($match[1] ?? null);
        $link = $match[1];
        $beforeLinkCookies = $this->cookies;
        $opened = $this->get($link);
        self::assertNotSame($beforeLinkCookies['orbit_session'], $this->cookies['orbit_session'], 'Receiving an email credential must rotate the browser session.');
        self::assertSame('/magic/confirm', $opened->headers->first('Location'));
        self::assertSame(1, $this->database->query('auth_email_tokens')->count(), 'GET must not consume the link.');
        $this->get('/magic/confirm');
        self::assertSame('/mfa', $this->post('/magic/confirm')->headers->first('Location'));
        self::assertSame('/login', $this->get('/account/security')->headers->first('Location'));
        $this->get('/mfa');
        self::assertSame('/account/security', $this->post('/mfa', ['code' => $codes[0]])->headers->first('Location'));
        self::assertStringContainsString('Two-step verification is on', $this->get('/account/security')->body);
        $signedInCookies = $this->cookies;

        $this->cookies = [];
        self::assertSame('/login', $this->get('/account/security')->headers->first('Location'));
        $this->get('/magic');
        $this->get($link);
        $this->get('/magic/confirm');
        self::assertSame('/magic', $this->post('/magic/confirm')->headers->first('Location'), 'The token is single use.');
        $this->cookies = $signedInCookies;
        self::assertSame(Status::Ok, $this->get('/account/security')->status);
    }

    public function test_password_reset_revokes_existing_session_and_preserves_enrolled_mfa(): void
    {
        $this->enroll();
        $oldCookies = $this->cookies;
        $this->cookies = [];
        $this->get('/password/forgot');
        $this->post('/password/forgot', ['email' => 'ada@example.test']);
        $text = $this->mail->sent()[0]->textBody;
        self::assertNotNull($text);
        preg_match('~https://app\.example\.test(/password/reset\?token=[a-f0-9]{64})~', $text, $match);
        self::assertSame(2, count($match));
        self::assertIsString($match[1] ?? null);
        $this->get($match[1]);
        $this->get('/password/reset');
        self::assertSame('/login', $this->post('/password/reset', [
            'password' => 'a-new-secure-password', 'password_confirmation' => 'a-new-secure-password',
        ])->headers->first('Location'));
        $this->cookies = $oldCookies;
        self::assertSame('/login', $this->get('/account/security')->headers->first('Location'));
        $this->get('/login');
        self::assertSame('/mfa', $this->post('/login', [
            'email' => 'ada@example.test', 'password' => 'a-new-secure-password',
        ])->headers->first('Location'));
        self::assertSame('/login', $this->get('/account/security')->headers->first('Location'));
    }

    public function test_external_login_requires_explicit_link_and_mfa_and_can_be_unlinked(): void
    {
        $codes = $this->enroll();
        $this->get('/account/security');
        $start = $this->post('/account/link/google', ['password' => 'correct-horse-battery', 'code' => $codes[0]]);
        $this->completeCallback($start);
        self::assertSame(1, $this->database->query('auth_external_accounts')->count());
        $this->post('/logout');
        $this->get('/login');
        $response = $this->completeCallback($this->post('/auth/google/start'));
        self::assertSame('/mfa', $response->headers->first('Location'));
        self::assertSame('/login', $this->get('/account/security')->headers->first('Location'));
        $this->get('/mfa');
        $this->post('/mfa', ['code' => $codes[1]]);
        $security = $this->get('/account/security');
        self::assertStringContainsString('Linked google account', $security->body);
        $id = $this->database->query('auth_external_accounts')->value('id');
        $this->post('/account/unlink/' . (string) $id, ['password' => 'correct-horse-battery', 'code' => $codes[2]]);
        self::assertSame(0, $this->database->query('auth_external_accounts')->count());
    }

    public function test_unknown_external_accounts_cannot_sign_in_even_with_matching_email(): void
    {
        $this->get('/login');
        self::assertSame('/login', $this->completeCallback($this->post('/auth/google/start'))->headers->first('Location'));
        self::assertStringContainsString('not linked', $this->get('/login')->body);
        self::assertSame('/login', $this->get('/account/security')->headers->first('Location'));
        self::assertSame(0, $this->database->query('auth_external_accounts')->count());
    }

    public function test_link_callback_fails_when_password_or_security_version_changes(): void
    {
        $this->signIn();
        $start = $this->post('/account/link/google', ['password' => 'correct-horse-battery']);
        $this->state->revoke('1');
        self::assertSame('/login', $this->completeCallback($start)->headers->first('Location'));
        self::assertSame(0, $this->database->query('auth_external_accounts')->count());
    }

    public function test_csrf_is_required_for_email_requests_and_external_login_starts(): void
    {
        self::assertSame(Status::Forbidden, $this->app->handle(Requests::form('/magic', ['email' => 'ada@example.test']))->status);
        self::assertSame(Status::Forbidden, $this->app->handle(Requests::form('/auth/google/start', []))->status);
        self::assertSame([], $this->mail->sent());
        self::assertSame([], $this->http->requests);
    }

    public function test_email_requests_have_same_response_for_known_unknown_and_rate_limited_accounts(): void
    {
        $this->get('/magic');
        $known = $this->post('/magic', ['email' => 'ada@example.test']);
        $unknown = $this->post('/magic', ['email' => 'nobody@example.test']);
        self::assertSame($known->body, $unknown->body);
        self::assertSame($known->status, $unknown->status);
        self::assertSame($known->headers->first('Location'), $unknown->headers->first('Location'));
        for ($index = 0; $index < 6; $index++) {
            $this->post('/magic', ['email' => 'ada@example.test']);
        }
        self::assertCount(5, $this->mail->sent());
    }

    public function test_sensitive_pages_are_not_cached_or_referrer_sources(): void
    {
        foreach (['/login', '/magic', '/magic/confirm', '/password/forgot', '/password/reset'] as $url) {
            $response = $this->get($url);
            self::assertSame('no-store', $response->headers->first('Cache-Control'));
            self::assertSame('no-referrer', $response->headers->first('Referrer-Policy'));
        }
    }

    /** @return list<string> */
    private function enroll(): array
    {
        $this->signIn();
        $enroll = $this->post('/account/mfa/enroll', ['password' => 'correct-horse-battery']);
        self::assertSame(Status::Ok, $enroll->status, $enroll->body);
        preg_match('/class="auth-secret">([A-Z2-7]{32})/', $enroll->body, $match);
        self::assertSame(2, count($match));
        self::assertIsString($match[1] ?? null);
        $enabled = $this->post('/account/mfa/enable', [
            'password' => 'correct-horse-battery', 'code' => Totp::code($match[1], time()),
        ]);
        self::assertSame(Status::Ok, $enabled->status, $enabled->body);
        preg_match_all('/<li><code>([a-f0-9]{20})<\/code>/', $enabled->body, $matches);
        self::assertCount(10, $matches[1]);

        return $matches[1];
    }

    private function signIn(): void
    {
        $this->get('/login');
        self::assertSame('/notes', $this->post('/login', [
            'email' => 'ada@example.test', 'password' => 'correct-horse-battery',
        ])->headers->first('Location'));
        self::assertSame(Status::Ok, $this->get('/account/security')->status);
    }

    private function completeCallback(Response $start): Response
    {
        $location = $start->headers->first('Location');
        self::assertNotNull($location, $start->body);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertIsString($params['nonce'] ?? null);
        self::assertIsString($params['state'] ?? null);
        $this->http->nonce = $params['nonce'];

        return $this->get('/auth/google/callback?code=code&state=' . $params['state']);
    }

    private function get(string $url): Response
    {
        $response = $this->app->handle(Requests::of(Method::Get, $url, cookies: $this->cookies));
        $this->capture($response);

        return $response;
    }

    /** @param array<string, string> $fields */
    private function post(string $url, array $fields = []): Response
    {
        $response = $this->app->handle(Requests::form($url, $fields + ['_token' => $this->csrf], $this->cookies));
        $this->capture($response);

        return $response;
    }

    private function capture(Response $response): void
    {
        foreach ($response->headers->all('Set-Cookie') as $cookie) {
            if (preg_match('/^orbit_session=([^;]*)/', $cookie, $matches) === 1) {
                $this->cookies['orbit_session'] = $matches[1];
            }
        }
        if (preg_match('/name="_token" value="([^"]+)"/', $response->body, $matches) === 1) {
            $this->csrf = $matches[1];
        }
        self::assertLessThan(500, $response->status->value, $response->body);
    }
}
