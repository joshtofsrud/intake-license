@extends('layouts.tenant.app')
@php $pageTitle = 'Pages'; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Pages</h1>
    <p class="ia-page-subtitle">Build your public-facing website.</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ tenant_url() }}" target="_blank" class="ia-btn ia-btn--secondary">
      View site →
    </a>
    <button type="button" class="ia-btn ia-btn--primary"
      onclick="document.getElementById('new-page-form').style.display='block';this.style.display='none'">
      + New page
    </button>
  </div>
</div>

{{-- New page inline form --}}
<div id="new-page-form" class="ia-card ia-card--tight" style="display:none;margin-bottom:20px">
  <form method="POST" action="{{ route('tenant.pages.store') }}" style="display:flex;gap:10px;align-items:flex-end">
    @csrf
    <div class="ia-form-group" style="flex:1;margin-bottom:0">
      <label class="ia-form-label">Page title</label>
      <input type="text" name="title" class="ia-input" placeholder="e.g. About us" required autofocus>
    </div>
    <button type="submit" class="ia-btn ia-btn--primary">Create page</button>
    <button type="button" class="ia-btn ia-btn--ghost"
      onclick="document.getElementById('new-page-form').style.display='none';document.querySelector('.ia-btn--primary').style.display=''">
      Cancel
    </button>
  </form>
</div>

<div class="ia-card" style="padding:0;overflow:hidden">
  <table class="ia-table">
    <thead>
      <tr>
        <th>Page</th>
        <th>URL</th>
        <th>Status</th>
        <th>In nav</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach($pages as $page)
        <tr>
          <td>
            <div style="font-weight:500">{{ $page->title }}</div>
            @if($page->is_home)
              <div style="font-size:11px;opacity:.4">Home page</div>
            @endif
          </td>
          <td class="ia-muted-cell">
            {{ $page->is_home ? '/' : '/' . $page->slug }}
          </td>
          <td>
            @if($page->is_published)
              <span class="ia-badge ia-badge--completed">Published</span>
            @else
              <span class="ia-badge ia-badge--pending">Draft</span>
            @endif
          </td>
          <td>
            <span style="font-size:13px;opacity:.5">{{ $page->is_in_nav ? 'Yes' : 'No' }}</span>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <a href="{{ route('tenant.pages.edit', $page->id) }}"
               class="ia-btn ia-btn--secondary ia-btn--sm">Edit</a>
            @if(!$page->is_home)
              <form method="POST" action="{{ route('tenant.pages.destroy', $page->id) }}"
                style="display:inline" data-confirm="Delete '{{ $page->title }}'?">
                @csrf @method('DELETE')
                <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm">Delete</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>

@endsection
