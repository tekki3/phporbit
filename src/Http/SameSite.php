<?php

declare(strict_types=1);

namespace PhpOrbit\Http;

enum SameSite: string
{
    case Strict = 'Strict';
    case Lax = 'Lax';
    case None = 'None';
}
