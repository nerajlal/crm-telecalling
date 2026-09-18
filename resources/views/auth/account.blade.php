@extends('layouts.app')
@section('title','My account')
@section('content')
<div class="page-heading"><div><h1>My account</h1><p>Keep your workspace access secure.</p></div></div>
<div class="panel form-panel"><div class="panel-heading"><div><h2>{{ auth()->user()->name }}</h2><p>{{ auth()->user()->email }} · {{ ucfirst(auth()->user()->role) }}</p></div></div><form class="stack panel-body" method="post" action="{{ route('account.password') }}">@csrf @method('put')<h3>Change password</h3><label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label><label>New password<input type="password" name="password" autocomplete="new-password" minlength="12" required><small>At least 12 characters.</small></label><label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required></label><button class="button primary">Update password</button></form></div>
@endsection
