<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Prova differenziale dell'emissione: le stesse rate emesse dalla finestra «Emetti le rate» (con il corpo che la finestra manda, `da=dashboard`, dalla
 * dashboard) e dalla pagina del piano (con il corpo che la pagina manda, dalla pagina del piano) su due condomini uguali danno le stesse scritture, le stesse
 * quote, gli stessi eventi dei condòmini, gli stessi compiti tolti e lo stesso estratto conto di ogni persona, al centesimo. E ogni rifiuto arriva uguale e
 * non scrive niente, in nessuna tabella che l'emissione tocca.
 *
 * E la finestra, aperta, spunta le rate che l'azione «Emetti le rate» conta: il server manda per ogni rata la sua cifra, e una rata dei 30 giorni senza
 * denaro (già versata da tutti) resta nell'elenco ma l'azione non la conta; la finestra non la spunta di serie (`spuntateDiSerie`, provata con vitest).
 */

use App\Enums\EventoTipo;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\EstrattoContoDellaPersona;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';
require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/**
 * Quattro persone; rossi con un debito pregresso di € 150,00, verdi con un credito di € 650,00 (più di una rata: la sua quota della rata 1 va sotto
 * zero), bianchi con € 0,01 di debito; 12 rate dal 05/01/2026 con il pregresso nella prima; il piano approvato con le sue scadenze e i compiti; le rate 1–3
 * già emesse; oggi il 20/05/2026.
 */
function edpCondominio($test, array $emesse = [1, 2, 3]): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 400], 'B' => ['bianchi', 300], 'C' => ['verdi', 200], 'D' => ['neri', 100]], 12, 'prima_rata',
        [['rossi', 'A', 15000], ['verdi', 'C', -65000], ['bianchi', 'B', 1]], 1200001);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $test->user,
        \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));
    if ($emesse !== []) {
        lcdEmetti($test, $s, $emesse, '2026-01-02');
    }
    $test->travelTo('2026-05-20 10:00:00');

    return $s;
}

/** Il corpo che manda la pagina del piano (`PianiRateShow.vue`, `formEmissione`): le rate nell'ordine in cui sono state spuntate. */
function edpDalPiano($test, array $s, array $numeri, array $altro = [])
{
    return $test->actingAs($test->user)
        ->from(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]))
        ->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), array_merge([
            'rate_ids' => array_map(fn ($n) => lcdRata($s, $n), $numeri), 'data_emissione' => '2026-05-20', 'descrizione_personalizzata' => '', 'invia_notifiche' => true,
        ], $altro));
}

/** Il corpo che manda la finestra (`EmissioneRapidaDialog.vue`, `emetti()`): le rate nell'ordine del piano, `da=dashboard`, dalla dashboard. */
function edpDallaFinestra($test, array $s, array $numeri, array $altro = [])
{
    $ordinati = $numeri;
    sort($ordinati);

    return $test->actingAs($test->user)
        ->from(route('admin.gestionale.index', $s['c']))
        ->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), array_merge([
            'rate_ids' => array_map(fn ($n) => lcdRata($s, $n), $ordinati), 'data_emissione' => '2026-05-20', 'descrizione_personalizzata' => '', 'invia_notifiche' => true,
            'da' => 'dashboard',
        ], $altro));
}

/** Toglie da un valore gli id, i codici e le date di sistema: restano le cifre, i nomi, i versi, le note, le date dell'emissione. */
function edpSenzaId(mixed $v): mixed
{
    if (! is_array($v)) {
        return $v;
    }
    $fuori = '/(^id$|_id$|Id$|^ids$|url|href|codice|created_at|updated_at|data_registrazione|numero_protocollo|protocollo)/i';
    $out = [];
    foreach ($v as $k => $x) {
        if (is_string($k) && preg_match($fuori, $k)) {
            continue;
        }
        $out[$k] = edpSenzaId($x);
    }

    return $out;
}

