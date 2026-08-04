#!/usr/bin/env bash
# apply-old-school-notes.sh
# MARKER-OLD-SCHOOL — a scratch pad in the corner.
#
# Stage one of four: the pad itself. Banners, photos and reporting follow.
#
# DELIBERATELY NOT tenant_customer_notes. That table already exists and is a
# customer's permanent record — shown on their page, written automatically by
# the class controller. Old School is a scratch pad: a note may POINT AT a
# customer so it can be found and later surfaced, but it never becomes part of
# their history, and crossing one off makes it go away rather than filing it.
# Mixing the two would turn machine-written log entries into open tasks
# nobody chose to create, which would poison the list on day one.
#
# Every note carries a completed state from the moment it is written, because
# on paper you do not decide up front whether something is a task — you write
# it and cross it out later.
#
# No JavaScript beyond opening the panel. Adding and ticking are plain form
# posts that redirect back to where you were, so capture cannot fail silently
# and nothing depends on a fetch that might not fire.
set -e

cat <<'EOF' > database/migrations/2026_08_03_000100_create_tenant_notes.php
<?php

// MARKER-OLD-SCHOOL

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pad.
 *
 * customer_id is a POINTER, not a filing. It exists so a note can be found
 * and surfaced next to the person it concerns; it is not that customer's
 * history, which lives in tenant_customer_notes and is untouched by this.
 *
 * completed_at doubles as the archive: open is null, crossed off is a time.
 * A third "archived" state was considered and dropped — a pad has two piles,
 * not three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_notes', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Where it was written. Nullable because a note is not location
            // work — it just helps a multi-location shop filter later.
            $t->foreignUuid('location_id')
                ->nullable()
                ->constrained('tenant_locations')
                ->nullOnDelete();

            $t->text('body');

            // Pointer, not history. nullOnDelete so removing a customer
            // leaves the note readable rather than deleting someone's
            // reminder along with them.
            $t->foreignUuid('customer_id')
                ->nullable()
                ->constrained('tenant_customers')
                ->nullOnDelete();

            $t->foreignUuid('created_by')
                ->nullable()
                ->constrained('tenant_users')
                ->nullOnDelete();

            $t->timestamp('completed_at')->nullable();
            $t->foreignUuid('completed_by')
                ->nullable()
                ->constrained('tenant_users')
                ->nullOnDelete();

            $t->timestamps();

            // The two reads that matter: the open pile, and open notes for
            // one customer (which is what the banner will ask for).
            $t->index(['tenant_id', 'completed_at']);
            $t->index(['tenant_id', 'customer_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notes');
    }
};
EOF
echo "created database/migrations/2026_08_03_000100_create_tenant_notes.php"

cat <<'EOF' > app/Models/Tenant/TenantNote.php
<?php

// MARKER-OLD-SCHOOL

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note on the pad.
 *
 * Not a customer record. customer_id points at someone so the note can be
 * found and surfaced; it is never written into their history.
 */
class TenantNote extends Model
{
    use HasUuids;

    protected $table = 'tenant_notes';

    protected $fillable = [
        'tenant_id', 'location_id', 'body', 'customer_id',
        'created_by', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'completed_by');
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null;
    }

    /** Days since writing — what makes a stale pad visible. */
    public function ageInDays(): int
    {
        return (int) $this->created_at?->diffInDays(now());
    }
}
EOF
echo "created app/Models/Tenant/TenantNote.php"

cat <<'EOF' > app/Http/Controllers/Tenant/NoteController.php
<?php

