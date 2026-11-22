<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use App\Enums\Permission;

/**
 * La casella PEC dello studio vista da chi manda per PEC (decisione 564): la convocazione e le lettere a chi è in ritardo. Si legge da
 * {@see CasellaPec::configurazione()} e da nessun'altra parte; sta qui, fra la posta, perché le lettere non dipendono dalle assemblee.
 *
 * Senza la casella «Manda le PEC» è spento e dice «Prima imposta la casella PEC dello studio», con il collegamento alla pagina delle caselle a chi la può
 * aprire (decisione 572, proposta 11). Con l'ultima prova della casella non andata il pulsante resta, e la conferma lo dice: decide l'amministratore.
 */
final class PecDelloStudio
{
    public const NON_IMPOSTATA = 'Prima imposta la casella PEC dello studio.';

    public function __construct(private readonly CasellaPec $casella, private readonly CaselleDelloStudio $caselle) {}

    public function impostata(): bool
    {
        return $this->casella->configurazione() !== null;
    }

    /** Il perché di «Manda le PEC» spento; null con la casella. */
    public function perche(): ?string
    {
        return $this->impostata() ? null : self::NON_IMPOSTATA;
    }

    /** Dove si imposta la casella, con la PEC aperta: solo senza la casella e a chi può aprire le impostazioni. */
    public function dove(): ?string
    {
        $utente = auth()->user();
        if ($this->impostata() || $utente === null || ! $utente->can(Permission::MANAGE_GENERAL_SETTINGS->value)) {
            return null;
        }

        return route('impostazioni.mail', ['apri' => 'pec']);
    }

    /** La frase della conferma di «Manda le PEC» quando l'ultima prova della casella non è andata; null altrimenti. */
    public function avviso(): ?string
    {
        return $this->impostata() ? $this->caselle->avvisoPerLInvio() : null;
    }
}