/** Tutto ciò che l'emissione scrive o cambia nel condominio, senza gli id. */
function edpFotografia(array $s): array
{
    $cid = (int) $s['c']->id;
    $nomi = DB::table('anagrafiche')->pluck('nome', 'id');
    $interni = DB::table('immobili')->where('condominio_id', $cid)->pluck('interno', 'id');
    $numeri = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('numero_rata', 'id');
    $ruoli = DB::table('conti_contabili')->where('condominio_id', $cid)->pluck('ruolo', 'id');

    $scritture = DB::table('scritture_contabili')->where('condominio_id', $cid)->orderBy('id')->get()->map(function ($sc) use ($nomi, $interni, $numeri, $ruoli) {
        $righe = DB::table('righe_scritture')->where('scrittura_id', $sc->id)->get()->map(fn ($r) => [
            $ruoli[$r->conto_contabile_id] ?? '?', $nomi[$r->anagrafica_id] ?? null, $interni[$r->immobile_id] ?? null, $numeri[$r->rata_id] ?? null,
            $r->tipo_riga, (int) $r->importo, (string) $r->note,
        ])->sort()->values()->all();

        return [substr((string) $sc->data_competenza, 0, 10), (string) $sc->causale, (string) $sc->tipo_movimento, (string) $sc->stato, $righe];
    })->all();

    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->get()->map(fn ($r) => [(int) $r->numero_rata, (string) $r->stato])->all();
    $quote = DB::table('rate_quote')->whereIn('rata_id', $numeri->keys())->get()->map(fn ($q) => [
        $numeri[$q->rata_id], $nomi[$q->anagrafica_id] ?? null, $interni[$q->immobile_id] ?? null, (int) $q->importo, (int) $q->importo_pagato, (string) $q->stato,
        $q->scrittura_contabile_id !== null,
    ])->sort()->values()->all();

    $eventi = DB::table('eventi')->whereIn('tipo', [EventoTipo::SCADENZA_RATA_CONDOMINO->value, EventoTipo::EMISSIONE_RATA->value, EventoTipo::CONTROLLO_INCASSI->value])
        ->get()->filter(fn ($e) => isset($numeri[(int) (json_decode((string) $e->meta, true)['context']['rata_id'] ?? 0)]))
        ->map(function ($e) use ($numeri, $nomi) {
            $m = json_decode((string) $e->meta, true);

            return [(string) $e->tipo, $numeri[(int) $m['context']['rata_id']], (string) $e->visibility, (bool) $e->is_completed, $m['is_emitted'] ?? null, $m['is_published'] ?? null,
                (string) $e->start_time, $m['requires_action'] ?? null];
        })->sort()->values()->all();

    $estratti = [];
    $esercizio = \App\Models\Esercizio::find($s['e']->id);
    foreach ($s['persone'] as $chi => $persona) {
        $estratti[$chi] = edpSenzaId(json_decode(json_encode(app(EstrattoContoDellaPersona::class)->ledger($s['c'], $esercizio, Anagrafica::find($persona->id))), true));
    }

    return compact('scritture', 'rate', 'quote', 'eventi', 'estratti');
}

it('le stesse rate dalla finestra e dalla pagina del piano: le stesse scritture, quote, eventi ed estratti conto, al centesimo', function () {
    $a = edpCondominio($this);
    $b = edpCondominio($this);
    expect(edpFotografia($a))->toEqual(edpFotografia($b));

    // La pagina del piano: le rate spuntate in un ordine qualunque (6, 4, 5); la finestra le manda nell'ordine del piano.
    $dalPiano = edpDalPiano($this, $a, [6, 4, 5]);
    $this->flushSession();
    $dallaFinestra = edpDallaFinestra($this, $b, [6, 4, 5]);

    $dalPiano->assertSessionHasNoErrors();
    $dallaFinestra->assertSessionHasNoErrors();
    $fa = edpFotografia($a);
    $fb = edpFotografia($b);
    expect($fa['scritture'])->toEqual($fb['scritture'])
        ->and($fa['quote'])->toEqual($fb['quote'])
        ->and($fa['rate'])->toEqual($fb['rate'])
        ->and($fa['eventi'])->toEqual($fb['eventi'])
        ->and($fa['estratti'])->toEqual($fb['estratti']);
    // Ciò che è stato emesso è proprio la richiesta: 4, 5, 6 a giornale con la data del 20/05, i compiti «Emettere rata» delle tre tolti.
    expect(collect($fa['rate'])->filter(fn ($r) => $r[1] === 'emessa')->pluck(0)->values()->all())->toBe([1, 2, 3, 4, 5, 6])
        ->and(collect($fa['scritture'])->filter(fn ($x) => $x[0] === '2026-05-20')->count())->toBe(3)
        ->and(collect($fa['eventi'])->filter(fn ($e) => $e[0] === EventoTipo::EMISSIONE_RATA->value)->pluck(1)->sort()->values()->all())->toBe([7, 8, 9, 10, 11, 12]);
    // Il messaggio e l'esito uguali, ognuno nel suo ritorno.
    expect($dallaFinestra->getSession()->get('message'))->toBe($dalPiano->getSession()->get('message'))
        ->and($dallaFinestra->getSession()->get('esito_emissione'))->toBe($dalPiano->getSession()->get('esito_emissione'));
});

