#!/usr/bin/env bash
# apply-old-school-photos.sh
# MARKER-OLD-SCHOOL-PHOTO — a photo can be the note.
#
# TWO THINGS NOTHING ELSE IN THE APP DOES, both of which matter here because
# a pad is used constantly where rental condition checks are occasional:
#
#   DOWNSCALING. A modern phone photo is 4-12 MB. Stored as-is, a shop taking
#   a few a day fills a disk and every page showing a thumbnail pulls the full
#   file over shop wifi. Images are scaled to a 1600px long edge at quality 82
#   — plenty for "the shock looked like this" — typically 200-400 KB.
#
#   ORIENTATION. Phones record rotation in EXIF rather than rotating the
#   pixels, and GD ignores it. Without this every second photo is sideways,
#   which reads as broken rather than as a missing feature.
#
# Both are best-effort: if GD is missing or a file is not a JPEG/PNG/WebP it
# is stored untouched rather than rejected. Losing the photo would be worse
# than storing a large one.
#
# Photos live in the tenant's own storage path, matching rental checks, and
# are deleted with the note — a scratch pad that leaves files behind forever
# is not a scratch pad.
set -e

cat <<'EOF' > database/migrations/2026_08_03_000200_add_photos_to_tenant_notes.php
<?php

// MARKER-OLD-SCHOOL-PHOTO

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo paths on a note.
 *
 * A JSON array rather than a child table: a note carries one or two photos,
 * they are never queried across, and they are written and deleted with the
 * note. A table would add a join for nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_notes', function (Blueprint $t) {
            $t->json('photos')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_notes', function (Blueprint $t) {
            $t->dropColumn('photos');
        });
    }
};
EOF
echo "created database/migrations/2026_08_03_000200_add_photos_to_tenant_notes.php"

python3 <<'PY'
import io

# ---------------------------------------------------------------- model
p = 'app/Models/Tenant/TenantNote.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-OLD-SCHOOL-PHOTO' not in s, 'already applied'

old = """    protected $fillable = [
        'tenant_id', 'location_id', 'body', 'customer_id',
        'created_by', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];"""
assert s.count(old) == 1, 'M1 model anchor'
s = s.replace(old, """    protected $fillable = [
        'tenant_id', 'location_id', 'body', 'photos', 'customer_id',
        'created_by', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        // MARKER-OLD-SCHOOL-PHOTO — storage paths, not URLs. A stored URL
        // breaks the day the disk or domain changes.
        'photos'       => 'array',
    ];""")

