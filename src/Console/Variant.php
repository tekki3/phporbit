<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use ValueError;

/**
 * Which shape of project `orbit new` should write.
 */
enum Variant: string
{
    /** The smallest application that boots and serves a page. */
    case Blank = 'blank';

    /** This repository's own demo: auth, uploads, notes and the self-check. */
    case Demo = 'demo';

    public static function fromName(string $name): self
    {
        $normalised = strtolower(trim($name));

        $normalised = match ($normalised) {
            'empty', 'minimal', 'bare' => 'blank',
            'example', 'test', 'testsite' => 'demo',
            default => $normalised,
        };

        return self::tryFrom($normalised) ?? throw new ValueError(sprintf(
            'Unknown project variant "%s". Use "blank" or "demo".',
            $name,
        ));
    }

    public function describe(): string
    {
        return match ($this) {
            self::Blank => 'a blank application — one route, one controller, one template',
            self::Demo => 'the demo application — auth, uploads, notes and the live self-check',
        };
    }
}
