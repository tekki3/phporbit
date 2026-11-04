<?php

declare(strict_types=1);

namespace PhpOrbit\Http;

use Closure;
use Generator;
use LogicException;

final class StreamBody
{
    private ?Generator $iterator = null;
    private bool $closed = false;

    /** @param Closure(): iterable<string> $produce */
    public function __construct(private readonly Closure $produce)
    {
    }

    /** @return Generator<int, string> */
    public function chunks(): Generator
    {
        if ($this->closed) {
            throw new LogicException('The response stream has already been closed.');
        }
        $produce = $this->produce;
        return $this->iterator ??= (static function () use ($produce): Generator {
            foreach ($produce() as $chunk) {
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        })();
    }

    public function prepared(): self
    {
        $this->chunks()->rewind();
        return $this;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->iterator = null;
    }
}
