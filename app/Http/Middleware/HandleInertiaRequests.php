<?php

namespace App\Http\Middleware;

use App\Http\Resources\User\UserResource;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\Evento;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use App\Services\UpdateService;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {

        $updateService = app(UpdateService::class);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'version' => config('app.version'),
            'is_demo' => env('IS_DEMO', false),
            
            // Dati per Vue I18n
            'locale' => app()->getLocale(),

            'auth.user' => fn () => $request->user()
                ? new UserResource($request->user())
                : null,

            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'scoperti_warning' => fn () => $request->session()->get('scoperti_warning'),
            ],

            'csrf_token' => fn () => $request->user() 
                ? csrf_token() 
                : null,

            'back_url' => fn () => $request->method() === 'GET'
                ? url()->previous()
                : null,

            // Aggiungiamo il contatore globale admin inbox (con caching e logica ibrida)
            'inbox_count' => $request->user() ? Cache::remember('inbox_count_' . $request->user()->id, now()->addMinutes(10), function () use ($request) {

                // --- IL FIX FONDAMENTALE ---
                // Se la colonna 'meta' non esiste, non eseguire la query.
                if (!Schema::hasColumn('eventi', 'meta')) {
                    return 0;
                }

                return Evento::query()
                    // 1. Deve richiedere azione
                    ->whereJsonContains('meta->requires_action', true)
                    // 2. NON deve essere completato
                    ->where('is_completed', false)
                    // 3. IL FIX: Logica Ibrida (Tempo O Tipo)
                    ->where(function (Builder $query) {
                        // A. Scadenze temporali (es. rate scadute, lavori in data X)
                        $query->where('start_time', '<=', now())
                        // B. Priorità di TIPO (mostra SEMPRE, anche se la data è futura)
                        ->orWhereJsonContains('meta->type', 'verifica_pagamento') // Segnalazione utente
                        ->orWhereJsonContains('meta->type', 'segnalazione_guasto');     // Segnalazione guasto
                    })
                    // 4. Logica visibilità
                    ->where(fn(Builder $q) => $q->where('visibility', '!=', 'private')->orWhereNull('visibility'))
                    ->count();
            }) : 0,

            // Aggiungiamo stato aggiornamenti sistema
            'system_update' => [
                'available' => $updateService->isAutoUpdateEnabled() 
                    ? $updateService->hasUpdateAvailable() 
                    : false,
                'new_version' => $updateService->getRemoteVersion(),
                'current_version' => config('app.version'),
            ],

        ];
    }

}