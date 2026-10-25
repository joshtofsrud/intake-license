{{-- Block: quick actions (Brief). Each button only for people who can open its page. --}}
@php $see ??= \App\Support\Dashboard\BlockAccess::for(auth('tenant')->user()); @endphp
    <div class="mb-acts">
      @if ($see->can('schedule'))
      <a href="{{ route('tenant.appointments.index', ['new' => 1]) }}" class="ia-btn ia-btn--primary">New appointment</a>
      @endif
      @if ($see->can('register'))
        <a href="{{ route('tenant.register.index') }}" class="ia-btn">Open register</a>
      @endif
    </div>
