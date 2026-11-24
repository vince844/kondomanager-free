<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «L'esercizio aperto», con due esercizi aperti, è il più recente anche fuori da `HasEsercizio` (Coda 646, decisione 707 (4)).
 *
 * Il caso. Dopo un'importazione restano aperti due esercizi (il 2025, che l'amministratore non ha ancora chiuso, e il 2026 importato).
 * La tappa 5 ha dato a `HasEsercizio` la regola di Vincenzo, «nel più recente», e l'anteprima dell'importazione la annuncia: incassi,
 * pagamenti e saldi da lì in poi vanno nel 2026. Ma altre strade cercavano «l'esercizio aperto» per conto loro: il più vecchio (il
 * saldo d'apertura di una cassa nuova, il riallineamento dei fondi, la finestra della solidarietà, gli accessori degli eredi, il compito
 * del deficit ereditato), l'ultimo scritto (le deleghe F24), il primo che il database restituisce (gli storni di un incasso da terzi, di
 * un giroconto e di una regolazione immediata di un esercizio chiuso), e fra quelli che contengono una data il più vecchio (gli
 * interessi, il piano straordinario dalla delibera). Lo stesso condominio, due risposte.
 *
 * Adesso tutte chiedono la regola sola, `HasEsercizio::esercizioApertoPiuRecente()` (fra quelli che contengono la data, il più recente).
 * Le regole private si chiamano per nome, come le chiama il codice; le altre dalle loro azioni.
 */

use App\Actions\Cassa\RegistraAperturaCassaAction;
use App\Actions\Gestionale\Movimenti\StornaGirocontoAction;
use App\Actions\Gestionale\Movimenti\StornaIncassoDaTerziAction;
use App\Actions\Gestionale\Movimenti\StornaRegolazioneImmediataAction;
use App\Enums\NaturaStorno;
use App\Enums\TipoMovimentoContabile;
use App\Http\Controllers\Gestionale\Casse\CassaController;
use App\Http\Controllers\Gestionale\Movimenti\DelegaF24Controller;
use App\Http\Controllers\Gestionale\Movimenti\InteressiController;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Assemblee\Seguito\PianoStraordinario;
use App\Services\CondominioService;
use App\Services\Gestionale\Accessori\PropostaInteressi;
use App\Services\Gestionale\RiallineaFondiService;
use App\Services\Subentro\AccessoriAgliEredi;
use App\Services\Subentro\NotaSolidarieta;
use App\Services\Treasury\DisponibilitaDiOggi;
use Illuminate\Support\Facades\DB;

/**
 * Un condominio con gli esercizi dati (nome → [dal, al, stato]), scritti nell'ordine dato, ognuno con la sua gestione ordinaria.
 *
 * @param  array<string, array{0: string, 1: string, 2: string}>  $esercizi
 * @return array{c: Condominio, e: array<string, Esercizio>, g: array<string, int>}
 */
