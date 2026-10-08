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
  <a href="{{ error_home_url() }}" class="btn btn-primary">← Back to dashboard</a>
  {{-- sign-out only accepts a form submit --}}
  <form method="POST" action="{{ url('/admin/logout') }}" style="display:inline;margin:0">
    <input type="hidden" name="_token" value="{{ rescue(fn () => csrf_token(), '', false) }}">
    <button type="submit" class="btn btn-secondary">Sign out</button>
  </form>
@endsection
@section('footer_text', "Need help? Contact your shop's owner, or email")
