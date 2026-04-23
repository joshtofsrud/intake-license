<?php

namespace App\Enums;

enum WorkOrderFieldType: string
{
    case Text     = 'text';
    case Textarea = 'textarea';
    case Number   = 'number';
    case Select   = 'select';

    public function label(): string
    {
        return match($this) {
            self::Text     => 'Single line text',
            self::Textarea => 'Long text',
            self::Number   => 'Number',
            self::Select   => 'Dropdown (pick one)',
        };
    }

    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }
        return $out;
    }
}
