<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Ai;

use Generator;
use InvalidArgumentException;
use LogicException;
use PhpOrbit\Ai\Agent;
use PhpOrbit\Ai\AiFailed;
use PhpOrbit\Ai\Provider;
use PhpOrbit\Ai\Providers\Anthropic;
use PhpOrbit\Ai\Providers\OpenAI;
use PhpOrbit\Ai\Providers\OpenAICompatible;
use PhpOrbit\Ai\Request;
use PhpOrbit\Ai\Secret;
use PhpOrbit\Ai\ServerEvents;
use PhpOrbit\Ai\Transport;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function test_plain_text_and_streaming_have_precise_types_and_immutable_configuration(): void
    {
        $transport = new class implements Transport {
            public int $calls = 0;
            public bool $closed = false;
            public ?Request $last = null;
            /** @var array<string, scalar|array<array-key, mixed>> */
            public array $body = [];

            public function request(Request $request, array $headers, array $body): iterable
            {
                $this->calls++;
                $this->closed = false;
                $this->last = $request;
                $this->body = $body;
                try {
                    if (!$request->streaming) {
                        yield '{"choices":[{"message":{"content":"Hello"}}]}';
                    } else {
                        foreach (str_split("data: {\"choices\":[{\"delta\":{\"content\":\"Hello \"}}]}\r\n\r\ndata: {\"choices\":[{\"delta\":{\"content\":\"world\"}}]}\n\ndata: [DONE]\n\n", 3) as $bytes) {
                            yield $bytes;
                        }
                    }
                } finally {
                    $this->closed = true;
                }
            }
        };
        $base = Agent::using(OpenAI::class)->withKey('synthetic-key')->model('test-model')->withTransport($transport);
        $first = $base->prompt('First prompt')->system('Brief answers')->maxTokens(25)->timeout(10);
        $second = $base->prompt('Second prompt');
        self::assertSame('Hello', $first->send());
        self::assertNotNull($transport->last);
        self::assertSame('First prompt', $transport->last->prompt);
        self::assertSame(25, $transport->body['max_completion_tokens']);
        self::assertSame('Hello', $second->send());
        self::assertSame('Second prompt', $transport->last->prompt);
        $calls = $transport->calls;
        $stream = $first->withStreaming()->send();
        self::assertInstanceOf(Generator::class, $stream);
        self::assertSame($calls, $transport->calls, 'Streaming is lazy.');
        self::assertSame(['Hello ', 'world'], iterator_to_array($stream));
        self::assertTrue($transport->closed);
        $partial = $first->stream();
        $partial->rewind();
        self::assertSame('Hello ', $partial->current());
        self::assertFalse($transport->closed);
        unset($partial);
        self::assertTrue($transport->closed, 'Abandoning a stream must release upstream resources.');
        self::assertStringNotContainsString('synthetic-key', print_r($first, true));
        self::assertStringNotContainsString('First prompt', print_r($first, true));
    }

    public function test_anthropic_uses_its_own_headers_and_protocol(): void
    {
        $provider = new Anthropic();
        $request = new Request(new Secret('synthetic'), 'test', 'Hello', 'Be concise', $provider->endpoint(), 45);
        self::assertSame('2023-06-01', $provider->headers($request)['anthropic-version']);
        self::assertSame('synthetic', $provider->headers($request)['x-api-key']);
        self::assertSame(45, $provider->body($request)['max_tokens']);
        self::assertSame('Be concise', $provider->body($request)['system']);
        self::assertSame('Hi there', $provider->text('{"content":[{"type":"text","text":"Hi "},{"type":"text","text":"there"}]}'));
        $events = "event: message_start\ndata: {\"type\":\"message_start\"}\n\n"
            . "event: ping\ndata: {\"type\":\"ping\"}\n\n"
            . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\n"
            . "data: {\"type\":\"message_stop\"}\n\n";
        self::assertSame(['Hi'], iterator_to_array($provider->chunks(str_split($events, 1))));
        $compatible = new OpenAICompatible();
        self::assertSame('http://127.0.0.1:11434/v1/chat/completions', $compatible->endpoint());
        self::assertArrayNotHasKey('max_completion_tokens', $compatible->body($request));
        self::assertSame(45, $compatible->body($request)['max_tokens']);
    }

    public function test_sse_supports_comments_multiline_data_and_all_line_endings(): void
    {
        $bytes = ": keepalive\rdata: one\rdata: two\r\rdata: three\r\n\r\n";
        self::assertSame(["one\ntwo", 'three'], iterator_to_array(ServerEvents::data(str_split($bytes, 1))));
    }

    public function test_malformed_truncated_error_and_non_text_responses_fail_explicitly(): void
    {
        foreach ([
            [new OpenAI(), '{"choices":[{"message":{"content":null}}]}', false],
            [new OpenAI(), '{"error":{"message":"private provider details"}}', false],
            [new OpenAI(), 'not-json', false],
            [new OpenAI(), '{"choices":[{"message":{"content":"","refusal":"private provider details"}}]}', false],
            [new OpenAI(), "data: {\"choices\":[{\"delta\":{\"tool_calls\":[]}}]}\n\ndata: [DONE]\n\n", true],
            [new OpenAI(), "data: {\"choices\":[{\"delta\":{\"refusal\":\"private provider details\"}}]}\n\n", true],
            [new OpenAI(), "data: {\"choices\":[{\"delta\":{\"content\":\"partial\"}}]}\n\n", true],
            [new OpenAI(), "data: {\"choices\":", true],
            [new Anthropic(), "data: {\"type\":\"error\",\"error\":{\"message\":\"private provider details\"}}\n\n", true],
            [new Anthropic(), "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":42}}\n\n", true],
            [new Anthropic(), '{"content":[]}', false],
            [new Anthropic(), '{"content":[{"type":"text","text":42}]}', false],
            [new Anthropic(), '{"content":[{"type":"text","text":"hi"},{"type":"tool_use"}]}', false],
            [new Anthropic(), "data: {\"type\":\"content_block_start\",\"content_block\":{\"type\":\"tool_use\"}}\n\n", true],
            [new Anthropic(), "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"input_json_delta\"}}\n\n", true],
        ] as [$provider, $data, $streaming]) {
            $this->assertInvalidResponse($provider, $data, $streaming);
        }
    }

    private function assertInvalidResponse(Provider $provider, string $data, bool $streaming): void
    {
        try {
            if ($streaming) {
                foreach ($provider->chunks([$data]) as $chunk) {
                    self::assertNotSame('', $chunk);
                }
            } else {
                $provider->text($data);
            }
            self::fail('Invalid response accepted.');
        } catch (AiFailed $error) {
            self::assertStringNotContainsString('private provider details', $error->getMessage());
        }
    }

    public function test_exact_request_limits_and_utf8_validation(): void
    {
        $key = new Secret('synthetic');
        $endpoint = (new OpenAI())->endpoint();
        new Request($key, 'model', str_repeat('a', 1048576), '', $endpoint, 1000000, 600);
        self::assertSame(1, (new Request($key, 'model', 'a', '', $endpoint, 1, 1))->maxTokens);
        foreach ([[0, 120], [1000001, 120], [1, 0], [1, 601]] as [$tokens, $timeout]) {
            try {
                new Request($key, 'model', 'a', '', $endpoint, $tokens, $timeout);
                self::fail('Invalid limits accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('token limit', $error->getMessage());
            }
        }
        foreach ([' ', "\xff", str_repeat('a', 1048577)] as $prompt) {
            try {
                new Request($key, 'model', $prompt, '', $endpoint);
                self::fail('Invalid prompt accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('UTF-8', $error->getMessage());
            }
        }
    }

    public function test_oversized_sse_events_are_rejected(): void
    {
        $this->expectException(AiFailed::class);
        iterator_to_array(ServerEvents::data(['data: ' . str_repeat('a', 1048576)]));
    }

    public function test_input_validation_and_key_redaction(): void
    {
        foreach (['', "key\r\nInjected: header", 'has space'] as $key) {
            try {
                new Secret($key);
                self::fail('Invalid key accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringNotContainsString('Injected', $error->getMessage());
            }
        }
        foreach (['http://example.com/v1', 'https://user:password@example.test', 'https://example.test/?key=secret', '/relative'] as $endpoint) {
            try {
                new Request(new Secret('test'), 'model', 'prompt', '', $endpoint);
                self::fail('Unsafe endpoint accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringNotContainsString('password', $error->getMessage());
            }
        }
        $this->expectException(LogicException::class);
        serialize(Agent::using(OpenAI::class)->withKey('synthetic-secret'));
    }

    public function test_unconfigured_agents_do_not_send_requests(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Agent::using(OpenAI::class)->prompt('Hello')->send();
    }

    public function test_provider_error_traces_redact_raw_response_arguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        try {
            (new OpenAI())->text('{"error":{"message":"synthetic confidential reply"}}');
            self::fail('Provider error accepted.');
        } catch (AiFailed $error) {
            self::assertStringNotContainsString('synthetic confidential reply', print_r($error->getTrace(), true));
            self::assertStringNotContainsString('synthetic confidential reply', $error->getTraceAsString());
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
}
