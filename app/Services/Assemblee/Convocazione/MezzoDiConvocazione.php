<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\MezzoConvocazione;

/**
 * Il mezzo con cui si convoca una persona (art. 66 c. 3 disp. att.): quello scelto nella sua scheda (`anagrafiche.mezzo_convocazione`) o, vuoto, la proposta
 * dai dati: la PEC se c'è, altrimenti la raccomandata se l'indirizzo non è vuoto, altrimenti nessun mezzo («Non convocabile»). **L'email semplice non è mai un
 * mezzo** (Cass. 16399/2025): si può mandare come avviso in più, e la pagina lo dice.
 *
 * Con un punto «Modifica della destinazione d'uso» valgono solo la raccomandata e la PEC (art. 1117-ter: «raccomandata o mezzi telematici equipollenti»):
 * «A mano» e «Fax» danno «Non convocabile» con il perché, e la spedizione non parte finché resta qualcuno così.
 *
 * La PEC proposta a chi non l'ha comunicata (`pec_comunicata_il` vuota) resta proposta, con un segno sulla riga e una riga sola in testa alla tabella: il
 * merito diverge, e il programma avvisa.
 */
final class MezzoDiConvocazione
{
    public const SOLO_EMAIL = 'ha solo l\'email: aggiungi la PEC o l\'indirizzo';

    public const NESSUN_DATO = 'aggiungi la PEC o l\'indirizzo, o scegli «A mano»';

    public const SOLO_RACCOMANDATA_E_PEC = 'con un punto sulla destinazione d\'uso valgono solo la raccomandata e la PEC (art. 1117-ter)';

    public const PEC_ASSENTE = 'nella sua scheda è scelta la PEC, ma la PEC non c\'è: aggiungila';

    public const INDIRIZZO_ASSENTE = 'nella sua scheda è scelta la raccomandata, ma l\'indirizzo è vuoto: aggiungilo';

    /**
     * Il mezzo di una persona.
     *
     * @param array{mezzo_convocazione?: ?string, pec?: ?string, indirizzo?: ?string, email?: ?string, pec_comunicata_il?: mixed} $persona
     * @return array{mezzo: ?string, proposto: bool, valido: bool, perche: ?string, pec_non_comunicata: bool}
     */
    public static function per(array $persona, bool $soloRaccomandataEPec = false): array
    {
        $pec = trim((string) ($persona['pec'] ?? ''));
        $indirizzo = trim((string) ($persona['indirizzo'] ?? ''));
        $email = trim((string) ($persona['email'] ?? ''));
        $scelto = MezzoConvocazione::tryFrom((string) ($persona['mezzo_convocazione'] ?? ''))?->value;
        $comunicata = filled($persona['pec_comunicata_il'] ?? null);

        $mezzo = $scelto ?? ($pec !== '' ? MezzoConvocazione::PEC->value : ($indirizzo !== '' ? MezzoConvocazione::RACCOMANDATA->value : null));
        $esito = ['mezzo' => $mezzo, 'proposto' => $scelto === null && $mezzo !== null, 'valido' => true, 'perche' => null,
            'pec_non_comunicata' => $mezzo === MezzoConvocazione::PEC->value && ! $comunicata];

        $perche = match (true) {
            $mezzo === null => $email !== '' ? self::SOLO_EMAIL : self::NESSUN_DATO,
            $mezzo === MezzoConvocazione::PEC->value && $pec === '' => self::PEC_ASSENTE,
            $mezzo === MezzoConvocazione::RACCOMANDATA->value && $indirizzo === '' => self::INDIRIZZO_ASSENTE,
            $soloRaccomandataEPec && in_array($mezzo, [MezzoConvocazione::A_MANO->value, MezzoConvocazione::FAX->value], true) => self::SOLO_RACCOMANDATA_E_PEC,
            default => null,
        };
        if ($perche !== null) {
            $esito['valido'] = false;
            $esito['perche'] = $perche;
            $esito['pec_non_comunicata'] = false;
        }

        return $esito;
    }

    /** La parola del mezzo per la tabella: «PEC», «Raccomandata», «A mano», «Fax», o «Nessun mezzo». */
    public static function parola(?string $mezzo): string
    {
        return MezzoConvocazione::tryFrom((string) $mezzo)?->parola() ?? 'Nessun mezzo';
    }
}
