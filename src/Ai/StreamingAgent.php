<?php

declare(strict_types=1);

namespace PhpOrbit\Ai;

use Generator;
use PhpOrbit\Http\Response;

final class StreamingAgent
{
    public function __construct(private readonly Agent $agent)
    {
    }

    /** @return Generator<int, string> */
    public function send(): Generator
    {
        return $this->agent->stream();
    }

    public function response(): Response
    {
        return Response::stream($this->send(...));
    }
}
