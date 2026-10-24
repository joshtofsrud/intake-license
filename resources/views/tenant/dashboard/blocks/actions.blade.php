{{-- Block: quick actions (Brief). --}}
    <div class="mb-acts">
      <a href="{{ route('tenant.appointments.index', ['new' => 1]) }}" class="ia-btn ia-btn--primary">New appointment</a>
      @if (tenant()->retail_enabled)
        <a href="{{ route('tenant.register.index') }}" class="ia-btn">Open register</a>
      @endif
    </div>
