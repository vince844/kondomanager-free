<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Assemblee\VotoInSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SalaDiViaRoma12.php';
require_once __DIR__ . '/../../Pdf/Support/CartaDelloStudio.php';

/**
 * Le catene della sala senza rete, il lato del server, da un capo all'altro:
 *
 * 1. **Il gesto senza rete → la coda → l'invio quando la rete torna → la riga del registro con le due ore.** I lotti sono quelli che la coda del browser manda
 *    (`tests/Fixtures/sala/lotti_senza_rete.json`, lo stesso file che `coda.test.ts` fa scrivere alla coda): cinque gesti segnati fra le 19:39 e le 19:41 con
 *    l'orologio del portatile indietro di dieci minuti, arrivati alle 20:31; il secondo lotto porta il numero della votazione che il primo ha avuto.
 * 2. **Il computer spento e riacceso → «Ho ritrovato…» → nessun voto doppio.** La risposta del secondo lotto si perde: la coda lo rimanda tutto com'era, il
 *    server risponde «doppio» gesto per gesto, i voti e le righe restano quelli della prima volta.
 * 3. **Il foglio della sala stampato → «Registra dal foglio della sala».** Il foglio ha le deleghe ricevute già scritte; il giorno dopo si riportano apertura,
 *    entrate e voto con le ore del foglio: il registro le scrive con l'ora del foglio e «registrato dal foglio della sala», e il prospetto lo dice.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake('local');
    Storage::fake('public');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** I lotti del file in comune, con i numeri di questa sala al posto dei segnaposti. @param array<string, int> $numeri @return list<array<string, mixed>> */
function csrLotti(array $numeri): array
{
    $testo = (string) file_get_contents(base_path('tests/Fixtures/sala/lotti_senza_rete.json'));
    foreach ($numeri as $nome => $numero) {
        $testo = str_replace(['"{' . $nome . '}"', '{' . $nome . '}'], [(string) $numero, (string) $numero], $testo);
    }

    return json_decode($testo, true, flags: JSON_THROW_ON_ERROR)['lotti'];
}

/** La seconda aperta alle 18:42, con dieci condòmini entrati alle 18:45; poi la rete del portatile cade. */
function csrSala($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['marco', 'rita', ...SalaDiViaRoma12::altri(8)]);

    return [$s, $a, $seduta, ['seduta' => $seduta->id, 'rossi' => $s->persone['rossi']->id, 'c40' => $s->persone['c40']->id,
        'punto1' => $a->punti()->where('numero', 1)->value('id')]];
}

it('catena 1: i gesti senza rete arrivano quando la rete torna, nell\'ordine, con l\'ora del gesto e l\'ora di arrivo nel registro', function () {
    [$s, $a, $seduta, $numeri] = csrSala($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:31');
    $riga = ViaRoma12::ultimaRiga();

    $lotti = csrLotti($numeri);
    $primo = $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $a]), $lotti[0])->assertOk()->json();
    expect(array_column($primo['risposte'], 'esito'))->toBe(['applicato', 'applicato', 'applicato'])
        ->and($primo['ora_del_server'])->toBe('2026-12-15T19:31:00Z');
    $votazione = (int) $primo['risposte'][2]['votazione_id'];
    $lotti = csrLotti($numeri + ['votazione' => $votazione]);
    $secondo = $this->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $a]), $lotti[1])->assertOk()->json();
    expect(array_column($secondo['risposte'], 'esito'))->toBe(['applicato', 'applicato']);

    // Il registro: l'entrata di Rossi con l'ora del gesto (19:39, corretta dallo scarto del portatile) e l'ora di arrivo (20:31).
    $entrata = ViaRoma12::righe($riga)->first(fn ($r) => str_contains((string) $r->riepilogo, 'Rossi Mario entra in sala'));
    expect($entrata)->not->toBeNull()
        ->and($entrata->riepilogo)->toContain('alle 19:39')
        ->and($entrata->avvenuta_il->format('Y-m-d H:i'))->toBe('2026-12-15 18:39')
        ->and($entrata->registrata_il->format('Y-m-d H:i'))->toBe('2026-12-15 19:31');
    // La votazione conta chi era dentro alle 19:40: anche Rossi e Condomino 40, entrati senza rete.
    $v = VotazioneDelPunto::query()->findOrFail($votazione);
    expect($v->intervenuti['numero'])->toBe(12)
        ->and(VotoInSala::query()->where('votazione_id', $votazione)->where('voto', 'contrario')->pluck('nome')->all())->toBe(['Condomino 40'])
        ->and(PresenzaInSala::query()->where('seduta_id', $seduta->id)->where('anagrafica_id', $s->persone['rossi']->id)->value('entrata_il')?->format('H:i'))->toBe('18:39');
});

