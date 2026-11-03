<?php

declare(strict_types=1);

use App\AuthKit\Accounts;
use App\AuthKit\AuthEmails;
use App\AuthKit\Registration;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\EmailTokens;
use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Auth\Oidc\CurlOidcHttp;
use PhpOrbit\Auth\Oidc\OidcHttp;
use PhpOrbit\Auth\Oidc\OidcProviders;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Auth\SecurityState;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Config\Environment;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Container\Container;
use PhpOrbit\Crypto\CryptoFactory;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Database\Connection;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Log\Logger;
use PhpOrbit\Mail\MailerFactory;
use PhpOrbit\Session\Session;

return static function (Blueprint $app, Environment $env, string $root): void {
    $app->container->singleton(PasswordHasher::class, static fn (): PasswordHasher => new PasswordHasher());
    $app->container->singleton(SecurityState::class, static fn (Container $c): SecurityState => new SecurityState($c->get(Connection::class)));
    $app->container->singleton(Accounts::class, static fn (Container $c): Accounts => new Accounts(
        $c->get(Connection::class), $c->get(SecurityState::class),
    ));
    $app->container->singleton(UserProvider::class, static fn (Container $c): UserProvider => $c->get(Accounts::class));
    $app->container->singleton(LoginThrottle::class, static fn (Container $c): LoginThrottle => new LoginThrottle(
        $c->get(Connection::class), $env->int('LOGIN_MAX_ATTEMPTS', 5), $env->int('LOGIN_WINDOW_SECONDS', 900),
    ));
    $app->container->singleton(Mfa::class, static fn (Container $c): Mfa => new Mfa(
        $c->get(Connection::class), $c->get(SecurityState::class),
        static fn (): Encrypter => CryptoFactory::encrypterFromEnvironment($env),
    ));
    $app->container->singleton(OidcProviders::class, static fn (): OidcProviders => OidcProviders::fromEnvironment($env));
    $app->container->singleton(OidcHttp::class, static fn (): OidcHttp => new CurlOidcHttp());
    $app->container->scoped(Authenticator::class, static fn (RequestScope $scope): Authenticator => new Authenticator(
        $scope->get(Session::class), $scope->get(UserProvider::class), $scope->get(PasswordHasher::class),
        static fn (Identity $user): string => hash('sha256', $user->passwordHash() . ':' . $scope->get(SecurityState::class)->version($user->authIdentifier())),
    ));
    $app->container->scoped(AuthEmails::class, static fn (RequestScope $scope): AuthEmails => new AuthEmails(
        $scope->get(EmailTokens::class), $scope->get(UserProvider::class), $scope->get(LoginThrottle::class),
        MailerFactory::fromEnvironment($env), $env, $scope->get(Logger::class),
    ));
    $app->container->scoped(Registration::class, static fn (RequestScope $scope): Registration => new Registration(
        $scope->get(Connection::class), $scope->get(PasswordHasher::class), $scope->get(LoginThrottle::class),
        MailerFactory::fromEnvironment($env), $env, $scope->get(Logger::class),
    ));
    $app->loadRoutes($root . '/app/auth-routes.php');
};
