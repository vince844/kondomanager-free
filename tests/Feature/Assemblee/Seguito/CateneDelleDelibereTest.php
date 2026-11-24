<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\StatoVersionePreventivo;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Enums\StatoPianoRate;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Evento;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\DispensaSeiMesi;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RegolaRecupero;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\Gestionale\VersionePreventivo;
use App\Services\Assemblee\Convocazione\VersioniDelPreventivo;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Services\Gestionale\VersioniDelRendiconto;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Le otto catene del seguito delle delibere (§3 del progetto della tappa), ognuna da un capo all'altro: la sala di Via Roma 12 con i gesti veri fino alla
 * conferma del voto, il verbale n. 42 generato e registrato dalle rotte vere, «Conferma» del riepilogo dalla sua rotta, poi la scheda del modulo letta dove
 * la legge chi la usa. Un `it()` per catena e per ogni esito che cambia la strada.
 *
 * Che cosa non copre: le pagine (il riepilogo, il passo 6 e le frasi sulle schede si guardano a video); «Crea il piano straordinario» come finestra (la
 * gestione e la voce dalla delibera); la stampa dello stato di esecuzione; la delega letta dallo stralcio (non c'è ancora).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Una versione del rendiconto 2026 presentata, la n. 2, fermata il 20/11/2026. */
function cddVersione(Beta51Scenario $s, int $numero = 2): RendicontoVersione
{
    return RendicontoVersione::create(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'numero' => $numero, 'stato' => RendicontoVersione::PRESENTATA,
        'forma' => RendicontoVersione::FORMA, 'cifre' => ['conguaglio' => ['condominio' => ['conguaglio' => 0]]], 'calcolato_il' => '2026-11-20 10:00:00',
        'pdf' => [], 'creata_da' => $s->admin->id]);
}

/** Il compito dello sforo di una fattura. */
function cddCompitoDelloSforo(Beta51Scenario $s, FatturaPassiva $fattura, string $tipo): Evento
{
    $e = Evento::create(['title' => $tipo, 'start_time' => now(), 'end_time' => now()->addHour(), 'visibility' => 'hidden', 'is_approved' => true,
        'meta' => ['type' => $tipo, 'requires_action' => true, 'context' => ['fattura_id' => $fattura->id]]]);
    $e->condomini()->attach($s->c->id);

    return $e;
}

it('catena 1 · il rendiconto con i suoi due esiti approvati: la versione 2 approvata con il giorno, il verbale n. 42 e il suo documento, i due collegamenti, il dato per la chiusura', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $v = cddVersione($s);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Approvazione del rendiconto 2026 e dello stato di riparto', 'materia' => 'rendiconto',
        'esercizio_id' => $s->e->id, 'oggetti' => [['rendiconto_versione', $v->id, 'documento']]]]);
    $rendiconto = SeguitoDiViaRoma12::delibera($a, 1, 1);
    $riparto = SeguitoDiViaRoma12::delibera($a, 1, 2);

    // R1: registrato il verbale, la versione è ancora presentata.
    expect($v->fresh()->stato)->toBe(RendicontoVersione::PRESENTATA)
        ->and(CollegamentoAllaDelibera::query()->where('oggetto_tipo', 'rendiconto_versione')->count())->toBe(0);
    $chiave = SeguitoDiViaRoma12::chiave($a, $rendiconto, 'rendiconto_versione');
    expect(SeguitoDiViaRoma12::righe($a)[$chiave]['titolo'])->toBe('Rendiconto 2026: versione 2 approvata, e lo stato di riparto approvato');

    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk()->assertJsonPath('esiti.' . $chiave . '.esito', 'applicata');

    $v->refresh();
    $documento = VerbaleDellAssemblea::query()->where('assemblea_id', $a->id)->value('documento_id');
    expect($v->stato)->toBe(RendicontoVersione::APPROVATA)
        ->and($v->data_delibera->format('Y-m-d'))->toBe('2026-12-15')
        ->and($v->numero_verbale)->toBe('42')
        ->and((bool) $v->riparto_approvato)->toBeTrue()
        ->and($v->verbale_documento_id)->toBe((int) $documento)
        ->and(CollegamentoAllaDelibera::query()->where('oggetto_tipo', 'rendiconto_versione')->where('oggetto_id', $v->id)->pluck('delibera_id', 'ruolo')->all())
        ->toBe(['approvazione' => $rendiconto->id, 'riparto' => $riparto->id]);
    $chiusura = VersioniDelRendiconto::perLaChiusura($s->e);
    expect($chiusura['delibere']['rendiconto']['id'])->toBe($rendiconto->id)
        ->and($chiusura['delibere']['riparto']['id'])->toBe($riparto->id)
        ->and($chiusura['delibere']['rendiconto']['frase'])->toBe('Delibera del 15/12/2026, punto 1 (verbale n. 42)')
        ->and($chiusura['verbale_documento_id'])->toBe((int) $documento);
    // Il passo 6: la delibera del rendiconto è eseguita alla conferma.
    $tabella = collect(SeguitoDelleDelibere::perLaPagina($a, $s->admin)['tabella'])->keyBy('delibera_id');
    expect($tabella[$rendiconto->id]['seguito']['stato'])->toBe('eseguita')
        ->and($tabella[$rendiconto->id]['seguito']['eseguita_il'])->toBe('2026-12-15');
});