old = """    public function isOpen(): bool"""
assert s.count(old) == 1, 'M2 method anchor'
s = s.replace(old, """    /** @return array<int,string> public URLs for display */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])
            ->map(fn ($p) => \\Illuminate\\Support\\Facades\\Storage::disk('public')->url($p))
            ->all();
    }

    public function isOpen(): bool""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- controller
p = 'app/Http/Controllers/Tenant/NoteController.php'
s = io.open(p, encoding='utf-8').read()

old = """use Illuminate\\Support\\Facades\\Auth;"""
assert s.count(old) == 1, 'C0 use anchor'
s = s.replace(old, """use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Support\\Facades\\Storage;""")

old = """        $data = $request->validate([
            'body'        => ['required', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'string', 'max:64'],
            'back'        => ['nullable', 'string', 'max:2000'],
        ]);"""
assert s.count(old) == 1, 'C1 validate anchor'
s = s.replace(old, """        $data = $request->validate([
            // MARKER-OLD-SCHOOL-PHOTO — a photo alone is a valid note, so the
            // body is only required when no photo came with it.
            'body'        => ['required_without:photos', 'nullable', 'string', 'max:2000'],
            'photos'      => ['nullable', 'array', 'max:4'],
            'photos.*'    => ['image', 'max:20480'],
            'customer_id' => ['nullable', 'string', 'max:64'],
            'back'        => ['nullable', 'string', 'max:2000'],
        ]);""")

old = """        TenantNote::create([
            'tenant_id'   => $tenant->id,
            'location_id' => $request->session()->get('current_location_id'),
            'body'        => trim($data['body']),
            'customer_id' => $customerId,
            'created_by'  => Auth::guard('tenant')->id(),
        ]);"""
assert s.count(old) == 1, 'C2 create anchor'
s = s.replace(old, """        $photos = [];
        foreach ((array) $request->file('photos', []) as $file) {
            $stored = $this->storePhoto($tenant->id, $file);
            if ($stored !== null) {
                $photos[] = $stored;
            }
        }

        TenantNote::create([
            'tenant_id'   => $tenant->id,
            'location_id' => $request->session()->get('current_location_id'),
            'body'        => trim((string) ($data['body'] ?? '')),
            'photos'      => $photos ?: null,
            'customer_id' => $customerId,
            'created_by'  => Auth::guard('tenant')->id(),
        ]);""")

old = """    public function destroy(Request $request, string $id): RedirectResponse
    {
        $note = TenantNote::where('tenant_id', tenant()->id)->findOrFail($id);
        $note->delete();"""
assert s.count(old) == 1, 'C3 destroy anchor'
s = s.replace(old, """    public function destroy(Request $request, string $id): RedirectResponse
    {
        $note = TenantNote::where('tenant_id', tenant()->id)->findOrFail($id);

        // MARKER-OLD-SCHOOL-PHOTO — take the files with it. A scratch pad
        // that leaves images on disk forever is not a scratch pad.
        foreach ((array) ($note->photos ?? []) as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (\\Throwable $e) {
                Log::warning('note.photo_delete_failed', ['note' => $note->id, 'path' => $path]);
            }
        }

        $note->delete();""")

old = """    /**
     * Return to where the note was written."""
assert s.count(old) == 1, 'C4 helper anchor'
s = s.replace(old, """    /**
     * MARKER-OLD-SCHOOL-PHOTO — store one photo, downscaled and upright.
     *
     * A phone photo is 4-12 MB and records its rotation in EXIF rather than
     * in the pixels. Stored raw, a busy pad fills a disk and half the images
     * appear sideways. Scaled to a 1600px long edge at quality 82 — ample for
     * "it looked like this" — and rotated to match the EXIF orientation.
     *
     * Every step is best-effort. If GD is unavailable, or the file is not a
     * format it can read, the original is stored untouched: an oversized
     * photo beats a lost one.
     *
     * @return string|null storage path, or null if it could not be saved
     */
    private function storePhoto(string $tenantId, $file): ?string
    {
        $dir = 'tenants/' . $tenantId . '/notes';

        try {
            if (! function_exists('imagecreatefromstring')) {
                return Storage::disk('public')->putFile($dir, $file);
            }

            $raw = @file_get_contents($file->getRealPath());
            $img = $raw ? @imagecreatefromstring($raw) : false;

            if ($img === false) {
                return Storage::disk('public')->putFile($dir, $file);
            }

            // EXIF first: rotating after scaling would scale to the wrong
            // aspect for a portrait shot.
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($file->getRealPath());
                $o = (int) ($exif['Orientation'] ?? 0);
                if ($o === 3)      { $img = imagerotate($img, 180, 0); }
                elseif ($o === 6)  { $img = imagerotate($img, -90, 0); }
                elseif ($o === 8)  { $img = imagerotate($img, 90, 0); }
            }

            $w = imagesx($img);
            $h = imagesy($img);
            $long = max($w, $h);

            if ($long > 1600) {
                $scaled = imagescale($img, (int) round($w * 1600 / $long), (int) round($h * 1600 / $long));
                if ($scaled !== false) {
                    imagedestroy($img);
                    $img = $scaled;
                }
            }

            $tmp = tempnam(sys_get_temp_dir(), 'note');
            imagejpeg($img, $tmp, 82);
            imagedestroy($img);

            $path = $dir . '/' . \\Illuminate\\Support\\Str::uuid() . '.jpg';
            Storage::disk('public')->put($path, file_get_contents($tmp));
            @unlink($tmp);

            return $path;
        } catch (\\Throwable $e) {
            Log::error('note.photo_failed', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
            try {
                return Storage::disk('public')->putFile($dir, $file);
            } catch (\\Throwable $e2) {
                return null;
            }
        }
    }

    /**
     * Return to where the note was written.""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- pad form
p = 'resources/views/layouts/tenant/_notes-pad.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """    <form method="POST" action="{{ route('tenant.notes.store') }}" class="pad-new">"""
assert s.count(old) == 1, 'V1 pad form anchor'
s = s.replace(old, """    {{-- MARKER-OLD-SCHOOL-PHOTO — enctype is required or the files are
         silently dropped and the note saves without them. --}}
    <form method="POST" action="{{ route('tenant.notes.store') }}" class="pad-new"
          enctype="multipart/form-data">""")

old = """        <button type="submit" class="pad-add">Add note</button>
      </div>"""
assert s.count(old) == 1, 'V2 pad foot anchor'
s = s.replace(old, """        {{-- MARKER-OLD-SCHOOL-PHOTO — capture="environment" makes a phone
             open the rear camera rather than the photo library. --}}
        <label class="pad-cam" title="Add a photo">
          <input type="file" name="photos[]" accept="image/*" capture="environment" multiple hidden data-pad-photos>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 7h3l2-2h8l2 2h3v12H3z"/><circle cx="12" cy="13" r="3.5"/>
          </svg>
          <span data-pad-photo-count hidden></span>
        </label>
        <button type="submit" class="pad-add">Add note</button>
      </div>""")

old = """  .pad-add { margin-left:auto;"""
assert s.count(old) == 1, 'V3 style anchor'
s = s.replace(old, """  /* MARKER-OLD-SCHOOL-PHOTO */
  .pad-cam { margin-left:auto; display:inline-flex; align-items:center; gap:5px; cursor:pointer;
             border:1px solid #D9CDB0; border-radius:8px; padding:6px 9px; color:#5A5343;
             background:#FBF7EC; font-size:11.5px; }
  .pad-cam:hover { background:#EDE3CC; }
  .pad-add { """)

old = """  window.addEventListener( 'resize', function () {
    if ( !panel.hasAttribute( 'hidden' ) ) { place(); }
  } );"""
assert s.count(old) == 1, 'V4 script anchor'
s = s.replace(old, """  window.addEventListener( 'resize', function () {
    if ( !panel.hasAttribute( 'hidden' ) ) { place(); }
  } );

  /* MARKER-OLD-SCHOOL-PHOTO — a hidden file input gives no feedback that
     anything was picked, so say how many. */
  var photos = panel.querySelector( '[data-pad-photos]' );
  var pcount = panel.querySelector( '[data-pad-photo-count]' );
  if ( photos && pcount ) {
    photos.addEventListener( 'change', function () {
      var n = photos.files ? photos.files.length : 0;
      if ( n > 0 ) {
        pcount.textContent = n;
        pcount.removeAttribute( 'hidden' );
      } else {
        pcount.setAttribute( 'hidden', '' );
      }
    } );
  }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- lists
for f, anchor, add in [
    ('resources/views/tenant/notes/index.blade.php',
     """        <div class="np-text">{{ $n->body }}</div>""",
     """        <div class="np-text">{{ $n->body }}</div>
        {{-- MARKER-OLD-SCHOOL-PHOTO --}}
        @if($n->photos)
          <div class="np-shots">
            @foreach($n->photoUrls() as $u)
              <a href="{{ $u }}" target="_blank" rel="noopener"><img src="{{ $u }}" alt="" loading="lazy"></a>
            @endforeach
          </div>
        @endif"""),
    ('resources/views/tenant/_notes-banner.blade.php',
     """          <div class="nb-text">{{ $bn->body }}</div>""",
     """          <div class="nb-text">{{ $bn->body }}</div>
          {{-- MARKER-OLD-SCHOOL-PHOTO --}}
          @if($bn->photos)
            <div class="np-shots">
              @foreach($bn->photoUrls() as $u)
                <a href="{{ $u }}" target="_blank" rel="noopener"><img src="{{ $u }}" alt="" loading="lazy"></a>
              @endforeach
            </div>
          @endif"""),
]:
    s = io.open(f, encoding='utf-8').read()
    assert s.count(anchor) == 1, 'list anchor in ' + f
    s = s.replace(anchor, add)
    io.open(f, 'w', encoding='utf-8').write(s)
    print('patched', f)

# shared thumbnail styling, once
p = 'resources/views/tenant/notes/index.blade.php'
s = io.open(p, encoding='utf-8').read()
old = """  .np-del { background:none;"""
assert s.count(old) == 1, 'V5 style anchor'
s = s.replace(old, """  /* MARKER-OLD-SCHOOL-PHOTO */
  .np-shots { display:flex; gap:6px; margin-top:7px; flex-wrap:wrap; }
  .np-shots img { width:64px; height:64px; object-fit:cover; border-radius:6px; display:block;
                  border:1px solid #D9CDB0; }
  .np-del { background:none;""")
io.open(p, 'w', encoding='utf-8').write(s)

p = 'resources/views/tenant/_notes-banner.blade.php'
s = io.open(p, encoding='utf-8').read()
old = """    .nb-foot { border-top:1px solid #D9CDB0;"""
assert s.count(old) == 1, 'V6 banner style anchor'
s = s.replace(old, """    /* MARKER-OLD-SCHOOL-PHOTO */
    .np-shots { display:flex; gap:6px; margin-top:7px; flex-wrap:wrap; }
    .np-shots img { width:64px; height:64px; object-fit:cover; border-radius:6px; display:block;
                    border:1px solid #D9CDB0; }
    .nb-foot { border-top:1px solid #D9CDB0;""")
io.open(p, 'w', encoding='utf-8').write(s)
print('thumbnail styles added')
PY

echo
echo "--- form can actually carry files ---"
grep -c 'enctype="multipart/form-data"' resources/views/layouts/tenant/_notes-pad.blade.php

echo
echo "--- body is only required without a photo ---"
grep -n "required_without:photos" app/Http/Controllers/Tenant/NoteController.php

echo
echo "--- photos deleted with the note ---"
grep -n "photo_delete_failed" app/Http/Controllers/Tenant/NoteController.php

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re, subprocess, os
pat = re.compile(r'\B@(@?\w+(?:::\w+)?)([ \t]*)(\()?', re.X)
OPEN  = {'if','unless','isset','auth','guest','forelse','foreach','for','while','php','section','error','once'}
CLOSE = {'endif','endunless','endisset','endempty','endauth','endguest','endforelse','endforeach','endfor','endwhile','endphp','endsection','enderror','endonce'}
for f in ['resources/views/layouts/tenant/_notes-pad.blade.php',
          'resources/views/tenant/notes/index.blade.php',
          'resources/views/tenant/_notes-banner.blade.php']:
    raw = io.open(f, encoding='utf-8').read()
    s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' '*len(m.group(0)), raw, flags=re.S)
    g = len(re.findall(r'\w@(?:if|endif|foreach|endforeach|forelse|endforelse|else|elseif|php|endphp|csrf)\b', s))
    d = 0
    for m in re.finditer(r'@(\w+)', s):
        if not pat.match(s, m.start()): continue
        if m.group(1) in OPEN: d += 1
        elif m.group(1) in CLOSE: d -= 1
    print('  %-34s glued=%d depth=%d %s' % (f.split('/')[-1], g, d, 'OK' if (g==0 and d==0) else '*** CHECK ***'))

raw = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
js = re.findall(r'<script[^>]*>(.*?)</script>', raw, flags=re.S)[0]
out, i = [], 0
while i < len(js):
    if js.startswith('@json(', i):
        d2 = 0; j = i + 5
        while j < len(js):
            if js[j] == '(': d2 += 1
            elif js[j] == ')':
                d2 -= 1
                if d2 == 0: break
            j += 1
        out.append('"/x"'); i = j + 1
    else:
        out.append(js[i]); i += 1
os.makedirs('/tmp/pad6', exist_ok=True)
io.open('/tmp/pad6/p.js','w',encoding='utf-8').write(''.join(out))
r = subprocess.run(['node','--check','/tmp/pad6/p.js'], capture_output=True, text=True)
print('  pad JS:', 'OK' if r.returncode==0 else 'FAIL\n'+r.stderr[:300])
PY

echo
echo "--- php balance ---"
python3 - <<'PY'
import io
for p in ['app/Http/Controllers/Tenant/NoteController.php',
          'app/Models/Tenant/TenantNote.php',
          'database/migrations/2026_08_03_000200_add_photos_to_tenant_notes.php']:
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
    print('%-52s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-old-school-photos: OK"
