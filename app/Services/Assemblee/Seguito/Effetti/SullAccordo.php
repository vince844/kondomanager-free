<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloCollegamento;
use App\Models\Gestionale\AccordoRientro;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;
use App\Services\Assemblee\Seguito\RigaDelSeguito;
use App\Services\Gestionale\AutorizzazioneDellAccordo;
use Illuminate\Validation\ValidationException;

/**
 * Catena 7, gli accordi di rientro: il punto «Autorizzazione di un piano di rientro …» (materia «accordo») **nato dall'accordo** (l'accordo è l'origine del
 * punto). Approvato, l'accordo è autorizzato con il giorno della riunione e il verbale ({@see AutorizzazioneDellAccordo}); le lettere e l'elenco lo leggono
 * come oggi. Un accordo già autorizzato non si tocca, e la riga lo dice. Un punto che ne parla soltanto (il decreto ingiuntivo per le rate non pagate
 * dell'accordo) non lo tocca.
 */
final class SullAccordo implements Effetto
{
    public function righe(ContestoDellaDelibera $c): array
    {
        $accordo = $c->materia() === MateriaPunto::ACCORDO ? $c->schedaDOrigine('accordo_rientro') : null;
        if (! $accordo instanceof AccordoRientro || ! $c->approvata()) {
            return [];
        }
        $titolo = SchedaDelCondominio::nomeDi($accordo) . ' autorizzato';
        $diQuesta = CollegamentiDelleDelibere::delibera($accordo, RuoloCollegamento::AUTORIZZAZIONE->value)?->id === $c->delibera->id;
        if ($accordo->autorizzato_assemblea && ! $diQuesta) {
            return [RigaDelSeguito::senzaSpunta($c, 'gia_fatto', 'accordo', SchedaDelCondominio::nomeDi($accordo) . ': già autorizzato'
                . ($accordo->data_autorizzazione !== null ? ' il ' . $accordo->data_autorizzazione->format('d/m/Y') : ''), 'Niente cambia: la riga lo dice, e non si spunta.',
                null, $accordo, false, [], RuoloCollegamento::AUTORIZZAZIONE->value)];
        }

        return [RigaDelSeguito::applica($c, $accordo, RuoloCollegamento::AUTORIZZAZIONE->value, 'accordo', $titolo,
            'Si applica adesso: l\'accordo diventa autorizzato, con la data della riunione e il verbale. Le lettere e l\'elenco degli accordi lo leggono come oggi.')];
    }

    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string
    {
        $accordo = AccordoRientro::query()->whereKey((int) ($riga['scheda']['id'] ?? 0))->where('condominio_id', $c->delibera->condominio_id)->first()
            ?? throw ValidationException::withMessages(['riga' => 'L\'accordo non c\'è più.']);
        AutorizzazioneDellAccordo::dallaDelibera($accordo, $c->delibera, $utente);

        return SchedaDelCondominio::nomeDi($accordo) . ': autorizzato il ' . $c->giorno() . '.';
    }
}
