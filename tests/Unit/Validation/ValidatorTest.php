<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Validation;

use PhpOrbit\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function test_it_passes_valid_input(): void
    {
        $validator = (new Validator(['title' => 'Hello', 'email' => 'a@b.test']))
            ->required('title')
            ->maxLength('title', 10)
            ->email('email');

        self::assertTrue($validator->passes());
        self::assertSame([], $validator->errors());
    }

    public function test_required_catches_missing_and_blank(): void
    {
        $validator = (new Validator(['blank' => '   ']))
            ->required('missing')
            ->required('blank');

        self::assertCount(2, $validator->errors());
    }

    public function test_values_are_trimmed(): void
    {
        self::assertSame('hello', (new Validator(['a' => '  hello  ']))->value('a'));
    }

    /**
     * Reporting one problem per field at a time keeps forms usable.
     */
    public function test_only_the_first_failure_per_field_is_reported(): void
    {
        $validator = (new Validator(['title' => '']))
            ->required('title')
            ->minLength('title', 5);

        self::assertCount(1, $validator->errors());
        self::assertStringContainsString('required', (string) $validator->error('title'));
    }

    /**
     * Length rules apply to characters, not bytes — otherwise a limit means
     * something different depending on the alphabet used.
     */
    public function test_length_is_measured_in_characters(): void
    {
        $validator = (new Validator(['title' => 'héllo']))->maxLength('title', 5);

        self::assertTrue($validator->passes());
    }

    public function test_optional_fields_skip_format_rules(): void
    {
        $validator = (new Validator([]))
            ->email('email')
            ->integer('count')
            ->minLength('name', 3);

        self::assertTrue($validator->passes(), 'absent fields are only an error when required');
    }

    public function test_integer_rejects_non_numeric_input(): void
    {
        self::assertTrue((new Validator(['n' => '12x']))->integer('n')->fails());
        self::assertTrue((new Validator(['n' => '-12']))->integer('n')->passes());
    }

    public function test_in_restricts_to_a_permitted_set(): void
    {
        self::assertTrue((new Validator(['role' => 'root']))->in('role', ['user', 'admin'])->fails());
        self::assertTrue((new Validator(['role' => 'admin']))->in('role', ['user', 'admin'])->passes());
    }
}
