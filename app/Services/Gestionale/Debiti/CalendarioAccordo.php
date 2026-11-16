<?php

namespace App\Services\Gestionale\Debiti;

use Carbon\CarbonImmutable;

/**
 * Il calendario di un accordo di rientro, puro: niente database.
 *
 * - **Versata o no.** Quello che va all'accordo copre le rate del calendario in ordine, la più vecchia prima (art. 1193 c.c.: il debitore non ha
 *   indicato altro). Una rata è versata quando il versato la copre per intero: anche un centesimo scoperto la lascia «non versata», e la lettura dice
 *   quanto manca. Nessuna tolleranza: sarebbe una cifra scelta dal programma.
 * - **Scaduta** è una rata con il giorno prima della data della lettura ({@see Scadenza}): il suo giorno non è scaduto.
 * - **La regola di decadenza** dell'accordo, nelle due forme: «N rate del calendario non versate, anche non consecutive» (il giorno D in cui almeno N
 *   rate scadute prima di D non sono versate) e «G giorni dopo una rata non versata» (il giorno D in cui una rata con più di G giorni da D alla sua data
 *   non è versata). Il segnale è il primo giorno in cui la forma si verifica, con le rate che la fanno e i loro versati di quel giorno. Il programma
 *   segnala; la decadenza la registra l'amministratore.
 *
 * I giorni in cui una forma può cominciare a verificarsi sono pochi: il giorno dopo ogni rata (una rata diventa scaduta), il giorno dopo i G giorni di
 * ogni rata, e i giorni in cui il versato cambia (un versamento tornato indietro lo abbassa). Si guardano in ordine, solo quelli.
 */
final class CalendarioAccordo
{
    /**
     * Le rate del calendario con il versato di ognuna, a una data.
     *
     * @param list<array{numero:int, data:string, importo:int}> $scadenze in ordine
     * @return list<array{numero:int, data:string, importo:int, versato:int, mancano:int, versata:bool, scaduta:bool}>
     */
    public static function rate(array $scadenze, int $versato, string $alla): array
    {
        $resto = max(0, $versato);
        $esito = [];
        foreach ($scadenze as $s) {
            $importo = (int) $s['importo'];
            $v = min($resto, $importo);
            $resto -= $v;
            $esito[] = ['numero' => (int) $s['numero'], 'data' => Scadenza::giorno($s['data']), 'importo' => $importo, 'versato' => $v,
                'mancano' => $importo - $v, 'versata' => $v === $importo, 'scaduta' => Scadenza::scaduta($s['data'], $alla)];
        }

        return $esito;
    }

    /**
     * Il primo giorno, da `$dal` a `$fino` compresi, in cui ogni forma della regola si verifica, con le rate che la fanno quel giorno; null per una
     * forma che l'accordo non ha o che in quei giorni non si verifica.
     *
     * @param list<array{numero:int, data:string, importo:int}> $scadenze
     * @param callable(string):int $versatoAl il versato all'accordo alla fine di un giorno `Y-m-d`
     * @param list<string> $giorniDeiMovimenti i giorni in cui il versato all'accordo cambia
     * @return array{rate: ?array{il:string, rate:list<array{numero:int, data:string, importo:int, versato:int}>}, giorni: ?array{il:string, rate:list<array{numero:int, data:string, importo:int, versato:int}>}}
     */
    public static function segnali(array $scadenze, ?int $nRate, ?int $giorni, callable $versatoAl, array $giorniDeiMovimenti, string $dal, string $fino): array
    {
        $esito = ['rate' => null, 'giorni' => null];
        if (($nRate === null && $giorni === null) || $scadenze === [] || $fino < $dal) {
            return $esito;
        }
        $dopo = fn (string $d, int $n) => CarbonImmutable::parse($d, 'UTC')->addDays($n)->format('Y-m-d');
        $candidati = array_map(fn ($g) => Scadenza::giorno($g), $giorniDeiMovimenti);
        foreach ($scadenze as $s) {
            $candidati[] = $dopo(Scadenza::giorno($s['data']), 1);
            if ($giorni !== null) {
                $candidati[] = $dopo(Scadenza::giorno($s['data']), $giorni + 1);
            }
        }
        $candidati = array_values(array_unique(array_filter($candidati, fn ($d) => $d >= $dal && $d <= $fino)));
        sort($candidati);

        foreach ($candidati as $d) {
            $verificate = self::condizioni($scadenze, $esito['rate'] === null ? $nRate : null, $esito['giorni'] === null ? $giorni : null, $versatoAl($d), $d);
            foreach (['rate', 'giorni'] as $forma) {
                if ($esito[$forma] === null && $verificate[$forma] !== null) {
                    $esito[$forma] = ['il' => $d, 'rate' => $verificate[$forma]];
                }
            }
            if (($nRate === null || $esito['rate'] !== null) && ($giorni === null || $esito['giorni'] !== null)) {
                break;
            }
        }

        return $esito;
    }

    /**
     * Le due forme della regola in un giorno, dato il versato all'accordo di quel giorno: per ognuna le rate che la fanno verificare (con il loro
     * versato), o null se quel giorno non si verifica o l'accordo non ha la forma. È la prova del giorno di un segnale conservato contro il giornale
     * di oggi.
     *
     * @param list<array{numero:int, data:string, importo:int}> $scadenze
     * @return array{rate: ?list<array{numero:int, data:string, importo:int, versato:int}>, giorni: ?list<array{numero:int, data:string, importo:int, versato:int}>}
     */
    public static function condizioni(array $scadenze, ?int $nRate, ?int $giorni, int $versato, string $giorno): array
    {
        $scoperte = array_values(array_filter(self::rate($scadenze, $versato, $giorno), fn ($r) => ! $r['versata'] && $r['data'] < $giorno));
        $perRate = $nRate !== null && count($scoperte) >= $nRate ? array_map([self::class, 'dettaglio'], $scoperte) : null;
        $oltre = $giorni === null ? [] : array_values(array_filter($scoperte, fn ($r) => Scadenza::giorniDiRitardo($r['data'], $giorno) > $giorni));

        return ['rate' => $perRate, 'giorni' => $oltre !== [] ? array_map([self::class, 'dettaglio'], $oltre) : null];
    }

    /**
     * Il controllo del calendario: la somma delle rate è il debito riconosciuto. Un calendario che non torna è un errore di battitura, non una scelta:
     * la frase dice le due cifre e la differenza. Null quando torna.
     *
     * @param list<int> $importi in centesimi
     */
    public static function sommaCheNonTorna(array $importi, int $totale): ?string
    {
        $somma = (int) array_sum($importi);
        if ($somma === $totale) {
            return null;
        }

        return 'La somma delle rate del calendario è ' . self::euro($somma) . ' e il debito riconosciuto è ' . self::euro($totale)
            . ': correggi una rata, la differenza è ' . self::euro(abs($somma - $totale)) . '.';
    }

    /** Una rata come la conserva il segnale: il numero, il giorno, l'importo e il versato di quel giorno. */
    private static function dettaglio(array $r): array
    {
        return ['numero' => $r['numero'], 'data' => $r['data'], 'importo' => $r['importo'], 'versato' => $r['versato']];
    }

    public static function euro(int $centesimi): string
    {
        return '€ ' . number_format($centesimi / 100, 2, ',', '.');
    }
}
