<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I due compiti che nascono generando un piano rate, «Quote non assegnate» e «Eccedenza già-versato», hanno il giorno di Roma in cui il piano è stato
 * generato, scritto come l'agenda scrive le ore: generato alle 00:30 di Roma, d'inverno o d'estate, il compito è di oggi, nei prossimi 7 giorni, non scaduto
 * da ieri.
 *
 * Il caso: Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`), U3 senza titolare a dicembre per le quote non assegnate; Neri che ha già versato
 * € 2.000,00 per «Pulizie» contro una quota di € 1.500,00 per l'eccedenza.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Services\Gestionale\Inbox\FasceDeiCompiti;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/AddebitiScenario.php';

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

const CPG_NOTTI = [
    'inverno, alle 00:30' => ['2026-11-16 00:30', '2026-11-16'],
    'ora legale, alle 00:30' => ['2026-07-16 00:30', '2026-07-16'],
];

/** Il piano generato di nuovo adesso, senza il compito di prima del tipo dato. */
function cpgGeneraAdesso(array $s, EventoTipo $tipo, string $adesso, array $opzioni): Evento
{
    Evento::where('tipo', $tipo->value)->delete();
    test()->travelTo(Carbon::parse($adesso, 'Europe/Rome')->utc());
    $s['piano']->rate()->delete();
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), ...$opzioni);

    return Evento::where('tipo', $tipo->value)->where('meta->context->piano_rate_id', $s['piano']->id)->sole();
}

it('il compito «Quote non assegnate» è del giorno di Roma in cui il piano è stato generato', function (string $adesso, string $oggi) {
    $s = adbVerdiFinoANovembre($this);
    $compito = cpgGeneraAdesso($s, EventoTipo::SCOPERTO_DOCUMENTATO, $adesso, ['forzaApplicazioneSaldi' => true, 'accettaScoperti' => true,
        'notaScoperti' => 'U3 senza titolare a dicembre, da addebitare', 'accettaDestinatari' => true, 'notaDestinatari' => 'Prova', 'esercizio' => $s['e']]);
    $giorno = $compito->start_time->format('Y-m-d');

    expect($giorno)->toBe($oggi)
        ->and(FasceDeiCompiti::oggi()->giorni($giorno))->toBe(0)
        ->and(FasceDeiCompiti::oggi()->fascia($giorno, EventoTipo::SCOPERTO_DOCUMENTATO->value))->toBe('prossimi_7');
})->with(CPG_NOTTI);

it('il compito «Eccedenza già-versato» è del giorno di Roma in cui il piano è stato generato', function (string $adesso, string $oggi) {
    $s = cpcViaVerdi($this, ['gia_versato' => [['U1', 'pulizie', 200000, 'Neri']]]);
    $compito = cpgGeneraAdesso($s, EventoTipo::ECCEDENZA_GIA_VERSATO_RILEVATA, $adesso, ['forzaApplicazioneSaldi' => true, 'accettaDestinatari' => true,
        'notaDestinatari' => 'Prova', 'esercizio' => $s['e']]);
    $giorno = $compito->start_time->format('Y-m-d');

    expect($compito->meta['context']['totale_cents'])->toBe(50000)
        ->and($giorno)->toBe($oggi)
        ->and(FasceDeiCompiti::oggi()->giorni($giorno))->toBe(0)
        ->and(FasceDeiCompiti::oggi()->fascia($giorno, EventoTipo::ECCEDENZA_GIA_VERSATO_RILEVATA->value))->toBe('prossimi_7');
})->with(CPG_NOTTI);
