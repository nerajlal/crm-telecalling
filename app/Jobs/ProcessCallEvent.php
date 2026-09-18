<?php

namespace App\Jobs;

use App\Models\CallEvent;
use App\Services\CallService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCallEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 45;

    public function backoff(): array
    {
        return [20, 60, 180];
    }

    public function __construct(public int $eventId) {}

    public function handle(CallService $service): void
    {
        $event = CallEvent::findOrFail($this->eventId);
        if ($event->processed_at) {
            return;
        }
        try {
            $service->sync($event->call, $event->payload['CallSid']);
            $event->update(['processed_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            $event->update(['error' => 'Verification failed; waiting for retry or reconciliation.']);
            throw new \RuntimeException('Call event verification failed for event '.$event->id);
        }
    }
}
