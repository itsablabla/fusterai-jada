<?php

namespace App\Providers;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Tolerance shim for OpenAI-compatible gateways (LiteLLM, 9router, OpenRouter, Ollama…)
 * whose responses omit fields the official API always returns. Prism's value objects
 * are strict, so a missing `model` on /responses or /chat/completions throws a TypeError.
 *
 * Also strips a stray trailing "data: [DONE]" that some gateways append to
 * non-streaming JSON bodies.
 */
class OpenAiCompatShimProvider extends ServiceProvider
{
    /** Model name from the most recent outgoing AI request (PHP is single-threaded per request/worker). */
    private static ?string $lastModel = null;

    public function boot(): void
    {
        if (! config('ai.compat_shim', true)) {
            return;
        }

        Http::globalRequestMiddleware(function (RequestInterface $request) {
            if ($this->isAiEndpoint($request->getUri()->getPath()) && $request->getBody()->getSize()) {
                $body = (string) $request->getBody();
                $json = json_decode($body, true);
                if (is_array($json) && isset($json['model']) && is_string($json['model'])) {
                    self::$lastModel = $json['model'];
                }
                $request->getBody()->rewind();
            }

            return $request;
        });

        Http::globalResponseMiddleware(function (ResponseInterface $response) {
            $type = $response->getHeaderLine('Content-Type');
            if (! str_contains($type, 'json')) {
                return $response;
            }

            $raw = (string) $response->getBody();
            $trimmed = rtrim($raw);

            // Some gateways append an SSE terminator to non-stream JSON bodies.
            $changed = false;
            if (str_ends_with($trimmed, 'data: [DONE]')) {
                $trimmed = rtrim(substr($trimmed, 0, -strlen('data: [DONE]')));
                $changed = true;
            }

            $json = json_decode($trimmed, true);
            if (! is_array($json)) {
                return $changed ? $response->withBody(Utils::streamFor($trimmed)) : $response;
            }

            $looksLikeAi = isset($json['object']) && in_array($json['object'], ['response', 'chat.completion'], true)
                || isset($json['output']) || isset($json['choices']);

            if ($looksLikeAi && (! isset($json['model']) || ! is_string($json['model']) || $json['model'] === '')) {
                $json['model'] = self::$lastModel ?? 'unknown';
                $changed = true;
            }

            // Prism\Prism\ValueObjects\Usage requires int prompt/completion tokens.
            // Some gateways (9router glm-5.3 route) send null. Coerce.
            if ($looksLikeAi && isset($json['usage']) && is_array($json['usage'])) {
                foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $k) {
                    if (! array_key_exists($k, $json['usage']) || $json['usage'][$k] === null) {
                        $json['usage'][$k] = 0;
                        $changed = true;
                    }
                }
            } elseif ($looksLikeAi && ! isset($json['usage'])) {
                $json['usage'] = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
                $changed = true;
            }

            // Reasoning-model routes (glm-5.3 behind garza-auto) stream tokens into
            // delta.reasoning_content and leave delta.content empty. Fold reasoning
            // into content so Prism produces a non-empty final text.
            if ($looksLikeAi && isset($json['choices']) && is_array($json['choices'])) {
                foreach ($json['choices'] as &$choice) {
                    if (isset($choice['message']) && is_array($choice['message'])) {
                        $c = $choice['message']['content'] ?? '';
                        $r = $choice['message']['reasoning_content'] ?? '';
                        if (($c === '' || $c === null) && is_string($r) && $r !== '') {
                            $choice['message']['content'] = $r;
                            $changed = true;
                        }
                    }
                }
                unset($choice);
            }

            if (! $changed) {
                return $response;
            }

            return $response->withBody(Utils::streamFor(json_encode($json)));
        });
    }

    private function isAiEndpoint(string $path): bool
    {
        return str_ends_with($path, '/responses')
            || str_ends_with($path, '/chat/completions')
            || str_ends_with($path, '/embeddings')
            || str_ends_with($path, '/messages');
    }
}
