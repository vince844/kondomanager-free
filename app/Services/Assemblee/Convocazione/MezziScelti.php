<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\MezzoConvocazione;
use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Il mezzo scelto **per questa assemblea** nella riga della tabella dei convocati (decisione 647): una riga per assemblea e per persona in
 * `assemblee_mezzi_scelti`, che vale finché l'avviso non è spedito e prende il posto del mezzo della scheda o della proposta
 * ({@see ConvocatiDellAssemblea::alGiorno()} la legge, così la stampa, le PEC e l'avviso usano tutti lo stesso mezzo). Con «Ricordalo nella sua scheda» il
 * mezzo si scrive anche in `anagrafiche.mezzo_convocazione`, con la riga del registro delle modifiche che ne viene (il modello della scheda registra da
 * sé), e la scelta per l'assemblea non serve più.
 *
 * La si cambia solo per chi è fra i convocati di oggi e non ha ancora l'avviso spedito, con l'assemblea ancora da convocare; la PEC solo se la persona ha una
 * PEC. Senza mezzo (`null`) la scelta per l'assemblea si toglie e torna il mezzo della scheda o la proposta.
 */
final class MezziScelti
{
    public function __construct(private readonly ElencoDeiConvocati $elenco) {}

    /** I mezzi scelti per l'assemblea, per persona. @return array<int, string> */
    public static function dell(Assemblea $assemblea): array
    {
        return DB::table('assemblee_mezzi_scelti')->where('assemblea_id', $assemblea->id)->pluck('mezzo', 'anagrafica_id')
            ->mapWithKeys(fn ($mezzo, $id) => [(int) $id => (string) $mezzo])->all();
    }

    /**
     * Sceglie (o toglie, con `$mezzo` null) il mezzo di una persona per questa assemblea; con `$ricorda` lo scrive anche nella scheda della persona.
     *
     * @throws ValidationException
     */
    public function scegli(Assemblea $assemblea, Anagrafica $persona, ?string $mezzo, bool $ricorda, ?User $utente): void
    {
        $this->controlla($assemblea, $persona, $mezzo);
        DB::transaction(function () use ($assemblea, $persona, $mezzo, $ricorda, $utente) {
            if ($ricorda && $mezzo !== null) {
                // La scheda della persona registra la modifica da sé (`RegistraLeModifiche`): la riga «Come si convoca» del registro.
                $persona->update(['mezzo_convocazione' => $mezzo]);
                DB::table('assemblee_mezzi_scelti')->where('assemblea_id', $assemblea->id)->where('anagrafica_id', $persona->id)->delete();

                return;
            }
            if ($mezzo === null) {
                DB::table('assemblee_mezzi_scelti')->where('assemblea_id', $assemblea->id)->where('anagrafica_id', $persona->id)->delete();

                return;
            }
            $adesso = now();
            DB::table('assemblee_mezzi_scelti')->upsert([[
                'assemblea_id' => $assemblea->id, 'anagrafica_id' => $persona->id, 'mezzo' => $mezzo, 'created_by' => $utente?->id,
                'created_at' => $adesso, 'updated_at' => $adesso,
            ]], ['assemblea_id', 'anagrafica_id'], ['mezzo', 'created_by', 'updated_at']);
        });
    }

    private function controlla(Assemblea $assemblea, Anagrafica $persona, ?string $mezzo): void
    {
        if ($mezzo !== null && MezzoConvocazione::tryFrom($mezzo) === null) {
            throw ValidationException::withMessages(['mezzo' => 'Come si convoca: PEC, raccomandata, a mano o fax.']);
        }
        if (! in_array($assemblea->stato, [StatoAssemblea::IN_PREPARAZIONE, StatoAssemblea::CONVOCATA], true)) {
            throw ValidationException::withMessages(['mezzo' => 'L\'assemblea non è più da convocare: il mezzo non si cambia.']);
        }
        $voce = collect($this->elenco->per($assemblea))->first(fn ($v) => (int) $v['anagrafica_id'] === (int) $persona->id);
        if ($voce === null || ! $voce['oggi']) {
            throw ValidationException::withMessages(['mezzo' => 'La persona non è fra i convocati di questa assemblea.']);
        }
        if ($voce['spedito']) {
            throw ValidationException::withMessages(['mezzo' => 'L\'avviso di questa persona è già spedito: il mezzo non si cambia.']);
        }
        if ($mezzo === MezzoConvocazione::PEC->value && trim((string) $persona->pec) === '') {
            throw ValidationException::withMessages(['mezzo' => 'La persona non ha una PEC: aggiungila nella sua scheda, o scegli un altro mezzo.']);
        }
    }
}
