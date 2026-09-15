{{-- MARKER-HELP-TENANT — contextual help for one screen.

     Usage:  <x-help-link key="inventory.import" />

     Resolves by help_key at click time, not render time: a screen carrying a
     key for a guide that doesn't exist yet costs nothing and starts working the
     moment the guide is published. A shop that can't read it lands on the help
     page, not a 404. --}}
@props(['key', 'label' => 'Help with this screen'])

<a href="{{ route('tenant.help.for', $key) }}"
   title="{{ $label }}"
   aria-label="{{ $label }}"
   style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;
          border-radius:99px;border:1px solid var(--ia-border,#1f1f1f);background:var(--ia-surface,#131313);
          color:var(--ia-text-3,#888);text-decoration:none;font-size:13px;line-height:1;">?</a>
