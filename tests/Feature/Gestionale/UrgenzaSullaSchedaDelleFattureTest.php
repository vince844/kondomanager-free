<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\StatoPianoRate;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\ConsuntivoPerPersona;
use App\Services\Riparto\CompetenzaDelPiano;
use App\Services\Riparto\GiornoDellUrgenza;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

/**
 * Coda 688, «Lavori ordinati il …» anche sulla scheda «Spese fuori preventivo» di una gestione straordinaria. Il caso, nell'esercizio 2026 delle prove:
 * martedì 02/03/2026 si rompe la colonna montante; la fattura n. 45 di Idraulica Bianchi, € 1.800,00, è registrata con una riga «Fuori preventivo» sulla
 * voce «Riparazione colonna» (tabella «Proprietà»: l'Int. 4 600 millesimi, l'Int. 7 400). Andrea la mette a rate con «Urgenza». Il 15/03 Rossi Mario
 * vende l'Int. 4 a Verdi Luca.
 *
 * - **La richiesta**: con «Urgenza» la scheda delle fatture chiede il giorno dei lavori come quella delle voci; il piano lo scrive nella sua colonna
 *   (`piani_rate.giorno_dei_lavori`). Sulla gestione ordinaria (lo «Scudo Legale») il giorno non c'è.
 * - **Il giorno nello stesso punto**: la competenza del piano (`CompetenzaDelPiano`) lo dà al motore come competenza dichiarata di un giorno; la fattura
 *   senza periodo lo prende invece di fermare il riparto, quella con il suo periodo tiene il suo. **Le cifre in centesimi interi**, la somma al centesimo.
 * - **Le catene**: la fattura senza periodo → il piano con il giorno → le rate dell'Int. 4 tutte a Rossi → il conguaglio dell'opera con il giorno, scritto;
 *   e la stessa catena con la fattura che dice il suo periodo.
 * - **La fattura registrata dopo** sulla stessa voce (la n. 52): la pagina della fattura nuova propone il giorno del piano, come per i piani d'urgenza
 *   fatti dalle voci.
 *
 * Che cosa non copre: il riquadro a video (`LaDeliberaOLUrgenza.test.ts`); il piano d'urgenza sulle voci (`UrgenzaSulleVociTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->withoutVite();
});

/** La colonna montante: la gestione straordinaria con la voce, Rossi sull'Int. 4 fino al 14/03 e Verdi dal 15/03, Bianchi sull'Int. 7. */
function usfColonna($t, string $tipo = 'straordinaria'): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($t);
    $s->tabella('Proprietà', ['4' => 600, '7' => 400])->titolare('4', 'rossi')->titolare('7', 'bianchi')
        ->gestione('Colonna montante 2026', $tipo)->voce('Colonna montante 2026', 'Riparazione colonna', 180000, ['Proprietà' => 100]);
    DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['4']->id)->where('anagrafica_id', $s->persona('rossi')->id)
        ->update(['data_fine' => '2026-03-14']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s->persona('verdi')->id, 'immobile_id' => $s->unita['4']->id, 'tipologia' => 'proprietario',
        'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-03-15', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    return $s;
}

/** La fattura n. 45 di Idraulica Bianchi, € 1.800,00, con la riga «Fuori preventivo» sulla voce; il periodo solo se lo dice. */
function usfFattura(Beta51Scenario $s, ?string $dal = null, ?string $al = null): FatturaPassiva
{
    $fornitore = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Idraulica Bianchi', 'soggetto_ritenuta' => false, 'modalita_pagamento_default' => 'bonifico',
        'created_at' => now(), 'updated_at' => now()]);
    $fattura = FatturaPassiva::create([
        'condominio_id' => $s->c->id, 'fornitore_id' => $fornitore, 'esercizio_id' => $s->e->id, 'gestione_id' => $s->gestioni['Colonna montante 2026']['g']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => '45', 'data_documento' => '2026-03-05', 'data_scadenza' => '2026-04-05', 'is_pregresso' => false,
        'importo_imponibile' => 180000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 180000, 'netto_a_pagare' => 180000,
        'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico', 'competenza_dal' => $dal, 'competenza_al' => $al,
    ]);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => $s->voci['Riparazione colonna']['conto']->id, 'immobile_id' => null,
        'descrizione' => 'Riparazione della colonna montante', 'aliquota_iva' => 0, 'importo_imponibile' => 180000, 'importo_iva' => 0, 'is_sopravvenienza' => true,
        'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);

    return $fattura;
}

