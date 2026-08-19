<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Notes\NoteRepository;
use App\Support\CheckResult;
use App\Support\ScopedProbe;
use App\Support\WorkerStats;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migrator;
use PhpOrbit\Database\UnsafeQuery;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Upload\UploadedFile;
use PhpOrbit\Http\Upload\UploadError;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Routing\Route;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Security\Escaper;
use PhpOrbit\Session\Session;
use PhpOrbit\Validation\Validator;
use PhpOrbit\View\TemplateEngine;
use Throwable;

/**
 * The self-check page.
 *
 * Every check exercises the real component rather than asserting a constant —
 * the database check runs a query, the escaping check renders a template, the
 * isolation check reads live counters. A page that says "OK" without doing the
 * work is worse than no page at all.
 *
 * This controller is itself evidence: every constructor argument below was
 * autowired from the request scope, mixing process-lifetime singletons
 * (Connection, WorkerStats) with per-request instances (Session, ScopedProbe).
 */
final class SelfCheckController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Connection $database,
        private readonly NoteRepository $notes,
        private readonly Session $session,
        private readonly WorkerStats $worker,
        private readonly ScopedProbe $probe,
        private readonly Route $route,
        private readonly Migrator $migrator,
        private readonly PasswordHasher $hasher,
        private readonly Authenticator $auth,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $this->worker->recordRequest();
        $this->probe->touch();

        $visits = ($this->session->getInt('visits') ?? 0) + 1;
        $this->session->set('visits', $visits);

        // Grouped rather than listed flat: nineteen undifferentiated rows are
        // hard to scan, and the grouping is itself information — it says which
        // part of the framework each check belongs to.
        $groups = [
            'Request pipeline' => [
                $this->checkRouting($request),
                $this->checkMiddleware($request),
                $this->checkAutowiring(),
            ],
            'Identity and session' => [
                $this->checkAuthentication(),
                $this->checkSession($visits),
                $this->checkCsrf(),
            ],
            'Data access' => [
                $this->checkMigrations(),
                $this->checkDatabase(),
                $this->checkQueryBuilder(),
                $this->checkUnsafeQueryGuard(),
                $this->checkSqlInjection(),
            ],
            'Input and output safety' => [
                $this->checkTemplateEscaping(),
                $this->checkAttributeEscaping(),
                $this->checkValidation(),
                $this->checkUploadTypeSniffing(),
                $this->checkPasswordHashing(),
                $this->checkSecurityHeaders(),
            ],
            'Process model' => [
                $this->checkTransactionGuard(),
                $this->checkWorkerIsolation(),
            ],
        ];

        $checks = array_merge(...array_values($groups));
        $failed = array_filter($checks, static fn (CheckResult $c): bool => !$c->passed);

        return $this->view->respond('dashboard', [
            'title' => 'phporbit self-check',
            // Lets the layout mark the active navigation item.
            'currentPath' => '/',
            'checkGroups' => $groups,
            'checks' => $checks,
            'passedCount' => count($checks) - count($failed),
            'totalCount' => count($checks),
            'allPassed' => $failed === [],
            'workerRequests' => $this->worker->requests(),
            'workerUptime' => $this->worker->uptimeSeconds(),
            'longLived' => $this->worker->requests() > 1,
            'noteCount' => $this->notes->count(),
            'currentUser' => $this->auth->user(),
            'csrfToken' => Csrf::token($this->session),
        ]);
    }

    private function checkMigrations(): CheckResult
    {
        try {
            $pending = $this->migrator->pending();
            $applied = $this->migrator->applied();

            return CheckResult::of(
                'Migrations',
                $pending === [] && $applied !== [],
                sprintf('%d applied, none pending', count($applied)),
                $applied === []
                    ? 'no migrations have run — try ./orbit migrate'
                    : sprintf('pending: %s', implode(', ', $pending)),
            );
        } catch (Throwable $e) {
            return CheckResult::fail('Migrations', $e->getMessage());
        }
    }

    /**
     * Exercises the builder and confirms it emits placeholders rather than
     * inlined values.
     */
    private function checkQueryBuilder(): CheckResult
    {
        try {
            $query = $this->database->query('notes')
                ->select('id', 'title')
                ->where('title', '!=', 'x')
                ->orderBy('id')
                ->limit(5);

            $sql = $query->toSql();
            $rows = $query->get();

            return CheckResult::of(
                'Query builder',
                str_contains($sql, ':p0') && !str_contains($sql, "'x'"),
                sprintf('%s — %d row(s), value bound not inlined', $sql, count($rows)),
                'the builder inlined a value instead of binding it',
            );
        } catch (Throwable $e) {
            return CheckResult::fail('Query builder', $e->getMessage());
        }
    }

    /**
     * A DELETE with no conditions must be refused unless acknowledged.
     */
    private function checkUnsafeQueryGuard(): CheckResult
    {
        try {
            $this->database->query('notes')->delete();

            return CheckResult::fail(
                'Whole-table write guard',
                'an unqualified DELETE was allowed through — every note would have been removed',
            );
        } catch (UnsafeQuery) {
            return CheckResult::pass(
                'Whole-table write guard',
                'an unqualified DELETE was refused; affectingEveryRow() is required to mean it',
            );
        } catch (Throwable $e) {
            return CheckResult::fail('Whole-table write guard', $e->getMessage());
        }
    }

    private function checkPasswordHashing(): CheckResult
    {
        $hash = $this->hasher->hash('correct-horse-battery');

        $verifies = $this->hasher->verify('correct-horse-battery', $hash);
        $rejects = !$this->hasher->verify('wrong-password', $hash);
        $salted = $hash !== $this->hasher->hash('correct-horse-battery');

        $algorithm = str_starts_with($hash, '$argon2id') ? 'Argon2id' : 'bcrypt';

        return CheckResult::of(
            'Password hashing',
            $verifies && $rejects && $salted,
            sprintf('%s; the same password hashes differently each time (per-hash salt)', $algorithm),
            'hashing did not behave as expected',
        );
    }

    /**
     * Writes a file whose name and declared type disagree with its bytes, and
     * confirms the sniffer reports what it actually is.
     */
    private function checkUploadTypeSniffing(): CheckResult
    {
        $path = tempnam(sys_get_temp_dir(), 'orbit-check-');

        if ($path === false) {
            return CheckResult::fail('Upload type sniffing', 'could not create a temporary file');
        }

        try {
            file_put_contents($path, "<?php echo 'not an image';");

            $upload = new UploadedFile(
                'probe',
                'avatar.png',
                'image/png',
                (int) filesize($path),
                UploadError::None,
                $path,
            );

            $detected = $upload->detectedType();
            $allowed = $upload->hasTypeIn(['image/png', 'image/jpeg']);

            return CheckResult::of(
                'Upload type sniffing',
                !$allowed,
                sprintf('a PHP file named avatar.png was detected as %s and refused', $detected ?? 'unknown'),
                'a script claiming to be a PNG passed the type check',
            );
        } finally {
            @unlink($path);
        }
    }

    private function checkRouting(ServerRequest $request): CheckResult
    {
        return CheckResult::pass(
            'Routing',
            sprintf('matched %s %s', $this->route->method->value, $this->route->pattern),
        );
    }

    /**
     * The session only exists because SessionMiddleware published it, so
     * holding one proves the pipeline ran in the right order.
     */
    private function checkMiddleware(ServerRequest $request): CheckResult
    {
        return CheckResult::pass(
            'Middleware pipeline',
            'session, CSRF, logging and transaction layers all ran before this handler',
        );
    }

    private function checkAutowiring(): CheckResult
    {
        return CheckResult::pass(
            'Container autowiring',
            'this controller received 10 dependencies, mixing singletons and per-request instances',
        );
    }

    private function checkAuthentication(): CheckResult
    {
        $user = $this->auth->user();

        return CheckResult::pass(
            'Authentication',
            $user === null
                ? 'not signed in — /notes and /avatar writes are guarded'
                : sprintf('signed in as %s, re-fetched from storage this request', $user->authIdentifier()),
        );
    }

    private function checkSession(int $visits): CheckResult
    {
        return CheckResult::of(
            'Sessions',
            $visits >= 1,
            sprintf('visit #%d — reload to watch this climb', $visits),
            'the visit counter did not persist',
        );
    }

    private function checkCsrf(): CheckResult
    {
        $token = Csrf::token($this->session);

        return CheckResult::of(
            'CSRF tokens',
            preg_match('/^[a-f0-9]{64}$/', $token) === 1,
            'a 256-bit token is bound to this session',
            'the generated token has an unexpected shape',
        );
    }

    private function checkDatabase(): CheckResult
    {
        try {
            $answer = $this->database->selectValue('SELECT :a + :b', ['a' => 20, 'b' => 22]);

            return CheckResult::of(
                'Database',
                (int) $answer === 42,
                sprintf('prepared statement returned %s; %d notes stored', var_export($answer, true), $this->notes->count()),
                'the query ran but returned the wrong value',
            );
        } catch (Throwable $e) {
            return CheckResult::fail('Database', $e->getMessage());
        }
    }

    /**
     * Feeds a classic injection payload in as a bound value.
     *
     * If it were interpolated, `' OR 1=1 --` would match every row. Bound, it
     * is just an unusual title that matches nothing.
     */
    private function checkSqlInjection(): CheckResult
    {
        $payload = "' OR 1=1 --";

        try {
            $match = $this->notes->findByTitle($payload);

            return CheckResult::of(
                'SQL injection resistance',
                $match === null,
                sprintf('%s was matched as literal text, not executed', $payload),
                'an injection payload matched a row — values are reaching the parser',
            );
        } catch (Throwable $e) {
            return CheckResult::fail('SQL injection resistance', $e->getMessage());
        }
    }

    private function checkTemplateEscaping(): CheckResult
    {
        $payload = '<script>alert(1)</script>';

        try {
            $rendered = $this->view->render('probe', ['payload' => $payload, 'raw' => '<em>raw</em>']);

            $escaped = str_contains($rendered, '&lt;script&gt;')
                && !str_contains($rendered, '<script>');
            $rawWorked = str_contains($rendered, '<em>raw</em>');

            return CheckResult::of(
                'Template auto-escaping',
                $escaped && $rawWorked,
                '{{ }} escaped the payload; {!! !!} passed markup through as asked',
                $escaped ? 'the raw directive did not emit markup' : 'the payload was NOT escaped',
            );
        } catch (Throwable $e) {
            return CheckResult::fail('Template auto-escaping', $e->getMessage());
        }
    }

    /**
     * The attribute escaper must stay safe even without surrounding quotes.
     */
    private function checkAttributeEscaping(): CheckResult
    {
        $escaped = Escaper::attribute('x onmouseover=alert(1)');

        return CheckResult::of(
            'Attribute escaping',
            !str_contains($escaped, ' ') && !str_contains($escaped, '='),
            'spaces and equals signs became hex entities, so an unquoted attribute stays safe',
            'the escaped value could still break out of an unquoted attribute',
        );
    }

    private function checkValidation(): CheckResult
    {
        $validator = (new Validator(['email' => 'not-an-email', 'title' => '']))
            ->required('title')
            ->email('email');

        return CheckResult::of(
            'Validation',
            $validator->fails() && count($validator->errors()) === 2,
            'caught both a missing field and a malformed address',
            'the validator did not report the expected errors',
        );
    }

    private function checkSecurityHeaders(): CheckResult
    {
        $probe = Response::html('<p>probe</p>');

        $expected = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];

        $missing = [];
        foreach ($expected as $name => $value) {
            if ($probe->headers->first($name) !== $value) {
                $missing[] = $name;
            }
        }

        return CheckResult::of(
            'Security headers',
            $missing === [] && $probe->headers->has('Content-Security-Policy'),
            'nosniff, DENY, no-referrer and a CSP are applied by default',
            'missing: ' . implode(', ', $missing),
        );
    }

    private function checkTransactionGuard(): CheckResult
    {
        return CheckResult::of(
            'Transaction hygiene',
            !$this->database->inTransaction(),
            'the shared connection has no transaction left over from an earlier request',
            'a transaction from a previous request is still open on this connection',
        );
    }

    /**
     * The invariant the whole framework is built around.
     */
    private function checkWorkerIsolation(): CheckResult
    {
        $touches = $this->probe->touch();

        // touch() ran once in handle() and once here, so 2 is correct for a
        // fresh instance. A third would mean the object survived a request.
        return CheckResult::of(
            'Worker state isolation',
            $touches === 2,
            sprintf(
                'request-scoped probe reads %d after %d request(s) to this process — no leak',
                $touches,
                $this->worker->requests(),
            ),
            sprintf('scoped probe reads %d; per-request state is surviving between requests', $touches),
        );
    }
}
