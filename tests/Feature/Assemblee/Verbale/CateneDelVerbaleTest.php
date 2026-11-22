<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Evento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\DiarioDellAssemblea;
use App\Services\Assemblee\Verbale\DestinatariDelVerbale;
use App\Services\Assemblee\Verbale\RegistroDeiVerbali;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Le catene del verbale (§8 del progetto), ognuna da un capo all'altro, letta dove la legge chi la usa: la pagina, il registro, l'Inbox, la scheda della
 * persona, il Diario.
 *
 * - C1 la sala → il verbale: la discussione scritta in sala, le parti «Dati della sala», il voto corretto dopo e «aggiorna le parti segnate»;
 * - C2 la bozza firmata → la registrazione → il registro dei verbali, con l'annotazione della prima deserta e la stampa;
 * - C3 la registrazione → la trasmissione → i trenta giorni: la PEC, la lettera, la consegna, la scheda della persona, il Diario, «Può impugnare fino al»;
 * - C4 il verbale → le delibere: il numero dove si legge già;
 * - C5 il verbale → il registro delle modifiche e il Diario, con l'ora del gesto e l'ora d'arrivo;
 * - C6 il verbale → l'Action inbox;
 * - C7 la sala deserta e la riunione a mano → il registro;
 * - C8 la delibera di nomina → il registro di nomina e revoca.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La prop `verbale` della pagina «Il verbale», dalla rotta vera. @return array<string, mixed> */
function cdvPagina($t, $s, $a): array
{
    $r = $t->actingAs($s->admin)->get(route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a]))->assertOk();

    return $r->viewData('page')['props']['verbale'];
}

it('C1 · la sala → il verbale: la discussione scritta in sala, le parti della sala, il voto corretto dopo e le parti aggiornate', function () {
    [$s, $a, $seduta, $v] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $p4 = VerbaleDiViaRoma12::punto($a, 4);
    // In sala: la discussione del punto 4 scritta sotto il voto, dalla rotta della sala, con la seduta (il verbale nasce).
    SalaDiViaRoma12::applica($this, $s, $a, ['gesto' => (string) \Illuminate\Support\Str::uuid(), 'tipo' => 'testo', 'dati' => ['seduta_id' => $seduta->id,
        'parte' => 'punto:' . $p4, 'documento' => StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo('L\'amministratore illustra i tre preventivi ricevuti.')])],
        'base' => 0] + array_intersect_key(SalaDiViaRoma12::gesto('x', [], '2026-12-15 19:50'), ['orologio' => 1, 'segnato_il' => 1]));
    $sala = $this->actingAs($s->admin)->getJson(route('admin.gestionale.assemblee.sala.stato', [$s->c, $a]))->json();
    expect($sala['verbale']['discussioni'][(string) $p4]['revisione'])->toBe(1)
        ->and(StrutturaDelVerbale::testo($sala['verbale']['discussioni'][(string) $p4]['documento']))->toBe('L\'amministratore illustra i tre preventivi ricevuti.')
        ->and($sala['rotte']['verbale'])->toBe(route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $a, 'da' => 'sala']));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));

    // Nel verbale: generato dal modello, con le parti della sala e la discussione sotto il voto del punto 4.
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $pagina = cdvPagina($this, $s, $a);
    $intero = $pagina['verbale']['parti']['intero']['documento'];
    $blocchi = StrutturaDelVerbale::blocchi($intero);
    expect($blocchi['costituzione:' . $seduta->id])->toContain('Sono presenti, di persona o per delega, 11 condòmini su 26, per 494,25 millesimi su 1.000')
        ->and(StrutturaDelVerbale::testo($intero))->toContain('L\'amministratore illustra i tre preventivi ricevuti.')
        ->and($pagina['verbale']['sala_cambiata'])->toBeNull();

    // In sala, dopo la chiusura e prima della registrazione: il condomino 35 era favorevole. Nel verbale: «I dati della sala sono cambiati».
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:25');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v[4], 'testa' => VerbaleDiViaRoma12::testa($v[4], 'Condomino 35'),
        'voto' => 'favorevole', 'perche' => 'Il presidente aveva letto male la mano alzata.'], '2026-12-15 21:20', 1));
    VerbaleDiViaRoma12::conferma($this, $s, $a, $v[4], '2026-12-15 21:21');
    $pagina = cdvPagina($this, $s, $a);
    expect($pagina['verbale']['sala_cambiata'])->not->toBeNull();
    $this->actingAs($s->admin)->post($pagina['rotte']['aggiorna_sala'])->assertSessionHasNoErrors();
    $blocchi = StrutturaDelVerbale::blocchi(cdvPagina($this, $s, $a)['verbale']['parti']['intero']['documento']);
    expect($blocchi['voto:' . $v[4]])->toContain('Favorevoli: 9 condòmini per 371,50 millesimi')
        ->and(cdvPagina($this, $s, $a)['verbale']['sala_cambiata'])->toBeNull();
});

