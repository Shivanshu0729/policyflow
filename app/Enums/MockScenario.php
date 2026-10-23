<?php

namespace App\Enums;

enum MockScenario: string
{
    case SUCCESS = 'SUCCESS';
    case TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    case TIMEOUT_AFTER_ISSUE = 'TIMEOUT_AFTER_ISSUE';
    case DELAYED_CALLBACK = 'DELAYED_CALLBACK';
    case DUPLICATE_CALLBACK = 'DUPLICATE_CALLBACK';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
