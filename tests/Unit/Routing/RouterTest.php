<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Routing;

use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Routing\Exception\InvalidRoutePattern;
use PhpOrbit\Routing\Outcome;
use PhpOrbit\Routing\RouteCollection;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_it_matches_a_static_route(): void
    {
        $router = (new RouteCollection())
            ->get('/about', static fn (): Response => Response::text('about'))
            ->compile();

        $result = $router->match(Requests::get('/about'));

        self::assertSame(Outcome::Found, $result->outcome);
    }

    public function test_it_captures_parameters(): void
    {
        $router = (new RouteCollection())
            ->get('/users/{id}/posts/{slug}', static fn (): Response => Response::text('ok'))
            ->compile();

        $result = $router->match(Requests::get('/users/42/posts/hello-world'));

        self::assertSame(Outcome::Found, $result->outcome);
        self::assertSame(['id' => '42', 'slug' => 'hello-world'], $result->parameters);
    }

    public function test_a_parameter_does_not_span_path_segments(): void
    {
        $router = (new RouteCollection())
            ->get('/files/{name}', static fn (): Response => Response::text('ok'))
            ->compile();

        self::assertSame(Outcome::NotFound, $router->match(Requests::get('/files/a/b'))->outcome);
    }

    public function test_it_honours_a_parameter_constraint(): void
    {
        $router = (new RouteCollection())
            ->get('/orders/{id:\d+}', static fn (): Response => Response::text('ok'))
            ->compile();

        self::assertSame(Outcome::Found, $router->match(Requests::get('/orders/123'))->outcome);
        self::assertSame(Outcome::NotFound, $router->match(Requests::get('/orders/abc'))->outcome);
    }

    public function test_it_reports_method_not_allowed_with_the_allowed_set(): void
    {
        $router = (new RouteCollection())
            ->get('/thing', static fn (): Response => Response::text('ok'))
            ->delete('/thing', static fn (): Response => Response::noContent())
            ->compile();

        $result = $router->match(Requests::post('/thing'));

        self::assertSame(Outcome::MethodNotAllowed, $result->outcome);
        self::assertEqualsCanonicalizing([Method::Get, Method::Delete], $result->allowedMethods);
    }

    public function test_trailing_slashes_address_the_same_route(): void
    {
        $router = (new RouteCollection())
            ->get('/docs/', static fn (): Response => Response::text('ok'))
            ->compile();

        self::assertSame(Outcome::Found, $router->match(Requests::get('/docs'))->outcome);
        self::assertSame(Outcome::Found, $router->match(Requests::get('/docs/'))->outcome);
    }

    public function test_head_is_served_by_the_get_route(): void
    {
        $router = (new RouteCollection())
            ->get('/page', static fn (): Response => Response::text('body'))
            ->compile();

        $result = $router->match(Requests::of(Method::Head, '/page'));

        self::assertSame(Outcome::Found, $result->outcome);
    }

    public function test_groups_prefix_their_routes(): void
    {
        $router = (new RouteCollection())
            ->group('/api', static function (RouteCollection $routes): void {
                $routes->get('/users', static fn (): Response => Response::text('users'));

                $routes->group('/v2', static function (RouteCollection $nested): void {
                    $nested->get('/items', static fn (): Response => Response::text('items'));
                });
            })
            ->compile();

        self::assertSame(Outcome::Found, $router->match(Requests::get('/api/users'))->outcome);
        self::assertSame(Outcome::Found, $router->match(Requests::get('/api/v2/items'))->outcome);
    }

    public function test_a_group_prefix_does_not_leak_past_the_callback(): void
    {
        $router = (new RouteCollection())
            ->group('/admin', static function (RouteCollection $routes): void {
                $routes->get('/panel', static fn (): Response => Response::text('panel'));
            })
            ->get('/public', static fn (): Response => Response::text('public'))
            ->compile();

        self::assertSame(Outcome::Found, $router->match(Requests::get('/public'))->outcome);
        self::assertSame(Outcome::NotFound, $router->match(Requests::get('/admin/public'))->outcome);
    }

    public function test_a_bad_pattern_fails_at_boot(): void
    {
        $this->expectException(InvalidRoutePattern::class);

        (new RouteCollection())->get('users', static fn (): Response => Response::text('ok'));
    }

    public function test_a_duplicated_parameter_name_fails_at_boot(): void
    {
        $this->expectException(InvalidRoutePattern::class);

        (new RouteCollection())->get('/{id}/{id}', static fn (): Response => Response::text('ok'));
    }

    public function test_a_controller_class_is_accepted_as_a_handler(): void
    {
        $router = (new RouteCollection())
            ->get('/ctrl', GreetingRoute::class)
            ->compile();

        self::assertSame(Outcome::Found, $router->match(Requests::get('/ctrl'))->outcome);
    }

    public function test_a_duplicated_route_name_fails_at_boot(): void
    {
        $routes = (new RouteCollection())
            ->get('/one', static fn (): Response => Response::text('one'), 'home');

        $this->expectException(InvalidRoutePattern::class);

        $routes->get('/two', static fn (): Response => Response::text('two'), 'home');
    }
}
