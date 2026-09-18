<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCallEvent;
use App\Models\Call;
use App\Models\CallEvent;
use App\Services\CallService;
use Illuminate\Console\Command;

class ReconcileCalls extends Command
{
    protected $signature = 'calls:reconcile';

    protected $description = 'Read back recent Exotel calls; requeue unprocessed callback events.';

    public function handle(CallService $service): int
    {
        $count = 0;
        $failed = 0;
        Call::where('provider', 'exotel')->whereNotNull('provider_sid')->where('initiated_at', '>', now()->subDay())
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(5)))
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhereNull('duration_seconds')->orWhere('customer_status', 'unknown')->orWhere('ended_at', '>', now()->subMinutes(15)))
            ->orderBy('id')->chunkById(50, function ($calls) use ($service, &$count, &$failed) {
                foreach ($calls as $call) {
                    try {
                        $service->sync($call);
                        $count++;
                    } catch (\Throwable) {
                        $failed++;
                        $call->update(['error_message' => 'Scheduled verification failed. Check provider configuration and call details.']);
                    }
                }
            });
        CallEvent::whereNull('processed_at')->where('created_at', '>', now()->subDay())->where('updated_at', '<', now()->subMinutes(10))->limit(100)->get()->each(fn ($event) => ProcessCallEvent::dispatch($event->id));
        $this->info("Verified $count calls; $failed need attention.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
