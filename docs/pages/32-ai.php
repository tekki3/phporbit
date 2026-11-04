<?php

declare(strict_types=1);

return [
    'slug' => 'ai',
    'title' => 'AI agents',
    'summary' => 'Fluent text generation with OpenAI, Anthropic and compatible local providers, including real HTTP streaming.',
    'body' => <<<'HTML'
<p><code>PhpOrbit\Ai\Agent</code> provides a fluent, immutable builder for text generation. OpenAI, Anthropic and OpenAI-compatible services share one interface. Each call owns its HTTP connection; prompts, credentials and conversation state are never cached globally. This is a text-generation API, not an autonomous tool-running agent.</p>

<h2>Configuration</h2>
<p>Enable <code>ext-curl</code> and configure a provider key in your real environment or <code>.env</code>. Configuration is read at boot through <code>Environment</code>, not a global <code>env()</code> helper. Keep keys on the server. The framework does not contact a provider automatically.</p>
[[ini]]
OPENAI_API_KEY=your-provider-key
# Only if you use Anthropic:
ANTHROPIC_API_KEY=your-provider-key
[[/ini]]
<p>A live call can incur costs and sends your prompt to the configured provider. Do not send secrets or personal data without an appropriate data-handling policy and consent. Only synthetic, local provider responses are used by the framework tests; live model access and billing have not been verified here.</p>

<h2>One answer</h2>
[[php]]
<?php
use PhpOrbit\Ai\Agent;
use PhpOrbit\Ai\Providers\OpenAI;
use PhpOrbit\Config\Environment;

// $env is the injected Environment, loaded once at application boot.
$answer = Agent::using(OpenAI::class)
    ->withKey($env->required('OPENAI_API_KEY'))
    ->model('gpt-4')
    ->system('Be concise and distinguish verified facts from assumptions.')
    ->prompt('Explain the benefits of a worker-safe PHP framework.')
    ->maxTokens(1024)
    ->timeout(120)
    ->send();
// $answer is a string.
[[/php]]
<p>The model is explicit, not a framework default. Choose a model available to your provider account. OpenAI uses <code>/v1/chat/completions</code>, with <code>max_completion_tokens</code>; this supports the example's familiar chat interface, not every capability of OpenAI's Responses API. Parameter/model compatibility is the provider's responsibility.</p>

<h2>Streaming in the CLI</h2>
[[php]]
<?php
use PhpOrbit\Ai\Agent;
use PhpOrbit\Ai\Providers\OpenAI;

$stream = Agent::using(OpenAI::class)
    ->withKey($env->required('OPENAI_API_KEY'))
    ->model('gpt-4')
    ->prompt('Explain worker-safe request handling in PHP.')
    ->withStreaming()
    ->send();

foreach ($stream as $chunk) {
    echo $chunk;
    flush();
}
[[/php]]
<p><code>withStreaming()</code> returns a <code>StreamingAgent</code> whose <code>send()</code> returns a <code>Generator&lt;int, string&gt;</code>. It does not issue a request until iteration starts. Chunks contain text only, not raw SSE/JSON or provider metadata. Chunks are arbitrary text fragments, not complete words or lines. Configuration methods belong before <code>withStreaming()</code>. You can also use <code>$agent-&gt;stream()</code> directly.</p>
<p>If iteration is abandoned, release the generator (for example <code>unset($stream)</code>) so its <code>finally</code> closes the upstream connection promptly. There is no automatic retry: retrying a partial generation could repeat text and incur another charge.</p>

<h2>Live HTTP streaming</h2>
<p>Inside a controller, return a response rather than echoing or calling <code>flush()</code>. The adapters own the output layer:</p>
[[php]]
<?php
namespace App\Controllers;

use PhpOrbit\Ai\Agent;
use PhpOrbit\Ai\Providers\OpenAI;
use PhpOrbit\Config\Environment;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class ExplainController implements Handler
{
    public function __construct(private readonly Environment $env) {}

    public function handle(ServerRequest $request): Response
    {
        return Agent::using(OpenAI::class)
            ->withKey($this->env->required('OPENAI_API_KEY'))
            ->model('gpt-4')
            ->prompt('Explain worker-safe PHP request handling.')
            ->withStreaming()
            ->response();
    }
}
[[/php]]
<p>This is an incremental <code>text/plain; charset=utf-8</code> response. It sets <code>Cache-Control: no-store</code> and <code>X-Accel-Buffering: no</code>. The own server uses HTTP/1.1 chunk framing; FPM/Apache/FrankenPHP flush PHP's output layer. Browser clients can consume a fetch response's readable body. This is not browser <code>EventSource</code> framing; use a custom <code>Response::stream()</code> producer if you need SSE.</p>
<p>The first text fragment is obtained before committing response headers. An initial failure becomes an ordinary 500; failures after output starts are logged by exception class and terminate the stream. A partial response cannot retroactively become a JSON error or change its HTTP status. Never treat an incomplete stream as a completed answer.</p>
<p>The kernel keeps the request scope and temporary uploads alive through emission and closes the scope in <code>finally</code>, including when a client disconnects or an upstream fails. Complete authentication, cookies and session changes in the handler before returning the response. Session writes during lazy generation throw rather than being silently lost. Do not open a transaction across a long AI call; <code>TransactionGuard</code> also cleans transactions opened during stream iteration.</p>
<p>Configure your reverse proxy/CDN not to buffer this route, disable compression/output handlers that buffer, and allow suitable request timeouts. The web emitter supports at most one flushable PHP output buffer; nested/nonflushable buffers fail explicitly instead of silently buffering a stream. PHP/web-server configuration can still buffer despite application flush calls. Orbit's development server is sequential: one long generation occupies it until completion. Production streaming also occupies a worker. Authenticate and rate-limit any publicly accessible AI endpoint and enforce your own budget policy.</p>
<p>Request-logging middleware measures handler/pipeline time, not the full duration of stream consumption. The kernel logs later emission failures separately. Stream responses and their iterators are single-use; an emitter must consume synchronously, not save them for use after the scope closes.</p>

<h2>Anthropic</h2>
[[php]]
<?php
use PhpOrbit\Ai\Agent;
use PhpOrbit\Ai\Providers\Anthropic;

$stream = Agent::using(Anthropic::class)
    ->withKey($env->required('ANTHROPIC_API_KEY'))
    ->model('claude-sonnet-4-5')
    ->system('Answer clearly.')
    ->prompt('Explain request isolation.')
    ->maxTokens(1024)
    ->withStreaming()
    ->send();
[[/php]]
<p>Anthropic uses its Messages API, <code>x-api-key</code>, the pinned <code>anthropic-version: 2023-06-01</code> contract and required <code>max_tokens</code>. The adapter extracts text blocks and text deltas. Thinking/signature deltas are not exposed as answer text. Tool calling, images, audio, embeddings, structured-output schemas, usage accounting and conversation persistence are not part of this API.</p>

<h2>Compatible and local providers</h2>
[[php]]
<?php
use PhpOrbit\Ai\Agent;
use PhpOrbit\Ai\Providers\OpenAICompatible;

$answer = Agent::using(OpenAICompatible::class)
    ->withKey('local') // Placeholder only for a local server that ignores auth.
    ->endpoint('http://127.0.0.1:11434/v1/chat/completions')
    ->model('llama3.2')
    ->prompt('Explain dependency injection.')
    ->send();
[[/php]]
<p><code>OpenAICompatible</code> uses the OpenAI chat/SSE shape and <code>max_tokens</code>. Its default endpoint is the loopback URL above. <code>endpoint()</code> takes the complete request URL, not a base URL. Remote endpoints require HTTPS with peer/host verification; plain HTTP is permitted only for literal loopback hosts. Redirects are disabled, and URLs must not contain credentials, query strings or fragments. Endpoint configuration is operator-controlled: never accept an arbitrary endpoint from user input.</p>

<h2>Errors, limits and test doubles</h2>
<p>Catch <code>AiFailed</code> for transport, HTTP, provider-response, truncation or event-size failures. Exception messages never contain provider response bodies, keys or prompts. Missing/invalid builder inputs throw <code>InvalidArgumentException</code>. A key is validated without silently falling back to another provider or environment variable. Agent/request/credential debug output is redacted and serialization is refused.</p>
<p>Default limits: 1 MiB for prompt plus system text, 16 MiB total provider response, 1 MiB per pending transport buffer/SSE event, 1024 output tokens and a 120-second timeout (configurable from 1 to 600 seconds). Each provider request uses a fresh connection. No retries, background jobs or provider calls at boot are added.</p>
<p><code>Agent::using()</code> accepts a <code>Provider</code> class or instance. The interface supplies the endpoint, request body/headers and text/SSE decoding. <code>withTransport(Transport $transport)</code> supports isolated tests; a transport returns raw byte fragments and must enforce HTTP status, media type, size and timeout rules. Framework providers and builders are stateless/immutable. Custom providers/transports must not retain per-user request data when registered as singletons.</p>
HTML,
];
