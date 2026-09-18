@props(['value'])
@php($tone=match($value){'Connected','connected','Converted','Active','completed'=>'green','New','queued','initiating'=>'blue','Follow-up','pending','in-progress','Qualified'=>'amber','Lost','failed','no-answer','Overdue','Inactive','unknown'=>'red',default=>'gray'})
<span class="badge {{ $tone }}"><span class="badge-dot"></span>{{ ucfirst(str_replace('-',' ',$value ?? 'Unknown')) }}</span>
