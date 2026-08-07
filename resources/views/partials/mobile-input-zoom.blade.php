{{-- MARKER-MOBILE-INPUT-ZOOM
     iOS Safari auto-zooms any focused input whose font-size is under 16px.
     Pin form fields to 16px on phones so the zoom never triggers.
     Scoped to <=767px: desktop and iPad layouts are unchanged. --}}
<style>
@media (max-width: 767px) {
  input[type="text"], input[type="email"], input[type="password"],
  input[type="number"], input[type="search"], input[type="tel"],
  input[type="url"], input[type="date"], input[type="time"],
  input[type="datetime-local"], input[type="month"], input[type="week"],
  select, textarea {
    font-size: 16px !important;
  }
}
</style>