/** Il nuovo piano dalla scheda delle fatture, dalla rotta vera. @param array<string, mixed> $piu */
function usfPiano($t, Beta51Scenario $s, FatturaPassiva $fattura, array $piu = [])
{
    return $t->actingAs($s->admin)->from('/nuovo')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), $piu + [
        'tipo' => 'straordinario', 'gestione_id' => $s->gestioni['Colonna montante 2026']['g']->id, 'nome' => 'Colonna montante 2026 — rate',
        'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 3, 'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-04-30', 'genera_subito' => true,
        'recurrence_enabled' => false, 'tipo_autorizzazione' => 'urgenza',
        'motivazione_autorizzazione' => 'Rottura della colonna montante dell\'acqua il 02/03/2026, con perdite nelle cantine.',
        'fatture_config' => [['id' => $fattura->id, 'importo' => '1.800,00']], 'capitoli_ids' => [],
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: la vendita del 15/03 nella prova',
    ]);
}

/** La fattura pagata sul giornale della gestione, sulla voce, legata alla fattura: è la spesa che il conguaglio dell'opera divide. */
function usfSulGiornale(Beta51Scenario $s, FatturaPassiva $fattura): void
{
    $costi = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s->c->id, 'codice' => '60.10', 'nome' => 'Costi', 'tipo' => 'costo', 'categoria' => 'costi',
        'created_at' => now(), 'updated_at' => now()]);
    $debiti = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s->c->id, 'codice' => '40.10', 'nome' => 'Debiti verso fornitori', 'tipo' => 'passivo',
        'ruolo' => 'debiti_fornitori', 'categoria' => 'debiti', 'created_at' => now(), 'updated_at' => now()]);
    $sc = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s->c->id, 'gestione_id' => $s->gestioni['Colonna montante 2026']['g']->id,
        'esercizio_id' => $s->e->id, 'data_registrazione' => '2026-03-05', 'data_competenza' => '2026-03-05', 'numero_protocollo' => 'USF-45', 'causale' => 'fattura',
        'tipo_movimento' => 'fattura_acquisto', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    $voce = $s->voci['Riparazione colonna']['conto']->id;
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $sc, 'conto_contabile_id' => $costi, 'voce_spesa_id' => $voce, 'tipo_riga' => 'dare', 'importo' => 180000, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $sc, 'conto_contabile_id' => $debiti, 'voce_spesa_id' => null, 'tipo_riga' => 'avere', 'importo' => 180000, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('fattura_scrittura')->insert(['fattura_passiva_id' => $fattura->id, 'scrittura_contabile_id' => $sc, 'importo_allocato' => 180000, 'tipo' => 'competenza',
        'created_at' => now(), 'updated_at' => now()]);
}

/** Il conguaglio dell'opera: la gestione nel consuntivo per persona, e quanto tocca a ognuno della voce. @return array{0: array<string, mixed>, 1: array<string, int>} */
function usfConguaglio(Beta51Scenario $s): array
{
    $letto = (new ConsuntivoPerPersona())->perEsercizio($s->e->fresh());
    $g = $s->gestioni['Colonna montante 2026']['g']->id;
    $voce = $s->voci['Riparazione colonna']['conto']->id;
    $diChi = fn (string $chi) => (int) collect($letto['persone'][$g][$s->persona($chi)->id] ?? [])->sum(fn ($r) => (int) ($r['voci'][$voce] ?? 0));

    return [$letto['gestioni'][$g], ['rossi' => $diChi('rossi'), 'verdi' => $diChi('verdi'), 'bianchi' => $diChi('bianchi')]];
}

it('la richiesta: «Urgenza» sulla scheda delle fatture di una straordinaria chiede il giorno dei lavori, e il piano lo scrive nella sua colonna', function () {
    $s = usfColonna($this);
    $fattura = usfFattura($s);
    usfPiano($this, $s, $fattura, ['genera_subito' => false])
        ->assertSessionHasErrors(['giorno_dei_lavori' => 'Scrivi il giorno dei lavori urgenti: decide chi paga, chi era titolare dell\'unità quel giorno.']);
    usfPiano($this, $s, $fattura, ['giorno_dei_lavori' => '02/03/2026', 'genera_subito' => false])
        ->assertSessionHasErrors(['giorno_dei_lavori' => 'Scegli il giorno dei lavori urgenti.']);
    expect(PianoRate::query()->count())->toBe(0);

    usfPiano($this, $s, $fattura, ['giorno_dei_lavori' => '2026-03-02', 'genera_subito' => false])->assertSessionHasNoErrors();
    $piano = PianoRate::query()->sole();
    expect($piano->stato)->toBe(StatoPianoRate::APPROVATO)
        ->and($piano->tipo)->toBe('straordinario')
        ->and($piano->tipo_autorizzazione)->toBe('urgenza')
        ->and($piano->data_delibera_assemblea)->toBeNull()
        ->and($piano->giorno_dei_lavori?->toDateString())->toBe('2026-03-02')
        ->and(GiornoDellUrgenza::delPiano($piano))->toBe('2026-03-02')
        // Il registro delle modifiche lo chiama con le parole della pagina.
        ->and(\App\Services\RegistroModifiche\Schede\PianoRateNelRegistro::ETICHETTE['giorno_dei_lavori'] ?? null)->toBe('Lavori ordinati il');

    // Il giorno nello stesso punto delle voci: la competenza del piano lo dà al motore come competenza dichiarata di un giorno.
    $esito = app(CompetenzaDelPiano::class)->perPiano($piano, $s->e);
    expect($esito->richiedeDelibera)->toBeFalse()
        ->and($esito->gradino?->value)->toBe('dichiarata')
        ->and($esito->periodi->toArray())->toBe([['dal' => '2026-03-02', 'al' => '2026-03-02']]);

    // La pagina del piano lo ricorda in testa, e dice che cosa fa con le fatture.
    $this->actingAs($s->admin)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s->c, $s->e, $piano]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('urgenza_del_piano.giorno', '02/03/2026')
            ->where('urgenza_del_piano.frase', 'Lavori urgenti (art. 1135 c. 2 c.c.): il piano è nato approvato, senza una delibera. '
                . 'La fattura senza periodo prende il giorno dei lavori; quella con il suo periodo tiene il suo.'));
});

