<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoDelega;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Assemblee\VotoInSala;
use App\Http\Controllers\Assemblee\PerLePagine;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\AssembleeSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Le regole della sala che tengono insieme il motore, le pagine e la tenuta senza rete:
 *
 * - **«Segna deserta» anche dopo aver aperto la prima convocazione** (e la seconda, `SecondaDesertaTest`), finché non si vota: chiude le presenze, e il
 *   conto resta per il verbale.
 * - **Le scelte dello studio ferme all'apertura** (la delega oltre il quinto, il voto discorde dei comproprietari), nella fotografia della seduta.
 * - **Si vota anche senza rete**: i gesti del voto dicono la seduta, il punto e il numero al posto del numero della votazione, che il dispositivo senza rete
 *   non ha; con il numero del gesto che l'ha aperta, una votazione aperta da un altro dispositivo non si mescola.
 * - **La scansione della delega** si apre dalla sua rotta, con «Vede le assemblee».
 * - **Le due versioni** dicono anche da quale dispositivo e a che ora è venuta la versione di adesso; **«doppio»** ridice i numeri della prima volta.
 * - **Il foglio della sala**: quando è stato stampato, e «ristampa il foglio» se una delega è cambiata dopo; le rotte della sala nella sua prop.
 *
 * Che cosa non copre: le pagine (i vitest della sala e del passo «In sala»); le catene da un capo all'altro (`CateneDellaSalaInteraTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** L'assemblea convocata, con la prima aperta lunedì 14/12 alle 8:05 e le persone date entrate alle 8:10. @return array{0: Beta51Scenario, 1: \App\Models\Assemblee\Assemblea, 2: SedutaDellAssemblea} */
function udsPrimaAperta($t, array $chi): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $prima = SalaDiViaRoma12::seduta($a, 'prima');
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:45');
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $prima->id, 'presidente' => ['anagrafica_id' => $s->persone['rossi']->id],
        'segretario' => ['nome' => 'L\'amministratore']], '2026-12-14 08:05'));
    foreach ($chi as $x) {
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $prima->id, 'persona' => ['anagrafica_id' => $s->persone[$x]->id],
            'come' => 'in_sala'], '2026-12-14 08:10'));
    }

    return [$s, $a, $prima->refresh()];
}

/** La seconda aperta, con le persone date in sala alle 18:45. */
function udsSeconda($t, array $chi): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, $chi);

    return [$s, $a, $seduta];
}

/** Il passo «In sala» della strada dell'assemblea. @return array<string, mixed> */
function udsPasso($a): array
{
    return collect(PerLePagine::passi($a->refresh()))->keyBy('chiave')->all()['sala'];
}

function udsPunto($a, int $numero): int
{
    return (int) $a->punti()->where('numero', $numero)->value('id');
}

// ─── «Segna deserta» anche dopo aver aperto ───────────────────────────────────────────────────────────────────────────────────────────────────────────

it('aperta la prima e contati i presenti, «Segna deserta» la chiude: le presenze si chiudono all\'ora, l\'assemblea aspetta la seconda', function () {
    [$s, $a, $prima] = udsPrimaAperta($this, ['bianchi', 'rita', 'gallo']);
    $riga = ViaRoma12::ultimaRiga();

    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prima->id], '2026-12-14 08:40'));

    $prima->refresh();
    expect($r['esito'])->toBe('applicato')
        ->and($prima->esito->value)->toBe('deserta')
        ->and($prima->aperta_il->format('H:i'))->toBe('07:05')
        ->and($prima->chiusa_il->format('H:i'))->toBe('07:40')
        ->and($a->refresh()->stato->value)->toBe('convocata')
        ->and(PresenzaInSala::query()->where('seduta_id', $prima->id)->whereNull('uscita_il')->count())->toBe(0)
        ->and(PresenzaInSala::query()->where('seduta_id', $prima->id)->pluck('uscita_il')->map(fn ($x) => $x->format('H:i'))->unique()->all())->toBe(['07:40'])
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())
        ->toBe(['Assemblea ordinaria del 14/12/2026: la prima convocazione del 14/12/2026 è deserta: aperta alle 08:05, all\'appello 3 su 26 · 230,00 su 1.000,00']);

    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['seduta_id'])->toBeNull()
        ->and($sala['da_aprire']['seduta_id'])->toBe(SalaDiViaRoma12::seduta($a)->id)
        ->and(udsPasso($a)['riga'])->toBe('prima deserta · la seconda martedì 15/12 alle 18:30');
});

