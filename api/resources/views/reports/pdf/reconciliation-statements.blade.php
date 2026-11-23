@extends('reports.pdf.layout')
@php($X = \App\Services\ReportExporter::class)
@php($date = fn ($v) => app(\App\Support\Settings::class)->formatDate($v))
@php($colombo = fn ($v) => $v ? \Carbon\CarbonImmutable::parse($v)->setTimezone('Asia/Colombo')->format('Y-m-d H:i') : '')
@php($copies = $data['copies_supported'])
@section('body')
{{-- AG-033c S6 (accounting review finding 4): the checker's working paper. Filters and the R9 coverage line under the header. --}}
<p class="note">{{ \App\Services\BankReconciliation::listFilterText($data) }}</p>
<p class="{{ $data['coverage']['not_reconciled'] ? 'alert' : 'note' }}">{{ $X::coverageLine($data) }}</p>
<p class="note">{{ $data['total'] }} {{ $data['total'] === 1 ? 'statement' : 'statements' }}</p>
<table>
    <thead><tr>
        <th>Branch</th><th>Account</th><th>Statement date</th><th class="num">Statement balance</th><th class="num">Lines</th>
        <th>Completed by / at</th><th>Status</th><th>Changed</th><th class="num">Late entries</th>
        @if ($copies)<th>Bank statement copy</th>@endif
        <th style="width: 90px">Checked (initials, date)</th>
    </tr></thead>
    <tbody>
    @forelse ($data['data'] as $r)
        <tr>
            <td>{{ $r['branch']['code'] }}</td>
            <td>{{ $X::listAccountText($r['account']) }}</td>
            <td>{{ $date($r['statement_date']) }}</td>
            <td class="num">{{ $X::statementBalanceText($r['statement_balance']) }}</td>
            <td class="num">{{ $r['lines_count'] }} ({{ $r['self_count'] }} by preparer)</td>
            <td>{{ $X::personText($r['completed_by']) }}<br>{{ $colombo($r['completed_at']) }}</td>
            <td>{{ $X::statementStatusText($r) }}</td>
            <td>{{ $r['changed_since_completion'] ? 'Changed since completion' : '' }}</td>
            <td class="num">{{ $X::lateText($r) }}</td>
            @if ($copies)<td>{{ $X::copyText($r) }}</td>@endif
            <td></td>
        </tr>
    @empty
        <tr><td colspan="{{ $copies ? 11 : 10 }}">No statements match these filters.</td></tr>
    @endforelse
    </tbody>
</table>
<p class="note">Late entries: entries dated on or before the statement date that were recorded or voided after completion (as listed on the statement).</p>
<table class="sign">
    <tr>
        <td>
            <div><b>Checked by</b></div>
            <div style="margin-top:18px" class="l">Name</div>
            <div style="margin-top:18px" class="l">Signature</div>
        </td>
        <td>
            <div><b>Date</b></div>
            <div style="margin-top:18px" class="l">&nbsp;</div>
        </td>
        <td></td>
    </tr>
</table>
@endsection
