<x-mail::message>
# Your week, {{ $user->name }}

## 💶 Money

@php($b = $shared['budget'])
You spent **{{ $money($shared['spent_this_week']) }}** this week.
@if ($b['forecast'] !== null && $b['budget'] !== null)
At this pace the month ends at **{{ $money($b['forecast']) }}** of your **{{ $money($b['budget']) }}** budget{{ $b['over_budget'] > 0 ? ', '.$money($b['over_budget']).' over' : '' }}.
@elseif ($b['forecast'] !== null)
At this pace the month ends at about **{{ $money($b['forecast']) }}**.
@endif

## 🧹 Chores

@if ($shared['overdue'])
<x-mail::table>
| Chore | Every | Overdue by |
|:--|--:|--:|
@foreach ($shared['overdue'] as $c)
| {{ $c['room'] }}: {{ $c['name'] }} | {{ $c['every_days'] }} days | {{ $c['days_overdue'] }} {{ Str::plural('day', $c['days_overdue']) }} |
@endforeach
</x-mail::table>
@else
Nothing overdue. 🎉
@endif

@foreach ($shared['learned'] as $l)
- **{{ $l['chore'] }}** now repeats every {{ $l['to'] }} days instead of {{ $l['from'] }}, to match what you really do. Undo it in the app if you'd rather keep it.
@endforeach

## 🥗 Your food and water

@if ($nutrition['logged_days'] === 0 && $nutrition['water_days'] === 0)
Nothing logged this week.
@elseif ($nutrition['gaps'])
Over the {{ $nutrition['logged_days'] }} days you logged:
@foreach ($nutrition['gaps'] as $g)
- **{{ ['protein' => 'Protein', 'fiber' => 'Fibre', 'water' => 'Water'][$g['key']] }}** averaged {{ $g['key'] === 'water' ? number_format($g['average']).' ml' : $g['average'].' g' }} a day, {{ round($g['ratio'] * 100) }}% of {{ $g['key'] === 'water' ? number_format($g['target']).' ml' : $g['target'].' g' }}.
@endforeach
@else
No gaps in protein, fibre or water over the {{ $nutrition['logged_days'] }} days you logged.
@endif

Only you get this part: other members of {{ $household->name }} don't see your food and water.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
