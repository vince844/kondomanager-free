<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Comunicazione;
use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\Seguito\EsitiNelleSchede;
use App\Services\Assemblee\Seguito\SegniDellaDelibera;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * I segni (R11) e «Avvisa i condòmini» (R12, decisione 622 (6)):
 *
 * - «Segna annullata dal giudice» e «Segna revocata» dalle loro rotte: il segno sulla delibera, la riga del registro, le schede che la usano lo dicono,
 *   «Scegli la delibera» la spegne; la revoca è di una delibera successiva dello stesso condominio, proposta se il punto parla di lei;
 * - l'avviso: acceso di serie per il riscaldamento, spento per il rendiconto, assente per l'accordo; la comunicazione si apre già scritta con la frase pubblica
 *   (mai il testo del punto), e pubblicata fa la cosa da fare.
 *
 * Che cosa non copre: le finestre (a video); «Segna impugnata» (è del verbale, `TerminiDellImpugnazioneTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Una riunione tenuta su carta il giorno dato, con i punti approvati. */
function sdaSuCarta(Beta51Scenario $s, array $punti, string $giorno = '2026-12-15'): \App\Models\Assemblee\Assemblea
{
    $a = ViaRoma12::ordinaria($s, $punti);
    $esiti = $a->punti()->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => ['esito' => 'approvata']])->all();

    return StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, $esiti, $giorno);
}

it('«Segna annullata dal giudice»: il segno con il provvedimento, la riga del registro, la scheda che la usa lo dice, «Scegli la delibera» la spegne', function () {
    $s = ViaRoma12::nuovo($this);
    $f = ViaRoma12::fatturaInSforo($s);
    $a = sdaSuCarta($s, [['testo' => 'Ratifica della spesa urgente', 'materia' => 'ratifica', 'oggetti' => [['fattura_passiva', $f->id, 'origine']]]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    \App\Services\Assemblee\RatificaDelloSforo::ratifica($f, $d, null, $s->admin);
    $prima = ViaRoma12::ultimaRiga();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.delibere.annullata', [$s->c, $a, $d]), ['giorno' => '2026-12-16',
        'provvedimento' => 'Sentenza n. 1187/2026 del Tribunale di Milano'])->assertSessionHasNoErrors();
    expect($d->fresh()->annullata_il->format('Y-m-d'))->toBe('2026-12-16')
        ->and(ViaRoma12::righe($prima)->pluck('riepilogo')->all())->toContain('annullata dal giudice il 16/12/2026')
        ->and(collect(EsitiNelleSchede::perLaScheda($f))->pluck('frase')->all())
        ->toContain('Annullata dal giudice il 16/12/2026 · Sentenza n. 1187/2026 del Tribunale di Milano')
        ->and(DelibereDelCondominio::siSceglie($d->fresh(), 'ratifica'))->toBe([false, 'annullata il 16/12/2026'])
        // Un segno non ferma niente: la fattura resta ratificata.
        ->and($f->fresh()->stato_approvazione)->toBe('approvata');
});

it('«Segna revocata»: dalla delibera successiva dello stesso condominio, proposta perché il punto parla di lei; una delibera di prima no', function () {
    $s = ViaRoma12::nuovo($this);
    $a = sdaSuCarta($s, [['testo' => 'Delega all\'amministratore', 'materia' => 'delega', 'importo' => 200000]], '2026-10-22');
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $b = sdaSuCarta($s, [['testo' => 'Revoca della delega all\'amministratore', 'materia' => 'altro', 'oggetti' => [['delibera', $d->id, 'parla_di']]],
        ['testo' => 'Orari del riscaldamento', 'materia' => 'riscaldamento']], '2026-12-15');
    $revoca = SeguitoDiViaRoma12::delibera($b, 1);

    $proposte = SegniDellaDelibera::proposteDiRevoca($d);
    expect($proposte[0]['id'])->toBe($revoca->id)
        ->and($proposte[0]['proposta'])->toBe('Proposta perché il punto parla di questa delibera');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.delibere.revocata', [$s->c, $b, $revoca]), ['revocata_da_delibera_id' => $d->id])
        ->assertSessionHasErrors('revocata_da_delibera_id');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.delibere.revocata', [$s->c, $a, $d]), ['revocata_da_delibera_id' => $revoca->id])
        ->assertSessionHasNoErrors();
    expect($d->fresh()->revocata_il->format('Y-m-d'))->toBe('2026-12-15')
        ->and($d->fresh()->revocata_da_delibera_id)->toBe($revoca->id)
        ->and(DelibereDelCondominio::siSceglie($d->fresh(), 'delega'))->toBe([false, 'revocata il 15/12/2026']);
});

it('«Avvisa i condòmini»: acceso per il riscaldamento, spento per il rendiconto, assente per l\'accordo; già scritto con la frase pubblica; pubblicato, la cosa è fatta', function () {
    $s = ViaRoma12::nuovo($this);
    $accordo = \App\Models\Gestionale\AccordoRientro::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $s->persona('gallo')->id,
        'data_accordo' => '2026-09-01', 'fino_al' => '2027-03-01', 'autorizzato_assemblea' => false]);
    $a = sdaSuCarta($s, [
        ['testo' => 'Riscaldamento: orari di accensione 2026/2027 (richiesta di Rossi Mario)', 'testo_pubblico' => 'Orari del riscaldamento 2026/2027',
            'materia' => 'riscaldamento'],
        ['testo' => 'Approvazione del rendiconto 2026', 'materia' => 'rendiconto'],
        ['testo' => 'Piano di rientro di Gallo Enzo', 'materia' => 'accordo', 'oggetti' => [['accordo_rientro', $accordo->id, 'origine']]],
    ]);
    $righe = SeguitoDiViaRoma12::righe($a);
    $calore = SeguitoDiViaRoma12::delibera($a, 1);
    expect($righe[$calore->id . ':cosa:avvisa_condomini']['spuntata'])->toBeTrue()
        ->and($righe[SeguitoDiViaRoma12::delibera($a, 2)->id . ':cosa:avvisa_condomini']['spuntata'])->toBeFalse()
        ->and(array_key_exists(SeguitoDiViaRoma12::delibera($a, 3)->id . ':cosa:avvisa_condomini', $righe))->toBeFalse();

    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $calore->id)->where('tipo', TipoCosaDaFare::AVVISA_CONDOMINI->value)->firstOrFail();
    foreach (\App\Enums\Permission::cases() as $p) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p->value, 'guard_name' => 'web']);
    }
    $s->admin->givePermissionTo(\App\Enums\Permission::CREATE_COMUNICAZIONI->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $pagina = $this->actingAs($s->admin)->get(route('admin.comunicazioni.create', ['cosa_da_fare' => $cosa->id]))->assertOk()->viewData('page')['props'];
    expect($pagina['precompilata']['subject'])->toBe('Assemblea del 15/12/2026: Orari del riscaldamento 2026/2027')
        ->and($pagina['precompilata']['description'])->toBe('L\'assemblea ordinaria del 15/12/2026 ha approvato: Orari del riscaldamento 2026/2027.')
        ->and($pagina['precompilata']['description'])->not->toContain('Rossi');

    $c = Comunicazione::create(['subject' => 'Orari', 'description' => 'Testo', 'is_published' => true, 'is_approved' => true, 'is_private' => false,
        'priority' => 'bassa', 'created_by' => $s->admin->id]);
    $c->condomini()->attach($s->c->id);
    \App\Services\Assemblee\Seguito\AvvisoAiCondomini::collega($c, $cosa->id, $s->admin);
    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and($cosa->fresh()->oggetto_tipo)->toBe('comunicazione');
});
