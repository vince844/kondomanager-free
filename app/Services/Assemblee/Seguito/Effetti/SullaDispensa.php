<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Gestionale\DispensaSeiMesi;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\PropostaDelPunto;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;
use App\Services\Assemblee\Seguito\RigaDelSeguito;
use App\Services\Gestionale\RevocaDellaDispensa;
use Illuminate\Validation\ValidationException;

/**
 * Catena 6, la dispensa dei sei mesi (art. 1129 c. 9 c.c.: la dispensa è dell'assemblea, «espressa»). Una dispensa nuova è **una cosa da fare** «Registra la
 * dispensa»: si apre la finestra di oggi con la delibera e l'esercizio del punto, e per chi vale (tutti o alcuni) lo sceglie l'amministratore. Salvata con la
 * delibera, la cosa è fatta (R5). La revoca di una dispensa da cui il punto nasce (l'origine) si applica adesso, **non spuntata di serie**
 * ({@see RevocaDellaDispensa}, la risposta proposta alla domanda della revoca: per accenderla si cambia `REVOCA_SPUNTATA`); un punto che parla soltanto della
 * dispensa vecchia (il rinnovo) propone «Registra la dispensa».
 */
final class SullaDispensa implements Effetto
{
    /** La revoca della dispensa nella sala: spenta di serie (una revoca cambia i sei mesi di tutti i crediti). */
    public const REVOCA_SPUNTATA = false;

    public function righe(ContestoDellaDelibera $c): array
    {
        if ($c->materia() !== MateriaPunto::DISPENSA || ! $c->approvata()) {
            return [];
        }
        $dispensa = $c->schedaDOrigine('dispensa_sei_mesi');
        if ($dispensa instanceof DispensaSeiMesi) {
            $diQuesta = CollegamentiDelleDelibere::delibera($dispensa, RuoloCollegamento::REVOCA_DELLA_DISPENSA->value)?->id === $c->delibera->id;
            if ($dispensa->revocata() && ! $diQuesta) {
                return [RigaDelSeguito::senzaSpunta($c, 'gia_fatto', 'dispensa', 'Dispensa dei sei mesi: già revocata',
                    'Già revocata il ' . $dispensa->revocata_il?->format('d/m/Y') . ': niente cambia.', null, $dispensa, false, [],
                    RuoloCollegamento::REVOCA_DELLA_DISPENSA->value)];
            }

            return [RigaDelSeguito::applica($c, $dispensa, RuoloCollegamento::REVOCA_DELLA_DISPENSA->value, 'dispensa',
                'Dispensa dei sei mesi: revocata dal ' . $c->giorno(), 'Si applica adesso, se la spunti: accanto alle date si legge «dispensa revocata».', self::REVOCA_SPUNTATA)];
        }
        $anno = PropostaDelPunto::anno($c->punto?->esercizio_id);

        return [RigaDelSeguito::cosa($c, TipoCosaDaFare::REGISTRA_DISPENSA, 'dispensa', 'Dispensa dall\'agire entro sei mesi' . ($anno !== '' ? ' per i crediti del '
            . $anno : ''), 'Approvata, ma resta da fare: si apre la finestra delle dispense con la delibera' . ($anno !== '' ? ' e l\'esercizio ' . $anno : '')
            . ' già scelti; per chi vale, tutti o alcuni, lo scegli tu.', 'Registra la dispensa' . ($anno !== '' ? ' per i crediti del ' . $anno : ''))];
    }

    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string
    {
        $dispensa = DispensaSeiMesi::query()->whereKey((int) ($riga['scheda']['id'] ?? 0))->where('condominio_id', $c->delibera->condominio_id)->first()
            ?? throw ValidationException::withMessages(['riga' => 'La dispensa non c\'è più.']);
        RevocaDellaDispensa::dallaDelibera($dispensa, $c->delibera, $utente);

        return 'Dispensa dei sei mesi revocata il ' . $c->giorno() . '.';
    }
}
