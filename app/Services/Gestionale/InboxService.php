<?php

namespace App\Services\Gestionale;

use App\Enums\CategoriaEventoEnum;
use App\Enums\EventoTipo;
use App\Models\User;
use App\Models\Evento;
use App\Enums\Role;
use App\Enums\VisibilityStatus;
use App\Models\CategoriaEvento;
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
     * Gestisce in automatico category_id, visibility, e requires_action.
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
            'action_url' => $actionUrl,
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
     * Esegue il calcolo aggregato dei task pendenti.
     * Centralizza la query SQL complessa che estrae i dati dal JSON 'meta' degli Eventi.
     * * @return array{all: int, urgent: int, payments: int, maintenance: int}
     */
    public static function getCounts(): array
    {
        // Definiamo il limite temporale per i task urgenti (fine giornata odierna)
        $deadline = now()->endOfDay()->toDateTimeString();
        
        $statsQuery = Evento::query()
            // Filtriamo solo eventi che richiedono un'azione esplicita dell'admin
            ->whereJsonContains('meta->requires_action', true)
            // Escludiamo eventi privati (dedicati solo ai condòmini)
            ->where(fn($q) => $q->where('visibility', '!=', 'private')->orWhereNull('visibility'))
            // Consideriamo solo ciò che non è ancora stato evaso
            ->where('is_completed', false);

        // Fix cross-database per SQLite tests vs MySQL
        $isSqlite = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite';
        $typeExtract = $isSqlite 
            ? "JSON_EXTRACT(meta, '$.\"type\"')" 
            : "CONVERT(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.\"type\"')) USING utf8mb4)";

        $stats = $statsQuery->selectRaw("
            COUNT(*) as all_tasks,
            SUM(CASE WHEN start_time <= ? THEN 1 ELSE 0 END) as urgent,
            SUM(CASE WHEN {$typeExtract} = 'verifica_pagamento' THEN 1 ELSE 0 END) as payments,
            SUM(CASE WHEN {$typeExtract} = 'segnalazione_guasto' THEN 1 ELSE 0 END) as maintenance
        ", [$deadline])
            ->first();

        // Cast esplicito a intero per garantire coerenza nel frontend (Inertia/Vue)
        return [
            'all'         => (int) ($stats->all_tasks ?? 0),
            'urgent'      => (int) ($stats->urgent ?? 0),
            'payments'    => (int) ($stats->payments ?? 0),
            'maintenance' => (int) ($stats->maintenance ?? 0),
        ];
    }
}