function b14epCondominio(array $esercizi): array
{
    $c = Condominio::factory()->create();
    app(CondominioService::class)->ensureDefaultConti($c);
    $e = [];
    $g = [];
    foreach ($esercizi as $nome => [$dal, $al, $stato]) {
        $e[$nome] = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => $nome, 'data_inizio' => $dal, 'data_fine' => $al, 'stato' => $stato]);
        $g[$nome] = DB::table('gestioni')->insertGetId(['condominio_id' => $c->id, 'nome' => 'Ordinaria '.$nome, 'tipo' => 'ordinaria', 'attiva' => true,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('esercizio_gestione')->insert(['esercizio_id' => $e[$nome]->id, 'gestione_id' => $g[$nome], 'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    return ['c' => $c, 'e' => $e, 'g' => $g];
}

/** Il 2024 chiuso, il 2025 ancora aperto (scritto prima) e il 2026 aperto, come li lascia l'importazione del 2026. */
function b14epDueAperti(): array
{
    return b14epCondominio([
        '2024' => ['2024-01-01', '2024-12-31', 'chiuso'],
        '2025' => ['2025-01-01', '2025-12-31', 'aperto'],
        '2026' => ['2026-01-01', '2026-12-31', 'aperto'],
    ]);
}

/** Due esercizi aperti che contengono tutti e due il marzo 2026: il «2025/2026» (luglio–giugno, scritto prima) e il «2026» solare. */
function b14epAccavallati(): array
{
    return b14epCondominio([
        '2025/2026' => ['2025-07-01', '2026-06-30', 'aperto'],
        '2026' => ['2026-01-01', '2026-12-31', 'aperto'],
    ]);
}

/** Un conto di liquidità con la sua cassa. */
function b14epCassa(Condominio $c, string $tipo = 'banca', int $saldoIniziale = 0): Cassa
{
    $conto = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '1010.'.uniqid(), 'nome' => 'Conto '.$tipo, 'tipo' => 'attivo',
        'categoria' => 'liquidita', 'ruolo' => $tipo === 'fondo' ? 'fondo_riserva' : 'conto_bancario']);

    return Cassa::create(['condominio_id' => $c->id, 'nome' => ucfirst($tipo).' '.uniqid(), 'tipo' => $tipo, 'conto_contabile_id' => $conto->id,
        'saldo_iniziale' => $saldoIniziale, 'attiva' => true, 'sottotipo_fondo' => $tipo === 'fondo' ? 'generico' : null]);
}

/** Una scrittura di due righe, scritta nell'esercizio dato. */
function b14epScrittura(array $s, string $esercizio, TipoMovimentoContabile $tipo, int $dare, int $avere, int $importo = 10000): ScritturaContabile
{
    $sc = ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e'][$esercizio]->id, 'gestione_id' => $s['g'][$esercizio],
        'data_registrazione' => $s['e'][$esercizio]->data_inizio->format('Y-m-d'), 'data_competenza' => $s['e'][$esercizio]->data_inizio->format('Y-m-d'),
        'causale' => 'Prova '.$tipo->value, 'tipo_movimento' => $tipo, 'stato' => 'registrata']);
    $sc->righe()->create(['conto_contabile_id' => $dare, 'tipo_riga' => 'dare', 'importo' => $importo, 'note' => 'prova']);
    $sc->righe()->create(['conto_contabile_id' => $avere, 'tipo_riga' => 'avere', 'importo' => $importo, 'note' => 'prova']);

    return $sc->fresh();
}

function b14epMastro(Condominio $c, string $ruolo): int
{
    return (int) ContoContabile::where('condominio_id', $c->id)->where('ruolo', $ruolo)->value('id');
}

/** Chiama un metodo privato come lo chiama il codice. */
function b14epChiama(object|string $chi, string $metodo, mixed ...$argomenti): mixed
{
    return (new ReflectionMethod($chi, $metodo))->invoke(is_object($chi) ? $chi : null, ...$argomenti);
}

beforeEach(function () {
    $this->actingAs(\App\Models\User::factory()->create());
});

it('lo storno di un incasso da terzi, di un giroconto e di una regolazione immediata di un esercizio chiuso va nel più recente degli aperti', function () {
    $s = b14epDueAperti();
    $banca = b14epCassa($s['c']);

    $incasso = b14epScrittura($s, '2024', TipoMovimentoContabile::INCASSO_DIVERSO, $banca->conto_contabile_id, b14epMastro($s['c'], 'passate_gestioni'));
    $giroconto = b14epScrittura($s, '2024', TipoMovimentoContabile::GIROCONTO, $banca->conto_contabile_id, b14epCassa($s['c'], 'fondo')->conto_contabile_id);
    $regolazione = b14epScrittura($s, '2024', TipoMovimentoContabile::REGOLAZIONE_IMMEDIATA, b14epMastro($s['c'], 'passate_gestioni'), $banca->conto_contabile_id);

    $storni = [
        'incasso da terzi' => app(StornaIncassoDaTerziAction::class)->execute($incasso, $s['c'], NaturaStorno::ERRORE_REGISTRAZIONE, 'registrato due volte'),
        'giroconto' => app(StornaGirocontoAction::class)->execute($giroconto, $s['c'], 'sbagliato'),
        'regolazione immediata' => app(StornaRegolazioneImmediataAction::class)->execute($regolazione, $s['c'], 'sbagliata'),
    ];

    expect(array_map(fn (ScritturaContabile $st) => (int) $st->esercizio_id, $storni))->toBe([
        'incasso da terzi' => $s['e']['2026']->id, 'giroconto' => $s['e']['2026']->id, 'regolazione immediata' => $s['e']['2026']->id,
    ]);
});

