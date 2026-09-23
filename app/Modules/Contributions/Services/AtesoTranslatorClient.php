<?php

namespace App\Modules\Contributions\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Talks to the hosted Ateso translator (a Gradio Space).
 *
 * We proxy rather than embed. An iframed Space is a separate origin, so we would
 * see nothing the visitor typed, nothing the model returned and no verdict — and
 * the capture is the entire point of the guest loop.
 *
 * The Gradio call is TWO requests, not one: POST returns an event id, then a
 * second GET streams back an SSE body containing the result. A single-shot POST
 * returns `event: error`, which looks exactly like the model being down.
 */
class AtesoTranslatorClient
{
    /** Gradio's dropdown labels, which are part of its API contract. */
    private const DIRECTION_LABELS = [
        'teo_to_en' => 'Ateso → English',
        'en_to_teo' => 'English → Ateso',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $endpoint,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('contributions.translator.base_url'), '/'),
            trim((string) config('contributions.translator.endpoint', 'translate'), '/'),
            (int) config('contributions.translator.timeout', 45),
        );
    }

    /**
     * Translate one string. Returns the model output, or throws so the caller
     * can surface a real failure instead of writing a bogus row.
     *
     * @throws RuntimeException
     */
    public function translate(string $text, string $direction): string
    {
        $label = self::DIRECTION_LABELS[$direction] ?? self::DIRECTION_LABELS['teo_to_en'];

        $eventId = $this->openCall($text, $label);

        return $this->readResult($eventId);
    }

    /** Step 1 — hand Gradio the inputs, get back an event id. */
    private function openCall(string $text, string $label): string
    {
        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->post("{$this->baseUrl}/gradio_api/call/{$this->endpoint}", [
                'data' => [$text, $label],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Translator rejected the request ({$response->status()}).");
        }

        $eventId = $response->json('event_id');

        if (! is_string($eventId) || $eventId === '') {
            throw new RuntimeException('Translator did not return an event id.');
        }

        return $eventId;
    }

    /** Step 2 — read the SSE stream and pull the completed payload out of it. */
    private function readResult(string $eventId): string
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->get("{$this->baseUrl}/gradio_api/call/{$this->endpoint}/{$eventId}");

        if (! $response->successful()) {
            throw new RuntimeException("Translator stream failed ({$response->status()}).");
        }

        $body = (string) $response->body();

        // The stream is a sequence of `event: <name>` / `data: <json>` pairs. We
        // want the data line belonging to the `complete` event.
        $completed = false;

        foreach (preg_split('/\r?\n/', $body) as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));

                if ($event === 'error') {
                    throw new RuntimeException('Translator reported an error for this input.');
                }

                $completed = $event === 'complete';

                continue;
            }

            if (! $completed || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = json_decode(trim(substr($line, 5)), true);

            // Gradio wraps single outputs in an array.
            $value = is_array($payload) ? ($payload[0] ?? null) : $payload;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        throw new RuntimeException('Translator returned no usable output.');
    }

    /**
     * Cheap liveness ping. The Space sleeps after 48 hours idle and needs
     * one to three minutes to wake, so a scheduled ping keeps a visitor from
     * ever being the one who pays that cost.
     */
    public function ping(): bool
    {
        try {
            $this->translate('Ejok', 'teo_to_en');

            return true;
        } catch (\Throwable $e) {
            Log::warning('Ateso translator ping failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
