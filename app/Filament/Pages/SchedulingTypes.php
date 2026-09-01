<?php

namespace App\Filament\Pages;

use App\Models\PlatformBookingType;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

// MARKER-SCHED-ADMIN — the kinds of call people can book, and their links.
class SchedulingTypes extends Page
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'scheduling';

    protected static ?string $navigationIcon  = 'heroicon-o-link';
    protected static ?string $navigationLabel = 'Booking types';
    protected static ?string $navigationGroup = 'Scheduling';
    protected static ?string $title           = 'Booking types';
    protected static ?int    $navigationSort  = 62;
    protected static ?string $slug            = 'scheduling-types';

    protected static string $view = 'filament.pages.scheduling-types';

    public ?int   $editing = null;   // null = adding
    public string $eName        = '';
    public string $eSlug        = '';
    public string $eKind        = 'public';
    public int    $eLength      = 20;
    public string $eMode        = 'meet';
    public string $eMeetUrl     = '';
    public string $eDescription = '';
    public int    $eReminder    = 60;
    /** [['label' => '', 'type' => 'text', 'required' => false, 'options' => 'a, b'], ...] */
    public array  $eQuestions   = [];

    public function edit(int $id): void
    {
        $t = PlatformBookingType::findOrFail($id);
        $this->editing      = $t->id;
        $this->eName        = $t->name;
        $this->eSlug        = $t->slug;
        $this->eKind        = $t->kind;
        $this->eLength      = $t->length_min;
        $this->eMode        = $t->location_mode;
        $this->eMeetUrl     = (string) $t->meet_url;
        $this->eDescription = (string) $t->description;
        $this->eReminder    = $t->reminder_minutes;
        $this->eQuestions   = array_map(fn ($q) => [
            'label'    => $q['label'],
            'type'     => $q['type'],
            'required' => $q['required'],
            'options'  => implode(', ', $q['options']),
        ], $t->questionList());
        $this->resetErrorBag();
        $this->dispatch('open-modal', id: 'type-edit');
    }

    public function add(): void
    {
        $this->reset(['editing', 'eName', 'eSlug', 'eMeetUrl', 'eDescription', 'eQuestions']);
        $this->eKind = 'public'; $this->eLength = 20; $this->eMode = 'meet'; $this->eReminder = 60;
        $this->resetErrorBag();
        $this->dispatch('open-modal', id: 'type-edit');
    }

    public function updatedEName(string $v): void
    {
        if ($this->editing === null) {
            $this->eSlug = Str::slug($v);
        }
    }

    public function addQuestion(): void
    {
        $this->eQuestions[] = ['label' => '', 'type' => 'text', 'required' => false, 'options' => ''];
    }

    public function removeQuestion(int $i): void
    {
        unset($this->eQuestions[$i]);
        $this->eQuestions = array_values($this->eQuestions);
    }

    public function save(): void
    {
        $this->validate([
            'eName'        => 'required|string|max:80',
            'eSlug'        => 'required|string|max:40|regex:/^[a-z0-9-]+$/|unique:platform_booking_types,slug,' . ($this->editing ?? 'NULL') . ',id',
            'eKind'        => 'required|in:public,internal',
            'eLength'      => 'required|integer|min:5|max:480',
            'eMode'        => 'required|in:meet,phone,choice,in_person',
            'eMeetUrl'     => 'nullable|url|max:255',
            'eDescription' => 'nullable|string|max:1000',
            'eReminder'    => 'required|integer|min:0|max:10080',
            'eQuestions.*.label' => 'required|string|max:120',
            'eQuestions.*.type'  => 'required|in:text,textarea,select',
        ], ['eSlug.regex' => 'Link must be lowercase letters, numbers and dashes.', 'eQuestions.*.label.required' => 'Every question needs a label.']);

        $questions = [];
        foreach ($this->eQuestions as $q) {
            $questions[] = [
                'key'      => Str::slug($q['label'], '_'),
                'label'    => trim($q['label']),
                'type'     => $q['type'],
                'required' => (bool) ($q['required'] ?? false),
                'options'  => $q['type'] === 'select'
                    ? array_values(array_filter(array_map('trim', explode(',', $q['options'] ?? ''))))
                    : [],
            ];
        }

        $data = [
            'name'             => trim($this->eName),
            'slug'             => $this->eSlug,
            'kind'             => $this->eKind,
            'length_min'       => $this->eLength,
            'location_mode'    => $this->eMode,
            'meet_url'         => trim($this->eMeetUrl) ?: null,
            'description'      => trim($this->eDescription) ?: null,
            'reminder_minutes' => $this->eReminder,
            'questions'        => $questions,
        ];

        if ($this->editing) {
            PlatformBookingType::findOrFail($this->editing)->update($data);
        } else {
            $data['sort_order'] = (int) PlatformBookingType::max('sort_order') + 1;
            PlatformBookingType::create($data);
        }

        Notification::make()->title('Booking type saved')->success()->send();
        $this->dispatch('close-modal', id: 'type-edit');
    }

    public function toggle(int $id): void
    {
        $t = PlatformBookingType::findOrFail($id);
        $t->update(['is_active' => ! $t->is_active]);
        Notification::make()->title($t->name . ($t->is_active ? ' is on' : ' is off'))->success()->send();
    }

    protected function getViewData(): array
    {
        return [
            'types' => PlatformBookingType::withCount([
                'bookings',
                'bookings as no_show_count' => fn ($q) => $q->where('status', 'no_show'),
            ])->orderBy('sort_order')->get(),
            'modes' => PlatformBookingType::LOCATION_LABELS,
        ];
    }
}
