{{-- MARKER-LIVE-VIEW: answers Intake support's requests to see this tab. See App\Services\LiveView. --}}
@if (auth('tenant')->check() && ! is_impersonating())
<style>
  .lv-bar{position:fixed;left:50%;top:10px;transform:translateX(-50%);z-index:2147483000;display:flex;align-items:center;gap:12px;
    padding:8px 10px 8px 14px;border-radius:999px;background:#7c2d12;color:#fff;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.25);max-width:calc(100vw - 24px);white-space:nowrap}
  .lv-bar-dot{width:8px;height:8px;border-radius:50%;background:#fb923c;flex-shrink:0;animation:lv-pulse 1.6s ease-in-out infinite}
  .lv-bar button{background:#fff;color:#7c2d12;border:0;border-radius:999px;padding:5px 12px;font-weight:700;font-size:12.5px;cursor:pointer}
  @media (max-width:520px){.lv-bar{top:auto;bottom:calc(84px + env(safe-area-inset-bottom));font-size:12.5px}.lv-bar-more{display:none}}
  @keyframes lv-pulse{50%{opacity:.35}}
</style>
<script src="{{ asset('js/tenant/live-view.js') }}?v={{ filemtime(public_path('js/tenant/live-view.js')) }}" defer
  data-pending="{{ route('tenant.live-view.pending') }}"
  data-base="{{ url('/admin/live-view') }}"
  data-recorder="{{ asset('js/vendor/rrweb-record-2.1.7.min.js') }}"
  data-poll="{{ \App\Services\LiveView::POLL }}"
  data-minutes="{{ \App\Services\LiveView::LASTS }}"></script>
@endif