it('il saldo d\'apertura di una cassa nuova: proposto il primo giorno del più recente, senza «Saldo al» scritto lì; il minimo resta il più vecchio aperto', function () {
    $s = b14epDueAperti();

    expect(b14epChiama(app(CassaController::class), 'ilGiornoDelSaldo', $s['c']))
        ->toBe(['saldo_al_predefinito' => '2026-01-01', 'saldo_al_minimo' => '2025-01-01']);

    $cassa = b14epCassa($s['c'], 'banca', 50000);
    app(RegistraAperturaCassaAction::class)->execute($cassa);
    $apertura = ScritturaContabile::where('condominio_id', $s['c']->id)->where('tipo_movimento', TipoMovimentoContabile::APERTURA->value)->firstOrFail();

    expect((int) $apertura->esercizio_id)->toBe($s['e']['2026']->id)
        ->and($apertura->data_competenza->format('Y-m-d'))->toBe('2026-01-01');
});

it('il riallineamento dei fondi scrive la rettifica nel più recente degli aperti', function () {
    $s = b14epDueAperti();
    $fondo = b14epCassa($s['c'], 'fondo', 100000);
    $storica = b14epScrittura($s, '2025', TipoMovimentoContabile::FATTURA_ACQUISTO, $fondo->conto_contabile_id, b14epMastro($s['c'], 'sopravvenienze_passive'), 30000);

    expect(app(RiallineaFondiService::class)->esegui($s['c'])['rettificate'])->toBe(1);

    $rettifica = ScritturaContabile::where('scrittura_padre_id', $storica->id)->where('tipo_movimento', 'rettifica')->firstOrFail();
    expect((int) $rettifica->esercizio_id)->toBe($s['e']['2026']->id);
});

it('la finestra della solidarietà parte dall\'esercizio prima del più recente; gli accessori degli eredi senza il piano vanno nel più recente', function () {
    $s = b14epDueAperti();

    expect(b14epChiama(app(NotaSolidarieta::class), 'inizioFinestra', $s['c'])?->format('Y-m-d'))->toBe('2025-01-01')
        ->and(b14epChiama(app(AccessoriAgliEredi::class), 'esercizio', new PianoRate(['esercizio_id' => null]), (int) $s['c']->id)?->id)->toBe($s['e']['2026']->id);
});

it('le deleghe F24 guardano il più recente degli aperti, anche quando il più vecchio è stato scritto dopo', function () {
    // L'esercizio vecchio scritto dopo: un'importazione dell'anno prima, fatta dopo aver aperto quello nuovo.
    $s = b14epCondominio(['2026' => ['2026-01-01', '2026-12-31', 'aperto'], '2025' => ['2025-01-01', '2025-12-31', 'aperto']]);

    expect(b14epChiama(app(DelegaF24Controller::class), 'esercizioCorrente', $s['c'])?->id)->toBe($s['e']['2026']->id);
});

it('il compito del deficit ereditato e le azioni della tesoreria leggono il più recente degli aperti, anche fuori da tutti e due', function () {
    $s = b14epDueAperti();
    $a = b14epAccavallati();

    expect(DisponibilitaDiOggi::esercizioDiOggi((int) $s['c']->id, '2027-02-01'))->toBe($s['e']['2026']->id)
        ->and(DisponibilitaDiOggi::esercizioDiOggi((int) $a['c']->id, '2026-03-01'))->toBe($a['e']['2026']->id);
});

it('fra due esercizi aperti che contengono la stessa data, gli interessi e il piano straordinario propongono il più recente', function () {
    $a = b14epAccavallati();
    $this->travelTo('2026-03-01 10:00:00');

    // La spesa degli interessi: la gestione proposta è l'ordinaria del più recente.
    expect(b14epChiama(app(InteressiController::class), 'doveDiOggi', $a['c'])['proposta'])->toBe($a['g']['2026']);

    // La proposta degli interessi per una gestione di un esercizio chiuso: si scrive nella sua sorella del più recente.
    $vecchia = DB::table('gestioni')->insertGetId(['condominio_id' => $a['c']->id, 'nome' => 'Ordinaria 2024', 'tipo' => 'ordinaria', 'attiva' => true,
        'created_at' => now(), 'updated_at' => now()]);
    $dove = b14epChiama(app(PropostaInteressi::class), 'doveSiScrive', $a['c'], '2026-03-01', [$vecchia]);
    expect($dove[0]['scrive']['esercizio_id'] ?? null)->toBe($a['e']['2026']->id);

    // Il piano straordinario dalla delibera: l'esercizio proposto per una scadenza di marzo.
    $esercizi = b14epChiama(PianoStraordinario::class, 'esercizi', $a['c']);
    expect(b14epChiama(PianoStraordinario::class, 'esercizioProposto', $esercizi, '2026-03-15')?->id)->toBe($a['e']['2026']->id);
});
