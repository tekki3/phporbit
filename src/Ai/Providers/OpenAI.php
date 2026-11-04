<?php

declare(strict_types=1);

namespace PhpOrbit\Ai\Providers;

use Generator;
use PhpOrbit\Ai\AiFailed;
use PhpOrbit\Ai\Json;
use PhpOrbit\Ai\Provider;
use PhpOrbit\Ai\Request;
use PhpOrbit\Ai\ServerEvents;
use SensitiveParameter;

class OpenAI implements Provider
{
    public function endpoint(): string
    {
        return 'https://api.openai.com/v1/chat/completions';
    }

    public function body(#[SensitiveParameter] Request $request): array
    {
        $messages = [];
        if ($request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }
        $messages[] = ['role' => 'user', 'content' => $request->prompt];

        return ['model' => $request->model, 'messages' => $messages,
            'max_completion_tokens' => $request->maxTokens, 'stream' => $request->streaming];
    }

    public function headers(#[SensitiveParameter] Request $request): array
    {
        return ['Authorization' => 'Bearer ' . $request->key->value()];
    }

    public function text(#[SensitiveParameter] string $json): string
    {
        $data = Json::object($json);
        $choices = $data['choices'] ?? null;
        $choice = is_array($choices) ? ($choices[0] ?? null) : null;
        $message = is_array($choice) ? ($choice['message'] ?? null) : null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;
        if (is_array($message) && (isset($message['tool_calls']) || isset($message['function_call']) || isset($message['refusal']))) {
            throw new AiFailed('AI provider returned an unsupported non-text completion.');
        }
        if (!is_string($content)) {
            throw new AiFailed('AI provider did not return a text completion.');
        }

        return $content;
    }

    /**
     * @param iterable<string> $bytes
     * @return Generator<int, string>
     */
    public function chunks(#[SensitiveParameter] iterable $bytes): Generator
    {
        foreach (ServerEvents::data($bytes) as $event) {
            if ($event === '[DONE]') {
                return;
            }
            $data = Json::object($event);
            $choices = $data['choices'] ?? null;
            if (!is_array($choices)) {
                throw new AiFailed('AI provider returned an invalid completion event.');
            }
            if ($choices === []) {
                continue; // Usage-only events carry no text.
            }
            $choice = $choices[0] ?? null;
            $delta = is_array($choice) ? ($choice['delta'] ?? null) : null;
            if (!is_array($delta)) {
                throw new AiFailed('AI provider returned an invalid text delta.');
            }
            if (isset($delta['tool_calls']) || isset($delta['function_call']) || isset($delta['refusal'])) {
                throw new AiFailed('AI provider returned an unsupported non-text completion.');
            }
            if (isset($delta['content'])) {
                if (!is_string($delta['content'])) {
                    throw new AiFailed('AI provider returned a non-text delta.');
                }
                if ($delta['content'] !== '') {
                    yield $delta['content'];
                }
            }
        }
        throw new AiFailed('AI stream ended without its completion marker.');
    }
}
