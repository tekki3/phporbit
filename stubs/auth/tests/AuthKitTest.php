<?php

declare(strict_types=1);

namespace App\Tests;

use App\AuthKit\Accounts;
use App\AuthKit\Registration;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Totp;
use PhpOrbit\Config\Environment;
use PhpOrbit\Console\AuthAdmin;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migrator;
use PhpOrbit\Database\Model;
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\FormBody;
use PhpOrbit\Http\Uri;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Log\Level;
use PhpOrbit\Log\Logger;
use PhpOrbit\Mail\ArrayMailer;
use PhpOrbit\Mail\Mailer;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\Message;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class AuthKitTest extends TestCase
{
    private Application $app;
    private Connection $database;
    private string $databasePath;
    private ArrayMailer $mail;
    private Registration $registration;
    /** @var array<string, string> */
    private array $cookies = [];
    private string $csrf = '';
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__);
        $this->databasePath = sys_get_temp_dir() . '/orbit-auth-kit-' . bin2hex(random_bytes(6)) . '.sqlite';
        foreach ([
            'DB_DRIVER' => 'sqlite', 'DB_DATABASE' => $this->databasePath,
            'APP_URL' => 'http://localhost:8080', 'APP_DEBUG' => 'true', 'LOG_LEVEL' => 'error',
            'GOOGLE_CLIENT_ID' => '', 'ENTRA_CLIENT_ID' => '', 'MAIL_DRIVER' => 'array', 'SESSION_COOKIE' => 'orbit_session',
        ] as $key => $value) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        Model::resetConnectionForTesting();
        $application = require $root . '/app/bootstrap.php';
        self::assertInstanceOf(Application::class, $application);
        $this->app = $application;
        $this->database = $application->container()->get(Connection::class);
        $application->container()->get(Migrator::class)->migrate();
        $this->mail = new ArrayMailer();
        $logger = new class implements Logger {
            public function log(Level $level, string $message, array $context = []): void
            {
            }
        };
        $this->registration = new Registration(
            $this->database, $application->container()->get(PasswordHasher::class),
            new LoginThrottle($this->database), $this->mail,
            Environment::fromArray(['MAIL_DRIVER' => 'smtp', 'APP_URL' => 'http://localhost:8080']), $logger,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
        Model::resetConnectionForTesting();
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function test_registration_verification_login_mfa_and_profile(): void
    {
        $this->get('/register');
        $denied = $this->app->handle($this->request(Method::Post, '/register', ['email' => 'ada@example.test']));
        self::assertSame(403, $denied->status->value);
        $this->registration->request('ada@example.test', 'Ada', 'correct-horse-battery');
        self::assertSame(0, $this->database->query('auth_users')->count());
        $this->get('/login');
        self::assertSame('/login', $this->post('/login', ['email' => 'ada@example.test', 'password' => 'correct-horse-battery'])->headers->first('Location'));
        $token = $this->verificationToken();
        $this->get('/register/verify?token=' . $token);
        self::assertSame(0, $this->database->query('auth_users')->count(), 'GET is not verification.');
        $this->get('/register/verify');
        self::assertSame('/login', $this->post('/register/verify')->headers->first('Location'));
        self::assertSame(1, $this->database->query('auth_users')->count());
        self::assertSame(0, (int) $this->database->query('auth_users')->value('is_admin'));
        $this->get('/login');
        self::assertSame('/account', $this->post('/login', ['email' => 'ada@example.test', 'password' => 'correct-horse-battery'])->headers->first('Location'));
        self::assertSame(403, $this->get('/account/users')->status->value);
        self::assertSame(403, $this->post('/account/users/1', ['action' => 'promote', 'password' => 'correct-horse-battery'])->status->value);
        $this->get('/account');
        $this->post('/account', ['name' => 'Updated Ada', 'is_admin' => '1']);
        self::assertStringContainsString('Updated Ada', $this->get('/account')->body);
        self::assertSame(0, (int) $this->database->query('auth_users')->value('is_admin'));
        $this->get('/account/security');
        $enroll = $this->post('/account/mfa/enroll', ['password' => 'correct-horse-battery']);
        self::assertSame(1, preg_match('/class="auth-secret">([A-Z2-7]{32})/', $enroll->body, $match));
        self::assertIsString($match[1] ?? null);
        $enabled = $this->post('/account/mfa/enable', [
            'password' => 'correct-horse-battery', 'code' => Totp::code($match[1], time()),
        ]);
        preg_match_all('/<li><code>([a-f0-9]{20})<\/code>/', $enabled->body, $codes);
        self::assertCount(10, $codes[1]);
        $this->post('/logout');
        $this->get('/login');
        self::assertSame('/mfa', $this->post('/login', ['email' => 'ada@example.test', 'password' => 'correct-horse-battery'])->headers->first('Location'));
        self::assertSame('/login', $this->get('/account')->headers->first('Location'));
        $this->get('/mfa');
        $this->post('/mfa', ['code' => $codes[1][0]]);
        self::assertSame(200, $this->get('/account')->status->value);
        $authenticated = $this->cookies;
        $this->cookies = [];
        self::assertSame('/login', $this->get('/account')->headers->first('Location'));
        $this->cookies = $authenticated;
        self::assertSame(200, $this->get('/account')->status->value);
        $this->get('/register/verify?token=' . $token);
        $this->get('/register/verify');
        self::assertSame('/register', $this->post('/register/verify')->headers->first('Location'), 'Verification cannot be replayed.');
    }

    public function test_admin_management_requires_reauthentication_and_revokes_sessions(): void
    {
        $this->createAccount('admin@example.test');
        $this->createAccount('member@example.test');
        (new AuthAdmin($this->database))->promote('admin@example.test');
        $this->get('/login');
        $this->post('/login', ['email' => 'member@example.test', 'password' => 'correct-horse-battery']);
        $memberCookies = $this->cookies;
        $this->cookies = [];
        $this->get('/login');
        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct-horse-battery']);
        self::assertSame(200, $this->get('/account/users')->status->value);
        self::assertStringContainsString('member@example.test', $this->get('/account/users')->body);
        $this->post('/account/users/2', ['action' => 'block', 'password' => 'wrong']);
        self::assertSame(0, (int) $this->database->query('auth_users')->where('id', '=', 2)->value('blocked'));
        $this->get('/account/users');
        $this->post('/account/users/1', ['action' => 'demote', 'password' => 'correct-horse-battery']);
        self::assertSame(1, (int) $this->database->query('auth_users')->where('id', '=', 1)->value('is_admin'));
        $this->get('/account/users');
        $this->post('/account/users/2', ['action' => 'block', 'password' => 'correct-horse-battery']);
        self::assertSame(1, (int) $this->database->query('auth_users')->where('id', '=', 2)->value('blocked'));
        $adminCookies = $this->cookies;
        $this->cookies = $memberCookies;
        self::assertSame('/login', $this->get('/account')->headers->first('Location'));
        $this->get('/login');
        self::assertSame('/login', $this->post('/login', [
            'email' => 'member@example.test', 'password' => 'correct-horse-battery',
        ])->headers->first('Location'), 'Blocked accounts cannot sign in with valid credentials.');
        $this->cookies = $adminCookies;
        $this->get('/account/users');
        $this->post('/account/users/2', ['action' => 'unblock', 'password' => 'correct-horse-battery']);
        $this->cookies = $memberCookies;
        self::assertSame('/login', $this->get('/account')->headers->first('Location'), 'Unblocking cannot revive an old session.');
        $this->cookies = $adminCookies;
        $this->get('/account/users');
        $this->post('/account/users/2', ['action' => 'promote', 'password' => 'correct-horse-battery']);
        self::assertSame(1, (int) $this->database->query('auth_users')->where('id', '=', 2)->value('is_admin'));
        $this->cookies = [];
        $this->get('/login');
        $this->post('/login', ['email' => 'member@example.test', 'password' => 'correct-horse-battery']);
        $this->get('/account/users');
        $this->post('/account/users/1', ['action' => 'demote', 'password' => 'correct-horse-battery']);
        self::assertSame(0, (int) $this->database->query('auth_users')->where('id', '=', 1)->value('is_admin'));
        $this->cookies = $adminCookies;
        self::assertSame('/login', $this->get('/account/users')->headers->first('Location'), 'Demotion revokes administrator sessions.');
    }

    public function test_registration_expiry_collision_and_no_smtp_are_explicit(): void
    {
        $this->registration->request('ada@example.test', 'Ada', 'correct-horse-battery');
        $token = $this->verificationToken();
        $this->database->query('auth_registrations')->where('token_hash', '=', hash('sha256', $token))->update(['expires_at' => time()]);
        try {
            $this->registration->verify($token);
            self::fail('Expired verification must be refused.');
        } catch (AuthenticationFailed) {
            self::assertSame(0, $this->database->query('auth_users')->count());
        }
        $this->createAccount('ada@example.test');
        $this->registration->request('ADA@example.test', 'Another', 'another-long-password');
        self::assertSame(1, $this->database->query('auth_users')->count());
        $this->get('/register');
        $this->post('/register', ['email' => 'new@example.test', 'name' => 'New', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery']);
        self::assertStringContainsString('configure SMTP', $this->get('/register')->body);
        $logger = new class implements Logger {
            /** @var list<string> */
            public array $messages = [];

            public function log(Level $level, string $message, array $context = []): void
            {
                $this->messages[] = $message;
            }
        };
        $failing = new Registration(
            $this->database, $this->app->container()->get(PasswordHasher::class), new LoginThrottle($this->database),
            new class implements Mailer {
                public function send(Message $message): void
                {
                    throw MailFailed::connecting('test relay', 'offline');
                }
            },
            Environment::fromArray(['MAIL_DRIVER' => 'smtp', 'APP_URL' => 'http://localhost:8080']), $logger,
        );
        $failing->request('failure@example.test', 'Delivery failure', 'correct-horse-battery');
        self::assertSame(0, $this->database->query('auth_registrations')->where('email', '=', 'failure@example.test')->count());
        self::assertSame(['Registration verification email delivery failed.'], $logger->messages);
    }

    public function test_generated_migrations_are_reversible(): void
    {
        $migrator = $this->app->container()->get(Migrator::class);
        self::assertNotEmpty($migrator->rollback());
        self::assertSame([], $migrator->applied());
        self::assertNotEmpty($migrator->migrate());
        self::assertSame(0, $this->database->query('auth_users')->count());
        self::assertSame(1, $this->database->query('auth_management')->count());
    }

    public function test_generated_api_supports_json_login_personal_tokens_mfa_and_revocation(): void
    {
        $this->createAccount('api@example.test');
        $json = static function (string $email, string $code = ''): string {
            return json_encode(['email' => $email, 'password' => 'correct-horse-battery', 'code' => $code], JSON_THROW_ON_ERROR);
        };
        $login = $this->api(Method::Post, '/api/v1/token', $json('api@example.test'));
        self::assertSame(200, $login->status->value, $login->body);
        $data = json_decode($login->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $bearer = $data['access_token'] ?? null;
        self::assertIsString($bearer);
        $profile = $this->api(Method::Get, '/api/v1/me', bearer: $bearer);
        self::assertSame(['id' => '1', 'name' => 'Test member'], json_decode($profile->body, true));
        self::assertSame(401, $this->api(Method::Get, '/api/v1/me')->status->value);
        $this->get('/login');
        $this->post('/login', ['email' => 'api@example.test', 'password' => 'correct-horse-battery']);
        self::assertSame(401, $this->api(Method::Get, '/api/v1/me')->status->value, 'Browser cookies never authorize API requests.');
        $this->get('/account/tokens');
        $created = $this->post('/account/tokens', ['name' => 'Generated CLI', 'password' => 'correct-horse-battery']);
        self::assertSame(1, preg_match('/orbit_pat_[a-f0-9]{32}_[a-f0-9]{64}/', $created->body, $match));
        self::assertIsString($match[0] ?? null);
        self::assertSame(200, $this->api(Method::Get, '/api/v1/me', bearer: $match[0])->status->value);
        self::assertStringNotContainsString($match[0], $this->get('/account/tokens')->body, 'Plain token is shown only once.');
        self::assertSame(204, $this->api(Method::Delete, '/api/v1/token', bearer: $bearer)->status->value);
        self::assertSame(401, $this->api(Method::Get, '/api/v1/me', bearer: $bearer)->status->value);
        $mfa = $this->app->container()->get(\PhpOrbit\Auth\Mfa::class);
        $secret = Totp::secret();
        $codes = $mfa->enable('1', $secret, Totp::code($secret, time()));
        self::assertSame(401, $this->api(Method::Get, '/api/v1/me', bearer: $match[0])->status->value, 'MFA changes invalidate personal tokens.');
        self::assertSame(401, $this->api(Method::Post, '/api/v1/token', $json('api@example.test'))->status->value);
        self::assertSame(200, $this->api(Method::Post, '/api/v1/token', $json('api@example.test', $codes[0]))->status->value);
        self::assertSame(401, $this->api(Method::Post, '/api/v1/token', $json('api@example.test', $codes[0]))->status->value);
        self::assertSame(200, $this->get('/api/docs')->status->value);
        self::assertStringContainsString('3.0.3', $this->get('/api/openapi.json')->body);
    }

    private function api(Method $method, string $url, string $body = '', ?string $bearer = null): Response
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
        $response = $this->app->handle(new ServerRequest(
            $method, Uri::fromRequestTarget($url, 'http', 'localhost', 8080),
            Headers::fromArray($headers), $body, $this->cookies,
        ));
        self::assertLessThan(500, $response->status->value, $response->body);
        return $response;
    }

    private function createAccount(string $email): void
    {
        $this->registration->request($email, 'Test member', 'correct-horse-battery');
        $this->registration->verify($this->verificationToken());
    }

    private function verificationToken(): string
    {
        $text = $this->mail->last()?->textBody;
        self::assertIsString($text);
        self::assertSame(1, preg_match('/token=([a-f0-9]{64})/', $text, $match));
        self::assertIsString($match[1] ?? null);

        return $match[1];
    }

    /** @param array<string, string> $fields */
    private function request(Method $method, string $url, array $fields = []): ServerRequest
    {
        $headers = Headers::fromArray(['Content-Type' => 'application/x-www-form-urlencoded']);
        $body = http_build_query($fields);

        return new ServerRequest($method, Uri::fromRequestTarget($url, 'http', 'localhost', 8080),
            $headers, $body, $this->cookies, form: FormBody::parse($headers, $body));
    }

    private function get(string $url): Response
    {
        $response = $this->app->handle($this->request(Method::Get, $url));
        $this->capture($response);

        return $response;
    }

    /** @param array<string, string> $fields */
    private function post(string $url, array $fields = []): Response
    {
        $response = $this->app->handle($this->request(Method::Post, $url, $fields + ['_token' => $this->csrf]));
        $this->capture($response);

        return $response;
    }

    private function capture(Response $response): void
    {
        self::assertLessThan(500, $response->status->value, $response->body);
        foreach ($response->headers->all('Set-Cookie') as $cookie) {
            if (preg_match('/^orbit_session=([^;]*)/', $cookie, $match) === 1) {
                $this->cookies['orbit_session'] = $match[1];
            }
        }
        if (preg_match('/name="_token" value="([^"]+)"/', $response->body, $match) === 1) {
            $this->csrf = $match[1];
        }
    }
}
