<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Session;

use PhpOrbit\Session\FileSessionStore;
use PhpOrbit\Session\Session;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileSessionStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/orbit-sessions-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function test_it_round_trips_a_session(): void
    {
        $store = new FileSessionStore($this->directory);
        $id = Session::generateId();

        $store->write($id, ['user' => 'ada', 'count' => 3], 3600);

        self::assertSame(['user' => 'ada', 'count' => 3], $store->read($id));
    }

    public function test_reading_an_unknown_session_returns_null(): void
    {
        self::assertNull((new FileSessionStore($this->directory))->read(Session::generateId()));
    }

    public function test_an_expired_session_is_not_returned(): void
    {
        $store = new FileSessionStore($this->directory);
        $id = Session::generateId();

        $store->write($id, ['user' => 'ada'], -1);

        self::assertNull($store->read($id));
    }

    public function test_destroy_removes_it(): void
    {
        $store = new FileSessionStore($this->directory);
        $id = Session::generateId();

        $store->write($id, ['user' => 'ada'], 3600);
        $store->destroy($id);

        self::assertNull($store->read($id));
    }

    public function test_garbage_collection_removes_only_expired_sessions(): void
    {
        $store = new FileSessionStore($this->directory);

        $live = Session::generateId();
        $dead = Session::generateId();

        $store->write($live, ['a' => 1], 3600);
        $store->write($dead, ['a' => 1], -1);

        self::assertSame(1, $store->collectGarbage());
        self::assertNotNull($store->read($live));
    }

    /**
     * A session id from a cookie is attacker-controlled, so it must never be
     * concatenated into a path without validation.
     */
    public function test_it_refuses_a_traversal_id(): void
    {
        $store = new FileSessionStore($this->directory);

        $this->expectException(RuntimeException::class);

        $store->write('../../../tmp/pwned', ['a' => 1], 3600);
    }

    public function test_a_corrupt_file_reads_as_no_session(): void
    {
        $store = new FileSessionStore($this->directory);
        $id = Session::generateId();

        file_put_contents($this->directory . '/sess_' . $id, 'not json at all');

        self::assertNull($store->read($id));
    }

    /**
     * Session files are bearer credentials at rest.
     */
    public function test_session_files_are_not_world_readable(): void
    {
        $store = new FileSessionStore($this->directory);
        $id = Session::generateId();

        $store->write($id, ['a' => 1], 3600);

        $permissions = fileperms($this->directory . '/sess_' . $id);

        self::assertNotFalse($permissions);
        self::assertSame(0, $permissions & 0o077, 'group and other must have no access');
    }
}
