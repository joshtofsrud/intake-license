<!DOCTYPE html>
{{-- MARKER-OFFLINE-SYNC — branded offline fallback, precached by the service
     worker and served for any admin navigation that isn't cached. --}}
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Offline — Intake</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:-apple-system,'Inter',system-ui,sans-serif;background:#0B0B0B;color:#EDEDED;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{max-width:480px;text-align:center;background:#141414;border:1px solid #242424;border-radius:16px;padding:44px 32px}
  .logomark{width:44px;height:44px;background:#BEF264;border-radius:12px;position:relative;display:inline-block;margin-bottom:18px}
  .logomark i{position:absolute;left:9px;height:4.4px;background:#0B0B0B;border-radius:3px}
  .logomark i:nth-child(1){top:9px;width:25px}
  .logomark i:nth-child(2){top:19px;width:20px}
  .logomark i:nth-child(3){top:29px;width:15px}
  h1{font-size:20px;font-weight:800;letter-spacing:-.02em;margin-bottom:8px}
  p{font-size:13.5px;color:#9C9C9C;line-height:1.6;margin-bottom:20px}
  p b{color:#F5C56B}
  .btn{display:inline-block;background:#BEF264;color:#0B0B0B;font-weight:700;font-size:14px;border:none;border-radius:10px;padding:12px 22px;cursor:pointer;text-decoration:none;font-family:inherit;margin:0 4px}
  .btn.ghost{background:transparent;color:#EDEDED;border:1px solid #333}
</style>
</head>
<body>
  <div class="card">
    <span class="logomark"><i></i><i></i><i></i></span>
    <h1>This page needs a connection</h1>
    <p>You're offline. Your register, calendar, and time clock keep working from their last-loaded state<span id="qnote"></span>. Everything queued syncs the moment the connection returns.</p>
    <a class="btn" href="/admin/register">← Back to register</a>
    <button class="btn ghost" onclick="location.reload()">Retry</button>
  </div>
<script>
// Show queued-sale count from the outbox, best-effort.
try {
  const rq = indexedDB.open('intake-offline', 1);
  rq.onsuccess = () => {
    try {
      const c = rq.result.transaction('outbox').objectStore('outbox').count();
      c.onsuccess = () => {
        if (c.result > 0) document.getElementById('qnote').textContent =
          ' — ' + c.result + ' queued sale' + (c.result > 1 ? 's' : '') + ' waiting to sync';
      };
    } catch (e) {}
  };
} catch (e) {}
</script>
</body>
</html>
