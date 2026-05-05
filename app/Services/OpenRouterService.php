<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenRouterService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function postChatCompletions(array $payload, string $apiKey): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'HTTP-Referer' => config('app.url'),
            'X-Title' => config('app.name', 'AiPaleograph'),
        ])->timeout(180)->post('https://openrouter.ai/api/v1/chat/completions', $payload);
    }

    /**
     * Account credits from OpenRouter (requires an API key with credits access).
     *
     * @return array{ok: true, total_credits: float, total_usage: float, remaining: float}|array{ok: false, message: string, status?: int}
     */
    public function fetchCredits(string $apiKey): array
    {
        if (trim($apiKey) === '') {
            return ['ok' => false, 'message' => 'No API key configured.'];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'HTTP-Referer' => config('app.url'),
            'X-Title' => config('app.name', 'AiPaleograph'),
        ])->timeout(15)->get('https://openrouter.ai/api/v1/credits');

        if (! $response->successful()) {
            $body = $response->json();
            $msg = is_array($body) && isset($body['error']['message'])
                ? (string) $body['error']['message']
                : 'Unable to fetch credits.';

            return ['ok' => false, 'message' => $msg, 'status' => $response->status()];
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            return ['ok' => false, 'message' => 'Invalid credits response from OpenRouter.'];
        }

        $total = (float) ($data['total_credits'] ?? 0);
        $used = (float) ($data['total_usage'] ?? 0);

        return [
            'ok' => true,
            'total_credits' => $total,
            'total_usage' => $used,
            'remaining' => $total - $used,
        ];
    }

    /**
     * Model IDs that accept image input (vision), from OpenRouter's public model list.
     *
     * @return list<string>
     */
    public function listVisionCapableModelIds(): array
    {
        $response = Http::timeout(45)->get('https://openrouter.ai/api/v1/models');

        if (! $response->successful()) {
            return [];
        }

        $rows = $response->json('data');
        if (! is_array($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $modalities = $row['architecture']['input_modalities'] ?? null;
            if (! is_array($modalities)) {
                continue;
            }
            if (! in_array('image', $modalities, true)) {
                continue;
            }
            $ids[] = (string) $row['id'];
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NATURAL | SORT_FLAG_CASE);

        return $ids;
    }

    public function transcribeManuscriptPage(
        string $base64Image,
        string $mimeType,
        string $targetLanguage,
        string $apiKey,
        string $model,
    ): array {
        // Plain-text markers only: vision models often break strict JSON / json_object schema.
        $prompt = <<<TXT
You are a digital paleography assistant. Look at the manuscript page image.

1) Transcribe the text exactly as it appears (keep original spelling; line breaks allowed).
2) Give the same content in contemporary {$targetLanguage} for modern readers.

Reply with NOTHING before the first marker and NOTHING after the last marker.
Use this exact structure (three lines must match exactly, including brackets):

[[[ORIGINAL]]]
(paste the diplomatic transcription here)
[[[TRANSCRIBED]]]
(paste the contemporary version here)
[[[END]]]

