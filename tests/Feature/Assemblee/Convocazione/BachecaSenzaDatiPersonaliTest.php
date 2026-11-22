<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Services\Assemblee\Convocazione\AvvisoInBacheca;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, D5): in bacheca nessun dato di una persona. La bacheca la leggono anche gli inquilini e il portale (Cass. 29323/2022): il testo
 * del punto, che è quello dell'avviso per persona, non ci va mai. Un punto senza la frase per la bacheca ha una riga neutra con la sua materia, e la scheda
 * e la pagina della comunicazione dicono a quale punto manca la frase; «la frase non ha dati personali» si dice solo se tutti i punti hanno la loro.
 *
 * Che cosa non copre: che la frase scritta dall'amministratore non abbia nomi (la scrive lui, e la finestra del punto lo ricorda); la pagina della
 * comunicazione che mostra `manca_la_frase` (la prova il suo vitest).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('un punto scritto a mano senza la frase per la bacheca non porta il nome in bacheca: la riga è neutra, e la scheda dice a quale punto manca', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    foreach (PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $a->id)->get() as $p) {
        $p->forceFill(['testo_pubblico' => 'Frase pubblica del punto ' . $p->numero])->save();
    }
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), [
        'testo' => 'Decreto ingiuntivo contro Neri Carla per le rate 2025 non pagate: autorizzazione all\'azione', 'materia' => 'liti',
    ])->assertSessionHasNoErrors();
    $punto = PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $a->id)->where('materia', 'liti')->first();

    $bacheca = app(AvvisoInBacheca::class)->precompilata($a->refresh());
    expect($bacheca['description'])->not->toContain('Neri Carla')
        ->toContain($punto->numero . '. Una questione legale del condominio')
        ->and($bacheca['manca_la_frase'])->toBe('Al punto ' . $punto->numero . ' manca la frase per la bacheca: il programma scrive solo «Una questione legale del condominio».');

    $scheda = app(StatoDellaConvocazione::class)->perLaPagina($a);
    expect($scheda['bacheca']['adesso'])->not->toContain('non ha dati personali')
        ->toContain('Al punto ' . $punto->numero . ' manca la frase per la bacheca');

});

it('con la frase per la bacheca di ogni punto la bacheca la usa, e solo allora la scheda dice che non ha dati personali; senza frasi, una riga sola per tutti i punti', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    foreach (PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $a->id)->get() as $p) {
        $p->forceFill(['testo_pubblico' => 'Frase pubblica del punto ' . $p->numero])->save();
    }
    $this->actingAs($s->admin);

    $bacheca = app(AvvisoInBacheca::class)->precompilata($a->refresh());
    expect($bacheca['description'])->toContain('4. Frase pubblica del punto 4')->and($bacheca['manca_la_frase'])->toBeNull();
    expect(app(StatoDellaConvocazione::class)->perLaPagina($a)['bacheca']['adesso'])->toContain('le frasi dei punti senza dati personali');

    $b = ConvocazioneDiViaRoma12::ordinaria($s);
    expect(app(AvvisoInBacheca::class)->precompilata($b)['manca_la_frase'])
        ->toBe('Ai punti 1, 2, 3, 4, 5 e 6 manca la frase per la bacheca: il programma scrive solo la materia di ogni punto.');
});
