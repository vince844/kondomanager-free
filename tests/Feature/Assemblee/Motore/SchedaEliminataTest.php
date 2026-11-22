<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\PianoRate;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R2 e R17): una scheda che se ne va porta via i suoi collegamenti alle delibere, nello stesso gesto, con la riga «Delibera: … →
 * nessuna». Andrea registra a mano la delibera del 14/05/2026 (verbale n. 7), la sceglie approvando il «Preventivo 2026», riporta il piano in bozza e lo
 * elimina: la delibera non è più usata da niente e si elimina. Un collegamento rimasto da prima (una scheda tolta senza passare dal modello) non trattiene la
 * delibera: se ne va con lei.
 *
 * Che cosa non copre: l'eliminazione dalle pagine di ogni scheda una per una (la fattura, la dispensa, l'accordo…): per quelle il test legge che l'evento del
 * modello è registrato, e lo prova davvero sulla fattura.
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('il piano in bozza eliminato dalla rotta toglie il collegamento, con la riga; poi la delibera a mano si elimina', function () {
    $s = ViaRoma12::conFacciata($this)->pianoOrdinario('Preventivo 2026', 'Facciata 2026', 12, '2026-01-05', 'prima_rata', false, true);
    $piano = $s->piano('Preventivo 2026');
    $d = ViaRoma12::delibera($s, '2026-05-14', 'Approvazione del preventivo 2026', 'approvata', '7');
    CollegamentiDelleDelibere::collega($piano, 'delibera', $d, null, $s->admin);
    $prima = ViaRoma12::ultimaRiga();

    $this->actingAs($s->admin)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $piano]));

    expect(PianoRate::query()->find($piano->id))->toBeNull()
        ->and(CollegamentoAllaDelibera::query()->count())->toBe(0)
        ->and(CollegamentiDelleDelibere::posti($d->fresh()))->toBe([])
        ->and(ViaRoma12::righe($prima)->count())->toBeGreaterThanOrEqual(1);
    DeliberaAMano::elimina($d->fresh(), $s->admin);
    expect(Delibera::query()->whereKey($d->id)->exists())->toBeFalse();
});

it('la fattura eliminata toglie la sua ratifica', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $d = ViaRoma12::delibera($s, '2026-11-18', 'Ratifica della spesa urgente', 'approvata', '42');
    CollegamentiDelleDelibere::collega($fattura, 'ratifica', $d, null, $s->admin);

    $fattura->delete();

    expect(CollegamentoAllaDelibera::query()->count())->toBe(0);
});

it('un collegamento rimasto da una scheda tolta senza il modello non trattiene la delibera, e se ne va con lei', function () {
    $s = ViaRoma12::conFacciata($this)->pianoOrdinario('Preventivo 2026', 'Facciata 2026', 12, '2026-01-05', 'prima_rata', false, true);
    $piano = $s->piano('Preventivo 2026');
    $d = ViaRoma12::delibera($s, '2026-05-14', 'Approvazione del preventivo 2026', 'approvata', '7');
    CollegamentiDelleDelibere::collega($piano, 'delibera', $d, null, $s->admin);
    DB::table('piani_rate')->where('id', $piano->id)->delete();

    expect(CollegamentiDelleDelibere::posti($d->fresh())[0]['esiste'])->toBeFalse();
    DeliberaAMano::elimina($d->fresh(), $s->admin);
    expect(Delibera::query()->whereKey($d->id)->exists())->toBeFalse()->and(CollegamentoAllaDelibera::query()->count())->toBe(0);
});

it('ogni scheda che si collega a una delibera ha l\'evento della sua eliminazione', function () {
    $senza = [];
    foreach (['fattura_passiva', 'piano_rate', 'cassa', 'accordo_rientro', 'dispensa_sei_mesi', 'regola_recupero', 'contributo_versato', 'rata_quote',
        'rendiconto_versione'] as $tipo) {
        if (! Event::hasListeners('eloquent.deleted: ' . SchedaDelCondominio::TIPI[$tipo][0])) {
            $senza[] = $tipo;
        }
    }
    if (! Event::hasListeners('eloquent.forceDeleted: ' . SchedaDelCondominio::TIPI['scrittura_contabile'][0])) {
        $senza[] = 'scrittura_contabile';
    }

    expect($senza)->toBe([]);
});
