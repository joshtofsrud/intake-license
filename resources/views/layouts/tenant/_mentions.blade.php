{{-- MARKER-NOTE-MENTIONS: who can be @mentioned in notes. See public/js/tenant/mentions.js --}}
@if (auth('tenant')->check() && ($currentTenant ?? null))
@php
  $__mp = [];
  try {
      foreach (\App\Support\NoteMentions::people($currentTenant) as $__id => $__n) $__mp[] = ['id' => $__id, 'name' => $__n];
  } catch (\Throwable $e) { $__mp = []; }
@endphp
<style>
  .ia-mention{background:var(--ia-accent-soft, rgba(190,242,100,.14));color:var(--ia-text);font-weight:600;padding:0 3px;border-radius:4px}
  .ia-mention-menu{position:fixed;z-index:10050;min-width:200px;max-width:240px;padding:4px;border-radius:var(--ia-r-md, 8px);
    background:var(--ia-surface, #1c1c1f);border:0.5px solid var(--ia-border, rgba(255,255,255,.14));box-shadow:0 10px 30px rgba(0,0,0,.3)}
  .ia-mention-opt{padding:7px 10px;border-radius:6px;font-size:13px;color:var(--ia-text);cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ia-mention-opt.on,.ia-mention-opt:hover{background:var(--ia-hover, rgba(255,255,255,.07))}
  .ia-note:target,.ma-note:target{outline:2px solid var(--ia-accent, #BEF264);outline-offset:4px;border-radius:6px}
</style>
<script>window.IntakeMentionPeople = @json($__mp);</script>
<script src="{{ asset('js/tenant/mentions.js') }}?v={{ filemtime(public_path('js/tenant/mentions.js')) }}" defer></script>
@endif
