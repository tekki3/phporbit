<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Auth;

use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Database\Connection;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = Connection::sqlite(':memory:');
        $this->db->executeSchema(sprintf(
            'CREATE TABLE %s (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_key TEXT NOT NULL,
                attempted_at INTEGER NOT NULL
            )',
            LoginThrottle::TABLE,
        ));
    }

    public function test_it_permits_attempts_below_the_limit(): void
    {
        $throttle = new LoginThrottle($this->db, maxAttempts: 3);

        $throttle->record('ada@example.test|1.2.3.4');
        $throttle->record('ada@example.test|1.2.3.4');

        self::assertFalse($throttle->tooManyAttempts('ada@example.test|1.2.3.4'));
    }

    public function test_it_blocks_once_the_limit_is_reached(): void
    {
        $throttle = new LoginThrottle($this->db, maxAttempts: 3);

        for ($i = 0; $i < 3; $i++) {
            $throttle->record('ada@example.test|1.2.3.4');
        }

        self::assertTrue($throttle->tooManyAttempts('ada@example.test|1.2.3.4'));
        self::assertGreaterThan(0, $throttle->retryAfter('ada@example.test|1.2.3.4'));
    }

    /**
     * Keyed on email *and* address together: keying on either alone gives an
     * attacker a way to lock out an account, or a way to spread guesses.
     */
    public function test_attempts_are_scoped_to_the_exact_key(): void
    {
        $throttle = new LoginThrottle($this->db, maxAttempts: 2);

        $throttle->record('ada@example.test|1.2.3.4');
        $throttle->record('ada@example.test|1.2.3.4');

        self::assertTrue($throttle->tooManyAttempts('ada@example.test|1.2.3.4'));
        self::assertFalse(
            $throttle->tooManyAttempts('ada@example.test|5.6.7.8'),
            'a different address must not inherit the block',
        );
    }

    public function test_a_successful_login_clears_the_record(): void
    {
        $throttle = new LoginThrottle($this->db, maxAttempts: 2);

        $throttle->record('ada@example.test|1.2.3.4');
        $throttle->record('ada@example.test|1.2.3.4');
        $throttle->clear('ada@example.test|1.2.3.4');

        self::assertFalse($throttle->tooManyAttempts('ada@example.test|1.2.3.4'));
    }

    public function test_attempts_outside_the_window_do_not_count(): void
    {
        $throttle = new LoginThrottle($this->db, maxAttempts: 2, windowSeconds: 60);

        // Two attempts recorded two minutes ago, written directly so the test
        // does not have to wait.
        $this->db->query(LoginThrottle::TABLE)->insert([
            'attempt_key' => hash('sha256', 'ada@example.test|1.2.3.4'),
            'attempted_at' => time() - 120,
        ]);
        $this->db->query(LoginThrottle::TABLE)->insert([
            'attempt_key' => hash('sha256', 'ada@example.test|1.2.3.4'),
            'attempted_at' => time() - 120,
        ]);

        self::assertFalse($throttle->tooManyAttempts('ada@example.test|1.2.3.4'));
        self::assertSame(2, $throttle->purge());
    }

    /**
     * This table exists to slow attackers down, not to become a record of who
     * tried to sign in from where.
     */
    public function test_the_key_is_stored_hashed(): void
    {
        (new LoginThrottle($this->db))->record('ada@example.test|1.2.3.4');

        $stored = (string) $this->db->query(LoginThrottle::TABLE)->value('attempt_key');

        self::assertStringNotContainsString('ada@example.test', $stored);
        self::assertStringNotContainsString('1.2.3.4', $stored);
        self::assertSame(hash('sha256', 'ada@example.test|1.2.3.4'), $stored);
    }
}