it('C2 · la bozza firmata → la registrazione → il registro dei verbali, con l\'annotazione della prima deserta, e la stampa', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $bozza = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.bozza', [$s->c, $a, $verbale]))->assertOk();
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $pagina = cdvPagina($this, $s, $a);
    expect($pagina['verbale'])->toMatchArray(['stato' => 'registrato', 'numero' => 42, 'sottoscritto_il' => '2026-12-15'])
        ->and($pagina['verbale']['stampata']['impronta'])->toBe(hash('sha256', (string) $bozza->getContent()));

    $registro = $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.registro', $s->c))->assertOk()->viewData('page')['props']['registro'];
    expect(array_map(fn ($r) => [$r['numero'], $r['giorno'], $r['assemblea']['parola'], $r['contenuto'], $r['parola']], $registro['righe']))->toBe([
        [null, '2026-12-14', 'Ordinaria, prima convocazione', 'mancata costituzione', 'Mancata costituzione'],
        [42, '2026-12-15', 'Ordinaria, seconda convocazione', '5 delibere · 1 dichiarazione', 'Sottoscritto'],
    ])->and($registro['prossimo_numero'])->toBe(43);
    $stampa = $this->get(route('admin.gestionale.assemblee.registro.stampa', $s->c));
    expect($stampa->status())->toBe(200)->and($stampa->headers->get('Content-Type'))->toBe('application/pdf');
});

it('la spedizione delle lettere del verbale accetta il giorno di Roma anche dopo la mezzanotte di Roma, quando a Greenwich è ancora ieri (revisione del 22/11)', function () {
    [$s, $a, , $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$s->persone['bianchi']->id]])->assertOk();
    $lettera = RegistroInvio::query()->where('tipo', 'verbale')->where('anagrafica_id', $s->persone['bianchi']->id)->firstOrFail();
    // Le 23:30 UTC del 15/12 sono le 00:30 del 16/12 a Roma: la finestra propone il 16/12, e il server lo accetta.
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-12-15 23:30:00', 'UTC'));
    $this->post(route('admin.gestionale.assemblee.verbale.spedizione', [$s->c, $a, $verbale]), ['invii' => [$lettera->id], 'canale' => 'raccomandata',
        'data' => '2026-12-16'])->assertSessionHasNoErrors();
});

