@extends('errors._shell')
@section('page_title', '503 — Scheduled maintenance')
@section('eyebrow', '503 · Scheduled maintenance')
@section('eyebrow_tone', 'tone-amber')
@section('title')
We're <span class="err-title-accent">making it better</span>.
@endsection
@section('body')
Intake is briefly offline for scheduled maintenance. Customer bookings on your public booking page will continue to queue — nothing is lost. We'll be back online shortly.
@endsection
@section('status_block')
<div class="err-status-block">
  <div class="err-status-row">
    <span>Status</span>
    <span class="err-status-pill"><span class="err-status-dot"></span> In progress</span>
  </div>
  <div class="err-status-row">
    <span>Public booking</span>
    <span class="err-status-pill ok"><span class="err-status-dot"></span> Queueing</span>
  </div>
</div>
<div class="err-actions" style="margin-top:28px">
  <a href="{{ url('/status') }}" class="btn btn-secondary">View status page</a>
</div>
@endsection
@section('footer_text', 'Follow updates:')
