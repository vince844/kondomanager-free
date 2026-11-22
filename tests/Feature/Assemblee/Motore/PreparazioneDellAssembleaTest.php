<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Evento;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\Assemblee\PreparazioneDellAssemblea;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §3 P2, R11, §4.6, §4.7 e §6.3 del progetto della tappa 2): preparare l'assemblea è una riga sola con i punti e le sedute nel
 * lotto; il giorno della riunione è la seconda convocazione; cambiare una data è la riga della seduta; riordinare è una riga; togliere un punto è la riga
 * del punto, e il memo torna; eliminarla dice i punti e i memo; l'agenda ha una voce nascosta per seduta; da convocata non si cambia più; «Annulla e
 * riconvoca» crea la nuova con i punti e i memo.
 *
 * Che cosa non copre: le rotte e le pagine (sono di chi fa le pagine: `AreaAssembleeTest`); i compiti della convocazione (l'aggancio c'è, il contenuto è
 * della convocazione).
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('preparare l\'assemblea è una riga sola, con i punti e le sedute nel lotto; il giorno è la seconda convocazione', function () {
    $s = ViaRoma12::nuovo($this);
    $prima = ViaRoma12::ultimaRiga();
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Approvazione del rendiconto 2025', 'materia' => 'rendiconto'], ['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);

    $righe = ViaRoma12::righe($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toBe('Assemblea ordinaria del 15/12/2026 preparata: 2 punti, prima convocazione 14/12/2026 alle 08:00, seconda 15/12/2026 alle 18:30')
        ->and($righe[0]->oggetto_tipo)->toBe('assemblea')->and($righe[0]->oggetto_id)->toBe($a->id)
        ->and($righe[0]->area)->toBe('assemblee')
        ->and($a->data->format('Y-m-d'))->toBe('2026-12-15')
        ->and($a->stato->value)->toBe('in_preparazione');
});

it('l\'agenda ha una voce per seduta, nascosta ai condòmini, con l\'ora come si legge; annullata, le voci se ne vanno', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s);
    $voci = Evento::query()->where('eventable_type', $a->getMorphClass())->where('eventable_id', $a->id)->orderBy('start_time')->get();

    expect($voci)->toHaveCount(2)
        ->and($voci[0]->visibility)->toBe('hidden')
        ->and((bool) $voci[0]->is_approved)->toBeTrue()
        ->and($voci[0]->start_time->format('Y-m-d H:i'))->toBe('2026-12-14 08:00')
        ->and($voci[1]->title)->toBe('Ordinaria del 15/12/2026 — seconda convocazione')
        ->and($voci[0]->condomini()->pluck('condomini.id')->all())->toBe([$s->c->id]);

    StatiDellAssemblea::annullaERiconvoca($a, 'Un errore nelle date', [['convocazione' => 'prima', 'numero' => 1, 'fissata_il' => '2026-12-21 08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2026-12-22 18:30']], $s->admin);
    expect(Evento::query()->where('eventable_id', $a->id)->where('eventable_type', $a->getMorphClass())->count())->toBe(0);
});

it('cambiare la seconda convocazione è la riga della seduta, con il prima e il dopo', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s);
    $prima = ViaRoma12::ultimaRiga();

    PreparazioneDellAssemblea::aggiorna($a, ['sedute' => [['convocazione' => 'prima', 'numero' => 1, 'fissata_il' => '2026-12-14 08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2026-12-16 18:30']]], $s->admin);

    $righe = ViaRoma12::righe($prima, 'seduta');
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->oggetto_nome)->toBe('Seconda convocazione')
        ->and($righe[0]->scheda_tipo)->toBe('assemblea')
        ->and($righe[0]->campi[0])->toMatchArray(['etichetta' => 'Il giorno e l\'ora', 'prima' => '15/12/2026 alle 18:30', 'dopo' => '16/12/2026 alle 18:30'])
        ->and($a->fresh()->data->format('Y-m-d'))->toBe('2026-12-16');
});

it('le date sbagliate non passano: la frase del controllo', function () {
    $s = ViaRoma12::nuovo($this);
    expect(fn () => PreparazioneDellAssemblea::crea($s->c, ['sedute' => [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'],
        ['convocazione' => 'seconda', 'fissata_il' => '2026-12-14 18:30']]], [], $s->admin))->toThrow(ValidationException::class);
});

it('riordinare è una riga sola; togliere un punto è la sua riga e il memo torna nella memoria; Varie resta in fondo', function () {
    $s = ViaRoma12::nuovo($this);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Sostituzione del portone d\'ingresso'], $s->admin);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Rendiconto', 'materia' => 'rendiconto'], ['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    $portone = MemoriaDelCondominio::nellOrdine($memo, $a, $s->admin);
    expect($a->punti()->pluck('testo')->all())->toBe(['Rendiconto', 'Sostituzione del portone d\'ingresso', 'Varie ed eventuali'])
        ->and($memo->fresh()->stato->value)->toBe('nel_punto');

    $prima = ViaRoma12::ultimaRiga();
    $ids = $a->punti()->pluck('id')->all();
    OrdineDelGiorno::riordina($a, [$ids[1], $ids[0], $ids[2]], $s->admin);
    expect(ViaRoma12::righe($prima)->pluck('riepilogo')->all())->toBe(['Ordine del giorno riordinato: il punto 2 diventa il 1; il punto 1 diventa il 2']);

    $prima = ViaRoma12::ultimaRiga();
    OrdineDelGiorno::togli(PuntoDellOrdineDelGiorno::query()->find($portone->id), $s->admin);
    $righe = ViaRoma12::righe($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->azione)->toBe('eliminata')
        ->and($righe[0]->oggetto_nome)->toBe('Punto 1 — Sostituzione del portone d\'ingresso')
        ->and($righe[0]->riepilogo)->toBe('Torna nella memoria del condominio.')
        ->and($a->punti()->pluck('numero')->all())->toBe([1, 2])
        ->and($memo->fresh()->stato->value)->toBe('in_attesa');
});

