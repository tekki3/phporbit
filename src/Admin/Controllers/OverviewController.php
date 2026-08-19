<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Admin\AdminApplication;
use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Database\Migrator;
use PhpOrbit\Database\QueryFailed;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Mail\MailLogRepository;
use PhpOrbit\Mail\MailStatus;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

/**
 * The landing page: one tile per area, so a glance says whether anything
 * needs attention before clicking through to it.
 *
 * Every tile is fetched defensively. A project that has never run
 * `orbit migrate` has no `mail_log` table yet, and the overview page is
 * exactly the place that should say so plainly rather than 500.
 */
final class OverviewController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Migrator $migrator,
        private readonly MailLogRepository $mail,
        private readonly ProjectPaths $paths,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $mailAvailable = true;
        $sent = $failed = 0;

        try {
            $sent = $this->mail->count(MailStatus::Sent);
            $failed = $this->mail->count(MailStatus::Failed);
        } catch (QueryFailed) {
            $mailAvailable = false;
        }

        return $this->view->respond('overview', [
            'title' => 'Overview',
            'subtitle' => 'Reads and acts on this project directly — the same database, mail '
                . 'configuration and filesystem orbit itself uses. Nothing here is a preview.',
            'currentPath' => '/',
            'pendingMigrations' => count($this->migrator->pending()),
            'appliedMigrations' => count($this->migrator->applied()),
            'mailAvailable' => $mailAvailable,
            'mailSent' => $sent,
            'mailFailed' => $failed,
            // Whether the project's routes.php would add debug-only routes
            // does not change how many there are to count, so false is fine
            // here — it only matters when the table is actually being shown.
            'routeCount' => count(AdminApplication::projectRoutes($this->paths, false)),
            'sessionCount' => $this->sessionFileCount(),
            'flash' => $this->session->takeFlash('admin.notice'),
            'error' => $this->session->takeFlash('admin.error'),
        ]);
    }

    private function sessionFileCount(): int
    {
        return count(glob($this->paths->root . '/storage/sessions/sess_*') ?: []);
    }
}
