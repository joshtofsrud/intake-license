<?php

namespace App\Services\Anthropic;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin wrapper around the Anthropic Messages API.
 *
 * Single entry point: messages(). Returns the parsed JSON body. Caller
 * is responsible for extracting whatever shape they need from `content`.
 *
 * Configuration: ANTHROPIC_API_KEY in .env. Throws if missing — fast fail
 * is better than a runtime 401 deep inside a request.
 */
class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const TIMEOUT_SECONDS = 30;

    private string $apiKey;

    public function __construct()
    {
        $key = (string) config('services.anthropic.key', ''); // MARKER-PATCH-224B
        if ($key === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not set (services.anthropic.key).');
        }
        $this->apiKey = $key;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options  e.g. ['system' => '...', 'temperature' => 0.7]
     * @return array<string, mixed>
     */
    public function messages(string $model, int $maxTokens, array $messages, array $options = []): array
    {
        $payload = array_merge([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ], $options);

        $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ])
            ->timeout(self::TIMEOUT_SECONDS)
            ->post(self::API_URL, $payload);

        if (!$response->successful()) {
            // Log the failure but don't leak the API key or full prompt to logs.
            Log::warning('Anthropic API call failed', [
                'status' => $response->status(),
                'body' => $response->json(),
                'model' => $model,
            ]);
            throw new RuntimeException(sprintf(
                'Anthropic API error (HTTP %d): %s',
                $response->status(),
                $response->json('error.message') ?? 'Unknown error'
            ));
        }

        return $response->json() ?? [];
    }

    /**
     * Convenience: extract the concatenated text from a Messages-API response,
     * skipping any non-text blocks (tool_use, etc.). Used when we expect a
     * single text reply and want to skip the `content[0].text` boilerplate.
     */
    public function extractText(array $response): string
    {
        $blocks = $response['content'] ?? [];
        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        return $text;
    }
}
