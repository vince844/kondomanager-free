<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Settings;

/**
 * Le assemblee, le impostazioni dello studio (Coda 679, §1.2 del progetto della tappa 2). `giorni_di_posta`: quanti giorni lavorativi lo studio conta per
 * l'arrivo di una raccomandata, di serie 3 (il numero della bozza approvata), per proporre il giorno entro cui spedirle (decisione 565).
 *
 * La casella PEC dello studio **non** sta qui (decisione 564: dove si imposta non è deciso): si legge da {@see \App\Services\Posta\CasellaPec}.
 *
 * La sala (decisione 492 (2)):
 * - `delega_oltre_il_quinto`: con più di venti condòmini, la delega che porta il delegato oltre un quinto dei condòmini o del valore (art. 67 c. 1 disp.
 *   att.) si rifiuta con l'articolo (`rifiuta`, di serie) o la decide il presidente con il suo motivo (`decide_il_presidente`);
 * - `voto_discorde_comproprietari`: i comproprietari presenti che votano in modo diverso contano astenuti (`astenuti`, di serie), o decide il presidente.
 *
 * Il cambio è una riga del registro come le altre impostazioni (gesto 8, `ImpostazioniNelRegistro`).
 */
class AssembleeSettings extends ImpostazioniDelRegistro
{
    public const RIFIUTA = 'rifiuta';

    public const DECIDE_IL_PRESIDENTE = 'decide_il_presidente';

    public const ASTENUTI = 'astenuti';

    public int $giorni_di_posta = 3;

    public string $delega_oltre_il_quinto = self::RIFIUTA;

    public string $voto_discorde_comproprietari = self::ASTENUTI;

    public static function group(): string
    {
        return 'assemblee';
    }
}