it('catena 1 · il riparto respinto: la versione approvata con «riparto: no», e il dato per la chiusura lo dice', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $v = cddVersione($s);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Approvazione del rendiconto 2026 e dello stato di riparto', 'materia' => 'rendiconto',
        'esercizio_id' => $s->e->id, 'oggetti' => [['rendiconto_versione', $v->id, 'documento']]]], [1 => [2 => 'respinta']]);
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    expect($v->fresh()->stato)->toBe(RendicontoVersione::APPROVATA)
        ->and((bool) $v->fresh()->riparto_approvato)->toBeFalse()
        ->and(VersioniDelRendiconto::perLaChiusura($s->e)['riparto_approvato'])->toBeFalse();
});

it('catena 1 · il rendiconto respinto: la versione resta presentata, e la risposta da dare con il compito «Delibere respinte»', function () {
    $t = SalaDiViaRoma12::nuovo($this);
    $w = cddVersione($t);
    $b = SeguitoDiViaRoma12::tenuta($this, $t, [['testo' => 'Approvazione del rendiconto 2026 e dello stato di riparto', 'materia' => 'rendiconto',
        'esercizio_id' => $t->e->id, 'oggetti' => [['rendiconto_versione', $w->id, 'documento']]]], [1 => [1 => 'respinta', 2 => 'respinta']]);
    $d = SeguitoDiViaRoma12::delibera($b, 1);
    $riga = SeguitoDiViaRoma12::righe($b)[SeguitoDiViaRoma12::chiave($b, $d, 'dopo_il_rifiuto')];
    expect($riga['titolo'])->toBe('Rendiconto respinto dall\'assemblea del 15/12/2026, punto 1 (verbale n. 42)');
    SeguitoDiViaRoma12::conferma($this, $t, $b)->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    expect($w->fresh()->stato)->toBe(RendicontoVersione::PRESENTATA)
        ->and($cosa->tipo)->toBe(TipoCosaDaFare::DOPO_IL_RIFIUTO)
        ->and(Evento::query()->find($cosa->evento_id)?->meta['type'])->toBe('delibera_respinta');
});

it('catena 2 · il preventivo approvato: la versione approvata e il piano «Ordinario 2026» da bozza ad approvato con il giorno e il verbale n. 42', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizia delle scale', 600000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $versione = null;
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Approvazione del preventivo 2026 e del piano di riparto', 'materia' => 'preventivo',
        'esercizio_id' => $s->e->id]], [], function ($a) use ($s, &$versione) {
            $versione = app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
        });
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $piano = $s->piano('Ordinario 2026');
    expect($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA);

    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();

    expect($versione->fresh()->stato)->toBe(StatoVersionePreventivo::APPROVATA)
        ->and($piano->fresh()->stato)->toBe(StatoPianoRate::APPROVATO)
        ->and($piano->fresh()->data_delibera_assemblea->format('Y-m-d'))->toBe('2026-12-15')
        ->and($piano->fresh()->numero_verbale)->toBe('42')
        ->and((int) CollegamentoAllaDelibera::query()->where('oggetto_tipo', 'piano_rate')->where('oggetto_id', $piano->id)->value('delibera_id'))->toBe($d->id);
});

