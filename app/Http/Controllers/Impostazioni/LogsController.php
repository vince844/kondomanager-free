<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LogsIndexRequest;
use App\Models\Errori\Errore;
use App\Models\Errori\VoltaDellErrore;
use App\Models\MailLog;
use App\Models\User;
use App\Support\CodiceDellErrore;
use App\Support\Errori\RapportoDellErrore;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La pagina «Registri» delle impostazioni (fino alla 1.12.0-beta.13 «Audit & Logs»), per chi ha «Gestisci impostazioni generali» (la guardia della rotta):
 * la scheda «Email», il registro delle email inviate, e dalla 1.12.0-beta.14 la scheda «Errori» (decisione 698 (2); bozza approvata
 * docs/design/beta14_pagine_errore_grafica_bozza.html, scene 7–9).
 *
 * La scheda si sceglie nell'indirizzo (`?scheda=errori`): la pagina la cambia con una visita parziale, e chiede solo le sue props (`errori`,
 * `erroriFiltri`, `dettaglio`, `codiceNonTrovato`), calcolate solo quando la scheda è quella. «Errori» elenca un errore per riga (gli uguali si contano
 * insieme), con i filtri «Codice o testo», «Stato», «Dal» e «Al». Il codice intero nella ricerca apre l'errore (la finestra del dettaglio, con quella volta
 * per prima); un codice che non c'è lo dice (`codiceNonTrovato`). Aprire un errore «nuovo» lo segna «visto»; uno «avvisato» resta tale.
 */
class LogsController extends Controller
{
    /** Le volte che la finestra del dettaglio mostra. */
    private const VOLTE_NEL_DETTAGLIO = 10;

    public function index(LogsIndexRequest $request)
    {
        $search = $request->validated('search');
        $status = $request->validated('status');
        $mailer = $request->validated('mailer');
        $dateFrom = $request->validated('date_from');
        $dateTo = $request->validated('date_to');
        $perPage = (int) $request->validated('per_page', 10);
        if (! in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 10;
        }
        $scheda = $request->validated('scheda') === 'errori' ? 'errori' : 'email';

        $props = [
            'filters' => [
                'search' => is_string($search) ? $search : '',
                'status' => $status ?? 'tutti',
                'mailer' => $mailer ?? 'tutti',
                'date_from' => is_string($dateFrom) ? $dateFrom : '',
                'date_to' => is_string($dateTo) ? $dateTo : '',
                'per_page' => $perPage,
            ],
            'availableMailers' => fn () => MailLog::query()
                ->distinct()
                ->whereNotNull('mailer')
                ->pluck('mailer')
                ->filter()
                ->values(),
            // TAB 1: Mail Logs ('mail_page' è la chiave per non confondere la paginazione con l'altra scheda)
            'mailLogs' => fn () => MailLog::query()
                ->when($search, function ($query, $search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('recipient', 'like', "%{$search}%")
                          ->orWhere('subject', 'like', "%{$search}%");
                    });
                })
                ->when($status && $status !== 'tutti', function ($query) use ($status) {
                    $query->where('status', $status);
                })
                ->when($mailer && $mailer !== 'tutti', function ($query) use ($mailer) {
                    $query->where('mailer', $mailer);
                })
                ->when($dateFrom, function ($query, $from) {
                    try {
                        $query->whereDate('sent_at', '>=', Carbon::parse($from)->startOfDay());
                    } catch (\Throwable) {}
                })
                ->when($dateTo, function ($query, $to) {
                    try {
                        $query->whereDate('sent_at', '<=', Carbon::parse($to)->endOfDay());
                    } catch (\Throwable) {}
                })
                ->latest('sent_at')
                ->paginate($perPage, ['*'], 'mail_page')
                ->withQueryString(),
            'scheda' => $scheda,

