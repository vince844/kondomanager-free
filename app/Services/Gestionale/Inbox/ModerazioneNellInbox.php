<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Inbox;

use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Chi tiene in regola il compito «da moderare» ({@see CompitoDaModerare}) da qualunque pagina: ascolta i modelli, non le pagine. Un commento che nasce in attesa
 * o cambia stato (approvato, nascosto, tornato in attesa, eliminato, ripristinato), un allegato o un documento che nasce da approvare o si approva o si
 * elimina, una comunicazione o un evento che si approva, un elemento che si elimina: ognuno porta il compito dell'elemento a com'è. Così la scheda, la pagina
 * «Commenti», la pagina «Allegati» e le azioni in blocco chiudono il compito senza saperlo.
 *
 * Le chiavi esterne in cascata non passano dagli eventi dei modelli: un utente eliminato porta via i suoi commenti (e quelli della sua discussione
 * personale) senza che `Commento::deleted` lo sappia. Per questo l'utente che si elimina dice prima quali elementi avevano suoi commenti in attesa, e
 * dopo il loro compito si porta a com'è (Fase 1-bis della 1.12.0-beta.11).
 *
 * Si registra una volta, all'avvio dell'applicazione (`AppServiceProvider`).
 */
final class ModerazioneNellInbox
{
    /** @var array<int, array<int, array{0: string, 1: int}>> Per utente che si sta eliminando, gli elementi con suoi commenti in attesa. */
    private static array $elementiDellUtente = [];

    public static function registra(): void
    {
        Commento::saved(function (Commento $commento) {
            // Un commento in attesa corretto dal suo autore aspetta da adesso (`modificato_at`): anche questo cambia il compito.
            if ($commento->wasChanged('stato') || ($commento->wasRecentlyCreated && $commento->stato === 'in_attesa')
                || ($commento->stato === 'in_attesa' && $commento->wasChanged('modificato_at'))) {
                self::delCommento($commento);
            }
        });
        Commento::deleted(fn (Commento $commento) => self::delCommento($commento));
        Commento::restored(fn (Commento $commento) => self::delCommento($commento));

        Documento::saved(function (Documento $documento) {
            if ($documento->wasChanged('is_approved') || ($documento->wasRecentlyCreated && ! $documento->is_approved)) {
                self::delDocumento($documento);
            }
        });
        Documento::deleted(fn (Documento $documento) => self::delDocumento($documento, true));

        Comunicazione::saved(function (Comunicazione $comunicazione) {
            if ($comunicazione->wasChanged('is_approved') || ($comunicazione->wasRecentlyCreated && ! $comunicazione->is_approved)) {
                app(CompitoDaModerare::class)->sincronizza($comunicazione);
            }
        });
        Comunicazione::deleted(fn (Comunicazione $comunicazione) => app(CompitoDaModerare::class)->togli($comunicazione));
        Segnalazione::deleted(fn (Segnalazione $segnalazione) => app(CompitoDaModerare::class)->togli($segnalazione));

        Evento::saved(function (Evento $evento) {
            if (! CompitoDaModerare::eUnEventoDellAgenda($evento)) {
                return;
            }
            if ($evento->wasChanged('is_approved') || ($evento->wasRecentlyCreated && ! $evento->is_approved)) {
                // Dopo il salvataggio dei condomini, che chi crea l'evento scrive subito dopo, nella stessa transazione.
                DB::afterCommit(fn () => app(CompitoDaModerare::class)->sincronizza($evento->fresh() ?? $evento));
            }
        });
        Evento::deleted(function (Evento $evento) {
            if (CompitoDaModerare::eUnEventoDellAgenda($evento)) {
                app(CompitoDaModerare::class)->togli($evento);
            }
        });

        User::deleting(function (User $utente) {
            self::$elementiDellUtente[(int) $utente->getKey()] = Commento::query()
                ->where('stato', 'in_attesa')
                ->where(fn ($q) => $q->where('user_id', $utente->getKey())->orWhere('discussione_user_id', $utente->getKey()))
                ->distinct()->get(['commentable_type', 'commentable_id'])
                ->map(fn (Commento $c) => [(string) $c->commentable_type, (int) $c->commentable_id])->all();
        });
        User::deleted(function (User $utente) {
            $elementi = self::$elementiDellUtente[(int) $utente->getKey()] ?? [];
            unset(self::$elementiDellUtente[(int) $utente->getKey()]);
            foreach ($elementi as [$tipo, $id]) {
                $classe = \Illuminate\Database\Eloquent\Model::getActualClassNameForMorph($tipo);
                // L'elemento creato dall'utente se n'è andato con lui, e il suo compito pure: resta da fare solo per gli altri.
                $elemento = in_array($classe, [Segnalazione::class, Comunicazione::class], true) ? $classe::query()->find($id) : null;
                if ($elemento !== null) {
                    app(CompitoDaModerare::class)->sincronizza($elemento);
                }
            }
        });
    }

    private static function delCommento(Commento $commento): void
    {
        $elemento = $commento->commentable;
        if ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione) {
            app(CompitoDaModerare::class)->sincronizza($elemento, $commento->autore);
        }
    }

    /** Un allegato (il documento di una segnalazione o di una comunicazione) tocca il compito dell'elemento; un documento dell'archivio, il suo. */
    private static function delDocumento(Documento $documento, bool $eliminato = false): void
    {
        if ($documento->documentable_type === null) {
            $eliminato ? app(CompitoDaModerare::class)->togli($documento) : app(CompitoDaModerare::class)->sincronizza($documento);

            return;
        }
        $elemento = $documento->documentable;
        if ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione) {
            app(CompitoDaModerare::class)->sincronizza($elemento, $documento->createdBy);
        }
    }
}
