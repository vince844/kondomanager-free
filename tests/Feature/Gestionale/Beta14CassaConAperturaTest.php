<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 754 (1), decisione 696 (D3, «Elimina e storna»): la cassa con l'apertura a giornale.
 *
 * Via Roma: il conto corrente è stato aperto il 01/01/2026 con € 2.150,00 invece di € 2.100,00. Il saldo d'apertura registrato non si corregge dalla
 * modifica della cassa; la cassa con la sola apertura però si elimina, e prima l'apertura restava a giornale sul conto archiviato: eliminata e ricreata con
 * € 2.100,00, la liquidità diventava € 4.250,00 e il controllo «Liquidità e cassa» rosso.
 *
 * Ora eliminando la cassa il programma storna anche la sua apertura, nella stessa transazione: una scrittura uguale e contraria, figlia dell'apertura, con la
 * data di competenza dell'apertura e la registrazione di oggi; l'apertura resta, annullata. Tutte e due visibili nel libro giornale; a ogni data il conto
 * archiviato è a zero. Con l'apertura in un esercizio chiuso l'eliminazione si rifiuta e lo dice; con un movimento vero si rifiuta come prima.
 */

use App\Actions\Cassa\RegistraAperturaCassaAction;
use App\Enums\EsitoAperturaCassa;
use App\Models\Gestionale\Cassa;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

function b14caUtente(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $u = \App\Models\User::factory()->create();
    $u->givePermissionTo('Accesso pannello amministratore');

    return $u;
}

/** «Nuova risorsa» dalla rotta: il conto corrente con il saldo d'apertura al 01/01/2026, che va a giornale. */
function b14caCrea($test, $condominio, string $saldo): Cassa
{
    $test->post(route('admin.gestionale.casse.store', $condominio->id), [
        'nome' => 'Conto corrente', 'tipo' => 'banca', 'saldo_iniziale' => $saldo, 'saldo_al' => '2026-01-01',
    ])->assertSessionHasNoErrors();

    return Cassa::where('condominio_id', $condominio->id)->where('nome', 'Conto corrente')->latest('id')->firstOrFail();
}

/** Le scritture di apertura di un conto, con lo stato e il padre. */
function b14caAperture(int $contoId): \Illuminate\Support\Collection
{
    return DB::table('scritture_contabili as sc')->join('righe_scritture as rs', 'rs.scrittura_id', '=', 'sc.id')
        ->where('rs.conto_contabile_id', $contoId)->where('sc.tipo_movimento', 'apertura')
        ->orderBy('sc.id')->get(['sc.id', 'sc.stato', 'sc.scrittura_padre_id', 'sc.data_competenza', 'sc.data_registrazione', 'sc.esercizio_id', 'rs.tipo_riga', 'rs.importo']);
}

test('P14 rovesciata: elimina e ricrea con € 2.100,00, la liquidità è € 2.100,00 e «Liquidità e cassa» torna', function () {
    [$condominio, $esercizio] = setupContabile();
    $this->actingAs(b14caUtente());
    $sbagliata = b14caCrea($this, $condominio, '2.150,00');
    $contoArchiviato = (int) $sbagliata->conto_contabile_id;
    expect(b14caAperture($contoArchiviato))->toHaveCount(1);

    $this->delete(route('admin.gestionale.casse.destroy', [$condominio->id, $sbagliata->id]))->assertRedirect();
    expect(Cassa::find($sbagliata->id))->toBeNull()
        ->and(session('message')['type'] ?? null)->toBe('success')
        ->and(session('message')['message'] ?? '')->toContain('La sua apertura di € 2.150,00 del 01/01/2026 è stornata nel libro giornale');

    b14caCrea($this, $condominio, '2.100,00');

    // La catena: lo Stato patrimoniale al 15/01/2026, la liquidità e il controllo R2.
    $pagina = app(StatoPatrimonialePaginaService::class)->costruisci($condominio->fresh(), $esercizio, '2026-01-15');
    $r2 = collect($pagina['controlli'])->firstWhere('id', 'R2');
    expect($pagina['sintesi']['liquidita'])->toBe(210000)
        ->and($r2['esito'])->toBe('quadra')
        ->and($pagina['situazione']['quadra'])->toBeTrue();
    // Il conto archiviato è a zero alla data e prima: anche lo Stato patrimoniale che conta sempre le aperture (la tesoreria).
    $saldoArchiviato = fn (array $foto) => (int) (collect($foto['attivo']['voci'])->firstWhere('id', $contoArchiviato)['saldo'] ?? 0);
    expect($saldoArchiviato(app(StatoPatrimonialeService::class)->calcola($condominio->fresh(), allaData: '2026-01-15')))->toBe(0)
        ->and($saldoArchiviato(app(StatoPatrimonialeService::class)->calcola($condominio->fresh(), allaData: '2025-12-31', apertureSempre: true)))->toBe(0);
});

