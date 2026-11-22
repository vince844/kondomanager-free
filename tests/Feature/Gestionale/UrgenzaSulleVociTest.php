<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\StatoPianoRate;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\User;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Gestionale\ConsuntivoPerPersona;
use App\Services\Riparto\GiornoDellUrgenza;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/Beta51PianoRateScenario.php';
require_once __DIR__ . '/GestionaleTestHelpers.php';
require_once __DIR__ . '/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

/**
 * Coda 688, «Urgenza» sulle voci di una gestione straordinaria (la scena 13 e la proposta 17 della bozza, lette con un giorno solo: decisione 623 (2)–(4)).
 * Il caso, spostato nell'esercizio 2026 delle prove: martedì 02/03/2026 si rompe la colonna montante; Andrea apre la gestione straordinaria «Colonna montante
 * 2026» con la voce «Riparazione colonna» da € 1.800,00 (tabella «Proprietà»: l'Int. 4 600 millesimi, l'Int. 7 400), e il 10/03 fa il piano in tre rate da
 * aprile. Il 15/03 Rossi Mario vende l'Int. 4 a Verdi Luca.
 *
 * - **La richiesta**: «Urgenza» sulle voci chiede il motivo e il giorno («Lavori ordinati il …»); il piano nasce approvato, ricorda «Urgenza» e il motivo
 *   (il difetto D-0: il motivo che spariva), e scrive il giorno su ogni voce. Il rifiuto provvisorio «Con l'urgenza il piano si fa dalla scheda delle fatture»
 *   non c'è più.
 * - **Il giorno decide chi paga**: con il 02/03 Rossi paga tutta la parte dell'Int. 4, Verdi niente; con un giorno dopo la vendita, il contrario. Nessuna
 *   divisione a giorni. **Le cifre in centesimi interi**: la somma delle quote è la voce, al centesimo.
 * - **Le catene**: la fattura n. 45 registrata dopo sulla voce trova il giorno del piano proposto; la prossima assemblea propone «Comunicazione dei lavori
 *   urgenti …»; la pagina del piano lo ricorda in testa. E la fattura pagata senza periodo: il conguaglio dell'opera la divide al giorno del piano, e lo scrive.
 *
 * Che cosa non copre: il riquadro a video (`LaDeliberaOLUrgenza.test.ts`); il piano d'urgenza delle fatture (com'era: il periodo lo dice ogni fattura).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->withoutVite();
});

/** La colonna montante: la gestione straordinaria con la voce, Rossi sull'Int. 4 fino al 14/03 e Verdi dal 15/03, Bianchi sull'Int. 7. */
function usvColonna($t, string $nome = 'Colonna montante 2026'): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($t);
    $s->tabella('Proprietà', ['4' => 600, '7' => 400])->titolare('4', 'rossi')->titolare('7', 'bianchi')
        ->gestione($nome, 'straordinaria')->voce($nome, 'Riparazione colonna', 180000, ['Proprietà' => 100]);
    usvVendita($s);

    return $s;
}

/** La vendita del 15/03/2026: Rossi esce dall'Int. 4, entra Verdi. */
function usvVendita(Beta51Scenario $s): void
{
    DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['4']->id)->where('anagrafica_id', $s->persona('rossi')->id)
        ->update(['data_fine' => '2026-03-14']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s->persona('verdi')->id, 'immobile_id' => $s->unita['4']->id, 'tipologia' => 'proprietario',
        'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-03-15', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/** Il nuovo piano dalla scheda delle voci, dalla rotta vera. @param array<string, mixed> $piu */
function usvPiano($t, Beta51Scenario $s, string $gestione, array $piu = [])
{
    return $t->actingAs($s->admin)->from('/nuovo')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), $piu + [
        'tipo' => 'ordinario', 'gestione_id' => $s->gestioni[$gestione]['g']->id, 'nome' => $gestione . ' — rate', 'metodo_distribuzione' => 'tutte_rate',
        'numero_rate' => 3, 'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-04-30', 'genera_subito' => true, 'recurrence_enabled' => false,
        'tipo_autorizzazione' => 'urgenza', 'motivazione_autorizzazione' => 'Rottura della colonna montante dell\'acqua il 02/03/2026, con perdite nelle cantine.',
        'capitoli_config' => [['id' => $s->voci['Riparazione colonna']['conto']->id, 'importo' => '1.800,00', 'note' => '']], 'capitoli_ids' => [],
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: la vendita del 15/03 nella prova',
    ]);
}

