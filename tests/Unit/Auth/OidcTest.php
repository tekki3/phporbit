<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Auth;

use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Oidc\ExternalAccounts;
use PhpOrbit\Auth\Oidc\ExternalIdentity;
use PhpOrbit\Auth\Oidc\OidcClient;
use PhpOrbit\Auth\Oidc\OidcProvider;
use PhpOrbit\Auth\Oidc\OidcProviders;
use PhpOrbit\Auth\Oidc\OidcHttp;
use PhpOrbit\Config\Environment;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;
use PhpOrbit\Session\Session;
use PhpOrbit\Tests\Support\ArrayUserProvider;
use PhpOrbit\Tests\Support\OidcFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OidcTest extends TestCase
{
    private OidcFixture $http;
    private Session $session;
    private OidcClient $client;
    /** @var array<string, string> */
    private array $parameters;

    protected function setUp(): void
    {
        $this->http = new OidcFixture();
        $this->session = Session::started();
        $this->client = new OidcClient(new OidcProviders([$this->http->provider()]), $this->http, $this->session);
        $this->start();
    }

    public function test_authorization_and_token_exchange_bind_state_nonce_pkce_and_redirect(): void
    {
        self::assertSame('S256', $this->parameters['code_challenge_method']);
        self::assertSame('code', $this->parameters['response_type']);
        self::assertSame('openid profile email', $this->parameters['scope']);
        $identity = $this->client->finish('test', $this->parameters['state'], 'authorization-code');
        self::assertSame('external-user', $identity->subject);
        self::assertSame('https://id.example.test', $identity->issuer);
        self::assertNull($identity->linkUser);
        self::assertCount(2, $this->http->requests);
        $form = $this->http->requests[0]['form'];
        self::assertSame($this->parameters['code_challenge'],
            rtrim(strtr(base64_encode(hash('sha256', $form['code_verifier'], true)), '+/', '-_'), '='));
        self::assertSame($this->parameters['redirect_uri'], $form['redirect_uri']);
        self::assertSame('authorization-code', $form['code']);
        self::assertSame('test-secret', $form['client_secret']);
        self::assertNull($this->session->get('_oidc_verifier'));
    }

    public function test_wrong_state_is_rejected_before_network_and_flow_is_consumed(): void
    {
        try {
            $this->client->finish('test', 'bad-state', 'code');
            self::fail('Wrong state accepted.');
        } catch (AuthenticationFailed) {
            self::assertSame([], $this->http->requests);
        }
        $this->expectException(AuthenticationFailed::class);
        $this->client->finish('test', $this->parameters['state'], 'code');
    }

    public function test_browser_binding_expiration_and_callback_replay(): void
    {
        $other = new OidcClient(new OidcProviders([$this->http->provider()]), $this->http, Session::started());
        try {
            $other->finish('test', $this->parameters['state'], 'code');
            self::fail('A different browser must not accept the state.');
        } catch (AuthenticationFailed) {
            self::assertSame([], $this->http->requests);
        }
        $this->session->set('_oidc_expires', time());
        $this->expectException(AuthenticationFailed::class);
        $this->client->finish('test', $this->parameters['state'], 'code');
    }

    public function test_a_successful_callback_cannot_be_replayed(): void
    {
        $this->client->finish('test', $this->parameters['state'], 'code');
        $this->expectException(AuthenticationFailed::class);
        $this->client->finish('test', $this->parameters['state'], 'code');
    }

    /** @return iterable<string, array{array<string, scalar|list<string>|null>}> */
    public static function invalidClaims(): iterable
    {
        yield 'issuer' => [['iss' => 'https://attacker.example.test']];
        yield 'audience' => [['aud' => 'another-client']];
        yield 'multiple audiences without azp' => [['aud' => ['test-client', 'another-client']]];
        yield 'wrong azp' => [['azp' => 'another-client']];
        yield 'nonce' => [['nonce' => 'another-nonce']];
        yield 'expired' => [['exp' => 1]];
        yield 'missing expiry' => [['exp' => null]];
        yield 'future issued at' => [['iat' => 9999999999]];
        yield 'stale issued at' => [['iat' => 1]];
        yield 'empty subject' => [['sub' => '']];
        yield 'invalid subject' => [['sub' => 123]];
        yield 'oversized subject' => [['sub' => str_repeat('s', 192)]];
    }

    /** @param array<string, scalar|list<string>|null> $claims */
    #[DataProvider('invalidClaims')]
    public function test_invalid_signed_claims_are_refused(array $claims): void
    {
        $this->http->claims = $claims;
        $this->expectException(AuthenticationFailed::class);
        $this->client->finish('test', $this->parameters['state'], 'code');
    }

    public function test_algorithm_confusion_is_refused(): void
    {
        $this->http->algorithm = 'HS256';
        $this->expectException(AuthenticationFailed::class);
        $this->client->finish('test', $this->parameters['state'], 'code');
    }

    public function test_unknown_signing_keys_are_refused(): void
    {
        $this->http->kid = 'attacker';
        $this->expectException(AuthenticationFailed::class);
        $this->client->finish('test', $this->parameters['state'], 'code');
    }

    public function test_a_forged_signature_is_refused_even_when_the_key_id_matches(): void
    {
        $attacker = new OidcFixture();
        $http = new class($this->http, $attacker) implements OidcHttp {
            public function __construct(private readonly OidcFixture $trusted, private readonly OidcFixture $attacker)
            {
            }

            public function request(string $url, array $form = []): string
            {
                return ($form === [] ? $this->trusted : $this->attacker)->request($url, $form);
            }
        };
        $client = new OidcClient(new OidcProviders([$this->http->provider()]), $http, $this->session);
        $attacker->nonce = $this->parameters['nonce'];
        $this->expectException(AuthenticationFailed::class);
        $client->finish('test', $this->parameters['state'], 'code');
    }

    public function test_both_google_issuer_spellings_resolve_to_the_same_identity(): void
    {
        $provider = new OidcProvider('google', 'test-client', 'test-secret', 'https://app.example.test/auth/google/callback',
            'https://id.example.test/authorize', 'https://id.example.test/token', 'https://id.example.test/keys',
            ['https://accounts.google.com', 'accounts.google.com']);
        $client = new OidcClient(new OidcProviders([$provider]), $this->http, $this->session);
        $identities = [];
        foreach (['https://accounts.google.com', 'accounts.google.com'] as $issuer) {
            parse_str((string) parse_url($client->start('google'), PHP_URL_QUERY), $params);
            self::assertIsString($params['nonce']);
            self::assertIsString($params['state']);
            $this->http->nonce = $params['nonce'];
            $this->http->claims = ['iss' => $issuer];
            $identities[] = $client->finish('google', $params['state'], 'code')->key();
        }
        self::assertSame($identities[0], $identities[1]);
    }

    public function test_linking_context_is_bound_to_the_browser_flow(): void
    {
        $this->start('1', 'security-stamp');
        $identity = $this->client->finish('test', $this->parameters['state'], 'code');
        self::assertSame('1', $identity->linkUser);
        self::assertSame('security-stamp', $identity->linkStamp);
    }

    public function test_entra_tenants_and_key_issuers_are_checked(): void
    {
        $tenant = '12345678-1234-1234-1234-123456789abc';
        $issuer = 'https://login.microsoftonline.com/' . $tenant . '/v2.0';
        $this->http->issuer = $issuer;
        $this->http->claims = ['tid' => $tenant];
        $this->http->keyIssuer = 'https://login.microsoftonline.com/{tenantid}/v2.0';
        $provider = new OidcProvider('entra', 'test-client', 'test-secret', 'https://app.example.test/auth/entra/callback',
            'https://id.example.test/authorize', 'https://id.example.test/token', 'https://id.example.test/keys',
            [$issuer], [$tenant]);
        $client = new OidcClient(new OidcProviders([$provider]), $this->http, $this->session);
        parse_str((string) parse_url($client->start('entra'), PHP_URL_QUERY), $params);
        self::assertIsString($params['nonce']);
        self::assertIsString($params['state']);
        $this->http->nonce = $params['nonce'];
        self::assertSame($issuer, $client->finish('entra', $params['state'], 'code')->issuer);

        parse_str((string) parse_url($client->start('entra'), PHP_URL_QUERY), $params);
        self::assertIsString($params['nonce']);
        self::assertIsString($params['state']);
        $this->http->nonce = $params['nonce'];
        $this->http->claims = ['tid' => 'ffffffff-1234-1234-1234-123456789abc'];
        $this->expectException(AuthenticationFailed::class);
        $client->finish('entra', $params['state'], 'code');
    }

    public function test_configuration_is_opt_in_and_entra_requires_explicit_tenants(): void
    {
        self::assertSame([], OidcProviders::fromEnvironment(Environment::fromArray([]))->names());
        $this->expectException(\InvalidArgumentException::class);
        OidcProviders::fromEnvironment(Environment::fromArray(['ENTRA_CLIENT_ID' => 'client', 'ENTRA_CLIENT_SECRET' => 'secret']));
    }

    public function test_entra_rejects_a_signing_key_issued_for_another_tenant(): void
    {
        $tenant = '12345678-1234-1234-1234-123456789abc';
        $issuer = 'https://login.microsoftonline.com/' . $tenant . '/v2.0';
        $this->http->issuer = $issuer;
        $this->http->claims = ['tid' => $tenant];
        $this->http->keyIssuer = 'https://login.microsoftonline.com/ffffffff-1234-1234-1234-123456789abc/v2.0';
        $provider = new OidcProvider('entra', 'test-client', 'test-secret', 'https://app.example.test/auth/entra/callback',
            'https://id.example.test/authorize', 'https://id.example.test/token', 'https://id.example.test/keys',
            [$issuer], [$tenant]);
        $client = new OidcClient(new OidcProviders([$provider]), $this->http, $this->session);
        parse_str((string) parse_url($client->start('entra'), PHP_URL_QUERY), $params);
        self::assertIsString($params['nonce']);
        self::assertIsString($params['state']);
        $this->http->nonce = $params['nonce'];
        $this->expectException(AuthenticationFailed::class);
        $client->finish('entra', $params['state'], 'code');
    }

    public function test_provider_secrets_are_redacted_from_debug_output(): void
    {
        ob_start();
        var_dump($this->http->provider());
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('test-secret', $debug);
        self::assertStringContainsString('[redacted]', $debug);
    }

    public function test_external_accounts_never_auto_link_by_email_and_cannot_be_stolen(): void
    {
        $database = Connection::sqlite(':memory:');
        $migration = require dirname(__DIR__, 3) . '/database/migrations/0005_create_auth_security.php';
        self::assertInstanceOf(Migration::class, $migration);
        $migration->up($database);
        $users = new ArrayUserProvider();
        $ada = $users->add('1', 'ada@example.test', 'hash');
        $other = $users->add('2', 'other@example.test', 'hash');
        $accounts = new ExternalAccounts($database, $users);
        $external = $this->client->finish('test', $this->parameters['state'], 'code');
        self::assertNull($accounts->user($external));
        $accounts->link($ada, $external);
        self::assertSame('1', $accounts->user($external)?->authIdentifier());
        self::assertNull($accounts->user(new ExternalIdentity('test', 'https://another.example.test', $external->subject)));
        try {
            $accounts->link($other, $external);
            self::fail('A linked identity cannot change owners.');
        } catch (AuthenticationFailed) {
            self::assertSame('1', $accounts->user($external)?->authIdentifier());
        }
        $linked = $accounts->linked('1');
        self::assertCount(1, $linked);
        $accounts->unlink('1', (int) $linked[0]['id']);
        self::assertNull($accounts->user($external));
        $migration->down($database);
    }

    private function start(?string $user = null, ?string $stamp = null): void
    {
        $url = $this->client->start('test', $user, $stamp);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
        $this->parameters = [];
        foreach ($parameters as $key => $value) {
            self::assertIsString($value);
            $this->parameters[(string) $key] = $value;
        }
        $this->http->nonce = $this->parameters['nonce'];
    }
}
