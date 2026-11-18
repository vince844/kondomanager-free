<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\CategoriaEventoEnum;
use App\Enums\EventoTipo;
use App\Models\User;
use App\Models\Evento;
use App\Enums\Role;
use App\Enums\VisibilityStatus;
use App\Models\CategoriaEvento;
use App\Services\Gestionale\Inbox\CompitoDaModerare;
use App\Services\Gestionale\Inbox\CompitoDellaSegnalazione;
use App\Services\Gestionale\Inbox\FasceDeiCompiti;
use App\Services\Gestionale\Inbox\IndirizzoDelCompito;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * InboxService
 * * Gestisce la logica di business per l'Inbox amministrativo, inclusi i conteggi 
 * in tempo reale e l'invalidazione della cache per garantire la sincronia tra admin.
 */
class InboxService
{
    /**
     * Crea un task per l'Admin Inbox in modo centralizzato e sicuro.
     * Gestisce in automatico category_id, visibility, e requires_action. Il collegamento si salva come percorso (`IndirizzoDelCompito`): non dipende
     * dall'indirizzo del sito con cui il compito nasce.
     */
    public static function createTask(
        EventoTipo $tipo,
        string $title,
        string $description,
        Carbon $scadenza,
        int $createdByUserId,
        ?int $condominioId = null,
        array $context = [],
        ?string $actionUrl = null,
        array $extraMeta = [],
        ?string $eventableType = null,
        ?int $eventableId = null,
        string $priorita = 'normale'
    ): Evento {
        $catAdmin = CategoriaEvento::firstOrCreate(
            ['name' => CategoriaEventoEnum::SCADENZE_AMMINISTRATIVE->value],
            ['description' => 'Auto']
        );

        $meta = array_merge([
            'type' => $tipo->value,
            'requires_action' => true,
            'action_url' => IndirizzoDelCompito::percorso($actionUrl),
            'context' => $context,
        ], $extraMeta);

        $evento = Evento::create([
            'title' => $title,
            'description' => $description,
            'start_time' => $scadenza,
            'end_time' => $scadenza->copy()->addHour(),
            'created_by' => $createdByUserId,
            'category_id' => $catAdmin->id,
            'visibility' => VisibilityStatus::HIDDEN->value,
            'is_approved' => true,
            'tipo' => $tipo,
            'meta' => $meta,
            'eventable_type' => $eventableType,
            'eventable_id' => $eventableId,
            'priorita' => $priorita,
        ]);

        if ($condominioId) {
            $evento->condomini()->syncWithoutDetaching([$condominioId]);
        }

        self::clearAdminCache();

        return $evento;
    }

    /**
     * Un promemoria nell'agenda del condominio, scritto da «Ricordamelo» o da «Mettilo in agenda»: un evento a sé, **non** un compito (`meta.requires_action`
     * falso: altrimenti nell'Inbox ci sarebbero due compiti per la stessa cosa), nascosto ai condòmini, nella categoria «Scadenze amministrative», un'ora di
     * durata, con il ritorno al compito o alla riga da cui nasce (`meta.action_url`).
     */
    public static function creaPromemoria(
        string $title,
        string $description,
        Carbon $quando,
        int $createdByUserId,
        ?int $condominioId,
        ?string $actionUrl,
        array $context = [],
    ): Evento {
        $categoria = CategoriaEvento::firstOrCreate(
            ['name' => CategoriaEventoEnum::SCADENZE_AMMINISTRATIVE->value],
            ['description' => 'Auto']
        );

        $evento = Evento::create([
            'title' => $title,
            'description' => $description,
            'start_time' => $quando,
            'end_time' => $quando->copy()->addHour(),
            'created_by' => $createdByUserId,
            'category_id' => $categoria->id,
            'visibility' => VisibilityStatus::HIDDEN->value,
            'is_approved' => true,
            'tipo' => EventoTipo::PROMEMORIA,
            'meta' => ['type' => EventoTipo::PROMEMORIA->value, 'requires_action' => false, 'action_url' => IndirizzoDelCompito::percorso($actionUrl), 'context' => $context],
        ]);

        if ($condominioId) {
            $evento->condomini()->syncWithoutDetaching([$condominioId]);
        }

        return $evento;
    }

    /**
     * Invalida la cache dei conteggi per tutto lo staff amministrativo.
     * Da invocare ogni volta che un task viene creato, completato, eliminato 
     * o modificato (es. registrazione incassi, emissione rate, rifiuti segnalazioni).
     */
    public static function clearAdminCache(): void
    {
        try {
            // Recuperiamo solo gli ID per massimizzare le performance della query
            $adminIds = User::role([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])->pluck('id');
            
            foreach ($adminIds as $id) {
                Cache::forget("inbox_count_{$id}");
            }
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            // Ignoriamo in ambiente di testing se il seeder dei ruoli non è stato eseguito
        }
    }

    /**
     * Invalida la cache per un singolo utente.
     * Utile per azioni che non impattano la visibilità globale degli altri admin.
     */
    public static function clearUserCache(int $userId): void
    {
        Cache::forget("inbox_count_{$userId}");
    }

    /**
     * Il numero dell'Action inbox nel menu: i compiti aperti con il giorno arrivato (oggi compreso), i bonifici segnalati, ciò che è da moderare e le
     * segnalazioni che chi guarda può aprire ({@see CompitoDellaSegnalazione::chiVede()}, {@see CompitoDaModerare::chiVede()}), con la regola di {@see FasceDeiCompiti}. Lo tiene in memoria `HandleInertiaRequests` per dieci
     * minuti, per utente, e `clearAdminCache()` lo azzera.
     */
    public static function numeroPerIlMenu(bool|int $segnalazioni, ?array $moderare = null): int
    {
        $query = Evento::query()->compitiAperti();
        CompitoDellaSegnalazione::soloQuelliCheVede($query, $segnalazioni, $moderare);
        FasceDeiCompiti::oggi()->filtraPerIlMenu($query, FasceDeiCompiti::sqlTipo());

        return $query->count();
    }
}