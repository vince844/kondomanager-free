<?php
// app/Helpers/FileHelper.php

namespace App\Helpers;

class FileHelper
{
    /**
     * Formatta bytes in formato leggibile
     *
     * @param int|string $bytes
     * @param int $precision
     * @param bool $siUnits Usare unità SI (base 1000) invece di binarie (base 1024)
     * @return string
     */
    public static function formatBytes($bytes, int $precision = 2, bool $siUnits = false): string
    {
        $bytes = (int) $bytes;
        
        if ($bytes === 0) {
            return '0 B';
        }
        
        $base = $siUnits ? 1000 : 1024;
        
        $units = $siUnits 
            ? ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
            : ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'];
        
        // Calcola l'esponente
        $exponent = floor(log($bytes, $base));
        $maxExponent = count($units) - 1;
        $i = min($exponent, $maxExponent);
        
        // Calcola il valore
        $value = $bytes / pow($base, $i);
        
        // Formatta con precisione ma rimuove zeri non necessari
        if ($precision > 0) {
            $value = round($value, $precision);
            // Converti a stringa e rimuovi zeri decimali non necessari
            $value = (string) $value;
            if (strpos($value, '.') !== false) {
                $value = rtrim(rtrim($value, '0'), '.');
            }
        } else {
            $value = round($value);
        }
        
        return $value . ' ' . $units[$i];
    }
    
    /**
     * Formatta bytes per uso in attributi HTML (senza spazi)
     */
    public static function formatBytesForHtml($bytes, int $precision = 2): string
    {
        return str_replace(' ', '&nbsp;', self::formatBytes($bytes, $precision));
    }
    
    /**
     * Converte formato leggibile in bytes
     */
    public static function parseBytes(string $formatted): int
    {
        if (!preg_match('/^([\d.]+)\s*([KMGTPEZY]?i?B)$/i', trim($formatted), $matches)) {
            return 0;
        }
        
        $value = (float) $matches[1];
        $unit = strtoupper($matches[2]);
        
        $units = [
            'B' => 0,
            'KB' => 1, 'KIB' => 1,
            'MB' => 2, 'MIB' => 2,
            'GB' => 3, 'GIB' => 3,
            'TB' => 4, 'TIB' => 4,
            'PB' => 5, 'PIB' => 5,
            'EB' => 6, 'EIB' => 6,
            'ZB' => 7, 'ZIB' => 7,
            'YB' => 8, 'YIB' => 8,
        ];
        
        $base = strpos($unit, 'I') !== false ? 1024 : 1000;
        $exponent = $units[$unit] ?? 0;
        
        return (int) round($value * pow($base, $exponent));
    }
}