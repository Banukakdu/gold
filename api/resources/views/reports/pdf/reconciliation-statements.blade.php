@extends('reports.pdf.layout')
@php($X = \App\Services\ReportExporter::class)
@php($date = fn ($v) => app(\App\Support\Settings::class)->formatDate($v))
@php($colombo = fn ($v) => $v ? \Carbon\CarbonImmutable::parse($v)->setTimezone('Asia/Colombo')->format('Y-m-d H:i') : '')
@php($copies = $data['copies_supported'])
{{-- The Branch column as on S6: All branches mode (or more than one branch) only. --}}
@php($showBranch = $data['all_branches'] || count($data['branches']) > 1)
@section('body')
{{-- AG-033c S6 (accounting review finding 4): the checker's working paper. Filters and the R9 coverage line under the header. --}}
<p class="note">{{ \App\Services\BankReconciliation::listFilterText($data) }}</p>
<p class="{{ $data['coverage']['not_reconciled'] ? 'alert' : 'note' }}">{{ $X::coverageLine($data) }}</p>
<p class="note">{{ $data['total'] }} {{ $data['total'] === 1 ? 'statement' : 'statements' }}</p>
<table>
    <thead><tr>
        @if ($showBranch)<th>Branch</th>@endif<th>Account</th><th>Statement date</th><th class="num">Statement balance</th><th class="num">Lines</th>
        <th>Completed by / at</th><th>Status</th><th>Changed</th><th class="num">Late entries</th>
        <th style="width: 110px">Checked</th>
        @if ($copies)<th>Bank statement copy</th>@endif
    </tr></thead>
    <tbody>
    @forelse ($data['data'] as $r)
        <tr>
            @if ($showBranch)<td>{{ $r['branch']['code'] }}</td>@endif
            <td>{{ $X::listAccountText($r['account']) }}</td>
            <td>{{ $date($r['statement_date']) }}</td>
            <td class="num">{{ $X::statementBalanceText($r['statement_balance']) }}</td>
            <td class="num">{{ $r['lines_count'] }} ({{ $r['self_count'] }} by preparer)</td>
            <td>{{ $X::personText($r['completed_by']) }}<br>{{ $colombo($r['completed_at']) }}</td>
            <td>{{ $X::statementStatusText($r) }}</td>
            <td>{{ $r['changed_since_completion'] ? 'Changed since completion' : '' }}</td>
            <td class="num">{{ $X::lateText($r) }}</td>
            <td>{!! $X::pdfKeepDates(\App\Services\ReconciliationChecks::listText($r, true)) !!}</td>
            @if ($copies)<td>{{ $X::copyText($r) }}</td>@endif
        </tr>
    @empty
        <tr><td colspan="{{ 9 + ($copies ? 1 : 0) + ($showBranch ? 1 : 0) }}">No statements match these filters.</td></tr>
    @endforelse
    </tbody>
</table>
<p class="note">Late entries: entries dated on or before the statement date that were recorded or voided after completion (as listed on the statement).</p>
@endsection