it('una rata di sola quota a credito: la finestra e la pagina del piano scrivono la stessa scrittura con il credito in AVERE', function () {
    // Verdi ha € 650,00 di credito: la sua quota della rata 1 era sotto zero; qui la rata 7 emessa da sola, con la quota di neri a zero per un pagamento anticipato.
    $a = edpCondominio($this);
    $b = edpCondominio($this);
    foreach ([$a, $b] as $s) {
        DB::table('rate_quote')->where('id', lcdQuota($s, 'neri', 7))->update(['importo_pagato' => DB::raw('importo')]);
    }
    edpDalPiano($this, $a, [7])->assertSessionHasNoErrors();
    $this->flushSession();
    edpDallaFinestra($this, $b, [7])->assertSessionHasNoErrors();
    expect(edpFotografia($a))->toEqual(edpFotografia($b));
});

/** Lo stato che fa rifiutare l'emissione, scritto nello stesso modo nei due condomini. */
function edpRifiuta($test, array $s, string $caso): void
{
    switch ($caso) {
        case 'non approvato':
            DB::table('piani_rate')->where('id', $s['piano']->id)->update(['stato' => 'bozza']);
            break;
        case 'addebiti':
            DB::table('piani_rate')->where('id', $s['piano']->id)->update(['natura' => PianoRate::NATURA_ADDEBITI]);
            break;
        case 'delibera':
            DB::table('gestioni')->where('id', $s['g']->id)->update(['tipo' => 'straordinaria']);
            DB::table('piani_rate')->where('id', $s['piano']->id)->update(['data_delibera_assemblea' => '2026-02-12']);
            DB::table('righe_riparto')->insert(['piano_rate_id' => $s['piano']->id, 'tipo' => 'riparto', 'anagrafica_id' => null, 'immobile_id' => null, 'conto_nome' => 'Facciata',
                'conto_radice_nome' => 'Facciata', 'importo' => 100000, 'competenza_dal' => '2026-01-10', 'competenza_al' => '2026-01-10', 'gradino_competenza' => 'delibera',
                'versione_calcolo' => 'prova', 'created_at' => now(), 'updated_at' => now()]);
            break;
        case 'conti mancanti':
            DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'gestione_rate')->delete();
            break;
        case 'passate gestioni mancante':
            // La rata 4 con un riporto (una quota con il dettaglio del calcolo che lo dice), e il conto «Passate gestioni» che non c'è più.
            $q = DB::table('rate_quote')->where('id', lcdQuota($s, 'rossi', 4))->first();
            $regole = json_decode((string) $q->regole_calcolo, true) ?: [];
            $regole['importi'] = ['quota_pura_gestione' => (int) $q->importo, 'saldo_usato' => 5000, 'totale_calcolato' => (int) $q->importo + 5000];
            DB::table('rate_quote')->where('id', $q->id)->update(['importo' => (int) $q->importo + 5000, 'regole_calcolo' => json_encode($regole)]);
            DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->delete();
            break;
        default:
            $elsa = Anagrafica::forceCreate(['nome' => 'Elsa Acquirente', 'email' => "edp-elsa{$s['c']->id}@test.it", 'indirizzo' => 'Via Roma 1',
                'codice_fiscale' => 'LRMACQUIRENTE' . str_pad((string) $s['c']->id, 3, '0', STR_PAD_LEFT)]);
            $elsa->condomini()->syncWithoutDetaching([$s['c']->id]);
            $riga = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['immobili']['A']->id)->where('anagrafica_id', $s['persone']['rossi']->id)->value('id');
            $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['immobili']['A']]), [
                'tipo' => 'vendita', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $elsa->id, 'decorrenza' => '2026-02-01', 'quota' => 100, 'tipologia' => 'proprietario',
                'copia_autentica' => true, 'copia_autentica_il' => '2026-02-01', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true,
                'nota_cancello' => 'Rogito letto, piano da ricalcolare',
            ])->assertSessionHasNoErrors();
    }
}

