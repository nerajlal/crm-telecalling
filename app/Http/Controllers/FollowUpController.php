<?php

namespace App\Http\Controllers;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Services\LeadService;
use App\Support\India;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FollowUpController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['filter' => 'nullable|in:pending,today,overdue,completed']);
        $filter = $r->input('filter', 'pending');
        $start = India::now()->startOfDay()->utc();
        $end = India::now()->addDay()->startOfDay()->utc();
        $items = FollowUp::visibleTo($r->user())->with('lead', 'employee')
            ->when($filter === 'completed', fn ($q) => $q->whereNotNull('completed_at'), fn ($q) => $q->whereNull('completed_at'))
            ->when($filter === 'today', fn ($q) => $q->where('due_at', '>=', $start)->where('due_at', '<', $end))
            ->when($filter === 'overdue', fn ($q) => $q->where('due_at', '<', now()))->orderBy('due_at')->paginate(15)->withQueryString();

        return view('follow-ups.index', compact('items', 'filter'));
    }

    public function store(Request $r, Lead $lead, LeadService $service)
    {
        $data = $r->validate(['due_at' => 'required|date_format:Y-m-d\TH:i', 'note' => 'required|string|max:2000']);
        $due = India::inputTime($data['due_at']);
        if ($due->isPast()) {
            throw ValidationException::withMessages(['due_at' => 'Choose a future time in IST.']);
        }
        DB::transaction(function () use ($r, $lead, $data, $due, $service) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->user()->isOwner() || $lead->assigned_to === $r->user()->id, 403);
            if (! $lead->assigned_to) {
                throw ValidationException::withMessages(['due_at' => 'Assign this lead to an employee first.']);
            }
            $lead->followUps()->create(['employee_id' => $lead->assigned_to, 'due_at' => $due, 'note' => $data['note']]);
            $service->activity($lead, $r->user(), 'follow-up', 'Follow-up scheduled for '.India::display($due).' IST. '.$data['note']);
        });

        return back()->with('success', 'Follow-up scheduled.');
    }

    public function update(Request $r, FollowUp $followUp, LeadService $service)
    {
        $data = $r->validate(['action' => 'required|in:complete,reschedule', 'due_at' => 'required_if:action,reschedule|nullable|date_format:Y-m-d\TH:i']);
        DB::transaction(function () use ($r, $followUp, $data, $service) {
            $lead = Lead::whereKey($followUp->lead_id)->lockForUpdate()->firstOrFail();
            $followUp = FollowUp::whereKey($followUp->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->user()->isOwner() || ($lead->assigned_to === $r->user()->id && $followUp->employee_id === $r->user()->id), 403);
            if ($followUp->completed_at) {
                throw ValidationException::withMessages(['action' => 'This follow-up is already completed.']);
            }
            if ($data['action'] === 'complete') {
                $followUp->update(['completed_at' => now()]);
                $message = 'Follow-up completed.';
            } else {
                $due = India::inputTime($data['due_at']);
                if ($due->isPast()) {
                    throw ValidationException::withMessages(['due_at' => 'Choose a future time in IST.']);
                }
                $followUp->update(['due_at' => $due]);
                $message = 'Follow-up rescheduled for '.India::display($due).' IST.';
            }
            $service->activity($lead, $r->user(), 'follow-up', $message);
        });

        return back()->with('success','Follow-up updated.');
    }
}
