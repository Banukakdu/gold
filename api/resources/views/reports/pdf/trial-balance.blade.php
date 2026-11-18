@extends('reports.pdf.layout')
@php($m = fn ($v) => $v === null ? '' : \App\Support\Money::format($v))
@php($groups = \App\Services\ReportService::TB_GROUPS)
{{-- Core fonts only (DejaVu text is not searchable in tests and adds weight): plain ASCII for the arrow and dash. --}}
@php($t = fn ($s) => str_replace(['↔', '–'], ['<->', '-'], (string) $s))
@section('body')
@if ($data['view'] === 'consolidated')
    @unless ($data['balanced'])<p class="alert">Out of balance by {{ $m($data['difference']) }}. Run ledger:verify.</p>@endunless
    <table>
        <thead><tr><th>Line</th>
            @foreach ($data['branches'] as $b)<th class="num">{{ $b['code'] }}@if ($b['status'] !== 'active') (Inactive)@endif</th>@endforeach
            <th class="num">Eliminations</th><th class="num">Consolidated Dr</th><th class="num">Consolidated Cr</th></tr></thead>
        <tbody>
        @php($last = null)
        @foreach ($data['rows'] as $r)
            @if ($r['group'] !== $last)
                <tr class="sub b"><td colspan="{{ count($data['branches']) + 4 }}">{{ $groups[$r['group']] }}</td></tr>
                @php($last = $r['group'])
            @endif
            <tr><td>{{ $t($r['label']) }}</td>
                @foreach ($data['branches'] as $b)<td class="num">{{ $m($r['by_branch'][$b['id']] ?? null) }}</td>@endforeach
                <td class="num">{{ $m($r['elimination']) }}</td><td class="num">{{ $m($r['dr']) }}</td><td class="num">{{ $m($r['cr']) }}</td></tr>
        @endforeach
        <tr class="b"><td>Total</td>
            @foreach ($data['branches'] as $b)<td class="num">{{ $m($data['totals']['by_branch'][$b['id']] ?? '0') }}</td>@endforeach
            <td class="num">{{ $m($data['totals']['elimination']) }}</td><td class="num">{{ $m($data['totals']['dr']) }}</td><td class="num">{{ $m($data['totals']['cr']) }}</td></tr>
        </tbody>
    </table>
    <p class="note">Branch columns are closing balances, signed: debit positive, credit negative.</p>
    @if ($data['subset'])<p class="note">Inter-branch balances with branches not selected are not eliminated.</p>@endif
@else
    @foreach ($data['sections'] as $sec)
        <h2>{{ $sec['branch']['code'] }} {{ $sec['branch']['name'] }}@if ($sec['branch']['status'] !== 'active') (Inactive)@endif</h2>
        @unless ($sec['balanced'])<p class="alert">Out of balance by {{ $m($sec['difference']) }}. Run ledger:verify.</p>@endunless
        <table>
            <thead><tr><th>Code</th><th>Account</th><th>Class</th><th class="num">Opening Dr</th><th class="num">Opening Cr</th>
                <th class="num">Period debits</th><th class="num">Period credits</th><th class="num">Closing Dr</th><th class="num">Closing Cr</th></tr></thead>
            <tbody>
            @forelse ($sec['rows'] as $r)
                <tr><td>{{ $r['account']['code'] }}</td>
                    <td>{{ $t($r['account']['name']) }}@if ($r['account']['status'] !== 'active') ({{ ucfirst($r['account']['status']) }})@endif</td>
                    <td>{{ $groups[$r['account']['group']] }}</td>
                    @foreach (['opening_dr', 'opening_cr', 'period_dr', 'period_cr', 'closing_dr', 'closing_cr'] as $k)<td class="num">{{ $m($r[$k]) }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="9">No balances or movements.</td></tr>
            @endforelse
            <tr class="b"><td></td><td>Total</td><td></td>
                @foreach (['opening_dr', 'opening_cr', 'period_dr', 'period_cr', 'closing_dr', 'closing_cr'] as $k)<td class="num">{{ $m($sec['totals'][$k]) }}</td>@endforeach</tr>
            </tbody>
        </table>
    @endforeach
@endif
@include('reports.pdf.signatures')
@endsection
