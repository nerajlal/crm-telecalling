<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function save(?Lead $lead, array $data, User $actor): Lead
    {
        return DB::transaction(function () use ($lead, $data, $actor) {
            // Lock target assignee before the lead; the same order is used by calls and deactivation.
            if (! empty($data['assigned_to'])) {
                $assignee = User::whereKey($data['assigned_to'])->lockForUpdate()->firstOrFail();
                if (! $assignee->active || $assignee->role !== 'employee') {
                    throw ValidationException::withMessages(['assigned_to' => 'Choose an active employee.']);
                }
            }
            $existing = $lead !== null;
            $lead = $existing ? Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail() : new Lead;
            abort_unless($actor->isOwner() || $lead->assigned_to === $actor->id, 403);
            $previous = $lead->stage;
            $previousAssignee = $lead->assigned_to;
            $lead->fill($data);
            if (! $existing || $previous !== $lead->stage) {
                $lead->converted_at = $lead->stage === 'Converted' ? now() : null;
            }
            if ($existing && $previousAssignee !== $lead->assigned_to && $lead->calls()->whereIn('status', Call::ACTIVE)->whereNull('resolved_at')->exists()) {
                throw ValidationException::withMessages(['assigned_to' => 'Finish or resolve the active call before reassigning this lead.']);
            }
            $lead->save();
            if (! $existing) {
                $this->activity($lead, $actor, 'created', 'Lead created.');
            } elseif ($previous !== $lead->stage) {
                $this->activity($lead, $actor, 'stage', "Stage changed from {$previous} to {$lead->stage}.");
            } else {
                $this->activity($lead, $actor, 'updated', 'Lead details updated.');
            }
            if ($previousAssignee !== $lead->assigned_to) {
                $this->activity($lead, $actor, 'assignment', 'Assigned to '.($lead->assignee?->name ?? 'Unassigned').'.');
                if ($lead->assigned_to) {
                    $lead->followUps()->whereNull('completed_at')->update(['employee_id' => $lead->assigned_to]);
                } elseif ($lead->followUps()->whereNull('completed_at')->exists()) {
                    throw ValidationException::withMessages(['assigned_to' => 'Assign an employee while pending follow-ups exist.']);
                }
            }

            return $lead;
        });
    }

    public function activity(Lead $lead, User $actor, string $type, string $description): void
    {
        $lead->activities()->create(['user_id' => $actor->id, 'type' => $type, 'description' => $description]);
    }
}
