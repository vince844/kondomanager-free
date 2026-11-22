<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * I sei mesi per agire dei crediti di un esercizio (art. 1129 c. 9 c.c.), come scheda da cui nasce il memo «Da portare in assemblea» del riquadro dei sei
 * mesi e delle rate scadute (Coda 736): è l'esercizio stesso, letto con il tipo `sei_mesi` di {@see \App\Services\Assemblee\SchedaDelCondominio}, perché il
 * memo e il punto proposto dal programma abbiano la stessa chiave (`sei_mesi:12`) e siano un punto solo (decisione 499 (3)).
 *
 * Solo lettura: l'esercizio si cambia dalla sua pagina, mai da qui. Per questo sta sulla tabella degli esercizi senza ereditarne il modello: niente tratto
 * del registro delle modifiche, che resta all'esercizio (fuori dal registro in `CatalogoDelRegistro::FUORI`).
 */
final class SeiMesiDellEsercizio extends Model
{
    protected $table = 'esercizi';

    public function save(array $options = []): bool
    {
        throw new LogicException('I sei mesi di un esercizio si leggono soltanto: l\'esercizio si cambia dalla sua pagina.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('I sei mesi di un esercizio si leggono soltanto: l\'esercizio si cambia dalla sua pagina.');
    }
}