it('catena 2: la risposta persa e i gesti rimandati com\'erano: «doppio» gesto per gesto, nessun voto doppio, nessuna riga in più', function () {
    [$s, $a, , $numeri] = csrSala($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:31');
    $primo = $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $a]), csrLotti($numeri)[0])->assertOk()->json();
    $lotti = csrLotti($numeri + ['votazione' => (int) $primo['risposte'][2]['votazione_id']]);
    $this->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $a]), $lotti[1])->assertOk();
    $voti = VotoInSala::query()->orderBy('id')->get(['testa', 'voto', 'versione'])->toArray();
    $presenze = PresenzaInSala::query()->count();
    $riga = ViaRoma12::ultimaRiga();

    // Il portatile riacceso rimanda tutto: il deposito non sapeva che il server aveva risposto.
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:40');
    $ancora = array_merge(...array_map(fn ($l) => $this->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $a]), $l)->assertOk()->json('risposte'), $lotti));
    expect(array_column($ancora, 'esito'))->toBe(['doppio', 'doppio', 'doppio', 'doppio', 'doppio'])
        ->and($ancora[0]['messaggio'])->toBe('Questo gesto era già arrivato alle 20:31: conta una volta.')
        ->and(VotoInSala::query()->orderBy('id')->get(['testa', 'voto', 'versione'])->toArray())->toBe($voti)
        ->and(PresenzaInSala::query()->count())->toBe($presenze)
        ->and(ViaRoma12::righe($riga))->toHaveCount(0);
});

it('catena 3: il foglio della sala stampato con le deleghe → il giorno dopo «Registra dal foglio della sala» con le ore del foglio → il registro e il prospetto lo dicono', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-13 17:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['gallo']->id,
        'delegato' => ['nome' => 'Bianchi Giorgio'], 'ricevuta_il' => '2026-12-13'])->assertSessionHasNoErrors();
    cdsSenzaStudio();

    // Un capo: il foglio stampato il giorno prima, con la delega già scritta e le colonne delle ore.
    $foglio = $this->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a]))->assertOk()->getContent();
    $testo = '';
    for ($i = 1; $i <= cdsNumeroPagine((string) $foglio); $i++) {
        $testo .= ' ' . cdsTesto((string) $foglio, $i);
    }
    expect($testo)->toContain('Gallo Enzo')->toContain('delega a Bianchi Giorgio')->toContain('Entrata')->toContain('deserta alle')->toContain('Votato alle');

    // L'altro capo: mercoledì 16/12 si riporta la sala del 15/12 dal foglio, con le ore scritte a mano.
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:12');
    $riga = ViaRoma12::ultimaRiga();
    $delega = $a->deleghe()->firstOrFail();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id, 'dal_foglio' => true], '2026-12-14 08:40'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => SalaDiViaRoma12::seduta($a)->id, 'dal_foglio' => true,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], '2026-12-15 18:42'));
    $seduta = SalaDiViaRoma12::seduta($a)->refresh();
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)], '2026-12-15 18:45');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['delega_id' => $delega->id], 'come' => 'in_sala'],
        '2026-12-15 18:50'));
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:05'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:05'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v['votazione_id'], 'esito' => 'approvata'], '2026-12-15 19:06',
        (int) VotazioneDelPunto::query()->findOrFail($v['votazione_id'])->versione));

    $righe = ViaRoma12::righe($riga);
    $apertura = $righe->first(fn ($r) => str_contains((string) $r->riepilogo, 'aperta in seconda convocazione'));
    $conferma = $righe->first(fn ($r) => str_contains((string) $r->riepilogo, 'punto 1'));
    expect($apertura->riepilogo)->toEndWith('· registrato dal foglio della sala')
        ->and($apertura->avvenuta_il->format('Y-m-d H:i'))->toBe('2026-12-15 17:42')
        ->and($apertura->registrata_il->format('Y-m-d H:i'))->toBe('2026-12-16 09:12')
        ->and($conferma->riepilogo)->toContain('registrato dal foglio della sala')
        ->and($conferma->avvenuta_il->format('Y-m-d H:i'))->toBe('2026-12-15 18:06')
        ->and($seduta->refresh()->dal_foglio)->toBeTrue()
        // Il delegato del foglio porta la delega di Gallo: il voto conta dodici intervenuti.
        ->and(VotazioneDelPunto::query()->findOrFail($v['votazione_id'])->intervenuti['numero'])->toBe(12);

    // E il prospetto delle presenze e dei voti lo dice.
    $prospetto = (string) $this->get(route('admin.gestionale.assemblee.sala.prospetto', [$s->c, $a]))->assertOk()->getContent();
    $testo = '';
    for ($i = 1; $i <= cdsNumeroPagine($prospetto); $i++) {
        $testo .= ' ' . cdsTesto($prospetto, $i);
    }
    expect($testo)->toContain('registrata dal foglio della sala')->toContain('Bianchi Giorgio')->toContain('per delega di Gallo Enzo')->toContain('Votato alle 19:05');
});
