<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Models\Assemblee\Assemblea;
use App\Models\User;
use App\Services\Assemblee\Seguito\AvvisoAiCondomini;

/**
 * Dove torna la pagina della comunicazione aperta da un'assemblea (decisione 646): «Avviso anche in bacheca» dalla convocazione, «Avvisa i condòmini» dal passo 6.
 * Il server lo decide dagli id che il modulo manda (`assemblea`/`assemblea_id`, `cosa_da_fare`/`cosa_da_fare_id`), per chi gestisce le assemblee e solo se
 * l'assemblea è di un condominio dei destinatari: mai un indirizzo preso dalla richiesta (sarebbe un reindirizzamento aperto).
 */
final class RitornoAllAssemblea
{
    /**
     * @param list<int>|null $condominiDestinatari i condomini della comunicazione, quando si salva; `null` quando si apre la pagina
     * @return array{url: string, testo: string}|null
     */
    public static function per(?User $utente, int $assembleaId, int $cosaId, ?array $condominiDestinatari = null): ?array
    {
        if (! AccessoAlleAssemblee::gestisce($utente)) {
            return null;
        }
        $scheda = 'convocazione';
        if ($assembleaId === 0 && $cosaId !== 0) {
            $cosa = AvvisoAiCondomini::cosaPer($cosaId, $utente);
            $assembleaId = (int) ($cosa?->delibera?->assemblea_id ?? 0);
            $scheda = 'delibere';
        }
        $assemblea = $assembleaId === 0 ? null : Assemblea::query()->find($assembleaId);
        if ($assemblea === null || ($condominiDestinatari !== null && ! in_array((int) $assemblea->condominio_id, array_map('intval', $condominiDestinatari), true))) {
            return null;
        }

        return ['url' => route('admin.gestionale.assemblee.show', ['condominio' => $assemblea->condominio_id, 'assemblea' => $assemblea->id, 'scheda' => $scheda], false),
            'testo' => 'Torna all\'assemblea'];
    }
}
