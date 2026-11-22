<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Verbale\DestinatariDelVerbale;
use App\Services\Assemblee\Verbale\TerminiDellImpugnazione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * I trenta giorni dell'assente quando la raccomandata va in giacenza (decisione 615 (1), «nel modo prudente»; la proposta (a) della domanda della tappa 4):
 * Bianchi Anna, assente ai punti 1 e 2, ha l'avviso di giacenza il 18/12/2026.
 *
 * - Ritira il 28/12: i termini partono dal ritiro, fino al 27/01/2027 (non dall'avviso, fino al 18/01/2027: nove giorni prima della lettura prudente).
 * - Non ritira: dalla compiuta giacenza, dieci giorni dopo l'avviso (28/12), fino al 27/01/2027.
 * - La pagina e la prova della trasmissione dicono da quale giorno contano; la convocazione resta com'è (il giorno dell'avviso, Cass. 23396/2017).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il verbale n. 42 registrato, la lettera di Bianchi Anna stampata e spedita il 15/12, l'avviso di giacenza il 18/12. */
function tgdGiacenza($t): array
{
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($t);
    $verbale = VerbaleDiViaRoma12::genera($t, $s, $a);
    VerbaleDiViaRoma12::registra($t, $s, $verbale)->assertSessionHasNoErrors();
    $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.lettere', [$s->c, $a, $verbale]), ['anagrafiche' => [$s->persone['bianchi']->id]]);
    $riga = RegistroInvio::query()->where('tipo', 'verbale')->where('anagrafica_id', $s->persone['bianchi']->id)->firstOrFail();
    $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.spedizione', [$s->c, $a, $verbale]), ['invii' => [$riga->id], 'canale' => 'raccomandata',
        'data' => '2026-12-15'])->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-12-18 10:00');
    $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $riga]), ['esito' => 'in_giacenza', 'giorno' => '2026-12-18'])
        ->assertSessionHasNoErrors();

    return [$s, $a, $verbale->refresh(), $riga];
}

function tgdTerminiDiAnna($s, $verbale): array
{
    $p = collect(app(DestinatariDelVerbale::class)->per($verbale)['persone'])->firstWhere('nome', 'Bianchi Anna');

    return collect($p['termini'])->firstWhere('perche', 'assente');
}

it('ritirata il 28/12 dopo l\'avviso del 18/12: i trenta giorni partono dal ritiro, fino al 27/01/2027, e la pagina lo dice', function () {
    [$s, $a, $verbale, $riga] = tgdGiacenza($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-28 11:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $riga]), ['esito' => 'ritirata', 'giorno' => '2026-12-28'])
        ->assertSessionHasNoErrors();
    expect($riga->refresh()->ricevuta_il->format('Y-m-d'))->toBe('2026-12-18')
        ->and(tgdTerminiDiAnna($s, $verbale))->toMatchArray(['dal' => '2026-12-28', 'fino_al' => '2027-01-27', 'dal_come' => 'ritiro'])
        ->and(DestinatariDelVerbale::daQuando('ritiro', '2026-12-28'))->toBe(' (dal ritiro del 28/12/2026)');
    $punto1 = collect(app(TerminiDellImpugnazione::class)->perIlVerbale($verbale))->firstWhere('punto', 1);
    expect(collect($punto1['chi'])->firstWhere('nome', 'Bianchi Anna'))->toMatchArray(['fino_al' => '2027-01-27', 'dal_come' => 'ritiro']);
});

it('non ritirata: dalla compiuta giacenza, dieci giorni dopo l\'avviso, fino al 27/01/2027', function () {
    [$s, $a, $verbale] = tgdGiacenza($this);
    expect(tgdTerminiDiAnna($s, $verbale))->toMatchArray(['dal' => '2026-12-28', 'fino_al' => '2027-01-27', 'dal_come' => 'compiuta_giacenza']);
});

it('consegnata a mano o per PEC: il giorno della consegna, come prima', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $riga = new RegistroInvio(['esito' => 'consegnata', 'ricevuta_il' => '2026-12-18', 'dati' => ['mezzo' => 'pec']]);
    expect(DestinatariDelVerbale::giornoDeiTermini($riga))->toBe(['2026-12-18', 'consegna']);
});