it('catena 2 · il piano diverso dal preventivo approvato resta fuori con la differenza; rimesso uguale, «Applica» lo approva (decisione 622 (8))', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Manutenzione dell\'ascensore', 380000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Approvazione del preventivo 2026 e del piano di riparto', 'materia' => 'preventivo',
        'esercizio_id' => $s->e->id]], [], function ($a) use ($s) {
            app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
            // Dopo la spedizione: la voce passa da € 3.800,00 a € 5.000,00.
            $s->voci['Manutenzione dell\'ascensore']['conto']->update(['importo' => 500000]);
        });
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $piano = $s->piano('Ordinario 2026');
    // Una chiave per scheda e ruolo: la stessa quando la riga resta fuori e quando si applica.
    $chiave = $d->id . ':piano_rate:' . $piano->id . ':delibera';
    $riga = SeguitoDiViaRoma12::righe($a)[$chiave];
    expect($riga['perche_no'])->toBe('Il piano chiede € 5.000,00, l\'assemblea ha approvato € 3.800,00.')
        ->and($riga['dati']['differenza']['voci'][0]['frase'])->toBe('la voce «Manutenzione dell\'ascensore» è passata da € 3.800,00 a € 5.000,00')
        ->and($riga['spuntata'])->toBeFalse();

    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    expect($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA);

    $s->voci['Manutenzione dell\'ascensore']['conto']->update(['importo' => 380000]);
    $applica = $d->id . ':piano_rate:' . $piano->id . ':delibera';
    expect(SeguitoDiViaRoma12::righe($a)[$applica]['si_applica'])->toBeTrue();
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]), ['chiave' => $applica])->assertOk()
        ->assertJsonPath('esito', 'applicata');
    expect($piano->fresh()->stato)->toBe(StatoPianoRate::APPROVATO);
});

it('catena 2 · il preventivo respinto: il piano resta in bozza, e la risposta da dare dice «Il piano resta in bozza»', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizia delle scale', 600000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $piano = $s->piano('Ordinario 2026');
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Approvazione del preventivo 2026 e del piano di riparto', 'materia' => 'preventivo',
        'esercizio_id' => $s->e->id, 'oggetti' => [['piano_rate', $piano->id, 'origine']]]], [1 => 'respinta']);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $riga = SeguitoDiViaRoma12::righe($a)[SeguitoDiViaRoma12::chiave($a, $d, 'dopo_il_rifiuto')];
    expect($riga['titolo'])->toBe('Respinto dall\'assemblea del 15/12/2026, punto 1 (verbale n. 42)')
        ->and($riga['frase'])->toStartWith('Il piano resta in bozza: nessuna rata parte.');
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    expect($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA)
        ->and(\App\Services\Assemblee\Seguito\EsitiNelleSchede::perLaScheda($piano)[0]['frase'])
        ->toBe('Respinto dall\'assemblea del 15/12/2026, punto 1 (verbale n. 42).');
});

it('catena 3 · la ratifica: la fattura n. 45 approvata con il giorno e il verbale, i due compiti dello sforo chiusi', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $f = ViaRoma12::fatturaInSforo($s);
    $convocare = cddCompitoDelloSforo($s, $f, 'convocazione_urgenza');
    $ratifiche = cddCompitoDelloSforo($s, $f, 'ratifica_sforo');
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Ratifica della spesa urgente di € 1.800,00 (fattura n. 45)', 'materia' => 'ratifica',
        'oggetti' => [['fattura_passiva', $f->id, 'origine']]]]);
    expect($f->fresh()->stato_approvazione)->toBe('sforo_motivato');
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    $f->refresh();
    expect($f->stato_approvazione)->toBe('approvata')
        ->and($f->dati_extra['ratifica_assembleare']['deliberata_il'])->toBe('2026-12-15')
        ->and($f->dati_extra['ratifica_assembleare']['numero_verbale'])->toBe('42')
        ->and((bool) $convocare->fresh()->is_completed)->toBeTrue()
        ->and((bool) $ratifiche->fresh()->is_completed)->toBeTrue();
});

