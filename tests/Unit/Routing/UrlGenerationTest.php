<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Routing;

use PhpOrbit\Http\Response;
use PhpOrbit\Routing\RouteCollection;
use PhpOrbit\Routing\Router;
use PhpOrbit\Routing\UnknownRoute;
use PHPUnit\Framework\TestCase;

final class UrlGenerationTest extends TestCase
{
    public function test_it_builds_a_static_url(): void
    {
        self::assertSame('/about', $this->router()->urlFor('about'));
    }

    public function test_it_substitutes_parameters(): void
    {
        self::assertSame('/users/42/posts/hello', $this->router()->urlFor('post', [
            'id' => 42,
            'slug' => 'hello',
        ]));
    }

    /**
     * A value with a slash or a space would otherwise change the path's shape.
     */
    public function test_it_encodes_parameter_values(): void
    {
        self::assertSame('/users/1/posts/a%20b%2Fc', $this->router()->urlFor('post', [
            'id' => 1,
            'slug' => 'a b/c',
        ]));
    }

    public function test_it_strips_the_constraint_from_the_generated_path(): void
    {
        self::assertSame('/orders/7', $this->router()->urlFor('order', ['id' => 7]));
    }

    /**
     * A link that violates its own route's constraint would 404 for whoever
     * clicked it, so it fails where it is built instead.
     */
    public function test_a_value_violating_a_constraint_is_rejected(): void
    {
        $this->expectException(UnknownRoute::class);
        $this->expectExceptionMessageMatches('/do not satisfy/');

        $this->router()->urlFor('order', ['id' => 'abc']);
    }

    public function test_a_missing_parameter_is_reported(): void
    {
        $this->expectException(UnknownRoute::class);
        $this->expectExceptionMessageMatches('/needs a value for \{slug\}/');

        $this->router()->urlFor('post', ['id' => 1]);
    }

    public function test_an_unknown_name_lists_the_known_ones(): void
    {
        $this->expectException(UnknownRoute::class);
        $this->expectExceptionMessageMatches('/Known names: about, post, order/');

        $this->router()->urlFor('nope');
    }

    public function test_has_name_reports_availability(): void
    {
        self::assertTrue($this->router()->hasName('about'));
        self::assertFalse($this->router()->hasName('absent'));
    }

    private function router(): Router
    {
        return (new RouteCollection())
            ->get('/about', static fn (): Response => Response::text('about'), 'about')
            ->get('/users/{id}/posts/{slug}', static fn (): Response => Response::text('post'), 'post')
            ->get('/orders/{id:\d+}', static fn (): Response => Response::text('order'), 'order')
            ->compile();
    }
}
