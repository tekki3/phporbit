<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use InvalidArgumentException;
use PhpOrbit\Config\Environment;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\Message;
use PhpOrbit\Mail\PersistingMailer;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

/**
 * The same as `orbit mail:test`: one real message through whatever
 * MAIL_DRIVER is configured, going through the same PersistingMailer every
 * controller uses — so a failure here is one more row in /mail, resendable
 * from there once whatever was wrong is fixed.
 */
final class SendTestMailController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Environment $env,
        private readonly PersistingMailer $mailer,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $to = trim($request->form('to') ?? '');
        $from = trim($request->form('from') ?? '') ?: ($this->env->raw('MAIL_FROM_ADDRESS') ?? '');
        $driver = $this->env->string('MAIL_DRIVER', 'array');

        $base = [
            'title' => 'Tools',
            'subtitle' => 'orbit key:generate and orbit mail:test, without leaving the browser.',
            'currentPath' => '/tools',
            'csrfToken' => Csrf::token($this->session),
            'mailDriver' => $driver,
            'defaultFrom' => $this->env->raw('MAIL_FROM_ADDRESS') ?? '',
            'generatedKey' => null,
            'mailTo' => $to,
            'mailFrom' => $request->form('from') ?? '',
        ];

        if ($from === '') {
            return $this->view->respond('tools', [
                ...$base,
                'mailResult' => null,
                'error' => 'No sender configured. Enter one, or set MAIL_FROM_ADDRESS in .env.',
            ], Status::UnprocessableEntity);
        }

        try {
            $message = Message::to($to)
                ->from($from)
                ->subject('phporbit test message')
                ->text(sprintf(
                    "Sent from the admin UI at %s, through the %s driver.\n\n"
                    . "If this arrived, MAIL_DRIVER=%s is delivering mail correctly.",
                    gmdate('c'),
                    $driver,
                    $driver,
                ));
        } catch (InvalidArgumentException $e) {
            return $this->view->respond('tools', [
                ...$base,
                'mailResult' => null,
                'error' => $e->getMessage(),
            ], Status::UnprocessableEntity);
        }

        try {
            $this->mailer->send($message);
        } catch (MailFailed $e) {
            return $this->view->respond('tools', [
                ...$base,
                'mailResult' => null,
                'error' => sprintf('Send failed (driver: %s): %s', $driver, $e->getMessage()),
            ], Status::UnprocessableEntity);
        }

        $result = in_array($driver, ['array', 'memory', 'null'], true)
            ? sprintf('Accepted by the "%s" driver — nothing left this machine.', $driver)
            : sprintf('Sent to %s via %s.', $to, $driver);

        return $this->view->respond('tools', [
            ...$base,
            'mailResult' => $result,
            'error' => null,
        ]);
    }
}
