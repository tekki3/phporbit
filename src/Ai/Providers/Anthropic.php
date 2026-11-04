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

final class Anthropic implements Provider
{
    public function endpoint(): string
    {
        return 'https://api.anthropic.com/v1/messages';
    }

    public function headers(#[SensitiveParameter] Request $request): array
    {
        return ['x-api-key' => $request->key->value(), 'anthropic-version' => '2023-06-01'];
    }

    public function body(#[SensitiveParameter] Request $request): array
    {
        $body = ['model' => $request->model, 'max_tokens' => $request->maxTokens,
            'messages' => [['role' => 'user', 'content' => $request->prompt]], 'stream' => $request->streaming];
        if ($request->system !== '') {
            $body['system'] = $request->system;
        }
        return $body;
    }

    public function text(#[SensitiveParameter] string $json): string
    {
        $data = Json::object($json);
        $blocks = $data['content'] ?? null;
        if (!is_array($blocks)) {
            throw new AiFailed('AI provider did not return message content.');
        }
        $text = '';
        $found = false;
        foreach ($blocks as $block) {
            if (!is_array($block) || !in_array($block['type'] ?? null, ['text', 'thinking', 'redacted_thinking'], true)) {
                throw new AiFailed('AI provider returned unsupported message content.');
            }
            if ($block['type'] === 'text') {
                if (!is_string($block['text'] ?? null)) {
                    throw new AiFailed('AI provider returned invalid text content.');
                }
                $text .= $block['text'];
                $found = true;
            }
        }
        if (!$found) {
            throw new AiFailed('AI provider did not return text.');
        }
        return $text;
    }

    /**
     * @param iterable<string> $bytes
     * @return Generator<int, string>
     */
    public function chunks(#[SensitiveParameter] iterable $bytes): Generator
    {
        foreach (ServerEvents::data($bytes) as $event) {
            $data = Json::object($event);
            $type = $data['type'] ?? null;
            if ($type === 'message_stop') {
                return;
            }
            if ($type === 'content_block_start') {
                $block = $data['content_block'] ?? null;
                if (!is_array($block) || !in_array($block['type'] ?? null, ['text', 'thinking', 'redacted_thinking'], true)) {
                    throw new AiFailed('AI provider returned an unsupported content block.');
                }
            }
            if ($type === 'content_block_delta') {
                $delta = $data['delta'] ?? null;
                if (!is_array($delta)) {
                    throw new AiFailed('AI provider returned an invalid content delta.');
                }
                if (($delta['type'] ?? null) === 'text_delta') {
                    $text = $delta['text'] ?? null;
                    if (!is_string($text)) {
                        throw new AiFailed('AI provider returned an invalid text delta.');
                    }
                    if ($text !== '') {
                        yield $text;
                    }
                } elseif (!in_array($delta['type'] ?? null, ['thinking_delta', 'signature_delta'], true)) {
                    throw new AiFailed('AI provider returned an unsupported non-text delta.');
                }
            } elseif (!in_array($type, ['message_start', 'content_block_start', 'content_block_stop', 'message_delta', 'ping'], true)) {
                throw new AiFailed('AI provider returned an unknown stream event.');
            }
        }
        throw new AiFailed('AI stream ended without its completion marker.');
    }
}
