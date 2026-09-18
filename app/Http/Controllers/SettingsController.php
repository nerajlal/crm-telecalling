<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Call;
use App\Models\CallEvent;
use App\Services\ExotelClient;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function __invoke(ExotelClient $provider)
    {
        return view('settings', ['ready' => $provider->ready(), 'pendingJobs' => DB::table('jobs')->count(), 'failedJobs' => DB::table('failed_jobs')->count(),
            'eventErrors' => CallEvent::whereNull('processed_at')->whereNotNull('error')->count(),
            'unresolved' => Call::with('lead', 'employee')->whereNull('resolved_at')->whereIn('status', Call::ACTIVE)->where('initiated_at', '<', now()->subMinutes(5))->latest()->limit(30)->get(),
            'audits' => AuditLog::with('user')->latest()->limit(15)->get()]);
    }
}
