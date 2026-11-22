<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Http\Requests\Fornitore\UpdateFornitoreRequest;
use App\Models\Fornitore;

/**
 * Il fornitore (`FornitoriEdit.vue`, `FornitoreController::update`). Il modulo ha tutti i campi della pagina com'è oggi: la richiesta rimette sempre
 * alcune chiavi (i booleani a falso se mancano, l'IBAN a vuoto), e senza l'IBAN il controller toglierebbe il conto corrente. La scrittura è solo del
 * campo, con il modello: niente conto corrente toccato, niente «ritenuta decisa il» (il ripristino non tocca la ritenuta: non è fra i campi ripristinabili).
 * Il capitale sociale va nel modulo in euro, come lo scrive la pagina (la richiesta lo porta in centesimi).
 */
final class ModuloFornitore extends ModuloDellaScheda
{
    public function cosa(?int $id): ?object
    {
        return $id === null ? null : Fornitore::query()->find($id);
    }

    public function rotta(): string
    {
        return 'admin.fornitori.update';
    }

    public function parametri(object $cosa): array
    {
        return ['fornitore' => $cosa];
    }

    public function richiesta(): string
    {
        return UpdateFornitoreRequest::class;
    }

    public function modulo(object $cosa): array
    {
        /** @var Fornitore $cosa */
        $modulo = [];
        foreach ($cosa->getFillable() as $campo) {
            if ($campo === 'ritenuta_decisa_il') {
                continue;
            }
            $modulo[$campo] = $this->valoreDelModulo($campo, $cosa->getRawOriginal($campo));
        }
        $modulo['capitale_sociale'] = $cosa->getRawOriginal('capitale_sociale') === null ? null : ((int) $cosa->getRawOriginal('capitale_sociale')) / 100;
        $modulo['iban_principale'] = $cosa->getRawOriginal('iban_principale')
            ?: $cosa->contiCorrenti()->where('tipo', 'ordinario')->value('iban');

        return $modulo;
    }
}
