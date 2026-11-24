<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Errori;

use App\Enums\Permission;
use App\Models\Errori\Errore;
use App\Models\Errori\VoltaDellErrore;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\CodiceDellErrore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Ciò che le pagine d'errore sanno (bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, decisioni 696 (8) e 698). Le pagine sono Blade
 * con lo stile dentro (`resources/views/errors/`): reggono senza Vite, e con il database fermo.
 *
 * - **Chi vede che cosa** ({@see per()}): a tutti il codice; a chi è collegato senza il dettaglio «Avvisa lo studio» (al collaboratore «Avvisa
 *   l'amministratore»), se l'errore è nel registro e l'interruttore {@see avvisoAperto()} è acceso (spento: modo `senza_avviso`, il codice e la frase che
 *   dice di comunicarlo allo studio, Coda 788); a chi ha «Gestisci impostazioni generali» il dettaglio ripulito e «Copia il rapporto»; nella demo
 *   pubblica a nessuno né l'uno né l'altro. Con il database fermo la pagina non prova nemmeno a leggere chi è collegato: è quella del visitatore, e lo dice.
 * - **Le risposte dei moduli** ({@see rispostaPerIl500()}): un 500 in JSON dice «Server Error» con il codice, anche quando il programma ha scritto un testo
 *   con `abort(500, …)`.
 */
final class PaginaDiErrore
{
    /** I messaggi del 403 che non sono scritti per le persone: quelli di Laravel e del pacchetto dei permessi. */
    private const MESSAGGI_DEL_403_DA_NON_MOSTRARE = [
        '', 'This action is unauthorized.', 'Forbidden', 'User does not have the right permissions.', 'User does not have the right roles.',
        'Invalid signature.', 'Unauthorized.',
    ];

    /** @return array<string, mixed> */
    public static function per(?Throwable $e): array
    {
        $codice = $e !== null ? CodiceDellErrore::di($e) : CodiceDellErrore::nuovo();
        $esito = ($e !== null ? RegistroDegliErrori::esito($e) : null) ?? ['salvato' => false, 'motivo' => null, 'volta_id' => null];
        $motivo = $esito['motivo'];
        // Con il database fermo non si legge chi è collegato: la lettura fallirebbe, o farebbe aspettare la pagina.
        $utente = $motivo === RegistroDegliErrori::DATABASE ? null : self::utente();
        $demo = self::eDemo();
        $token = $utente !== null ? rescue(fn () => csrf_token(), null, false) : null;

        $modo = match (true) {
            $demo => 'demo',
            $utente !== null && self::vedeIlDettaglio($utente) => 'dettaglio',
            $utente !== null && $esito['salvato'] && $token !== null => self::avvisoAperto($utente) ? 'avviso' : 'senza_avviso',
            default => 'ospite',
        };
        $collaboratore = $modo === 'avviso' && rescue(fn () => $utente->hasRole('collaboratore'), false, false);

        return [
            'codice' => $codice,
            'quando' => RapportoDellErrore::quando(now(), false),
            'modo' => $modo,
            'motivo' => $motivo,
            'lettura' => request()->isMethod('GET') || request()->isMethod('HEAD'),
            'pagina_iniziale' => self::paginaIniziale($utente),
            'avviso' => $modo !== 'avviso' ? null : [
                'url' => route('errori.avviso'),
                'firma' => self::firma($codice, (int) $utente->id),
                'token' => $token,
                'etichetta' => $collaboratore ? __('errors.avvisa_l_amministratore') : __('errors.avvisa_lo_studio'),
                'sotto' => $collaboratore ? __('errors.avviso_sotto_amministratore') : __('errors.avviso_sotto_studio'),
                'fatto' => $collaboratore ? __('errors.avviso_fatto_titolo_amministratore') : __('errors.avviso_fatto_titolo'),
            ],
            'dettaglio' => $modo === 'dettaglio' && $e !== null ? self::dettaglio($e, $codice, $esito) : null,
        ];
    }

    /** La pagina iniziale di chi è collegato: la dashboard del pannello o quella del portale; per un visitatore l'accesso. */
    public static function paginaIniziale(?User $utente = null, bool $cercaChiECollegato = false): string
    {
        $utente ??= $cercaChiECollegato ? self::utente() : null;

        return (string) rescue(function () use ($utente) {
            if ($utente === null) {
                return route('login');
            }

            return $utente->checkPermissionTo(Permission::ACCESS_ADMIN_PANEL->value) ? route('admin.dashboard') : route('user.dashboard');
        }, url('/'), false);
    }

    /** La demo pubblica, dalla configurazione (`kondomanager.demo`): con la configurazione in cache `env()` qui varrebbe sempre falso. */
    public static function eDemo(): bool
    {
        return (bool) config('kondomanager.demo', false);
    }

    /** La firma dell'avviso: lega il codice a chi ha visto la pagina, senza salvare niente. Un altro utente non la usa. */
    public static function firma(string $codice, int $utente): string
    {
        return hash_hmac('sha256', $codice.'|'.$utente, (string) config('app.key'));
    }

