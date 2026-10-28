{{-- person detail --}}
@extends('layouts.tenant.app')
@php
  $pageTitle = $member->name;
  $me        = Auth::guard('tenant')->user();
  $pinModeOn = $currentTenant->pin_tier_active;
  $pinLocked = $member->pin_locked_until && $member->pin_locked_until->isFuture();
  $tmPhoneDigits = $member->phone ? preg_replace('/[^0-9+]/', '', $member->phone) : '';
  $tmUpdate = route('tenant.team.update', $member->id);
@endphp

@push('styles')
<style>
  /* MARKER-TEAM-MEMBER-LAYOUT: two columns, hairlines, no cards. Left = what you change
     (contact, access); right = sign-in and the account actions. Every action still owns its
     own form, so a button is never next to fields it doesn't submit (a role change once
     posted the contact form). */
  .tm-back { font-size:12px; color:var(--ia-text-dim); display:inline-flex; gap:4px; margin-bottom:14px; text-decoration:none; }
  .tm-back:hover { color:var(--ia-text-muted); }
  .tm-head { display:flex; gap:14px; align-items:center; padding-bottom:16px; border-bottom:0.5px solid var(--ia-border); }
  .tm-av { width:44px; height:44px; border-radius:50%; background:var(--ia-accent); color:var(--ia-accent-text); display:grid; place-items:center; font-size:15px; font-weight:600; flex-shrink:0; }
  .tm-name { font-size:20px; font-weight:600; margin:0; line-height:1.2; }
  .tm-sub { font-size:12px; color:var(--ia-text-dim); margin-top:2px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
  .tm-dot { width:7px; height:7px; border-radius:50%; background:#4ADE80; display:inline-block; }
  .tm-dot.is-off { background:var(--ia-text-dim); }
  .tm-ct { margin-left:auto; display:flex; gap:6px; }
  .tm-ib { width:34px; height:34px; display:grid; place-items:center; border-radius:9px; border:0.5px solid var(--ia-border); background:transparent; color:var(--ia-accent); text-decoration:none; }
  .tm-ib:hover { background:var(--ia-surface); }
  .tm-ib:focus-visible { outline:2px solid var(--ia-accent); outline-offset:2px; }
  .tm-ib.is-disabled { opacity:.3; color:var(--ia-text-dim); pointer-events:none; }

  .tm-act { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:14px; padding:10px 14px; border:1px solid rgba(251,191,36,.4); background:rgba(251,191,36,.06); border-radius:10px; font-size:13px; }
  .tm-act b { color:#FBBF24; font-weight:600; }
  .tm-act-meta { color:var(--ia-text-dim); font-size:12px; }
  .tm-act-sp { flex:1; }

  .tm-cols { display:grid; grid-template-columns:minmax(0,1.55fr) minmax(0,1fr); gap:0 56px; margin-top:6px; }
  .tm-sec { padding:18px 0 20px; border-top:0.5px solid var(--ia-border); }
  .tm-cols > div > .tm-sec:first-child { border-top:0; }
  .tm-lab { font-size:11px; letter-spacing:.08em; text-transform:uppercase; font-weight:600; color:var(--ia-text-dim); margin:0 0 12px; }
  .tm-hint { font-size:11.5px; color:var(--ia-text-dim); line-height:1.45; }

  /* One height for every control in a row (input, select, button), so a button never sits
     shorter than the box beside it. */
  .tm-row .ia-input, .tm-row select.ia-input { height:36px; box-sizing:border-box; }
  .tm-row .ia-btn { height:36px; padding:0 16px; font-size:13px; box-sizing:border-box; justify-content:center; white-space:nowrap; }
  .tm-row .ia-btn--ghost { padding:0 12px; }
  .tm-row .ia-btn[disabled] { opacity:.38; cursor:not-allowed; filter:none; }

  .tm-g2 { display:grid; grid-template-columns:1fr 1fr; gap:12px 14px; }
  .tm-f label { display:block; font-size:12px; color:var(--ia-text-dim); margin-bottom:5px; }
  .tm-f .ia-input { width:100%; }
  .tm-save { align-self:end; display:flex; justify-content:flex-end; }
  .tm-line { display:grid; grid-template-columns:110px 1fr; gap:12px; align-items:center; padding:10px 0; border-top:0.5px solid var(--ia-border); }
  .tm-line:first-of-type { border-top:0; padding-top:0; }
  .tm-line.is-hint { border-top:0; padding:0 0 10px; margin-top:-4px; }
  .tm-k { font-size:12px; color:var(--ia-text-dim); }
  .tm-v { display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:13px; min-width:0; }
  .tm-v form { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0; }
  .tm-chips { display:flex; gap:8px; flex-wrap:wrap; }
  .tm-chip { display:inline-flex; align-items:center; gap:8px; font-size:13px; height:36px; box-sizing:border-box; padding:0 12px; border:0.5px solid var(--ia-border); border-radius:99px; cursor:pointer; }
  .tm-chip input { accent-color:var(--ia-accent); margin:0; }
  .tm-chip small { color:var(--ia-text-dim); font-size:11px; }
  .tm-quiet { display:flex; gap:16px; flex-wrap:wrap; }
  .tm-quiet form { margin:0; }
  .tm-quiet button { background:none; border:0; padding:0; font:inherit; font-size:13px; color:var(--ia-text-dim); cursor:pointer; text-decoration:underline; text-underline-offset:3px; text-decoration-color:var(--ia-border); }
  .tm-quiet button:hover { color:var(--ia-text); }
  .tm-quiet button.is-danger { color:#F87171; }

  /* this block MUST stay last: a media query adds no specificity. */
  @media (max-width:820px) {
    .tm-cols { grid-template-columns:1fr; }
    .tm-cols > div + div > .tm-sec:first-child { border-top:0.5px solid var(--ia-border); }
  }
  @media (max-width:560px) {
    .tm-g2 { grid-template-columns:1fr; }
    .tm-line { grid-template-columns:1fr; gap:6px; }
    .tm-head { flex-wrap:wrap; }
    .tm-ct { margin-left:0; width:100%; }
    .tm-ib { flex:1; width:auto; }
    .tm-v form { width:100%; }
    .tm-v form .ia-input, .tm-v form select { flex:1; min-width:0; }
    .tm-save { justify-content:stretch; }
    .tm-save .ia-btn { width:100%; }
  }
</style>
@endpush

@section('content')
<a href="{{ route('tenant.team.index') }}" class="tm-back">← All team members</a>

<div class="tm-head">
  <div class="tm-av">{{ strtoupper(substr($member->name, 0, 2)) }}</div>
  <div>
    <h2 class="tm-name">{{ $member->name }}</h2>
    <div class="tm-sub">
      <span><span class="tm-dot {{ $member->is_active ? '' : 'is-off' }}"></span> {{ $member->is_active ? 'Active' : 'Inactive' }}</span>
      <span>·</span><span>{{ ucfirst($member->role) }}</span>
      <span>·</span><span>last seen {{ $member->last_login_at?->diffForHumans() ?? 'never' }}</span>
    </div>
  </div>
  {{-- same affordance as the customer page; a button with nothing behind it is disabled, not dead --}}
  <div class="tm-ct">
    <a href="{{ $tmPhoneDigits ? 'tel:' . $tmPhoneDigits : '#' }}" class="tm-ib {{ $tmPhoneDigits ? '' : 'is-disabled' }}"
       @if(!$tmPhoneDigits) aria-disabled="true" tabindex="-1" @endif
       title="{{ $tmPhoneDigits ? 'Call ' . $member->name : 'No phone number on file' }}" aria-label="Call">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
    </a>
    <a href="{{ $tmPhoneDigits ? 'sms:' . $tmPhoneDigits : '#' }}" class="tm-ib {{ $tmPhoneDigits ? '' : 'is-disabled' }}"
       @if(!$tmPhoneDigits) aria-disabled="true" tabindex="-1" @endif
       title="{{ $tmPhoneDigits ? 'Text ' . $member->name : 'No phone number on file' }}" aria-label="Text">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
    </a>
    <a href="{{ $member->email ? 'mailto:' . $member->email : '#' }}" class="tm-ib {{ $member->email ? '' : 'is-disabled' }}"
       @if(!$member->email) aria-disabled="true" tabindex="-1" @endif
       title="{{ $member->email ? 'Email ' . $member->name : 'No email on file' }}" aria-label="Email">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
    </a>
  </div>
</div>

{{-- activation strip: only for never-activated members, on a page that is already manager-gated. --}}
@if(! $member->is_active && $member->last_login_at === null && ($inviteStats ?? null))
  <div class="tm-act tm-row">
    <b>Hasn't activated their account</b>
    <span class="tm-act-meta">
      @if($pendingInvite)
        Sent {{ $inviteStats['sends'] }}× · last {{ $inviteStats['last'] ? \Illuminate\Support\Carbon::parse($inviteStats['last'])->format('M j, g:ia') : 'never' }}
        · opened: {{ $inviteStats['opened'] ? 'yes' : 'never' }}. Links stay good for 7 days.
      @else
        The last link expired. Send again to make a new one. Sent {{ $inviteStats['sends'] }}× · opened: {{ $inviteStats['opened'] ? 'yes' : 'never' }}.
      @endif
    </span>
    <span class="tm-act-sp"></span>
    @if($pendingInvite)
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="ia-invite-copy"
              data-link="{{ route('tenant.team.setup') . '?token=' . $pendingInvite->token }}">Copy link</button>
    @endif
    <form method="POST" action="{{ route('tenant.team.resend-invite', $member->id) }}" style="margin:0">
      @csrf
      <button type="submit" class="ia-btn" style="background:#FBBF24;border-color:#FBBF24;color:#0a0a0a;font-weight:600">Send activation email again</button>
    </form>
  </div>
@endif

<div class="tm-cols">
  <div>
    {{-- Contact --}}
    <div class="tm-sec">
      <div class="tm-lab">Contact</div>
      <form method="POST" action="{{ $tmUpdate }}" class="tm-row" data-dirty>
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="update_account">
        <div class="tm-g2">
          <div class="tm-f"><label for="tm-name">Name</label><input id="tm-name" class="ia-input" name="name" value="{{ $member->name }}"></div>
          <div class="tm-f"><label for="tm-email">Email</label><input id="tm-email" class="ia-input" type="email" name="email" value="{{ $member->email }}"></div>
          <div class="tm-f"><label for="tm-phone">Phone</label><input id="tm-phone" class="ia-input" type="tel" name="phone" value="{{ $member->phone }}" maxlength="32" placeholder="(509) 555-0142"></div>
          <div class="tm-save"><button class="ia-btn ia-btn--primary">Save contact</button></div>
        </div>
        <div class="tm-hint" style="margin-top:8px">Phone is used for staff text alerts and the Call and Text buttons above. They can't change their own email or role.</div>
      </form>
    </div>

    {{-- Access --}}
    <div class="tm-sec">
      <div class="tm-lab">Access</div>

      <div class="tm-line tm-row">
        <div class="tm-k">Role</div>
        <div class="tm-v">
          <form method="POST" action="{{ $tmUpdate }}" data-dirty>
            @csrf @method('PATCH')
            <input type="hidden" name="op" value="change_role">
            <select name="role_id" class="ia-input" style="width:auto;min-width:150px" aria-label="Role">
              @if(! $allRoles->contains('id', $member->role_id))
                <option value="" disabled selected>Choose a role…</option>
              @endif
              @foreach($allRoles as $r)
                <option value="{{ $r->id }}" @selected($member->role_id === $r->id)>{{ $r->name }}</option>
              @endforeach
            </select>
            <button class="ia-btn ia-btn--primary">Change role</button>
          </form>
          <span class="tm-hint">Takes effect on their next page load.</span>
        </div>
      </div>

      <div class="tm-line tm-row">
        <div class="tm-k">Locations</div>
        <div class="tm-v">
          <form method="POST" action="{{ $tmUpdate }}" data-dirty>
            @csrf @method('PATCH')
            <input type="hidden" name="op" value="update_locations">
            <div class="tm-chips">
              @foreach($allLocations as $loc)
                <label class="tm-chip">
                  <input type="checkbox" name="location_ids[]" value="{{ $loc->id }}" @checked(in_array($loc->id, $memberLocationIds))>
                  <span>{{ $loc->name }}@if($loc->is_default) <small>default</small>@endif</span>
                </label>
              @endforeach
            </div>
            <button class="ia-btn ia-btn--primary">Save locations</button>
          </form>
        </div>
      </div>

      {{-- owners/salaried staff opt out of the clock-in nudge --}}
      <div class="tm-line tm-row">
        <div class="tm-k">Clock-in prompt</div>
        <div class="tm-v">
          <form method="POST" action="{{ $tmUpdate }}">
            @csrf @method('PATCH')
            <input type="hidden" name="op" value="toggle_timeclock_exempt">
            <span>{{ $member->exempt_from_timeclock ? 'Never clocks in' : 'Required' }}</span>
            <button class="ia-btn ia-btn--ghost">{{ $member->exempt_from_timeclock ? 'Require clock-in' : 'Mark as never clocks in' }}</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div>
    {{-- Sign-in --}}
    <div class="tm-sec">
      <div class="tm-lab">Sign-in</div>

      @if($pinLocked)
        <div class="ia-notice ia-notice--error" style="margin-bottom:12px">
          <strong>PIN is locked.</strong> Too many wrong attempts. Cooldown ends
          {{ $member->pin_locked_until->diffForHumans() }}.
        </div>
      @endif

      <div class="tm-line tm-row">
        <div class="tm-k">Password</div>
        <div class="tm-v">
          <form method="POST" action="{{ $tmUpdate }}">
            @csrf @method('PATCH')
            <input type="hidden" name="op" value="reset_password">
            <button class="ia-btn ia-btn--ghost" data-confirm="Reset password for {{ $member->name }}?">Reset password</button>
          </form>
        </div>
      </div>
      <div class="tm-line is-hint"><div class="tm-k"></div>
        <div class="tm-hint">Emails them a temporary password and shows it to you once. They choose their own at next sign-in.</div>
      </div>

      @if($pinModeOn)
      <div class="tm-line tm-row">
        <div class="tm-k">PIN</div>
        <div class="tm-v">
          @if($pinLocked)
            <span class="ia-badge ia-badge--cancelled">Locked</span>
            <form method="POST" action="{{ $tmUpdate }}">
              @csrf @method('PATCH')
              <input type="hidden" name="op" value="pin_unlock">
              <button class="ia-btn ia-btn--ghost" data-confirm="Unlock {{ $member->name }}'s PIN?">Unlock</button>
            </form>
          @elseif($member->pin_hash)
            <span class="ia-badge ia-badge--completed">Set</span>
          @else
            <span class="ia-badge ia-badge--pending">Not set</span>
          @endif
          @if($member->pin_hash)
            <form method="POST" action="{{ $tmUpdate }}">
              @csrf @method('PATCH')
              <input type="hidden" name="op" value="pin_force_reset">
              <button class="ia-btn ia-btn--ghost" data-confirm="Force {{ $member->name }} to set a new PIN?">Force reset</button>
            </form>
          @endif
        </div>
      </div>
      @endif
      {{-- per-user sign-out-everywhere removed (devices are tenant-scoped; see /admin/team/devices) --}}
    </div>

    {{-- Account: the destructive actions, quiet on purpose --}}
    <div class="tm-sec">
      <div class="tm-lab">Account</div>
      <div class="tm-quiet">
        <form method="POST" action="{{ $tmUpdate }}">
          @csrf @method('PATCH')
          <input type="hidden" name="op" value="toggle_active">
          <button data-confirm="{{ $member->is_active ? 'Deactivate' : 'Reactivate' }} {{ $member->name }}?">{{ $member->is_active ? 'Deactivate' : 'Reactivate' }}</button>
        </form>
        <form method="POST" action="{{ route('tenant.team.destroy', $member->id) }}">
          @csrf @method('DELETE')
          <button class="is-danger" data-confirm="Remove {{ $member->name }} from the team?">Remove from team</button>
        </form>
      </div>
      <div class="tm-hint" style="margin-top:8px">Deactivating blocks sign-in and keeps their history. Removing takes them off the team.</div>
    </div>
  </div>
</div>

<script>
/* MARKER-TEAM-MEMBER-LAYOUT: a Save button is live only once its form differs from what's saved,
   so pressing it always changes something. Without JS the buttons simply stay enabled. */
(function () {
  function snap(f) { return new URLSearchParams(new FormData(f)).toString(); }
  document.querySelectorAll('form[data-dirty]').forEach(function (f) {
    var btn = f.querySelector('button.ia-btn'); if (!btn) return;
    var start = snap(f);
    function sync() { var same = snap(f) === start; btn.disabled = same; btn.title = same ? 'Nothing changed yet' : ''; }
    f.addEventListener('input', sync); f.addEventListener('change', sync); sync();
  });
  var c = document.getElementById('ia-invite-copy');
  if (c) c.addEventListener('click', function () {
    var b = this, t = b.getAttribute('data-link');
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
      b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy link'; }, 1500);
    }, function () { b.textContent = 'Copy failed'; });
  });
})();
</script>
@endsection
