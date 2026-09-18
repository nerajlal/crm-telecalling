<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use App\Services\CallService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CallController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['status' => 'nullable|in:connected,no-answer,busy,failed,canceled,pending,unknown,not-attempted', 'employee' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $calls = Call::visibleTo($r->user())->with('lead', 'employee')
            ->when($r->filled('status'), fn ($q) => $q->where('customer_status', $r->input('status')))
            ->when($r->filled('employee') && $r->user()->isOwner(), fn ($q) => $q->where('employee_id', $r->input('employee')))
            ->when($r->filled('from'), fn ($q) => $q->where('initiated_at', '>=', CarbonImmutable::parse($r->input('from'), 'Asia/Kolkata')->utc()))
            ->when($r->filled('to'), fn ($q) => $q->where('initiated_at', '<', CarbonImmutable::parse($r->input('to'), 'Asia/Kolkata')->addDay()->utc()))
            ->latest('initiated_at')->paginate(20)->withQueryString();

        return view('calls.index', ['calls' => $calls, 'employees' => User::where('role', 'employee')->orderBy('name')->get()]);
    }

    public function store(Request $r, Lead $lead, CallService $service)
    {
        $data = $r->validate(['request_key' => 'required|uuid', 'duration' => 'prohibited', 'duration_seconds' => 'prohibited', 'status' => 'prohibited', 'customer_status' => 'prohibited', 'initiated_at' => 'prohibited', 'connected_at' => 'prohibited', 'ended_at' => 'prohibited', 'provider_sid' => 'prohibited', 'employee_id' => 'prohibited', 'employee_phone' => 'prohibited', 'customer_phone' => 'prohibited']);
        $call = $service->start($lead, $r->user(), $data['request_key']);

        return redirect()->route('leads.show', $lead)->with($call->status === 'unknown' ? 'warning' : 'success', $call->provider === 'demo' ? 'Demo call started. No phones will ring. Choose a simulated result below.' : ($call->status === 'unknown' ? 'Call acceptance is uncertain. Ask the owner to check before redialing.' : ($call->status === 'failed' ? 'Provider rejected the call. Check the call history.' : 'Call requested. Answer your registered phone.')));
    }

    public function demo(Request $r, Call $call, CallService $service)
    {
        abort_unless(Call::demoEnabled() && $call->provider === 'demo', 404);
        abort_unless($call->employee_id === $r->user()->id && $call->lead->assigned_to === $r->user()->id, 403);
        $data = $r->validate(['outcome' => 'required|in:connected,no-answer,busy,failed', 'duration' => 'required_if:outcome,connected|nullable|integer|min:0|max:3600']);
        $service->demo($call, $data['outcome'], isset($data['duration']) ? (int) $data['duration'] : null);

        return back()->with('success', 'Simulated call result saved.');
    }

    public function reconcile(Request $r, Call $call, CallService $service)
    {
        abort_unless($call->provider === 'exotel', 422);
        $data = $r->validate(['provider_sid' => 'nullable|string|max:128|regex:/^[a-zA-Z0-9_-]+$/']);
        try {
            $service->sync($call, $data['provider_sid'] ?? null);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['call' => 'Could not verify the call. Check the provider reference, account configuration, and provider logs.']);
        }
        AuditLog::create(['user_id' => $r->user()->id, 'action' => 'call.reconciled', 'description' => 'Verified call #'.$call->id]);

        return back()->with('success', 'Call verified against provider records.');
    }

    public function resolve(Request $r, Call $call)
    {
        $data = $r->validate(['reason' => 'required|string|min:15|max:1000', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($r, $call, $data) {
            $call = Call::whereKey($call->id)->lockForUpdate()->firstOrFail();
            abort_unless($call->isActive() && $call->initiated_at->lt(now()->subMinutes(5)), 422);
            $call->update(['resolved_at' => now(), 'status' => 'unknown', 'customer_status' => 'unknown', 'error_message' => 'Manually released after provider review. Outcome remains unknown.']);
            AuditLog::create(['user_id' => $r->user()->id, 'action' => 'call.released', 'description' => 'Call #'.$call->id.': '.$data['reason']]);
        });

        return back()->with('success', 'Call lock released. Its outcome remains unknown and has not been counted as connected.');
    }
}
