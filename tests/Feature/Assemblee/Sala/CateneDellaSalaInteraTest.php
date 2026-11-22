<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoDelega;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Assemblee\VotoInSala;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\AssembleeSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/SalaDiViaRoma12.php';
require_once __DIR__ . '/../../Pdf/Support/CartaDelloStudio.php';

/**
 * Le catene della sala da un capo all'altro, con i pezzi delle pagine e della tenuta senza rete insieme al motore:
 *
 * 1. **Il voto senza rete → la coda → l'invio → la votazione nel server → il prospetto.** Il lotto è quello che la pagina della sala manda quando la rete
 *    torna (`tests/Fixtures/sala/voto_senza_rete.json`, lo stesso file che `Sala.test.ts` fa scrivere alla pagina): un punto votato tutto senza rete, con la
 *    seduta, il punto e il numero al posto del numero della votazione; il server lo applica gesto per gesto, e il prospetto lo dice.
 * 2. **«Segna deserta» dopo aver aperto → la prima deserta → la seconda convocazione → il verbale.** La prima aperta alle 8:05 con tre condòmini, segnata
 *    deserta alle 8:40 con il gesto del menu della sala; martedì la seconda si apre, si vota e si chiude; la lettura per il verbale ha la prima con l'appello e
 *    la seconda tenuta, e il prospetto scrive «aperta alle 8:05 · deserta».
 * 3. **L'impostazione cambiata a sala aperta → la delega decisa con la regola dell'apertura.** Con la sala aperta lo studio salva «Decide il presidente» dalla
 *    sua card; la delega oltre il quinto segnata dopo si rifiuta con l'articolo, come all'apertura; la prop della sala dice le scelte ferme.
 * 4. **La delega con la scansione → «Apri» → il file.** Il corpo di «Segna una delega ricevuta» con la scansione; la pagina dell'assemblea porta l'indirizzo
 *    nella riga della delega; l'indirizzo apre lo stesso file.
 * 5. **La sala → il foglio con quello che c'è → scritto a mano → «Registra dal foglio della sala» → nessun doppione.** Alle 20:10 il portatile si scarica: si
 *    stampa il foglio compilato (le presenze con le ore, il voto del punto 1); si continua a penna; il giorno dopo si riporta il foglio, e quello che era già
 *    segnato e viene riscritto risponde «Era già così» o si rifiuta, senza righe nuove.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    Storage::fake('local');
    Storage::fake('public');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il testo di tutte le pagine di un PDF. */
function cduTesto(string $pdf): string
{
    $testo = '';
    for ($i = 1; $i <= cdsNumeroPagine($pdf); $i++) {
        $testo .= ' ' . cdsTesto($pdf, $i);
    }

    return (string) preg_replace('/\s+/u', ' ', $testo);
}

it('catena 1: il punto votato senza rete → la coda lo manda quando la rete torna → il server lo applica con la seduta e il punto → il prospetto lo stampa', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'rita', ...SalaDiViaRoma12::altri(12)]);
    $punto = (int) $a->punti()->where('numero', 1)->value('id');
    $numeri = ['seduta' => $seduta->id, 'punto' => $punto, 'testa_contrario' => 'p-' . $s->persone['rita']->id];
    $testo = (string) file_get_contents(base_path('tests/Fixtures/sala/voto_senza_rete.json'));
    foreach ($numeri as $nome => $numero) {
        $testo = str_replace('"{' . $nome . '}"', is_int($numero) ? (string) $numero : '"' . $numero . '"', $testo);
    }
    $lotto = json_decode($testo, true, flags: JSON_THROW_ON_ERROR)['lotto'];
    cdsSenzaStudio();

    $risposte = $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $a]), $lotto)->assertOk()->json('risposte');

    $v = VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->where('punto_id', $punto)->firstOrFail();
    expect(array_column($risposte, 'esito'))->toBe(['applicato', 'applicato', 'applicato', 'applicato', 'applicato'])
        ->and(array_values(array_unique(array_column($risposte, 'votazione_id'))))->toBe([(int) $v->id])
        ->and((string) $v->gesto)->toBe($lotto['gesti'][0]['gesto'])
        ->and($v->esito->value)->toBe('approvata_con_modifiche')
        ->and($v->nota)->toBe('Votato senza rete, il Wi-Fi è caduto alle 19:38.')
        ->and(VotoInSala::query()->where('votazione_id', $v->id)->where('voto', 'contrario')->pluck('nome')->all())->toBe(['Colombo Rita'])
        ->and($v->delibera_id)->not->toBeNull();

    $prospetto = cduTesto((string) $this->get(route('admin.gestionale.assemblee.sala.prospetto', [$s->c, $a]))->assertOk()->getContent());
    expect($prospetto)->toContain('Votato alle 19:45')->toContain('Colombo Rita')->toContain('Approvata con modifiche')
        ->toContain('Nota del presidente sul voto: Votato senza rete, il Wi-Fi è caduto alle 19:38.');
});