it('C3 · la registrazione → la trasmissione → i trenta giorni: la PEC e la lettera, la consegna, la scheda della persona, il Diario, «Può impugnare fino al»', function () {
    [$s, $a, , $v] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->where('anagrafica_id', $s->persone['c37']->id)->update(['mezzo' => 'pec', 'pec' => 'c37@pec.it']);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:37');
    ConvocazioneDiViaRoma12::conLaCasella();
    $trasmissione = cdvPagina($this, $s, $a)['verbale']['trasmissione'];
    expect($trasmissione['per_mezzo'])->toMatchArray(['pec' => 1])->and($trasmissione['quanti'])->toBe(18);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.pec', [$s->c, $a, $verbale]))->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$s->persone['bianchi']->id]])->assertOk();
    $lettera = RegistroInvio::query()->where('tipo', 'verbale')->where('anagrafica_id', $s->persone['bianchi']->id)->firstOrFail();
    $this->post(route('admin.gestionale.assemblee.verbale.spedizione', [$s->c, $a, $verbale]), ['invii' => [$lettera->id], 'canale' => 'raccomandata',
        'data' => '2026-12-15'])->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $lettera]), ['esito' => 'consegnata', 'giorno' => '2026-12-18'])
        ->assertSessionHasNoErrors();

    expect(RegistroInvio::query()->where('tipo', 'verbale')->pluck('canale', 'anagrafica_id')->all())
        ->toBe([$s->persone['c37']->id => 'pec', $s->persone['bianchi']->id => 'stampa']);
    $voce = collect(app(\App\Services\Gestionale\Lettere\CorrispondenzaDellaPersona::class)->documenti($s->c, $s->persone['bianchi']))->firstWhere('tipo', 'verbale');
    expect($voce)->toMatchArray(['numero' => 42, 'assemblea_del' => '2026-12-15'])->and($voce['righe'][0]['esito'])->toBe('consegnata');
    $invii = collect(DiarioDellAssemblea::righe($a->refresh(), 'invii')['righe'])->pluck('frase')->all();
    expect($invii)->toContain('Verbale n. 42, raccomandata a Bianchi Anna: spedita il 15/12');

    $termini = cdvPagina($this, $s, $a)['verbale']['termini'];
    $delibera1 = collect($termini['delibere'])->firstWhere('punto', 1);
    expect(collect($delibera1['chi'])->firstWhere('nome', 'Bianchi Anna'))->toMatchArray(['perche' => 'assente', 'dal' => '2026-12-18', 'fino_al' => '2027-01-18'])
        ->and($delibera1['per_tutti'])->toBeNull()
        ->and($termini['per_tutti'])->toBeNull();
});

it('C4 · il verbale → le delibere: il numero si legge dove si legge già, nella frase della delibera e nella pagina dell\'assemblea', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $delibera = Delibera::query()->where('assemblea_id', $a->id)->orderBy('id')->firstOrFail();
    expect(DeliberaInParole::frase($delibera->refresh()))->not->toContain('verbale n.');
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();

    expect($a->refresh()->numero_verbale)->toBe('42')->and($a->verbale_documento_id)->toBe($verbale->refresh()->documento_id)
        ->and(DeliberaInParole::frase($delibera->refresh()))->toContain('(verbale n. 42)')
        ->and(\App\Http\Controllers\Assemblee\PerLePagine::assemblea($a)['numero_verbale'])->toBe('42')
        ->and(\App\Services\Assemblee\DelibereDelCondominio::riga($delibera)['frase'])->toContain('(verbale n. 42)');
});

it('C5 · il verbale → il registro delle modifiche e il Diario: la versione arrivata senza rete con l\'ora in cui è stata scritta e l\'ora d\'arrivo', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $verbale = VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    $p4 = 'punto:' . VerbaleDiViaRoma12::punto($a, 4);
    $doc = fn ($t) => StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo($t)]);
    ConvocazioneDiViaRoma12::oggi('2026-12-15 20:31');
    VerbaleDiViaRoma12::manda($this, $s, $a, [
        VerbaleDiViaRoma12::testo($verbale->id, $p4, $doc('Il preventivo'), '2026-12-15 20:13', 0),
        VerbaleDiViaRoma12::testo($verbale->id, $p4, $doc('Il preventivo della ditta Edil'), '2026-12-15 20:16', 1),
        VerbaleDiViaRoma12::testo($verbale->id, $p4, $doc('Il preventivo della ditta Edil, € 18.400'), '2026-12-15 20:22', 2),
    ]);
    // La pausa fra le 20:13 e le 20:16 fa la versione 1 (il testo delle 20:13), quella fra le 20:16 e le 20:22 la versione 2 (il testo delle 20:16).
    $riga = collect(DiarioDellAssemblea::righe($a->refresh(), 'modifiche')['righe'])->first(fn ($r) => str_contains((string) $r['frase'], 'versione 2'));
    expect($riga)->not->toBeNull()
        ->and($riga['frase'])->toContain('la discussione del punto 4')
        ->and($riga['registro']['avvenuta_il'])->toBe('15/12/2026 20:16')
        ->and($riga['registro']['registrata_il'])->toBe('15/12/2026 20:31')
        ->and($riga['registro']['scheda'])->toMatchArray(['tipo' => 'assemblea', 'id' => $a->id]);
});

