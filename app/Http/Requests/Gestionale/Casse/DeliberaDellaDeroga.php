<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Gestionale\Casse;

use App\Http\Controllers\Assemblee\DeliberaNelleSchede;
use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\Cassa;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaInParole;

/**
 * Lo sblocco d'emergenza di un fondo vincolato con la delibera scelta (§1.4 del progetto della tappa 2, ruolo `deroga`; §13 rilievo 29): in
 * `motivazione_override` la frase della delibera **in testa**, e il testo scritto dall'amministratore resta dopo («Delibera del 22/10/2026, assemblea
 * straordinaria, punto 2 (verbale n. 41) — art. 5 del regolamento»). La delibera è del condominio della rotta (R18) e si sceglie con «Gestisce le assemblee».
 */
trait DeliberaDellaDeroga
{
    private ?Delibera $deliberaDellaDeroga = null;

    /** Prima della validazione: con la delibera, la motivazione comincia con la sua frase. */
    protected function prepareForValidation(): void
    {
        if (! $this->boolean('is_override_assemblea')) {
            return;
        }
        $delibera = DeliberaNelleSchede::dallaRichiesta($this, $this->route('condominio'));
        if ($delibera === null) {
            return;
        }
        $this->deliberaDellaDeroga = $delibera;
        $frase = DeliberaInParole::frase($delibera, scritta: true);
        $testo = trim((string) $this->input('motivazione_override', ''));
        // In modifica il testo può cominciare con la frase della delibera di prima: resta il testo scritto dall'amministratore.
        $cassa = $this->route('cassa');
        $diPrima = $cassa instanceof Cassa ? CollegamentiDelleDelibere::delibera($cassa, 'deroga') : null;
        foreach (array_filter([$frase, $diPrima === null ? null : DeliberaInParole::frase($diPrima, scritta: true)]) as $inTesta) {
            if (str_starts_with($testo, $inTesta)) {
                $testo = (string) preg_replace('/^[\s—-]+/u', '', mb_substr($testo, mb_strlen($inTesta)));
            }
        }
        $this->merge(['motivazione_override' => $testo === '' ? $frase : $frase . ' — ' . $testo]);
    }

    /** La delibera dello sblocco, se l'amministratore l'ha scelta. */
    public function deliberaDellaDeroga(): ?Delibera
    {
        return $this->deliberaDellaDeroga;
    }
}
