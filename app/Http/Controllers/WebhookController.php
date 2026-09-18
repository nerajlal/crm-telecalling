<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCallEvent;
use App\Models\Call;
use App\Models\CallEvent;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(Request $r, Call $call, string $token)
    {
        abort_unless($call->provider === 'exotel' && hash_equals($call->callback_token, $token), 403);
        $data = $r->validate(['CallSid' => 'required|string|max:128|regex:/^[a-zA-Z0-9_-]+$/', 'Status' => 'nullable|string|max:30', 'DateUpdated' => 'nullable|string|max:60', 'EventType' => 'nullable|string|max:30']);
        abort_if($call->provider_sid && $call->provider_sid !== $data['CallSid'], 403);
        ksort($data);
        // Store only the minimum callback hint; no recording URLs, query secrets or untrusted durations.
        $event = CallEvent::firstOrCreate(['fingerprint' => hash('sha256', $call->id.json_encode($data))], ['call_id' => $call->id, 'payload' => $data]);
        if ($event->wasRecentlyCreated || (! $event->processed_at && $event->error)) {
            ProcessCallEvent::dispatch($event->id);
        }

        return response()->json(['received' => true]);
    }
}