it('catena 3 · la ratifica respinta: niente cambia sulla fattura, i compiti dello sforo restano; «Chiudi con il motivo» li chiude', function () {
    $t = SalaDiViaRoma12::nuovo($this);
    $g = ViaRoma12::fatturaInSforo($t);
    $compito = cddCompitoDelloSforo($t, $g, 'ratifica_sforo');
    $b = SeguitoDiViaRoma12::tenuta($this, $t, [['testo' => 'Ratifica della spesa urgente di € 1.800,00 (fattura n. 45)', 'materia' => 'ratifica',
        'oggetti' => [['fattura_passiva', $g->id, 'origine']]]], [1 => 'respinta']);
    SeguitoDiViaRoma12::conferma($this, $t, $b)->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', SeguitoDiViaRoma12::delibera($b, 1)->id)->firstOrFail();
    expect($g->fresh()->stato_approvazione)->toBe('sforo_motivato')
        ->and((bool) $compito->fresh()->is_completed)->toBeFalse();
    $this->actingAs($t->admin)->post(route('admin.gestionale.assemblee.seguito.cose.chiudi', [$t->c, $b, $cosa->id]), ['motivo' => 'Pagata, se ne parla con l\'impresa.'])
        ->assertSessionHasNoErrors();
    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::NON_SERVE)
        ->and((bool) $compito->fresh()->is_completed)->toBeTrue()
        ->and($g->fresh()->stato_approvazione)->toBe('sforo_motivato');
});

it('catena 4 · i lavori: nessun conto cambia, la gestione si lega e c\'è «Crea il piano straordinario» con il compito; il piano salvato con la delibera la fa e chiude il compito', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Portone 2027', 'straordinaria')->voce('Portone 2027', 'Sostituzione del portone', 1450000, ['Proprietà' => 100]);
    $gestione = $s->gestioni['Portone 2027']['g'];
    $marco = ViaRoma12::utente([\App\Enums\Permission::VIEW_ASSEMBLEE]);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino, fondo speciale', 'materia' => 'lavori',
        'importo' => 1450000, 'oggetti' => [['gestione', $gestione->id, 'origine']]]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $cosaChiave = $d->id . ':cosa:crea_piano_straordinario';
    expect(SeguitoDiViaRoma12::righe($a)[$cosaChiave]['cosa']['testo'])->toBe('Crea il piano straordinario (€ 14.500,00)');

    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['cose' => [$cosaChiave => ['entro_il' => '2027-01-15', 'a_chi' => 'collaboratore',
        'assegnato_a' => $marco->id]]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::CREA_PIANO_STRAORDINARIO->value)->firstOrFail();
    $compito = Evento::query()->findOrFail($cosa->evento_id);
    expect(PianoRate::query()->where('gestione_id', $gestione->id)->count())->toBe(0)
        ->and((int) CollegamentoAllaDelibera::query()->where('oggetto_tipo', 'gestione')->where('oggetto_id', $gestione->id)->value('delibera_id'))->toBe($d->id)
        ->and($compito->meta['type'])->toBe('delibera_da_eseguire')
        ->and($compito->meta['assegnato_a'])->toBe($marco->id)
        ->and($compito->start_time->format('Y-m-d'))->toBe('2027-01-15')
        ->and(\App\Services\Assemblee\Seguito\StatoDiEsecuzione::seguito($d->fresh())['stato'])->toBe('da_eseguire');

    // Il piano salvato per la strada di oggi con la delibera: la cosa è fatta, il compito chiuso, la delibera eseguita.
    $this->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $gestione->id, 'nome' => 'Portone d\'ingresso 2027 — rate', 'metodo_distribuzione' => 'tutte_rate',
        'numero_rate' => 2, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2027-01-31', 'genera_subito' => 0, 'delibera_id' => $d->id,
        'capitoli_config' => [['id' => $s->voci['Sostituzione del portone']['conto']->id, 'importo' => '9.000,00']],
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('gestione_id', $gestione->id)->firstOrFail();
    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and($cosa->fresh()->oggetto_tipo)->toBe('piano_rate')
        ->and($cosa->fresh()->oggetto_id)->toBe($piano->id)
        ->and((bool) $compito->fresh()->is_completed)->toBeTrue()
        // L'avviso in bacheca dei lavori (acceso di serie) conta fra le cose da fare: «Da eseguire · 1 di 2» finché non è pubblicato.
        ->and(\App\Services\Assemblee\Seguito\StatoDiEsecuzione::seguito($d->fresh()))->toMatchArray(['stato' => 'da_eseguire', 'fatte' => 1, 'quante' => 2])
        // Il fondo speciale: il piano chiede € 9.000,00 sui € 14.500,00 deliberati, e lo dice (decisione 622 (2)).
        ->and(\App\Services\Assemblee\Seguito\Effetti\SuiLavori::fondoSpeciale($d->fresh())['frase'])
        ->toBe('Fondo speciale: € 9.000,00 chiesti su € 14.500,00 deliberati.');
});

