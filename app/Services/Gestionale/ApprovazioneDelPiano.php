<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\StatoPianoRate;
use App\Events\Gestionale\PianoRateStatusUpdated;
use App\Models\Assemblee\Delibera;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaInParole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Approva» e «Torna in bozza» di un piano rate: il corpo che stava nel controllore del piano (`PianoRateController::updateStato`), in un servizio che chiamano
 * la pagina del piano e il seguito delle delibere. Le regole sono quelle di prima, una per una:
 *
 * - il piano degli addebiti nasce approvato senza una delibera e resta così;
 * - con «Urgenza» (art. 1135 c. 2 c.c.) la data della delibera non si chiede e, se arriva, non si scrive (decisione 12);
 * - sul piano già emesso con una data diversa la delibera si collega e la data **resta** quella del piano (le rate sono state calcolate con quella): la
 *   differenza si dice;
 * - tornare in bozza azzera l'audit dell'approvazione, **non** la data della delibera, che è un fatto dell'assemblea;
 * - lo stato e il collegamento della delibera nella stessa transazione; l'evento `PianoRateStatusUpdated` dopo il commit.
 */
final class ApprovazioneDelPiano
{
    /**
     * Cambia lo stato del piano. `$data` e `$numeroVerbale` sono già quelli della delibera, quando c'è.
     *
     * @return array{differenza: ?string, data_cambiata: bool, data_precedente: ?string, data: ?string, urgenza: bool}
     */
    public static function cambiaStato(PianoRate $piano, bool $approvato, ?string $data, ?string $numeroVerbale, ?string $nota, ?Delibera $delibera,
        ?User $utente, ?Esercizio $esercizio = null): array
    {
        if (($rifiuto = $piano->rifiutoDegliAddebiti()) !== null) {
            throw ValidationException::withMessages(['approvato' => $rifiuto]);
        }
        $urgenza = $piano->tipo_autorizzazione === 'urgenza';
        if ($urgenza) {
            $delibera = null;
        }
        $vecchioStato = $piano->stato;
        $nuovoStato = $approvato ? StatoPianoRate::APPROVATO : StatoPianoRate::BOZZA;
        $aggiorna = ['stato' => $nuovoStato];
        $dataPrecedente = $piano->data_delibera_assemblea?->toDateString();
        $dataCambiata = false;

        $differenza = null;
        if ($delibera !== null && $piano->eImmutabile() && $dataPrecedente !== null && $dataPrecedente !== $data) {
            $differenza = 'La delibera è del ' . CarbonImmutable::parse((string) $data)->format('d/m/Y') . ', il piano dice '
                . CarbonImmutable::parse($dataPrecedente)->format('d/m/Y');
            $data = $dataPrecedente;
        }

        if ($approvato) {
            if (! $urgenza) {
                $aggiorna['data_delibera_assemblea'] = $data;
                $dataCambiata = ! empty($data) && $dataPrecedente !== null && $dataPrecedente !== CarbonImmutable::parse($data)->toDateString();
            }
            $aggiorna['numero_verbale'] = $numeroVerbale;
            $aggiorna['nota_approvazione'] = $nota;
            $aggiorna['approvato_da_user_id'] = $utente?->id;
            $aggiorna['approvato_il'] = now();
        } else {
            $aggiorna['numero_verbale'] = null;
            $aggiorna['nota_approvazione'] = null;
            $aggiorna['approvato_da_user_id'] = null;
            $aggiorna['approvato_il'] = null;
        }

        DB::transaction(function () use ($piano, $aggiorna, $delibera, $utente) {
            $piano->update($aggiorna);
            if ($delibera !== null && $utente !== null) {
                CollegamentiDelleDelibere::collega($piano, RuoloCollegamento::DELIBERA->value, $delibera, null, $utente);
            }
        });

        $esercizio ??= $piano->esercizio_id === null ? null : Esercizio::query()->find($piano->esercizio_id);
        if ($utente !== null && $esercizio !== null && $piano->condominio !== null) {
            PianoRateStatusUpdated::dispatch($piano->condominio, $esercizio, $piano, $utente, $vecchioStato, $nuovoStato);
        }

        return ['differenza' => $differenza, 'data_cambiata' => $dataCambiata, 'data_precedente' => $dataPrecedente, 'data' => $data, 'urgenza' => $urgenza];
    }

    /**
     * L'approvazione dalla delibera (il seguito delle delibere, la catena del preventivo): il giorno della riunione e il numero del verbale di quel giorno.
     * Rende la frase dell'esito, o la differenza della data sul piano già emesso.
     */
    public static function dallaDelibera(PianoRate $piano, Delibera $delibera, User $utente): string
    {
        if ($piano->tipo_autorizzazione === 'urgenza') {
            throw ValidationException::withMessages(['piano' => 'Il piano è un intervento d\'urgenza: si riferisce all\'assemblea, non si approva con una delibera.']);
        }
        $numero = DeliberaInParole::numeroDelVerbale($delibera) ?? $piano->numero_verbale;
        $esito = self::cambiaStato($piano, true, $delibera->deliberata_il?->format('Y-m-d'), $numero, $piano->nota_approvazione, $delibera, $utente);

        return $esito['differenza'] !== null ? 'Piano approvato e legato alla delibera. ' . $esito['differenza'] . ': il piano è emesso e la data non cambia da sola.'
            : 'Il piano «' . $piano->nome . '» passa ad approvato con la data della riunione.';
    }
}
