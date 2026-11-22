<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloCollegamento;
use App\Models\Gestionale\Cassa;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;
use App\Services\Assemblee\Seguito\RigaDelSeguito;
use App\Services\Gestionale\DerogaDelFondo;
use Illuminate\Validation\ValidationException;

/**
 * Catena 4, il fondo: il punto «Utilizzo del fondo «Riserva»» nato dal fondo (il fondo è l'origine del punto; un punto che ne parla soltanto non lo
 * sblocca). Approvato, lo sblocco con la delibera ({@see DerogaDelFondo::sblocca()}): la frase della delibera in testa alla motivazione, il testo di prima
 * dopo, la guardia del modello. Un fondo non vincolato non ha niente da sbloccare.
 */
final class SulFondo implements Effetto
{
    public function righe(ContestoDellaDelibera $c): array
    {
        if ($c->materia() !== MateriaPunto::FONDO || ! $c->approvata()) {
            return [];
        }
        $cassa = $c->schedaDOrigine('cassa');
        if (! $cassa instanceof Cassa) {
            return [];
        }
        $titolo = SchedaDelCondominio::nomeDi($cassa) . ': sbloccato con la delibera';
        $diQuesta = CollegamentiDelleDelibere::delibera($cassa, RuoloCollegamento::DEROGA->value)?->id === $c->delibera->id;
        if (! $diQuesta && ! DerogaDelFondo::siSblocca($cassa)) {
            return [RigaDelSeguito::senzaSpunta($c, 'gia_fatto', 'fondo', $titolo, 'Il fondo non è vincolato: non c\'è niente da sbloccare.', null, $cassa, false, [],
                RuoloCollegamento::DEROGA->value)];
        }

        return [RigaDelSeguito::applica($c, $cassa, RuoloCollegamento::DEROGA->value, 'fondo', $titolo,
            'Si applica adesso: il fondo dice «Sbloccato con la delibera del ' . $c->giorno() . '», con la motivazione di prima sotto.')];
    }

    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string
    {
        $cassa = Cassa::query()->whereKey((int) ($riga['scheda']['id'] ?? 0))->where('condominio_id', $c->delibera->condominio_id)->first()
            ?? throw ValidationException::withMessages(['riga' => 'Il fondo non c\'è più.']);
        DerogaDelFondo::sblocca($cassa, $c->delibera, $utente);

        return SchedaDelCondominio::nomeDi($cassa) . ': sbloccato con la delibera del ' . $c->giorno() . '.';
    }
}
