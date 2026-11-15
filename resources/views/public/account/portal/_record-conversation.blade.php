{{-- MARKER-PORTAL-DETAIL: messages about one record + a compose box that tags
     the message with it. $conversation, $action, $aboutLabel --}}
<div class="ac-section-title" id="conversation">Questions about {{ $aboutLabel }}</div>
<div class="ac-msg-wrap">
  @if($conversation->isNotEmpty())
    <div class="ac-msgs" id="ac-msgs">
      @foreach($conversation as $m)
        <div class="ac-msg {{ $m->direction === 'in' ? 'me' : 'shop' }}">{{ $m->body }}
          <div class="ac-msg-t"><span class="ac-chan">{{ strtoupper($m->channel ?? 'web') }}</span>{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div></div>
      @endforeach
    </div>
  @endif
  <form method="POST" action="{{ $action }}" class="ac-compose">
    @csrf
    <textarea name="body" rows="2" maxlength="1200" required placeholder="Ask {{ tenant()->name }} about {{ $aboutLabel }}&hellip;"></textarea>
    <button type="submit" aria-label="Send">&uarr;</button>
  </form>
</div>
<div class="ac-legend" style="margin-top:0">Your message goes to {{ tenant()->name }} with {{ $aboutLabel }} attached, so they know what it's about. Replies show here and in <a href="{{ route('tenant.customer.portal.messages') }}" class="ac-link">Messages</a>, and arrive by text or email as usual.</div>
<script>
  (function () { var m = document.getElementById('ac-msgs'); if (m) { m.scrollTop = m.scrollHeight; } })();
</script>
