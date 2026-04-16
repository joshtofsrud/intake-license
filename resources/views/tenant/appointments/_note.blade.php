@props(['note', 'deleteUrl', 'deleteOp' => 'delete_note', 'idKey' => 'note_id'])

<div class="ia-note" data-note-id="{{ $note->id }}">
  <div class="ia-note-head">
    <span class="ia-note-author">
      {{ $note->user?->name ?? 'Staff' }}
    </span>
    <span class="ia-note-time">
      {{ \Carbon\Carbon::parse($note->created_at)->format('M j, Y g:i a') }}
    </span>
    <button type="button" class="ia-note-delete"
      data-delete-url="{{ $deleteUrl }}"
      data-note-id="{{ $note->id }}"
      title="Delete note">&#x2715;</button>
  </div>
  <div class="ia-note-body">{{ $note->note_content ?? $note->note }}</div>
</div>
