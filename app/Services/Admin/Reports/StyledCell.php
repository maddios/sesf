<?php

namespace App\Services\Admin\Reports;

/**
 * A spreadsheet cell with one of the legacy workbook styles declared by SpreadsheetMl::open()
 * (RedText, LightOrangeBg).
 */
final class StyledCell
{
    public const RED_TEXT = 'RedText';

    public const LIGHT_ORANGE = 'LightOrangeBg';

    public function __construct(
        public readonly mixed $value,
        public readonly string $style,
    ) {}
}
