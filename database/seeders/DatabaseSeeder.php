<?php

namespace Database\Seeders;

use App\Models\Call;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Support\India;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing') || config('telephony.driver') !== 'demo') {
            throw new \RuntimeException('Demo data can only be seeded locally with TELEPHONY_DRIVER=demo.');
        }
        if (User::exists()) {
            $this->command?->warn('Database already has users. Demo seeding skipped.');

            return;
        }
        $owner = User::create(['name' => 'Arjun Nair', 'email' => 'owner@telecrm.test', 'password' => 'DemoOwner!2026', 'role' => 'owner', 'active' => true]);
        $employees = collect();
        foreach ([['Anjali Menon', 'anjali'], ['Rahul Sharma', 'rahul'], ['Priya Nair', 'priya'], ['Adithya Kumar', 'adithya'], ['Sneha Thomas', 'sneha']] as $i => [$name,$email]) {
            $employees->push(User::create(['name' => $name, 'email' => $email.'@telecrm.test', 'password' => 'DemoEmployee!2026', 'role' => 'employee', 'phone' => '+91900000100'.$i, 'active' => true, 'last_login_at' => now()->subMinutes(10 + $i * 7)]));
        }
        $names = ['Meera Krishnan', 'Vikram Patel', 'Divya Suresh', 'Nikhil Joseph', 'Aisha Rahman', 'Sanjay Menon', 'Kavya Reddy', 'Rohan Das', 'Lakshmi Iyer', 'Arun Varma', 'Neha Kapoor', 'Deepak Nair', 'Sara Mathew', 'Vivek Rao', 'Pooja Shah', 'Kiran Babu', 'Anu George', 'Manoj Pillai', 'Reena Thomas', 'Ajay Kumar', 'Farah Ali', 'Gokul Raj', 'Shreya Joshi', 'Naveen Prasad'];
        $sources = ['Website', 'Referral', 'Facebook Ads', 'Walk-in', 'Google Ads'];
        $stages = ['New', 'Contacted', 'Follow-up', 'Qualified', 'Converted', 'Lost', 'Follow-up', 'New'];
        foreach ($names as $i => $name) {
            $employee = $employees[$i % 5];
            $stage = $stages[$i % 8];
            $lead = Lead::create(['name' => $name, 'phone' => '+91910000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'source' => $sources[$i % 5], 'stage' => $stage, 'assigned_to' => $employee->id, 'converted_at' => $stage === 'Converted' ? now()->subHours(1) : null, 'created_at' => now()->subDays(1 + $i % 12)]);
            $lead->activities()->create(['user_id' => $owner->id, 'type' => 'created', 'description' => 'Demo lead imported and assigned to '.$employee->name.'.', 'created_at' => $lead->created_at]);
            if ($stage !== 'New') {
                $lead->activities()->create(['user_id' => $employee->id, 'type' => 'note', 'description' => ['Interested in the service. Asked for a callback with more details.', 'Discussed requirements. Will review the proposal this week.', 'Reached out to understand their needs. Next step is a follow-up.'][$i % 3]]);
                foreach (range(0, $i % 3) as $j) {
                    $connected = $j > 0 || $i % 4 !== 0;
                    $time = India::now()->startOfDay()->addHours(9)->addMinutes(($i * 17 + $j * 8) % 180)->utc();
                    if ($time->isFuture()) {
                        $time = now()->subMinutes($i * 3 + $j + 10);
                    }
                    $duration = $connected ? 70 + $i * 13 + $j * 22 : 0;
                    Call::create(['request_key' => Str::uuid(), 'lead_id' => $lead->id, 'employee_id' => $employee->id, 'provider' => 'demo', 'provider_sid' => 'demo-'.Str::uuid(), 'callback_token' => Str::random(64), 'employee_phone' => $employee->phone, 'customer_phone' => $lead->phone, 'status' => $connected ? 'completed' : 'no-answer', 'customer_status' => $connected ? 'connected' : 'no-answer', 'initiated_at' => $time, 'connected_at' => $connected ? $time->addSeconds(12) : null, 'ended_at' => $time->addSeconds($duration + 12), 'duration_seconds' => $duration, 'last_synced_at' => now()]);
                }
            }
            if (in_array($stage, ['Follow-up', 'Qualified', 'Contacted'])) {
                $due = $i % 3 === 0 ? now()->subHours(2) : now()->addHours(($i % 4) + 1);
                FollowUp::create(['lead_id' => $lead->id, 'employee_id' => $employee->id, 'due_at' => $due, 'note' => ['Discuss requirements and next steps', 'Follow up on the proposal', 'Share pricing and answer questions'][$i % 3]]);
            }
        }
    }
}
