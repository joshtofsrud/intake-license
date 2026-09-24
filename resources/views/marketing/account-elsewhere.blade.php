{{-- MARKER-APEX-ACCOUNT — a customer account link that arrived without a shop.
     Older emails built their links on the platform address (fixed by
     MARKER-TENANT-LINK), and those links live in inboxes for months. The
     account pages need a shop to render, so explain instead of erroring.
     Editable: publish a marketing page with the slug "account-help" in master
     admin and it is shown instead of this. --}}
@extends('marketing.layout')
@section('title', 'Customer accounts live on your shop’s address')
@section('content')
<section style="max-width:640px;margin:0 auto;padding:72px 24px 96px">
  <h1 style="font-size:32px;line-height:1.25;margin:0 0 16px">This link is missing its shop</h1>
  <p style="font-size:17px;line-height:1.6;margin:0 0 20px">
    Your account is held by the business you bought from, not by Intake, so it lives on
    <strong>their</strong> web address rather than this one.
  </p>
  <div style="border:1px solid rgba(0,0,0,.12);border-radius:12px;padding:16px 18px;margin:0 0 24px">
    <div style="font-size:13px;opacity:.7;margin-bottom:6px">It looks like</div>
    <code style="font-size:16px">theirshop.intake.works/account</code>
  </div>
  <p style="font-size:16px;line-height:1.6;margin:0 0 12px">
    Open the email the business sent you and use the link in it. If you can't find it,
    ask them to send a new one — they can do that in seconds.
  </p>
  <p style="font-size:16px;line-height:1.6;margin:0 0 28px">
    Nothing is wrong with your account, and nothing needs fixing on your side.
  </p>
  <a href="/" style="display:inline-block;padding:12px 20px;border-radius:8px;background:#111;color:#fff;text-decoration:none">
    About Intake
  </a>
</section>
@endsection
