<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title','Dashboard') · TeleCRM</title><link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body>
<aside class="sidebar" id="sidebar">
<a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark"><x-icon name="phone" size="21"/></span>tele<span class="brand-light">crm</span><span class="brand-dot">.</span></a>
<div class="workspace"><span class="workspace-avatar">IN</span><div><strong>India workspace</strong><small>Team operations</small></div><span class="workspace-chevron">⌄</span></div>
<p class="nav-caption">WORKSPACE</p>
<nav aria-label="Main navigation">
@foreach([['dashboard','grid','Overview'],['leads.*','users','Leads'],['calls.*','phone','Call history'],['follow-ups.*','calendar','Follow-ups']] as [$pattern,$icon,$label])
@php($route=match($pattern){'dashboard'=>'dashboard','leads.*'=>'leads.index','calls.*'=>'calls.index',default=>'follow-ups.index'})
<a class="nav-link {{ request()->routeIs($pattern)?'selected':'' }}" href="{{ route($route) }}"><x-icon :name="$icon"/>{{ $label }}@if(request()->routeIs($pattern))<span class="nav-indicator"></span>@endif</a>
@endforeach
@if(auth()->user()->isOwner())
<p class="nav-caption nav-space">MANAGEMENT</p>
<a class="nav-link {{ request()->routeIs('employees.*')?'selected':'' }}" href="{{ route('employees.index') }}"><x-icon name="users"/>Employees</a>
<a class="nav-link {{ request()->routeIs('settings')?'selected':'' }}" href="{{ route('settings') }}"><x-icon name="settings"/>Settings</a>
@endif
</nav>
<div class="sidebar-bottom"><div class="local-time"><span class="online-dot"></span> India · All times in IST</div><a class="profile" href="{{ route('account') }}"><span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ ucfirst(auth()->user()->role) }} account</small></div><x-icon name="arrow" size="17"/></a></div>
</aside>
<div class="shell"><header class="topbar"><div class="breadcrumb"><button type="button" class="icon-button mobile-menu" data-menu aria-label="Toggle navigation"><x-icon name="menu"/></button><span>Workspace</span><span class="slash">/</span><strong>@yield('title','Overview')</strong></div><div class="topbar-right"><span class="today-label">{{ \App\Support\India::now()->format('D, d M Y') }}</span><form method="post" action="{{ route('logout') }}">@csrf<button class="icon-button" aria-label="Sign out" title="Sign out"><x-icon name="logout"/></button></form></div></header>
<main>
@if(\App\Models\Call::demoEnabled())<div class="demo-banner"><span class="demo-tag">DEMO</span> Calling is simulated. No real phone calls are placed.<span class="demo-tail">Explore your workspace</span></div>@endif
@if(session('success'))<div class="alert success" role="status"><x-icon name="check"/>{{ session('success') }}</div>@endif
@if(session('warning'))<div class="alert warning" role="status">{{ session('warning') }}</div>@endif
@if($errors->any())<div class="alert error" role="alert"><div><strong>Please check the following</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
@yield('content')
<footer class="page-footer"><span>TeleCRM · A clearer view of every conversation.</span><span>India workspace · IST</span></footer>
</main></div></body></html>
