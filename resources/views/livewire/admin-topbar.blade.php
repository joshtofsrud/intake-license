{{-- MARKER-ADMIN-TOPBAR --}}
<div class="iat" wire:poll.60s>
    <style>
        .iat{display:flex;align-items:center;gap:2px;margin-right:6px}
        .iat-i{position:relative;width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;color:rgb(161 161 170);text-decoration:none}
        .iat-i:hover{background:rgba(255,255,255,.06);color:#fff}
        .iat-i svg{width:21px;height:21px}
        .iat-b{position:absolute;top:3px;right:1px;min-width:17px;height:17px;padding:0 4px;border-radius:9px;font-size:10.5px;font-weight:700;line-height:17px;text-align:center;box-sizing:border-box}
        .iat-b.v{background:#8b5cf6;color:#fff}.iat-b.l{background:#BEF264;color:#0a0a0a}.iat-b.r{background:#f47c7c;color:#0a0a0a}
        .iat-t{position:absolute;top:44px;right:-6px;z-index:60;width:260px;background:rgb(39 39 42);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:10px 12px;font-size:12.5px;line-height:1.5;color:#e8e8ec;box-shadow:0 10px 30px rgba(0,0,0,.45);display:none;white-space:normal;text-align:left}
        .iat-i:hover .iat-t{display:block}
        .iat-t b{display:block;font-size:13px;margin-bottom:3px}
        @media (max-width:640px){.iat{gap:0;margin-right:2px}.iat-i{width:34px;height:34px}.iat-t{display:none!important}}
        html:not(.dark) .iat-i{color:rgb(82 82 91)} html:not(.dark) .iat-i:hover{background:rgba(0,0,0,.05);color:#111}
    </style>
    @php
        $icons = [
            'inbox'    => ['v', 'M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859M2.25 13.5V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.5M2.25 13.5l2.58-7.743A2.25 2.25 0 016.964 4.5h10.072a2.25 2.25 0 012.134 1.257L21.75 13.5'],
            'calendar' => ['v', 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5'],
            'follow'   => ['l', 'M20.25 3.75v4.5m0-4.5h-4.5m4.5 0l-6 6m3 12c-8.284 0-15-6.716-15-15V4.5A2.25 2.25 0 014.5 2.25h1.372c.516 0 .966.351 1.091.852l1.106 4.423c.11.44-.054.902-.417 1.173l-1.293.97a1.062 1.062 0 00-.38 1.21 12.035 12.035 0 007.143 7.143c.441.162.928-.004 1.21-.38l.97-1.293a1.125 1.125 0 011.173-.417l4.423 1.106c.5.125.852.575.852 1.091V19.5a2.25 2.25 0 01-2.25 2.25h-2.25z'],
            'issues'   => ['r', 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z'],
        ];
    @endphp
    @foreach ($icons as $k => $ic)
        @if (isset($c[$k]))
            <a class="iat-i" href="{{ $c[$k]['url'] }}" aria-label="{{ $c[$k]['title'] }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $ic[1] }}"/></svg>
                @if ($c[$k]['n'] > 0)<span class="iat-b {{ $ic[0] }}">{{ $c[$k]['n'] > 99 ? '99+' : $c[$k]['n'] }}</span>@endif
                <span class="iat-t"><b>{{ $c[$k]['title'] }}</b>{{ $c[$k]['line'] }}</span>
            </a>
        @endif
    @endforeach
</div>