it('catena 2: la prima aperta, l\'appello, «Segna deserta» → la seconda si apre martedì → la lettura per il verbale e il prospetto hanno l\'appello della prima', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $prima = SalaDiViaRoma12::seduta($a, 'prima');
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:45');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $prima->id, 'presidente' => ['anagrafica_id' => $s->persone['rossi']->id],
        'segretario' => ['nome' => 'L\'amministratore']], '2026-12-14 08:05'));
    foreach (['bianchi', 'rita', 'gallo'] as $chi) {
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $prima->id, 'persona' => ['anagrafica_id' => $s->persone[$chi]->id],
            'come' => 'in_sala'], '2026-12-14 08:10', 0));
    }
    // Il gesto come lo manda il menu ⋯ della sala, «Segna deserta la prima convocazione», con l'ora della finestra.
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prima->id], '2026-12-14 08:40'));
    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['da_aprire']['convocazione'])->toBe('seconda')->and($a->stato->value)->toBe('convocata');

    // Martedì: la seconda si apre (la prima è deserta), si vota il punto 1 e si chiude.
    ConvocazioneDiViaRoma12::oggi('2026-12-15 18:40');
    $seconda = SalaDiViaRoma12::seduta($a);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $seconda->id, 'presidente' => ['anagrafica_id' => $s->persone['rossi']->id],
        'segretario' => ['nome' => 'L\'amministratore']], '2026-12-15 18:42'));
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:00');
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'rita', ...SalaDiViaRoma12::altri(12)]);
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seconda->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    $esito = VotazioneDelPunto::query()->findOrFail($v['votazione_id']);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $esito->id, 'esito' => $esito->esito_proposto->value],
        '2026-12-15 19:02', (int) $esito->versione));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seconda->id, 'non_trattati' => 'rinviata'], '2026-12-15 20:40'));

    $verbale = app(LetturaDellaSala::class)->perIlVerbale($a->refresh());
    $diPrima = collect($verbale['sedute'])->firstWhere('id', $prima->id);
    $diSeconda = collect($verbale['sedute'])->firstWhere('id', $seconda->id);
    expect($verbale['stato'])->toBe('chiusa')
        ->and($diPrima['esito'])->toBe('deserta')
        ->and($diPrima['aperta_il'])->toBe('2026-12-14T07:05:00Z')
        ->and(array_column($diPrima['presenze'], 'uscita_il'))->toBe(['2026-12-14T07:40:00Z', '2026-12-14T07:40:00Z', '2026-12-14T07:40:00Z'])
        ->and($diPrima['impronta_fotografia'])->not->toBeNull()
        ->and($diSeconda['esito'])->toBe('tenuta')
        ->and(collect($verbale['punti'])->firstWhere('numero', 1)['votazioni'][0]['seduta_id'])->toBe($seconda->id);

    cdsSenzaStudio();
    $prospetto = cduTesto((string) $this->get(route('admin.gestionale.assemblee.sala.prospetto', [$s->c, $a]))->assertOk()->getContent());
    expect($prospetto)->toContain('Aperta alle 08:05 · deserta, segnata alle 08:40')->toContain('Gallo Enzo In sala 08:10 08:40');
});

it('catena 3: a sala aperta lo studio salva «Decide il presidente» dalla card → la delega oltre il quinto si decide con la regola dell\'apertura', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->admin->assignRole(Role::firstOrCreate(['name' => \App\Enums\Role::AMMINISTRATORE->value, 'guard_name' => 'web']));
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['c30']);

    // Il corpo come lo manda «Salva» della card «Assemblee» delle impostazioni dello studio.
    $this->actingAs($s->admin)->patch(route('impostazioni.assemblee.update'), ['delega_oltre_il_quinto' => 'decide_il_presidente',
        'voto_discorde_comproprietari' => 'astenuti', 'giorni_di_posta' => 5])->assertSessionHasNoErrors();
    expect(app(AssembleeSettings::class)->delega_oltre_il_quinto)->toBe('decide_il_presidente');

    // In sala, come la manda «Delega» della riga di chi delega: due deleghe a Condomino 30, poi la terza che supera il quinto del valore.
    foreach (['rita', 'gallo', 'bianchi'] as $i => $chi) {
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('delega', ['delegante_anagrafica_id' => $s->persone[$chi]->id,
            'delegato' => ['anagrafica_id' => $s->persone['c30']->id]], '2026-12-15 18:5' . $i));
    }

    $terza = DelegaDellAssemblea::query()->latest('id')->firstOrFail();
    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($terza->stato)->toBe(StatoDelega::RIFIUTATA)
        ->and($terza->motivo)->toEndWith('oltre un quinto del valore (art. 67 c. 1 disp. att.).')
        ->and($sala['impostazioni'])->toMatchArray(['delega_oltre_il_quinto' => 'rifiuta', 'ferme_all_apertura' => true])
        ->and($seduta->refresh()->fotografia['impostazioni']['delega_oltre_il_quinto'])->toBe('rifiuta');
});

