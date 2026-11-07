{{-- MARKER-MULTI-ASSET-COUNT: "3 items" on a multi-asset appointment. Hover,
     focus or tap lists each item with its services and times (the script and
     styles are in the tenant layout). Draws nothing for one item or none. --}}
@php $acList = \App\Support\AssetSummary::for($appt); @endphp
@if (count($acList) > 1)
  <button type="button" class="ia-asset-count" data-assets='@json($acList)' aria-label="{{ count($acList) }} {{ strtolower(tenant()->asset_label_plural ?: 'items') }} on this booking, show them">{{ count($acList) }} {{ strtolower(tenant()->asset_label_plural ?: 'items') }}</button>
@endif
