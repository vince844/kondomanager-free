<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Evento;
use App\Models\Gestionale\PianoRate;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\Seguito\CoseDaFare;
use App\Services\Gestionale\RispostePromemoria;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Le cose da fare dopo la conferma: il documento di «Segna eseguita» che deve essere quello che dice il nome; la cosa fatta da una scheda che torna da fare
 * quando la delibera si toglie da quella scheda (R5 nei due sensi); «Un'altra cosa da fare» solo a riepilogo aperto; le risposte dal compito; gli id di
 * un'altra assemblea dello stesso condominio (R13); il deficit (catena 2).
 *
 * Che cosa non copre: la card della dashboard a video.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('il documento di «Segna eseguita» che si chiama .pdf ma non è un PDF si rifiuta, come le prove della consegna', function () {
    $s = ViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari', 'materia' => 'riscaldamento']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $cosa = CoseDaFare::crea($d, TipoCosaDaFare::FUORI_DAL_PROGRAMMA, 'Firma con Serramenti Marino', null, null, null, $s->admin);
    // Un file caricato vero: il tipo lo legge `finfo` dal contenuto (il file finto di prova dice il tipo dal nome).
    $tmp = tempnam(sys_get_temp_dir(), 'dsc');
    file_put_contents($tmp, "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>\n");
    $falso = new UploadedFile($tmp, 'contratto.pdf', null, null, true);

    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.cose.eseguita', [$s->c, $a, $cosa->id]),
        ['giorno' => '2026-12-16', 'documento' => $falso]);

    $r->assertSessionHasErrors('documento');
    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::DA_FARE);
});

it('tolta la delibera dal piano che aveva fatto «Crea il piano straordinario», la cosa torna da fare con un compito nuovo', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->gestione('Portone 2027', 'straordinaria')->voce('Portone 2027', 'Sostituzione del portone', 1450000, ['Proprietà' => 100]);
    $gestione = $s->gestioni['Portone 2027']['g'];
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino', 'materia' => 'lavori', 'importo' => 1450000,
        'oggetti' => [['gestione', $gestione->id, 'origine']]]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':cosa:crea_piano_straordinario';
    SeguitoDiViaRoma12::conferma($this, $s, $a, [$chiave], ['cose' => [$chiave => ['entro_il' => '2027-01-15']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::CREA_PIANO_STRAORDINARIO->value)->firstOrFail();
    $compitoDiPrima = (int) $cosa->evento_id;
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'nome' => 'Portone a rate',
        'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2027-01-31',
        'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false]);
    CollegamentiDelleDelibere::collega($piano, 'delibera', $d, null, $s->admin);
    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA);

    CollegamentiDelleDelibere::scollega($piano, 'delibera', $s->admin);

    $cosa->refresh();
    expect($cosa->stato)->toBe(StatoCosaDaFare::DA_FARE)
        ->and($cosa->oggetto_tipo)->toBeNull()
        ->and((int) $cosa->evento_id)->not->toBe($compitoDiPrima)
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeFalse();
});

it('«Un\'altra cosa da fare» su una delibera il cui riepilogo non è ancora aperto si rifiuta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi();
    $a = SeguitoDiViaRoma12::tenuta($this, $s, [['testo' => 'Riscaldamento: orari', 'materia' => 'riscaldamento']], [], null, false);
    $d = SeguitoDiViaRoma12::delibera($a, 1);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.seguito.cose.store', [$s->c, $a, $d]), ['testo' => 'Avvisa il manutentore'])
        ->assertSessionHasErrors('testo');

    expect(CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->count())->toBe(0);
});

it('dal compito: «Fatto» fa la cosa, «Ricordamelo» sposta il suo giorno; la respinta senza origine non crea compiti', function () {
    $s = ViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari', 'materia' => 'riscaldamento'], ['testo' => 'Cambio dell\'orario del portiere', 'materia' => 'altro']],
        '2026-12-15', [2 => 'respinta']);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $respinta = SeguitoDiViaRoma12::delibera($a, 2);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [], ['altre' => [['delibera_id' => $d->id, 'testo' => 'Avvisa il manutentore', 'entro_il' => '2026-12-20'],
        ['delibera_id' => $d->id, 'testo' => 'Scrivi al fornitore del gasolio', 'entro_il' => '2026-12-21']]])->assertOk();
    [$uno, $due] = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->orderBy('id')->get()->all();

    app(RispostePromemoria::class)->ricordameloDelCompito(Evento::query()->findOrFail($due->evento_id), '2027-01-08', null, $s->admin);
    app(RispostePromemoria::class)->fattoDelCompito(Evento::query()->findOrFail($uno->evento_id), 'Fatto per telefono', $s->admin);

    expect($uno->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and($uno->fresh()->nota)->toBe('Fatto per telefono')
        ->and($due->fresh()->entro_il->format('Y-m-d'))->toBe('2027-01-08')
        ->and($due->fresh()->stato)->toBe(StatoCosaDaFare::DA_FARE)
        ->and(CosaDaFareDellaDelibera::query()->where('delibera_id', $respinta->id)->count())->toBe(0)
        ->and(Evento::query()->where('meta->type', 'delibera_respinta')->count())->toBe(0);
});

