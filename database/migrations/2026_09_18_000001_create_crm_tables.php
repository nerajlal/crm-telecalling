<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('employee')->index();
            $t->string('phone', 20)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamp('last_login_at')->nullable();
        });
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone', 20)->unique();
            $t->string('source')->default('Manual');
            $t->string('stage')->default('New')->index();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('converted_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('calls', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('lead_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $t->string('provider');
            $t->string('provider_sid')->nullable()->unique();
            $t->string('callback_token', 64);
            $t->string('employee_phone', 20);
            $t->string('customer_phone', 20);
            $t->string('status')->default('initiating')->index();
            $t->string('customer_status')->default('pending');
            $t->string('employee_leg_status')->nullable();
            $t->string('customer_leg_status')->nullable();
            $t->timestamp('initiated_at')->index();
            $t->timestamp('connected_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('provider_updated_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('call_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('call_id')->constrained()->cascadeOnDelete();
            $t->string('fingerprint', 64)->unique();
            $t->json('payload');
            $t->timestamp('processed_at')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
        });
        Schema::create('lead_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type');
            $t->text('description');
            $t->timestamps();
        });
        Schema::create('follow_ups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('due_at')->index();
            $t->text('note');
            $t->timestamp('completed_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('action');
            $t->text('description');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'follow_ups', 'lead_activities', 'call_events', 'calls', 'leads'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'phone', 'active', 'last_login_at']));
    }
};
