@extends('errors._shell')
@section('page_title', '403 — Access denied')
@section('eyebrow', '403 · Access denied')
@section('eyebrow_tone', 'tone-blue')
@section('title')
You don't have <span class="err-title-accent">access</span> to this page.
@endsection
@section('body')
Either you're not signed in to this shop, or the owner hasn't granted you permission for this area. If you think this is a mistake, ask the shop owner to check your role under Team Settings.
@endsection
@section('mini_links')
  <a href="{{ url('/login') }}">Sign in with a different account</a>
@endsection
@section('actions')
  {{-- MARKER-ERR-HOME --}}
  <a href="{{ error_home_url() }}" class="btn btn-primary">← Back to dashboard</a>
  <a href="{{ url('/logout') }}" class="btn btn-secondary">Sign out</a>
@endsection
@section('footer_text', "Need help? Contact your shop's owner, or email")
