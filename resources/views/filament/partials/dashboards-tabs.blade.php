{{-- MARKER-DASH-BUILDER: Templates | Blocks, at the top of both Dashboards pages. --}}
<style>
  .db-tabs { display:flex; gap:22px; border-bottom:1px solid var(--sx-line); margin:-4px 0 14px; }
  .db-tabs a { padding:8px 0 10px; font-size:14px; font-weight:500; color:var(--sx-dim); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-1px; }
  .db-tabs a.on { color:var(--db-txt); border-bottom-color:var(--sx-violet); }
  .db-tabs a:hover { color:var(--db-txt); }
</style>
<nav class="db-tabs">
  <a href="{{ \App\Filament\Pages\Dashboards::getUrl() }}" class="{{ $tab === 'templates' ? 'on' : '' }}">Templates</a>
  <a href="{{ \App\Filament\Pages\DashboardBlocks::getUrl() }}" class="{{ $tab === 'blocks' ? 'on' : '' }}">Blocks</a>
</nav>
