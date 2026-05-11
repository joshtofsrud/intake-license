@extends('errors._shell')
@section('page_title', '404 — Page not found')
@section('eyebrow', '404 · Page not found')
@section('title')
This page <span class="err-title-accent">doesn't exist</span> — yet.
@endsection
@section('body')
The link might be old, mistyped, or pointing to something we haven't built. Try the homepage, or use the search if you know what you're looking for.
@endsection
@section('actions')
  <a href="{{ url('/') }}" class="btn btn-primary">← Back to homepage</a>
  <a href="{{ url('/docs') }}" class="btn btn-secondary">Browse help center</a>
@endsection