/** Le righe di ogni tabella che l'emissione può toccare, intere (anche `updated_at`). */
function edpTutto(array $s): array
{
    $cid = (int) $s['c']->id;
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id');

    return [
        'scritture' => DB::table('scritture_contabili')->where('condominio_id', $cid)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'righe' => DB::table('righe_scritture')->whereIn('scrittura_id', DB::table('scritture_contabili')->where('condominio_id', $cid)->pluck('id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'rate' => DB::table('rate')->whereIn('id', $rate)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'quote' => DB::table('rate_quote')->whereIn('rata_id', $rate)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'eventi' => DB::table('eventi')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
}

it('ogni rifiuto arriva uguale dalla finestra e dalla pagina del piano, e non scrive niente in nessuna tabella', function (string $caso) {
    // Il passaggio da seguire: con le rate 1-3 già emesse la vendita del 01/02 conguaglia il piano e passa le bozze a chi compra (non resta niente da
    // seguire); senza rate emesse il piano resta da ricalcolare.
    $emesse = $caso === 'passaggio' ? [] : [1, 2, 3];
    $a = edpCondominio($this, $emesse);
    $b = edpCondominio($this, $emesse);
    edpRifiuta($this, $a, $caso);
    edpRifiuta($this, $b, $caso);
    $primaA = edpTutto($a);
    $primaB = edpTutto($b);

    $this->travel(5)->minutes();
    $dalPiano = edpDalPiano($this, $a, [4, 5]);
    $this->flushSession();
    $dallaFinestra = edpDallaFinestra($this, $b, [4, 5]);

    $frase = $dalPiano->getSession()->get('message');
    expect($frase['type'] ?? null)->toBe('error')
        ->and($dallaFinestra->getSession()->get('message'))->toBe($frase)
        ->and(edpTutto($a))->toEqual($primaA)
        ->and(edpTutto($b))->toEqual($primaB);
    $dallaFinestra->assertRedirect(route('admin.gestionale.index', $b['c']));
})->with(['non approvato', 'addebiti', 'delibera', 'passaggio', 'conti mancanti', 'passate gestioni mancante']);

it('una rata di un altro piano: lo stesso errore dalle due pagine, e niente scritto', function () {
    $a = edpCondominio($this);
    $b = edpCondominio($this);
    $primaA = edpTutto($a);
    $primaB = edpTutto($b);
    // La rata di un altro piano: quella dell'altro condominio (la stessa rata 4 di b per a, e di a per b).
    $altroA = lcdRata($b, 4);
    $altroB = lcdRata($a, 4);
    $this->actingAs($this->user)->from(route('admin.gestionale.esercizi.piani-rate.show', [$a['c'], $a['e'], $a['piano']]))
        ->post(route('admin.gestionale.piani-rate.emetti', [$a['c'], $a['piano']]), ['rate_ids' => [lcdRata($a, 4), $altroA], 'data_emissione' => '2026-05-20',
            'descrizione_personalizzata' => '', 'invia_notifiche' => true])
        ->assertSessionHasErrors(['rate_ids.1' => 'Una delle rate scelte non è di questo piano: scegli di nuovo le rate.']);
    $this->flushSession();
    $this->actingAs($this->user)->from(route('admin.gestionale.index', $b['c']))
        ->post(route('admin.gestionale.piani-rate.emetti', [$b['c'], $b['piano']]), ['rate_ids' => [lcdRata($b, 4), $altroB], 'data_emissione' => '2026-05-20',
            'descrizione_personalizzata' => '', 'invia_notifiche' => true, 'da' => 'dashboard'])
        ->assertRedirect(route('admin.gestionale.index', $b['c']))
        ->assertSessionHasErrors(['rate_ids.1' => 'Una delle rate scelte non è di questo piano: scegli di nuovo le rate.']);
    expect(edpTutto($a))->toEqual($primaA)->and(edpTutto($b))->toEqual($primaB);
});

it('la finestra, aperta, ha le rate dell\'azione «Emetti le rate» con la stessa cifra; una rata dei 30 giorni senza denaro c\'è, con la cifra a zero', function () {
    // `DatiDelleAzioni::rateDaEmettere` salta le rate con la cifra a zero; la finestra le elenca (si possono spuntare a mano) e non le spunta di serie: le
    // spuntate di serie sono quelle fino a `fino` con la cifra sopra zero (`emissioneRapida.ts`, `spuntateDiSerie`).
    $s = edpCondominio($this);
    // La rata 6 (05/06, nei 30 giorni) già versata per intero da tutti prima dell'emissione: non porta denaro.
    DB::table('rate_quote')->where('rata_id', lcdRata($s, 6))->update(['importo_pagato' => DB::raw('importo')]);

    $versione = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $f = $this->actingAs($this->user)->get(route('admin.gestionale.index', $s['c']), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $versione,
        'X-Inertia-Partial-Component' => 'gestionale/dashboard/Dashboard', 'X-Inertia-Partial-Data' => 'emissioneRapida'])->assertOk()->json('props.emissioneRapida');
    $rate = collect($f['piani'][0]['rate']);
    $spuntate = $rate->filter(fn ($r) => $r['scadenza'] <= $f['fino'] && $r['cifra'] > 0);
    $azione = app(\App\Services\Treasury\TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray()['datiDelleAzioni']['rateDaEmettere'][0];

    expect($spuntate->pluck('rataId')->values()->all())->toBe(array_column($azione['rate'], 'rataId'))
        ->and($spuntate->sum('cifra'))->toBe($azione['cifra'])
        ->and($rate->firstWhere('rataId', lcdRata($s, 6))['cifra'] ?? null)->toBe(0);
});
