{{-- MARKER-CONNECTED-POS-SECTION: Settings — the mode, outlets, linked items, and what is sent about items --}}
@php
  $pc = $pos['conn'];
  $twoWay = $pc->isTwoWay();
@endphp
<div class="ec-sec">
  <h3>Mode</h3>
  <div class="ec-row">
    <div><div class="t">{{ $pc->modeLabel() }} <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>@if($twoWay) Intake reads from your Connected POS and sends to it. Linked items take its price and stock. @if($pos['use'] === 'move') When you are ready to run on Intake, switch over from the Overview. @endif @else Switched over {{ $pc->switched_at?->diffForHumans() }}. Intake reads from your Connected POS and sends nothing to it. Intake owns price and stock. @endif</span></div></div>
    @unless($twoWay)
      <div class="ctl"><form method="POST" action="{{ route('tenant.cpos.switch_back') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" data-confirm="Go back to 2 Way Data? From the next read, linked items take your Connected POS's prices and stock counts again, replacing Intake's. Online orders are sent again if that is on in E-commerce.">Back to 2 Way Data</button></form></div>
    @endunless
  </div>
</div>

<form method="POST" action="{{ route('tenant.cpos.settings') }}">
  @csrf
  <div class="ec-sec">
    <h3>Outlets and locations</h3>
    @forelse($pc->locations ?? [] as $l)
      <div class="ec-row">
        <div><div class="t">{{ $l['name'] }} <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Which Intake location this Connected POS outlet's stock counts against.</span></div></div>
        <div class="ctl"><select class="ia-select" name="map[{{ $l['id'] }}]">
          <option value="">Don't use this outlet</option>
          @foreach($pos['intake_locations'] as $il)
            <option value="{{ $il->id }}" @selected((($pc->location_map ?? [])[$l['id']] ?? null) === (string) $il->id)>{{ $il->name }}</option>
          @endforeach
        </select></div>
      </div>
    @empty
      <p class="ec-note">No outlets found yet. Click <b>Check connection</b> at the top to read them.</p>
    @endforelse
  </div>
  <div class="ec-sec">
    <h3>Linked items</h3>
    <div class="ec-row">
      <div><div class="t">{{ $twoWay ? 'Keep price and stock in sync' : 'Keep reading the catalog' }} <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>@if($twoWay) For linked items only. The Connected POS price replaces the Intake sell price, and its count replaces Intake's at each mapped location. Also keeps the Import list current. About every minute, with a full read each night. @else Keeps the Import list current, about every minute. No Intake item is changed: in Reading Only, Intake owns price and stock. @endif</span></div></div>
      <div class="ctl"><label class="tg"><input type="hidden" name="sync_enabled" value="0"><input type="checkbox" name="sync_enabled" value="1" @checked($pc->sync_enabled)><i></i></label></div>
    </div>
    <div class="ec-row">
      <div><div class="t">Link matches on their own <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>When a Connected POS product is plainly an item you already carry: same barcode, or same part number. A product that only shares a SKU with an item waits in Import for you to check. Off: nothing links until you say so in Import.</span></div></div>
      <div class="ctl"><label class="tg"><input type="hidden" name="auto_link" value="0"><input type="checkbox" name="auto_link" value="1" @checked($pc->auto_link)><i></i></label></div>
    </div>
    <div class="ec-row">
      <div><div class="t">When an item is removed from your Connected POS <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Nothing is deleted. In 2 Way Data its Connected POS stock goes to zero either way.</span></div></div>
      <div class="ctl"><select class="ia-select" name="removal_mode">
        <option value="keep" @selected(($pc->removal_mode ?? 'keep') === 'keep')>Keep selling it from suppliers</option>
        <option value="hide" @selected($pc->removal_mode === 'hide')>Hide it from the online store</option>
        <option value="deactivate" @selected($pc->removal_mode === 'deactivate')>Deactivate it in Intake</option>
      </select></div>
    </div>
    <div class="ec-actions"><button class="ia-btn ia-btn--primary" type="submit">Save</button></div>
  </div>
</form>

<form method="POST" action="{{ route('tenant.cpos.item_settings') }}" class="ec-sec">
  @csrf
  <h3>Sending items</h3>
  @unless($twoWay)<p class="ec-note" style="margin-bottom:6px">Reading Only: nothing is sent, whatever is chosen here. These apply again if you go back to 2 Way Data.</p>@endunless
  <div class="ec-row">
    <div><div class="t">Create items in your Connected POS <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>An item an online order needs is always created first, so the sale is complete.</span></div></div>
    <div class="ctl"><select class="ia-select" name="items_create">
      <option value="orders" @selected(($pc->items_create ?? 'orders') === 'orders')>Only when an order needs one</option>
      <option value="always" @selected($pc->items_create === 'always')>Also whenever an item is added in Intake</option>
    </select></div>
  </div>
  <div class="ec-row">
    <div><div class="t">Send my item edits <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Name, SKU, barcode and description changes made in Intake go to your Connected POS. Price stays with your Connected POS.</span></div></div>
    <div class="ctl"><label class="tg"><input type="hidden" name="items_edits" value="0"><input type="checkbox" name="items_edits" value="1" @checked($pc->items_edits)><i></i></label></div>
  </div>
  <div class="ec-actions"><button class="ia-btn ia-btn--primary" type="submit">Save</button></div>
</form>

<div class="ec-sec">
  <h3>Online orders</h3>
  @if(tenant()->online_store_enabled)
    <div class="ec-row">
      <div><div class="t">{{ $pc->orders_out && $twoWay ? 'Paid online orders are sent as sales' : 'Online orders are not being sent' }} <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Whether they're sent, and the register, staff member, payment type and tax each sale is recorded with, are set with the rest of your online store.</span></div></div>
      <div class="ctl"><a class="ia-btn ia-btn--secondary ia-btn--sm" href="{{ route('tenant.storefront.settings', ['tab' => 'pos']) }}">Open E-commerce › Connected POS</a></div>
    </div>
  @else
    <p class="ec-note">Sending online orders to your Connected POS needs the E-commerce add-on. <a class="cpx-lnk" href="{{ route('tenant.feature_addons.index') }}">See add-ons</a>.</p>
  @endif
</div>
