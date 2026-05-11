@extends('errors._shell')
@section('page_title', '419 — Session expired')
@section('eyebrow', '419 · Session expired')
@section('eyebrow_tone', 'tone-amber')
@section('title')
Your <span class="err-title-accent">session expired</span>. Sign in to keep going.
@endsection
@section('body')
For your security we sign you out after a long period of inactivity. Any unsaved changes on the previous page may be lost. Sign in again and we'll bring you back where you left off when possible.
@endsection
@section('actions')
  <a href="{{ url('/login') }}" class="btn btn-primary">Sign in again</a>
  <a href="javascript:window.location.reload()" class="btn btn-secondary">Reload page</a>
@endsection
@section('footer_text', 'Tip: long forms? Save as draft often. We auto-save every 90 seconds where supported.')
