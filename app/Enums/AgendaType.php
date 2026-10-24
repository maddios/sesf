<?php

namespace App\Enums;

use App\Enums\Concerns\TriesLegacyValues;

enum AgendaType: int
{
    use TriesLegacyValues;

    case Agenda = 1;
    case Judging = 2;

    public function label(): string
    {
        return match ($this) {
            self::Agenda => 'Agenda',
            self::Judging => 'Judging',
        };
    }
}