it('senza la data della delibera la frase manda a «Urgenza» con il giorno dei lavori anche dalla scheda delle fatture', function () {
    $s = usfColonna($this);
    $r = $this->actingAs($s->admin)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'straordinario', 'gestione_id' => $s->gestioni['Colonna montante 2026']['g']->id, 'nome' => 'Colonna', 'metodo_distribuzione' => 'tutte_rate',
        'numero_rate' => 3, 'giorno_scadenza' => 30, 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale assemblea',
        'fatture_config' => [['id' => usfFattura($s)->id, 'importo' => '1.800,00']], 'capitoli_ids' => [], 'genera_subito' => false, 'recurrence_enabled' => false,
    ]);
    $r->assertUnprocessable()->assertJsonValidationErrors('data_delibera_assemblea');
    expect($r->json('errors.data_delibera_assemblea.0'))->toContain('Con un intervento d\'urgenza scegli «Urgenza» e scrivi il giorno dei lavori.')
        ->not->toContain('dichiara la competenza sulla fattura');
});

it('sulla gestione ordinaria («Scudo Legale») l\'urgenza delle fatture non chiede il giorno, e il piano non lo scrive', function () {
    $s = usfColonna($this, 'ordinaria');
    $fattura = usfFattura($s);
    usfPiano($this, $s, $fattura, ['genera_subito' => false, 'giorno_dei_lavori' => '2026-03-02'])->assertSessionHasNoErrors();
    expect(PianoRate::query()->sole()->giorno_dei_lavori)->toBeNull();
});

it('catena · la fattura n. 45 senza periodo → «Urgenza» con «Lavori ordinati il 02/03/2026» → le rate dell\'Int. 4 tutte a Rossi → il conguaglio dell\'opera con il giorno, scritto', function () {
    $s = usfColonna($this);
    $fattura = usfFattura($s);
    usfPiano($this, $s, $fattura, ['giorno_dei_lavori' => '2026-03-02'])->assertSessionHasNoErrors();
    $piano = PianoRate::query()->sole();
    $s->piani['p'] = ['p' => $piano, 'gestione' => 'Colonna montante 2026', 'tipo' => 'straordinario'];

    // La fattura senza periodo prende il giorno del piano: il 02/03 l'Int. 4 era di Rossi, che paga tutta la sua parte (600/1000 di € 1.800,00). Al centesimo.
    $somme = $s->somme('p', ['rossi', 'verdi', 'bianchi']);
    expect($somme)->toBe(['rossi' => 108000, 'verdi' => 0, 'bianchi' => 72000])
        ->and(array_sum($somme))->toBe(180000)
        ->and(DB::table('rate')->where('piano_rate_id', $piano->id)->count())->toBe(3)
        ->and(DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->distinct()->pluck('gradino_competenza')->all())->toBe(['dichiarata'])
        ->and(DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->get(['competenza_dal', 'competenza_al'])
            ->map(fn ($r) => substr((string) $r->competenza_dal, 0, 10) . '|' . substr((string) $r->competenza_al, 0, 10))->unique()->values()->all())
        ->toBe(['2026-03-02|2026-03-02']);

    // Il conguaglio dell'opera: la fattura pagata senza periodo si divide come le rate, e lo scrive.
    usfSulGiornale($s, $fattura);
    $this->travelTo('2027-01-10 10:00:00');
    [$gestione, $chi] = usfConguaglio($s);
    expect($gestione['non_calcolabile'])->toBeNull()
        ->and($gestione['giorno_dell_urgenza'])->toBe('Le spese senza periodo sulla fattura vanno a chi era titolare il 02/03/2026, il giorno dei lavori urgenti scritto nel piano «Colonna montante 2026 — rate», come le rate.')
        ->and($chi)->toBe(['rossi' => 108000, 'verdi' => 0, 'bianchi' => 72000]);
});

