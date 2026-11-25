{{-- MARKER-CAMPAIGN-STOP: Stop / Unschedule, Resume, Duplicate for one campaign.
     Used on the report and under the editor. $rc is the campaign. --}}
@php $rcResume = $this->resumableCount($rc); @endphp
<div class="pc-acts" style="margin-top:12px;align-items:center;flex-wrap:wrap">
  @if($rc->status === 'sending')
    <button type="button" class="pc-btn pc-btn--warn" wire:click="cancelCampaign('{{ $rc->id }}')" wire:loading.attr="disabled">Stop sending</button>
  @elseif($rc->status === 'scheduled')
    <button type="button" class="pc-btn pc-btn--warn" wire:click="cancelCampaign('{{ $rc->id }}')" wire:loading.attr="disabled">Unschedule</button>
  @endif
  @if($rcResume > 0)
    @if($confirmDel === 'r:' . $rc->id)
      <span style="font-size:13px;opacity:.8">Send to the {{ number_format($rcResume) }} who haven't had it?</span>
      <button type="button" class="pc-btn pc-btn--pri" wire:click="resumeCampaign('{{ $rc->id }}')" wire:loading.attr="disabled">Resume</button>
      <button type="button" class="pc-btn" wire:click="cancelDelete">Not now</button>
    @else
      <button type="button" class="pc-btn pc-btn--pri" wire:click="askResume('{{ $rc->id }}')">Resume</button>
      <span style="font-size:12px;opacity:.55">{{ number_format($rcResume) }} still to send · no one gets it twice</span>
    @endif
  @endif
  <button type="button" class="pc-btn" wire:click="duplicateCampaign('{{ $rc->id }}')">Duplicate</button>
</div>