it('C6 · il verbale → l\'Action inbox: «Registrare» alla chiusura, chiuso dalla registrazione; «Trasmettere», chiuso dall\'ultima spedizione', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $compiti = fn (string $tipo) => Evento::query()->where('meta->type', $tipo)->where('meta->context->assemblea_id', $a->id)->get();
    expect($compiti('registrare_verbale'))->toHaveCount(1)->and($compiti('registrare_verbale')[0]->title)->toBe('Registrare il verbale di Via Roma 12 entro il 22/12');
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    expect($compiti('registrare_verbale')[0]->is_completed)->toBeTruthy()
        ->and($compiti('trasmettere_verbale'))->toHaveCount(1)->and($compiti('trasmettere_verbale')[0]->is_completed)->toBeFalsy();

    $daSpedire = collect(app(DestinatariDelVerbale::class)->per($verbale->refresh())['persone'])->where('riceve', true)->pluck('anagrafica_id')->all();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => $daSpedire])->assertOk();
    $righe = RegistroInvio::query()->where('tipo', 'verbale')->pluck('id')->all();
    expect($righe)->toHaveCount(count($daSpedire));
    $this->post(route('admin.gestionale.assemblee.verbale.spedizione', [$s->c, $a, $verbale]), ['invii' => $righe, 'canale' => 'raccomandata', 'data' => '2026-12-15'])
        ->assertSessionHasNoErrors();
    expect($compiti('trasmettere_verbale')[0]->refresh()->is_completed)->toBeTruthy();
});

it('C7 · la sala deserta e la riunione a mano → il registro: «Mancata costituzione» e «A mano» con il numero scritto', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id], '2026-12-14 08:30'));
    ViaRoma12::delibera($s, '2026-03-12', 'Rifacimento della facciata', 'approvata', '43');

    $righe = app(RegistroDeiVerbali::class)->righe($s->c, 'condominio');
    expect(array_map(fn ($r) => [$r['tipo'], $r['giorno'], $r['numero'], $r['parola']], $righe))->toBe([
        ['a_mano', '2026-03-12', '43', 'A mano'],
        ['annotazione', '2026-12-14', null, 'Mancata costituzione'],
    ])->and(app(RegistroDeiVerbali::class)->prossimoNumero($s->c, 'condominio'))->toBe(44);
});

it('C8 · la delibera di nomina → il registro di nomina e revoca: la riga proposta dal verbale registrato, confermata dall\'amministratore', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [['testo' => 'Nomina dell\'amministratore: Studio Marchetti', 'materia' => 'nomina']]), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'marco', 'rita', 'gallo', 'bianchi', ...SalaDiViaRoma12::altri()]);
    VerbaleDiViaRoma12::tuttiFavorevoli($this, $s, $a, 1, '2026-12-15 19:00');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.store', $s->c), ['amministratore' => 'Andrea Costa', 'dal' => '2025-04-10',
        'da_dove' => 'carta', 'estremi' => 'verbale del 10/04/2025'])->assertSessionHasNoErrors();
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();

    $registro = $this->get(route('admin.gestionale.assemblee.registro', $s->c))->viewData('page')['props']['registro'];
    $proposta = collect($registro['nomine'])->firstWhere('stato', 'proposta');
    expect($proposta)->toMatchArray(['evento' => 'nomina', 'dal' => '2026-12-15', 'da_dove' => 'verbale', 'verbale' => ['id' => $verbale->id, 'numero' => 42]]);
    $this->post(route('admin.gestionale.assemblee.registro.nomine.conferma', [$s->c, $proposta['id']]))->assertSessionHasNoErrors();
    $registro = $this->get(route('admin.gestionale.assemblee.registro', $s->c))->viewData('page')['props']['registro'];
    expect(collect($registro['nomine'])->pluck('stato')->all())->toBe(['confermata', 'confermata']);
});
