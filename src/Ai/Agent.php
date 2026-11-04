<?php

declare(strict_types=1);

namespace PhpOrbit\Ai;

use Generator;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final class Agent
{
    private ?Secret $key = null;
    private string $modelName = '';
    private string $promptText = '';
    private string $systemText = '';
    private ?string $url = null;
    private int $tokens = 1024;
    private int $timeout = 120;

    private function __construct(
        private readonly Provider $provider,
        private Transport $transport = new CurlTransport(),
    ) {
    }

    /** @param class-string<Provider>|Provider $provider */
    public static function using(string|Provider $provider): self
    {
        return new self(is_string($provider) ? new $provider() : $provider);
    }

    public function withKey(#[SensitiveParameter] string $key): self
    {
        $agent = clone $this;
        $agent->key = new Secret($key);
        return $agent;
    }

    public function model(string $model): self
    {
        $agent = clone $this;
        $agent->modelName = $model;
        return $agent;
    }

    public function prompt(#[SensitiveParameter] string $prompt): self
    {
        $agent = clone $this;
        $agent->promptText = $prompt;
        return $agent;
    }

    public function system(#[SensitiveParameter] string $instructions): self
    {
        $agent = clone $this;
        $agent->systemText = $instructions;
        return $agent;
    }

    public function endpoint(string $url): self
    {
        $agent = clone $this;
        $agent->url = $url;
        return $agent;
    }

    public function maxTokens(int $tokens): self
    {
        $agent = clone $this;
        $agent->tokens = $tokens;
        return $agent;
    }

    public function timeout(int $seconds): self
    {
        $agent = clone $this;
        $agent->timeout = $seconds;
        return $agent;
    }

    public function withTransport(Transport $transport): self
    {
        $agent = clone $this;
        $agent->transport = $transport;
        return $agent;
    }

    public function withStreaming(): StreamingAgent
    {
        return new StreamingAgent($this);
    }

    public function send(): string
    {
        $request = $this->request(false);
        $body = '';
        foreach ($this->transport->request($request, $this->provider->headers($request), $this->provider->body($request)) as $chunk) {
            $body .= $chunk;
            if (strlen($body) > 16777216) {
                throw new AiFailed('AI response exceeded its size limit.');
            }
        }
        return $this->provider->text($body);
    }

    /** @return Generator<int, string> */
    public function stream(): Generator
    {
        $request = $this->request(true);
        foreach ($this->provider->chunks($this->transport->request(
            $request, $this->provider->headers($request), $this->provider->body($request),
        )) as $chunk) {
            yield $chunk;
        }
    }

    private function request(bool $streaming): Request
    {
        return new Request(
            $this->key ?? throw new InvalidArgumentException('Set an AI API key with withKey().'),
            $this->modelName, $this->promptText, $this->systemText,
            $this->url ?? $this->provider->endpoint(), $this->tokens, $this->timeout, $streaming,
        );
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['provider' => $this->provider::class, 'model' => $this->modelName, 'request' => '[redacted]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('AI agents cannot be serialized.');
    }
}