it('catena · la stessa con la fattura che dice il suo periodo: vale il periodo della fattura, nelle rate e nel conguaglio', function () {
    $s = usfColonna($this);
    $fattura = usfFattura($s, '2026-03-16', '2026-03-31');
    usfPiano($this, $s, $fattura, ['giorno_dei_lavori' => '2026-03-02'])->assertSessionHasNoErrors();
    $s->piani['p'] = ['p' => PianoRate::query()->sole(), 'gestione' => 'Colonna montante 2026', 'tipo' => 'straordinario'];
    expect($s->piano('p')->giorno_dei_lavori?->toDateString())->toBe('2026-03-02');

    // Dal 16 al 31 marzo l'Int. 4 è di Verdi: paga lui, anche se il piano dice lavori ordinati il 02/03.
    $somme = $s->somme('p', ['rossi', 'verdi', 'bianchi']);
    expect($somme)->toBe(['rossi' => 0, 'verdi' => 108000, 'bianchi' => 72000])
        ->and(array_sum($somme))->toBe(180000);

    usfSulGiornale($s, $fattura);
    $this->travelTo('2027-01-10 10:00:00');
    [$gestione, $chi] = usfConguaglio($s);
    expect($gestione['non_calcolabile'])->toBeNull()
        ->and($chi)->toBe(['rossi' => 0, 'verdi' => 108000, 'bianchi' => 72000]);
});

it('il piano d\'urgenza delle fatture senza il giorno (scritto prima della colonna) si ferma ancora sulla fattura senza periodo, e lo dice', function () {
    $s = usfColonna($this);
    $fattura = usfFattura($s);
    $piano = PianoRate::create(['gestione_id' => $s->gestioni['Colonna montante 2026']['g']->id, 'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id,
        'nome' => 'Colonna di prima', 'stato' => 'approvato', 'tipo' => 'straordinario', 'numero_rate' => 1, 'giorno_scadenza' => 30,
        'data_prima_scadenza' => '2026-04-30', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false, 'tipo_autorizzazione' => 'urgenza',
        'motivazione_autorizzazione' => 'Rottura']);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 180000]);

    expect(fn () => app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s->e))
        ->toThrow(\App\Exceptions\Gestionale\RichiedeDeliberaException::class, 'la fattura 45 non dichiara un periodo di competenza');
});

it('catena · il piano d\'urgenza fatto dalle fatture → la fattura n. 52 registrata dopo sulla stessa voce: la pagina della fattura nuova propone «Lavori ordinati il 02/03/2026»', function () {
    $s = usfColonna($this);
    usfPiano($this, $s, usfFattura($s), ['giorno_dei_lavori' => '2026-03-02', 'genera_subito' => false])->assertSessionHasNoErrors();
    $piano = PianoRate::query()->sole();
    $voce = $s->voci['Riparazione colonna']['conto']->id;
    // Il piano delle fatture non scrive la competenza sulle voci: il giorno sta solo nella sua colonna.
    expect(DB::table('competenze_capitolo')->count())->toBe(0);

    // Il 20/04 Andrea registra la fattura n. 52 di Idraulica Bianchi sulla stessa voce: la pagina la trova proposta con il giorno del piano.
    $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.create', $s->c))->assertOk()
        ->assertInertia(function (AssertableInertia $p) use ($voce, $piano) {
            $conto = collect($p->toArray()['props']['conti'])->firstWhere('id', $voce);
            expect($conto['competenza_piano'])->toBe(['piano_rate_id' => $piano->id, 'piano' => 'Colonna montante 2026 — rate',
                'tratti' => [['dal' => '2026-03-02', 'al' => '2026-03-02']], 'urgenza' => true]);
        });
});

it('un piano delle fatture che non è d\'urgenza, o d\'urgenza senza il giorno, non propone niente alla fattura dopo', function () {
    $s = usfColonna($this);
    $fattura = usfFattura($s);
    $piano = PianoRate::create(['gestione_id' => $s->gestioni['Colonna montante 2026']['g']->id, 'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id,
        'nome' => 'Colonna di prima', 'stato' => 'approvato', 'tipo' => 'straordinario', 'numero_rate' => 1, 'giorno_scadenza' => 30,
        'data_prima_scadenza' => '2026-04-30', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false, 'tipo_autorizzazione' => 'urgenza',
        'motivazione_autorizzazione' => 'Rottura']);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 180000]);
    $voce = $s->voci['Riparazione colonna']['conto']->id;

    $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.create', $s->c))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => expect(collect($p->toArray()['props']['conti'])->firstWhere('id', $voce)['competenza_piano'])->toBeNull());
});
