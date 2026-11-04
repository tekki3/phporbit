<?php

declare(strict_types=1);

namespace PhpOrbit\Ai\Providers;

use PhpOrbit\Ai\Request;
use SensitiveParameter;

final class OpenAICompatible extends OpenAI
{
    public function endpoint(): string
    {
        return 'http://127.0.0.1:11434/v1/chat/completions';
    }

    public function body(#[SensitiveParameter] Request $request): array
    {
        $body = parent::body($request);
        unset($body['max_completion_tokens']);
        $body['max_tokens'] = $request->maxTokens;

        return $body;
    }
}