it('dopo il primo «Si vota» la prima non si segna più deserta', function () {
    [$s, $a, $prima] = udsPrimaAperta($this, ['bianchi', 'rita', 'gallo', 'rossi']);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $prima->id, 'punto_id' => udsPunto($a, 1)], '2026-12-14 08:20'));

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prima->id], '2026-12-14 08:40')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('Sul punto 1 si è già votato: la prima convocazione non si segna più deserta. Chiudi l\'assemblea, o rinviala.')
        ->and($prima->refresh()->esito)->toBeNull();
});

it('la prosecuzione di una seduta sospesa non si segna deserta: l\'assemblea era già costituita (la seconda aperta sì, in `SecondaDesertaTest`)', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::ordinaria($s);
    \App\Models\Assemblee\SedutaDellAssemblea::create(['assemblea_id' => $a->id, 'convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-17 18:30']);
    $a = SalaDiViaRoma12::convoca($a, $s);
    $seconda = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $prosegue = SalaDiViaRoma12::seduta($a, 'seconda', 2);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seconda->id, 'prosegue_seduta_id' => $prosegue->id], '2026-12-15 20:30'));
    ConvocazioneDiViaRoma12::oggi('2026-12-17 21:00');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $prosegue->id, 'presidente' => ['anagrafica_id' => $s->persone['rossi']->id],
        'segretario' => ['nome' => 'L\'amministratore']], '2026-12-17 18:35'));

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prosegue->id], '2026-12-17 19:00')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('La prosecuzione è aperta: si segna deserta solo la prima o la seconda convocazione aperta, prima di votare.');
});

// ─── Le scelte dello studio ferme all'apertura ────────────────────────────────────────────────────────────────────────────────────────────────────────

it('la delega oltre il quinto segnata a sala aperta segue la scelta dell\'apertura, anche se lo studio l\'ha cambiata dopo', function () {
    [$s, $a, $seduta] = udsSeconda($this, ['c30']);
    $studio = app(AssembleeSettings::class);
    $studio->delega_oltre_il_quinto = AssembleeSettings::DECIDE_IL_PRESIDENTE;
    $studio->save();
    foreach (['rita', 'gallo'] as $chi) {
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('delega', ['delegante_anagrafica_id' => $s->persone[$chi]->id,
            'delegato' => ['anagrafica_id' => $s->persone['c30']->id]], '2026-12-15 18:50'));
    }

    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('delega', ['delegante_anagrafica_id' => $s->persone['bianchi']->id,
        'delegato' => ['anagrafica_id' => $s->persone['c30']->id]], '2026-12-15 18:52'));

    $d = DelegaDellAssemblea::query()->latest('id')->first();
    expect($r['esito'])->toBe('applicato')
        ->and($d->stato)->toBe(StatoDelega::RIFIUTATA)
        ->and($d->motivo)->toBe('Con questa delega Condomino 30 rappresenterebbe 3 condòmini su 5 · 230,00 su 200,00: oltre un quinto del valore (art. 67 c. 1 disp. att.).')
        ->and($seduta->refresh()->fotografia['impostazioni'])->toBe(['delega_oltre_il_quinto' => 'rifiuta', 'voto_discorde_comproprietari' => 'astenuti'])
        ->and(app(LetturaDellaSala::class)->perLaPagina($a, $s->admin)['impostazioni'])
        ->toBe(['delega_oltre_il_quinto' => 'rifiuta', 'voto_discorde_comproprietari' => 'astenuti', 'teste_caso_misto' => 'due_teste', 'ferme_all_apertura' => true]);
});

