@props([
    'name'        => 'customer_id',
    'required'    => false,
    'placeholder' => 'Search by name, email, or phone…',
    'autofocus'   => false,
])

{{--
  Customer search picker. Renders an input + dropdown that loads matching
  customers from /admin/customers/search. Picking a customer fills the
  hidden input named `$name` with their UUID. Submit the parent form like
  any other field.

  Reuses the existing `cl-input` and visual conventions. Designed to drop
  into class registration forms where the old "Customer UUID" text input
  was forcing admins to look up IDs manually.

  Usage:
    <x-tenant.customer-search name="customer_id" required />

  Behavior:
    - Type → debounced fetch → dropdown shows up to 12 matches
    - Empty input on focus → shows 12 most-recent customers (browse-first UX)
    - Click a row → input fills with name, hidden field gets UUID
    - Click cleared → x button to reset and re-search
    - Esc closes dropdown without selecting
--}}
<div class="ia-cs"
     data-customer-search
     data-search-url="{{ route('tenant.customers.search', []) }}">
    <input type="hidden" name="{{ $name }}" data-cs-id @if($required) required @endif>
    <input type="text"
           class="cl-input ia-cs-input"
           data-cs-input
           placeholder="{{ $placeholder }}"
           autocomplete="off"
           @if($autofocus) autofocus @endif>
    <button type="button" class="ia-cs-clear" data-cs-clear hidden aria-label="Clear">×</button>
    <div class="ia-cs-results" data-cs-results hidden></div>
</div>

@once
@push('styles')
<style>
.ia-cs { position: relative; }
.ia-cs-input { padding-right: 28px; }
.ia-cs-clear {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 18px; height: 18px;
    background: var(--ia-border);
    color: var(--ia-text);
    border: none;
    border-radius: 50%;
    cursor: pointer;
    font-family: inherit;
    font-size: 12px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    transition: background var(--ia-t);
}
.ia-cs-clear:hover { background: var(--ia-border-strong); }
.ia-cs-results {
    /* Fixed positioning escapes any ancestor with overflow:hidden
       (e.g. .cl-session-card / .cl-card on the class pages). The JS sets
       top/left/width on every show via getBoundingClientRect(). */
    position: fixed;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-md);
    box-shadow: 0 8px 24px rgba(0,0,0,.4);
    max-height: 300px;
    overflow-y: auto;
    z-index: 9999;
}
.ia-cs-row {
    padding: 9px 12px;
    cursor: pointer;
    transition: background var(--ia-t);
    border-bottom: 0.5px solid var(--ia-border);
}
.ia-cs-row:last-child { border-bottom: none; }
.ia-cs-row:hover, .ia-cs-row.is-active { background: var(--ia-hover); }
.ia-cs-row-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--ia-text);
}
.ia-cs-row-meta {
    font-size: 11.5px;
    color: var(--ia-text-muted);
    margin-top: 1px;
}
.ia-cs-empty {
    padding: 16px 12px;
    font-size: 12px;
    color: var(--ia-text-dim);
    text-align: center;
}
</style>
@endpush

@push('scripts')
@php
    $csJsPath = public_path('js/tenant/customer-search.js');
    $csJsVer  = file_exists($csJsPath) ? filemtime($csJsPath) : time();
@endphp
<script src="{{ asset('js/tenant/customer-search.js') }}?v={{ $csJsVer }}" defer></script>
@endpush
@endonce