// MARKER-OLD-SCHOOL

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class NoteController extends Controller
{
    /** The pad, full page. Open and crossed-off. */
    public function index(Request $request): View
    {
        $tenant = tenant();
        $tab = $request->input('tab') === 'done' ? 'done' : 'open';

        $q = TenantNote::where('tenant_id', $tenant->id)
            ->with(['customer', 'author', 'completer']);

        if ($tab === 'done') {
            $notes = (clone $q)->whereNotNull('completed_at')
                ->orderByDesc('completed_at')->paginate(40);
        } else {
            // Oldest first: a pad is cleared from the bottom of the pile, and
            // the stale ones are the whole reason to look.
            $notes = (clone $q)->whereNull('completed_at')
                ->orderBy('created_at')->paginate(40);
        }

        return view('tenant.notes.index', [
            'notes'     => $notes,
            'tab'       => $tab,
            'openCount' => self::openCount(),
            'doneCount' => TenantNote::where('tenant_id', $tenant->id)
                ->whereNotNull('completed_at')->count(),
            'oldest'    => TenantNote::where('tenant_id', $tenant->id)
                ->whereNull('completed_at')->orderBy('created_at')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();

        $data = $request->validate([
            'body'        => ['required', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'string', 'max:64'],
            'back'        => ['nullable', 'string', 'max:2000'],
        ]);

        // A customer from another tenant would be a data leak, so it is
        // verified rather than trusted from the form.
        $customerId = null;
        if (! empty($data['customer_id'])) {
            $customerId = TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $data['customer_id'])->value('id');
        }

        TenantNote::create([
            'tenant_id'   => $tenant->id,
            'location_id' => $request->session()->get('current_location_id'),
            'body'        => trim($data['body']),
            'customer_id' => $customerId,
            'created_by'  => Auth::guard('tenant')->id(),
        ]);

        return $this->backTo($data['back'] ?? null)->with('flash', [
            'type' => 'success', 'message' => 'Note added.',
        ]);
    }

    /** Cross off, or put back. One button, both directions. */
    public function toggle(Request $request, string $id): RedirectResponse
    {
        $note = TenantNote::where('tenant_id', tenant()->id)->findOrFail($id);

        if ($note->completed_at === null) {
            $note->update([
                'completed_at' => now(),
                'completed_by' => Auth::guard('tenant')->id(),
            ]);
            $msg = 'Crossed off.';
        } else {
            $note->update(['completed_at' => null, 'completed_by' => null]);
            $msg = 'Back on the pad.';
        }

        return $this->backTo($request->input('back'))->with('flash', [
            'type' => 'success', 'message' => $msg,
        ]);
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $note = TenantNote::where('tenant_id', tenant()->id)->findOrFail($id);
        $note->delete();

        return $this->backTo($request->input('back'))->with('flash', [
            'type' => 'success', 'message' => 'Note deleted.',
        ]);
    }

    /** Open notes for the whole shop — the badge on the pad button. */
    public static function openCount(): int
    {
        return TenantNote::where('tenant_id', tenant()->id)
            ->whereNull('completed_at')->count();
    }

    /**
     * Return to where the note was written.
     *
     * Only same-host paths are honoured — an open redirect from a posted
     * field is exactly the kind of thing that looks harmless in a scratch
     * pad and is not.
     */
    private function backTo(?string $back): RedirectResponse
    {
        if (is_string($back) && str_starts_with($back, '/') && ! str_starts_with($back, '//')) {
            return redirect()->to($back);
        }
        return redirect()->back();
    }
}
EOF
echo "created app/Http/Controllers/Tenant/NoteController.php"

python3 <<'PY'
import io

# ---------------------------------------------------------------- routes
p = 'routes/web.php'
s = io.open(p, encoding='utf-8').read()

assert 'NoteController' not in s, 'routes already added'

old = """            Route::prefix('vendors')->name('vendors.')->group(function () {"""
assert s.count(old) == 1, 'R1 vendors group anchor'
s = s.replace(old, """            // MARKER-OLD-SCHOOL — the pad.
            Route::prefix('notes')->name('notes.')->group(function () {
                Route::get('/',              [TenantControllers\\NoteController::class, 'index'])->name('index');
                Route::post('/',             [TenantControllers\\NoteController::class, 'store'])->name('store');
                Route::post('/{id}/toggle',  [TenantControllers\\NoteController::class, 'toggle'])->name('toggle');
                Route::delete('/{id}',       [TenantControllers\\NoteController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('vendors')->name('vendors.')->group(function () {""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- header
p = 'resources/views/layouts/tenant/_attention-row.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  {{-- alerts bell (existing dropdown, rebuilt) --}}
  @include('layouts.tenant._staff-alerts-bell')"""
assert s.count(old) == 1, 'H1 attention row anchor'
s = s.replace(old, """  {{-- MARKER-OLD-SCHOOL — the pad. Sits with the things that want attention
       now, because an open note is exactly that. --}}
  @include('layouts.tenant._notes-pad')

  {{-- alerts bell (existing dropdown, rebuilt) --}}
  @include('layouts.tenant._staff-alerts-bell')""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

cat <<'EOF' > resources/views/layouts/tenant/_notes-pad.blade.php
{{-- MARKER-OLD-SCHOOL — the pad button and its panel.

     Adding and ticking are plain form posts that return to the current page,
     so capture works with no JavaScript at all. The only script here opens
     and closes the panel. --}}
@php
  $padNotes = \App\Models\Tenant\TenantNote::where('tenant_id', tenant()->id)
      ->whereNull('completed_at')
      ->with('customer')
      ->orderBy('created_at')
      ->limit(8)
      ->get();
  $padOpen = \App\Http\Controllers\Tenant\NoteController::openCount();
  // Pages may pre-attach a customer by setting $noteCustomer. That is the
  // whole friction saving — the note already knows who it is about.
  $padCustomer = $noteCustomer ?? null;
@endphp

<div class="pad" data-pad>
  <button type="button" class="pad-btn" data-pad-toggle aria-label="Notes" title="Notes">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <path d="M4 4h11l5 5v11H4z"/><path d="M8 10h8M8 14h5"/>
    </svg>
    @if($padOpen > 0)<span class="pad-badge">{{ $padOpen > 99 ? '99+' : $padOpen }}</span>@endif
  </button>

  <div class="pad-panel" data-pad-panel hidden>
    <form method="POST" action="{{ route('tenant.notes.store') }}" class="pad-new">
      @csrf
      <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
      @if($padCustomer)
        <input type="hidden" name="customer_id" value="{{ $padCustomer->id }}">
      @endif
      <textarea name="body" rows="3" placeholder="Write it down…" required></textarea>
      <div class="pad-new-foot">
        @if($padCustomer)
          <span class="pad-chip">{{ $padCustomer->first_name }} {{ $padCustomer->last_name }}</span>
        @else
          <span class="pad-hint">no customer</span>
        @endif
        <button type="submit" class="pad-add">Add note</button>
      </div>
    </form>

    <div class="pad-list">
      @forelse($padNotes as $n)
        <div class="pad-note">
          <form method="POST" action="{{ route('tenant.notes.toggle', $n->id) }}">
            @csrf
            <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
            <button type="submit" class="pad-box" aria-label="Cross off"></button>
          </form>
          <div class="pad-body">
            <div class="pad-text">{{ $n->body }}</div>
            <div class="pad-meta">
              @if($n->customer)
                <span class="pad-who">{{ $n->customer->first_name }} {{ $n->customer->last_name }}</span>
              @endif
              <span @class(['pad-age' => $n->ageInDays() >= 7])>
                {{ $n->created_at?->diffForHumans() }}
              </span>
            </div>
          </div>
        </div>
      @empty
        <div class="pad-empty">Nothing on the pad.</div>
      @endforelse
    </div>

    <a href="{{ route('tenant.notes.index') }}" class="pad-foot">
      Open the pad
      @if($padOpen > count($padNotes)) · {{ $padOpen }} total @endif
    </a>
  </div>
</div>

<style>
  .pad { position:relative; }
  .pad-btn { position:relative; width:36px; height:36px; border-radius:10px; display:flex; align-items:center;
             justify-content:center; background:transparent; border:none; cursor:pointer; color:var(--ia-text);
             opacity:.55; transition:opacity .15s ease, background .15s ease; }
  .pad-btn:hover { opacity:1; background:rgba(127,127,127,.09); }
  .pad-btn:focus { outline:none; }
  .pad-badge { position:absolute; top:-4px; right:-4px; min-width:16px; height:16px; padding:0 4px;
               border-radius:999px; background:#B8860B; color:#fff; font-size:10px; font-weight:700;
               display:flex; align-items:center; justify-content:center; }

  .pad-panel { position:fixed; width:320px; z-index:9000; border-radius:12px; overflow:hidden;
               background:#F4ECD8; color:#2A2419;
               box-shadow:0 8px 30px rgba(0,0,0,.28), inset 0 0 0 .5px rgba(0,0,0,.12); }

  .pad-new { padding:12px 12px 10px; border-bottom:1px solid #D9CDB0; }
  .pad-new textarea { width:100%; border:1px solid #D9CDB0; border-radius:8px; padding:9px 10px;
                      font-family:inherit; font-size:13px; line-height:1.5; resize:vertical;
                      background:#FBF7EC; color:#2A2419; }
  .pad-new textarea:focus { outline:none; border-color:#B8860B; }
  .pad-new-foot { display:flex; align-items:center; gap:8px; margin-top:8px; }
  .pad-chip { background:#DBE6D5; color:#33452C; border-radius:5px; padding:2px 8px; font-size:11px; font-weight:600; }
  .pad-hint { font-size:11px; color:#7A7159; }
  .pad-add { margin-left:auto; background:#B8860B; color:#fff; border:none; border-radius:8px;
             padding:7px 13px; font-size:12px; font-weight:650; cursor:pointer; font-family:inherit; }

  .pad-list { max-height:320px; overflow-y:auto; }
  .pad-note { display:flex; gap:10px; align-items:flex-start; padding:10px 12px; border-bottom:1px solid #D9CDB0; }
  .pad-box { width:18px; height:18px; border:1.6px solid #8D8267; border-radius:4px; background:#FBF7EC;
             cursor:pointer; flex:none; margin-top:1px; padding:0; }
  .pad-box:hover { background:#8D8267; }
  .pad-body { flex:1; min-width:0; }
  .pad-text { font-size:13px; line-height:1.45; word-break:break-word; }
  .pad-meta { display:flex; gap:7px; align-items:center; margin-top:4px; font-size:10.5px; color:#7A7159; }
  .pad-who { background:#E3D8BD; color:#4A4231; border-radius:4px; padding:1px 6px; font-weight:600; }
  .pad-age { color:#A8622A; font-weight:600; }
  .pad-empty { padding:18px 12px; font-size:12.5px; color:#7A7159; text-align:center; }
  .pad-foot { display:block; padding:10px 12px; font-size:12px; color:#5A5343; text-decoration:none;
              border-top:1px solid #D9CDB0; background:#EDE3CC; }
  .pad-foot:hover { background:#E5DAC0; }
</style>

<script>
( function () {
  var wrap = document.querySelector( '[data-pad]' );
  if ( !wrap ) { return; }
  var btn   = wrap.querySelector( '[data-pad-toggle]' );
  var panel = wrap.querySelector( '[data-pad-panel]' );
  if ( !btn || !panel ) { return; }

  function place() {
    var r = btn.getBoundingClientRect();
    panel.style.top = ( r.bottom + 8 ) + 'px';
    // Keep it on screen on narrow viewports rather than hanging off the edge.
    var left = Math.min( r.right - 320, window.innerWidth - 330 );
    panel.style.left = Math.max( 10, left ) + 'px';
  }

  btn.addEventListener( 'click', function ( e ) {
    e.stopPropagation();
    var showing = !panel.hasAttribute( 'hidden' );
    if ( showing ) { panel.setAttribute( 'hidden', '' ); return; }
    place();
    panel.removeAttribute( 'hidden' );
    var ta = panel.querySelector( 'textarea' );
    if ( ta ) { ta.focus(); }
  } );

  document.addEventListener( 'click', function ( e ) {
    if ( !panel.hasAttribute( 'hidden' ) && !wrap.contains( e.target ) ) {
      panel.setAttribute( 'hidden', '' );
    }
  } );

  document.addEventListener( 'keydown', function ( e ) {
    if ( e.key === 'Escape' ) { panel.setAttribute( 'hidden', '' ); }
  } );

  window.addEventListener( 'resize', function () {
    if ( !panel.hasAttribute( 'hidden' ) ) { place(); }
  } );
}() );
</script>
EOF
echo "created resources/views/layouts/tenant/_notes-pad.blade.php"

mkdir -p resources/views/tenant/notes
cat <<'EOF' > resources/views/tenant/notes/index.blade.php
@extends('layouts.tenant.app')
@php $pageTitle = 'Notes'; @endphp

@section('content')

{{-- MARKER-OLD-SCHOOL --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">The pad</h1>
    <p class="ia-page-subtitle">
      Write it down, cross it off. Notes here are a scratch pad — they never go onto a customer's record.
    </p>
  </div>
</div>

@if($oldest && $oldest->ageInDays() >= 7)
  <div class="np-stale">
    Oldest note has been sitting for <b>{{ $oldest->ageInDays() }} days</b> — a pad nobody clears stops
    getting read.
  </div>
@endif

<div class="np-tabs">
  <a href="{{ route('tenant.notes.index') }}"
     class="np-tab {{ $tab === 'open' ? 'on' : '' }}">Open {{ $openCount }}</a>
  <a href="{{ route('tenant.notes.index', ['tab' => 'done']) }}"
     class="np-tab {{ $tab === 'done' ? 'on' : '' }}">Crossed off {{ $doneCount }}</a>
</div>

<div class="np-list">
  @forelse($notes as $n)
    <div class="np-note {{ $n->completed_at ? 'done' : '' }}">
      <form method="POST" action="{{ route('tenant.notes.toggle', $n->id) }}">
        @csrf
        <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
        <button type="submit" class="np-box {{ $n->completed_at ? 'on' : '' }}"
                aria-label="{{ $n->completed_at ? 'Put back on the pad' : 'Cross off' }}"></button>
      </form>

      <div class="np-body">
        <div class="np-text">{{ $n->body }}</div>
        <div class="np-meta">
          @if($n->customer)
            <a class="np-who" href="{{ route('tenant.customers.show', $n->customer->id) }}">
              {{ $n->customer->first_name }} {{ $n->customer->last_name }}
            </a>
          @endif
          <span>{{ $n->author?->name ?? 'someone' }} · {{ $n->created_at?->diffForHumans() }}</span>
          @if($n->completed_at)
            <span class="np-done-by">crossed off by {{ $n->completer?->name ?? 'someone' }}
              {{ $n->completed_at->diffForHumans() }}</span>
          @elseif($n->ageInDays() >= 7)
            <span class="np-age">{{ $n->ageInDays() }} days old</span>
          @endif
        </div>
      </div>

      <form method="POST" action="{{ route('tenant.notes.destroy', $n->id) }}"
            onsubmit="return confirm('Delete this note? It is not kept anywhere else.')">
        @csrf
        @method('DELETE')
        <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
        <button type="submit" class="np-del" aria-label="Delete">×</button>
      </form>
    </div>
  @empty
    <div class="np-empty">
      {{ $tab === 'done' ? 'Nothing crossed off yet.' : 'Nothing on the pad. Use the notes button up top.' }}
    </div>
  @endforelse
</div>

<div style="margin-top:14px">{{ $notes->withQueryString()->links() }}</div>

<style>
  .np-stale { background:rgba(184,134,11,.10); border:.5px solid rgba(184,134,11,.35); border-radius:9px;
              padding:10px 13px; font-size:12.5px; margin-bottom:14px; }
  .np-tabs { display:flex; gap:6px; margin-bottom:14px; }
  .np-tab { padding:6px 13px; border-radius:999px; font-size:12.5px; text-decoration:none;
            border:.5px solid var(--ia-border); color:var(--ia-text-dim); }
  .np-tab.on { background:#B8860B; border-color:#B8860B; color:#fff; font-weight:650; }

  .np-list { display:flex; flex-direction:column; gap:8px; }
  .np-note { display:flex; gap:11px; align-items:flex-start; background:#F4ECD8; color:#2A2419;
             border-radius:10px; padding:12px 13px; }
  .np-note.done { opacity:.62; }
  .np-box { width:19px; height:19px; border:1.6px solid #8D8267; border-radius:4px; background:#FBF7EC;
            cursor:pointer; flex:none; margin-top:1px; padding:0; position:relative; }
  .np-box.on { background:#8D8267; }
  .np-box.on:after { content:''; position:absolute; left:5px; top:1px; width:5px; height:10px;
                     border:solid #FBF7EC; border-width:0 2px 2px 0; transform:rotate(43deg); }
  .np-body { flex:1; min-width:0; }
  .np-text { font-size:13.5px; line-height:1.5; word-break:break-word; }
  .np-note.done .np-text { text-decoration:line-through; text-decoration-color:#6B6250; }
  .np-meta { display:flex; gap:9px; align-items:center; flex-wrap:wrap; margin-top:6px;
             font-size:10.5px; color:#7A7159; }
  .np-who { background:#DBE6D5; color:#33452C; border-radius:4px; padding:1px 7px; font-weight:600;
            text-decoration:none; }
  .np-age { color:#A8622A; font-weight:600; }
  .np-done-by { color:#5F7A55; }
  .np-del { background:none; border:none; color:#8D8267; font-size:17px; line-height:1; cursor:pointer;
            padding:0 2px; }
  .np-del:hover { color:#A8622A; }
  .np-empty { padding:28px; text-align:center; font-size:13px; color:var(--ia-text-dim);
              border:.5px dashed var(--ia-border); border-radius:10px; }
</style>

@endsection
EOF
echo "created resources/views/tenant/notes/index.blade.php"

echo
echo "--- routes wired ---"
grep -n "MARKER-OLD-SCHOOL" routes/web.php resources/views/layouts/tenant/_attention-row.blade.php | head

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re, subprocess, os
files = ['resources/views/layouts/tenant/_notes-pad.blade.php',
         'resources/views/tenant/notes/index.blade.php',
         'resources/views/layouts/tenant/_attention-row.blade.php']
for f in files:
    raw = io.open(f, encoding='utf-8').read()
    s = re.sub(r'\{\{--.*?--\}\}', '', raw, flags=re.S)
    out = [f.split('/')[-1], 'glued=%d' % len(re.findall(r'\w@(?:if|endif|foreach|endforeach|forelse|endforelse|else|elseif|php|endphp|csrf|method|class)\b', s))]
    for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@forelse','@endforelse'), ('@php','@endphp'), ('@section','@endsection')]:
        o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
        if o != c: out.append('MISMATCH %s %d/%d' % (a, o, c))
    print('  '.join(out))
    # the known trap
    for m in re.finditer(r'@php(.*?)@endphp', raw, re.S):
        if '{{--' in m.group(1):
            print('   *** blade comment inside @php ***')

os.makedirs('/tmp/pad', exist_ok=True)
js = re.findall(r'<script[^>]*>(.*?)</script>',
                io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read(), flags=re.S)[0]
io.open('/tmp/pad/p.js','w',encoding='utf-8').write(js)
r = subprocess.run(['node','--check','/tmp/pad/p.js'], capture_output=True, text=True)
print('pad JS:', 'OK' if r.returncode==0 else 'FAIL\n'+r.stderr[:300])
PY

echo
echo "--- no nested forms in the list ---"
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/notes/index.blade.php',
          'resources/views/layouts/tenant/_notes-pad.blade.php']:
    s = io.open(f, encoding='utf-8').read()
    d = 0; nested = False
    for m in re.finditer(r'<form\b|</form>', s):
        d += -1 if m.group(0) == '</form>' else 1
        if d > 1: nested = True
    print('  %-34s balanced=%s nested=%s' % (f.split('/')[-1], d == 0, nested))
PY

echo
echo "--- php balance ---"
python3 - <<'PY'
import io
for p in ['app/Models/Tenant/TenantNote.php',
          'app/Http/Controllers/Tenant/NoteController.php',
          'database/migrations/2026_08_03_000100_create_tenant_notes.php',
          'routes/web.php']:
    s = io.open(p, encoding='utf-8').read()
    i, n, d, par, brk = 0, len(s), 0, 0, 0
    while i < n:
        c = s[i]
        if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
            while i < n and s[i] != '\n': i += 1
        elif c == '/' and i+1 < n and s[i+1] == '*':
            i += 2
            while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
            i += 2
        elif c in '"\'':
            q = c; i += 1
            while i < n and s[i] != q:
                if s[i] == '\\': i += 1
                i += 1
            i += 1
        else:
            if c == '{': d += 1
            elif c == '}': d -= 1
            elif c == '(': par += 1
            elif c == ')': par -= 1
            elif c == '[': brk += 1
            elif c == ']': brk -= 1
            i += 1
    print('%-46s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "--- no private override of a Controller method ---"
python3 - <<'PY'
import io, re
s = io.open('app/Http/Controllers/Tenant/NoteController.php', encoding='utf-8').read()
reserved = {'middleware','authorize','validate','dispatch','callAction','__call'}
bad = [m for m in re.findall(r'(?:private|protected) function (\w+)\(', s) if m in reserved]
print('  clashes:', bad or 'none')
PY

echo
echo "apply-old-school-notes: OK"
