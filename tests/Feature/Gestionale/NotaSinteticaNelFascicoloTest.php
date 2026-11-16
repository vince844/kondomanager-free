<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La nota sintetica dentro il fascicolo, su Via Verdi al 01/03/2027: la spiegazione di un controllo del conguaglio c'è anche senza la parte del conguaglio
 * (la nota ne chiede la lettura); il tetto, senza quella parte, dice di togliere la nota e non una parte che non è fra le scelte; la frase della
 * riservatezza nomina la parte delle posizioni con il titolo dell'indice.
 */

use App\Models\User;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\FattiDellaNota;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        Permission::findOrCreate($permesso->value, 'web');
    }
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
});

/** Via Verdi al 01/03/2027, con le spese, il passaggio e la spesa personale. */
function nnfViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, []);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000, []);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');

    return $s;
}

/** Le frasi della nota stampata, per chiave del fatto. */
function nnfFrasi(array $stampa): array
{
    return collect($stampa['parti'])->flatMap(fn ($p) => array_column($p['fatti'], 'testo', 'chiave'))->all();
}

it('la spiegazione di un controllo del conguaglio è nella nota del fascicolo anche senza la parte del conguaglio, come in «Stampa la nota»', function () {
    $s = nnfViaVerdi($this);
    // U3 cambia titolare il 01/07/2026 senza un passaggio registrato: Verdi esce, Gialli entra; un controllo del conguaglio lo segnala.
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Verdi'])->update(['data_fine' => '2026-06-30']);
    $gialli = \App\Models\Anagrafica::forceCreate(['nome' => 'Gialli', 'email' => 'gialli-nnf@test.it', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'NNFGIA0000000001']);
    $gialli->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $gialli->id, 'immobile_id' => $s['unita']['U3']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-07-15', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $this->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));
    $nota = app(NotaSinteticaDelRendiconto::class);
    $daSpiegare = collect($nota->letture($s['c'], $s['e'])['controlli']['esito']['righe'])
        ->first(fn ($r) => in_array($r['esito'], ['non_quadra', 'segnalazione'], true) && in_array($r['id'], NotaSinteticaDelRendiconto::CONTROLLI_DEL_CONGUAGLIO, true));
    expect($daSpiegare)->not->toBeNull();
    $id = $daSpiegare['id'];
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['spiegazioni' => [$id => 'Il passaggio di U3 è dal notaio, lo registro a marzo'],
        'parti' => ['altre' => ['testo' => 'I documenti si vedono in studio.']]]), $this->user);

    $daSola = nnfFrasi($nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e'])));
    $esito = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['nota', 'riepilogo']);

    expect(NotaSinteticaDelRendiconto::chiedeIlConguaglio($s['e']))->toBeTrue()
        ->and($daSola)->toHaveKey('G9:'.$id)
        ->and(nnfFrasi($esito['nota']))->toHaveKey('G9:'.$id)
        ->and(nnfFrasi($esito['nota'])['G9:'.$id])->toBe($daSola['G9:'.$id])
        ->and($esito['nota']['spiegati'])->toBe(1)
        // La stima del fascicolo con la nota e senza il conguaglio conta la lettura che la nota chiede.
        ->and(app(FascicoloDelRendiconto::class)->righeDelleParti(['nota'], $s['e'], [], false))->toHaveKey('conguaglio_lettura');
});

it('il tetto del fascicolo, quando la lettura del conguaglio la chiede la nota, dice di togliere la nota: il «Conguaglio» non è fra le parti scelte', function () {
    $tetti = FascicoloDelRendiconto::tetti('512M', 120);
    $righe = ['nota' => 1, 'nota_crediti' => 100, 'riepilogo' => 50, 'conguaglio_lettura' => 2_000_000];
    $senza = FascicoloDelRendiconto::troppo(['nota', 'riepilogo'], $righe, $tetti, '512M');
    $con = FascicoloDelRendiconto::troppo(['nota', 'riepilogo', 'conguaglio'], $righe, $tetti, '512M');

    expect($senza['togliere'])->toContain('nota')->not->toContain('conguaglio')
        ->and($senza['troppo'])->not->toContain('Conguaglio')
        // Con la parte del conguaglio scelta, la lettura è sua.
        ->and($con['togliere'])->toContain('conguaglio');
});

it('la frase della riservatezza nomina la parte delle posizioni con il titolo che ha nell\'indice del fascicolo', function () {
    $s = nnfViaVerdi($this);
    $this->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));
    $fatti = app(FattiDellaNota::class)->perEsercizio($s['c'], $s['e'], app(NotaSinteticaDelRendiconto::class)->letture($s['c'], $s['e']))['fatti'];
    expect(array_column($fatti, 'chiave'))->toContain('N5');
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['fatti' => ['N5' => ['stato' => 'tenuto']]]), $this->user);

    $esito = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['nota', 'condomini']);
    $titolo = collect($esito['indice'])->firstWhere('parte', 'condomini')['titolo'];

    expect($titolo)->toBe('Posizioni dei condòmini')
        ->and(nnfFrasi($esito['nota'])['N5'])->toBe(FattiDellaNota::N5_NEL_FASCICOLO)->toContain('«'.$titolo.'»');
});
