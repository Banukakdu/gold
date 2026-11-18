@extends('reports.pdf.layout')
@php($m = fn ($v) => \App\Support\Money::format($v))
@section('body')
@foreach ($pages as $i => $page)
    <h2>Movements @if (count($pages) > 1)(page {{ $i + 1 }} of {{ count($pages) }} of the list)@endif</h2>
    <table>
        <thead><tr><th>Date</th><th>Ref no</th><th>Type</th><th>From</th><th>To</th><th class="num">Amount</th><th>Status</th><th>Note</th><th>Created by</th></tr></thead>
        <tbody>
        <tr class="carry"><td colspan="5">Brought forward</td><td class="num">{{ $m($page['bf']['amount']) }}</td><td colspan="3"></td></tr>
        @forelse ($page['rows'] as $r)
            <tr><td>{{ $r['txn_date'] }}</td><td>{{ $r['ref_no'] }}</td><td>{{ \App\Services\ReportExporter::movementLabel($r) }}</td>
                <td>{{ $r['from']['branch_code'] }} - {{ $r['from']['account'] }}</td><td>{{ $r['to']['branch_code'] }} - {{ $r['to']['account'] }}</td>
                <td class="num">{{ $m($r['amount']) }}</td><td>{{ \App\Services\ReportExporter::statusLabel($r) }}</td>
                <td>{{ \Illuminate\Support\Str::limit((string) $r['description'], 40) }}</td><td>{{ $r['created_by'] }}</td></tr>
        @empty
            <tr><td colspan="9">No transfers or allocations in this period.</td></tr>
        @endforelse
        <tr class="b"><td colspan="5">Page total</td><td class="num">{{ $m($page['page']['amount']) }}</td><td colspan="3"></td></tr>
        <tr class="carry b"><td colspan="5">{{ $loop->last ? 'Carried forward = Net moved (reversals deducted)' : 'Carried forward' }}</td><td class="num">{{ $m($page['cf']['amount']) }}</td><td colspan="3"></td></tr>
        </tbody>
    </table>
    <div class="page-break"></div>
@endforeach
<h2>Net position matrix</h2>
@php($mx = $data['matrix'])
@php($cell = collect($mx['cells'])->mapWithKeys(fn ($c) => [$c['from_id'].':'.$c['to_id'] => $c['amount']]))
<table>
    <thead><tr><th>From \ To</th>@foreach ($mx['branches'] as $b)<th class="num">{{ $b['code'] }}</th>@endforeach<th class="num">Total sent</th></tr></thead>
    <tbody>
    @foreach ($mx['branches'] as $a)
        <tr><td>{{ $a['code'] }}</td>
            @foreach ($mx['branches'] as $b)
                <td class="num">@if ($a['id'] === $b['id'])<span class="g">—</span>@else{{ isset($cell[$a['id'].':'.$b['id']]) ? $m($cell[$a['id'].':'.$b['id']]) : '' }}@endif</td>
            @endforeach
            <td class="num">{{ $a['in_scope'] ? $m($mx['sent'][$a['id']]) : '' }}</td></tr>
    @endforeach
    <tr class="b"><td>Total received</td>@foreach ($mx['branches'] as $b)<td class="num">{{ $b['in_scope'] ? $m($mx['received'][$b['id']]) : '' }}</td>@endforeach<td></td></tr>
    <tr class="b"><td>Net (received - sent)</td>@foreach ($mx['branches'] as $b)<td class="num">{{ $b['in_scope'] ? $m($mx['net'][$b['id']]) : '' }}</td>@endforeach<td></td></tr>
    </tbody>
</table>
<p class="note">Cell = net amount sent from the row branch to the column branch (reversals deducted).</p>
@if ($data['subset'])<p class="note">Transfers with branches not selected are included; inter-branch amounts will not net to zero.</p>@endif
@endsection
