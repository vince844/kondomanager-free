<?php

namespace App\Services\Gestionale\Debiti;

use App\Helpers\DateHelper;
use Carbon\CarbonImmutable;

/**
 * Quando una rata è scaduta: una regola sola, nel server, per chi la legge (la lettura comune dei debiti, la situazione debitoria
 * del modulo dell'incasso, la pagina del piano, la previsione di cassa).
 *
 * Il giorno della scadenza non è scaduto: la rata è scaduta dal giorno dopo (`scadenza < oggi`). «Oggi» è il giorno
 * dell'amministratore ({@see DateHelper::oggiUtente()}), non quello del server: fra le 00:00 e le 02:00 di Roma il server è ancora
 * al giorno prima. Il predicato guarda solo la data: che la rata sia emessa lo dice chi lo chiama (lo scaduto di una persona conta
 * solo le rate emesse).
 */
final class Scadenza
{
    /** Le fasce dello scaduto, per giorni di ritardo. */
    public const FASCE = ['1_30' => 0, '31_90' => 0, 'oltre_90' => 0];

    public static function scaduta(\DateTimeInterface|string $scadenza, ?string $oggi = null): bool
    {
        return self::giorno($scadenza) < self::giorno($oggi ?? DateHelper::oggiUtente());
    }

    /**
     * I giorni ricordati per (scadenza, oggi): la lettura chiama la funzione per ogni riga del giornale, e le scadenze distinte sono
     * poche. Due `CarbonImmutable::parse` e un `diffInDays` per riga erano quasi tutto il tempo della lettura sulla dashboard.
     *
     * @var array<string, int>
     */
    private static array $giorni = [];

    /** I giorni dalla scadenza a oggi; zero se la rata non è scaduta. */
    public static function giorniDiRitardo(\DateTimeInterface|string $scadenza, ?string $oggi = null): int
    {
        $oggi = self::giorno($oggi ?? DateHelper::oggiUtente());
        $scadenza = self::giorno($scadenza);
        if ($scadenza >= $oggi) {
            return 0;
        }

        return self::$giorni[$scadenza . '|' . $oggi] ??= (int) CarbonImmutable::parse($scadenza, 'UTC')->diffInDays(CarbonImmutable::parse($oggi, 'UTC'));
    }

    /** La fascia di un ritardo: «1_30», «31_90», «oltre_90»; null se non c'è ritardo. */
    public static function fascia(int $giorniDiRitardo): ?string
    {
        return match (true) {
            $giorniDiRitardo <= 0 => null,
            $giorniDiRitardo <= 30 => '1_30',
            $giorniDiRitardo <= 90 => '31_90',
            default => 'oltre_90',
        };
    }

    /**
     * Il giorno di una data, come `Y-m-d`: le colonne DATE arrivano come stringa (con o senza l'ora, secondo il database) o come Carbon.
     * La scadenza di una rata è obbligatoria (`rate.data_scadenza`): una data nulla qui non arriva.
     */
    public static function giorno(\DateTimeInterface|string $data): string
    {
        return $data instanceof \DateTimeInterface ? $data->format('Y-m-d') : substr($data, 0, 10);
    }
}