            // TAB 2: Activity Logs (Spatie). La scheda «Attività Sistema» non c'è finché la query qui sotto non ha dati da mostrare: non si manda una
            // proprietà vuota, che la pagina leggerebbe come un elenco. Quando la query torna, torna con la scheda in `impostazioniLogs.vue`.
           /*  'activityLogs' => Activity::query()
                ->with('causer') // Carica l'utente che ha fatto l'azione
                ->when($search, function ($query, $search) {
                    $query->where('description', 'like', "%{$search}%")
                          ->orWhereHas('causer', function ($q) use ($search) {
                              $q->where('name', 'like', "%{$search}%"); // Assumendo che User abbia 'name'
                          });
                })
                ->latest()
                ->paginate(10, ['*'], 'activity_page')
                ->withQueryString(), */
        ];

        if ($scheda === 'errori') {
            $props = [...$props, ...$this->schedaErrori($request)];
        }
        // Il numero sul cartellino della scheda «Errori»: gli errori che nessuno ha ancora aperto. Dopo il dettaglio, che segna «visto» quello che apre.
        $props['erroriNuovi'] = fn () => Errore::query()->where('stato', Errore::NUOVO)->count();

        return Inertia::render('impostazioni/impostazioniLogs', $props);
    }

    /** «Scarica il rapporto»: il testo del rapporto di una volta, in un file con il codice nel nome. */
    public function rapporto(VoltaDellErrore $volta): StreamedResponse
    {
        $testo = RapportoDellErrore::testo($volta->load('errore'));

        return response()->streamDownload(fn () => print($testo), RapportoDellErrore::nomeDelFile($volta), ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function schedaErrori(LogsIndexRequest $request): array
    {
        $cerca = trim((string) $request->validated('cerca'));
        $codice = CodiceDellErrore::daTesto($cerca);
        $stato = (string) ($request->validated('stato_errori') ?: 'tutti');
        $dal = $request->validated('errori_dal');
        $al = $request->validated('errori_al');
        $perPage = (int) ($request->validated('errori_per_page') ?: 10);

        // Il codice intero apre l'errore, con quella volta; l'id (dal pulsante «Apri») apre l'errore con la sua ultima volta. Il dettaglio, l'elenco e il
        // codice che non c'è si calcolano solo quando la pagina li chiede: un filtro chiede l'elenco, «Apri» il dettaglio.
        $voltaCercata = $codice !== null ? VoltaDellErrore::query()->where('codice', $codice)->latest('id')->first() : null;
        $idErrore = $request->validated('errore') ?? $voltaCercata?->errore_id;

        return [
            'errori' => fn () => $this->elenco($cerca, $codice, $stato, $dal, $al, $perPage, $voltaCercata),
            'erroriFiltri' => [
                'cerca' => $cerca,
                'stato' => $stato,
                'dal' => is_string($dal) ? $dal : '',
                'al' => is_string($al) ? $al : '',
                'per_page' => $perPage,
            ],
            'dettaglio' => function () use ($idErrore, $voltaCercata) {
                $errore = $idErrore !== null ? Errore::query()->find((int) $idErrore) : null;

                return $errore !== null ? $this->dettaglio($errore, $voltaCercata) : null;
            },
            'codiceNonTrovato' => fn () => $codice !== null && $voltaCercata === null ? $codice : null,
        ];
    }

    private function elenco(string $cerca, ?string $codice, string $stato, mixed $dal, mixed $al, int $perPage, ?VoltaDellErrore $voltaCercata): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $elenco = Errore::query()
            ->select('errori.*')
            ->addSelect(['ultima_volta_id' => VoltaDellErrore::query()->select('id')->whereColumn('errore_id', 'errori.id')
                ->orderByDesc('avvenuto_il')->orderByDesc('id')->limit(1)])
            ->addSelect(['ultimo_avviso_id' => VoltaDellErrore::query()->select('id')->whereColumn('errore_id', 'errori.id')
                ->whereNotNull('avvisato_il')->orderByDesc('avvisato_il')->limit(1)])
            ->when($cerca !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('errori.id', VoltaDellErrore::query()->select('errore_id')->where('codice', $codice ?? Str::upper($cerca)))
                ->orWhere('errori.messaggio', 'like', '%'.$cerca.'%')
                ->orWhere('errori.classe', 'like', '%'.$cerca.'%')))
            ->when($stato !== 'tutti', fn ($q) => $q->where('errori.stato', $stato))
            ->when($dal, function ($q, $dal) {
                try {
                    $q->where('errori.ultima_volta_il', '>=', Carbon::parse($dal)->startOfDay());
                } catch (\Throwable) {}
            })
            ->when($al, function ($q, $al) {
                try {
                    $q->where('errori.ultima_volta_il', '<=', Carbon::parse($al)->endOfDay());
                } catch (\Throwable) {}
            })
            ->orderByDesc('errori.ultima_volta_il')
            ->orderByDesc('errori.id')
            ->paginate(in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10, ['*'], 'errori_page')
            ->withQueryString();

        $volte = VoltaDellErrore::query()->whereIn('id', collect($elenco->items())->flatMap(fn ($e) => [$e->ultima_volta_id, $e->ultimo_avviso_id])->filter()->unique())
            ->with('avvisatoDa:id,name')->get()->keyBy('id');
        $elenco->through(fn (Errore $e) => $this->riga($e, $volte->get($e->ultima_volta_id), $volte->get($e->ultimo_avviso_id), $voltaCercata));

        return $elenco;
    }

    /** @return array<string, mixed> */
    private function riga(Errore $errore, ?VoltaDellErrore $ultima, ?VoltaDellErrore $avviso, ?VoltaDellErrore $cercata): array
    {
        $volta = $cercata !== null && $cercata->errore_id === $errore->id ? $cercata : $ultima;

        return [
            'id' => $errore->id,
            'stato' => $errore->stato,
            'classe' => class_basename($errore->classe),
            'messaggio' => Str::limit((string) $errore->messaggio, 300),
            'codice' => $volta?->codice,
            'metodo' => $volta?->metodo,
            'pagina' => $volta?->pagina,
            'azione' => $volta?->azione,
            'volte' => (int) $errore->volte,
            'ultima_volta_il' => $errore->ultima_volta_il?->toIso8601String(),
            'avvisato_da' => $avviso !== null ? ($avviso->avvisatoDa?->name ?? __('Utente eliminato')) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function dettaglio(Errore $errore, ?VoltaDellErrore $cercata): array
    {
        if ($errore->stato === Errore::NUOVO) {
            $errore->forceFill(['stato' => Errore::VISTO])->save();
        }
        $cercata = $cercata !== null && $cercata->errore_id === $errore->id ? $cercata : null;
        $ultime = $errore->volteRegistrate()->orderByDesc('avvenuto_il')->orderByDesc('id')
            ->when($cercata, fn ($q) => $q->whereKeyNot($cercata->id))
            ->limit($cercata !== null ? self::VOLTE_NEL_DETTAGLIO - 1 : self::VOLTE_NEL_DETTAGLIO)->get();
        $volte = $cercata !== null ? collect([$cercata])->concat($ultime) : $ultime;
        $scelta = $volte->first();
        $scelta?->setRelation('errore', $errore);
        $avvisi = $errore->volteRegistrate()->whereNotNull('avvisato_il')->with('avvisatoDa.anagrafica.condomini')
            ->orderByDesc('avvisato_il')->limit(self::VOLTE_NEL_DETTAGLIO)->get();

        return [
            'id' => $errore->id,
            'stato' => $errore->stato,
            'classe' => $errore->classe,
            'messaggio' => (string) $errore->messaggio,
            'volte_in_tutto' => (int) $errore->volte,
            'prima_volta' => RapportoDellErrore::quando($errore->prima_volta_il, false),
            'ultima_volta' => RapportoDellErrore::quando($errore->ultima_volta_il, false),
            'impronta' => $errore->improntaBreve(),
            'volta' => $scelta !== null ? $this->volta($scelta) : null,
            'volte' => $volte->map(fn (VoltaDellErrore $v) => [
                'id' => $v->id,
                'codice' => $v->codice,
                'quando' => RapportoDellErrore::quando($v->avvenuto_il, false),
                'chi' => RapportoDellErrore::chiPerLaScheda($v->ruolo, $v->metodo !== null),
                'avvisato' => $v->avvisato_il !== null,
            ])->values()->all(),
            'avvisi' => $avvisi->map(fn (VoltaDellErrore $v) => [
                'codice' => $v->codice,
                'nome' => $v->avvisatoDa?->name ?? __('Utente eliminato'),
                'chi' => RapportoDellErrore::chiPerLaScheda($v->avvisato_ruolo, true),
                'condominio' => $this->condominioDi($v->avvisatoDa),
                'quando' => RapportoDellErrore::quando($v->avvisato_il, false),
                'nota' => $v->nota_avviso,
            ])->values()->all(),
            'traccia' => $scelta !== null ? RapportoDellErrore::righeDellaTraccia((array) ($scelta->traccia ?? [])) : [],
            'rapporto' => $scelta !== null ? RapportoDellErrore::testo($scelta) : '',
            'rapporto_url' => $scelta !== null ? route('logs.errori.rapporto', $scelta) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function volta(VoltaDellErrore $v): array
    {
        return [
            'id' => $v->id,
            'codice' => $v->codice,
            'quando' => RapportoDellErrore::quando($v->avvenuto_il, false),
            'metodo' => $v->metodo,
            'pagina' => $v->pagina,
            'azione' => $v->azione,
            'file' => $v->file,
            'riga' => $v->riga,
            'causa' => $v->causa,
            'programma' => 'KondoManager '.$v->versione.' · PHP '.$v->versione_php.' · Laravel '.$v->versione_laravel.($v->database ? ' · '.$v->database : ''),
            'chi' => RapportoDellErrore::chiPerLaScheda($v->ruolo, $v->metodo !== null),
        ];
    }

    private function condominioDi(?User $utente): ?string
    {
        return rescue(fn () => $utente?->anagrafica?->condomini?->first()?->nome, null, false);
    }
}