it('catena 4 · il fondo «Riserva»: sbloccato con la delibera, la frase in testa e la motivazione di prima sotto', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $conto = \Illuminate\Support\Facades\DB::table('conti_contabili')->insertGetId(['condominio_id' => $s->c->id, 'ruolo' => 'fondo', 'codice' => '2010.R',
        'nome' => 'Fondo Riserva', 'tipo' => 'passivo', 'categoria' => 'fondi', 'created_at' => now(), 'updated_at' => now()]);
    $cassa = Cassa::create(['condominio_id' => $s->c->id, 'conto_contabile_id' => $conto, 'nome' => 'Riserva', 'tipo' => 'fondo',
        'sottotipo_fondo' => 'vincolato_lavori', 'vincolo_descrizione' => 'Lavori straordinari', 'is_override_assemblea' => false, 'saldo_iniziale' => 0,
        'attiva' => true]);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Utilizzo del fondo «Riserva»', 'materia' => 'fondo', 'oggetti' => [['cassa', $cassa->id, 'origine']]]]);
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    $cassa->refresh();
    expect((bool) $cassa->is_override_assemblea)->toBeTrue()
        ->and($cassa->motivazione_override)->toBe('Sbloccato con la delibera del 15/12/2026, punto 1 (verbale n. 42).');
});

it('catena 6 · la dispensa: «Registra la dispensa» da fare, fatta dalla finestra di oggi con la delibera e l\'esercizio', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Dispensa dall\'agire entro sei mesi, crediti del 2026', 'materia' => 'dispensa',
        'esercizio_id' => $s->e->id]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    expect($cosa->tipo)->toBe(TipoCosaDaFare::REGISTRA_DISPENSA)
        ->and(DispensaSeiMesi::query()->where('condominio_id', $s->c->id)->count())->toBe(0);

    $this->actingAs($s->admin)->post(route('admin.gestionale.dispense.store', ['condominio' => $s->c->id]), [
        'delibera_id' => $d->id, 'esercizi' => [(int) $s->e->id], 'per_tutti' => '1', 'nota' => 'Per i crediti del 2026',
    ])->assertSessionHasNoErrors();
    $dispensa = DispensaSeiMesi::query()->where('condominio_id', $s->c->id)->firstOrFail();
    expect($dispensa->data_delibera->format('Y-m-d'))->toBe('2026-12-15')
        ->and($dispensa->numero_verbale)->toBe('42')
        ->and($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA);
});

it('catena 6 · la revoca di una dispensa nata dal punto: la riga non è spuntata di serie; spuntata, la dispensa è revocata dal giorno della riunione', function () {
    $t = SalaDiViaRoma12::nuovo($this);
    $vecchia = DispensaSeiMesi::create(['condominio_id' => $t->c->id, 'data_delibera' => '2026-03-10', 'tutte_le_persone' => true, 'registrata_da' => $t->admin->id]);
    $b = SeguitoDiViaRoma12::tenuta($this, $t, [['testo' => 'Revoca della dispensa dei sei mesi', 'materia' => 'dispensa',
        'oggetti' => [['dispensa_sei_mesi', $vecchia->id, 'origine']]]]);
    $r = SeguitoDiViaRoma12::delibera($b, 1);
    $chiave = $r->id . ':dispensa_sei_mesi:' . $vecchia->id . ':revoca_della_dispensa';
    expect(SeguitoDiViaRoma12::righe($b)[$chiave]['spuntata'])->toBeFalse();
    SeguitoDiViaRoma12::conferma($this, $t, $b, [$chiave])->assertOk()->assertJsonPath('esiti.' . $chiave . '.esito', 'applicata');
    expect($vecchia->fresh()->revocata_il->format('Y-m-d'))->toBe('2026-12-15');
});

