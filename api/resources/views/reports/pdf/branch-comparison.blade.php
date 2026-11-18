@extends('reports.pdf.layout')
@php($m = fn ($v) => \App\Support\Money::format($v))
@php($keys = \App\Services\ReportService::COMPARISON_KEYS)
@section('body')
<table>
    <thead><tr><th>Branch</th><th class="num">Opening balance</th><th class="num">Capital introduced</th><th class="num">Funds received</th><th class="num">Income</th>
        <th class="num">Expenses</th><th class="num">Transfers in</th><th class="num">Transfers out</th><th class="num">Closing balance</th></tr></thead>
    <tbody>
    @forelse ($data['rows'] as $r)
        <tr><td>{{ $r['branch']['code'] }} {{ $r['branch']['name'] }}@if ($r['branch']['status'] !== 'active') (Inactive)@endif</td>
            @foreach ($keys as $k)<td class="num">{{ $m($r[$k]) }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="9">No branches to show.</td></tr>
    @endforelse
    <tr class="b"><td>Total</td>@foreach ($keys as $k)<td class="num">{{ $m($data['total'][$k]) }}</td>@endforeach</tr>
    </tbody>
</table>
<p class="note">Closing = Opening + Capital introduced + Funds received + Income - Expenses + Transfers in - Transfers out. Internal transfers between accounts of the same branch are not shown.</p>
@if ($data['subset'])<p class="note">Transfers with branches not selected are included; inter-branch amounts will not net to zero.</p>@endif
@include('reports.pdf.signatures')
@endsection