it('il voto discorde dei comproprietari conta come diceva lo studio all\'apertura', function () {
    $studio = app(AssembleeSettings::class);
    $studio->voto_discorde_comproprietari = AssembleeSettings::DECIDE_IL_PRESIDENTE;
    $studio->save();
    [$s, $a, $seduta] = udsSeconda($this, ['marco', 'anna', ...SalaDiViaRoma12::altri(9)]);
    $studio->voto_discorde_comproprietari = AssembleeSettings::ASTENUTI;
    $studio->save();
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => udsPunto($a, 1)], '2026-12-15 19:00'));
    $testa = collect(VotazioneDelPunto::query()->findOrFail($v['votazione_id'])->intervenuti['teste'])->first(fn ($t) => count($t['persone']) > 1)['chiave'];

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => $testa, 'voto' => 'discorde'],
        '2026-12-15 19:01', 0)])->json('risposte.0');

    expect($r['esito'])->toBe('rifiutato')
        ->and($r['messaggio'])->toBe('I comproprietari votano in modo diverso: decide il presidente, scegli come conta il voto.');
});

// ─── Si vota anche senza rete ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('un punto si vota tutto senza il numero della votazione: la seduta, il punto e il numero, in un lotto solo', function () {
    [$s, $a, $seduta] = udsSeconda($this, ['rossi', 'rita', ...SalaDiViaRoma12::altri(9)]);
    $punto = udsPunto($a, 1);
    $dove = ['seduta_id' => $seduta->id, 'punto_id' => $punto, 'numero' => 1];
    $siVota = SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $punto], '2026-12-15 19:00');
    $rita = 'p-' . $s->persone['rita']->id;
    $aperta = ['aperta_dal_gesto' => $siVota['gesto']];

    $risposte = SalaDiViaRoma12::manda($this, $s, $a, [
        $siVota,
        SalaDiViaRoma12::gesto('tutti_favorevoli', $dove + $aperta, '2026-12-15 19:01'),
        SalaDiViaRoma12::gesto('voto', $dove + $aperta + ['testa' => $rita, 'voto' => 'contrario'], '2026-12-15 19:02', 1),
        SalaDiViaRoma12::gesto('conflitto', $dove + $aperta + ['testa' => 'p-' . $s->persone['rossi']->id, 'in_conflitto' => true, 'nota' => 'È il fornitore'],
            '2026-12-15 19:03'),
        SalaDiViaRoma12::gesto('nota', $dove + $aperta + ['su' => 'voto', 'testo' => 'Votato senza rete, riportato dal portatile.'], '2026-12-15 19:04'),
        SalaDiViaRoma12::gesto('conferma', $dove + $aperta + ['esito' => 'respinta'], '2026-12-15 19:05'),
    ])->assertOk()->json('risposte');

    $v = VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->where('punto_id', $punto)->firstOrFail();
    expect(array_column($risposte, 'esito'))->toBe(['applicato', 'applicato', 'applicato', 'applicato', 'applicato', 'applicato'])
        ->and(array_unique(array_column($risposte, 'votazione_id')))->toBe([(int) $v->id])
        ->and($v->esito->value)->toBe('respinta')
        ->and($v->nota)->toBe('Votato senza rete, riportato dal portatile.')
        ->and(VotoInSala::query()->where('votazione_id', $v->id)->where('voto', 'contrario')->pluck('nome')->all())->toBe(['Colombo Rita'])
        ->and(VotoInSala::query()->where('votazione_id', $v->id)->where('in_conflitto', true)->pluck('nome')->all())->toBe(['Rossi Mario'])
        ->and($v->delibera_id)->not->toBeNull();
});

