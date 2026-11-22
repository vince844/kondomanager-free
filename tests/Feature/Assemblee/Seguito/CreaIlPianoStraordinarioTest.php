<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';

/**
 * «Crea il piano straordinario» (decisione 622 (1), la scena 2 della bozza approvata): **prepara tutto, salvi tu**. Il punto «Portone: € 14.500,00 con
 * Serramenti Marino» approvato il 15/12/2026, la cosa da fare confermata; la finestra legge la proposta dalla delibera; confermata, nascono la gestione
 * straordinaria, il suo piano dei conti e la voce per le strade di oggi, e la gestione si lega alla delibera; il nuovo piano si apre con la delibera scelta e
 * nasce solo con «Salva il piano»: allora la cosa è fatta e il compito chiuso. Poi il fondo speciale: € 9.000,00 chiesti su € 14.500,00 deliberati, la riga in
 * testa al piano; il secondo piano di giugno porta i piani ai lavori e la riga sparisce.
 *
 * Che cosa non copre: la finestra a video (`CreaIlPianoStraordinario.test.ts`); il passo 6 che la monta.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Via Roma 12 con la tabella «Proprietà», due unità, l'esercizio 2027 aperto, e il portone approvato su carta il 15/12/2026 con la cosa confermata. */
function cpsPortone($t): array
{
    $s = ViaRoma12::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'anna');
    $e27 = Esercizio::factory()->create(['condominio_id' => $s->c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31',
        'stato' => 'aperto']);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino, fondo speciale', 'materia' => 'lavori', 'importo' => 1450000]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':cosa:crea_piano_straordinario';
    SeguitoDiViaRoma12::conferma($t, $s, $a, null, ['cose' => [$chiave => ['entro_il' => '2027-01-15', 'a_chi' => 'me']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::CREA_PIANO_STRAORDINARIO->value)->firstOrFail();

    return ['s' => $s, 'a' => $a, 'd' => $d, 'cosa' => $cosa, 'e27' => $e27];
}

/** I campi della finestra confermata, come li manda la pagina. @param array<string, mixed> $piu @return array<string, mixed> */
function cpsFinestra(array $x, array $piu = []): array
{
    return $piu + ['gestione_nome' => 'Portone d\'ingresso 2027', 'esercizio_id' => $x['e27']->id, 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31',
        'voce_nome' => 'Sostituzione del portone', 'importo' => '14.500,00', 'tabella_id' => $x['s']->tabelle['Proprietà']->id];
}

it('catena · la cosa «Crea il piano straordinario» → la finestra → la gestione, il piano dei conti e la voce → il piano compilato → «Salva il piano» → la cosa fatta e il compito chiuso', function () {
    $x = cpsPortone($this);
    ['s' => $s, 'a' => $a, 'd' => $d, 'cosa' => $cosa] = $x;
    $p = ['condominio' => $s->c->id, 'assemblea' => $a->id, 'cosa' => $cosa->id];

    // La finestra: il nome, la voce e l'importo presi dalla delibera; la tabella «Proprietà»; l'esercizio aperto che contiene il giorno «entro il» della cosa.
    $proposta = $this->actingAs($s->admin)->getJson(route('admin.gestionale.assemblee.seguito.piano-straordinario.proposta', $p))->assertOk()->json();
    expect($proposta['delibera']['frase'])->toBe('Dalla delibera del 15/12/2026, punto 1 (verbale da caricare): «Portone: € 14.500,00 con Serramenti Marino, fondo speciale».')
        ->and($proposta['delibera']['importo'])->toBe(1450000)
        ->and($proposta['gestione']['nome'])->toBe('Portone 2027')
        ->and($proposta['gestione']['esercizio_id'])->toBe($x['e27']->id)
        ->and(array_column($proposta['esercizi'], 'nome'))->toBe(['Esercizio 2026', 'Esercizio 2027'])
        ->and($proposta['voce'])->toMatchArray(['nome' => 'Portone', 'importo' => 1450000, 'tabella_id' => $s->tabelle['Proprietà']->id])
        ->and($proposta['gestione_esistente'])->toBeNull();

    // Confermata: nascono la gestione, il suo piano dei conti e la voce; il piano no.
    $esito = $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.piano-straordinario', $p), cpsFinestra($x))->assertOk()->json();
    $gestione = Gestione::query()->where('condominio_id', $s->c->id)->where('nome', 'Portone d\'ingresso 2027')->sole();
    $pc = PianoConto::query()->where('gestione_id', $gestione->id)->sole();
    $voce = Conto::query()->where('piano_conto_id', $pc->id)->sole();
    expect($gestione->tipo)->toBe('straordinaria')
        ->and($gestione->data_inizio->format('Y-m-d'))->toBe('2027-01-01')
        ->and(DB::table('esercizio_gestione')->where('gestione_id', $gestione->id)->pluck('esercizio_id')->all())->toBe([$x['e27']->id])
        // L'importo da euro a centesimi una volta sola, al confine: 14.500,00 → 1450000.
        ->and((int) $voce->importo)->toBe(1450000)
        ->and($voce->nome)->toBe('Sostituzione del portone')
        ->and(DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->value('tabella_id'))->toBe($s->tabelle['Proprietà']->id)
        ->and(DB::table('conto_tabella_ripartizioni')->join('conto_tabella_millesimale', 'conto_tabella_millesimale.id', '=', 'conto_tabella_ripartizioni.conto_tabella_millesimale_id')
            ->where('conto_id', $voce->id)->pluck('percentuale', 'soggetto')->map(fn ($v) => (float) $v)->all())->toBe(['proprietario' => 100.0])
        ->and((int) CollegamentoAllaDelibera::query()->where('oggetto_tipo', 'gestione')->where('oggetto_id', $gestione->id)->where('ruolo', 'delibera')->value('delibera_id'))
        ->toBe($d->id)
        ->and(PianoRate::query()->where('gestione_id', $gestione->id)->count())->toBe(0)
        ->and($cosa->fresh()->stato)->toBe(StatoCosaDaFare::DA_FARE)
        ->and($esito['url'])->toBe(route('admin.gestionale.esercizi.piani-rate.create', ['condominio' => $s->c->id, 'esercizio' => $x['e27']->id,
            'gestione_id' => $gestione->id, 'conto_id' => $voce->id, 'delibera_id' => $d->id], false));

    // Il nuovo piano si apre con la delibera scelta.
    $this->actingAs($s->admin)->get($esito['url'])->assertOk()
        ->assertInertia(fn (AssertableInertia $pg) => $pg->component('gestionale/pianiRate/PianiRateNew')->where('deliberaProposta.id', $d->id)
            ->where('deliberaProposta.deliberata_il', '2026-12-15'));

    // «Salva il piano» per la strada di oggi, con la delibera: la cosa è fatta, il compito chiuso.
    $this->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $x['e27']]), [
        'tipo' => 'ordinario', 'gestione_id' => $gestione->id, 'nome' => 'Portone d\'ingresso 2027 — rate', 'metodo_distribuzione' => 'tutte_rate',
        'numero_rate' => 1, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2027-01-31', 'genera_subito' => 0, 'delibera_id' => $d->id,
        'capitoli_config' => [['id' => $voce->id, 'importo' => '9.000,00']],
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('gestione_id', $gestione->id)->sole();
    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and($cosa->fresh()->oggetto_id)->toBe($piano->id)
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue();

    // Una seconda volta la finestra non crea un'altra gestione: porta al piano di quella legata.
    $cosa->fresh()->forceFill(['stato' => StatoCosaDaFare::DA_FARE->value])->saveQuietly();
    $di = $this->actingAs($s->admin)->getJson(route('admin.gestionale.assemblee.seguito.piano-straordinario.proposta', $p))->json('gestione_esistente');
    expect($di['nome'])->toBe('Portone d\'ingresso 2027');
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.piano-straordinario', $p), cpsFinestra($x, ['gestione_nome' => 'Altro']))
        ->assertOk()->assertJson(['creata' => false, 'gestione_id' => $gestione->id]);
    expect(Gestione::query()->where('condominio_id', $s->c->id)->where('tipo', 'straordinaria')->count())->toBe(1);
});

