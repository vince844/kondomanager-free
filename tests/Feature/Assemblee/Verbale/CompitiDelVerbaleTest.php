<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Evento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * I compiti dell'Action inbox (V13, decisione 604 (3)): «Registrare il verbale di Via Roma 12 entro il 22/12» dalla chiusura, chiuso da sé con «registrato il
 * n. 42»; «Trasmettere il verbale n. 42 entro il 22/12» dalla registrazione, chiuso dall'ultima spedizione; «L'assemblea … è ancora in corso» con la seduta
 * aperta, dal giorno dopo; «Due versioni da scegliere»; «Da decidere in sala». Ciò che il programma crea e chiude da solo non va nel registro delle modifiche.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function cdvCompiti(int $assemblea, string $tipo)
{
    return Evento::query()->where('meta->type', $tipo)->where('meta->context->assemblea_id', $assemblea)->orderBy('id')->get();
}

it('«Registrare il verbale» nasce alla chiusura, sette giorni dopo, e si chiude da sé con la registrazione; poi «Trasmettere», chiuso dall\'ultima spedizione', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $registrare = cdvCompiti($a->id, 'registrare_verbale');
    expect($registrare)->toHaveCount(1)->and($registrare[0]->title)->toBe('Registrare il verbale di Via Roma 12 entro il 22/12')
        ->and($registrare[0]->is_completed)->toBeFalsy()->and($registrare[0]->start_time->format('Y-m-d H:i'))->toBe('2026-12-15 09:00');

    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $registrare = cdvCompiti($a->id, 'registrare_verbale');
    $trasmettere = cdvCompiti($a->id, 'trasmettere_verbale');
    expect($registrare[0]->is_completed)->toBeTruthy()->and(collect($registrare[0]->meta['risposte'] ?? [])->pluck('testo')->implode(' ') . ($registrare[0]->meta['chiuso'] ?? ''))
        ->toBeString()
        ->and($trasmettere)->toHaveCount(1)->and($trasmettere[0]->title)->toBe('Trasmettere il verbale n. 42 entro il 22/12')
        ->and($trasmettere[0]->description)->toContain('18 da spedire su 18');

    // Ogni destinatario ha il suo invio spedito: il compito si chiude.
    foreach (app(\App\Services\Assemblee\Verbale\DestinatariDelVerbale::class)->per($verbale->refresh())['persone'] as $p) {
        if ($p['riceve']) {
            RegistroInvio::create(['condominio_id' => $s->c->id, 'anagrafica_id' => $p['anagrafica_id'], 'tipo' => 'verbale', 'canale' => 'stampa',
                'documentabile_type' => $a->getMorphClass(), 'documentabile_id' => $a->id, 'dati' => ['mezzo' => 'raccomandata', 'verbale_id' => $verbale->id],
                'stato' => 'spedita', 'emesso_il' => now()]);
        }
    }
    app(\App\Services\Assemblee\Verbale\CompitiDelVerbale::class)->sincronizza($a);
    expect(cdvCompiti($a->id, 'trasmettere_verbale')[0]->is_completed)->toBeTruthy();
});

it('«L\'assemblea è ancora in corso» con la seduta aperta, dal giorno dopo; si chiude con la chiusura della sala', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $c = cdvCompiti($a->id, 'sala_in_corso');
    expect($c)->toHaveCount(1)->and($c[0]->start_time->format('Y-m-d H:i'))->toBe('2026-12-16 09:00')->and($c[0]->title)->toContain('è ancora in corso');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
    expect(cdvCompiti($a->id, 'sala_in_corso')[0]->is_completed)->toBeTruthy();
});

it('«Due versioni da scegliere» per un testo del verbale in conflitto, chiuso dalla scelta; «Da decidere in sala» per una delega da decidere', function () {
    [$s, $a, $seduta] = VerbaleDiViaRoma12::salaChiusa($this, false);
    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    $parte = 'punto:' . VerbaleDiViaRoma12::punto($a, 4);
    $doc = fn ($t) => \App\Services\Assemblee\Verbale\StrutturaDelVerbale::documento([\App\Services\Assemblee\Verbale\StrutturaDelVerbale::paragrafo($t)]);
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, $parte, $doc('a'), '2026-12-15 20:14', 0)], 'portatile');
    $id = VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, $parte, $doc('b'), '2026-12-15 20:15', 0)], 'tablet')->json('risposte.0.versione_id');
    $c = cdvCompiti($a->id, 'due_versioni');
    expect($c)->toHaveCount(1)->and($c[0]->description)->toBe('1 scelta fra due versioni, 1 nel verbale.');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.scegli', [$s->c, $a, $verbale, $id]), ['scelta' => 'quella']);
    expect(cdvCompiti($a->id, 'due_versioni')[0]->is_completed)->toBeTruthy();

    \App\Models\Assemblee\DelegaDellAssemblea::query()->create(['assemblea_id' => $a->id, 'delegante_anagrafica_id' => $s->persone['c40']->id, 'delegante_nome' => 'Condomino 40',
        'unita' => [], 'delegato_nome' => 'Mario Bianchi', 'arrivata_come' => 'carta_prima', 'ricevuta_il' => '2026-12-10', 'stato' => 'valida',
        'dati' => ['da_decidere' => true]]);
    app(\App\Services\Assemblee\Verbale\CompitiDelVerbale::class)->sincronizza($a);
    expect(cdvCompiti($a->id, 'da_decidere_in_sala'))->toHaveCount(1)->and(cdvCompiti($a->id, 'da_decidere_in_sala')[0]->description)->toContain('1 delega');
});

it('i compiti del programma non sono righe del registro delle modifiche', function () {
    $prima = (int) \App\Models\RegistroModifica::query()->max('id');
    [, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    expect(cdvCompiti($a->id, 'registrare_verbale'))->toHaveCount(1)
        ->and(\App\Models\RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'evento')->count())->toBe(0);
});
