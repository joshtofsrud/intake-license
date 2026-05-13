{{-- 
    Legal document section — long-form prose with optional TOC.
--}}
@php
    $sections = $c['sections'] ?? [];
    $showToc  = !empty($c['show_toc']);
    
    $slugify = function(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    };
@endphp

<style>
.legal-wrap { max-width: 760px; margin: 0 auto; padding: clamp(48px,6vw,80px) 24px; }
.legal-title { font-size: clamp(32px,4vw,44px); font-weight: 700; letter-spacing: -.02em; line-height: 1.15; margin: 0 0 18px; }
.legal-meta { color: var(--mk-muted); font-size: 14px; margin: 0 0 36px; padding-bottom: 24px; border-bottom: .5px solid var(--mk-border); }
.legal-meta-item { margin-right: 20px; }
.legal-intro { font-size: 17px; line-height: 1.7; color: var(--mk-text); margin: 0 0 40px; }
.legal-toc { background: rgba(255,255,255,.02); border: .5px solid var(--mk-border); border-radius: 12px; padding: 24px 28px; margin-bottom: 56px; }
.legal-toc-label { font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: var(--mk-dim); font-weight: 600; margin-bottom: 14px; }
.legal-toc ol { margin: 0; padding: 0; list-style: none; counter-reset: toc; }
.legal-toc li { counter-increment: toc; font-size: 14px; padding: 6px 0; display: flex; gap: 12px; }
.legal-toc li::before { content: counter(toc) "."; color: var(--mk-dim); flex-shrink: 0; min-width: 24px; }
.legal-toc a { color: var(--mk-text); text-decoration: none; transition: color .12s; }
.legal-toc a:hover { color: var(--mk-accent); }
.legal-section { margin-bottom: 48px; counter-increment: section; }
.legal-section-h2 { font-size: 22px; font-weight: 700; letter-spacing: -.01em; line-height: 1.3; margin: 0 0 18px; scroll-margin-top: 80px; }
.legal-section-h2::before { content: counter(section) ". "; color: var(--mk-dim); font-weight: 500; }
.legal-section-h3 { font-size: 16px; font-weight: 600; margin: 24px 0 10px; color: var(--mk-text); }
.legal-section p { font-size: 15px; line-height: 1.75; color: var(--mk-text); margin: 0 0 14px; }
.legal-section ul { font-size: 15px; line-height: 1.7; color: var(--mk-text); margin: 0 0 14px; padding-left: 22px; }
.legal-section li { margin-bottom: 6px; }
.legal-section a { color: var(--mk-accent); text-decoration: underline; text-decoration-thickness: .5px; text-underline-offset: 3px; }
.legal-wrap { counter-reset: section; }
</style>

<div class="legal-wrap">
    @if(!empty($c['doc_title']))
    <h1 class="legal-title">{{ $c['doc_title'] }}</h1>
    @endif

    @if(!empty($c['effective_date']) || !empty($c['updated_date']))
    <p class="legal-meta">
        @if(!empty($c['effective_date']))
            <span class="legal-meta-item"><strong>Effective:</strong> {{ $c['effective_date'] }}</span>
        @endif
        @if(!empty($c['updated_date']))
            <span class="legal-meta-item"><strong>Last updated:</strong> {{ $c['updated_date'] }}</span>
        @endif
    </p>
    @endif

    @if(!empty($c['intro_paragraph']))
    <p class="legal-intro">{!! nl2br(e($c['intro_paragraph'])) !!}</p>
    @endif

    @if($showToc && count($sections) > 1)
    <nav class="legal-toc">
        <div class="legal-toc-label">Contents</div>
        <ol>
            @foreach($sections as $sec)
                @if(!empty($sec['heading']))
                <li><a href="#{{ $slugify($sec['heading']) }}">{{ $sec['heading'] }}</a></li>
                @endif
            @endforeach
        </ol>
    </nav>
    @endif

    @foreach($sections as $sec)
    <section class="legal-section" id="{{ $slugify($sec['heading'] ?? '') }}">
        @if(!empty($sec['heading']))
        <h2 class="legal-section-h2">{{ $sec['heading'] }}</h2>
        @endif
        @foreach($sec['blocks'] ?? [] as $block)
            @if(($block['type'] ?? '') === 'paragraph')
                <p>{!! nl2br(e($block['text'] ?? '')) !!}</p>
            @elseif(($block['type'] ?? '') === 'subheading')
                <h3 class="legal-section-h3">{{ $block['text'] ?? '' }}</h3>
            @elseif(($block['type'] ?? '') === 'list')
                <ul>
                    @foreach($block['items'] ?? [] as $item)
                    <li>{!! nl2br(e($item)) !!}</li>
                    @endforeach
                </ul>
            @endif
        @endforeach
    </section>
    @endforeach
</div>
