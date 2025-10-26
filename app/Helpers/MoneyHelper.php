<?php
// app/Helpers/MoneyHelper.php
namespace App\Helpers;

class MoneyHelper
{
    public static function toCents(string $input): int
    {
        if (trim($input) === '') {
            return 0;
        }

        return (int) str_replace(['.', ','], ['', ''], $input);
    }

    public static function format(int $cents, bool $withSymbol = true): string
    {
        if ($cents === 0) {
            return $withSymbol ? '€ 0,00' : '0,00';
        }
        
        $formatted = number_format($cents / 100, 2, ',', '.');
        return $withSymbol ? "€ {$formatted}" : $formatted;
    }
    
    public static function isValidFormat(string $input): bool
    {
        return preg_match('/^\d{1,3}(?:\.\d{3})*,\d{2}$/', $input) === 1;
    }
    
    // 👇 NUOVO: Metodo per formattare qualsiasi campo
    public static function formatField($model, string $fieldName, bool $withSymbol = true): string
    {
        $cents = $model->{$fieldName} ?? 0;
        return self::format($cents, $withSymbol);
    }
}