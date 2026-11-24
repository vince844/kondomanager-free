<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Errori\Errore;
use App\Models\Errori\VoltaDellErrore;
use App\Models\User;
use App\Notifications\Errori\ErroreAvvisatoNotification;
use App\Services\Gestionale\Inbox\CompitoDellErrore;
use App\Support\CodiceDellErrore;
use App\Support\Errori\PaginaDiErrore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Avvisa lo studio» dalla pagina d'errore (decisioni 698 (4) e 701; bozza docs/design/beta14_pagine_errore_grafica_bozza.html, scene 1, 4 e 11).
 *
 * Chi è collegato e non vede il dettaglio (il condòmino, il fornitore, il collaboratore) manda allo studio il codice della pagina, con una nota facoltativa:
 * la volta si segna avvisata (chi, quando, con quale ruolo, la nota), l'errore diventa «avvisato», nasce o cresce il compito «Errori avvisati» della Action
 * inbox ({@see CompitoDellErrore}), e parte l'email a chi ha «Gestisci impostazioni generali» e ha acceso «Errore avvisato» nelle sue preferenze
 * ({@see ErroreAvvisatoNotification}: niente dettaglio tecnico né nota). **Un'email per errore** (Coda 788, decisione 713): parte solo quando l'avviso fa
 * nascere il compito; gli avvisi dopo, a compito aperto, ne aumentano il conteggio senza un'altra email; chiuso il compito, il primo avviso dopo la rimanda.
 * L'interruttore «I condòmini possono avvisare lo studio dalla pagina d'errore» ({@see PaginaDiErrore::avvisoAperto()}), spento, fa rifiutare la rotta
 * a condòmini e fornitori; il collaboratore dello studio la tiene sempre.
 *
 * - La firma della pagina ({@see PaginaDiErrore::firma()}) lega il codice a chi l'ha vista: un altro utente non la usa.
 * - Un avviso per codice; cinque all'ora per persona (il limite della rotta, `routes/settings.php`). La demo pubblica non ne manda.
 * - Risponde in JSON alla pagina d'errore; senza JavaScript con la pagina `errors/avviso`.
 */
class AvvisoDellErroreController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $dati = $request->validate([
            'codice' => ['required', 'string', 'max:20'],
            'firma' => ['required', 'string', 'max:128'],
            'nota' => ['nullable', 'string', 'max:300'],
        ]);
        /** @var User $utente */
        $utente = $request->user();
        $codice = CodiceDellErrore::daTesto($dati['codice']);

        if (PaginaDiErrore::eDemo()) {
            return $this->risposta($request, 403, __('errors.avviso_non_nella_demo'), false);
        }
        // Il controllo di chi può: la firma della pagina d'errore, che lega il codice a chi l'ha vista. Senza, 403.
        Gate::denyIf($codice === null || ! hash_equals(PaginaDiErrore::firma($codice, (int) $utente->id), (string) $dati['firma']));
        if (PaginaDiErrore::vedeIlDettaglio($utente)) {
            return $this->risposta($request, 422, __('errors.avviso_vedi_gia'), false);
        }
        // Una pagina rimasta aperta da prima dello spegnimento (Coda 788): il registro ha già l'errore, lo studio non riceve l'avviso.
        if (! PaginaDiErrore::avvisoAperto($utente)) {
            return $this->risposta($request, 403, __('errors.avviso_non_attivo'), false);
        }
        $volta = VoltaDellErrore::query()->with('errore')->where('codice', $codice)->latest('id')->first();
        if ($volta === null) {
            return $this->risposta($request, 404, __('errors.avviso_non_trovato'), false);
        }
        if ($volta->avvisato_il !== null) {
            return $this->risposta($request, 409, __('errors.avviso_gia'), false);
        }

        $nota = trim((string) ($dati['nota'] ?? ''));
        $compito = new CompitoDellErrore();
        DB::transaction(function () use ($volta, $utente, $nota, $compito) {
            $volta->forceFill([
                'avvisato_da' => $utente->id,
                'avvisato_il' => now(),
                'avvisato_ruolo' => rescue(fn () => $utente->getRoleNames()->first(), null, false),
                'nota_avviso' => $nota === '' ? null : $nota,
            ])->save();
            $volta->errore->forceFill(['stato' => Errore::AVVISATO])->save();
            $compito->avvisato($volta, $utente);
        });
        // Un'email per errore (Coda 788, decisione 713): parte solo se questo avviso ha fatto nascere il compito.
        if ($compito->compitoNato) {
            $this->mandaLeEmail($volta, $utente);
        }

        $collaboratore = rescue(fn () => $utente->hasRole('collaboratore'), false, false);
        $testo = __($nota === '' ? 'errors.avviso_fatto' : 'errors.avviso_fatto_con_nota', ['codice' => $codice]);

        return $this->risposta($request, 200, $testo, true,
            $collaboratore ? __('errors.avviso_fatto_titolo_amministratore') : __('errors.avviso_fatto_titolo'));
    }

    /**
     * L'email «Errore avvisato» (decisione 701): solo a chi ha «Gestisci impostazioni generali», non è sospeso e ha acceso la voce nelle sue preferenze (spenta
     * di serie). Chi avvisa non la riceve. Un errore d'invio non ferma l'avviso: il compito c'è già.
     */
    private function mandaLeEmail(VoltaDellErrore $volta, User $chi): void
    {
        try {
            $accesi = DB::table('notification_preferences')
                ->where('type', ErroreAvvisatoNotification::TIPO)
                ->where('enabled', true)
                ->where('user_id', '!=', $chi->id)
                ->pluck('user_id');
            $destinatari = User::query()->whereIn('id', $accesi)->whereNull('suspended_at')->get()
                ->filter(fn (User $u) => PaginaDiErrore::vedeIlDettaglio($u));
            if ($destinatari->isNotEmpty()) {
                Notification::send($destinatari, new ErroreAvvisatoNotification($volta, (string) $chi->name, $chi->getRoleNames()->first()));
            }
        } catch (\Throwable $e) {
            Log::error('Email dell\'avviso dell\'errore '.$volta->codice.' non partita: '.$e->getMessage());
        }
    }

    private function risposta(Request $request, int $stato, string $messaggio, bool $riuscito, ?string $titolo = null): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['messaggio' => $messaggio, 'titolo' => $titolo], $stato);
        }

        return response()->view('errors.avviso', [
            'titolo' => $titolo ?? __('errors.avviso_non_riuscito'),
            'messaggio' => $messaggio,
            'riuscito' => $riuscito,
            'paginaIniziale' => PaginaDiErrore::paginaIniziale($request->user()),
        ], $stato);
    }
}
