<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Call;
use App\Models\User;
use App\Services\LeadService;
use App\Support\India;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function index()
    {
        return view('employees.index', ['employees' => User::where('role', 'employee')->withCount('leads')->orderBy('name')->paginate(20)]);
    }

    public function create()
    {
        return view('employees.form', ['employee' => new User(['active' => true]), 'others' => collect()]);
    }

    public function store(Request $r)
    {
        $data = $this->validated($r);
        $data['role'] = 'employee';
        User::create($data);
        AuditLog::create(['user_id' => $r->user()->id, 'action' => 'employee.created', 'description' => 'Created employee '.$data['email']]);

        return redirect()->route('employees.index')->with('success', 'Employee created. Share their login securely.');
    }

    public function edit(User $employee)
    {
        abort_unless($employee->role === 'employee', 404);

        return view('employees.form', ['employee' => $employee, 'others' => User::where('role', 'employee')->where('active', true)->where('id', '!=', $employee->id)->get()]);
    }

    public function update(Request $r, User $employee, LeadService $service)
    {
        abort_unless($employee->role === 'employee', 404);
        $data = $this->validated($r, $employee);
        $r->validate(['reassign_to' => ['nullable', 'different:employee', Rule::exists('users', 'id')->where('active', true)->where('role', 'employee')]]);
        DB::transaction(function () use ($r, $employee, $data, $service) {
            $target = $r->integer('reassign_to');
            User::whereIn('id', array_filter([$employee->id, $target]))->orderBy('id')->lockForUpdate()->get();
            $employee->refresh();
            if (! $data['active'] && $employee->leads()->exists()) {
                if (! $target || $target === $employee->id) {
                    throw ValidationException::withMessages(['reassign_to' => 'Choose an active employee to receive all assigned leads and pending follow-ups.']);
                }
                if (Call::where('employee_id', $employee->id)->whereIn('status', Call::ACTIVE)->whereNull('resolved_at')->exists()) {
                    throw ValidationException::withMessages(['active' => 'Finish or resolve active calls before deactivating.']);
                }
                foreach ($employee->leads()->get() as $lead) {
                    $service->save($lead, ['assigned_to' => $target], $r->user());
                }
            }
            $employee->update($data);
            if (! $employee->active || ! empty($data['password'])) {
                DB::table('sessions')->where('user_id', $employee->id)->delete();
            }
            AuditLog::create(['user_id' => $r->user()->id, 'action' => 'employee.updated', 'description' => 'Updated employee '.$employee->email.'; active: '.($employee->active ? 'yes' : 'no')]);
        });

        return redirect()->route('employees.index')->with('success', 'Employee updated.');
    }

    private function validated(Request $r, ?User $employee = null): array
    {
        $r->validate(['phone' => 'required|string|max:40']);
        $r->merge(['phone' => India::phone($r->input('phone')), 'email' => strtolower($r->input('email', ''))]);
        $data = $r->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($employee?->id)], 'phone' => 'required', 'password' => [$employee ? 'nullable' : 'required', 'string', 'min:12', 'max:128', 'confirmed'], 'active' => 'required|boolean']);
        if (empty($data['password'])) {
            unset($data['password']);
        }

return $data;
    }
}
