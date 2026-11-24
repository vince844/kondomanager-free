<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Inbox;

use App\Enums\EventoTipo;
use App\Enums\Permission;
use App\Helpers\DateHelper;
use App\Models\Errori\VoltaDellErrore;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\InboxService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Il compito «Errori avvisati» della Action inbox (la 698 (4) del registro delle decisioni; bozza docs/design/beta14_pagine_errore_grafica_bozza.html, scena 11): un condòmino o un
 * collaboratore ha avvisato lo studio di un errore dalla pagina d'errore.
 *
 * - **Uno per errore**: l'errore è l'`eventable`; un secondo avviso dello stesso errore, finché il compito è aperto, si aggiunge a quello (`meta.avvisi`),
 *   con il titolo e la pagina dell'ultimo. Solo l'avviso che fa nascere il compito manda l'email (`$compitoNato`, Coda 788).
 * - Il titolo «Errore K7M2QX avvisato», senza il nome: lo copia il registro delle modifiche, che legge anche chi non ha il permesso. Il nome di chi ha
 *   avvisato e la nota stanno sotto, nella descrizione. Il condominio è quello di chi avvisa (nessuno, per chi non ne ha: è dello studio).
 * - «Apri l'errore» porta alla scheda «Errori» delle impostazioni con il codice cercato, che apre l'errore da sola.
 * - **Lo legge, lo conta e lo chiude solo chi ha «Gestisci impostazioni generali»** ({@see chiLoVede()}, {@see aggiungiLaRegola()}): la regola entra in
 *   `CompitoDellaSegnalazione::soloQuelliCheVede()`, che vale per le due Inbox, il numero del menu e le risposte.
 */
final class CompitoDellErrore
{
    public const TIPO = 'errore_avvisato';

    /**
     * Il titolo del promemoria di «Ricordamelo» su questo compito ({@see \App\Services\Gestionale\RispostePromemoria::promemoriaAParte()}): senza il
     * codice, il nome di chi ha avvisato e la sua nota, che legge solo chi ha «Gestisci impostazioni generali». L'agenda dello studio la legge anche chi non
     * ce l'ha. Il compito resta al suo giorno, nascosto nell'Inbox fino a quello del promemoria.
     */
    public const TITOLO_DEL_PROMEMORIA = 'Riprendi un errore avvisato';

    /** La descrizione di serie dello stesso promemoria: anche qui niente codice, nome né nota. */
    public const DESCRIZIONE_DEL_PROMEMORIA = 'L\'avviso resta nell\'Inbox al suo giorno: si legge aprendolo.';

    public static function chiLoVede(?User $utente): bool
    {
        return $utente !== null && (bool) rescue(fn () => $utente->checkPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value), false, false);
    }

    /** @param array<string, mixed>|null $vede la regola di chi legge ({@see CompitoDaModerare::chiVede()}, la chiave `errori`) */
    public static function aggiungiLaRegola(Builder $q, ?array $vede): void
    {
        if (! ($vede['errori'] ?? false)) {
            return;
        }
        $tipoSql = FasceDeiCompiti::sqlTipo();
        $q->orWhere(fn (Builder $suo) => $suo->whereRaw("COALESCE({$tipoSql}, '') = ?", [self::TIPO]));
    }

    /**
     * Vero se l'ultimo {@see avvisato()} ha fatto nascere il compito (il primo avviso di quell'errore, o il primo dopo la chiusura del precedente): è il
     * momento dell'unica email per errore (Coda 788). Un avviso a compito aperto lo lascia falso.
     */
    public bool $compitoNato = false;

    public function avvisato(VoltaDellErrore $volta, User $chi): Evento
    {
        $this->compitoNato = false;
        $errore = $volta->errore;
        $titolo = 'Errore '.$volta->codice.' avvisato';
        $chiHaAvvisato = 'Ti ha avvisato '.Str::limit(trim((string) $chi->name), 200, '…');
        $descrizione = $volta->nota_avviso !== null ? $chiHaAvvisato.': «'.Str::limit($volta->nota_avviso, 300, '…').'»' : $chiHaAvvisato.', senza una nota.';
        $indirizzo = route('logs.index', ['scheda' => 'errori', 'cerca' => $volta->codice], false);
        $condominio = rescue(fn () => $chi->anagrafica?->condomini()->orderBy('condomini.id')->value('condomini.id'), null, false);

        $compito = Evento::query()->compitiNonChiusi()
            ->where('tipo', self::TIPO)
            ->where('eventable_type', $errore->getMorphClass())
            ->where('eventable_id', $errore->id)
            ->latest('id')
            ->first();

        if ($compito !== null) {
            $meta = (array) $compito->meta;
            $compito->forceFill([
                'title' => $titolo,
                'description' => $descrizione,
                'meta' => array_merge($meta, [
                    'avvisi' => (int) ($meta['avvisi'] ?? 1) + 1,
                    'codice' => $volta->codice,
                    'action_url' => IndirizzoDelCompito::percorso($indirizzo),
                ]),
            ])->save();
            if ($condominio !== null) {
                $compito->condomini()->syncWithoutDetaching([(int) $condominio]);
            }
            InboxService::clearAdminCache();

            return $compito;
        }

        $this->compitoNato = true;

        return InboxService::createTask(
            tipo: EventoTipo::ERRORE_AVVISATO,
            title: $titolo,
            description: $descrizione,
            scadenza: DateHelper::adessoComeSiLegge(),
            createdByUserId: (int) $chi->id,
            condominioId: $condominio !== null ? (int) $condominio : null,
            context: ['errore_id' => (int) $errore->id, 'codice' => $volta->codice],
            actionUrl: $indirizzo,
            extraMeta: ['avvisi' => 1, 'codice' => $volta->codice],
            eventableType: $errore->getMorphClass(),
            eventableId: (int) $errore->id,
        );
    }
}
