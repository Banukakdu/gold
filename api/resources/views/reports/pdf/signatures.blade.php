<table class="sign">
    <tr>
        @foreach (['Prepared by', 'Checked by', 'Approved by'] as $who)
            <td>
                <div><b>{{ $who }}</b></div>
                <div style="margin-top:18px" class="l">Name</div>
                <div style="margin-top:18px" class="l">Signature</div>
                <div style="margin-top:18px" class="l">Date</div>
            </td>
        @endforeach
    </tr>
</table>
