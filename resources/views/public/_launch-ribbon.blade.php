{{-- MARKER-LAUNCH-CHECKLIST: only signed-in staff ever see this. Preview = visitors get "coming soon";
     paused = visitors can look but not pay or book. $lrModule: ecom | rent --}}
@php
  $lrT = $currentTenant ?? tenant();
  $lrState = $lrT ? \App\Support\Launch\Launch::publicState($lrT, $lrModule) : 'off';
  $lrStaff = \App\Support\Launch\Launch::staffViewing();
@endphp
@if($lrState === 'paused')
  <div style="background:#3a1d1d;color:#F09595;font:500 13.5px/1.5 -apple-system,'Inter',sans-serif;padding:10px 16px;text-align:center">
    {{ \App\Support\Launch\Launch::pausedNotice($lrModule) }}
    @if($lrStaff)<a href="{{ \App\Support\Launch\Launch::home($lrModule) }}" style="color:inherit;text-decoration:underline;margin-left:6px">Why? (staff only)</a>@endif
  </div>
@elseif($lrState === 'preview' && $lrStaff)
  <div style="background:#3a2d12;color:#EF9F27;font:500 13.5px/1.5 -apple-system,'Inter',sans-serif;padding:10px 16px;text-align:center">
    <b>Preview.</b> Only your signed-in team can see this {{ \App\Support\Launch\Launch::thing($lrModule) }}. Visitors see a "coming soon" page.
    <a href="{{ \App\Support\Launch\Launch::home($lrModule) }}" style="color:inherit;text-decoration:underline;margin-left:6px">Open the checklist</a>
  </div>
@endif