it('eliminarla in preparazione dice i punti e i memo; da convocata non si cambia e non si elimina', function () {
    $s = ViaRoma12::nuovo($this);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);
    $a = ViaRoma12::ordinaria($s, [MemoriaDelCondominio::comeProposta($memo), ['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    $prima = ViaRoma12::ultimaRiga();
    PreparazioneDellAssemblea::elimina($a, $s->admin);
    expect(ViaRoma12::righe($prima)->pluck('riepilogo')->all())->toBe(['Assemblea ordinaria del 15/12/2026 eliminata, in preparazione: 2 punti; 1 memo torna nella memoria'])
        ->and(Assemblea::query()->whereKey($a->id)->exists())->toBeFalse()
        ->and($memo->fresh()->stato->value)->toBe('in_attesa');

    $b = ViaRoma12::ordinaria($s);
    StatiDellAssemblea::convocata($b, $s->admin);
    expect(fn () => OrdineDelGiorno::aggiungi($b->fresh(), ['testo' => 'Un punto in più'], $s->admin))->toThrow(ValidationException::class)
        ->and(fn () => PreparazioneDellAssemblea::elimina($b->fresh(), $s->admin))->toThrow(ValidationException::class);
});

it('«Annulla e riconvoca»: la nuova copia i punti con i loro oggetti e i memo, con il rimando; la vecchia è annullata con il motivo', function () {
    $s = ViaRoma12::nuovo($this);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);
    $a = ViaRoma12::ordinaria($s, [array_merge(MemoriaDelCondominio::comeProposta($memo), ['oggetti' => [['gestione', 7, 'parla_di']]])]);
    StatiDellAssemblea::convocata($a, $s->admin);
    $prima = ViaRoma12::ultimaRiga();

    $nuova = StatiDellAssemblea::annullaERiconvoca($a->fresh(), 'La sala non è disponibile', [['convocazione' => 'prima', 'numero' => 1, 'fissata_il' => '2026-12-21 08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2026-12-22 18:30']], $s->admin);

    $righe = ViaRoma12::righe($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toBe('Assemblea ordinaria del 15/12/2026 annullata e riconvocata per il 22/12/2026')
        ->and($righe[0]->perche)->toBe('La sala non è disponibile')
        ->and($righe[0]->rimando)->toMatchArray(['tipo' => 'assemblea', 'id' => $nuova->id])
        ->and($a->fresh()->stato->value)->toBe('annullata')
        ->and($nuova->riconvocata_da_id)->toBe($a->id)
        ->and($nuova->punti()->first()->oggetti()->count())->toBe(1)
        ->and($memo->fresh()->assemblea_id)->toBe($nuova->id);
});

it('«Varie ed eventuali» resta in fondo: il riordino che la sposta in mezzo non passa', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s);
    $uno = OrdineDelGiorno::aggiungi($a, ['testo' => 'Rendiconto', 'materia' => 'rendiconto'], $s->admin);
    $due = OrdineDelGiorno::aggiungi($a, ['testo' => 'Preventivo', 'materia' => 'preventivo'], $s->admin);
    $varie = OrdineDelGiorno::aggiungi($a, ['testo' => 'Varie ed eventuali', 'materia' => 'varie'], $s->admin);

    try {
        OrdineDelGiorno::riordina($a, [$uno->id, $varie->id, $due->id], $s->admin);
        expect(true)->toBeFalse();
    } catch (ValidationException $e) {
        expect($e->errors()['ordine'][0])->toBe('«Varie ed eventuali» resta l\'ultimo punto dell\'ordine del giorno.');
    }
    OrdineDelGiorno::aggiungi($a, ['testo' => 'Portone', 'materia' => 'lavori'], $s->admin);
    expect($a->punti()->orderBy('numero')->get()->map(fn ($p) => $p->numero . ' ' . $p->testo)->all())
        ->toBe(['1 Rendiconto', '2 Preventivo', '3 Portone', '4 Varie ed eventuali']);
});

it('un punto aggiunto con «Varie» fuori dal fondo fa scendere tutti i punti da lì: mai due punti con lo stesso numero', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s);
    $uno = OrdineDelGiorno::aggiungi($a, ['testo' => 'Rendiconto', 'materia' => 'rendiconto'], $s->admin);
    $due = OrdineDelGiorno::aggiungi($a, ['testo' => 'Preventivo', 'materia' => 'preventivo'], $s->admin);
    $varie = OrdineDelGiorno::aggiungi($a, ['testo' => 'Varie ed eventuali', 'materia' => 'varie'], $s->admin);
    // Un ordine scritto prima della guardia: «Varie» al secondo posto.
    \Illuminate\Support\Facades\DB::table('assemblee_punti')->where('id', $varie->id)->update(['numero' => 2]);
    \Illuminate\Support\Facades\DB::table('assemblee_punti')->where('id', $due->id)->update(['numero' => 3]);

    OrdineDelGiorno::aggiungi($a, ['testo' => 'Portone', 'materia' => 'lavori'], $s->admin);
    $numeri = $a->punti()->pluck('numero')->all();
    expect(count(array_unique($numeri)))->toBe(count($numeri));
});
