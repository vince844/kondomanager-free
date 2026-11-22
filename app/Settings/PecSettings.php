<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Settings;

/**
 * La casella PEC dello studio (decisioni 564, 569 e 572): da qui partono le convocazioni delle assemblee per PEC e le lettere a chi è in ritardo che
 * l'amministratore sceglie di mandare per PEC (sollecito, diffida, messa in mora). Un gruppo suo, accanto a quello della posta e non dentro le impostazioni delle assemblee, perché la casella serve a più di una cosa.
 *
 * - `indirizzo`, `server`, `porta`, `cifratura` (solo `ssl` o `tls`: la PEC non parte in chiaro), `utente`: come li scrive la pagina «Configurazione email».
 * - `password`: cifrata con `Crypt::encryptString()`, come quella della posta; non torna mai alla pagina e vale solo per il server, la porta e la cifratura
 *   per cui è scritta.
 * - `impostata_il`: quando server, porta, cifratura, utente o password sono cambiati l'ultima volta (la riga «Adesso» di una casella da provare).
 * - `ultima_prova`: l'ultima prova, andata o no, con l'impronta della casella provata ({@see \App\Services\Posta\ImprontaDellaCasella}): vale per la
 *   casella salvata solo se l'impronta è la stessa, così un cambio riporta la casella a «Da provare».
 *
 * Il programma la legge da un punto solo, {@see \App\Services\Posta\CasellaPec::configurazione()}. Il cambio è una riga del registro delle modifiche come le
 * altre impostazioni: la password «cambiata», senza valori; l'ultima prova e il giorno dell'impostazione non entrano.
 */
class PecSettings extends ImpostazioniDelRegistro
{
    public ?string $indirizzo = null;

    public ?string $server = null;

    public int $porta = 465;

    public string $cifratura = 'ssl';

    public ?string $utente = null;

    public ?string $password = null;

    public ?string $impostata_il = null;

    public ?array $ultima_prova = null;

    public static function group(): string
    {
        return 'pec';
    }
}