it('la richiesta: «Urgenza» sulle voci chiede il motivo e il giorno; con tutti e due il piano nasce approvato e ricorda l\'urgenza, il motivo e il giorno su ogni voce', function () {
    $s = usvColonna($this);
    usvPiano($this, $s, 'Colonna montante 2026', ['motivazione_autorizzazione' => '', 'genera_subito' => false])
        ->assertSessionHasErrors(['motivazione_autorizzazione', 'giorno_dei_lavori' => 'Scrivi il giorno dei lavori urgenti: decide chi paga, chi era titolare dell\'unità quel giorno.'])
        ->assertSessionDoesntHaveErrors('tipo_autorizzazione');
    usvPiano($this, $s, 'Colonna montante 2026', ['giorno_dei_lavori' => '02/03/2026', 'genera_subito' => false])
        ->assertSessionHasErrors(['giorno_dei_lavori' => 'Scegli il giorno dei lavori urgenti.']);
    expect(PianoRate::query()->count())->toBe(0);

    usvPiano($this, $s, 'Colonna montante 2026', ['giorno_dei_lavori' => '2026-03-02', 'genera_subito' => false])->assertSessionHasNoErrors();
    $piano = PianoRate::query()->sole();
    expect($piano->stato)->toBe(StatoPianoRate::APPROVATO)
        ->and($piano->tipo_autorizzazione)->toBe('urgenza')
        ->and($piano->motivazione_autorizzazione)->toBe('Rottura della colonna montante dell\'acqua il 02/03/2026, con perdite nelle cantine.')
        ->and($piano->data_delibera_assemblea)->toBeNull()
        ->and(GiornoDellUrgenza::delPiano($piano))->toBe('2026-03-02')
        ->and(DB::table('competenze_capitolo')->join('piano_rate_capitoli', 'piano_rate_capitoli.id', '=', 'competenze_capitolo.piano_rate_capitolo_id')
            ->where('piano_rate_id', $piano->id)->get(['dal', 'al'])->map(fn ($r) => [substr($r->dal, 0, 10), substr($r->al, 0, 10)])->all())
        ->toBe([['2026-03-02', '2026-03-02']]);

    // La pagina del piano lo ricorda in testa.
    $this->actingAs($s->admin)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s->c, $s->e, $piano]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('urgenza_del_piano.giorno', '02/03/2026')
            ->where('urgenza_del_piano.motivo', 'Rottura della colonna montante dell\'acqua il 02/03/2026, con perdite nelle cantine.'));
});

it('catena · l\'urgenza sulle voci → il piano approvato con il giorno → le rate di Rossi dopo la vendita → la fattura n. 45 con lo stesso giorno → la prossima assemblea', function () {
    $s = usvColonna($this);
    usvPiano($this, $s, 'Colonna montante 2026', ['giorno_dei_lavori' => '2026-03-02'])->assertSessionHasNoErrors();
    $piano = PianoRate::query()->sole();
    $s->piani['Colonna montante 2026 — rate'] = ['p' => $piano, 'gestione' => 'Colonna montante 2026', 'tipo' => 'ordinario'];

    // Il giorno decide chi paga: il 02/03 l'Int. 4 era di Rossi, che paga tutta la sua parte (600/1000 di € 1.800,00); Verdi niente. Al centesimo.
    $somme = $s->somme('Colonna montante 2026 — rate', ['rossi', 'verdi', 'bianchi']);
    expect($somme)->toBe(['rossi' => 108000, 'verdi' => 0, 'bianchi' => 72000])
        ->and(array_sum($somme))->toBe(180000)
        ->and(DB::table('rate')->where('piano_rate_id', $piano->id)->count())->toBe(3)
        ->and(DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->distinct()->pluck('gradino_competenza')->all())->toBe(['dichiarata']);

    // La fattura n. 45 registrata dopo sulla voce: la pagina la trova proposta con lo stesso giorno, dal piano d'urgenza.
    $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.create', $s->c))->assertOk()
        ->assertInertia(function (AssertableInertia $p) use ($s, $piano) {
            $voce = collect($p->toArray()['props']['conti'])->firstWhere('id', $s->voci['Riparazione colonna']['conto']->id);
            expect($voce['competenza_piano'])->toMatchArray(['piano_rate_id' => $piano->id, 'urgenza' => true, 'tratti' => [['dal' => '2026-03-02', 'al' => '2026-03-02']]]);
        });

    // La prossima assemblea propone di riferire i lavori urgenti.
    $proposte = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->flatMap(fn ($g) => $g['punti'])->pluck('testo')->all();
    expect($proposte)->toContain('Comunicazione dei lavori urgenti «Colonna montante 2026 — rate» e del loro riparto');
});

it('il giorno decide chi paga: con un giorno dopo la vendita paga Verdi; nessuna divisione a giorni', function () {
    $s = usvColonna($this);
    usvPiano($this, $s, 'Colonna montante 2026', ['giorno_dei_lavori' => '2026-03-20'])->assertSessionHasNoErrors();
    $s->piani['p'] = ['p' => PianoRate::query()->sole(), 'gestione' => 'Colonna montante 2026', 'tipo' => 'ordinario'];
    expect($s->somme('p', ['rossi', 'verdi', 'bianchi']))->toBe(['rossi' => 0, 'verdi' => 108000, 'bianchi' => 72000]);
});

