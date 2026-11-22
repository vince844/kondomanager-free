<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\OrigineAssemblea;
use App\Models\Assemblee\Delibera;

/**
 * La frase di una delibera in un campo di testo (§1.4 del progetto della tappa 2): **una sola**, e usa **sempre `deliberata_il`** (§13, rilievo 6). «Delibera
 * del 22/10/2026, assemblea straordinaria, punto 1 (verbale n. 41)»; per una riunione di carta «Delibera del 12/03/2024, riunione registrata a mano, punto 1
 * (verbale n. 3/2024)».
 *
 * **Le frasi che restano scritte** in una scheda (lo sblocco del fondo, il riferimento della regola e dell'addebito, la nota del già versato, il compito
 * della cosa e quello chiuso dalla ratifica, la descrizione della gestione nata dalla delibera) si chiedono con `scritta: true`: per l'assemblea tenuta su
 * carta dicono il giorno e il punto, senza «(verbale da caricare)», che un testo scritto direbbe per sempre (decisione 637 (2)); il numero solo se c'è già.
 * Le frasi calcolate a ogni lettura dicono lo stato del verbale, e il numero da sole quando il verbale arriva.
 */
final class DeliberaInParole
{
    public static function frase(Delibera $delibera, bool $scritta = false): string
    {
        $delibera->loadMissing('assemblea', 'punto');
        $assemblea = $delibera->assemblea;
        $chi = $assemblea === null ? null : ($assemblea->origine === OrigineAssemblea::A_MANO ? 'riunione registrata a mano'
            : 'assemblea ' . $assemblea->tipo?->value);

        return 'Delibera del ' . $delibera->deliberata_il?->format('d/m/Y') . ($chi !== null ? ', ' . $chi : '')
            . ($delibera->punto !== null ? ', punto ' . $delibera->punto->numero : '') . self::verbale($delibera, $scritta);
    }

    /** «Delibera del 15/12/2026, punto 3 (verbale n. 42)»: per la riga del registro e per le risposte dei compiti. */
    public static function breve(Delibera $delibera, bool $scritta = false): string
    {
        $delibera->loadMissing('assemblea', 'punto');

        return 'Delibera del ' . $delibera->deliberata_il?->format('d/m/Y') . ($delibera->punto !== null ? ', punto ' . $delibera->punto->numero : '')
            . self::verbale($delibera, $scritta);
    }

    /** «assemblea del 22/10/2026, punto 1 «Rifacimento della facciata…»»: il valore del campo «Delibera» di una scheda nel registro (§6.3). */
    public static function nelRegistro(Delibera $delibera): string
    {
        $delibera->loadMissing('assemblea', 'punto');
        $testo = trim(preg_replace('/\s+/u', ' ', (string) ($delibera->punto?->testo ?? $delibera->testo)) ?? '');
        if (mb_strlen($testo) > 40) {
            $testo = rtrim(mb_substr($testo, 0, 39)) . '…';
        }
        $chi = $delibera->assemblea?->origine === OrigineAssemblea::A_MANO ? 'riunione' : 'assemblea';

        return $chi . ' del ' . $delibera->deliberata_il?->format('d/m/Y') . ($delibera->punto !== null ? ', punto ' . $delibera->punto->numero : '')
            . ($testo !== '' ? ' «' . $testo . '»' : '');
    }

    /**
     * Il numero del verbale **della riunione della delibera** (decisione 615 (7)): un'assemblea sospesa e proseguita ha due verbali, «42 e 43», e ogni delibera
     * dice il numero del suo giorno. Senza un verbale registrato di quel giorno, quello che la riunione tiene **solo se la riunione non ha verbali nel
     * programma** (la riunione di carta, scritto a mano): per la sala il numero del secondo giorno non c'è finché il suo verbale non è registrato, e prendere
     * quello del primo giorno lo direbbe sbagliato nelle schede. `null` se non c'è. È il numero che le schede copiano (la ratifica, il piano, l'accordo, la
     * dispensa) e che la frase dice.
     */
    public static function numeroDelVerbale(Delibera $delibera): ?string
    {
        $delGiorno = $delibera->assemblea_id === null ? null
            : \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::numeroDelGiorno((int) $delibera->assemblea_id, $delibera->deliberata_il?->format('Y-m-d'));
        $conIVerbali = $delibera->assemblea_id !== null
            && \App\Models\Assemblee\VerbaleDellAssemblea::query()->where('assemblea_id', $delibera->assemblea_id)->exists();
        $numero = $delGiorno !== null ? (string) $delGiorno : ($conIVerbali ? '' : trim((string) $delibera->assemblea?->numero_verbale));

        return $numero === '' ? null : $numero;
    }

    /**
     * «(verbale n. 42)»; per l'assemblea tenuta su carta finché il verbale firmato non è caricato «(verbale da caricare)», al posto del numero: il numero
     * compare da solo quando il verbale si carica. Per una frase che resta scritta (`scritta`) lo stato del verbale non c'è: il numero, se c'è già.
     */
    public static function verbale(Delibera $delibera, bool $scritta = false): string
    {
        $assemblea = $delibera->assemblea;
        if (! $scritta && $assemblea !== null && $assemblea->stato === \App\Enums\Assemblee\StatoAssemblea::REGISTRATA_A_MANO && ! $assemblea->eAMano()
            && $assemblea->verbale_documento_id === null) {
            return ' (verbale da caricare)';
        }
        $numero = self::numeroDelVerbale($delibera);

        return $numero !== null ? ' (verbale n. ' . $numero . ')' : '';
    }
}
