<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Middleware;

use App\Http\Resources\User\UserResource;
use App\Support\Allegati\RegoleAllegati;
use App\Support\FunzioniInstallazione;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Services\Gestionale\InboxService;
use Illuminate\Support\Facades\Cache;
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
        return [
            ...parent::share($request),
            'name' => config('app.name'),

            // La versione, lo stato degli aggiornamenti (più giù) e la mappa delle funzioni vanno solo
            // a chi ha fatto l'accesso: le pagine per ospiti (pagina iniziale, login, registrazione,
            // «password dimenticata», reset) non li portano, nemmeno nel sorgente: da queste pagine la
            // versione di un'installazione non si legge senza accesso. Per un ospite `null`; chi li legge
            // (piè di pagina, intestazione, impostazioni, aggiornamento) sta dietro l'accesso (Coda 342,
            // decisione 127.6).
            'version' => fn () => $request->user() ? config('app.version') : null,
            // La demo pubblica dalla configurazione (`kondomanager.demo`): con `artisan optimize` la variabile IS_DEMO letta qui con env() valeva sempre falso (1.12.0-beta.14).
            'is_demo' => (bool) config('kondomanager.demo', false),

            // Coda 493 (decisione 687): l'indirizzo dei termini del servizio, se l'istanza l'ha impostato (vuoto di serie), per la finestra «Note legali» che sta
            // anche nelle pagine per ospiti. Solo un indirizzo web: altro non diventa un collegamento.
            'termini_del_servizio_url' => fn () => preg_match('#^https?://#i', (string) config('kondomanager.termini_del_servizio_url')) ? config('kondomanager.termini_del_servizio_url') : null,

            // Cosa questa installazione sa fare (aggiornamenti in app, pianificatore esterno,
            // backup, archiviazione esterna): una mappa sola, vedi App\Support\FunzioniInstallazione.
            'funzioni' => fn () => $request->user() ? FunzioniInstallazione::mappa() : null,
            
            // Dati per Vue I18n
            'locale' => app()->getLocale(),

            // I valori che il selettore righe-per-pagina può offrire. Condivisi una volta invece
            // che scritti a mano nel componente: erano già divergenti — il menu partiva da 15
            // mentre il valore predefinito era 10, così chi si spostava a 50 non trovava più
            // l'opzione per tornare indietro. Una lista sola, e non può succedere di nuovo.
            'paginazione' => [
                'consentite' => config('pagination.consentite') ?? [],
            ],

            'auth.user' => fn () => $request->user()
                ? new UserResource($request->user())
                : null,

            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'scoperti_warning' => fn () => $request->session()->get('scoperti_warning'),
                // B2, cancello (2): la risoluzione per periodo ha cambiato dei destinatari.
                'destinatari_warning' => fn () => $request->session()->get('destinatari_warning'),
                // …e, se il cancello è scattato rimuovendo una voce dal piano, quale voce: la rimozione riparte da lì.
                'destinatari_warning_detach' => fn () => $request->session()->get('destinatari_warning_detach'),
                // B2, S5: l'avviso verde dopo «Registra passaggio», con le due azioni del §6.4.
                'passaggio_registrato' => fn () => $request->session()->get('passaggio_registrato'),
                // Chiave separata e non accodata a `message`: il banner del flash viene
                // dipinto e subito cancellato dal modale di conferma che gli si sostituisce
                // (verificato a video sulla beta.46). Il suggerimento deve poter arrivare
                // dove l'amministratore guarda davvero, cioè dentro quel modale.
                'suggerimento_crediti' => fn () => $request->session()->get('suggerimento_crediti'),
                // Decisione 34 (1.11.0-beta.42): che cosa ha fatto l'emissione, contato sulle rate andate a giornale. Stessa
                // ragione del suggerimento: lo legge il modale dell'emissione.
                'esito_emissione' => fn () => $request->session()->get('esito_emissione'),
            ],

            'csrf_token' => fn () => $request->user() 
                ? csrf_token() 
                : null,

            'back_url' => fn () => $request->method() === 'GET'
                ? url()->previous()
                : null,

            // Il numero dei compiti dello studio, solo a chi ha il pannello: lo legge solo la dashboard del pannello, e a un condòmino non serve (decide il
            // pannello, non il ruolo: la regola di `RegoleAllegati::haPannello`).
            'inbox_count' => $request->user() && RegoleAllegati::haPannello($request->user()) ? Cache::remember('inbox_count_' . $request->user()->id, now()->addMinutes(10), function () use ($request) {

                // --- IL FIX FONDAMENTALE ---
                // Se la colonna 'meta' non esiste, non eseguire la query.
                // Così anche la tabella delle risposte, che la regola dei compiti aperti legge: prima della migrazione, niente numero.
                if (!Schema::hasColumn('eventi', 'meta') || !Schema::hasTable('risposte_promemoria')) {
                    return 0;
                }

                // I compiti aperti con il giorno arrivato (oggi compreso, nel giorno dell'amministratore), i bonifici segnalati e le segnalazioni che questo
                // utente può aprire: la regola dell'Action inbox, scritta in un posto (`FasceDeiCompiti`, `CompitoDellaSegnalazione::chiVede`).
                return InboxService::numeroPerIlMenu(\App\Services\Gestionale\Inbox\CompitoDellaSegnalazione::chiVede($request->user()), \App\Services\Gestionale\Inbox\CompitoDaModerare::chiVede($request->user()));
            }) : 0,

            'allegati_in_attesa' => fn () => Schema::hasTable('documenti')
                ? RegoleAllegati::conteggioInAttesa($request->user())
                : 0,

            // Stato degli aggiornamenti: solo con l'accesso, come la versione (Coda 342).
            'system_update' => function () use ($request) {
                if (! $request->user()) {
                    return null;
                }

                $updateService = app(UpdateService::class);

                return [
                    'available' => $updateService->isAutoUpdateEnabled()
                        ? $updateService->hasUpdateAvailable()
                        : false,
                    'new_version' => $updateService->getRemoteVersion(),
                    'current_version' => config('app.version'),
                ];
            },

        ];
    }

}