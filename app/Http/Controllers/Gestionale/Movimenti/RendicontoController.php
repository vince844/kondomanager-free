<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\AnnoACavallo;
use App\Services\Gestionale\ConfrontoDelRendiconto;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\Gestionale\PisteDellaBanca;
use App\Services\Gestionale\SintesiDelRendiconto;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use App\Services\Gestionale\VersioniDelRendiconto;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\PaginaElenco;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le schede del rendiconto dell'esercizio che non hanno una pagina loro da prima: la Sintesi, la Cassa e i Controlli, una richiesta ciascuna (le
 * letture di una scheda non si sommano a quelle delle altre); e il fascicolo per l'assemblea, un PDF solo. Il riepilogo finanziario, la situazione
 * patrimoniale e i condòmini hanno i loro controller.
 *
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello, mai fra quelle del portale: il fascicolo e i controlli portano i dati di tutti i
 * condòmini. L'esercizio di un altro condominio fa 404 per tutta la richiesta: il gruppo lo cerca dentro il condominio (`scopeBindings()`), e la guardia
 * in ogni metodo resta come in tutte le rotte annidate.
 */
class RendicontoController extends Controller
{
    use HandleFlashMessages, HasCondomini, PaginaElenco;

    /** Le proprietà comuni delle schede: il condominio, l'esercizio, gli elenchi del selettore. */
    private function comuni(Condominio $condominio, Esercizio $esercizio): array
    {
        return [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $esercizio,
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            // Lo stato del rendiconto in testa a ogni scheda: la bozza, presentato all'assemblea, approvato.
            'stato_rendiconto' => VersioniDelRendiconto::statoInTesta($esercizio),
        ];
    }

    public function sintesi(Request $request, Condominio $condominio, Esercizio $esercizio, SintesiDelRendiconto $sintesi, ControlliDelRendiconto $controlli,
        ConguaglioDelRendiconto $conguaglio, ConfrontoDelRendiconto $confronto): Response
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        // Una lettura del conguaglio sotto la soglia di peso, e i controlli una volta: per la Sintesi e per il confronto con le versioni fermate.
        $peso = $conguaglio->peso($esercizio);
        $letto = $peso <= ConguaglioDelRendiconto::soglia() ? $conguaglio->leggi($esercizio) : null;
        $c = $controlli->perEsercizio($condominio, $esercizio, null, [], null, null, [], $letto);
        $letta = $sintesi->perEsercizio($condominio, $esercizio, null, $c, null, $peso);
        $nota = NotaSinteticaDelRendiconto::contenuto($esercizio);

