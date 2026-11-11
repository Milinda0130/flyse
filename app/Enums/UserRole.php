<?php

namespace App\Enums;

enum UserRole: string
{
    use HasOptions;

    case Owner = 'owner';
    case Staff = 'staff';
    case Readonly = 'readonly';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner / Administrator',
            self::Staff => 'Booking staff',
            self::Readonly => 'Read-only staff',
        };
    }

    public function canEdit(): bool
    {
        return $this !== self::Readonly;
    }
}
