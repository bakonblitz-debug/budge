<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Anthropic Messages API using Laravel's HTTP client.
 *
 * Deliberately small: it authenticates and POSTs a Messages payload, and knows
 * nothing about budge's domain. Domain services (TransactionClassifier, etc.)
 * build the payload — including prompt-cached system blocks, structured output
 * formats, and model selection — and hand it here.
 *
 * Tests fake this at the HTTP layer with Http::fake(), so no real key is needed.
 */
class AnthropicClient
{
    /**
     * POST a Messages API payload and return the decoded response.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function messages(array $payload): array
    {
        $key = (string) config('services.anthropic.key');

        if ($key === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured (config/services.php → anthropic.key).');
        }

        $payload['model'] ??= config('services.anthropic.models.classify');
        $payload['max_tokens'] ??= 4096;

        $response = Http::baseUrl((string) config('services.anthropic.base_url'))
            ->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => (string) config('services.anthropic.version'),
                'content-type' => 'application/json',
            ])
            ->timeout(120)
            ->retry(2, 1000, throw: false)
            ->post('/v1/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException("Anthropic API request failed ({$response->status()}): {$response->body()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Pull the first text block out of a Messages response. With
     * output_config.format set, that block is guaranteed to be valid JSON.
     *
     * @param  array<string, mixed>  $response
     */
    public function firstText(array $response): ?string
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                return $block['text'] ?? null;
            }
        }

        return null;
    }
}
