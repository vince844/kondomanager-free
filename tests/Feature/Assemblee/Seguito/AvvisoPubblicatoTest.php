<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Comunicazione;
use App\Models\Evento;
use App\Services\Assemblee\Seguito\AvvisoAiCondomini;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Services\Assemblee\Seguito\StatoDiEsecuzione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Support\Comunicazioni\PubblicazioneProgrammata;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * R12, «Avvisa i condòmini»: la cosa da fare è fatta **quando la comunicazione diventa pubblicata**, da qualunque strada (la sua pagina, «Approva», la
 * pubblicazione programmata), e il compito si chiude in quel momento. Leggere il passo 6 non scrive niente, nemmeno per chi vede soltanto.
 *
 * Che cosa non copre: la pagina della nuova comunicazione già scritta (la provano i test della convocazione e della comunicazione).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La riunione su carta con «Avvisa i condòmini» confermato con il compito del 18/12, e la comunicazione in bozza legata alla cosa. @return array{0: Beta51Scenario, 1: CosaDaFareDellaDelibera, 2: Comunicazione, 3: \App\Models\Assemblee\Assemblea} */
function apcAvvisoInBozza(\Tests\TestCase $t, array $comunicazione = []): array
{
    $s = ViaRoma12::nuovo($t);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari di accensione', 'testo_pubblico' => 'Orari del riscaldamento', 'materia' => 'riscaldamento']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':cosa:avvisa_condomini';
    SeguitoDiViaRoma12::conferma($t, $s, $a, [$chiave], ['cose' => [$chiave => ['entro_il' => '2026-12-18']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    $c = Comunicazione::create($comunicazione + ['subject' => 'Orari', 'description' => 'Testo', 'is_published' => false, 'is_approved' => false,
        'is_private' => false, 'priority' => 'bassa', 'created_by' => $s->admin->id]);
    $c->condomini()->attach($s->c->id);
    AvvisoAiCondomini::collega($c, $cosa->id, $s->admin);

    return [$s, $cosa, $c, $a];
}

it('pubblicata dalla pagina della comunicazione, la cosa è fatta e il compito chiuso subito; la lettura del passo 6 di chi vede soltanto non scrive niente', function () {
    [$s, $cosa, $c] = apcAvvisoInBozza($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');
    $c->update(['is_published' => true, 'is_approved' => true]);

    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and($cosa->fresh()->fatta_il->format('Y-m-d'))->toBe('2026-12-17')
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue()
        ->and(SeguitoDelleDelibere::perLaDashboard($s->c))->toBe([])
        ->and(StatoDiEsecuzione::seguito($cosa->delibera->fresh())['stato'])->toBe('eseguita');
});

it('la lettura del passo 6 di chi vede soltanto non scrive niente, nemmeno con una comunicazione pubblicata senza passare dal programma', function () {
    [$s, $cosa, $c, $a] = apcAvvisoInBozza($this);
    \Illuminate\Support\Facades\DB::table('comunicazioni')->where('id', $c->id)->update(['is_published' => 1, 'is_approved' => 1]);
    $vede = ViaRoma12::utente([\App\Enums\Permission::VIEW_ASSEMBLEE]);
    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($vede);

    SeguitoDelleDelibere::perLaPagina($a, $vede);

    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::DA_FARE)->and(ViaRoma12::righe($prima)->count())->toBe(0);
});

it('approvata con «Approva» della comunicazione, la cosa è fatta', function () {
    \Illuminate\Support\Facades\Notification::fake();
    [$s, $cosa, $c] = apcAvvisoInBozza($this);
    foreach ([\App\Enums\Permission::APPROVE_COMUNICAZIONI, \App\Enums\Permission::PUBLISH_COMUNICAZIONI, \App\Enums\Permission::ACCESS_ADMIN_PANEL] as $p) {
        \Spatie\Permission\Models\Permission::findOrCreate($p->value, 'web');
        $s->admin->givePermissionTo($p->value);
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');

    $this->actingAs($s->admin)->put(route('admin.comunicazioni.toggle-approval', $c));

    expect((bool) $c->fresh()->is_published)->toBeTrue()
        ->and($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue();
});

it('pubblicata dalla pubblicazione programmata, la cosa è fatta con il giorno della pubblicazione', function () {
    [$s, $cosa, $c] = apcAvvisoInBozza($this, ['is_approved' => true]);
    $c->forceFill(['programmata_per' => '2026-12-17 08:00:00', 'programmata_da' => $s->admin->id])->saveQuietly();
    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');

    expect(PubblicazioneProgrammata::pubblica($c->fresh()))->toBeTrue();

    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and($cosa->fresh()->fatta_il->format('Y-m-d'))->toBe('2026-12-17')
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue();
});