test('il libro giornale ha l\'apertura annullata e il suo storno, tutte e due visibili, e lo storno è lo specchio dell\'apertura', function () {
    [$condominio, $esercizio] = setupContabile();
    $this->actingAs(b14caUtente());
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-03-10 10:00', 'Europe/Rome'));
    $cassa = b14caCrea($this, $condominio, '2.150,00');
    $contoId = (int) $cassa->conto_contabile_id;

    $this->delete(route('admin.gestionale.casse.destroy', [$condominio->id, $cassa->id]))->assertRedirect();

    $aperture = b14caAperture($contoId);
    expect($aperture)->toHaveCount(2);
    [$apertura, $storno] = [$aperture[0], $aperture[1]];
    expect($apertura->stato)->toBe('annullata')
        ->and((int) $storno->scrittura_padre_id)->toBe((int) $apertura->id)
        ->and($storno->stato)->toBe('registrata')
        ->and(substr((string) $storno->data_competenza, 0, 10))->toBe('2026-01-01')
        ->and(substr((string) $storno->data_registrazione, 0, 10))->toBe('2026-03-10')
        ->and((int) $storno->esercizio_id)->toBe((int) $esercizio->id)
        ->and([$apertura->tipo_riga, (int) $apertura->importo])->toBe(['dare', 215000])
        ->and([$storno->tipo_riga, (int) $storno->importo])->toBe(['avere', 215000]);
    // Anche la contropartita torna a zero: «Fondo Passate Gestioni» ha l'avere dell'apertura e il dare dello storno.
    $passate = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->value('id');
    $righePassate = DB::table('righe_scritture')->where('conto_contabile_id', $passate)->whereIn('scrittura_id', [$apertura->id, $storno->id])
        ->orderBy('scrittura_id')->get(['tipo_riga', 'importo'])->map(fn ($r) => [$r->tipo_riga, (int) $r->importo])->all();
    expect($righePassate)->toBe([['avere', 215000], ['dare', 215000]]);

    // Dalla rotta del libro giornale: le due scritture ci sono, con il loro stato.
    $this->withoutVite()->get(route('admin.gestionale.esercizi.scritture.index', [$condominio->id, $esercizio->id]))
        ->assertInertia(fn ($p) => $p->where('scritture.data', fn ($righe) => collect($righe)->where('tipo_movimento', 'apertura')->pluck('stato')->sort()->values()->all()
            === ['annullata', 'registrata'])
            ->where('quadratura.quadra', true));
});

test('con l\'apertura in un esercizio chiuso l\'eliminazione si rifiuta, lo dice, e non scrive niente', function () {
    [$condominio, $esercizio] = setupContabile();
    $this->actingAs(b14caUtente());
    $cassa = b14caCrea($this, $condominio, '2.150,00');
    $esercizio->update(['stato' => 'chiuso']);
    $prima = [DB::table('scritture_contabili')->count(), DB::table('righe_scritture')->count()];

    $this->delete(route('admin.gestionale.casse.destroy', [$condominio->id, $cassa->id]))->assertRedirect();

    expect(Cassa::find($cassa->id))->not->toBeNull()
        ->and(session('message')['type'] ?? null)->toBe('error')
        ->and(session('message')['message'] ?? '')->toContain('che è chiuso')
        ->and($cassa->fresh()->motivoBloccoEliminazione())->toContain('disattivala');
    expect([DB::table('scritture_contabili')->count(), DB::table('righe_scritture')->count()])->toBe($prima)
        ->and(b14caAperture((int) $cassa->conto_contabile_id)->pluck('stato')->all())->toBe(['registrata']);
});