it('catena · la fattura pagata senza periodo → il conguaglio dell\'opera con il giorno del piano, che lo scrive', function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->travelTo('2026-03-10 10:00:00');
    $x = lcdScenario(['A' => ['neri', 500], 'B' => ['verdi', 500]], 2, 'tutte_rate');
    $g = Gestione::create(['condominio_id' => $x['c']->id, 'nome' => 'Colonna', 'tipo' => 'straordinaria', 'descrizione' => 'Colonna', 'attiva' => true,
        'data_inizio' => '2026-03-01', 'data_fine' => '2026-06-30']);
    legaAEsercizio($x['e'], $g->id);
    $pc = PianoConto::create(['condominio_id' => $x['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC Colonna']);
    $voce = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Riparazione colonna', 'tipo' => 'spesa', 'natura_spesa' => 'straordinaria', 'importo' => 180000]);
    $tabella = (int) DB::table('tabelle')->where('condominio_id', $x['c']->id)->value('id');
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $tabella, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $x['c']->id, 'esercizio_id' => $x['e']->id, 'nome' => 'Colonna — rate', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 3, 'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-04-30', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false,
        'tipo_autorizzazione' => 'urgenza', 'motivazione_autorizzazione' => 'Rottura della colonna montante']);
    $riga = DB::table('piano_rate_capitoli')->insertGetId(['piano_rate_id' => $piano->id, 'conto_id' => $voce->id, 'importo' => 180000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('competenze_capitolo')->insert(['piano_rate_capitolo_id' => $riga, 'dal' => '2026-03-02', 'al' => '2026-03-02', 'ordine' => 0, 'created_at' => now(), 'updated_at' => now()]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $x['e']);

    // La fattura n. 45, pagata senza scrivere il periodo: sul giornale della gestione, sulla voce.
    $costi = DB::table('conti_contabili')->insertGetId(['condominio_id' => $x['c']->id, 'codice' => '60.10', 'nome' => 'Costi', 'tipo' => 'costo', 'categoria' => 'costi',
        'created_at' => now(), 'updated_at' => now()]);
    $debiti = DB::table('conti_contabili')->insertGetId(['condominio_id' => $x['c']->id, 'codice' => '40.10', 'nome' => 'Debiti verso fornitori', 'tipo' => 'passivo',
        'ruolo' => 'debiti_fornitori', 'categoria' => 'debiti', 'created_at' => now(), 'updated_at' => now()]);
    $sc = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $x['c']->id, 'gestione_id' => $g->id, 'esercizio_id' => $x['e']->id,
        'data_registrazione' => '2026-03-20', 'data_competenza' => '2026-03-20', 'numero_protocollo' => 'USV-45', 'causale' => 'fattura', 'tipo_movimento' => 'fattura_acquisto',
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $sc, 'conto_contabile_id' => $costi, 'voce_spesa_id' => $voce->id, 'tipo_riga' => 'dare', 'importo' => 180000, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $sc, 'conto_contabile_id' => $debiti, 'voce_spesa_id' => null, 'tipo_riga' => 'avere', 'importo' => 180000, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->travelTo('2027-01-10 10:00:00');
    $letto = (new ConsuntivoPerPersona())->perEsercizio($x['e']->fresh());
    $gestione = $letto['gestioni'][$g->id];
    expect($gestione['non_calcolabile'])->toBeNull()
        ->and($gestione['uscite'][$voce->id])->toBe(180000)
        ->and($gestione['giorno_dell_urgenza'])->toBe('Le spese senza periodo sulla fattura vanno a chi era titolare il 02/03/2026, il giorno dei lavori urgenti scritto nel piano «Colonna — rate», come le rate.');
    // Divisa come le rate: metà a testa, al centesimo.
    $persone = $letto['persone'][$g->id];
    $diChi = fn (string $chi) => (int) collect($persone[$x['persone'][$chi]->id] ?? [])->sum(fn ($r) => (int) ($r['voci'][$voce->id] ?? 0));
    expect([$diChi('neri'), $diChi('verdi')])->toBe([90000, 90000]);
});

it('il piano d\'urgenza sulle voci senza il giorno dei lavori (importato, o scritto prima) si ferma alla generazione e dice di scriverlo', function () {
    $s = usvColonna($this);
    $g = $s->gestioni['Colonna montante 2026']['g'];
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'nome' => 'Colonna importata', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-04-30', 'metodo_distribuzione' => 'tutte_rate',
        'applica_saldi' => false, 'tipo_autorizzazione' => 'urgenza', 'motivazione_autorizzazione' => 'Rottura']);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $s->voci['Riparazione colonna']['conto']->id, 'importo' => 180000,
        'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s->e))
        ->toThrow(\App\Exceptions\Gestionale\RichiedeDeliberaException::class, 'Il piano «Colonna importata» è un intervento d\'urgenza (nessuna delibera da registrare) e non dice il giorno dei lavori.');
});
