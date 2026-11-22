<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoDelega;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\AssembleeSettings;
use App\Settings\PrintSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Le deleghe (R7, art. 67 c. 1 e 5 disp. att.): ricevute prima («Deleghe ricevute», su carta, con la scansione facoltativa) o segnate in sala; il conto
 * del quinto **nel momento in cui si segna**, sulle teste e sul valore della fotografia (provvisoria prima dell'apertura), solo con più di venti teste; oltre
 * il quinto rifiutata con l'articolo, o la decide il presidente con il motivo (l'impostazione dello studio); mai all'amministratore; una seconda delega
 * sostituisce la prima; il delegante presente vale più della delega. La delega ricevuta prima che supera il quinto con la fotografia dell'apertura.
 *
 * Che cosa non copre: la pagina «Deleghe ricevute» (i vitest del passo).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** «Deleghe ricevute»: la rotta vera. */
function ddaRicevuta($t, $s, $a, string $delegante, array $delegato, array $piu = [])
{
    return $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), $piu + [
        'delegante_anagrafica_id' => $s->persone[$delegante]->id, 'delegato' => $delegato, 'ricevuta_il' => '2026-12-10']);
}

it('«Deleghe ricevute»: la delega su carta prima dell\'assemblea, con la scansione, il quinto al momento e la riga del registro', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $riga = ViaRoma12::ultimaRiga();

    ddaRicevuta($this, $s, $a, 'rossi', ['anagrafica_id' => $s->persone['c30']->id], ['scansione' => UploadedFile::fake()->create('delega.pdf', 120, 'application/pdf')])
        ->assertRedirect()->assertSessionHasNoErrors();

    $d = DelegaDellAssemblea::query()->firstOrFail();
    expect($d->arrivata_come->value)->toBe('carta_prima')
        ->and($d->stato)->toBe(StatoDelega::VALIDA)
        ->and($d->delegante_nome)->toBe('Rossi Mario')->and($d->delegato_nome)->toBe('Condomino 30')
        ->and($d->unita)->toBe([$s->unita['3']->id, $s->unita['21']->id])
        ->and($d->documento_id)->not->toBeNull()
        ->and($d->dati['quinto']['frase'])->toBe('1 condòmino su 5 · 65,00 su 200,00')
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())
        ->toBe(['Assemblea ordinaria del 15/12/2026: delega di Rossi Mario a Condomino 30, ricevuta su carta il 10/12/2026']);
});

it('oltre il quinto del valore: la delega si rifiuta con l\'articolo, e la riga del registro c\'è lo stesso', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $c30 = ['anagrafica_id' => $s->persone['c30']->id];
    ddaRicevuta($this, $s, $a, 'rita', $c30);
    ddaRicevuta($this, $s, $a, 'gallo', $c30);
    $riga = ViaRoma12::ultimaRiga();
    ddaRicevuta($this, $s, $a, 'bianchi', $c30)->assertSessionHasNoErrors();

    $ultima = DelegaDellAssemblea::query()->latest('id')->first();
    expect($ultima->stato)->toBe(StatoDelega::RIFIUTATA)
        ->and($ultima->motivo)->toBe('Con questa delega Condomino 30 rappresenterebbe 3 condòmini su 5 · 230,00 su 200,00: oltre un quinto del valore (art. 67 c. 1 disp. att.).')
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())
        ->toBe(['Assemblea ordinaria del 15/12/2026: delega di Bianchi Anna a Condomino 30, rifiutata: oltre un quinto del valore']);
});

it('oltre il quinto delle teste: sei condòmini su cinque', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    foreach (['c31', 'c32', 'c33', 'c34', 'c35', 'c36'] as $chi) {
        ddaRicevuta($this, $s, $a, $chi, ['anagrafica_id' => $s->persone['c30']->id]);
    }
    expect(DelegaDellAssemblea::query()->orderBy('id')->pluck('stato')->map(fn ($x) => $x->value)->all())
        ->toBe(['valida', 'valida', 'valida', 'valida', 'valida', 'rifiutata']);
});

it('con venti condòmini o meno il quinto non si applica', function () {
    $s = SalaDiViaRoma12::nuovo($this, false);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    foreach (['rita', 'gallo', 'bianchi', 'rossi'] as $chi) {
        ddaRicevuta($this, $s, $a, $chi, ['anagrafica_id' => $s->persone['carla']->id]);
    }
    expect(DelegaDellAssemblea::query()->where('stato', 'valida')->count())->toBe(4)
        ->and(DelegaDellAssemblea::query()->latest('id')->first()->dati['quinto']['si_applica'])->toBeFalse();
});

