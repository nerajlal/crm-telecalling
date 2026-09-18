<?php

namespace Tests\Feature;

use App\Jobs\ProcessCallEvent;
use App\Models\Call;
use App\Models\CallEvent;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\CallService;
use App\Services\LeadService;
use App\Support\India;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['telephony.driver' => 'demo']);
        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'active' => true]);
    }

    private function employee(array $extra = []): User
    {
        return User::factory()->create(array_merge(['role' => 'employee', 'active' => true, 'phone' => '+919876543210'], $extra));
    }

    private function lead(User $employee, array $extra = []): Lead
    {
        return Lead::create(array_merge(['name' => 'Test Lead', 'phone' => '+919876543211', 'source' => 'Website', 'stage' => 'New', 'assigned_to' => $employee->id], $extra));
    }

    private function makeCall(User $employee, Lead $lead, array $extra = []): Call
    {
        return Call::create(array_merge(['request_key' => (string) Str::uuid(), 'lead_id' => $lead->id, 'employee_id' => $employee->id, 'provider' => 'exotel', 'provider_sid' => 'sid123', 'callback_token' => Str::random(64), 'employee_phone' => $employee->phone, 'customer_phone' => $lead->phone, 'initiated_at' => now()->subMinute(), 'status' => 'in-progress'], $extra));
    }

    private function exotelConfig(): void
    {
        config(['telephony.driver' => 'exotel', 'telephony.live_enabled' => true, 'telephony.exotel.account_sid' => 'account', 'telephony.exotel.api_key' => 'key', 'telephony.exotel.api_token' => 'token', 'telephony.exotel.caller_id' => '08012345678', 'telephony.exotel.callback_base_url' => 'https://crm.example.test']);
    }

    private function details(Call $call, array $extra = []): array
    {
        return array_replace_recursive(['Sid' => $call->provider_sid ?: 'sid123', 'AccountSid' => 'account', 'From' => $call->employee_phone, 'To' => $call->customer_phone, 'DateCreated' => India::display($call->initiated_at, 'Y-m-d H:i:s'), 'DateUpdated' => India::display(now(), 'Y-m-d H:i:s'), 'Status' => 'completed', 'EndTime' => India::display(now(), 'Y-m-d H:i:s'), 'Duration' => 95, 'Details' => ['Leg1Status' => 'completed', 'Leg2Status' => 'completed', 'ConversationDuration' => 60]], $extra);
    }

    public function test_authentication_and_inactive_accounts(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $user = $this->employee(['password' => 'GoodPassword123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'GoodPassword123'])->assertRedirect('/dashboard');
        $user->update(['active' => false]);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $data = ['email' => 'unknown@example.test', 'password' => 'incorrect'];
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', $data);
        }
        $this->post('/login', $data)->assertSessionHasErrors('email', fn ($message) => str_contains($message, 'Too many'));
    }

    public function test_owner_pages_render_and_employee_is_scoped(): void
    {
        $owner = $this->owner();
        $employee = $this->employee();
        $other = $this->employee();
        $own = $this->lead($employee);
        $hidden = $this->lead($other, ['name' => 'Private Contact', 'phone' => '+919876543212']);
        $this->actingAs($owner);
        foreach (['/dashboard', '/leads', '/leads/create', '/leads/import', '/calls', '/follow-ups', '/employees', '/employees/create', '/settings', '/account', '/leads/'.$own->id, '/leads/'.$own->id.'/edit', '/employees/'.$employee->id.'/edit'] as $page) {
            $this->get($page)->assertOk();
        }
        $this->actingAs($employee)->get('/leads')->assertSee('Test Lead')->assertDontSee('Private Contact');
        foreach (['/leads/'.$hidden->id, '/leads/'.$hidden->id.'/edit', '/employees', '/settings', '/leads/create'] as $page) {
            $this->get($page)->assertForbidden();
        }
        $this->put('/leads/'.$hidden->id, ['stage' => 'Converted'])->assertForbidden();
        $this->post('/leads/'.$hidden->id.'/notes', ['note' => 'bad'])->assertForbidden();
        $this->post('/leads/'.$hidden->id.'/calls', ['request_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_lead_creation_normalization_duplicates_and_india_only(): void
    {
        $owner = $this->owner();
        $employee = $this->employee();
        $this->actingAs($owner);
        $data = ['name' => 'New person', 'phone' => '98765 43211', 'source' => 'Website', 'stage' => 'New', 'assigned_to' => $employee->id];
        $this->post('/leads', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leads', ['phone' => '+919876543211']);
        $this->post('/leads', $data)->assertSessionHasErrors('phone');
        $this->post('/leads', array_replace($data, ['phone' => '+14155552671']))->assertSessionHasErrors('phone');
        $this->assertSame(1, Lead::count());
    }

    public function test_employee_cannot_change_owner_fields(): void
    {
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $other = $this->employee();
        $this->actingAs($employee)->put('/leads/'.$lead->id, ['stage' => 'Qualified', 'name' => 'Changed', 'phone' => '+919876543299', 'assigned_to' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame('Qualified', $lead->fresh()->stage);
        $this->assertSame('Test Lead', $lead->fresh()->name);
        $this->assertSame($employee->id, $lead->fresh()->assigned_to);
    }

    public function test_import_reports_bad_and_duplicate_rows(): void
    {
        $employee = $this->employee();
        $this->lead($employee);
        $file = UploadedFile::fake()->createWithContent('leads.csv', "name,phone,source\nGood,9876543222,Referral\nDuplicate,9876543211,Website\nForeign,+14155552671,Website\nMissing,,Web\n");
        $response = $this->actingAs($this->owner())->post('/leads/import', ['file' => $file, 'assigned_to' => $employee->id]);
        $response->assertSessionHasNoErrors()->assertSessionHas('import_result', fn ($result) => $result['imported'] === 1 && count($result['skipped']) === 3);
        $this->assertSame(2, Lead::count());
    }

    public function test_reassignment_transfers_pending_work_and_preserves_calls(): void
    {
        $employee = $this->employee();
        $next = $this->employee();
        $owner = $this->owner();
        $lead = $this->lead($employee);
        $call = $this->makeCall($employee, $lead, ['status' => 'completed']);
        $follow = FollowUp::create(['lead_id' => $lead->id, 'employee_id' => $employee->id, 'due_at' => now()->addDay(), 'note' => 'callback']);
        app(LeadService::class)->save($lead, ['assigned_to' => $next->id], $owner);
        $this->assertSame($next->id, $follow->fresh()->employee_id);
        $this->assertSame($employee->id, $call->fresh()->employee_id);
        $this->actingAs($employee)->get('/leads/'.$lead->id)->assertForbidden();
        $this->get('/calls')->assertDontSee('Test Lead');
    }

    public function test_deactivation_requires_replacement_and_revokes_access(): void
    {
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $next = $this->employee();
        $data = ['name' => $employee->name, 'email' => $employee->email, 'phone' => $employee->phone, 'active' => 0];
        $this->actingAs($this->owner())->put('/employees/'.$employee->id, $data)->assertSessionHasErrors('reassign_to');
        $this->put('/employees/'.$employee->id, $data + ['reassign_to' => $next->id])->assertSessionHasNoErrors();
        $this->assertFalse($employee->fresh()->active);
        $this->assertSame($next->id, $lead->fresh()->assigned_to);
    }

    public function test_follow_up_times_are_ist_and_completion_is_explicit(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 18)->setTime(5, 0));
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $this->actingAs($employee)->post('/leads/'.$lead->id.'/follow-ups', ['due_at' => '2026-09-18T15:00', 'note' => 'Discuss proposal'])->assertSessionHasNoErrors();
        $follow = FollowUp::first();
        $this->assertSame('2026-09-18 09:30:00', $follow->due_at->format('Y-m-d H:i:s'));
        $this->put('/follow-ups/'.$follow->id, ['action' => 'reschedule', 'due_at' => '2026-09-19T15:00'])->assertSessionHasNoErrors();
        $this->assertNull($follow->fresh()->completed_at);
        $this->put('/follow-ups/'.$follow->id, ['action' => 'complete'])->assertSessionHasNoErrors();
        $this->assertNotNull($follow->fresh()->completed_at);
    }

    public function test_duplicate_call_clicks_are_idempotent_and_demo_is_explicit(): void
    {
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $key = (string) Str::uuid();
        $this->actingAs($employee)->post('/leads/'.$lead->id.'/calls', ['request_key' => $key])->assertSessionHasNoErrors();
        $this->post('/leads/'.$lead->id.'/calls', ['request_key' => $key])->assertSessionHasNoErrors();
        $this->post('/leads/'.$lead->id.'/calls', ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('call');
        $this->assertSame(1, Call::count());
        $call = Call::first();
        $this->assertSame('pending', $call->customer_status);
        $this->post('/calls/'.$call->id.'/demo', ['outcome' => 'connected', 'duration' => 125])->assertSessionHasNoErrors();
        $this->assertSame(125, $call->fresh()->duration_seconds);
        $this->assertSame('connected', $call->fresh()->customer_status);
        Http::assertNothingSent();
    }

    public function test_exotel_request_and_timeout_are_not_retried(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        Http::fake(['*' => Http::failedConnection()]);
        $this->actingAs($employee)->post('/leads/'.$lead->id.'/calls', ['request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('unknown', Call::first()->status);
        $this->post('/leads/'.$lead->id.'/calls', ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('call');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['From'] === $employee->phone && $request['To'] === $lead->phone && $request['Record'] === 'false' && str_starts_with($request['StatusCallback'], 'https://'));
    }

    public function test_webhook_is_token_protected_deduplicated_and_queued(): void
    {
        Queue::fake();
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $call = $this->makeCall($employee, $lead);
        $url = '/webhooks/exotel/'.$call->id.'/'.$call->callback_token;
        $this->postJson('/webhooks/exotel/'.$call->id.'/bad', ['CallSid' => 'sid123'])->assertForbidden();
        $payload = ['CallSid' => 'sid123', 'Status' => 'completed', 'DateUpdated' => '2026-09-18 15:00:00', 'ConversationDuration' => 99999];
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(1, CallEvent::count());
        $this->assertArrayNotHasKey('ConversationDuration', CallEvent::first()->payload);
        $this->assertSame('pending', $call->fresh()->customer_status);
        Queue::assertPushed(ProcessCallEvent::class, 1);
    }

    public function test_customer_connection_and_duration_come_from_verified_second_leg(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $call = $this->makeCall($employee, $lead);
        $data = $this->details($call, ['Details' => ['Leg1Status' => 'completed', 'Leg2Status' => 'no-answer', 'ConversationDuration' => 0]]);
        Http::fake(['*' => Http::sequence()->push(['Call' => $data])->push(['Call' => $this->details($call)])->push(['Call' => $this->details($call, ['Status' => 'in-progress', 'DateUpdated' => India::display(now()->subMinute(), 'Y-m-d H:i:s')])])]);
        app(CallService::class)->sync($call);
        $this->assertSame('no-answer', $call->fresh()->customer_status);
        $this->assertSame(0, $call->fresh()->duration_seconds);
        app(CallService::class)->sync($call->fresh());
        $this->assertSame('connected', $call->fresh()->customer_status);
        $this->assertSame(60, $call->fresh()->duration_seconds);
        $older = $this->details($call, ['Status' => 'in-progress', 'DateUpdated' => India::display(now()->subMinute(), 'Y-m-d H:i:s')]);
        app(CallService::class)->sync($call->fresh());
        $this->assertSame('completed', $call->fresh()->status);
    }

    public function test_total_duration_does_not_become_talk_time(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $call = $this->makeCall($employee, $lead);
        $data = $this->details($call);
        unset($data['Details']);
        Http::fake(['*' => Http::response(['Call' => $data])]);
        app(CallService::class)->sync($call);
        $this->assertNull($call->fresh()->duration_seconds);
        $this->assertSame('unknown', $call->fresh()->customer_status);
    }

    public function test_provider_identity_mismatch_is_rejected(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $call = $this->makeCall($employee, $lead);
        Http::fake(['*' => Http::response(['Call' => $this->details($call, ['To' => '+919876543299'])])]);
        $this->expectException(\RuntimeException::class);
        app(CallService::class)->sync($call);
    }

    public function test_dashboard_date_boundaries_use_ist(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $this->makeCall($employee, $lead, ['provider_sid' => 'first', 'last_synced_at' => now(), 'status' => 'completed', 'customer_status' => 'connected', 'duration_seconds' => 80, 'initiated_at' => '2026-09-17 18:30:00']);
        $this->makeCall($employee, $lead, ['provider_sid' => 'second', 'last_synced_at' => now(), 'status' => 'completed', 'customer_status' => 'connected', 'duration_seconds' => 20, 'initiated_at' => '2026-09-18 18:29:59']);
        $this->makeCall($employee, $lead, ['provider_sid' => 'third', 'last_synced_at' => now(), 'status' => 'completed', 'customer_status' => 'connected', 'duration_seconds' => 900, 'initiated_at' => '2026-09-18 18:30:00']);
        $this->actingAs($this->owner())->get('/dashboard?from=2026-09-18&to=2026-09-18')->assertOk()->assertViewHas('stats', fn ($stats) => $stats['attempts'] === 2 && $stats['talk'] === 100);
    }

    public function test_notes_are_escaped_and_followups_are_private(): void
    {
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $other = $this->employee();
        $this->actingAs($employee)->post('/leads/'.$lead->id.'/notes', ['note' => '<script>alert(1)</script>']);
        $this->get('/leads/'.$lead->id)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $follow = FollowUp::create(['lead_id' => $lead->id, 'employee_id' => $employee->id, 'due_at' => now()->addHour(), 'note' => 'Private']);
        $this->actingAs($other)->put('/follow-ups/'.$follow->id, ['action' => 'complete'])->assertForbidden();
    }

    public function test_owner_edit_with_unchanged_string_assignee_does_not_reassign_active_call(): void
    {
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $this->makeCall($employee, $lead);
        $this->actingAs($this->owner())->put('/leads/'.$lead->id, ['name' => $lead->name, 'phone' => $lead->phone, 'source' => $lead->source, 'stage' => 'Contacted', 'assigned_to' => (string) $employee->id])->assertSessionHasNoErrors();
        $this->assertSame('Contacted', $lead->fresh()->stage);
    }

    public function test_incomplete_provider_read_does_not_erase_verified_outcome(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $call = $this->makeCall($employee, $lead);
        $complete = $this->details($call);
        $partial = $complete;
        unset($partial['Details']);
        Http::fake(['*' => Http::sequence()->push(['Call' => $complete])->push(['Call' => $partial])]);
        app(CallService::class)->sync($call);
        app(CallService::class)->sync($call->fresh());
        $this->assertSame('connected', $call->fresh()->customer_status);
        $this->assertSame(60, $call->fresh()->duration_seconds);
    }

    public function test_live_call_cannot_be_simulated_and_employee_cannot_reconcile(): void
    {
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $call = $this->makeCall($employee, $lead);
        $this->actingAs($employee)->post('/calls/'.$call->id.'/demo', ['outcome' => 'connected', 'duration' => 100])->assertNotFound();
        $this->post('/calls/'.$call->id.'/reconcile')->assertForbidden();
        $this->post('/calls/'.$call->id.'/resolve', ['reason' => 'Verified with provider', 'confirmed' => 1])->assertForbidden();
        $this->assertSame('pending', $call->fresh()->customer_status);
    }

    public function test_owner_releases_unknown_call_without_faking_a_result(): void
    {
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $call = $this->makeCall($employee, $lead, ['initiated_at' => now()->subMinutes(10), 'status' => 'unknown']);
        $this->actingAs($this->owner())->post('/calls/'.$call->id.'/resolve', ['reason' => 'Checked provider portal; no active call remains.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('unknown', $call->fresh()->customer_status);
        $this->assertNotNull($call->fresh()->resolved_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'call.released']);
    }

    public function test_successful_exotel_initiation_and_callback_worker(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        Http::fake(['*/connect.json' => Http::response(['Call' => ['Sid' => 'newSid']], 200), '*/newSid.json*' => Http::response(['Call' => [
            'Sid' => 'newSid', 'AccountSid' => 'account', 'From' => $employee->phone, 'To' => $lead->phone,
            'DateCreated' => India::display(now(), 'Y-m-d H:i:s'), 'DateUpdated' => India::display(now(), 'Y-m-d H:i:s'),
            'Status' => 'completed', 'EndTime' => India::display(now(), 'Y-m-d H:i:s'),
            'Details' => ['Leg1Status' => 'completed', 'Leg2Status' => 'completed', 'ConversationDuration' => 45],
        ]])]);
        $this->actingAs($employee)->post('/leads/'.$lead->id.'/calls', ['request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $call = Call::first();
        $this->assertSame('newSid', $call->provider_sid);
        $this->assertSame('queued', $call->status);
        $this->postJson('/webhooks/exotel/'.$call->id.'/'.$call->callback_token, ['CallSid' => 'newSid', 'Status' => 'completed'])->assertOk();
        $this->assertNotNull(CallEvent::first()->processed_at);
        $this->assertSame(45, $call->fresh()->duration_seconds);
        $this->assertSame('connected', $call->fresh()->customer_status);
    }

    public function test_call_payload_cannot_expose_provider_secrets(): void
    {
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $call = $this->makeCall($employee, $lead);
        $this->assertArrayNotHasKey('callback_token', $call->toArray());
        config(['telephony.exotel.api_token' => 'DO-NOT-SHOW-THIS']);
        $this->actingAs($this->owner())->get('/settings')->assertOk()->assertDontSee('DO-NOT-SHOW-THIS');
    }

    public function test_provider_http_timeout_remains_unknown(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        Http::fake(['*' => Http::response([], 408)]);
        $this->actingAs($employee)->post('/leads/'.$lead->id.'/calls', ['request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('unknown', Call::first()->status);
        Http::assertSentCount(1);
    }

    public function test_employee_cannot_submit_manual_call_evidence(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $this->actingAs($employee)->post('/leads/'.$lead->id.'/calls', [
            'request_key' => (string) Str::uuid(), 'duration_seconds' => 900,
            'status' => 'completed', 'customer_status' => 'connected',
            'initiated_at' => '2026-09-18 10:00:00', 'provider_sid' => 'invented',
            'employee_id' => $employee->id, 'customer_phone' => '+919876543299',
        ])->assertSessionHasErrors(['duration_seconds', 'status', 'customer_status', 'initiated_at', 'provider_sid', 'employee_id', 'customer_phone']);
        $this->assertSame(0, Call::count());
        Http::assertNothingSent();
    }

    public function test_live_reports_exclude_demo_and_unverified_requests_even_with_claimed_outcomes(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $this->makeCall($employee, $lead, ['provider_sid' => 'verified', 'last_synced_at' => now(), 'status' => 'completed', 'customer_status' => 'connected', 'duration_seconds' => 60]);
        $this->makeCall($employee, $lead, ['provider_sid' => 'unverified', 'status' => 'completed', 'customer_status' => 'connected', 'duration_seconds' => 999]);
        $this->makeCall($employee, $lead, ['provider' => 'demo', 'provider_sid' => 'demo-only', 'last_synced_at' => now(), 'status' => 'completed', 'customer_status' => 'connected', 'duration_seconds' => 5000]);
        $this->actingAs($this->owner())->get('/dashboard')->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['attempts'] === 1 && $stats['connected'] === 1 && $stats['talk'] === 60)
            ->assertViewHas('unverified', 1);
        $this->get('/calls')->assertViewHas('calls', fn ($calls) => $calls->total() === 2);
        $this->get('/leads/'.$lead->id)->assertViewHas('calls', fn ($calls) => $calls->total() === 2);
    }

    public function test_notes_and_reported_stages_are_not_call_evidence(): void
    {
        $this->exotelConfig();
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $this->actingAs($employee)->post('/leads/'.$lead->id.'/notes', ['note' => 'I called for 30 minutes.'])->assertSessionHasNoErrors();
        $this->put('/leads/'.$lead->id, ['stage' => 'Contacted'])->assertSessionHasNoErrors();
        $this->makeCall($employee, $lead, ['provider_sid' => 'pending', 'status' => 'queued']);
        $this->get('/dashboard')->assertViewHas('stats', fn ($stats) => $stats['attempts'] === 0 && $stats['connected'] === 0 && $stats['talk'] === 0 && $stats['never'] === 1);
        $this->get('/leads?attention=never')->assertSee('Test Lead');
    }

    public function test_demo_endpoints_are_disabled_in_staging_and_production(): void
    {
        $employee = $this->employee();
        $lead = $this->lead($employee);
        $call = $this->makeCall($employee, $lead, ['provider' => 'demo', 'provider_sid' => 'demo-test']);
        foreach (['staging', 'production'] as $environment) {
            $this->app->instance('env', $environment);
            $this->actingAs($employee)->withSession(['_token' => 'test-csrf-token'])->post('/calls/'.$call->id.'/demo', ['_token' => 'test-csrf-token', 'outcome' => 'connected', 'duration' => 500])->assertNotFound();
            $this->post('/leads/'.$lead->id.'/calls', ['_token' => 'test-csrf-token', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('call');
            $this->get('/dashboard')->assertViewHas('stats', fn ($stats) => $stats['attempts'] === 0 && $stats['connected'] === 0);
        }
        $this->assertNull($call->fresh()->duration_seconds);
        Http::assertNothingSent();
    }
}
