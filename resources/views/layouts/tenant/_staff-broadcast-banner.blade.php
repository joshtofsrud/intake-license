{{-- MARKER-PATCH-281 — dismissible staff announcement banner --}}
@php
  $__bc = collect();
  try {
      $__t = function_exists('tenant') ? tenant() : null;
      $__u = auth('tenant')->user();
      if ($__t && $__u && $__t->staff_alerts_enabled) {
          $__bc = \App\Models\Tenant\TenantStaffAlertBroadcast::where('tenant_id', $__t->id)
              ->where('is_active', true)
              ->where('show_banner', true)
              ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
              ->whereDoesntHave('dismissals', function ($q) use ($__u) { $q->where('user_id', $__u->id); })
              ->latest()->limit(3)->get();
      }
  } catch (\Throwable $e) { $__bc = collect(); }
@endphp
@if($__bc->isNotEmpty())
<div class="sbc-wrap">
  @foreach($__bc as $b)
    <div class="sbc {{ $b->priority === 'high' ? 'sbc-high' : '' }}" data-sbc="{{ $b->id }}">
      <span class="sbc-ico">📣</span>
      <div class="sbc-main">
        <span class="sbc-title">{{ $b->title }}</span>
        @if($b->body)<span class="sbc-text">{{ $b->body }}</span>@endif
      </div>
      <button type="button" class="sbc-x" data-dismiss="{{ $b->id }}" aria-label="Dismiss">&times;</button>
    </div>
  @endforeach
</div>
<style>
  .sbc-wrap{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
  .sbc{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border-radius:10px;background:rgba(95,168,220,.08);border:1px solid rgba(95,168,220,.25)}
  .sbc-high{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.35)}
  .sbc-ico{font-size:15px;line-height:1.4;flex-shrink:0}
  .sbc-main{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1}
  .sbc-title{font-size:13.5px;font-weight:700;color:var(--ia-text)}
  .sbc-text{font-size:12.5px;color:var(--ia-text-2);line-height:1.45}
  .sbc-x{background:none;border:0;color:var(--ia-text-3);font-size:20px;line-height:1;cursor:pointer;flex-shrink:0;padding:0 2px}
  .sbc-x:hover{color:var(--ia-text)}
</style>
<script>
(function(){
  var csrf = '{{ csrf_token() }}';
  var tpl  = '{{ route('tenant.alerts.broadcasts.dismiss', ['id' => 'ID']) }}';
  document.querySelectorAll('.sbc-x[data-dismiss]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.getAttribute('data-dismiss');
      var row = btn.closest('.sbc'); if (row) row.remove();
      fetch(tpl.replace('ID', id), { method:'POST', headers:{ 'X-CSRF-TOKEN':csrf, 'Accept':'application/json' } }).catch(function(){});
      var wrap = document.querySelector('.sbc-wrap'); if (wrap && !wrap.querySelector('.sbc')) wrap.remove();
    });
  });
})();
</script>
@endif
