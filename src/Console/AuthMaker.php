<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class AuthMaker
{
    public const WIRING = <<<'PHP'
    $configureAuth = require $root . '/app/auth.php';
    if (!$configureAuth instanceof \Closure) {
        throw new \RuntimeException('app/auth.php must return a boot callback.');
    }
    $configureAuth($app, $env, $root);
    PHP;

    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = realpath($root) ?: throw new RuntimeException('The project directory does not exist.');
    }

    /** @return list<string> Paths relative to the project. Existing files are never replaced. */
    public function create(): array
    {
        if (!is_file($this->root . '/app/bootstrap.php')) {
            throw new RuntimeException('Run make:auth in a phporbit project with app/bootstrap.php.');
        }
        if (is_file($this->root . '/app/src/Auth/DatabaseUserProvider.php')) {
            throw new RuntimeException('This project already has demo authentication. Integrate registration and management manually, or start with a blank project.');
        }
        if ((glob($this->root . '/database/migrations/*_create_auth_kit.php') ?: []) !== []) {
            throw new RuntimeException('Authentication was already generated. Edit the existing files instead of regenerating.');
        }
        $framework = dirname(__DIR__, 2);
        $files = [];
        $source = $framework . '/stubs/auth';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item instanceof SplFileInfo && $item->isFile()) {
                $relative = substr($item->getPathname(), strlen($source) + 1);
                $files[$relative] = $this->read($item->getPathname());
            }
        }
        // The demo remains the source of truth for the existing second-factor
        // and email-link flows; generated projects own the copies afterwards.
        foreach ([
            'PasswordForgot', 'PasswordEmail', 'PasswordReset', 'PasswordUpdate',
            'MagicLink', 'MagicEmail', 'MagicConfirm', 'MagicLogin', 'MfaChallenge',
            'MfaVerify', 'MfaEnroll', 'MfaEnable', 'MfaDisable', 'Security',
            'OidcStart', 'OidcLink', 'OidcCallback', 'OidcUnlink',
        ] as $name) {
            $files['app/src/AuthKit/Controllers/' . $name . 'Controller.php'] = $this->adapt(
                $this->read($framework . '/app/src/Controllers/' . $name . 'Controller.php'),
            );
        }
        foreach (['AccountSecurity', 'AuthEmails', 'AuthPage'] as $name) {
            $contents = $this->adapt($this->read($framework . '/app/src/Support/' . $name . '.php'));
            if ($name === 'AuthPage') {
                $contents = str_replace(
                    ['$this->view->respond($template,', "'/notes'"],
                    ['$this->view->respond(\'auth-kit/\' . $template,', "'/account'"],
                    $contents,
                );
            }
            $files['app/src/AuthKit/' . $name . '.php'] = $contents;
        }
        foreach (glob($framework . '/app/templates/auth/*.orbit.php') ?: [] as $template) {
            $contents = preg_replace('/^\s*@if\(\$(?:notice|error)\).*@endif\r?\n/m', '', $this->read($template));
            if ($contents === null) {
                throw new RuntimeException('Cannot adapt authentication template ' . $template);
            }
            $files['app/templates/auth-kit/auth/' . basename($template)] = str_replace(
                "@extends('layout')", "@extends('auth-kit/layout')", $contents,
            );
        }
        $files['public/assets/css/auth.css'] = $this->read($framework . '/public/assets/app.css');
        $migration = $files['database/migrations/create_auth_kit.php'];
        unset($files['database/migrations/create_auth_kit.php']);
        $files['database/migrations/' . gmdate('YmdHis') . '_create_auth_kit.php'] = $migration;

        foreach (array_keys($files) as $relative) {
            $path = $this->root . '/' . $relative;
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException(sprintf('Refusing to overwrite "%s". No files were generated.', $relative));
            }
            for ($parent = dirname($path); $parent !== dirname($this->root) && $parent !== '/'; $parent = dirname($parent)) {
                if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) {
                    throw new RuntimeException(sprintf('Unsafe generator directory "%s".', $parent));
                }
            }
        }
        $created = [];
        try {
            foreach ($files as $relative => $contents) {
                $path = $this->root . '/' . $relative;
                $directory = dirname($path);
                if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                    throw new RuntimeException('Cannot create ' . $directory);
                }
                $stream = fopen($path, 'x');
                if ($stream === false) {
                    throw new RuntimeException('Cannot create ' . $relative);
                }
                $created[] = $relative;
                try {
                    if (fwrite($stream, $contents) !== strlen($contents)) {
                        throw new RuntimeException('Cannot finish writing ' . $relative);
                    }
                } finally {
                    fclose($stream);
                }
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($created) as $relative) {
                if (!unlink($this->root . '/' . $relative)) {
                    throw new RuntimeException('Generation failed and cleanup could not remove ' . $relative, previous: $exception);
                }
            }
            throw $exception;
        }
        sort($created);

        return $created;
    }

    private function adapt(string $source): string
    {
        return str_replace(
            ['namespace App\\Controllers;', 'App\\Support\\', 'namespace App\\Support;'],
            ['namespace App\\AuthKit\\Controllers;', 'App\\AuthKit\\', 'namespace App\\AuthKit;'],
            $source,
        );
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read authentication source ' . $path);
        }

        return $contents;
    }
}
