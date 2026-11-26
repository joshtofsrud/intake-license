{{-- MARKER-POSTS: shared post list styles (included once per page) --}}
@once
<style>
  .pst-list { display: grid; gap: 14px; }
  .pst-list.pst-cards { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 18px; }
  .pst-card { display: flex; gap: 16px; align-items: flex-start; padding: 16px 0; border-bottom: 1px solid color-mix(in srgb, currentColor 12%, transparent); color: inherit; }
  .pst-list.pst-cards .pst-card { flex-direction: column; gap: 0; padding: 0; border: 1px solid color-mix(in srgb, currentColor 12%, transparent); border-radius: var(--p-r-lg, 12px); overflow: hidden; background: color-mix(in srgb, var(--p-surface, #fff) 60%, transparent); }
  .pst-img { flex: 0 0 120px; aspect-ratio: 4/3; border-radius: var(--p-r, 8px); background: #0002 center/cover no-repeat; }
  .pst-list.pst-cards .pst-img { flex: none; width: 100%; aspect-ratio: 16/9; border-radius: 0; }
  .pst-txt { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
  .pst-list.pst-cards .pst-txt { padding: 14px 16px 18px; }
  .pst-meta { display: flex; gap: 10px; font-size: 12.5px; opacity: .6; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }
  .pst-title { font-family: var(--p-font-heading); font-size: 19px; font-weight: var(--p-heading-weight, 700); line-height: 1.3; }
  .pst-card:hover .pst-title { text-decoration: underline; text-underline-offset: 3px; }
  .pst-ex { font-size: 15px; opacity: .72; line-height: 1.55; }
  .pst-chips { display: flex; gap: 8px; flex-wrap: wrap; margin: 18px 0 8px; }
  .pst-chip { font-size: 13px; padding: 6px 14px; border-radius: 99px; border: 1px solid color-mix(in srgb, currentColor 20%, transparent); }
  .pst-chip.on { background: var(--p-accent); color: var(--p-accent-text); border-color: transparent; }
  .pst-empty { padding: 28px 0; opacity: .6; }
  @media (max-width: 560px) { .pst-img { flex-basis: 84px; } .pst-title { font-size: 17px; } }
</style>
@endonce
