<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AccountSecurity;
use App\Support\AuthPage;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\LoginFlow;
use PhpOrbit\Auth\Oidc\ExternalAccounts;
use PhpOrbit\Auth\Oidc\OidcClient;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class OidcCallbackController implements Handler
{
    public function __construct(
        private readonly OidcClient $client, private readonly ExternalAccounts $accounts,
        private readonly Authenticator $auth, private readonly LoginFlow $flow,
        private readonly AccountSecurity $security, private readonly AuthPage $page,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            $external = $this->client->finish(
                $request->attribute('provider') ?? '', $request->uri->queryParam('state') ?? '',
                $request->uri->queryParam('code') ?? '',
            );
            if ($external->linkUser !== null) {
                $user = $this->auth->user();
                if ($user === null || $user->authIdentifier() !== $external->linkUser
                    || !hash_equals($this->security->stamp($user), $external->linkStamp ?? '')) {
                    throw new AuthenticationFailed('Your account session changed. Sign in again before linking.');
                }
                $this->accounts->link($user, $external);

                return $this->page->back('/account/security', 'External account linked. It can now be used to sign in.');
            }
            $user = $this->accounts->user($external);
            if ($user === null) {
                throw new AuthenticationFailed('This external account is not linked. Sign in with your password first and link it from account security.');
            }
            $this->flow->begin($user);

            return $this->flow->pending() ? Response::redirect('/mfa') : $this->page->signedIn();
        } catch (AuthenticationFailed $exception) {
            return $this->page->back('/login', $exception->getMessage(), true);
        }
    }
}
