<?php

declare(strict_types=1);

use App\AuthKit\Controllers as Auth;
use App\AuthKit\RequireAdmin;
use PhpOrbit\Auth\RequireAuthentication;
use PhpOrbit\Routing\RouteCollection;

return static function (RouteCollection $routes, bool $debug): void {
    $routes->get('/login', Auth\LoginController::class, 'login');
    $routes->post('/login', Auth\LoginAttemptController::class, 'login.attempt');
    $routes->post('/logout', Auth\LogoutController::class, 'logout');
    $routes->get('/register', Auth\RegisterController::class, 'register');
    $routes->post('/register', Auth\RegisterSubmitController::class, 'register.submit');
    $routes->get('/register/verify', Auth\VerifyController::class, 'register.verify');
    $routes->post('/register/verify', Auth\VerifySubmitController::class, 'register.verify.submit');
    $routes->get('/password/forgot', Auth\PasswordForgotController::class, 'password.forgot');
    $routes->post('/password/forgot', Auth\PasswordEmailController::class, 'password.email');
    $routes->get('/password/reset', Auth\PasswordResetController::class, 'password.reset');
    $routes->post('/password/reset', Auth\PasswordUpdateController::class, 'password.update');
    $routes->get('/magic', Auth\MagicLinkController::class, 'magic');
    $routes->post('/magic', Auth\MagicEmailController::class, 'magic.email');
    $routes->get('/magic/confirm', Auth\MagicConfirmController::class, 'magic.confirm');
    $routes->post('/magic/confirm', Auth\MagicLoginController::class, 'magic.login');
    $routes->get('/mfa', Auth\MfaChallengeController::class, 'mfa');
    $routes->post('/mfa', Auth\MfaVerifyController::class, 'mfa.verify');
    $routes->post('/auth/{provider}/start', Auth\OidcStartController::class, 'oidc.start');
    $routes->get('/auth/{provider}/callback', Auth\OidcCallbackController::class, 'oidc.callback');
    $routes->withMiddleware([new RequireAuthentication()], static function (RouteCollection $routes): void {
        $routes->get('/account', Auth\ProfileController::class, 'account');
        $routes->post('/account', Auth\ProfileUpdateController::class, 'account.update');
        $routes->get('/account/security', Auth\SecurityController::class, 'security');
        $routes->post('/account/mfa/enroll', Auth\MfaEnrollController::class, 'mfa.enroll');
        $routes->post('/account/mfa/enable', Auth\MfaEnableController::class, 'mfa.enable');
        $routes->post('/account/mfa/disable', Auth\MfaDisableController::class, 'mfa.disable');
        $routes->post('/account/link/{provider}', Auth\OidcLinkController::class, 'oidc.link');
        $routes->post('/account/unlink/{id:\d+}', Auth\OidcUnlinkController::class, 'oidc.unlink');
        $routes->withMiddleware([new RequireAdmin()], static function (RouteCollection $routes): void {
            $routes->get('/account/users', Auth\UsersController::class, 'users');
            $routes->post('/account/users/{id:\d+}', Auth\UserUpdateController::class, 'users.update');
        });
    });
};
