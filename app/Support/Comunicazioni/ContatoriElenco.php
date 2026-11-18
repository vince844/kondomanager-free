<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Comunicazioni;

use App\Models\Commento;
use App\Models\Documento;
use App\Models\Comunicazione;
use App\Models\User;
use App\Support\Allegati\RegoleAllegati;
use App\Support\Commenti\RegoleCommenti;
use Illuminate\Support\Collection;

/**
 * Quanti commenti e quanti allegati ha ogni comunicazione di un elenco (decisione 441).
 *
 * Il numero è quello che la scheda mostra a chi guarda, non il totale: nel portale le discussioni che il condòmino vede
 * (`RegoleCommenti::discussioneVisibileAlPortale`) con i commenti pubblicati più i suoi in attesa, e gli allegati che
 * `RegoleAllegati::puoVedere` gli concede; nel pannello tutto, più quanto c'è da approvare.
 *
 * Le regole sono le stesse della scheda. Cambia il modo di chiederle: due richieste per tutto l'elenco (i commenti e gli
 * allegati di tutte le righe), non due per riga. Le persone e i palazzi di una riga si leggono dalle relazioni già caricate
 * dall'elenco (`anagrafiche`, `condomini`).
 */
final class ContatoriElenco
{
    /**
     * @param  Collection<int, Comunicazione>  $comunicazioni  Le righe dell'elenco, con `anagrafiche` e `condomini`.
     * @param  string  $porta  `user` (l'elenco del portale: le righe sono già quelle che la scheda apre) o `admin`.
     * @return array<int, array{commenti: int, allegati: int, in_attesa: int, commenti_in_attesa: int, allegati_in_attesa: int}> Per id di comunicazione.
     */
    public static function per(User $user, Collection $comunicazioni, string $porta): array
    {
        $risultato = [];
        foreach ($comunicazioni as $comunicazione) {
            $risultato[(int) $comunicazione->id] = ['commenti' => 0, 'allegati' => 0, 'in_attesa' => 0, 'commenti_in_attesa' => 0, 'allegati_in_attesa' => 0];
        }
        if ($comunicazioni->isEmpty()) {
            return $risultato;
        }

        $comunicazioni->loadMissing(['anagrafiche', 'condomini']);

        $ids = $comunicazioni->pluck('id')->all();
        $tipo = $comunicazioni->first()->getMorphClass();

        $commentiPerElemento = collect();
        if (RegoleCommenti::haPermesso($user, RegoleCommenti::permesso('comunicazioni', 'visualizza'))) {
            $commenti = Commento::query()
                ->where('commentable_type', $tipo)
                ->whereIn('commentable_id', $ids)
                ->whereIn('stato', ['pubblicato', 'in_attesa']);
            // Un palazzo tolto dai destinatari porta via i commenti scritti da lì, come nella scheda (decisione 459).
            RegoleCommenti::soloQuelliCheRestanoFraIDestinatari($commenti);
            $commentiPerElemento = $commenti
                ->get(['id', 'commentable_id', 'stato', 'user_id', 'condominio_id', 'discussione_user_id'])
                ->groupBy('commentable_id');
        }

        $allegatiPerElemento = Documento::query()
            ->where('documentable_type', $tipo)
            ->whereIn('documentable_id', $ids)
            ->get(['id', 'documentable_id', 'created_by', 'is_published', 'is_approved'])
            ->groupBy('documentable_id');

        $condominiDellUtente = $porta === 'user'
            ? ($user->anagrafica ? $user->anagrafica->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all() : [])
            : null;
        $autoriConPannello = [];

        foreach ($comunicazioni as $comunicazione) {
            $id = (int) $comunicazione->id;

            // Commenti.
            $commenti = $commentiPerElemento->get($id, collect());
            if ($commenti->isNotEmpty() && ($porta === 'user' || RegoleCommenti::apreSchedaPannello($user, $comunicazione))) {
                $visibili = self::chiaviVisibili($user, $comunicazione, $porta, $condominiDellUtente, $autoriConPannello);
                $moderatore = RegoleCommenti::puoModerare($user, $comunicazione);

                foreach ($commenti as $commento) {
                    if (! isset($visibili[RegoleCommenti::chiaveDelCommento($commento, $comunicazione)])) {
                        continue;
                    }

                    $inAttesa = $commento->stato === 'in_attesa';
                    if ($inAttesa && ! $moderatore && (int) $commento->user_id !== (int) $user->id) {
                        continue;
                    }

                    $risultato[$id]['commenti']++;
                    if ($inAttesa && $moderatore) {
                        $risultato[$id]['in_attesa']++;
                        $risultato[$id]['commenti_in_attesa']++;
                    }
                }
            }

            // Allegati.
            $puoApprovare = RegoleCommenti::haPannello($user)
                && RegoleCommenti::haPermesso($user, RegoleAllegati::permesso($comunicazione, 'approva'));
            foreach ($allegatiPerElemento->get($id, collect()) as $allegato) {
                if (! RegoleAllegati::puoVedere($user, $allegato, $comunicazione, schedaGiaVerificata: $porta === 'user')) {
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
     * @param  Collection<int, Comunicazione>  $comunicazioni
     */
    public static function mettiSulleRighe(User $user, Collection $comunicazioni, string $porta): void
    {
        $contatori = self::per($user, $comunicazioni, $porta);

        foreach ($comunicazioni as $comunicazione) {
            $comunicazione->setAttribute('contatori_elenco', $contatori[(int) $comunicazione->id]);
        }
    }

    /**
     * Le chiavi (`tutti`, `persona:9`) delle discussioni della riga che chi guarda può leggere. Pubblica dalla tappa F: la
     * pagina «Commenti» del pannello la usa per sapere in quali discussioni chi guarda può rispondere, senza una richiesta per riga.
     *
     * @param  array<int>|null  $condominiDellUtente
     * @param  array<int, bool>  $autoriConPannello  Se l'autore di una privata ha il pannello, per autore (una richiesta per autore, non per riga).
     * @return array<string, true>
     */
    public static function chiaviVisibili(User $user, Comunicazione $comunicazione, string $porta, ?array $condominiDellUtente, array &$autoriConPannello): array
    {
        $candidate = [];

        if (RegoleCommenti::eAPersoneOPrivata($comunicazione)) {
            foreach ($comunicazione->anagrafiche as $anagrafica) {
                if ($anagrafica->user_id !== null) {
                    $candidate['persona:' . $anagrafica->user_id] = ['persona', (int) $anagrafica->user_id];
                }
            }

            // Una privata scritta da un condòmino ha la discussione anche con lui (`RegoleCommenti::personeDestinatarie`).
            if ($comunicazione->is_private && $comunicazione->created_by !== null) {
                $autore = (int) $comunicazione->created_by;
                if (! array_key_exists($autore, $autoriConPannello)) {
                    $autoriConPannello[$autore] = $comunicazione->createdBy !== null
                        && RegoleCommenti::haPannello($comunicazione->createdBy);
                }
                if ($comunicazione->createdBy !== null && ! $autoriConPannello[$autore]) {
                    $candidate['persona:' . $autore] = ['persona', $autore];
                }
            }
        } elseif ($comunicazione->condomini->isNotEmpty()) {
            // Ai palazzi, uno o più: la discussione unica (decisione 458).
            $candidate[RegoleCommenti::CHIAVE_UNICA] = ['tutti', 0];
        }

        if ($porta === 'admin') {
            return array_fill_keys(array_keys($candidate), true);
        }

        $visibili = [];
        foreach ($candidate as $chiave => [$tipo, $id]) {
            if (RegoleCommenti::discussioneVisibileAlPortale($user, $comunicazione, $tipo, $id, $condominiDellUtente)) {
                $visibili[$chiave] = true;
            }
        }

        return $visibili;
    }
}
