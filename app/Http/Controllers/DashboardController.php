<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Support\India;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function __invoke(Request $r)
    {
        $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from = $r->input('from', India::now()->format('Y-m-d'));
        $to = $r->input('to', India::now()->format('Y-m-d'));
        $start = CarbonImmutable::parse($from, 'Asia/Kolkata')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Kolkata')->addDay()->startOfDay()->utc();
        if ($start->gte($end)) {
            throw ValidationException::withMessages(['to' => 'End date must be on or after start date.']);
        }
        $requests = Call::visibleTo($r->user())->where('initiated_at', '>=', $start)->where('initiated_at', '<', $end);
        $calls = (clone $requests)->forReporting();
        $unverified = (clone $requests)->whereNull('last_synced_at')->count();
        $leads = Lead::visibleTo($r->user());
        $follow = FollowUp::visibleTo($r->user())->whereNull('completed_at');
        $todayStart = India::now()->startOfDay()->utc();
        $tomorrow = India::now()->addDay()->startOfDay()->utc();
        $stats = ['attempts' => (clone $calls)->count(), 'connected' => (clone $calls)->where('customer_status', 'connected')->count(),
            'talk' => (int) (clone $calls)->where('customer_status', 'connected')->sum('duration_seconds'),
            'conversions' => (clone $leads)->where('converted_at', '>=', $start)->where('converted_at', '<', $end)->count(),
            'never' => (clone $leads)->whereDoesntHave('calls', fn ($q) => $q->forReporting())->count(), 'overdue' => (clone $follow)->where('due_at', '<', now())->count(),
            'due' => (clone $follow)->where('due_at', '>=', $todayStart)->where('due_at', '<', $tomorrow)->count(),
            'total_leads' => (clone $leads)->count()];
        $stageCounts = (clone $leads)->selectRaw('stage, COUNT(*) AS total')->groupBy('stage')->pluck('total', 'stage');
        $performance = User::where('role', 'employee')->when(! $r->user()->isOwner(), fn ($q) => $q->whereKey($r->user()->id))->get()->map(function ($employee) use ($calls) {
            $q = (clone $calls)->where('employee_id', $employee->id);

            return ['employee' => $employee, 'attempts' => (clone $q)->count(), 'connected' => (clone $q)->where('customer_status', 'connected')->count(), 'talk' => (int) (clone $q)->where('customer_status', 'connected')->sum('duration_seconds')];
        })->sortByDesc('attempts');
        $recent = (clone $requests)->with('lead', 'employee')->latest('initiated_at')->limit(6)->get();
        $due = (clone $follow)->with('lead', 'employee')->orderBy('due_at')->limit(5)->get();

        return view('dashboard', compact('stats', 'stageCounts', 'performance', 'recent', 'due', 'from', 'to', 'unverified'));
    }
}
