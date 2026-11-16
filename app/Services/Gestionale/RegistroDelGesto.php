<?php

namespace App\Services\Gestionale;

use App\Models\User;

/**
 * Le righe del registro di un gesto dell'amministratore che deve restare scritto: una lista dentro il JSON di chi la tiene (la nota sintetica del rendiconto,
 * «Ignora» di una pista della banca, le risposte ai promemoria). Ogni riga: il tipo, il giorno e l'ora (UTC), chi (il numero e il nome di quel giorno), la sua
 * nota e i dati del gesto. La scrive solo il server; la pagina la legge con {@see perLaPagina()}.
 */
final class RegistroDelGesto
{
    /** La nota di una riga, al più. */
    public const LUNGHEZZA_RIGA = 500;

    /**
     * Il contenitore con una riga in più in `registro`.
     *
     * @param array<string, mixed> $contenitore
     * @param array<string, mixed> $dati
     * @return array<string, mixed>
     */
    public static function registra(array $contenitore, string $tipo, string $nota, ?User $utente, array $dati): array
    {
        $contenitore['registro'][] = self::riga($tipo, $nota, $utente, $dati);

        return $contenitore;
    }

    /**
     * Una riga del registro.
     *
     * @param array<string, mixed> $dati
     * @return array{tipo: string, il: string, utente_id: ?int, utente: string, nota: string, dati: array<string, mixed>}
     */
    public static function riga(string $tipo, string $nota, ?User $utente, array $dati): array
    {
        return ['tipo' => $tipo, 'il' => now()->utc()->toIso8601String(), 'utente_id' => $utente?->id, 'utente' => (string) ($utente?->name ?? ''),
            'nota' => mb_substr(trim($nota), 0, self::LUNGHEZZA_RIGA), 'dati' => $dati];
    }

    /** Le righe del registro per la pagina, la più recente prima, con il giorno e l'ora nel fuso dell'amministratore. */
    public static function perLaPagina(array $registro): array
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');

        return array_values(array_map(fn ($r) => $r + ['quando' => \Carbon\Carbon::parse($r['il'])->setTimezone($fuso)->format('d/m/Y H:i')], array_reverse($registro)));
    }
}