    /**
     * L'interruttore «I condòmini possono avvisare lo studio dalla pagina d'errore» (Coda 788, decisioni 713 e 718): acceso di serie. Spento, vale per i condòmini
     * e i fornitori; il collaboratore dello studio tiene sempre «Avvisa l'amministratore». Se l'impostazione non si legge, resta acceso come di serie.
     */
    public static function avvisoAperto(User $utente): bool
    {
        if (rescue(fn () => $utente->hasRole('collaboratore'), false, false)) {
            return true;
        }

        return (bool) rescue(fn () => app(GeneralSettings::class)->errori_avviso_dalla_pagina, true, false);
    }

    public static function vedeIlDettaglio(?User $utente): bool
    {
        return $utente !== null && (bool) rescue(fn () => $utente->checkPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value), false, false);
    }

    /** Il motivo di un 403 da mostrare: solo una frase scritta dal programma per le persone, mai i messaggi di Laravel. */
    public static function motivoDel403(?Throwable $e): ?string
    {
        $messaggio = trim((string) $e?->getMessage());
        $tradotti = array_map(fn ($m) => $m === '' ? '' : (string) __($m), self::MESSAGGI_DEL_403_DA_NON_MOSTRARE);

        return in_array($messaggio, [...self::MESSAGGI_DEL_403_DA_NON_MOSTRARE, ...$tradotti], true) ? null : $messaggio;
    }

    /**
     * Il carattere del programma, Instrument Sans, dai file compilati (il manifest di Vite), se ci sono; altrimenti la pagina usa quello del sistema.
     *
     * @return array<int, string> peso => indirizzo
     */
    public static function carattere(): array
    {
        return rescue(function () {
            $manifest = public_path('build/manifest.json');
            if (! is_file($manifest)) {
                return [];
            }
            $voci = json_decode((string) file_get_contents($manifest), true) ?: [];
            $pesi = [];
            foreach ([400, 500, 600] as $peso) {
                $file = $voci["node_modules/@fontsource/instrument-sans/files/instrument-sans-latin-{$peso}-normal.woff2"]['file'] ?? null;
                if (is_string($file) && $file !== '') {
                    $pesi[$peso] = asset('build/'.$file);
                }
            }

            return $pesi;
        }, [], false);
    }

    /**
     * Le risposte dei 500 (dal gestore delle eccezioni, prima di Laravel): il 500 scritto a mano va nel registro, che Laravel salta; in JSON la risposta
     * dice «Server Error» con il codice, mai il testo. Null: la pagina la disegna Laravel, con `errors/500.blade.php`.
     */
    public static function rispostaPerIl500(Throwable $e, Request $richiesta): ?JsonResponse
    {
        if (config('app.debug')) {
            return null;
        }
        $http = $e instanceof HttpExceptionInterface;
        if ($http && $e->getStatusCode() !== 500) {
            return null;
        }
        if ($e instanceof HttpResponseException || $e instanceof AuthenticationException || $e instanceof ValidationException || $e instanceof AuthorizationException) {
            return null;
        }
        if ($http) {
            RegistroDegliErrori::registraAMano($e);
        }

        return $richiesta->expectsJson() ? new JsonResponse(['message' => 'Server Error', 'codice' => CodiceDellErrore::di($e)], 500) : null;
    }

    /** @param array{salvato: bool, motivo: ?string, volta_id: ?int} $esito */
    private static function dettaglio(Throwable $e, string $codice, array $esito): array
    {
        $volta = $esito['volta_id'] !== null ? VoltaDellErrore::query()->with('errore')->find($esito['volta_id']) : null;
        $volta ??= self::voltaAlVolo($e, $codice);
        $traccia = RapportoDellErrore::righeDellaTraccia((array) ($volta->traccia ?? []));

        return [
            'messaggio' => (string) $volta->messaggio,
            'pagina' => $volta->pagina !== null ? trim(($volta->metodo ?? '').' '.$volta->pagina) : ($volta->azione ?? '—'),
            'nel_programma' => $volta->file !== null ? __('errors.riga', ['file' => $volta->file, 'riga' => (string) $volta->riga]) : null,
            'versione' => $volta->versione.' · PHP '.$volta->versione_php,
            'traccia' => implode("\n", $traccia),
            'righe' => count((array) ($volta->traccia ?? [])),
            'rapporto' => RapportoDellErrore::testo($volta),
            'scheda' => $esito['salvato'] ? (string) rescue(fn () => route('logs.index', ['scheda' => 'errori', 'cerca' => $codice]), '', false) : null,
        ];
    }

    /** La volta che non si è potuta salvare, costruita come la si sarebbe salvata: per il dettaglio e il rapporto della pagina. */
    private static function voltaAlVolo(Throwable $e, string $codice): VoltaDellErrore
    {
        $dati = RegistroDegliErrori::datiDellaVolta($e, $codice);
        $volta = new VoltaDellErrore();
        $volta->forceFill(collect($dati)->except('impronta')->all());
        $volta->setRelation('errore', (new Errore())->forceFill([
            'impronta' => $dati['impronta'], 'volte' => 1, 'prima_volta_il' => $dati['avvenuto_il'], 'ultima_volta_il' => $dati['avvenuto_il'],
        ]));

        return $volta;
    }

    private static function utente(): ?User
    {
        $utente = rescue(fn () => auth()->user(), null, false);

        return $utente instanceof User ? $utente : null;
    }
}
