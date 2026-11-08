<?php

namespace App\Jobs;

use App\Support\Core;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * One event to PK95 Core (POST {PK95_CORE_URL}/api/v1/events). Retried while Core is not
 * reachable (about an hour in all); dropped when Core rejects it.
 */
class SendCoreEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $event  {type, severity, title, body, data, occurred_at}
     */
    public function __construct(public array $event) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300, 600, 1200];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function send(string $type, string $severity, string $title, ?string $body = null, array $data = []): void
    {
        if (! Core::enabled()) {
            return;
        }
        self::dispatch([
            'type' => $type, 'severity' => $severity, 'title' => mb_substr($title, 0, 255),
            'body' => $body, 'data' => $data, 'occurred_at' => now()->toIso8601String(),
        ]);
    }

    public function handle(): void
    {
        $response = Http::timeout(10)->withToken(Core::token())->acceptJson()->post(Core::url().'/api/v1/events', $this->event);
        if ($response->successful()) {
            return;
        }
        if ($response->serverError() || $response->status() === 429) {
            throw new RuntimeException("PK95 Core antwortet mit HTTP {$response->status()}");
        }
        Log::warning("PK95 Core hat das Ereignis {$this->event['type']} abgelehnt (HTTP {$response->status()})");
    }
}