it('R13 con un\'altra assemblea dello stesso condominio: la delibera e la cosa da fare del 15/12 sotto l\'indirizzo di un\'altra sono 404', function () {
    $s = ViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari', 'materia' => 'riscaldamento']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $cosa = CoseDaFare::crea($d, TipoCosaDaFare::FUORI_DAL_PROGRAMMA, 'Avvisa il manutentore', null, null, null, $s->admin);
    CoseDaFare::segnaEseguita($cosa, '2026-12-16', null, UploadedFile::fake()->create('lettera.pdf', 10, 'application/pdf'), $s->admin);
    $altra = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Varie ed eventuali', 'materia' => 'varie']], '2026-12-10');
    $p = ['condominio' => $s->c->id, 'assemblea' => $altra->id, 'delibera' => $d->id, 'cosa' => $cosa->id];
    $sbagliate = [];
    $provate = 0;
    foreach (Route::getRoutes() as $r) {
        if (! str_contains((string) $r->getActionName(), '\\Assemblee\\Seguito\\')) {
            continue;
        }
        $nomi = $r->parameterNames();
        if (! in_array('delibera', $nomi, true) && ! in_array('cosa', $nomi, true)) {
            continue;
        }
        $provate++;
        $metodo = collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        $stato = $this->actingAs($s->admin)->json($metodo, route((string) $r->getName(), array_intersect_key($p, array_flip($nomi))))->status();
        if ($stato !== 404) {
            $sbagliate[] = "{$metodo} {$r->getName()} → {$stato}";
        }
    }

    $r = SeguitoDiViaRoma12::conferma($this, $s, $altra, [], ['altre' => [['delibera_id' => $d->id, 'testo' => 'Intrusa']], 'modifiche' => [$d->id => 'Intrusa']]);

    expect($sbagliate)->toBe([])->and($provate)->toBe(12)
        ->and($r->json('esiti.altra:0.esito'))->toBe('sconosciuta')
        ->and($d->fresh()->dati)->toBeNull()
        ->and($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA);
});

it('catena 2, il deficit: approvato, «Prepara il piano di ripianamento»; il piano con la delibera la fa e chiude il compito del deficit', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $deficit = SeguitoDiViaRoma12::compito($s, 'pianifica_ripianamento_deficit');
    $deficit->forceFill(['title' => 'Deficit da ripianare'])->saveQuietly();
    $p = \App\Services\Assemblee\PropostaDelPunto::perLaScheda($deficit);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => $p['testo'], 'materia' => $p['materia'], 'oggetti' => [['compito', $deficit->id, 'origine']],
        'chiave_fonte' => 'compito:' . $deficit->id, 'fonte' => 'programma']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':cosa:prepara_ripianamento';
    SeguitoDiViaRoma12::conferma($this, $s, $a, [$chiave], ['cose' => [$chiave => ['entro_il' => '2027-01-15']]])->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::PREPARA_RIPIANAMENTO->value)->firstOrFail();
    $s->gestione('Ripianamento 2026', 'straordinaria');
    $piano = PianoRate::create(['gestione_id' => $s->gestioni['Ripianamento 2026']['g']->id, 'condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id,
        'nome' => 'Ripianamento', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 2, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2027-01-31',
        'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false]);

    CollegamentiDelleDelibere::collega($piano, 'delibera', $d, null, $s->admin);

    expect($cosa->fresh()->stato)->toBe(StatoCosaDaFare::FATTA)
        ->and((bool) $deficit->fresh()->is_completed)->toBeTrue()
        ->and((bool) Evento::query()->findOrFail($cosa->evento_id)->is_completed)->toBeTrue();
});
