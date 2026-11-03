<?php

namespace App\Services\Gestionale;

/**
 * Quanto credito della posizione va su quale riga di un incasso, e quanto della riga paga il denaro versato (Coda 167,
 * decisioni 30.7 e 30.11, 1.11.0-beta.40).
 *
 * Una funzione pura, senza database, perché la stessa regola vive due volte: qui, per `StoreIncassoRateAction`, e nel
 * modulo (`resources/js/lib/gestionale/incassi/pianoCreditoIncasso.ts`), che la usa per costruire le righe e dire prima
 * del salvataggio cosa succederà. Le due copie leggono la stessa tabella di casi (`tests/Fixtures/piano_credito_incasso.json`).
 *
 * Le regole:
 *
 * - **Ordine (30.7).** Con «prima i soldi» — il motore di sempre, e «Resta a X» — il credito copre solo lo scoperto,
 *   cioè quanto le righe chiedono oltre il denaro, e va sulle **ultime** righe: i soldi pagano dalla prima, come prima
 *   della beta. Con «prima il credito» — «Si usa adesso», solo con «Versato da» — il credito copre per primo, dalla
 *   **prima** riga, e il denaro il resto: quello che avanza del denaro è la parte in più di chi ha versato.
 * - **Gestione (30.11).** Il credito di una gestione copre da sé solo le righe della **sua** gestione. Su un'altra solo se
 *   l'amministratore l'ha scelto (`$fraGestioni === true`). `serve_scelta` dice se quella scelta cambierebbe il
 *   risultato: allora, se manca, chi chiama rifiuta — il programma non decide al suo posto. Prima di questa regola il
 *   motore spendeva il credito sullo scoperto di qualunque gestione e scriveva «confermata dall'amministratore» anche
 *   quando nessuno aveva confermato niente (reperti S1 e S5 del rigiro della Fase 1-bis).
 *
 * Importi in centesimi interi. Le righe sono le coperture che il modulo chiede, in ordine; i crediti, quelli impegnati.
 * Una gestione `null` vale come gestione a sé.
 */
final class PianoCreditoIncasso
{
    /**
     * @param  list<array{chiave:int|string, importo:int, gestione:int|null}>  $righe
     * @param  list<array{chiave:int|string, importo:int, gestione:int|null}>  $crediti
     * @return array{credito: array<int|string, array<int|string, int>>, contante: array<int|string, int>, usato: int, serve_scelta: bool}
     */
    public static function pianifica(array $righe, array $crediti, int $contante, bool $creditoPrima, ?bool $fraGestioni): array
    {
        $totaleRighe = array_sum(array_column($righe, 'importo'));
        $scoperto = max(0, $totaleRighe - $contante);

        $quanto = fn (bool $fra) => $creditoPrima
            ? self::assegnabile($righe, $crediti, $fra)
            : min(self::assegnabile($righe, $crediti, $fra), $scoperto);

        $serveScelta = $quanto(true) > $quanto(false);
        $fra = $serveScelta && $fraGestioni === true;
        $daAssegnare = $quanto($fra);

        // Prima i soldi: il credito sulle ultime righe, i soldi dalla prima. Prima il credito: dalla prima.
        $ordine = $creditoPrima ? $righe : array_reverse($righe);
        $restoRiga = array_column($righe, 'importo', 'chiave');
        $credito = [];

        $passata = function (bool $soloSuaGestione) use ($crediti, $ordine, &$restoRiga, &$credito, &$daAssegnare, &$restoCredito) {
            foreach ($crediti as $c) {
                foreach ($ordine as $r) {
                    if ($daAssegnare <= 0 || $restoCredito[$c['chiave']] <= 0) {
                        break;
                    }
                    if ($soloSuaGestione && $r['gestione'] !== $c['gestione']) {
                        continue;
                    }
                    $quota = min($restoRiga[$r['chiave']], $restoCredito[$c['chiave']], $daAssegnare);
                    if ($quota <= 0) {
                        continue;
                    }
                    $credito[$c['chiave']][$r['chiave']] = ($credito[$c['chiave']][$r['chiave']] ?? 0) + $quota;
                    $restoRiga[$r['chiave']] -= $quota;
                    $restoCredito[$c['chiave']] -= $quota;
                    $daAssegnare -= $quota;
                }
            }
        };

        $restoCredito = array_column($crediti, 'importo', 'chiave');
        $passata(true);
        if ($fra) {
            $passata(false);
        }

        $usato = array_sum(array_map('array_sum', $credito));

        return [
            'credito'      => $credito,
            'contante'     => $restoRiga,
            'usato'        => $usato,
            'serve_scelta' => $serveScelta,
        ];
    }

    /**
     * Il massimo credito che le righe possono assorbire: per gestione, il minore fra il credito e le righe di quella
     * gestione; fra gestioni, il minore fra tutto il credito e tutte le righe.
     */
    private static function assegnabile(array $righe, array $crediti, bool $fraGestioni): int
    {
        if ($fraGestioni) {
            return min(array_sum(array_column($crediti, 'importo')), array_sum(array_column($righe, 'importo')));
        }

        $perGestione = fn (array $voci) => array_reduce($voci, function ($somme, $v) {
            $g = $v['gestione'] === null ? '-' : (string) $v['gestione'];
            $somme[$g] = ($somme[$g] ?? 0) + $v['importo'];
            return $somme;
        }, []);

        $righeG = $perGestione($righe);
        $totale = 0;
        foreach ($perGestione($crediti) as $g => $k) {
            $totale += min($k, $righeG[$g] ?? 0);
        }

        return $totale;
    }
}
