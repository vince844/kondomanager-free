<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli aiuti delle prove degli interessi sul debito ereditato (decisioni 489, 493, 494): il caso di Ettore Fabbri. Via Verdi (`AccessoriScenario.php`) con
 * Neri rinominato Ettore Fabbri, la rata 1 di € 300,00 spostata al 30/04/2026 e non pagata, una regola del recupero con un tasso scritto del 2 % l'anno dal
 * 01/01/2026, senza giorni di decorrenza (gli interessi corrono dal giorno dopo la scadenza); Ettore muore il 17/11/2026 e la successione di U1, registrata
 * il 20/11/2026, passa a Elena e Marco al 50 %. Il piano nuovo che assorbe l'arretrato è della stessa gestione, con la sua voce, i saldi «nella prima rata»
 * (o «su tutte le rate»), la prima scadenza il 31/03/2027, emesso il 15/01/2027 (l'esercizio 2026 della prova arriva al 31/12/2027 per poterlo emettere).
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RegolaRecupero;
use App\Models\Gestionale\Subentro;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccessoriScenario.php';

/** Via Verdi con Ettore Fabbri (Neri) e la sua rata 1 di € 300,00 scaduta il 30/04/2026, non pagata; il tasso scritto del 2 % dal 01/01/2026. */
function ideCaso($test, int $decorrenza = 0): array
{
    $s = accViaVerdi($test);
    $s['persone']['Neri']->forceFill(['nome' => 'Ettore Fabbri'])->save();
    $rata = lcdRata($s, 1);
    DB::table('rate')->where('id', $rata)->update(['data_scadenza' => '2026-04-30']);
    DB::table('rate_quote')->where('rata_id', $rata)->update(['data_scadenza' => '2026-04-30']);
    RegolaRecupero::create(['condominio_id' => $s['c']->id, 'dal' => '2026-01-01', 'tasso_tipo' => RegolaRecupero::TASSO_SCRITTO, 'tasso_annuo' => '2.0000',
        'fonte' => 'regolamento', 'riferimento' => 'art. 12 del regolamento', 'giorni_decorrenza' => $decorrenza]);
    $s['persone']['Elena'] = ideErede($s, 'Elena');
    $s['persone']['Marco'] = ideErede($s, 'Marco');

    return $s;
}

function ideErede(array $s, string $nome): \App\Models\Anagrafica
{
    $email = strtolower($nome) . '-ide-' . $s['c']->id . '@test.it';
    if (($gia = \App\Models\Anagrafica::where('email', $email)->first()) !== null) {
        return $gia;
    }
    $p = \App\Models\Anagrafica::forceCreate(['nome' => $nome, 'email' => $email, 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'IDE' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** La successione di U1 dal 17/11/2026 a Elena e Marco al 50 %, con la strada dell'arretrato, la scelta sugli interessi del defunto e quella degli eredi. */
function ideSuccessione(array $s, string $arretrato = Subentro::ARRETRATO_AGLI_EREDI, ?string $interessi = null, ?array $eredi = null, ?string $accordo = null): array
{
    return ['tipo' => 'successione', 'riga_uscente_id' => $s['righe']['Neri'], 'decorrenza' => '2026-11-17', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $s['persone']['Elena']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Marco']->id, 'quota' => 50]], 'arretrato' => $arretrato,
        'erede_di_riferimento' => $s['persone']['Elena']->id, 'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta']
        + ($arretrato === Subentro::ARRETRATO_AL_DEFUNTO ? ['conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO] : [])
        + ($interessi !== null ? ['interessi_del_defunto' => $interessi] : [])
        + ($eredi !== null ? ['interessi_eredi' => $eredi] : [])
        + ($accordo !== null ? ['accordo_del_defunto' => $accordo] : []);
}

/** Registra la successione il 20/11/2026 e la restituisce. */
function ideRegistra($test, array $s, array $dati): Subentro
{
    $test->travelTo('2026-11-20 10:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $dati)->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
}

/** La proposta degli interessi alla data, «oggi» spostato a quel giorno, per le persone date per nome. */
function ideProposta($test, array $s, array $chi, string $alla): array
{
    $test->travelTo($alla . ' 10:00:00');

    return accProposta($test, $s, $chi, $alla)->assertOk()->json();
}

/** Le righe di una persona nella proposta: chiave => [interessi, dal, al]. */
function ideRighe(array $proposta, array $s, string $chi): array
{
    $p = accDi($proposta, $s, $chi);

    return collect($p['righe'] ?? [])->mapWithKeys(fn ($r) => [$r['chiave'] => [$r['interessi'], $r['dal'], $r['al']]])->all();
}

/** La riga ereditata di una persona (con il suffisso del passaggio), o null. */
function ideEreditata(array $proposta, array $s, string $chi): ?array
{
    return collect(accDi($proposta, $s, $chi)['righe'] ?? [])->first(fn ($r) => str_contains($r['chiave'], '|s'));
}

/**
 * Il piano nuovo della gestione di Via Verdi che assorbe l'arretrato: una voce di € 10,00 (o della cifra data, in centesimi) sulla tabella generale, i saldi
 * con il metodo dato, la prima scadenza il 31/03/2027 (poi ogni tre mesi), emesso alla data data (null: non emesso). L'esercizio della prova si allunga al
 * 31/12/2027.
 */
function idePianoNuovo($test, array $s, string $metodo = 'prima_rata', int $rate = 1, ?string $emissione = '2027-01-15', array $daEmettere = [1], int $voceCents = 1000): PianoRate
{
    DB::table('esercizi')->where('id', $s['e']->id)->update(['data_fine' => '2027-12-31']);
    DB::table('gestioni')->where('id', $s['g']->id)->update(['data_fine' => '2027-12-31']);
    $voce = cpmVoce($s, 'Manutenzione albero', [[$s['generali'], 100]], 'spesa', $voceCents);
    $piano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Preventivo 2027',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => $rate, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2027-03-31',
        'metodo_distribuzione' => $metodo, 'applica_saldi' => true]);
    $piano->capitoli()->attach($voce->id, ['importo' => $voceCents, 'note' => 'La voce del piano nuovo']);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
    $piano = $piano->fresh();
    $giorni = ['2027-03-31', '2027-06-30', '2027-09-30', '2027-12-31'];
    foreach (DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->get(['id', 'numero_rata']) as $i => $r) {
        DB::table('rate')->where('id', $r->id)->update(['data_scadenza' => $giorni[$i] ?? '2027-12-31']);
        DB::table('rate_quote')->where('rata_id', $r->id)->update(['data_scadenza' => $giorni[$i] ?? '2027-12-31']);
    }
    if ($emissione !== null) {
        $test->travelTo($emissione . ' 10:00:00');
        $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), [
            'rate_ids' => DB::table('rate')->where('piano_rate_id', $piano->id)->whereIn('numero_rata', $daEmettere)->pluck('id')->all(),
            'data_emissione' => $emissione, 'invia_notifiche' => false,
        ])->assertSessionHasNoErrors();
    }

    return $piano;
}

/**
 * Una riga scritta a mano nel giornale di Via Verdi per la persona data, su «Crediti verso condomini», sull'unità U1, con la rata data (null: senza rata), il
 * giorno, la cifra (più: a debito; meno: a credito) e il tipo del movimento (di serie sull'unità U1); la contropartita su «Gestione rate». Per i casi che le pagine non fanno in due
 * righe: un debito suo su una rata, un credito senza rata.
 */
function ideRigaNelGiornale(array $s, string $chi, ?int $rataId, string $giorno, int $cifra, string $tipo, string $unita = 'U1'): void
{
    $crediti = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'crediti_condomini')->value('id');
    $contro = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'gestione_rate')->value('id');
    $sc = \App\Models\Gestionale\ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
        'data_registrazione' => $giorno, 'data_competenza' => $giorno, 'causale' => 'Riga di prova', 'tipo_movimento' => $tipo, 'stato' => 'registrata']);
    $sc->righe()->create(['conto_contabile_id' => $crediti, 'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$unita]->id, 'rata_id' => $rataId,
        'tipo_riga' => $cifra > 0 ? 'dare' : 'avere', 'importo' => abs($cifra)]);
    $sc->righe()->create(['conto_contabile_id' => $contro, 'tipo_riga' => $cifra > 0 ? 'avere' : 'dare', 'importo' => abs($cifra)]);
}
