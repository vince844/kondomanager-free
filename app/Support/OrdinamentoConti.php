<?php

namespace App\Support;

/**
 * Criterio di ordinamento delle voci del piano dei conti — unico per la vista e per
 * la stampa, così l'elenco a schermo e la Distinta in PDF non possono divergere.
 *
 * PERCHÉ ESISTE. Richiesta di un'amministratrice: ordinare la distinta spese per
 * codice invece che per nome. Il codice non poteva però diventare l'unico criterio:
 * `conti.codice` è nullable, senza vincolo di formato né di unicità, e nei condomini
 * reali può essere valorizzato su tutte le voci, su alcune o su nessuna. Ordinare
 * tutti per codice avrebbe quindi rotto l'elenco a chi non li usa — probabilmente la
 * maggioranza. Da qui la scelta di due criteri e un selettore, con "nome" di default.
 *
 * DUE ACCORTEZZE che rendono l'ordinamento per codice utilizzabile davvero:
 *
 *  - le voci SENZA codice finiscono in fondo, raggruppate e ordinate per nome fra
 *    loro, invece di sparpagliarsi in mezzo alle altre (che è ciò che accade
 *    ordinando su un campo vuoto);
 *
 *  - il confronto è NATURALE, non alfabetico: `A.2` viene prima di `A.10` e `999`
 *    prima di `1020`. Con l'ordinamento alfabetico puro accadrebbe il contrario, ed
 *    è il tipo di dettaglio che fa sembrare la funzione rotta.
 */
final class OrdinamentoConti
{
    public const PER_NOME = 'nome';
    public const PER_CODICE = 'codice';

    /** @return array<int, string> */
    public static function criteriAmmessi(): array
    {
        return [self::PER_NOME, self::PER_CODICE];
    }

    /** Normalizza un valore che arriva dall'esterno (query string, preferenza salvata). */
    public static function criterioValido(?string $criterio): string
    {
        return in_array($criterio, self::criteriAmmessi(), true)
            ? $criterio
            : self::PER_NOME;
    }

    /**
     * Ordina una collection di conti (o qualunque iterabile di oggetti con `nome` e
     * `codice`) secondo il criterio indicato, mantenendo l'ordine stabile.
     *
     * @template T of iterable
     */
    public static function applica(iterable $conti, string $criterio)
    {
        $criterio = self::criterioValido($criterio);

        $ordinati = collect($conti)->sortBy(
            fn ($conto) => self::chiave($conto, $criterio),
            SORT_NATURAL | SORT_FLAG_CASE
        );

        return $ordinati->values();
    }

    /**
     * Chiave di ordinamento. Il prefisso "1_" / "2_" tiene le voci senza codice in
     * coda: fa parte della stringa confrontata, quindi funziona con un solo sortBy
     * senza bisogno di un secondo passaggio.
     */
    private static function chiave(object $conto, string $criterio): string
    {
        $nome = (string) ($conto->nome ?? '');

        if ($criterio === self::PER_NOME) {
            return $nome;
        }

        $codice = trim((string) ($conto->codice ?? ''));

        return $codice === ''
            ? '2_'.$nome
            : '1_'.$codice;
    }
}
