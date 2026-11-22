<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Esercizio;
use App\Models\Tabella;
use App\Services\Assemblee\Seguito\AvvisoAiCondomini;
use App\Services\Assemblee\Seguito\PianoStraordinario;
use App\Services\Assemblee\TabellaDiProprieta;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';

/**
 * Le correzioni della simulazione del 21/11/2026 sul seguito delle delibere: l'importo di un punto di lavori che arriva fino alla proposta del piano
 * straordinario (A1), la tabella che il piano propone (A2), il testo e la priorità di «Avvisa i condòmini» (M7, M8).
 *
 * Che cosa non copre: la finestra del punto a video (`FinestraDelPuntoImporto.test.ts`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La cosa «Crea il piano straordinario» del punto di lavori dato, con le tabelle dette. @param array<string, array<string, int>> $tabelle @param array<string, mixed> $punto */
function csCosa($t, array $tabelle, array $punto = []): array
{
    $s = ViaRoma12::nuovo($t);
    foreach ($tabelle as $nome => $millesimi) {
        $s->tabella($nome, $millesimi);
    }
    $s->titolare('A', 'ugo')->titolare('B', 'anna');
    Esercizio::factory()->create(['condominio_id' => $s->c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $a = SeguitoDiViaRoma12::suCarta($s, [$punto + ['testo' => 'Sostituzione del portone d\'ingresso', 'materia' => 'lavori', 'importo' => 1450000]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($t, $s, $a, null, ['cose' => [$d->id . ':cosa:crea_piano_straordinario' => ['entro_il' => '2027-01-15', 'a_chi' => 'me']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::CREA_PIANO_STRAORDINARIO->value)->firstOrFail();

    return ['s' => $s, 'a' => $a, 'd' => $d, 'cosa' => $cosa];
}

it('A1 · il punto di lavori scritto con € 14.500,00 arriva alla delibera approvata e il piano straordinario lo propone con 1450000', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'anna');
    Esercizio::factory()->create(['condominio_id' => $s->c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Approvazione del rendiconto 2026', 'materia' => 'varie']]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), ['testo' => 'Sostituzione del portone d\'ingresso',
        'materia' => 'lavori', 'maggioranza' => 'ordinaria', 'importo' => '14.500,00'])->assertSessionHasNoErrors();
    $punto = $a->punti()->where('materia', 'lavori')->sole();
    expect((int) $punto->importo)->toBe(1450000);

    $esiti = [];
    foreach ($a->punti()->get() as $p) {
        $esiti[(int) $p->id] = ['esito' => 'approvata'];
    }
    $a = \App\Services\Assemblee\StatiDellAssemblea::tenutaFuoriDalProgramma($a->fresh(), $s->admin, $esiti, '2026-12-15');
    $d = $a->delibere()->where('punto_id', $punto->id)->sole();
    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['cose' => [$d->id . ':cosa:crea_piano_straordinario' => ['entro_il' => '2027-01-15', 'a_chi' => 'me']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::CREA_PIANO_STRAORDINARIO->value)->firstOrFail();

    $proposta = PianoStraordinario::proposta($cosa);
    expect($proposta['delibera']['importo'])->toBe(1450000)->and($proposta['voce']['importo'])->toBe(1450000);
});

it('A2 · la tabella proposta: quella del punto, poi quella segnata di proprietà generale (anche se un\'altra viene prima per nome), poi «Proprietà» o «Generale», altrimenti nessuna', function () {
    // Segnata come di proprietà generale: vince su «Acqua — consumi» (prima per nome e per id) e su «Proprietà…» che non è segnata.
    $x = csCosa($this, ['Acqua — consumi' => ['A' => 500, 'B' => 500], 'Proprietà' => ['A' => 600, 'B' => 400], 'Generale' => ['A' => 700, 'B' => 300]]);
    TabellaDiProprieta::scegli($x['s']->c, $x['s']->tabelle['Generale']);
    expect(PianoStraordinario::proposta($x['cosa'])['voce']['tabella_id'])->toBe($x['s']->tabelle['Generale']->id);
});

it('A2 · senza una tabella segnata si cerca «Proprietà» o «Generale» per nome, e la prima per id non vince mai', function () {
    $x = csCosa($this, ['Acqua — consumi' => ['A' => 500, 'B' => 500], 'Generale' => ['A' => 700, 'B' => 300]]);
    expect(PianoStraordinario::proposta($x['cosa'])['voce']['tabella_id'])->toBe($x['s']->tabelle['Generale']->id);
});

it('A2 · senza una tabella segnata né un nome che dica la proprietà, nessuna proposta: mai la prima a caso', function () {
    $x = csCosa($this, ['Acqua — consumi' => ['A' => 500, 'B' => 500], 'Scale' => ['A' => 700, 'B' => 300]]);
    expect(PianoStraordinario::proposta($x['cosa'])['voce']['tabella_id'])->toBeNull();
});

it('A2 · la tabella scelta nel punto vince su tutte', function () {
    $x = csCosa($this, ['Acqua — consumi' => ['A' => 500, 'B' => 500], 'Proprietà' => ['A' => 600, 'B' => 400]], []);
    $x['d']->punto->forceFill(['tabella_id' => $x['s']->tabelle['Acqua — consumi']->id])->saveQuietly();
    TabellaDiProprieta::scegli($x['s']->c, $x['s']->tabelle['Proprietà']);
    expect(PianoStraordinario::proposta($x['cosa']->fresh())['voce']['tabella_id'])->toBe($x['s']->tabelle['Acqua — consumi']->id);
});

it('M7 e M8 · «Avvisa i condòmini» dice di che cosa parla il punto anche senza la frase per la bacheca, e propone la priorità bassa', function () {
    $x = csCosa($this, ['Proprietà' => ['A' => 600, 'B' => 400]]);
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $x['d']->id)->where('tipo', TipoCosaDaFare::AVVISA_CONDOMINI->value)->first();
    if ($cosa === null) {
        $cosa = CosaDaFareDellaDelibera::forceCreate(['delibera_id' => $x['d']->id, 'condominio_id' => $x['s']->c->id, 'tipo' => TipoCosaDaFare::AVVISA_CONDOMINI->value,
            'testo' => 'Avvisa i condòmini', 'stato' => 'da_fare']);
    }
    $p = AvvisoAiCondomini::precompilata($cosa);
    expect($p['subject'])->toBe('Assemblea del 15/12/2026: Lavori nelle parti comuni')
        ->and($p['description'])->toBe('L\'assemblea ordinaria del 15/12/2026 ha approvato: lavori nelle parti comuni.')
        ->and($p['description'])->not->toContain('portone')
        ->and($p['priority'])->toBe('bassa');
});

it('M5 · a sala chiusa la pagina della sala porta com\'è finita: l\'ora della chiusura, la convocazione, se era costituita e i presenti; aperta o da aprire no', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Varie ed eventuali', 'materia' => 'varie', 'maggioranza' => 'senza_voto']]);
    $sala = app(\App\Services\Assemblee\Sala\LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    $seduta = $a->sedute()->where('esito', 'tenuta')->sole();
    expect($sala['chiusura'])->toMatchArray(['alle' => $seduta->chiusa_il->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('H:i'),
        'convocazione' => 'seconda', 'costituita' => true])
        ->and($sala['chiusura']['teste'])->toBeGreaterThan(0)->and($sala['chiusura']['teste'])->toBeLessThanOrEqual($sala['chiusura']['totale_teste'])
        ->and($sala['chiusura']['valore'])->toBeGreaterThan(0);
});

it('M9 e B3 · nel diario le righe della cosa da fare dicono quale cosa e che cosa è successo, senza ripetere «Creata»', function () {
    $x = csCosa($this, ['Proprietà' => ['A' => 600, 'B' => 400]]);
    $cose = CosaDaFareDellaDelibera::query()->where('delibera_id', $x['d']->id)->orderBy('id')->get();
    $piano = $cose->firstWhere('tipo', TipoCosaDaFare::CREA_PIANO_STRAORDINARIO);
    $avviso = $cose->firstWhere('tipo', TipoCosaDaFare::AVVISA_CONDOMINI);
    expect($piano)->not->toBeNull()->and($avviso)->not->toBeNull();
    \App\Services\Assemblee\Seguito\CoseDaFare::segnaEseguita($piano, '2026-12-16', null, null, $x['s']->admin);
    \App\Services\Assemblee\Seguito\CoseDaFare::nonServe($avviso, 'Lo dico in assemblea.', $x['s']->admin);

    $riga = fn (int $id, string $azione) => \App\Services\RegistroModifiche\RegistroPerLaPagina::riga(
        \App\Models\RegistroModifica::query()->where('oggetto_tipo', 'cosa_da_fare_della_delibera')->where('oggetto_id', $id)->where('azione', $azione)->firstOrFail(), []);
    $fatta = $riga((int) $piano->id, 'modificata');
    $nonServe = $riga((int) $avviso->id, 'modificata');
    $creata = $riga((int) $piano->id, 'creata');
    expect($fatta['cambiato'])->toBe('Crea il piano straordinario: fatta il 16/12/2026')
        ->and($nonServe['cambiato'])->toBe('Avvisa i condòmini: non serve più (Lo dico in assemblea.)')
        ->and($creata['cambiato'])->toStartWith('Creata · «')->and(substr_count($creata['cambiato'], 'Creata'))->toBe(1);
});

it('B8 · la riga delle varie nel passo 6 dice, dopo il memo salvato, che è nella memoria del condominio', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Varie ed eventuali', 'materia' => 'varie', 'maggioranza' => 'senza_voto']]);
    SeguitoDiViaRoma12::conferma($this, $s, $a)->assertOk();
    $riga = fn () => collect(\App\Services\Assemblee\Seguito\SeguitoDelleDelibere::perLaPagina($a->refresh(), $s->admin)['tabella'])->sole();
    expect($riga()['memo_salvato'])->toBeFalse();

    \App\Services\Assemblee\MemoriaDelCondominio::salva($s->c, ['testo' => 'Pulizia delle grondaie', 'origine_tipo' => 'punto', 'origine_id' => $a->punti()->value('id')], $s->admin);
    expect($riga()['memo_salvato'])->toBeTrue();
});
