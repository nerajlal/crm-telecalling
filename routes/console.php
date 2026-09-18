<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('calls:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('crm:backup')->dailyAt('02:00')->timezone('Asia/Kolkata')->withoutOverlapping();
