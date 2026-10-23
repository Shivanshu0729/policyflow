<?php

namespace App\Enums;

enum SumInsured: int
{
    case TIER_100K = 100000;
    case TIER_300K = 300000;
    case TIER_500K = 500000;

    public function multiplier(): string
    {
        return match ($this) {
            self::TIER_100K => '1.00',
            self::TIER_300K => '1.25',
            self::TIER_500K => '1.50',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function isValid(int|string $val): bool
    {
        return in_array((int)$val, self::values(), true);
    }

    public static function getMultiplier(int|string $val): string
    {
        $case = self::tryFrom((int)$val);
        if ($case === null) {
            throw new \InvalidArgumentException("Unsupported sum insured value: {$val}");
        }
        return $case->multiplier();
    }
}
