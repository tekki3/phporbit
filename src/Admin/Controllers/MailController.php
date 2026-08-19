<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Database\QueryFailed;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Mail\MailLogRepository;
use PhpOrbit\Mail\MailStatus;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class MailController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly MailLogRepository $mail,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $status = MailStatus::tryFrom($request->uri->queryParam('status') ?? '');

        try {
            $entries = $this->mail->list($status, limit: 100);
            $available = true;
        } catch (QueryFailed) {
            $entries = [];
            $available = false;
        }

        return $this->view->respond('mail', [
            'title' => 'Mail',
            'subtitle' => 'Every message sent through this project, logged in mail_log — resend '
                . 'anything that failed.',
            'currentPath' => '/mail',
            'csrfToken' => Csrf::token($this->session),
            'entries' => $entries,
            'status' => $status,
            'available' => $available,
            'flash' => $this->session->takeFlash('admin.notice'),
            'error' => $this->session->takeFlash('admin.error'),
        ]);
    }
}
