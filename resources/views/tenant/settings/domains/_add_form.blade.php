{{-- Shared add-domain form. Used by empty-state pitch and active-state card. --}}
<form method="POST" action="{{ route('tenant.domains.store', ['subdomain' => $tenant->subdomain]) }}">
  @csrf
  <div class="dm-form-inline">
    <div>
      <label class="ia-form-label" for="dm-hostname-input">Domain</label>
      <input type="text"
             id="dm-hostname-input"
             name="hostname"
             class="ia-input"
             style="font-family:var(--ia-font-mono,monospace)"
             placeholder="yourdomain.com"
             value="{{ old('hostname') }}"
             {{ $atLimit ? 'disabled' : '' }}
             required>
      <div class="dm-form-help">
        Without <code>https://</code> or <code>www.</code>. You'll add DNS records on the next screen.
      </div>
    </div>
    <button type="submit"
            class="ia-btn ia-btn-primary"
            {{ $atLimit ? 'disabled' : '' }}>
      + Add domain
    </button>
  </div>

  <details style="margin-top:14px">
    <summary style="font-size:12px;color:var(--ia-text-3,#888);cursor:pointer">
      Advanced options
    </summary>
    <div style="padding:14px 0 0;display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <label class="ia-form-label">Used for</label>
        <select name="role" class="ia-input">
          <option value="both" selected>Both customer pages and admin (recommended)</option>
          <option value="booking">Customer pages only</option>
          <option value="admin">Admin only</option>
        </select>
        <div class="dm-form-help">Most shops use one domain for everything.</div>
      </div>
      <div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ia-text-2,#c8c8c8);margin-top:24px;cursor:pointer">
          <input type="checkbox" name="is_primary" value="1" style="accent-color:var(--ia-accent,#BEF264)">
          Mark as primary
        </label>
        <div class="dm-form-help" style="margin-left:24px">
          Other domains redirect to your primary.
        </div>
      </div>
    </div>
  </details>
</form>
