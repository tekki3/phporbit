<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Session;

use PhpOrbit\Session\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function test_a_new_session_has_a_valid_id(): void
    {
        $session = Session::started();

        self::assertTrue(Session::isValidId($session->id()));
        self::assertSame(64, strlen($session->id()));
        self::assertTrue($session->isNew());
        self::assertFalse($session->isDirty());
    }

    public function test_ids_are_unpredictable(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = Session::generateId();
        }

        self::assertCount(50, array_unique($ids));
    }

    public function test_writing_marks_it_dirty(): void
    {
        $session = Session::started();
        $session->set('user', 'ada');

        self::assertTrue($session->isDirty());
        self::assertSame('ada', $session->get('user'));
    }

    /**
     * Writing the same value should not force a store write on every request.
     */
    public function test_writing_an_identical_value_does_not_dirty_it(): void
    {
        $session = new Session(Session::generateId(), ['user' => 'ada'], isNew: false);
        $session->set('user', 'ada');

        self::assertFalse($session->isDirty());
    }

    public function test_typed_getters_narrow_values(): void
    {
        $session = new Session(Session::generateId(), ['n' => 42, 'flag' => true, 's' => 'x']);

        self::assertSame(42, $session->getInt('n'));
        self::assertTrue($session->getBool('flag'));
        self::assertSame('x', $session->get('s'));
        self::assertNull($session->getInt('s'));
        self::assertNull($session->get('absent'));
    }

    public function test_flash_values_are_read_once(): void
    {
        $session = Session::started();
        $session->flash('notice', 'saved');

        self::assertSame('saved', $session->takeFlash('notice'));
        self::assertNull($session->takeFlash('notice'));
    }

    /**
     * Session fixation defence: the id must change while the data survives.
     */
    public function test_regenerate_changes_the_id_but_keeps_the_data(): void
    {
        $session = new Session(Session::generateId(), ['user' => 'ada'], isNew: false);
        $original = $session->id();

        $previous = $session->regenerate();

        self::assertSame($original, $previous);
        self::assertNotSame($original, $session->id());
        self::assertTrue(Session::isValidId($session->id()));
        self::assertSame('ada', $session->get('user'));
    }

    public function test_destroy_empties_and_marks_it(): void
    {
        $session = new Session(Session::generateId(), ['user' => 'ada'], isNew: false);
        $session->destroy();

        self::assertTrue($session->isDestroyed());
        self::assertNull($session->get('user'));
    }

    public function test_it_rejects_malformed_ids(): void
    {
        self::assertFalse(Session::isValidId('../../etc/passwd'));
        self::assertFalse(Session::isValidId(str_repeat('z', 64)));
        self::assertFalse(Session::isValidId(str_repeat('a', 63)));
        self::assertTrue(Session::isValidId(str_repeat('a', 64)));
    }
}
