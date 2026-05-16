<style>
  .rep-h1 { font-size: 28px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
  .rep-sub { color: var(--ia-text-3, #888); font-size: 13.5px; margin-bottom: 24px; }

  .rep-toggle { display: inline-flex; gap: 4px; background: rgba(255,255,255,0.02); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 8px; padding: 3px; }
  .rep-toggle a { padding: 7px 14px; font-size: 12.5px; font-weight: 600; color: var(--ia-text-3, #888); text-decoration: none; border-radius: 5px; transition: all 0.12s; }
  .rep-toggle a:hover { color: var(--ia-text, #f0f0f0); }
  .rep-toggle a.active { background: #BEF264; color: #0a0a0a; }

  .rep-rangebar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 14px 16px; margin-bottom: 24px; background: var(--ia-surface, #131313); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 12px; }
  .rep-rangebar-label { font-size: 11.5px; color: var(--ia-text-3, #888); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; }
  .rep-rangebar-current { font-size: 14px; font-weight: 700; color: var(--ia-text, #f0f0f0); margin-left: 8px; }
  .rep-rangebar-controls { display: inline-flex; gap: 6px; align-items: center; }

  .rep-zone { background: var(--ia-surface, #131313); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 14px; padding: 22px; margin-bottom: 18px; position: relative; }
  .rep-zone-head { margin-bottom: 18px; display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
  .rep-zone-title { font-size: 15px; font-weight: 800; letter-spacing: -0.01em; }
  .rep-zone-sub { font-size: 12px; color: var(--ia-text-3, #888); font-weight: 500; margin-top: 2px; }

  .rep-stat-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0; border-top: 0.5px solid var(--ia-border, #1f1f1f); border-bottom: 0.5px solid var(--ia-border, #1f1f1f); margin: 14px 0; }
  .rep-stat-cell { padding: 16px 18px; border-right: 0.5px solid var(--ia-border, #1f1f1f); }
  .rep-stat-cell:last-child { border-right: none; }
  .rep-stat-cell .lbl { font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ia-text-3, #888); font-weight: 700; margin-bottom: 8px; }
  .rep-stat-cell .val { font-size: 24px; font-weight: 800; letter-spacing: -0.02em; line-height: 1; font-feature-settings: 'tnum'; }
  .rep-stat-cell.feat .val { color: #BEF264; }
  .rep-stat-cell.warn .val { color: #F59E0B; }
  .rep-stat-cell .meta { font-size: 11px; color: var(--ia-text-3, #888); margin-top: 6px; }

  table.rep-tbl { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 14px; }
  table.rep-tbl th { text-align: left; padding: 10px 12px; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ia-text-3, #888); font-weight: 700; border-bottom: 1px solid var(--ia-border, #1f1f1f); }
  table.rep-tbl th.right { text-align: right; }
  table.rep-tbl td { padding: 11px 12px; border-bottom: 1px solid var(--ia-border, #1f1f1f); vertical-align: top; }
  table.rep-tbl td.right { text-align: right; font-feature-settings: 'tnum'; font-weight: 600; }
  .rep-cell-name { color: var(--ia-text, #f0f0f0); font-weight: 600; }
  .rep-cell-meta { color: var(--ia-text-3, #888); font-size: 11px; margin-top: 2px; }
  .rep-empty { padding: 28px 18px; text-align: center; color: var(--ia-text-3, #888); font-size: 13px; }
  .rep-stub { padding: 32px 22px; text-align: center; color: var(--ia-text-3, #888); font-size: 13px; background: rgba(245,158,11,0.04); border: 1px dashed rgba(245,158,11,0.2); border-radius: 10px; margin-top: 14px; }
  .rep-stub strong { color: #F59E0B; font-weight: 700; }

  .rep-locked-list { position: relative; }
  .rep-locked-list table.rep-tbl { filter: blur(5px); user-select: none; pointer-events: none; }
  .rep-locked-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
  .rep-locked-badge { display: inline-flex; align-items: center; gap: 8px; background: var(--ia-surface-2, #1a1a1a); border: 1px solid var(--ia-border-2, #2a2a2a); border-radius: 99px; padding: 8px 16px; font-size: 12.5px; font-weight: 700; color: var(--ia-text, #f0f0f0); box-shadow: 0 6px 20px rgba(0,0,0,0.5); pointer-events: auto; }
  .rep-locked-badge .lime { color: #BEF264; }
  .rep-locked-badge button { background: #BEF264; color: #0a0a0a; border: none; border-radius: 99px; padding: 5px 12px; font-size: 11.5px; font-weight: 700; cursor: pointer; margin-left: 4px; font-family: inherit; }

  .rep-upsell-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(2px); }
  .rep-upsell-backdrop.open { display: flex; }
  .rep-upsell-modal { background: var(--ia-surface, #131313); border: 1px solid var(--ia-border-2, #2a2a2a); border-radius: 16px; max-width: 460px; width: 100%; padding: 28px; position: relative; box-shadow: 0 20px 60px rgba(0,0,0,0.6); }
  .rep-upsell-modal .close { position: absolute; top: 14px; right: 14px; background: transparent; border: none; color: var(--ia-text-3, #888); font-size: 20px; cursor: pointer; padding: 4px 8px; line-height: 1; }
  .rep-upsell-modal .badge { display: inline-block; background: rgba(190,242,100,0.12); color: #BEF264; font-size: 10.5px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; padding: 4px 10px; border-radius: 99px; margin-bottom: 14px; }
  .rep-upsell-modal h2 { font-size: 22px; font-weight: 800; margin-bottom: 10px; }
  .rep-upsell-modal p { font-size: 13.5px; line-height: 1.55; color: var(--ia-text-2, #c8c8c8); margin-bottom: 20px; }
  .rep-upsell-modal .cta-row { display: flex; gap: 10px; }
  .rep-upsell-modal .cta-primary { background: #BEF264; color: #0a0a0a; border: none; border-radius: 8px; padding: 11px 20px; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; font-family: inherit; }
  .rep-upsell-modal .cta-secondary { background: transparent; color: var(--ia-text-3, #888); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 8px; padding: 11px 18px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }
</style>