it('catena 7 · l\'accordo di rientro di Gallo Enzo autorizzato con il giorno e il verbale; già autorizzato, la riga lo dice e niente cambia', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $accordo = AccordoRientro::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persone['gallo']->id, 'data_accordo' => '2026-09-01',
        'fino_al' => '2027-03-01', 'autorizzato_assemblea' => false]);
    $gia = AccordoRientro::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persone['rossi']->id, 'data_accordo' => '2026-09-01',
        'fino_al' => '2027-03-01', 'autorizzato_assemblea' => true, 'data_autorizzazione' => '2026-12-02']);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [
        ['testo' => 'Autorizzazione di un piano di rientro', 'materia' => 'accordo', 'oggetti' => [['accordo_rientro', $accordo->id, 'origine']]],
        ['testo' => 'Autorizzazione di un altro piano di rientro', 'materia' => 'accordo', 'oggetti' => [['accordo_rientro', $gia->id, 'origine']]],
    ]);
    $d2 = SeguitoDiViaRoma12::delibera($a, 2);
    expect(SeguitoDiViaRoma12::righe($a)[$d2->id . ':accordo_rientro:' . $gia->id . ':autorizzazione']['titolo'])
        ->toBe('Accordo di rientro di Rossi Mario: già autorizzato il 02/12/2026');
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    $accordo->refresh();
    expect((bool) $accordo->autorizzato_assemblea)->toBeTrue()
        ->and($accordo->data_autorizzazione->format('Y-m-d'))->toBe('2026-12-15')
        ->and($accordo->verbale_autorizzazione)->toBe('42')
        ->and($gia->fresh()->data_autorizzazione->format('Y-m-d'))->toBe('2026-12-02');
});

it('catena 8 · le regole del recupero: «Scrivi la regola» da fare, fatta quando la regola nasce con la delibera (la fonte)', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Criteri per l\'addebito degli interessi e delle spese di recupero dei crediti', 'materia' => 'regole']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    expect($cosa->tipo)->toBe(TipoCosaDaFare::SCRIVI_REGOLA);
    // La regola del recupero la scrive chi ha «Modifica condomini» (Coda 755 (2)).
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::EDIT_CONDOMINI->value, 'guard_name' => 'web']);
    $s->admin->givePermissionTo(\App\Enums\Permission::EDIT_CONDOMINI->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.recupero-crediti.store', [$s->c]), [
        'dal' => '2027-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3', 'fonte' => 'delibera', 'riferimento' => null, 'delibera_id' => $d->id,
    ])->assertSessionHasNoErrors();
    $regola = RegolaRecupero::query()->where('condominio_id', $s->c->id)->firstOrFail();
    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and($cosa->fresh()->oggetto_id)->toBe($regola->id);
});

it('il seguito · «Segna eseguita» dalla rotta, con la nota e il documento: la cosa scritta è fatta, il compito si chiude, la delibera è eseguita il giorno scelto', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Riscaldamento: orari di accensione 2026/2027', 'materia' => 'riscaldamento']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [], ['altre' => [['delibera_id' => $d->id, 'testo' => 'Avvisa il manutentore della caldaia',
        'entro_il' => '2026-12-20']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    expect($cosa->tipo)->toBe(TipoCosaDaFare::FUORI_DAL_PROGRAMMA)
        ->and(\App\Services\Assemblee\Seguito\StatoDiEsecuzione::seguito($d->fresh())['stato'])->toBe('da_eseguire');
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.cose.eseguita', [$s->c, $a, $cosa->id]), [
        'giorno' => '2026-12-17', 'nota' => 'Telefonata al manutentore.', 'documento' => \Illuminate\Http\UploadedFile::fake()->create('lettera.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $cosa->refresh();
    expect($cosa->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and($cosa->fatta_il->format('Y-m-d'))->toBe('2026-12-17')
        ->and($cosa->documento_id)->not->toBeNull()
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue()
        ->and(\App\Services\Assemblee\Seguito\StatoDiEsecuzione::seguito($d->fresh()))->toMatchArray(['stato' => 'eseguita', 'eseguita_il' => '2026-12-17']);
});

