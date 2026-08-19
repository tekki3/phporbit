<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Integration;

/**
 * Skips a test when the server it needs is not there.
 *
 * These tests exist because the rest of the suite cannot reach a real MySQL,
 * PostgreSQL or SMTP server — the per-engine SQL and the SMTP conversation are
 * otherwise only ever checked against what the framework *generates*, never
 * against what a server *accepts*. That is a real gap, and one that no amount
 * of unit testing closes.
 *
 * They skip rather than fail when the service is absent, so `composer test` on
 * a laptop stays useful. CI supplies the services, so the gap is closed on
 * every push instead of never.
 */
trait RequiresService
{
    /**
     * Skips unless the named environment variables are all set.
     *
     * @param list<string> $variables
     * @return array<string, string>
     */
    protected function requireEnvironment(array $variables, string $service): array
    {
        $values = [];

        foreach ($variables as $variable) {
            $value = getenv($variable);

            if ($value === false || $value === '') {
                self::markTestSkipped(sprintf(
                    'No %s available: set %s to run this. CI provides one.',
                    $service,
                    implode(', ', $variables),
                ));
            }

            $values[$variable] = $value;
        }

        return $values;
    }

    /**
     * Skips unless something is listening, so a half-configured environment
     * reports "no server" rather than a confusing connection error.
     */
    protected function requireReachable(string $host, int $port, string $service): void
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 3);

        if ($socket === false) {
            self::markTestSkipped(sprintf('%s is not reachable at %s:%d.', $service, $host, $port));
        }

        fclose($socket);
    }
}