it('senza il numero della votazione: il punto che non si sta votando, e la votazione aperta da un altro dispositivo, si rifiutano', function () {
    [$s, $a, $seduta] = udsSeconda($this, ['rossi', 'rita', ...SalaDiViaRoma12::altri(9)]);
    $dove = ['seduta_id' => $seduta->id, 'punto_id' => udsPunto($a, 2), 'numero' => 1];

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('tutti_favorevoli', $dove, '2026-12-15 19:01')])->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($r['messaggio'])->toBe('Il punto 2 non si sta votando: prima «Si vota».');

    // Il telefono, in linea, apre il voto del punto 2; il portatile senza rete lo aveva aperto anche lui, e i suoi voti dicono il suo gesto.
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => udsPunto($a, 2)], '2026-12-15 19:00'));
    $suo = SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => udsPunto($a, 2)], '2026-12-15 19:00');
    $risposte = SalaDiViaRoma12::manda($this, $s, $a, [$suo, SalaDiViaRoma12::gesto('tutti_favorevoli', $dove + ['aperta_dal_gesto' => $suo['gesto']], '2026-12-15 19:02')],
        'portatile di Andrea')->json('risposte');
    expect(array_column($risposte, 'esito'))->toBe(['rifiutato', 'rifiutato'])
        ->and($risposte[1]['messaggio'])->toBe('Il punto 2 si votava già da un altro dispositivo: i voti segnati qui non si mescolano con quelli. Segnali di nuovo sul voto di adesso.')
        ->and(VotoInSala::query()->count())->toBe(0);
});

// ─── La scansione della delega ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('la scansione della delega si apre dalla sua rotta, con «Vede le assemblee»; senza scansione, o di un\'altra assemblea, non c\'è', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), [
        'delegante_anagrafica_id' => $s->persone['rossi']->id, 'delegato' => ['anagrafica_id' => $s->persone['c30']->id], 'ricevuta_il' => '2026-12-10',
        'scansione' => UploadedFile::fake()->createWithContent('delega.pdf', '%PDF-1.4 la delega di Rossi'),
    ])->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), [
        'delegante_anagrafica_id' => $s->persone['rita']->id, 'delegato' => ['anagrafica_id' => $s->persone['c31']->id], 'ricevuta_il' => '2026-12-10',
    ])->assertSessionHasNoErrors();
    [$conScansione, $senza] = DelegaDellAssemblea::query()->orderBy('id')->get()->all();
    $rotta = fn ($d) => route('admin.gestionale.assemblee.deleghe.scansione', [$s->c, $a, $d]);

    $r = $this->get($rotta($conScansione))->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($r->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($r->streamedContent())->toBe('%PDF-1.4 la delega di Rossi');
    $this->get($rotta($senza))->assertNotFound();
    $deleghe = app(LetturaDellaSala::class)->perLaPagina($a, $s->admin)['deleghe'];
    expect($deleghe[0]['scansione'])->toBe($rotta($conScansione))->and($deleghe[1]['scansione'])->toBeNull();

    $altra = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $this->get(route('admin.gestionale.assemblee.deleghe.scansione', [$s->c, $altra, $conScansione]))->assertNotFound();
});

// ─── Le due versioni, «doppio», il foglio, le rotte ───────────────────────────────────────────────────────────────────────────────────────────────────

it('le due versioni dicono da quale dispositivo e a che ora è venuta la versione di adesso', function () {
    [$s, $a, $seduta] = udsSeconda($this, ['rossi', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => udsPunto($a, 1)], '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    $rita = 'p-' . $s->persone['rita']->id;
    SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => $rita, 'voto' => 'astenuto'],
        '2026-12-15 19:44', 1)], 'tablet di Marco Galli#00aa11bb')->assertOk();

    $r = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => $rita, 'voto' => 'contrario'],
        '2026-12-15 19:42', 1)])->json('risposte.0');

    expect($r['esito'])->toBe('in_conflitto')
        ->and($r['conflitto'])->toBe(['questa' => 'Colombo Rita: Contrario', 'quella' => 'Colombo Rita: Astenuto', 'quella_dispositivo' => 'tablet di Marco Galli#00aa11bb',
            'quella_segnato_il' => '2026-12-15T18:44:00Z'])
        ->and(app(LetturaDellaSala::class)->perLaPagina($a, $s->admin)['conflitti'][0])->toMatchArray(['quella_dispositivo' => 'tablet di Marco Galli#00aa11bb',
            'quella_segnato_il' => '2026-12-15T18:44:00Z']);
});

