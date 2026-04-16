<div id="new-appt-modal" style="display:none">
  <style>
    #new-appt-backdrop {
      position: fixed; inset: 0;
      background: rgba(0,0,0,.6);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      padding: 20px;
      animation: appt-fade .2s ease-out;
    }
    @keyframes appt-fade { from { opacity: 0; } to { opacity: 1; } }
    #new-appt-card {
      background: var(--ia-surface, #1a1a1a);
      color: var(--ia-text, #f0f0f0);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-lg, 16px);
      width: 100%; max-width: 520px;
      max-height: 90vh;
      overflow-y: auto;
      animation: appt-pop .25s cubic-bezier(.2,1.1,.3,1);
    }
    @keyframes appt-pop { from { transform: scale(.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .appt-head {
      padding: 24px 28px 0;
      display: flex; justify-content: space-between; align-items: center;
    }
    .appt-title { font-size: 20px; font-weight: 700; }
    .appt-close {
      background: none; border: none; color: inherit;
      font-size: 24px; cursor: pointer; opacity: .5;
      padding: 4px 8px; line-height: 1;
    }
    .appt-close:hover { opacity: 1; }
    .appt-body { padding: 20px 28px; }
    .appt-field { margin-bottom: 16px; }
    .appt-label {
      display: block; font-size: 12px; font-weight: 500;
      opacity: .6; text-transform: uppercase; letter-spacing: .06em;
      margin-bottom: 6px;
    }
    .appt-input {
      width: 100%; padding: 10px 14px;
      background: rgba(255,255,255,.04);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-md, 8px);
      color: var(--ia-text, #f0f0f0);
      font-size: 14px; font-family: inherit;
      transition: border-color .12s;
    }
    .appt-input:focus { outline: none; border-color: var(--ia-accent, #BEF264); }
    .appt-textarea { resize: vertical; min-height: 80px; }
    .appt-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .appt-foot {
      padding: 16px 28px 22px;
      border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      display: flex; justify-content: flex-end; gap: 10px;
    }
    .appt-btn {
      padding: 10px 20px; border-radius: var(--ia-r-md, 8px);
      font-size: 14px; font-weight: 600; cursor: pointer;
      font-family: inherit; border: none; transition: filter .12s;
    }
    .appt-btn--cancel { background: rgba(255,255,255,.06); color: var(--ia-text, #f0f0f0); }
    .appt-btn--create { background: var(--ia-accent, #BEF264); color: #000; }
    .appt-btn:hover { filter: brightness(.92); }
    .appt-btn:disabled { opacity: .5; cursor: not-allowed; }
    .appt-err {
      background: rgba(226,75,74,.12); color: #f39999;
      border-radius: 8px; padding: 10px 14px;
      font-size: 13px; margin-bottom: 12px; display: none;
    }
    .appt-spin {
      display: inline-block; width: 12px; height: 12px;
      border: 2px solid currentColor; border-right-color: transparent;
      border-radius: 50%; animation: appt-spin .6s linear infinite;
      vertical-align: -2px; margin-right: 6px;
    }
    @keyframes appt-spin { to { transform: rotate(360deg); } }
  </style>

  <div id="new-appt-backdrop">
    <div id="new-appt-card">
      <div class="appt-head">
        <span class="appt-title">New Appointment</span>
        <button type="button" class="appt-close" onclick="closeApptModal()">&times;</button>
      </div>

      <div class="appt-body">
        <div id="appt-error" class="appt-err"></div>

        <div class="appt-row">
          <div class="appt-field">
            <label class="appt-label" for="appt-first">First name *</label>
            <input type="text" class="appt-input" id="appt-first" placeholder="Jane" required>
          </div>
          <div class="appt-field">
            <label class="appt-label" for="appt-last">Last name *</label>
            <input type="text" class="appt-input" id="appt-last" placeholder="Smith" required>
          </div>
        </div>

        <div class="appt-row">
          <div class="appt-field">
            <label class="appt-label" for="appt-email">Email *</label>
            <input type="email" class="appt-input" id="appt-email" placeholder="jane@example.com" required>
          </div>
          <div class="appt-field">
            <label class="appt-label" for="appt-phone">Phone</label>
            <input type="tel" class="appt-input" id="appt-phone" placeholder="+1 (555) 000-0000">
          </div>
        </div>

        <div class="appt-field">
          <label class="appt-label" for="appt-date">Appointment date *</label>
          <input type="date" class="appt-input" id="appt-date" value="{{ now()->format('Y-m-d') }}" required>
        </div>

        <div class="appt-field">
          <label class="appt-label" for="appt-notes">Staff notes</label>
          <textarea class="appt-input appt-textarea" id="appt-notes" placeholder="Internal notes about this appointment..."></textarea>
        </div>
      </div>

      <div class="appt-foot">
        <button type="button" class="appt-btn appt-btn--cancel" onclick="closeApptModal()">Cancel</button>
        <button type="button" class="appt-btn appt-btn--create" id="appt-submit" onclick="submitAppt()">
          Create Appointment
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function openApptModal() {
  document.getElementById('new-appt-modal').style.display = 'block';
  document.getElementById('appt-first').focus();
}

function closeApptModal() {
  document.getElementById('new-appt-modal').style.display = 'none';
  document.getElementById('appt-error').style.display = 'none';
  // Clear form
  ['appt-first','appt-last','appt-email','appt-phone','appt-notes'].forEach(function(id) {
    document.getElementById(id).value = '';
  });
  document.getElementById('appt-date').value = new Date().toISOString().split('T')[0];
}

async function submitAppt() {
  var btn = document.getElementById('appt-submit');
  var errBox = document.getElementById('appt-error');
  errBox.style.display = 'none';
  btn.disabled = true;
  btn.innerHTML = '<span class="appt-spin"></span>Creating...';

  try {
    var fd = new FormData();
    fd.append('customer_first_name', document.getElementById('appt-first').value);
    fd.append('customer_last_name',  document.getElementById('appt-last').value);
    fd.append('customer_email',      document.getElementById('appt-email').value);
    fd.append('customer_phone',      document.getElementById('appt-phone').value);
    fd.append('appointment_date',    document.getElementById('appt-date').value);
    fd.append('staff_notes',         document.getElementById('appt-notes').value);

    var csrf = document.querySelector('meta[name="csrf-token"]');
    var res = await fetch("{{ route('tenant.appointments.store') }}", {
      method: 'POST',
      body: fd,
      headers: {
        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
        'Accept': 'application/json'
      },
      credentials: 'same-origin'
    });

    if (!res.ok) {
      var text = await res.text();
      try {
        var json = JSON.parse(text);
        if (json.errors) {
          var msgs = Object.values(json.errors).flat().join(' ');
          throw new Error(msgs);
        }
        throw new Error(json.message || 'Server error');
      } catch(e) {
        if (e.message) throw e;
        throw new Error('Server error (' + res.status + ')');
      }
    }

    var json = await res.json();
    if (json.redirect) {
      window.location.href = json.redirect;
    } else {
      window.location.reload();
    }
  } catch(e) {
    errBox.textContent = e.message || 'Something went wrong.';
    errBox.style.display = 'block';
    btn.disabled = false;
    btn.innerHTML = 'Create Appointment';
  }
}
</script>
