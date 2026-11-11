<?php

namespace App\Enums;

/** Staff review of an alternative ticket PDF. Only "verified" documents can be emailed. */
enum VerificationStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }
}