it('catena 4: «Segna una delega ricevuta» con la scansione → la riga della delega nel passo «In sala» ha l\'indirizzo → «Apri» apre lo stesso file', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 11:00');
    // Il corpo come lo manda «Segna una delega ricevuta» (una visita con il file).
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['c30']->id,
        'delegato' => ['anagrafica_id' => $s->persone['rossi']->id], 'ricevuta_il' => '2026-12-10',
        'scansione' => UploadedFile::fake()->createWithContent('delega_c30.pdf', '%PDF-1.4 la delega firmata di Condomino 30')])->assertSessionHasNoErrors();

    $indirizzo = route('admin.gestionale.assemblee.deleghe.scansione', [$s->c, $a, DelegaDellAssemblea::query()->firstOrFail()]);
    $this->get(route('admin.gestionale.assemblee.show', [$s->c, $a, 'scheda' => 'sala']))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->reloadOnly('sala', fn (AssertableInertia $r) => $r->where('sala.deleghe.0.scansione', $indirizzo)));

    $r = $this->get($indirizzo)->assertOk();
    expect($r->streamedContent())->toBe('%PDF-1.4 la delega firmata di Condomino 30')
        ->and($r->headers->get('Content-Type'))->toBe('application/pdf');
});

it('catena 5: la sala → «Stampa il foglio con quello che c\'è» alle 20:10 → si continua a penna → «Registra dal foglio della sala» → nessun doppione', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'rita', ...SalaDiViaRoma12::altri(12)]);
    $persona = fn (string $chi) => ['anagrafica_id' => $s->persone[$chi]->id];
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => $persona('c30')], '2026-12-15 19:30', 1));
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:40'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:41'));
    $voto = VotazioneDelPunto::query()->findOrFail($v['votazione_id']);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $voto->id, 'esito' => $voto->esito_proposto->value], '2026-12-15 19:45',
        (int) $voto->versione));
    cdsSenzaStudio();

    // Un capo: alle 20:10, dal menu ⋯ della sala, il foglio con quello che c'è.
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:10');
    $indirizzo = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin)['rotte']['foglio_compilato'];
    $foglio = cduTesto((string) $this->actingAs($s->admin)->get($indirizzo)->assertOk()->getContent());
    expect($foglio)->toContain('Compilato fino alle 20:10 del 15/12/2026')->toContain('Seconda convocazione aperta alle 18:42')
        ->toContain('Colombo Rita Int. 12 90,00 Presente 18:45')->toContain('Condomino 30 Int. 30 27,75 Presente 18:45 19:30')
        ->toContain('Votato alle 19:40')->toContain('Approvata, confermato alle 19:45');

    // L'altro capo: il giorno dopo si riporta il foglio scritto a penna, dalla sala con «Registra dal foglio della sala».
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $presenze = PresenzaInSala::query()->where('seduta_id', $seduta->id)->count();
    $riga = ViaRoma12::ultimaRiga();
    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    $risposte = SalaDiViaRoma12::manda($this, $s, $a, [
        // Già sul foglio stampato, riscritto per sbaglio: l'entrata di Rossi e il voto del punto 1.
        SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $persona('rossi'), 'come' => 'in_sala'], '2026-12-15 18:45',
            $sala['versioni']['anagrafica:' . $s->persone['rossi']->id]),
        SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')], '2026-12-15 19:40'),
        // Scritto a mano dopo le 20:10.
        SalaDiViaRoma12::gesto('esce', ['seduta_id' => $seduta->id, 'persona' => $persona('c31')], '2026-12-15 20:20', $sala['versioni']['anagrafica:' . $s->persone['c31']->id]),
        SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => $persona('c45'), 'come' => 'in_sala'], '2026-12-15 20:15', 0),
    ])->assertOk()->json('risposte');

    expect(array_column($risposte, 'esito'))->toBe(['applicato', 'rifiutato', 'applicato', 'applicato'])
        ->and($risposte[0]['messaggio'])->toBe('Era già così: niente da cambiare.')
        ->and($risposte[1]['messaggio'])->toBe('Il punto 1 è già votato.')
        ->and(PresenzaInSala::query()->where('seduta_id', $seduta->id)->count())->toBe($presenze + 1)
        ->and(VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->count())->toBe(1)
        ->and(ViaRoma12::righe($riga)->pluck('riepilogo')->all())->toBe([
            'Assemblea ordinaria del 15/12/2026: Condomino 31 esce alle 20:20',
            'Assemblea ordinaria del 15/12/2026: Condomino 45 entra in sala alle 20:15',
        ]);
});