it('«doppio» ridice i numeri della prima volta: la votazione, la delega, la dichiarazione', function () {
    [$s, $a, $seduta] = udsSeconda($this, ['rossi', 'c30', ...SalaDiViaRoma12::altri(8)]);
    $gesti = [
        SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => udsPunto($a, 1)], '2026-12-15 19:00'),
        SalaDiViaRoma12::gesto('delega', ['delegante_anagrafica_id' => $s->persone['rita']->id, 'delegato' => ['anagrafica_id' => $s->persone['c30']->id]], '2026-12-15 19:01'),
        SalaDiViaRoma12::gesto('dichiarazione', ['seduta_id' => $seduta->id, 'punto_id' => udsPunto($a, 1), 'anagrafica_id' => $s->persone['rossi']->id,
            'testo' => 'Chiedo che il preventivo sia allegato.'], '2026-12-15 19:02'),
    ];
    $prima = SalaDiViaRoma12::manda($this, $s, $a, $gesti)->assertOk()->json('risposte');

    $di_nuovo = SalaDiViaRoma12::manda($this, $s, $a, $gesti)->assertOk()->json('risposte');

    expect(array_column($di_nuovo, 'esito'))->toBe(['doppio', 'doppio', 'doppio'])
        ->and($di_nuovo[0]['votazione_id'])->toBe($prima[0]['votazione_id'])
        ->and($di_nuovo[1]['delega_id'])->toBe($prima[1]['delega_id'])
        ->and($di_nuovo[2]['dichiarazione_id'])->toBe($prima[2]['dichiarazione_id']);
});

it('il foglio della sala ricorda quando è stato stampato, e il passo dice «ristampa il foglio» se una delega è cambiata dopo', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $voce = fn () => collect(udsPasso($a)['manca'])->firstWhere('chiave', 'foglio');
    expect($voce()['dettaglio'])->toBe('non ancora stampato: da stampare il giorno prima, con le deleghe ricevute; serve se la rete manca')
        ->and(app(LetturaDellaSala::class)->perLaPagina($a, $s->admin)['foglio'])->toBe(['stampato_il' => null, 'da_ristampare' => false]);

    ConvocazioneDiViaRoma12::oggi('2026-12-13 17:00');
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a]))->assertOk();
    // Stampato: la card è verde (fatta, non informa) e il pulsante dice «Stampa di nuovo».
    expect($voce()['dettaglio'])->toBe('stampato il 13/12 alle 17:00, con le deleghe ricevute fino ad allora')
        ->and([$voce()['fatto'], $voce()['informa']])->toBe([true, false])
        ->and($voce()['collegamento'])->toBe(['testo' => 'Stampa di nuovo', 'url' => route('admin.gestionale.assemblee.sala.foglio', [$s->c->id, $a->id]), 'passo' => null,
            'stampa' => true]);

    ConvocazioneDiViaRoma12::oggi('2026-12-13 18:10');
    $this->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), [
        'delegante_anagrafica_id' => $s->persone['rossi']->id, 'delegato' => ['anagrafica_id' => $s->persone['c30']->id], 'ricevuta_il' => '2026-12-13',
    ])->assertSessionHasNoErrors();
    // Una delega dopo la stampa: da rifare, in arancio, «Ristampa il foglio».
    expect($voce()['dettaglio'])->toBe('una delega è cambiata dopo la stampa del 13/12 alle 17:00: ristampalo')
        ->and($voce()['collegamento']['testo'])->toBe('Ristampa il foglio')
        ->and([$voce()['fatto'], $voce()['informa'], $voce()['rifare'] ?? false])->toBe([false, false, true])
        ->and(app(LetturaDellaSala::class)->perLaPagina($a, $s->admin)['foglio'])->toBe(['stampato_il' => '2026-12-13T16:00:00Z', 'da_ristampare' => true]);

    // Ristampato dopo la mezzanotte: l'ora con due cifre, e la card torna verde.
    ConvocazioneDiViaRoma12::oggi('2026-12-14 00:01');
    $this->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a]))->assertOk();
    expect($voce()['dettaglio'])->toBe('stampato il 14/12 alle 00:01, con le deleghe ricevute fino ad allora')
        ->and($voce()['collegamento']['testo'])->toBe('Stampa di nuovo')
        ->and($voce())->not->toHaveKey('rifare')
        ->and([$voce()['fatto'], $voce()['informa']])->toBe([true, false]);
});