it('catena del preventivo spedito · fermato di nuovo, l\'assemblea approva la versione spedita; il piano fuori con «Rimetti il piano», rimesso, «Applica»: eseguita', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Manutenzione dell\'ascensore', 380000, ['Proprietà' => 100])
        ->pianoOrdinario('Ordinario 2026', 'Ordinaria 2026', bozza: true);
    $spedita = null;
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [SeguitoDiViaRoma12::puntoDelPreventivo($s)], [], function ($a) use ($s, &$spedita) {
        $spedita = app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
    });
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $piano = $s->piano('Ordinario 2026');
    // Dopo la spedizione la voce passa a € 5.000,00: «Ferma il preventivo» non c'è più, e una versione fermata dopo (com'era prima della regola) resta fuori.
    ConvocazioneDiViaRoma12::oggi('2026-12-05 10:00');
    $s->voci['Manutenzione dell\'ascensore']['conto']->update(['importo' => 500000]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.fascicolo.preventivo', [$s->c, $a]), ['punto_id' => $a->punti()->value('id')])
        ->assertSessionHasErrors('punto');
    $stato = $a->stato->value;
    $a->forceFill(['stato' => 'in_preparazione'])->saveQuietly();
    $nuova = app(VersioniDelPreventivo::class)->ferma($a, $a->punti()->firstOrFail(), $s->admin);
    $a->forceFill(['stato' => $stato])->saveQuietly();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $chiaveDellaCosa = $d->id . ':cosa:rimetti_piano';

    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['cose' => [$chiaveDellaCosa => ['entro_il' => '2026-12-22', 'a_chi' => 'me']]])->assertOk();

    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::RIMETTI_PIANO->value)->firstOrFail();
    expect($spedita->fresh()->stato)->toBe(StatoVersionePreventivo::APPROVATA)
        ->and($nuova->fresh()->stato)->not->toBe(StatoVersionePreventivo::APPROVATA)
        ->and($piano->fresh()->stato)->toBe(StatoPianoRate::BOZZA)
        ->and($cosa->stato)->toBe(StatoCosaDaFare::DA_FARE)
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeFalse()
        ->and(\App\Services\Assemblee\Seguito\StatoDiEsecuzione::seguito($d->fresh())['stato'])->toBe('da_eseguire');

    // Il 20/12 Andrea rimette la voce come il preventivo approvato; il piano torna uguale e «Applica» lo approva.
    ConvocazioneDiViaRoma12::oggi('2026-12-20 10:00');
    $s->voci['Manutenzione dell\'ascensore']['conto']->update(['importo' => 380000]);
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]),
        ['chiave' => $d->id . ':piano_rate:' . $piano->id . ':delibera'])->assertOk()->assertJsonPath('esito', 'applicata');

    expect($piano->fresh()->stato)->toBe(StatoPianoRate::APPROVATO)
        ->and($piano->fresh()->data_delibera_assemblea->format('Y-m-d'))->toBe('2026-12-15')
        ->and($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue()
        ->and(\App\Services\Assemblee\Seguito\StatoDiEsecuzione::seguito($d->fresh())['stato'])->toBe('eseguita');
});

it('catena del rendiconto con modifiche · «Correggi e ferma una versione nuova», la versione fermata dalla pagina del rendiconto, «Applica»: la cosa fatta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $v = cddVersione($s);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Approvazione del rendiconto 2026 e dello stato di riparto', 'materia' => 'rendiconto',
        'esercizio_id' => $s->e->id, 'oggetti' => [['rendiconto_versione', $v->id, 'documento']]]], [1 => [1 => 'approvata_con_modifiche', 2 => 'approvata']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1, 1);
    $chiaveDellaCosa = $d->id . ':cosa:rifai_versione';
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['cose' => [$chiaveDellaCosa => ['entro_il' => '2026-12-22']],
        'modifiche' => [$d->id => 'Togliere la spesa del giardiniere, € 400,00']])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::RIFAI_VERSIONE->value)->firstOrFail();
    expect($v->fresh()->stato)->toBe(RendicontoVersione::PRESENTATA);

    // Il 18/12 Andrea corregge e ferma la versione nuova dalla pagina del rendiconto; il riepilogo la propone, «Applica» la approva.
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    $nuova = app(VersioniDelRendiconto::class)->ferma($s->c, $s->e, ['riepilogo'], false, false, $s->admin);
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.applica', [$s->c, $a]),
        ['chiave' => $d->id . ':rendiconto_versione:' . $nuova->id . ':approvazione'])->assertOk();

    expect($nuova->fresh()->stato)->toBe(RendicontoVersione::APPROVATA)
        ->and($nuova->fresh()->data_delibera->format('Y-m-d'))->toBe('2026-12-15')
        ->and($v->fresh()->stato)->toBe(RendicontoVersione::PRESENTATA)
        ->and($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue()
        ->and(\App\Services\Assemblee\Seguito\StatoDiEsecuzione::seguito($d->fresh())['stato'])->toBe('eseguita');
});

