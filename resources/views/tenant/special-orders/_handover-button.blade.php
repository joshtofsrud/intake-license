{{-- MARKER-SO-HANDOVER: the next step for an arrived order, by how it gets
     to its person. Paid or unpaid → Hand over (asks as needed); a work-order
     part → the job; a layaway → the layaway. --}}
@php $sz = ($size ?? null) === 'sm' ? ' ia-btn--sm' : ''; @endphp
@if($ctx['kind'] === 'stock')
  <button type="button" class="ia-btn ia-btn--secondary{{ $sz }}" data-so-act="putaway" data-id="{{ $so->id }}" data-so="{{ $so->so_number }}"
          title="It's already in stock and nobody is waiting for it. This closes the order.">Put away</button>
@elseif(in_array($ctx['kind'], ['paid', 'unpaid'], true))
  <button type="button" class="ia-btn ia-btn--primary{{ $sz }}" data-so-act="handover"
          data-id="{{ $so->id }}"
          data-so="{{ $so->so_number }}"
          data-who="{{ $who }}"
          data-item="{{ $so->item_name_snapshot }}"
          data-qty="{{ (int) $so->quantity }}"
          data-kind="{{ $ctx['kind'] }}"
          data-sale="{{ $ctx['sale'] }}"
          data-at="{{ $hold['at'] ?? '' }}"
          data-customer="{{ $so->customer_id }}"
          data-cataloged="{{ $so->inventory_item_id ? '1' : '0' }}">Hand over</button>
@elseif($ctx['url'])
  <a class="ia-btn ia-btn--secondary{{ $sz }}" href="{{ $ctx['url'] }}"
     title="{{ $ctx['kind'] === 'job' ? 'Completing the work order uses the part' : 'Handing the layaway over hands this over too' }}">Open {{ $ctx['kind'] === 'job' ? $ctx['label'] : 'layaway' }}</a>
@endif