Do not use markdown headings, code fences, or JSON.
TXT;

        $modelsToTry = $this->buildModelFallbackList($model);
        $attemptDebug = [];
        $lastDebugResult = null;
        $totalProcessingMs = 0;
        $totalCostUsd = 0.0;

        foreach ($modelsToTry as $attemptModel) {
            $startedAt = microtime(true);
            $payload = [
                'model' => $attemptModel,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => 'data:'.$mimeType.';base64,'.$base64Image,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->postChatCompletions($payload, $apiKey);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $totalProcessingMs += $elapsedMs;

            if (! $response->successful()) {
                $attemptDebug[] = sprintf(
                    'Model %s -> HTTP %d (%d ms): %s',
                    $attemptModel,
                    $response->status(),
                    $elapsedMs,
                    mb_substr($response->body(), 0, 280)
                );
                continue;
            }

            $body = $response->json();
            if (! is_array($body)) {
                $attemptDebug[] = sprintf('Model %s -> non-array JSON body.', $attemptModel);
                continue;
            }
            $totalCostUsd += $this->extractUsageCostUsd($body);

            $candidates = $this->buildReplyCandidates($body);
            if ($candidates === []) {
                $attemptDebug[] = sprintf('Model %s -> no text candidates extracted.', $attemptModel);
                $lastDebugResult = $this->buildParseFailureDebugReturn(
                    $body,
                    [],
                    'No text candidates were extracted from choices. Model: '.$attemptModel,
                    $totalProcessingMs,
                    $totalCostUsd
                );
                continue;
            }

            $refusalDetected = $this->containsRefusal($candidates);
            foreach ($candidates as $text) {
                $parsed = $this->tryParseReply($text);
                if ($parsed !== null) {
                    $parsed['processing_time_ms'] = $totalProcessingMs;
                    $parsed['processing_cost_usd'] = round($totalCostUsd, 6);
                    return $parsed;
                }
            }

            $attemptDebug[] = sprintf(
                'Model %s -> parse failed%s.',
                $attemptModel,
                $refusalDetected ? ' (assistant refusal detected)' : ''
            );
            $lastDebugResult = $this->buildParseFailureDebugReturn(
                $body,
                $candidates,
                'Tried all extracted text chunks; none matched [[[ORIGINAL]]]/JSON/markdown patterns. Model: '.$attemptModel,
                $totalProcessingMs,
                $totalCostUsd
            );

            // Always continue through fallback models when parsing fails.
        }

        if (is_array($lastDebugResult)) {
            $lastDebugResult['original_text'] .= "\n\n=== Model attempts summary ===\n".implode("\n", $attemptDebug);
            $lastDebugResult['processing_time_ms'] = $totalProcessingMs;
            $lastDebugResult['processing_cost_usd'] = round($totalCostUsd, 6);
            return $lastDebugResult;
        }

        throw new RuntimeException('OpenRouter failed on all fallback models. '.implode(' | ', $attemptDebug));
    }

    /**
     * @return list<string>
     */
    private function buildModelFallbackList(string $primaryModel): array
    {
        return array_values(array_unique(array_filter([
            $primaryModel,
            'openai/gpt-4o-mini',
            'google/gemini-2.0-flash-001',
            'anthropic/claude-3.5-sonnet',
        ])));
    }

    /**
     * @param  list<string>  $candidates
     */
    private function containsRefusal(array $candidates): bool
    {
        foreach ($candidates as $text) {
            $t = mb_strtolower($text);
            if (
                str_contains($t, "i'm unable to fulfill your request") ||
                str_contains($t, "i'm sorry, i can't assist with this task") ||
                str_contains($t, 'i am sorry, i cannot assist with this task') ||
                str_contains($t, "i can't assist with this task") ||
                str_contains($t, 'i cannot assist with that') ||
                str_contains($t, 'i can’t assist with that') ||
                str_contains($t, 'cannot comply with that request') ||
                str_contains($t, 'unable to assist with this task') ||
                str_contains($t, 'policy')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $candidates
     * @return array{original_text: string, transcribed_text: string, _parse_failed: true, processing_time_ms: int, processing_cost_usd: float}
     */
    private function buildParseFailureDebugReturn(
        array $body,
        array $candidates,
        string $reason,
        int $processingTimeMs,
        float $processingCostUsd
    ): array
    {
        $parts = [];
        $parts[] = '=== AiPaleograph parse debug ===';
        $parts[] = 'Reason: '.$reason;
        $parts[] = 'Hint: '.$this->summarizeOpenRouterChoice($body);
        $parts[] = '';
        $parts[] = '=== Full OpenRouter response body (decoded JSON, as returned by API) ===';
        $encoded = json_encode(
            $body,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $parts[] = ($encoded !== false && $encoded !== '') ? $encoded : '(json_encode failed)';
        $parts[] = '';
        $parts[] = '=== Text candidates extracted from response (same order as parser tried) ===';

        if ($candidates === []) {
            $parts[] = '(none)';
        }

        foreach ($candidates as $i => $text) {
            $parts[] = '';
            $parts[] = '--- Candidate '.($i + 1).' (length: '.strlen($text).' bytes) ---';
            $parts[] = $text;
        }

        $dump = implode("\n", $parts);

        return [
            'original_text' => $dump,
            'transcribed_text' => '[DEBUG] Parse failed — the full OpenRouter payload and extracted strings are in the Original text column.',
            '_parse_failed' => true,
            'processing_time_ms' => $processingTimeMs,
            'processing_cost_usd' => round($processingCostUsd, 6),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractUsageCostUsd(array $body): float
    {
        $usage = $body['usage'] ?? null;
        if (! is_array($usage)) {
            return 0.0;
        }

        $cost = $usage['cost'] ?? 0.0;
        if (is_numeric($cost)) {
            return (float) $cost;
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function buildReplyCandidates(array $body): array
    {
        $chunks = [];

        $merged = $this->extractAssistantText($body);
        if ($merged !== '') {
            $chunks[] = $merged;
        }

        $strings = [];
        $this->collectAllStrings($body['choices'] ?? [], $strings, 0);
        $strings = array_values(array_unique(array_filter($strings)));

        usort($strings, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        foreach ($strings as $s) {
            $chunks[] = $s;
        }

        return array_values(array_unique(array_filter($chunks)));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function summarizeOpenRouterChoice(array $body): string
    {
        $choice = $body['choices'][0] ?? [];
        if (! is_array($choice)) {
            return '';
        }

        $fr = $choice['finish_reason'] ?? null;
        $msg = $choice['message'] ?? [];

        $role = is_array($msg) ? ($msg['role'] ?? null) : null;
        $snippet = '';
        if (is_array($msg)) {
            $raw = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($raw)) {
                $snippet = mb_substr($raw, 0, 800);
            }
        }

        $parts = array_filter([
            $fr !== null && $fr !== '' ? ' finish_reason='.json_encode($fr) : null,
            $role ? ' role='.json_encode($role) : null,
            $snippet !== '' ? ' message≈'.$snippet : null,
        ]);

        return $parts === [] ? '' : ' ('.implode('; ', $parts).')';
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractAssistantText(array $body): string
    {
        $choice = $body['choices'][0] ?? [];
        if (! is_array($choice)) {
            return '';
        }

        $parts = [];

        $message = $choice['message'] ?? [];
        if (is_array($message)) {
            $raw = $message['content'] ?? null;
            if ($raw !== null && $raw !== '') {
                $parts[] = $this->messageContentToString($raw);
            }
            foreach (['reasoning', 'refusal', 'annotations'] as $alt) {
                if (! isset($message[$alt])) {
                    continue;
                }
                $val = $message[$alt];
                if (is_string($val) && $val !== '') {
                    $parts[] = $val;
                }
                if (is_array($val)) {
                    $nested = [];
                    $this->collectAllStrings($val, $nested, 0);
                    if ($nested !== []) {
                        $parts[] = implode("\n", $nested);
                    }
                }
            }
        }

        if (! empty($choice['text']) && is_string($choice['text'])) {
            $parts[] = $choice['text'];
        }

        return trim(implode("\n", array_filter(array_map('trim', $parts))));
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  list<string>  $out
     */
    private function collectAllStrings(mixed $node, array &$out, int $depth): void
    {
        if ($depth > 30) {
            return;
        }

        if (is_string($node)) {
            $t = trim($node);
            if ($t !== '') {
                $out[] = $t;
            }

            return;
        }

        if (! is_array($node)) {
            return;
        }

        foreach ($node as $v) {
            $this->collectAllStrings($v, $out, $depth + 1);
        }
    }

    private function messageContentToString(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        // OpenAI-style parts array
        if (array_is_list($content)) {
            $buf = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $buf[] = $part;
                    continue;
                }
                if (! is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $buf[] = (string) $part['text'];
                    continue;
                }
                // Nested or unknown shape: pull any "text" subtree
                $buf[] = $this->messageContentToString($part);
            }

            return implode('', $buf);
        }

        // Single associative blob (some gateways)
        if (isset($content['text']) && is_string($content['text'])) {
            return $content['text'];
        }

        $nested = [];
        $this->collectAllStrings($content, $nested, 0);

        return implode('', $nested);
    }

    /**
     * @return array{original_text: string, transcribed_text: string}|null
     */
    private function tryParseReply(string $content): ?array
    {
        $t = trim($content);
        if ($t === '') {
            return null;
        }

        foreach ($this->parseBracketDelimited($t) as $pair) {
            return $pair;
        }

        foreach ($this->parseLegacyDelimited($t) as $pair) {
            return $pair;
        }

        foreach ($this->parseMarkdownSections($t) as $pair) {
            return $pair;
        }

        try {
            return $this->decodeJsonPayload($t);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @return list<array{original_text: string, transcribed_text: string}>
     */
    private function parseBracketDelimited(string $content): array
    {
        $pattern = '/\[\[\[\s*ORIGINAL\s*\]\]\]\s*([\s\S]*?)\s*\[\[\[\s*TRANSCRIBED\s*\]\]\]\s*([\s\S]*?)(?:\s*\[\[\[\s*END\s*\]\]\]|$)/iu';

        if (preg_match($pattern, $content, $m)) {
            return [[
                'original_text' => trim($m[1]),
                'transcribed_text' => trim($m[2]),
            ]];
        }

        return [];
    }

    /**
     * @return list<array{original_text: string, transcribed_text: string}>
     */
    private function parseLegacyDelimited(string $content): array
    {
        if (! str_contains($content, 'AIPL_')) {
            return [];
        }

        $patterns = [
            '/<<<AIPL_ORIGINAL>>>\s*([\s\S]*?)\s*<<<AIPL_TRANSCRIBED>>>\s*([\s\S]*?)(?:<<<AIPL_END>>>|$)/i',
            '/<<<AIPL_ORIGINAL>>>\s*([\s\S]*?)\s*<<<AIPL_TRANSCRIBED>>>\s*([\s\S]*)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $m)) {
                return [[
                    'original_text' => trim($m[1]),
                    'transcribed_text' => trim($m[2]),
                ]];
            }
        }

        return [];
    }

    /**
     * @return list<array{original_text: string, transcribed_text: string}>
     */
    private function parseMarkdownSections(string $content): array
    {
        $patterns = [
            '/(?:^|\n)#{1,3}\s*Original[^\n]*\n+([\s\S]*?)\n+#{1,3}\s*Transcribed[^\n]*\n+([\s\S]*)/iu',
            '/(?:^|\n)\*{0,2}\s*Original\s*(?:transcription)?\*{0,2}\s*\n+([\s\S]*?)\n+\*{0,2}\s*Transcribed\*{0,2}\s*\n+([\s\S]*)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $m)) {
                return [[
                    'original_text' => trim($m[1]),
                    'transcribed_text' => trim($m[2]),
                ]];
            }
        }

        return [];
    }

    /**
     * @return array{original_text: string, transcribed_text: string}
     */
    private function decodeJsonPayload(string $content): array
    {
        $trimmed = trim($content);
        $trimmed = preg_replace('/^\xEF\xBB\xBF/', '', $trimmed) ?? $trimmed;

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        } else {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
            $trimmed = preg_replace('/\s*```$/', '', $trimmed);
            $trimmed = trim($trimmed);
        }

        $candidates = [];
        foreach ([$trimmed, $this->extractFirstBalancedJsonObject($trimmed)] as $c) {
            if (is_string($c) && $c !== '') {
                $candidates[] = $c;
            }
        }

        if (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) {
            $inner = json_decode($trimmed, true);
            if (is_string($inner)) {
                $candidates[] = $inner;
                $obj = $this->extractFirstBalancedJsonObject($inner);
                if ($obj !== null) {
                    $candidates[] = $obj;
                }
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates)));

        foreach ($candidates as $json) {
            foreach ($this->jsonVariants($json) as $variant) {
                $data = json_decode($variant, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                if (! is_array($data)) {
                    continue;
                }

                $data = $this->unwrapNestedPayload($data);
                if (! $this->hasTranscriptionKeys($data)) {
                    continue;
                }

                return $this->normalizeDecodedFields($data);
            }
        }

        $preview = mb_substr(preg_replace('/\s+/', ' ', $trimmed), 0, 400);

        throw new RuntimeException(
            'Could not parse JSON from the model response. Snippet: '.$preview
        );
    }

    /**
     * @return list<string>
     */
    private function jsonVariants(string $json): array
    {
        $out = [$json];
        $repaired = $this->repairCommonJsonIssues($json);
        if ($repaired !== $json) {
            $out[] = $repaired;
        }

        return array_values(array_unique($out));
    }

    private function repairCommonJsonIssues(string $json): string
    {
        return preg_replace('/,\s*([\]}])/', '$1', $json) ?? $json;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function unwrapNestedPayload(array $data, int $depth = 0): array
    {
        if ($depth > 4) {
            return $data;
        }

        foreach (['result', 'data', 'output', 'response', 'answer', 'content'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $inner = $data[$key];
                unset($data[$key]);

                return $this->unwrapNestedPayload(array_merge($data, $inner), $depth + 1);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasTranscriptionKeys(array $data): bool
    {
        foreach ([
            'original_text', 'originalText', 'transcribed_text', 'transcribedText',
            'original', 'transcription', 'source_text', 'modern_text',
            'contemporary_text', 'translation', 'translated_text',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }

        return false;
    }

    private function extractFirstBalancedJsonObject(string $s): ?string
    {
        $start = strpos($s, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($s);

        for ($i = $start; $i < $len; $i++) {
            $c = $s[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($inString) {
                if ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($c === '"') {
                $inString = true;

                continue;
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{original_text: string, transcribed_text: string}
     */
    private function normalizeDecodedFields(array $data): array
    {
        $original = $data['original_text']
            ?? $data['originalText']
            ?? $data['original']
            ?? $data['source_text']
            ?? $data['transcription']
            ?? $data['transcription_original']
            ?? '';

        $transcribed = $data['transcribed_text']
            ?? $data['transcribedText']
            ?? $data['modern_text']
            ?? $data['contemporary_text']
            ?? $data['translation']
            ?? $data['translated_text']
            ?? $data['transcription_modern']
            ?? '';

        if (is_array($original)) {
            $original = json_encode($original, JSON_UNESCAPED_UNICODE);
        }
        if (is_array($transcribed)) {
            $transcribed = json_encode($transcribed, JSON_UNESCAPED_UNICODE);
        }

        return [
            'original_text' => is_string($original) ? $original : (is_scalar($original) ? (string) $original : ''),
            'transcribed_text' => is_string($transcribed) ? $transcribed : (is_scalar($transcribed) ? (string) $transcribed : ''),
        ];
    }
}
