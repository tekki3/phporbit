<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Middleware\Middleware;

/**
 * Guarantees no transaction outlives the request that opened it.
 *
 * The connection is a process-lifetime singleton, so a handler that opens a
 * transaction and then throws — or simply forgets to commit — would hand the
 * next request an connection already inside someone else's transaction. That
 * request's writes would then commit or roll back with work it never made.
 *
 * Per-request SAPIs hide this: the process dies and the driver rolls back. It
 * is only visible under a worker, which is why the cleanup is explicit here
 * rather than left to the runtime.
 */
final class TransactionGuard implements Middleware
{
    /** @var Closure(string): void */
    private readonly Closure $report;

    /**
     * @param (Closure(string): void)|null $report notified when a transaction had to be abandoned
     */
    public function __construct(?Closure $report = null)
    {
        $this->report = $report ?? static function (string $message): void {
            error_log($message);
        };
    }

    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        try {
            return $next($request);
        } finally {
            $connection = $scope->get(Connection::class);

            if ($connection->rollBackIfOpen()) {
                // Silence would let the bug persist while the symptoms appear
                // in an unrelated request later on.
                $this->report(sprintf(
                    'Transaction left open by %s %s was rolled back. A handler opened a '
                    . 'transaction without committing it.',
                    $request->method->value,
                    $request->uri->path,
                ));
            }
        }
    }

    private function report(string $message): void
    {
        ($this->report)($message);
    }
}