        return Inertia::render('gestionale/movimenti/rendiconto/Sintesi', $this->comuni($condominio, $esercizio) + [
            'sintesi' => $letta,
            // Che cosa manca alla nota sintetica, per le finestre del fascicolo e di «Ferma per l'assemblea»: si dice, e non si ferma niente.
            // I conti della banca della stessa lettura dei controlli: la differenza di ognuno si spiega sotto il suo saldo.
            'nota' => ['vuota' => NotaSinteticaDelRendiconto::bozzaVuota($nota), 'mancanze' => NotaSinteticaDelRendiconto::mancanze($nota, $letta['esito']['righe'] ?? [], $c['banche'] ?? null)],
            'parti' => FascicoloDelRendiconto::PARTI,
            // Le versioni fermate per l'assemblea, senza le cifre, e i nomi delle parti per «Conserva le parti una per una».
            'versioni' => VersioniDelRendiconto::elenco($esercizio),
            'nomi_parti' => FascicoloDelRendiconto::NOMI,
            'oggi' => \App\Helpers\DateHelper::oggiUtente(),
            // Dopo l'approvazione, che cosa è cambiato con le cifre di allora e di oggi; e per la finestra dell'approvazione, dal giorno della versione
            // fermata. Sotto la soglia di peso si fa da sé; sopra, con «Confronta con la versione approvata» (`confronta=1`).
            'confronto' => $confronto->perLaSintesi($condominio, $esercizio, $letto, $request->boolean('confronta'), $c),
        ]);
    }

    public function cassa(Condominio $condominio, Esercizio $esercizio, StatoPatrimonialePaginaService $pagina): Response
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $p = $pagina->costruisci($condominio, $esercizio);

        return Inertia::render('gestionale/movimenti/rendiconto/Cassa', $this->comuni($condominio, $esercizio) + [
            'cassa' => ['data' => $p['data'], 'stato_esercizio' => $p['stato_esercizio'], 'riepilogo' => $p['riepilogo'], 'raccordo' => $p['raccordo'],
                'risultato_gestione' => $p['risultato_gestione']],
        ]);
    }

    public function controlli(Request $request, Condominio $condominio, Esercizio $esercizio, ControlliDelRendiconto $controlli, ConguaglioDelRendiconto $conguaglio,
        PisteDellaBanca $piste): Response
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $filtri = ['verso' => $request->query('verso'), 'tipo' => $request->query('tipo'), 'colore' => $request->query('colore')];
        // L'elenco delle registrazioni oltre il confine a pagine, con le righe per pagina ricordate come nelle altre tabelle.
        $pagine = ['pagina' => LengthAwarePaginator::resolveCurrentPage('pagina_confine'), 'per_pagina' => $this->righePerPagina($request, 'anno_a_cavallo', AnnoACavallo::RIGHE_ELENCO)];
        // I quattro controlli del conguaglio leggono il consuntivo per persona: qui la lettura si fa sempre, come nella scheda «Conguaglio»; non quando la
        // pagina chiede solo l'elenco delle registrazioni oltre il confine (i filtri e le pagine), che non la usa.
        $soloElenco = $request->header('X-Inertia-Partial-Data') === 'anno_a_cavallo';
        $c = $controlli->perEsercizio($condominio, $esercizio, null, array_map(fn ($v) => is_string($v) ? $v : null, $filtri), null, null, $pagine,
            $soloElenco ? null : $conguaglio->leggi($esercizio));

        // Il controllo della banca con i suoi conti, e per ogni conto con una differenza le possibili cause con la correzione consigliata e quelle ignorate.
        $perCassa = $soloElenco ? [] : $piste->perIConti($condominio, $esercizio, $c['banche']);
        $c['controlli'] = array_map(fn (array $x) => $x['id'] !== 'BC' ? $x : $x + ['banche' => array_map(fn (array $b) => $b + ['piste' => $perCassa[$b['cassa_id']] ?? []],
            $c['banche'])], $c['controlli']);

        return Inertia::render('gestionale/movimenti/rendiconto/Controlli', $this->comuni($condominio, $esercizio) + [
            'controlli' => $c['controlli'],
            'esito' => $c['esito'],
            'anno_a_cavallo' => $c['anno_a_cavallo'],
        ]);
    }

    /**
     * Il fascicolo per l'assemblea: le parti scelte (`parti[]`), sempre nell'ordine fisso, con il frontespizio. Prima di cominciare stima il tempo e la
     * memoria della stampa intera: oltre il tetto non parte, e torna alla Sintesi con la frase che dice quale parte togliere (ognuna si scarica da sola
     * dalla sua scheda). Una parte vuota si salta e l'indice lo dice. La colonna dell'ultima lettera di sollecito nei crediti c'è solo con `lettere`.
     */
    public function fascicolo(Request $request, Condominio $condominio, Esercizio $esercizio, FascicoloDelRendiconto $fascicolo)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $parti = array_values(array_filter((array) $request->query('parti', FascicoloDelRendiconto::PARTI), 'is_string'));
        $esito = $fascicolo->genera($condominio, $esercizio, $parti, $request->boolean('dettaglio'), null, $request->boolean('lettere'));
        if (isset($esito['troppo'])) {
            return redirect()->route('admin.gestionale.esercizi.rendiconto.sintesi', [$condominio->id, $esercizio->id])->with($this->flashError($esito['troppo']));
        }

        return response($esito['pdf'])
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$esito['nome'].'"');
    }
}
