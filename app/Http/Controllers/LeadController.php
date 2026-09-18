<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use App\Support\India;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100', 'stage' => ['nullable', Rule::in(Lead::STAGES)], 'employee' => 'nullable|integer', 'attention' => 'nullable|in:never']);
        $leads = Lead::visibleTo($r->user())->with('assignee')->withCount(['calls' => fn ($q) => $q->forReporting()])->withMin(['followUps as next_follow_up' => fn ($q) => $q->whereNull('completed_at')], 'due_at')
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->input('q').'%')->orWhere('phone', 'like', '%'.$r->input('q').'%')))
            ->when($r->filled('stage'), fn ($q) => $q->where('stage', $r->input('stage')))
            ->when($r->user()->isOwner() && $r->filled('employee'), fn ($q) => $q->where('assigned_to', $r->input('employee')))
            ->when($r->input('attention') === 'never', fn ($q) => $q->whereDoesntHave('calls', fn ($calls) => $calls->forReporting()))
            ->latest()->paginate(15)->withQueryString();

        return view('leads.index', ['leads' => $leads, 'employees' => User::where('role', 'employee')->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('leads.form', ['lead' => new Lead(['stage' => 'New', 'source' => 'Manual']), 'employees' => $this->employees()]);
    }

    public function store(Request $r, LeadService $service)
    {
        $lead = $service->save(null, $this->validated($r), $r->user());

        return redirect()->route('leads.show', $lead)->with('success', 'Lead added.');
    }

    public function show(Request $r, Lead $lead)
    {
        $this->authorizeLead($r, $lead);

        return view('leads.show', ['lead' => $lead->load('assignee'), 'calls' => $lead->calls()->inCurrentMode()->with('employee')->latest('initiated_at')->paginate(10, ['*'], 'calls_page'),
            'activities' => $lead->activities()->with('user')->latest()->paginate(15, ['*'], 'activity_page'),
            'followUps' => $lead->followUps()->with('employee')->orderByRaw('completed_at IS NOT NULL')->orderBy('due_at')->get()]);
    }

    public function edit(Request $r, Lead $lead)
    {
        $this->authorizeLead($r, $lead);

        return view('leads.form', ['lead' => $lead, 'employees' => $this->employees()]);
    }

    public function update(Request $r, Lead $lead, LeadService $service)
    {
        $this->authorizeLead($r, $lead);
        $service->save($lead, $this->validated($r, $lead), $r->user());

        return redirect()->route('leads.show', $lead)->with('success', 'Lead updated.');
    }

    public function note(Request $r, Lead $lead, LeadService $service)
    {
        $data = $r->validate(['note' => 'required|string|max:5000']);
        DB::transaction(function () use ($r, $lead, $service, $data) {
            $locked = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $this->authorizeLead($r, $locked);
            $service->activity($locked, $r->user(), 'note', $data['note']);
        });

        return back()->with('success', 'Note saved.');
    }

    public function importForm()
    {
        return view('leads.import', ['employees' => $this->employees()]);
    }

    public function sample()
    {
        return response("name,phone,source\nExample Lead,9876543210,Website\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="lead-import-template.csv"']);
    }

    public function import(Request $r, LeadService $service)
    {
        $r->validate(['file' => 'required|file|mimes:csv,txt|max:2048', 'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('role', 'employee')->where('active', true)]]);
        $handle = fopen($r->file('file')->getRealPath(), 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        if (! $headers) {
            return back()->withErrors(['file' => 'The CSV is empty.']);
        }
        $headers = array_map(fn ($v) => strtolower(trim(ltrim($v, "\xEF\xBB\xBF"))), $headers);
        if (count(array_unique($headers)) !== count($headers) || array_diff(['name', 'phone'], $headers)) {
            fclose($handle);

            return back()->withErrors(['file' => 'CSV must have unique name and phone columns. source is optional.']);
        }
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
            if (count($rows) > 1000) {
                break;
            }
        }
        fclose($handle);
        if (count($rows) > 1000) {
            return back()->withErrors(['file' => 'Import at most 1,000 rows per file. No rows were imported.']);
        }
        $imported = 0;
        $skipped = [];
        foreach ($rows as $i => $row) {
            if ($row === [null]) {
                continue;
            }
            $line = $i + 2;
            if (count($row) !== count($headers)) {
                $skipped[] = "Row $line: wrong number of columns.";

                continue;
            }
            $data = array_combine($headers, $row);
            try {
                $data['phone'] = India::phone($data['phone']);
                $clean = Validator::make($data, ['name' => 'required|string|max:255', 'phone' => 'required|unique:leads,phone', 'source' => 'nullable|string|max:100'])->validate();
                $clean['source'] = $clean['source'] ?? 'CSV import';
                $clean['assigned_to'] = $r->input('assigned_to');
                $clean['stage'] = 'New';
                $service->save(null, $clean, $r->user());
                $imported++;
            } catch (ValidationException $e) {
                $skipped[] = "Row $line: ".collect($e->errors())->flatten()->first();
            } catch (UniqueConstraintViolationException $e) {
                $skipped[] = "Row $line: duplicate phone number.";
            }
        }

        return back()->with('import_result', ['imported' => $imported, 'skipped' => $skipped])->with('success', "Imported $imported leads.");
    }

    private function validated(Request $r, ?Lead $lead = null): array
    {
        if (! $r->user()->isOwner()) {
            return $r->validate(['stage' => ['required', Rule::in(Lead::STAGES)]]);
        }
        $r->validate(['phone' => 'required|string|max:40']);
        $r->merge(['phone' => India::phone($r->input('phone'))]);

        return $r->validate(['name' => 'required|string|max:255', 'phone' => ['required', Rule::unique('leads', 'phone')->ignore($lead?->id)], 'source' => 'required|string|max:100', 'stage' => ['required', Rule::in(Lead::STAGES)], 'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('active', true)->where('role', 'employee')]]);
    }

    private function employees()
    {
        return User::where('role', 'employee')->where('active', true)->orderBy('name')->get();
    }

    public function authorizeLead(Request $r, Lead $lead): void
    {
        abort_unless($r->user()->isOwner() || $lead->assigned_to === $r->user()->id, 403);
    }
}
