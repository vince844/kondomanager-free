<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Segnalazioni;

use App\Models\Commento;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Support\Allegati\RegoleAllegati;
use App\Support\Commenti\RegoleCommenti;
use Illuminate\Support\Collection;

/**
 * Quanti commenti e quanti allegati ha ogni segnalazione di un elenco (decisione 453), la gemella per le segnalazioni di
 * `App\Support\Comunicazioni\ContatoriElenco`.
 *
 * Il numero è quello che la scheda mostra a chi guarda: nel pannello i commenti pubblicati più, per chi modera, quelli in
 * attesa (`SegnalazioneController::show`); nel portale i pubblicati più i propri in attesa; gli allegati che
 * `RegoleAllegati::puoVedere` concede. Le regole sono quelle della scheda; cambia il modo di chiederle: due richieste per
 * tutto l'elenco, non due per riga.
 */
final class ContatoriElencoSegnalazioni
{
    /**
     * @param  Collection<int, Segnalazione>  $segnalazioni  Le righe dell'elenco.
     * @param  string  $porta  `user` (le righe sono già quelle che la scheda apre) o `admin`.
     * @return array<int, array{commenti: int, allegati: int, in_attesa: int, commenti_in_attesa: int, allegati_in_attesa: int}> Per id di segnalazione.
     */
    public static function per(User $user, Collection $segnalazioni, string $porta): array
    {
        $risultato = [];
        foreach ($segnalazioni as $segnalazione) {
            $risultato[(int) $segnalazione->id] = ['commenti' => 0, 'allegati' => 0, 'in_attesa' => 0, 'commenti_in_attesa' => 0, 'allegati_in_attesa' => 0];
        }
        if ($segnalazioni->isEmpty()) {
            return $risultato;
        }

        $ids = $segnalazioni->pluck('id')->all();
        $tipo = $segnalazioni->first()->getMorphClass();

        $commentiPerElemento = Commento::query()
            ->where('commentable_type', $tipo)
            ->whereIn('commentable_id', $ids)
            ->whereIn('stato', ['pubblicato', 'in_attesa'])
            ->get(['id', 'commentable_id', 'stato', 'user_id'])
            ->groupBy('commentable_id');

        $allegatiPerElemento = Documento::query()
            ->where('documentable_type', $tipo)
            ->whereIn('documentable_id', $ids)
            ->get(['id', 'documentable_id', 'created_by', 'is_published', 'is_approved'])
            ->groupBy('documentable_id');

        foreach ($segnalazioni as $segnalazione) {
            $id = (int) $segnalazione->id;

            // Commenti: la scheda del pannello carica gli «in attesa» solo a chi modera; quella del portale solo i propri.
            $moderatore = $porta === 'admin' && RegoleCommenti::puoModerare($user, $segnalazione);
            foreach ($commentiPerElemento->get($id, collect()) as $commento) {
                if ($commento->stato === 'pubblicato') {
                    $risultato[$id]['commenti']++;
                    continue;
                }

                if ($porta === 'admin') {
                    if ($moderatore) {
                        $risultato[$id]['commenti']++;
                        $risultato[$id]['in_attesa']++;
                        $risultato[$id]['commenti_in_attesa']++;
                    }
                } elseif ((int) $commento->user_id === (int) $user->id) {
                    $risultato[$id]['commenti']++;
                }
            }

            // Allegati.
            $puoApprovare = RegoleCommenti::haPannello($user)
                && RegoleCommenti::haPermesso($user, RegoleAllegati::permesso($segnalazione, 'approva'));
            foreach ($allegatiPerElemento->get($id, collect()) as $allegato) {
                if (! RegoleAllegati::puoVedere($user, $allegato, $segnalazione, schedaGiaVerificata: $porta === 'user')) {
                    continue;
                }

                $risultato[$id]['allegati']++;
                if ($puoApprovare && ! ((bool) $allegato->is_published && (bool) $allegato->is_approved)) {
                    $risultato[$id]['in_attesa']++;
                    $risultato[$id]['allegati_in_attesa']++;
                }
            }
        }

        return $risultato;
    }

    /**
     * Calcola i contatori e li mette su ogni riga (`contatori_elenco`), da dove la risorsa dell'elenco li prende.
     *
     * @param  Collection<int, Segnalazione>  $segnalazioni
     */
    public static function mettiSulleRighe(User $user, Collection $segnalazioni, string $porta): void
    {
        $contatori = self::per($user, $segnalazioni, $porta);

        foreach ($segnalazioni as $segnalazione) {
            $segnalazione->setAttribute('contatori_elenco', $contatori[(int) $segnalazione->id]);
        }
    }
}
