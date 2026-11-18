@extends('reports.pdf.layout')
@php($m = fn ($v) => \App\Support\Money::format($v))
@section('body')
@foreach (['income' => ['Income', 'Total income'], 'expense' => ['Expenses', 'Total expenses']] as $type => [$label, $totalLabel])
    @if ($data[$type] !== null)
        <h2>{{ $label }}</h2>
        <table>
            <thead><tr><th>Code</th><th>Category</th><th>Sub-category</th><th class="num">Amount</th><th class="num">% of type total</th></tr></thead>
            <tbody>
            @forelse ($data[$type]['categories'] as $c)
                <tr class="b sub"><td>{{ $c['code'] ?? '' }}</td><td>{{ $c['name'] }}@if (! $c['is_active']) (Inactive)@endif</td><td></td><td class="num">{{ $m($c['amount']) }}</td><td class="num">@if ($c['percent'] !== null){{ $c['percent'] }}@else<span class="g">—</span>@endif</td></tr>
                @foreach ($c['subs'] as $s)
                    <tr><td>{{ $s['code'] ?? '' }}</td><td></td><td>{{ $s['name'] }}@if (! empty($s['branch_only'])) ({{ $s['branch_only'] }} only)@endif @if (! $s['is_active']) (Inactive)@endif</td><td class="num">{{ $m($s['amount']) }}</td><td class="num">@if ($s['percent'] !== null){{ $s['percent'] }}@else<span class="g">—</span>@endif</td></tr>
                @endforeach
            @empty
                <tr><td colspan="5">No {{ strtolower($label) }} in this period.</td></tr>
            @endforelse
            <tr class="b"><td></td><td>{{ $totalLabel }}</td><td></td><td class="num">{{ $m($data[$type]['total']) }}</td><td></td></tr>
            </tbody>
        </table>
    @endif
@endforeach
@if ($data['net'] !== null)
    <table style="margin-top:8px"><tr class="b"><td>Net (income - expenses)</td><td class="num">{{ $m($data['net']) }}</td></tr></table>
@endif
@endsection