it('con «Decide il presidente» la delega oltre il quinto passa solo con il suo motivo', function () {
    $impostazioni = app(AssembleeSettings::class);
    $impostazioni->delega_oltre_il_quinto = AssembleeSettings::DECIDE_IL_PRESIDENTE;
    $impostazioni->save();
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['c30']);
    foreach (['rita', 'gallo'] as $chi) {
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('delega', ['delegante_anagrafica_id' => $s->persone[$chi]->id,
            'delegato' => ['anagrafica_id' => $s->persone['c30']->id]], '2026-12-15 18:50'));
    }
    $gesto = fn (array $piu = []) => SalaDiViaRoma12::gesto('delega', $piu + ['delegante_anagrafica_id' => $s->persone['bianchi']->id,
        'delegato' => ['anagrafica_id' => $s->persone['c30']->id]], '2026-12-15 18:52');

    $r = SalaDiViaRoma12::manda($this, $s, $a, [$gesto()])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Con questa delega Condomino 30 rappresenterebbe 3 condòmini su 5 · 230,00 su 200,00: oltre un quinto del valore (art. 67 c. 1 disp. att.). Decide il presidente: scrivi il motivo per accettarla.');

    SalaDiViaRoma12::applica($this, $s, $a, $gesto(['motivo' => 'Il presidente la accetta: l\'assemblea è d\'accordo all\'unanimità']));
    $d = DelegaDellAssemblea::query()->latest('id')->first();
    expect($d->stato)->toBe(StatoDelega::VALIDA)->and($d->arrivata_come->value)->toBe('in_sala')
        ->and($d->motivo)->toBe('Oltre un quinto del valore, accettata dal presidente: Il presidente la accetta: l\'assemblea è d\'accordo all\'unanimità');
});

it('mai all\'amministratore dello studio (art. 67 c. 5 disp. att.)', function () {
    $stampa = app(PrintSettings::class);
    $stampa->studio_nome = 'Studio Costa';
    $stampa->save();
    $s = SalaDiViaRoma12::nuovo($this);
    $s->admin->forceFill(['name' => 'Andrea Costa'])->saveQuietly();
    $s->admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => \App\Enums\Role::AMMINISTRATORE->value, 'guard_name' => 'web']));
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);

    ddaRicevuta($this, $s, $a, 'rossi', ['nome' => 'Studio  costa'])->assertSessionHasErrors(['delegato' => 'All\'amministratore non possono essere conferite deleghe (art. 67 c. 5 disp. att.).']);
    ddaRicevuta($this, $s, $a, 'rossi', ['nome' => 'andrea costa'])->assertSessionHasErrors('delegato');
    expect(DelegaDellAssemblea::query()->count())->toBe(0);
});

it('una seconda delega della stessa persona sostituisce la prima, che resta revocata', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ddaRicevuta($this, $s, $a, 'rossi', ['anagrafica_id' => $s->persone['c30']->id]);
    ddaRicevuta($this, $s, $a, 'rossi', ['nome' => 'Rossi Luigi']);

    expect(DelegaDellAssemblea::query()->orderBy('id')->get()->map(fn ($d) => [$d->delegato_nome, $d->stato->value])->all())
        ->toBe([['Condomino 30', 'revocata'], ['Rossi Luigi', 'valida']]);
});

it('il delegato presente porta il delegante; il delegante presente vale più della delega', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ddaRicevuta($this, $s, $a, 'rita', ['nome' => 'Greco Paola']);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $delega = DelegaDellAssemblea::query()->firstOrFail();

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['delega_id' => $delega->id],
        'come' => 'in_sala'], '2026-12-15 18:50'));
    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['barra']['barra'])->toBe('1 su 26 · 90,00 su 1.000,00')
        ->and(collect($sala['teste'])->firstWhere('nome', 'Colombo Rita')['presente'])->toBe('Greco Paola, per delega');

    SalaDiViaRoma12::entrano($this, $s, $a, ['rita'], '2026-12-15 18:55');
    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['barra']['barra'])->toBe('1 su 26 · 90,00 su 1.000,00')
        ->and(collect($sala['teste'])->firstWhere('nome', 'Colombo Rita')['presente'])->toBe('Colombo Rita');
});

it('la delega ricevuta prima che con la fotografia dell\'apertura supera il quinto si rifiuta all\'apertura, con l\'articolo', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $c30 = ['anagrafica_id' => $s->persone['c30']->id];
    ddaRicevuta($this, $s, $a, 'rita', $c30);
    ddaRicevuta($this, $s, $a, 'gallo', $c30);
    expect(DelegaDellAssemblea::query()->where('stato', 'valida')->count())->toBe(2);
    // Il 15/12 Gallo compra l'Int. 31 e l'Int. 32: con la fotografia della seconda porta 130,50 millesimi.
    foreach (['31', '32'] as $u) {
        DB::table('anagrafica_immobile')->where('immobile_id', $s->unita[$u]->id)->update(['data_fine' => '2026-12-14']);
        $s->titolare($u, 'gallo', 'proprietario', 100, '2026-12-15');
    }

    SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $gallo = DelegaDellAssemblea::query()->where('delegante_anagrafica_id', $s->persone['gallo']->id)->firstOrFail();
    expect($gallo->stato)->toBe(StatoDelega::RIFIUTATA)
        ->and($gallo->motivo)->toBe('All\'apertura, con la fotografia della seduta, Condomino 30 rappresenterebbe 2 condòmini su 4 · 220,50 su 200,00: oltre un quinto del valore (art. 67 c. 1 disp. att.).');
});
