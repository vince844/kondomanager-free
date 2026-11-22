<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Le catene del verbale come le leggono le pagine (le prop di Inertia della pagina «Il verbale», del registro dei verbali e del passo 5), da un capo all'altro:
 *
 * - la sala chiusa → «Genera il verbale» con i dati della sala → la registrazione con il numero → la trasmissione → i trenta giorni sulla riga di chi riceve;
 * - la discussione scritta in sala → la stessa nella pagina del verbale, e nel verbale generato;
 * - la delibera di nomina con il nome detto in sala → la riga proposta nel registro di nomina e revoca → confermata.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La prop `verbale` della pagina «Il verbale», letta dalla rotta vera. @return array<string, mixed> */
function catenaVerbalePagina($t, $s, $a): array
{
    $pagina = null;
    $t->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]))->assertOk()
        ->assertInertia(function (AssertableInertia $p) use (&$pagina) {
            $p->component('gestionale/assemblee/Verbale');
            $pagina = $p->toArray()['props']['verbale'];
        });

    return $pagina;
}

it('la sala chiusa → «Genera» con i dati della sala → registrato n. 42 → trasmesso → i trenta giorni sulla riga di chi riceve', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this);

    // Un capo: la pagina senza verbale, con i punti e la riga della sala; il passo 5 dice «da scrivere».
    $pagina = catenaVerbalePagina($this, $s, $a);
    expect($pagina['verbale'])->toBeNull()->and($pagina['seduta_id'])->toBe($seduta->id)->and($pagina['punti'])->toHaveCount(6)
        ->and(collect(\App\Http\Controllers\Assemblee\PerLePagine::passi($a->refresh()))->firstWhere('chiave', 'verbale')['riga'])->toStartWith('da scrivere · entro il ');

    // «Genera il verbale dal modello»: la parte «Dati della sala» della costituzione.
    $this->actingAs($s->admin)->post($pagina['rotte']['genera'], ['seduta_id' => $seduta->id, 'modello_id' => null])->assertSessionHasNoErrors();
    $pagina = catenaVerbalePagina($this, $s, $a);
    expect($pagina['verbale']['stato'])->toBe('bozza')
        ->and(StrutturaDelVerbale::blocchi($pagina['verbale']['parti']['intero']['documento'])['costituzione:' . $seduta->id])
        ->toContain('Sono presenti, di persona o per delega, 11 condòmini su 26');

    // «Registra il verbale sottoscritto» dalla rotta della pagina: il numero 42 dopo il 41 del libro.
    $this->actingAs($s->admin)->post($pagina['rotte']['registra'], ['copia' => VerbaleDiViaRoma12::copia(), 'sottoscritto_il' => '2026-12-15',
        'partenza' => ['scelta' => 'libro', 'ultimo' => 41, 'del' => '2026-10-22'], 'senza_la_bozza' => true])->assertSessionHasNoErrors();
    $pagina = catenaVerbalePagina($this, $s, $a);
    expect($pagina['verbale']['numero'])->toBe(42)->and($pagina['verbale']['stato'])->toBe('registrato')
        ->and($pagina['verbale']['trasmissione']['quanti'])->toBeGreaterThan(0);

    // La trasmissione dalla pagina: la lettera, la spedizione, la consegna.
    $persona = collect($pagina['verbale']['trasmissione']['persone'])->first(fn ($p) => $p['riceve'] && $p['mezzo']['mezzo'] === 'raccomandata');
    expect(collect($persona['termini'])->every(fn ($x) => $x['fino_al'] === null && $x['da_consegnare']))->toBeTrue();
    $this->actingAs($s->admin)->post($pagina['rotte']['lettere'], ['anagrafiche' => [$persona['anagrafica_id']]])->assertOk();
    $lettera = RegistroInvio::query()->where('tipo', 'verbale')->where('anagrafica_id', $persona['anagrafica_id'])->firstOrFail();
    $this->actingAs($s->admin)->post($pagina['rotte']['spedizione'], ['invii' => [$lettera->id], 'canale' => 'raccomandata', 'data' => now()->format('Y-m-d')])
        ->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $lettera]), ['esito' => 'consegnata', 'giorno' => now()->format('Y-m-d')])
        ->assertSessionHasNoErrors();

    // L'altro capo: sulla riga di chi riceve, i trenta giorni dalla consegna, con il giorno.
    $riga = collect(catenaVerbalePagina($this, $s, $a)['verbale']['trasmissione']['persone'])->firstWhere('anagrafica_id', $persona['anagrafica_id']);
    expect($riga['invio']['ricevuta_il'])->toBe(now()->format('Y-m-d'))
        ->and(collect($riga['termini'])->firstWhere('perche', 'assente')['fino_al'])->toBeString();
});

