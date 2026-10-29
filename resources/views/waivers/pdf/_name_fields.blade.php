{{-- Two name fields on one row (First | Last). CSS table on divs: stable in dompdf (legacy pdfPrintedNameFields). --}}
<p class="sesf-waiver-printed-label"><strong>{{ $heading }}</strong></p>
<div style="display:table;width:100%;table-layout:fixed;margin:0.2em 0 0.55em;">
<div style="display:table-row;">
<div style="display:table-cell;width:50%;padding:0.35in 8px 0 0;vertical-align:bottom;">
<div style="border-bottom:1px solid #000;min-height:26px;padding:2px 4px 1px;">{{ $first }}</div>
<div style="text-align:center;margin:4px 0 0;font-size:8pt;">First Name</div>
</div>
<div style="display:table-cell;width:50%;padding:0.35in 0 0 8px;vertical-align:bottom;">
<div style="border-bottom:1px solid #000;min-height:26px;padding:2px 4px 1px;">{{ $last }}</div>
<div style="text-align:center;margin:4px 0 0;font-size:8pt;">Last Name</div>
</div>
</div></div>
