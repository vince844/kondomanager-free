<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Evento;
use App\Services\Assemblee\Seguito\DopoIlRifiuto;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Services\Assemblee\Seguito\StatoDiEsecuzione;
use App\Services\Gestionale\RispostePromemoria;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * R7, la risposta a una delibera respinta: «Non serve» dal compito «Delibere respinte» fa ciò che fa «Chiudi con il motivo» (chiude anche i compiti dello sforo,
 * con la riga nella «Storia»); il passo dice «Respinta · risposta data il …», mai «Eseguita»; il motivo resta fra i dati della persona, fuori dal perché della
 * riga e dalla frase di chiusura dei compiti.
 *
 * Che cosa non copre: «Riproponi in assemblea» (la memoria del condominio ha i suoi test).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La ratifica della fattura n. 45 respinta il 15/12 su carta, con il compito dello sforo aperto. @return array{0: Beta51Scenario, 1: CosaDaFareDellaDelibera, 2: Evento, 3: \App\Models\Assemblee\Assemblea} */
function rarRatificaRespinta(\Tests\TestCase $t): array
{
    $s = ViaRoma12::nuovo($t);
    $f = ViaRoma12::fatturaInSforo($s);
    $sforo = SeguitoDiViaRoma12::compito($s, 'ratifica_sforo', ['fattura_id' => $f->id]);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Ratifica della spesa urgente di € 1.800,00 (fattura n. 45)', 'materia' => 'ratifica',
        'oggetti' => [['fattura_passiva', $f->id, 'origine']]]], '2026-12-15', [1 => 'respinta']);
    SeguitoDiViaRoma12::conferma($t, $s, $a)->assertOk();

    return [$s, CosaDaFareDellaDelibera::query()->where('tipo', TipoCosaDaFare::DOPO_IL_RIFIUTO->value)->firstOrFail(), $sforo, $a];
}

it('«Non serve» dal compito «Delibere respinte» chiude anche i compiti dello sforo, con la riga della risposta sulla delibera', function () {
    [$s, $cosa, $sforo] = rarRatificaRespinta($this);
    $prima = ViaRoma12::ultimaRiga();

    app(RispostePromemoria::class)->nonServeDelCompito(Evento::query()->findOrFail($cosa->evento_id), 'Pagata, se ne parla con l\'impresa', $s->admin);

    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::NON_SERVE)
        ->and($cosa->fresh()->chiusa_motivo)->toBe('Pagata, se ne parla con l\'impresa')
        ->and((bool) $sforo->fresh()->is_completed)->toBeTrue()
        ->and(ViaRoma12::righe($prima, 'delibera')->pluck('riepilogo')->all())->toContain('Respinta: chiusa');
});

it('il passo dice «Respinta · risposta data il 17/12/2026», mai «Eseguita»', function () {
    [$s, $cosa, $sforo, $a] = rarRatificaRespinta($this);
    ConvocazioneDiViaRoma12::oggi('2026-12-17 10:00');
    DopoIlRifiuto::chiudi($cosa, 'Pagata, se ne parla con l\'impresa', $s->admin);

    $seguito = StatoDiEsecuzione::seguito($cosa->delibera->fresh());
    $passo = SeguitoDelleDelibere::perIlPasso($a);

    expect($seguito['stato'])->toBe('risposta_data')
        ->and($seguito['parola'])->toBe('Respinta · risposta data il 17/12/2026')
        ->and($passo['stato'])->toBe('fatto');
});

it('il motivo di «Chiudi» resta fra i dati della persona: non è nel perché della riga della delibera né nella frase che chiude i compiti', function () {
    [$s, $cosa, $sforo] = rarRatificaRespinta($this);
    $prima = ViaRoma12::ultimaRiga();

    DopoIlRifiuto::chiudi($cosa, 'Pagata a Bianchi Luigi, se ne parla con lui', $s->admin);

    $riga = ViaRoma12::righe($prima, 'delibera')->first();
    $chiusure = \App\Models\Gestionale\RispostaPromemoria::query()->whereIn('evento_id', [(int) $cosa->evento_id, (int) $sforo->id])->get()
        ->map(fn ($r) => (string) $r->nota . ' ' . json_encode($r->registro, JSON_UNESCAPED_UNICODE))->implode(' ');
    expect((bool) $sforo->fresh()->is_completed)->toBeTrue()
        ->and((string) $riga->perche)->not->toContain('Bianchi')
        ->and($chiusure)->not->toContain('Bianchi')
        ->and($cosa->fresh()->chiusa_motivo)->toBe('Pagata a Bianchi Luigi, se ne parla con lui');
});
