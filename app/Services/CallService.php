<?php

namespace App\Services;

use App\Models\Call;
use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\User;
use App\Support\India;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CallService
{
    public function __construct(private ExotelClient $provider) {}

    public function start(Lead $lead, User $employee, string $key): Call
    {
        $driver = config('telephony.driver');
        if (! in_array($driver, ['demo', 'exotel']) || ($driver === 'exotel' && ! $this->provider->ready())) {
            throw ValidationException::withMessages(['call' => 'Live calling is not configured. Ask the owner to check Settings.']);
        }
        if ($driver === 'demo' && ! Call::demoEnabled()) {
            throw ValidationException::withMessages(['call' => 'Demo calling is restricted to local development and automated tests.']);
        }
        $created = false;
        $call = DB::transaction(function () use ($lead, $employee, $key, $driver, &$created) {
            $employee = User::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            abort_unless($employee->active && $employee->role === 'employee' && $lead->assigned_to === $employee->id, 403);
            if ($existing = Call::where('request_key', $key)->first()) {
                abort_unless($existing->employee_id === $employee->id && $existing->lead_id === $lead->id, 403);

                return $existing;
            }
            if (Call::whereNull('resolved_at')->whereIn('status', Call::ACTIVE)->where(fn ($q) => $q->where('employee_id', $employee->id)->orWhere('lead_id', $lead->id))->exists()) {
                throw ValidationException::withMessages(['call' => 'An active or unconfirmed call already exists. Finish it or ask the owner to resolve it first.']);
            }
            $created = true;

            return Call::create(['request_key' => $key, 'lead_id' => $lead->id, 'employee_id' => $employee->id, 'provider' => $driver, 'callback_token' => Str::random(64), 'employee_phone' => India::phone($employee->phone), 'customer_phone' => India::phone($lead->phone), 'status' => 'initiating', 'initiated_at' => now()]);
        });
        if (! $created) {
            return $call;
        }
        if ($driver === 'demo') {
            $call->update(['status' => 'in-progress', 'provider_sid' => 'demo-'.$call->request_key]);

            return $call;
        }
        try {
            $result = $this->provider->initiate($call);
            DB::transaction(function () use ($call, $result) {
                $locked = Call::whereKey($call->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'initiating') {
                    return;
                } // A verified callback may win the race.
                if ($result['rejected'] ?? false) {
                    $locked->update(['status' => 'failed', 'customer_status' => 'not-attempted', 'ended_at' => now(), 'error_message' => 'Provider rejected the request (HTTP '.$result['http_status'].'). Check account setup.']);
                } else {
                    $locked->update(['provider_sid' => $result['sid'], 'status' => 'queued']);
                }
            });
        } catch (\Throwable $e) {
            Call::whereKey($call->id)->where('status', 'initiating')->update(['status' => 'unknown', 'error_message' => 'Provider acceptance is uncertain. Do not redial until the owner checks the provider call log.']);
        }

        return $call->fresh();
    }

    public function sync(Call $call, ?string $candidateSid = null): void
    {
        $sid = $call->provider_sid ?: $candidateSid;
        if (! $sid) {
            throw new \RuntimeException('Provider reference is missing.');
        }
        $data = $this->provider->details($sid);
        // Webhooks are hints only. A credentialed API read must match this exact call.
        if (($data['Sid'] ?? null) !== $sid || ($data['AccountSid'] ?? null) !== config('telephony.exotel.account_sid') || India::phone($data['From'] ?? '') !== $call->employee_phone || India::phone($data['To'] ?? '') !== $call->customer_phone) {
            throw new \RuntimeException('Provider call identity mismatch.');
        }
        $created = $this->timestamp($data['DateCreated'] ?? null);
        if (! $created || abs($created->timestamp - $call->initiated_at->timestamp) > 300) {
            throw new \RuntimeException('Provider call time mismatch.');
        }
        $this->applyVerified($call, $data, $sid);
    }

    public function applyVerified(Call $call, array $data, string $sid): void
    {
        DB::transaction(function () use ($call, $data, $sid) {
            $call = Call::whereKey($call->id)->lockForUpdate()->firstOrFail();
            if ($call->provider_sid && $call->provider_sid !== $sid) {
                throw new \RuntimeException('Provider reference mismatch.');
            }
            $stamp = $this->timestamp($data['DateUpdated'] ?? null);
            if ($stamp && $call->provider_updated_at && $stamp->lt($call->provider_updated_at)) {
                return;
            }
            $status = $data['Status'] ?? 'unknown';
            if (! in_array($status, array_merge(Call::TERMINAL, ['queued', 'in-progress']))) {
                return;
            }
            if (in_array($call->status, Call::TERMINAL) && ! in_array($status, Call::TERMINAL)) {
                return;
            }
            $details = $data['Details'] ?? [];
            $leg1 = $details['Leg1Status'] ?? null;
            $leg2 = $details['Leg2Status'] ?? null;
            $duration = $details['ConversationDuration'] ?? null;
            $duration = is_numeric($duration) && $duration >= 0 ? (int) $duration : null;
            $customer = 'pending';
            if ($leg2 === 'completed' || ($duration !== null && $duration > 0)) {
                $customer = 'connected';
            } elseif (in_array($leg2, ['busy', 'no-answer', 'failed', 'canceled'])) {
                $customer = $leg2;
            } elseif (in_array($status, Call::TERMINAL)) {
                $customer = array_key_exists('Leg2Status', $details) && $leg2 === null ? 'not-attempted' : 'unknown';
            }
            if (in_array($customer, ['pending', 'unknown']) && ! in_array($call->customer_status, ['pending', 'unknown'])) {
                $customer = $call->customer_status;
            }
            $leg1 ??= $call->employee_leg_status;
            $leg2 ??= $call->customer_leg_status;
            $call->fill(['provider_sid' => $sid, 'status' => $status, 'customer_status' => $customer, 'employee_leg_status' => $leg1, 'customer_leg_status' => $leg2, 'last_synced_at' => now(), 'error_message' => null]);
            if ($stamp) {
                $call->provider_updated_at = $stamp;
            }
            // Never substitute total/billed Duration for customer conversation duration.
            if ($duration !== null) {
                $call->duration_seconds = $duration;
            }
            if ($end = $this->timestamp($data['EndTime'] ?? null)) {
                $call->ended_at = $end;
            }
            // This API does not document an exact customer connection timestamp.
            // Keep it unknown rather than infer it from total or conversation duration.
            $call->save();
        });
    }

    private function timestamp(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value, config('telephony.exotel.timezone'))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public function demo(Call $call, string $outcome, ?int $duration): void
    {
        abort_unless(Call::demoEnabled() && $call->provider === 'demo', 404);
        DB::transaction(function () use ($call, $outcome, $duration) {
            $call = Call::whereKey($call->id)->lockForUpdate()->firstOrFail();
            if (! $call->isActive()) {
                return;
            }
            $seconds = $outcome === 'connected' ? $duration : 0;
            $call->update(['status' => $outcome === 'connected' ? 'completed' : $outcome, 'customer_status' => $outcome, 'duration_seconds' => $seconds, 'ended_at' => $call->initiated_at->copy()->addSeconds($seconds), 'connected_at' => $outcome === 'connected' ? $call->initiated_at : null, 'last_synced_at' => now()]);
            CallEvent::create(['call_id' => $call->id, 'fingerprint' => hash('sha256', 'demo:'.$call->id), 'payload' => ['source' => 'demo', 'outcome' => $outcome, 'duration' => $seconds], 'processed_at' => now()]);
        });
    }
}