test('la cassa con un movimento operativo si rifiuta come prima, e la sua apertura resta', function () {
    [$condominio, $esercizio] = setupContabile();
    $this->actingAs(b14caUtente());
    $cassa = b14caCrea($this, $condominio, '2.150,00');
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'data_registrazione' => '2026-02-01',
        'data_competenza' => '2026-02-01', 'numero_protocollo' => 'MOV-1', 'causale' => 'Bonifico', 'tipo_movimento' => 'incasso_diverso', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert(['scrittura_id' => $id, 'conto_contabile_id' => $cassa->conto_contabile_id, 'cassa_id' => $cassa->id, 'tipo_riga' => 'dare',
        'importo' => 5000, 'created_at' => now(), 'updated_at' => now()]);

    $this->delete(route('admin.gestionale.casse.destroy', [$condominio->id, $cassa->id]))->assertRedirect();

    expect(Cassa::find($cassa->id))->not->toBeNull()
        ->and(session('message')['message'] ?? '')->toContain('movimenti contabili registrati')
        ->and(b14caAperture((int) $cassa->conto_contabile_id)->pluck('stato')->all())->toBe(['registrata']);
});

test('un\'apertura annullata conta come assente: sullo stesso conto l\'apertura si registra di nuovo', function () {
    [$condominio] = setupContabile();
    $this->actingAs(b14caUtente());
    $vecchia = b14caCrea($this, $condominio, '2.150,00');
    $contoId = (int) $vecchia->conto_contabile_id;
    $this->delete(route('admin.gestionale.casse.destroy', [$condominio->id, $vecchia->id]))->assertRedirect();

    // Una cassa sul conto archiviato (come un riaggancio): l'apertura stornata non c'è più, e la nuova si scrive.
    $riagganciata = Cassa::create(['condominio_id' => $condominio->id, 'nome' => 'Conto corrente', 'tipo' => 'banca', 'conto_contabile_id' => $contoId,
        'saldo_iniziale' => 210000, 'attiva' => true]);
    expect($riagganciata->hasAperturaRegistrata())->toBeFalse()
        ->and(app(RegistraAperturaCassaAction::class)->execute($riagganciata, '2026-01-01'))->toBe(EsitoAperturaCassa::REGISTRATA)
        ->and($riagganciata->fresh()->hasAperturaRegistrata())->toBeTrue();
});

test('l\'elenco delle risorse dà alla finestra di conferma la frase dello storno, con l\'importo e il giorno dell\'apertura', function () {
    [$condominio] = setupContabile();
    $this->actingAs(b14caUtente());
    $conApertura = b14caCrea($this, $condominio, '2.150,00');
    $this->post(route('admin.gestionale.casse.store', $condominio->id), ['nome' => 'Cassa contanti', 'tipo' => 'contanti'])->assertSessionHasNoErrors();
    $senza = Cassa::where('nome', 'Cassa contanti')->firstOrFail();

    $this->withoutVite()->get(route('admin.gestionale.casse.index', $condominio->id))
        ->assertInertia(fn ($p) => $p->where("storni_delle_aperture.{$conApertura->id}", 'La sua apertura di € 2.150,00 del 01/01/2026 si storna nel libro '
            .'giornale: restano visibili tutte e due, e il conto archiviato torna a zero.')
            ->missing("storni_delle_aperture.{$senza->id}"));
});

test('la cassa eliminata prima, senza lo storno: «Liquidità e cassa» resta rosso e il rimedio dice il vero, con il comando in sola lettura', function () {
    [$condominio, $esercizio] = setupContabile();
    $this->actingAs(b14caUtente());
    $vecchia = b14caCrea($this, $condominio, '2.150,00');
    // Eliminata come faceva il programma prima: la cassa va via, l'apertura resta sul conto archiviato.
    DB::table('casse')->where('id', $vecchia->id)->delete();

    $r2 = collect(app(StatoPatrimonialePaginaService::class)->costruisci($condominio->fresh(), $esercizio, '2026-01-15')['controlli'])->firstWhere('id', 'R2');
    expect($r2['esito'])->toBe('non_quadra')
        ->and($r2['nota'])->toContain('nessuna cassa')
        ->and($r2['rimedio'])->toContain('una cassa eliminata con la sua apertura ancora a giornale')
        ->and($r2['rimedio'])->toContain('php artisan kondomanager:verifica-aperture-casse')
        ->and($r2['rimedio'])->not->toContain('riassocia');
});