it('la discussione scritta in sala → la stessa nella pagina del verbale → nel verbale generato', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $p4 = VerbaleDiViaRoma12::punto($a, 4);
    $documento = StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo('Il sig. Marino dichiara di essere il titolare della prima impresa.')]);
    $istante = \Illuminate\Support\Carbon::parse('2026-12-15 20:40', 'Europe/Rome')->utc()->toIso8601ZuluString();
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $a]), ['dispositivo' => 'portatile di Andrea', 'gesti' => [[
        'gesto' => (string) \Illuminate\Support\Str::uuid(), 'tipo' => 'testo', 'dati' => ['seduta_id' => $seduta->id, 'parte' => 'punto:' . $p4, 'documento' => $documento],
        'base' => 0, 'orologio' => $istante, 'segnato_il' => $istante]]])->assertOk()->assertJsonPath('risposte.0.esito', 'applicato');

    // Nella pagina del verbale, con la sala ancora aperta: la stessa discussione sul punto 4.
    $pagina = catenaVerbalePagina($this, $s, $a);
    expect(StrutturaDelVerbale::testo($pagina['verbale']['parti']['punto:' . $p4]['documento']))->toBe('Il sig. Marino dichiara di essere il titolare della prima impresa.');

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
    VerbaleDiViaRoma12::genera($this, $s, $a);
    expect(StrutturaDelVerbale::testo(catenaVerbalePagina($this, $s, $a)['verbale']['parti']['intero']['documento']))
        ->toContain('Il sig. Marino dichiara di essere il titolare della prima impresa.');
});

it('la delibera di nomina con il nome detto in sala → la riga proposta nel registro → confermata', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [['testo' => 'Nomina dell\'amministratore', 'materia' => 'nomina']]), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'marco', 'rita', 'gallo', 'bianchi', ...SalaDiViaRoma12::altri()]);
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 1)],
        '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $r['votazione_id']], '2026-12-15 19:00'));
    VerbaleDiViaRoma12::conferma($this, $s, $a, (int) $r['votazione_id'], '2026-12-15 19:01', null, ['amministratore' => 'Studio Marchetti']);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a->refresh());
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();

    $registro = function () use ($s) {
        $dati = null;
        $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro', $s->c))->assertOk()
            ->assertInertia(function (AssertableInertia $p) use (&$dati) {
                $p->component('gestionale/assemblee/RegistroDeiVerbali');
                $dati = $p->toArray()['props']['registro'];
            });

        return $dati;
    };
    $proposta = collect($registro()['nomine'])->firstWhere('stato', 'proposta');
    expect($proposta)->toMatchArray(['amministratore' => 'Studio Marchetti', 'evento' => 'nomina', 'dal' => '2026-12-15'])
        ->and($proposta['verbale']['numero'])->toBe(42);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.conferma', [$s->c, $proposta['id']]))->assertSessionHasNoErrors();
    expect(collect($registro()['nomine'])->firstWhere('id', $proposta['id'])['stato'])->toBe('confermata');
});