it('catena · il piano chiede € 9.000,00 su € 14.500,00 deliberati → la riga arancio in testa al piano → il secondo piano di giugno → la riga sparita', function () {
    $x = cpsPortone($this);
    ['s' => $s, 'a' => $a, 'd' => $d, 'cosa' => $cosa] = $x;
    $esito = $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.piano-straordinario',
        ['condominio' => $s->c->id, 'assemblea' => $a->id, 'cosa' => $cosa->id]), cpsFinestra($x))->assertOk()->json();
    $gestione = Gestione::query()->where('condominio_id', $s->c->id)->where('tipo', 'straordinaria')->sole();
    $voce = Conto::query()->whereIn('piano_conto_id', PianoConto::query()->where('gestione_id', $gestione->id)->select('id'))->sole();
    $salva = fn (string $nome, string $importo, string $prima, ?int $delibera) => $this->actingAs($s->admin)->from('/crea')
        ->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $x['e27']]), array_filter(['tipo' => 'ordinario', 'gestione_id' => $gestione->id,
            'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 1, 'giorno_scadenza' => 28, 'data_prima_scadenza' => $prima,
            'genera_subito' => 0, 'delibera_id' => $delibera, 'capitoli_config' => [['id' => $voce->id, 'importo' => $importo]]], fn ($v) => $v !== null))
        ->assertSessionHasNoErrors();
    $salva('Portone d\'ingresso 2027 — rate', '9.000,00', '2027-01-31', $d->id);
    $gennaio = PianoRate::query()->where('nome', 'Portone d\'ingresso 2027 — rate')->sole();
    $pagina = fn (PianoRate $p) => $this->actingAs($s->admin)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s->c, $x['e27'], $p]))->assertOk();

    $pagina($gennaio)->assertInertia(fn (AssertableInertia $pg) => $pg->where('fondo_speciale.frase', 'Fondo speciale: € 9.000,00 chiesti su € 14.500,00 deliberati.')
        ->where('fondo_speciale.chiesti', 900000)->where('fondo_speciale.deliberati', 1450000)
        ->where('fondo_speciale.legge', \App\Services\Assemblee\Seguito\Effetti\SuiLavori::LEGGE_DEL_FONDO));
    expect($esito['creata'])->toBeTrue();

    // A giugno il secondo piano, sulla stessa gestione, con la delibera della gestione che «Scegli la delibera» propone: i piani arrivano ai lavori, e la
    // riga sparisce da sola, nel primo piano e nel secondo.
    $salva('Portone, il saldo', '5.500,00', '2027-06-30', $d->id);
    $pagina($gennaio->fresh())->assertInertia(fn (AssertableInertia $pg) => $pg->where('fondo_speciale', null));
    $pagina(PianoRate::query()->where('nome', 'Portone, il saldo')->sole())->assertInertia(fn (AssertableInertia $pg) => $pg->where('fondo_speciale', null));
});

