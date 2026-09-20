<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CallController extends Controller
{
    /**
     * Receive and sync call logs from the Android App
     */
    public function sync(Request $request)
    {
        $attempts = $request->input('attempts', []);
        $recentCallLog = $request->input('recentCallLog'); // Could be an array of logs

        if (empty($attempts)) {
            return response()->json(['status' => 'success', 'message' => 'No attempts to sync']);
        }

        foreach ($attempts as $attempt) {
            $attemptId = $attempt['attemptId'] ?? null;
            if (!$attemptId) continue;

            $call = \App\Models\Call::where('request_key', $attemptId)->first();

            // Handle manual dialed calls from the app
            if (str_starts_with($attemptId, 'manual_') && !$call) {
                $employeeId = $request->input('employee_id');
                if (!$employeeId) continue; // Can't record without employee

                $targetPhone = preg_replace('/[^0-9]/', '', $attempt['phoneNumber']);
                
                // Find or create lead
                $lead = \App\Models\Lead::where('phone', 'like', '%' . substr($targetPhone, -10))->first();
                if (!$lead) {
                    $lead = \App\Models\Lead::create([
                        'name' => 'Manual Lead - ' . $attempt['phoneNumber'],
                        'phone' => '+' . $targetPhone,
                        'source' => 'App Dialer',
                        'stage' => 'New',
                        'assigned_to' => $employeeId,
                    ]);
                }

                // Create placeholder call to be processed below
                $call = \App\Models\Call::create([
                    'request_key' => $attemptId,
                    'lead_id' => $lead->id,
                    'employee_id' => $employeeId,
                    'provider' => 'native',
                    'callback_token' => \Illuminate\Support\Str::random(64),
                    'employee_phone' => 'unknown',
                    'customer_phone' => $lead->phone,
                    'status' => 'initiating',
                    'initiated_at' => now()->subMinutes(1), // Approximate
                ]);
            }
            
            if ($call && $call->isActive()) {
                // Find matching duration from the native call log
                $matchedLog = null;
                $targetPhone = preg_replace('/[^0-9]/', '', $call->customer_phone);

                if (is_array($recentCallLog)) {
                    foreach ($recentCallLog as $log) {
                        $logPhone = preg_replace('/[^0-9]/', '', $log['phoneNumber'] ?? '');
                        // If phone matches (fuzzy match last 10 digits) and time is within 5 minutes
                        if (substr($logPhone, -10) === substr($targetPhone, -10)) {
                            $timeDiff = abs(($log['timestamp'] ?? 0) - ($attempt['timestamp'] ?? 0));
                            if ($timeDiff < 300000) { // 5 minutes
                                $matchedLog = $log;
                                break;
                            }
                        }
                    }
                }

                $duration = $matchedLog['duration'] ?? 0;
                $status = $duration > 0 ? 'completed' : 'failed';
                $customerStatus = $duration > 0 ? 'connected' : 'no-answer';

                $call->update([
                    'status' => $status,
                    'customer_status' => $customerStatus,
                    'duration_seconds' => $duration,
                    'connected_at' => $duration > 0 ? now()->subSeconds($duration) : null,
                    'ended_at' => now(),
                    'last_synced_at' => now(),
                    'provider' => 'native',
                    'provider_sid' => 'native_' . $attemptId,
                ]);

                \App\Models\CallEvent::create([
                    'call_id' => $call->id,
                    'fingerprint' => hash('sha256', 'native:' . $call->id),
                    'payload' => json_encode(['source' => 'native', 'duration' => $duration]),
                    'processed_at' => now()
                ]);
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Synced']);
    }
}