it('catena dell\'avviso · «Avvisa i condòmini», la comunicazione scritta dalla sua pagina e pubblicata dopo: la cosa fatta, senza che nessuno apra il passo 6', function () {
    \Illuminate\Support\Facades\Notification::fake();
    $s = SalaDiViaRoma12::nuovo($this);
    foreach ([\App\Enums\Permission::CREATE_COMUNICAZIONI, \App\Enums\Permission::EDIT_COMUNICAZIONI, \App\Enums\Permission::VIEW_COMUNICAZIONI,
        \App\Enums\Permission::PUBLISH_COMUNICAZIONI, \App\Enums\Permission::ACCESS_ADMIN_PANEL] as $p) {
        \Spatie\Permission\Models\Permission::findOrCreate($p->value, 'web');
        $s->admin->givePermissionTo($p->value);
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Riscaldamento: orari di accensione 2026/2027', 'testo_pubblico' => 'Orari del riscaldamento',
        'materia' => 'riscaldamento']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':cosa:avvisa_condomini';
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    SeguitoDiViaRoma12::conferma($this, $s, $a, [$chiave], ['cose' => [$chiave => ['entro_il' => '2026-12-18']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    $precompilata = \App\Services\Assemblee\Seguito\AvvisoAiCondomini::precompilata($cosa);
    $campi = ['subject' => $precompilata['subject'], 'description' => $precompilata['description'], 'priority' => 'bassa', 'can_comment' => false,
        'is_featured' => false, 'condomini_ids' => $precompilata['condomini_ids'], 'anagrafiche' => []];
    $this->actingAs($s->admin)->post(route('admin.comunicazioni.store'), $campi + ['is_published' => false, 'cosa_da_fare_id' => $cosa->id])
        ->assertSessionHasNoErrors();
    $comunicazione = \App\Models\Comunicazione::query()->latest('id')->firstOrFail();
    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::DA_FARE)->and($cosa->fresh()->oggetto_id)->toBe($comunicazione->id);

    // Il 17/12 la pubblica dalla sua pagina; il passo 6 nessuno lo apre.
    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');
    $this->actingAs($s->admin)->put(route('admin.comunicazioni.update', $comunicazione), $campi + ['is_published' => true])->assertSessionHasNoErrors();

    expect((bool) $comunicazione->fresh()->is_published)->toBeTrue()
        ->and($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and($cosa->fresh()->fatta_il->format('Y-m-d'))->toBe('2026-12-17')
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue()
        ->and(SeguitoDelleDelibere::perLaDashboard($s->c))->toBe([]);
});

it('catena della riunione su carta · una cosa da fare, «Elimina»: la frase giusta, mai la pagina 500; «Non serve più», poi «Elimina» la toglie', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari', 'testo_pubblico' => 'Orari del riscaldamento', 'materia' => 'riscaldamento']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':cosa:avvisa_condomini';
    SeguitoDiViaRoma12::conferma($this, $s, $a, [$chiave], ['cose' => [$chiave => ['entro_il' => '2026-12-18']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');

    $r = $this->actingAs($s->admin)->deleteJson(route('admin.gestionale.delibere.destroy', [$s->c, $d]));

    expect($r->status())->toBe(422)
        ->and($r->json('errors.delibera.0'))->toBe('La delibera ha 1 cosa da fare («Avvisa i condòmini»): segnala «Non serve più», poi eliminala.')
        ->and(\App\Models\Assemblee\Delibera::query()->whereKey($d->id)->exists())->toBeTrue();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.cose.non-serve', [$s->c, $a, $cosa->id]), ['motivo' => 'L\'esito era sbagliato'])
        ->assertSessionHasNoErrors();
    // Con le cose da fare il motivo è sempre obbligatorio, e resta nella riga del registro con la fotografia.
    $this->actingAs($s->admin)->deleteJson(route('admin.gestionale.delibere.destroy', [$s->c, $d]))->assertStatus(422)->assertJsonValidationErrors('motivo');
    $this->actingAs($s->admin)->deleteJson(route('admin.gestionale.delibere.destroy', [$s->c, $d]), ['motivo' => 'Esito sbagliato, la registro di nuovo'])
        ->assertRedirect();
    expect(\App\Models\Assemblee\Delibera::query()->whereKey($d->id)->exists())->toBeFalse()
        ->and(CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->count())->toBe(0)
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue();
});
