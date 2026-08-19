<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class StorageController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly ProjectPaths $paths,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $files = glob($this->paths->root . '/storage/cache/views/*') ?: [];
        $bytes = 0;

        foreach ($files as $file) {
            $bytes += is_file($file) ? (filesize($file) ?: 0) : 0;
        }

        return $this->view->respond('storage', [
            'title' => 'Storage',
            'subtitle' => 'Compiled templates on disk, in storage/cache/views.',
            'currentPath' => '/storage',
            'csrfToken' => Csrf::token($this->session),
            'fileCount' => count($files),
            'size' => self::humanBytes($bytes),
            'flash' => $this->session->takeFlash('admin.notice'),
            'error' => $this->session->takeFlash('admin.error'),
        ]);
    }

    /**
     * Formatted here rather than in the template: a template may only
     * interpolate a value, never call an arbitrary function to compute one.
     */
    private static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }

        $value = $bytes / 1024;

        foreach (['KB', 'MB', 'GB'] as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                return sprintf('%.1f %s', $value, $unit);
            }

            $value /= 1024;
        }

        return sprintf('%.1f GB', $value);
    }
}