it('la finestra: l\'importo si scrive in euro e diventa centesimi una volta sola; i campi mancanti si dicono sotto il campo; «Vede» non la apre, un altro condominio è un 404', function () {
    $x = cpsPortone($this);
    ['s' => $s, 'a' => $a, 'cosa' => $cosa] = $x;
    $p = ['condominio' => $s->c->id, 'assemblea' => $a->id, 'cosa' => $cosa->id];
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.piano-straordinario', $p), cpsFinestra($x, ['importo' => 'quattordicimila', 'voce_nome' => '']))
        ->assertStatus(422)->assertJsonValidationErrors(['importo' => 'L\'importo si scrive così: 14.500,00.', 'voce_nome' => 'Scrivi il nome della voce.']);
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.piano-straordinario', $p), cpsFinestra($x, ['importo' => '12.345,67']))->assertOk();
    expect((int) Conto::query()->where('nome', 'Sostituzione del portone')->value('importo'))->toBe(1234567);

    $vede = apUtente(null, [\App\Enums\Permission::VIEW_ASSEMBLEE]);
    $this->actingAs($vede)->getJson(route('admin.gestionale.assemblee.seguito.piano-straordinario.proposta', $p))->assertForbidden();
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $this->actingAs($s->admin)->getJson(route('admin.gestionale.assemblee.seguito.piano-straordinario.proposta', ['condominio' => $altro->c->id] + $p))->assertNotFound();
});
