<?php

namespace App\Services\Admin\Reports;

/**
 * Writes SpreadsheetML 2003 (the legacy .xls export). Every text cell is typed String, so spreadsheet
 * apps never evaluate it as a formula; XML-unsafe characters are removed or escaped.
 */
final class SpreadsheetMl
{
    public static function open(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<?mso-application progid="Excel.Sheet"?>'."\n"
            .'<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            .'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n"
            .'<Styles>'
            .'<Style ss:ID="RedText"><Font ss:Color="#FF0000"/></Style>'
            .'<Style ss:ID="LightOrangeBg"><Interior ss:Color="#FFE599" ss:Pattern="Solid"/></Style>'
            .'</Styles>'."\n";
    }

    public static function close(): string
    {
        return "</Workbook>\n";
    }

    public static function sheet(string $name): string
    {
        return '<Worksheet ss:Name="'.self::escape($name).'"><Table>'."\n";
    }

    public static function endSheet(): string
    {
        return "</Table></Worksheet>\n";
    }

    /**
     * @param  list<mixed>  $cells
     */
    public static function row(array $cells): string
    {
        $xml = '<Row>';
        foreach ($cells as $cell) {
            $xml .= self::cell($cell);
        }

        return $xml.'</Row>'."\n";
    }

    public static function cell(mixed $value): string
    {
        if ($value instanceof StyledCell) {
            return self::styled(self::cell($value->value), $value->style);
        }
        if (is_int($value) || is_float($value)) {
            return '<Cell><Data ss:Type="Number">'.$value.'</Data></Cell>';
        }
        if (is_bool($value)) {
            return '<Cell><Data ss:Type="String">'.($value ? 'Yes' : 'No').'</Data></Cell>';
        }

        return '<Cell><Data ss:Type="String">'.self::escape($value === null ? '' : (string) $value).'</Data></Cell>';
    }

    private static function styled(string $cell, string $style): string
    {
        return str_replace('<Cell>', '<Cell ss:StyleID="'.$style.'">', $cell);
    }

    private static function escape(string $text): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