it('la prop della sala ha le rotte delle stampe e dell\'operaio senza rete', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);

    expect(app(LetturaDellaSala::class)->perLaPagina($a, $s->admin)['rotte'])->toMatchArray([
        'foglio' => route('admin.gestionale.assemblee.sala.foglio', [$s->c->id, $a->id]),
        'prospetto' => route('admin.gestionale.assemblee.sala.prospetto', [$s->c->id, $a->id]),
        'senza_rete' => route('admin.gestionale.assemblee.sala.senza_rete', [$s->c->id, $a->id]),
    ]);
});

// ─── Il foglio della sala compilato a metà ────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('a sala aperta il foglio si stampa con quello che c\'è: in testa l\'ora, le presenze con le ore, il voto già segnato; il resto vuoto', function () {
    [$s, $a, $seduta] = udsSeconda($this, ['rossi', 'rita', ...SalaDiViaRoma12::altri(9)]);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['c30']->id]],
        '2026-12-15 19:30'));
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => udsPunto($a, 1)], '2026-12-15 19:40'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:41'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => 'p-' . $s->persone['rita']->id,
        'voto' => 'contrario'], '2026-12-15 19:42', 1));
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:10');

    $dati = app(\App\Services\Assemblee\Sala\FoglioDellaSala::class)->dati($a->refresh(), true);

    $rita = collect($dati['teste'])->firstWhere('nome', 'Colombo Rita');
    $c30 = collect($dati['teste'])->firstWhere('nome', 'Condomino 30');
    $gallo = collect($dati['teste'])->firstWhere('nome', 'Gallo Enzo');
    $punto1 = collect($dati['punti'])->firstWhere('numero', 1);
    expect($dati['compilato'])->toBe('Compilato fino alle 20:10 del 15/12/2026')
        ->and($dati['apertura'])->toBe(['seduta' => 'Seconda convocazione', 'aperta' => '18:42', 'presidente' => 'Rossi Mario', 'segretario' => 'L\'amministratore'])
        ->and(array_column($dati['sedute'], 'segnato'))->toBe(['deserta alle 18:40', 'aperta alle 18:42'])
        ->and($rita['presenza'])->toBe(['chi' => 'Presente', 'entrata' => '18:45', 'uscita' => null])
        ->and($c30['presenza'])->toBe(['chi' => 'Presente', 'entrata' => '18:45', 'uscita' => '19:30'])
        ->and($gallo['presenza'])->toBeNull()
        ->and($punto1['votazioni'][0]['segnato'])->toMatchArray(['ora' => '19:40', 'esito' => 'da confermare'])
        ->and($punto1['votazioni'][0]['segnato']['contrari'])->toBe(['nomi' => 'Colombo Rita', 'quanti' => 1, 'millesimi' => '90,00'])
        ->and(collect($dati['punti'])->firstWhere('numero', 2)['votazioni'][0]['segnato'])->toBeNull();

    // La rotta: «Stampa il foglio con quello che c'è», solo a sala aperta; il foglio vuoto resta quello di sempre.
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a, 'compilato' => 1]))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect(app(LetturaDellaSala::class)->perLaPagina($a, $s->admin)['rotte']['foglio_compilato'])
        ->toBe(route('admin.gestionale.assemblee.sala.foglio', [$s->c->id, $a->id, 'compilato' => 1]));
    $vuoto = app(\App\Services\Assemblee\Sala\FoglioDellaSala::class)->dati($a, false);
    expect($vuoto['compilato'])->toBeNull()->and(collect($vuoto['teste'])->firstWhere('nome', 'Colombo Rita')['presenza'])->toBeNull();
});

it('il foglio con quello che c\'è non si stampa senza una seduta aperta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a, 'compilato' => 1]))->assertNotFound();
    $this->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a]))->assertOk();
    expect(app(LetturaDellaSala::class)->perLaPagina($a, $s->admin)['rotte']['foglio_compilato'])->toBeNull();
});
