<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\Sala\ProspettoDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Le catene del lavoro senza rete che non si perde, il lato del server, da un capo all'altro:
 *
 * 1. **Senza rete «Segna deserta» e «Apri» → il ritorno della rete → presenze e voti arrivati, nessuno perso.** Il lotto è quello che la pagina manda
 *    (`tests/Fixtures/sala/deserta_e_apri_senza_rete.json`, lo stesso file che `Sala.test.ts` fa scrivere alla pagina): la prima deserta, l'apertura della
 *    seconda senza il controllo (la pagina non lo aveva), un'entrata, «Si vota» e un voto. Intanto il controllo è cambiato (Gallo Enzo senza avviso): la
 *    seduta si apre «da decidere», tutto arriva, il presidente decide dopo, e il prospetto lo scrive.
 * 2. **L'uscita tardiva → «Due versioni» → la scelta → il voto giusto nel prospetto.** Scelto il voto (Colombo Rita era in sala), il voto conta e il
 *    prospetto lo scrive con il nome; l'uscita arrivata tardi non c'è.
 * 3. **La seconda deserta → i punti per la prossima assemblea.** Aperta la seconda e fatto l'appello, senza voti: l'assemblea è deserta e il memo del punto
 *    torna fra quelli per la prossima, nella card della dashboard del condominio.
 *
 * L'altra catena, l'uscita dal programma → le copie e il deposito vuoti → la pagina senza rete che non si apre, è tutta nel browser: `uscita.test.ts`.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake('local');
    Storage::fake('public');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il lotto del file in comune, con i numeri di questa sala al posto dei segnaposti. @param array<string, int> $numeri @return array<string, mixed> */
function cnspLotto(array $numeri): array
{
    $testo = (string) file_get_contents(base_path('tests/Fixtures/sala/deserta_e_apri_senza_rete.json'));
    foreach ($numeri as $nome => $numero) {
        $testo = str_replace(['"{' . $nome . '}"', '{' . $nome . '}'], [(string) $numero, (string) $numero], $testo);
    }

    return json_decode($testo, true, flags: JSON_THROW_ON_ERROR)['lotto'];
}

it('catena 1: senza rete «Segna deserta» e «Apri» → la rete torna → presenze e voti arrivati, nessuno perso; il presidente decide l\'apertura dopo', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s, ['gallo']);
    $seconda = SalaDiViaRoma12::seduta($a);
    $punto = (int) $a->punti()->where('numero', 1)->value('id');
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:00');

    $risposte = $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $a]), cnspLotto([
        'prima' => SalaDiViaRoma12::seduta($a, 'prima')->id, 'seconda' => $seconda->id, 'presidente' => $s->persone['rossi']->id,
        'rossi' => $s->persone['rossi']->id, 'punto' => $punto,
    ]))->assertOk()->json('risposte');

    expect(array_column($risposte, 'esito'))->toBe(['applicato', 'applicato', 'applicato', 'applicato', 'applicato'])
        ->and($seconda->refresh()->aperta_il->format('Y-m-d H:i'))->toBe('2026-12-15 17:42')
        ->and($seconda->apertura['scelta'])->toBe('da_decidere')
        ->and(PresenzaInSala::query()->where('seduta_id', $seconda->id)->pluck('nome')->all())->toBe(['Rossi Mario'])
        ->and(VotazioneDelPunto::query()->where('seduta_id', $seconda->id)->where('punto_id', $punto)->firstOrFail()->voti()->pluck('voto')->map(fn ($v) => $v->value)->all())
        ->toBe(['contrario']);

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apertura_decidi', ['seduta_id' => $seconda->id, 'motivo' => 'Gallo Enzo è presente'],
        '2026-12-15 20:55'));
    expect(collect(app(ProspettoDellaSala::class)->dati($a->refresh())['sedute'])->last()['scelta'])
        ->toContain('il presidente ha deciso alle 20:55: Gallo Enzo (Int. 7) non risulta fra i convocati. Motivo: Gallo Enzo è presente');
});

it('catena 2: l\'uscita tardiva → «Due versioni» → la scelta → il voto giusto nel prospetto', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $p1 = $a->punti()->where('numero', 1)->value('id');
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p1], '2026-12-15 19:40'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:40'));
    $versione = (int) VotazioneDelPunto::query()->findOrFail($v['votazione_id'])->voti()->where('testa', 'p-' . $s->persone['rita']->id)->value('versione');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => 'p-' . $s->persone['rita']->id,
        'voto' => 'contrario'], '2026-12-15 19:41', $versione));

    // Il telefono di Marco, senza rete, aveva segnato l'uscita di Colombo Rita alle 19:38: arriva alle 19:50.
    $uscita = SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['rita']->id]],
        '2026-12-15 19:38', 1)], 'telefono di Marco')->json('risposte.0');
    expect($uscita['esito'])->toBe('in_conflitto');
    $conferma = fn () => SalaDiViaRoma12::manda($this, $s, $a, [SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v['votazione_id'], 'esito' => 'approvata'],
        '2026-12-15 19:52')])->json('risposte.0');
    expect($conferma()['esito'])->toBe('rifiutato');

    // Andrea sceglie il voto: Colombo Rita era in sala.
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('scegli', ['gesto' => $uscita['gesto'], 'scelta' => 'quella'], '2026-12-15 19:51'));
    expect($conferma()['esito'])->toBe('applicato');
    $votazione = collect(collect(app(ProspettoDellaSala::class)->dati($a->refresh())['sedute'])->last()['votazioni'])->first();
    expect(json_encode($votazione, JSON_UNESCAPED_UNICODE))->toContain('Colombo Rita')
        ->and(VotazioneDelPunto::query()->findOrFail($v['votazione_id'])->conteggi['contrari']['nomi'])->toBe([['nome' => 'Colombo Rita', 'millesimi' => 9000000]])
        ->and(PresenzaInSala::query()->where('seduta_id', $seduta->id)->where('anagrafica_id', $s->persone['rita']->id)->value('uscita_il'))->toBeNull();
});

it('catena 3: la seconda deserta dopo l\'appello → l\'assemblea deserta → il memo del punto torna fra quelli per la prossima assemblea', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $punto = $a->punti()->where('numero', 3)->firstOrFail();
    $memo = MemoDellAssemblea::create(['condominio_id' => $s->c->id, 'ambito' => 'condominio', 'testo' => 'Il cancello carrabile da rifare', 'stato' => 'nel_punto',
        'punto_id' => $punto->id, 'assemblea_id' => $a->id, 'created_by' => $s->admin->id]);
    expect(collect(MemoriaDelCondominio::perLaDashboard($s->c)['punti'])->pluck('testo')->all())->not->toContain('Il cancello carrabile da rifare');
    $seconda = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi']);

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $seconda->id], '2026-12-15 19:15'));

    expect($a->refresh()->stato)->toBe(StatoAssemblea::DESERTA)
        ->and($memo->refresh()->stato->value)->toBe('in_attesa')
        ->and($memo->assemblea_id)->toBeNull()
        ->and(collect(MemoriaDelCondominio::perLaDashboard($s->c)['punti'])->pluck('testo')->all())->toContain('Il cancello carrabile da rifare');
});
