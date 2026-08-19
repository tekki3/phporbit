<?php

declare(strict_types=1);

namespace PhpOrbit\Routing;

enum Outcome
{
    case Found;
    case NotFound;
    case MethodNotAllowed;
}
