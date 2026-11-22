<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\Assemblee\Assemblea;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R8 del progetto della tappa 2; decisioni 492 (1), 562, 563; §13 rilievo 19): la delibera registrata a mano crea la riunione, il
 * punto e la delibera, una riga sola; entra in una riunione che c'è già solo con lo stesso giorno, lo stesso perimetro e lo stesso numero del verbale (o
 * senza numero tutte e due), e lo dice prima del clic; l'ordinaria (verbale n. 41) e la straordinaria (verbale n. 42) della stessa sera sono due riunioni;
 * senza il verbale si registra lo stesso; il verbale lo carica solo chi ha «Registra il verbale sottoscritto»; aperta da una pagina, porta l'origine.
 *
 * Che cosa non copre: la finestra della pagina e la sua rotta (sono della pagina); il verbale sostituito con le versioni (lo provano i test delle versioni
 * dei documenti, la strada è la stessa).
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('crea la riunione, il punto e la delibera, una riga sola, con «Verbale da caricare»', function () {
    $s = ViaRoma12::nuovo($this);
    $prima = ViaRoma12::ultimaRiga();
    $d = ViaRoma12::delibera($s, '2024-03-12', 'Sostituzione dell\'ascensore', 'approvata', '3/2024');

    $righe = ViaRoma12::righe($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toBe('Delibera registrata a mano: riunione del 12/03/2024, «Sostituzione dell\'ascensore», approvata, verbale n. 3/2024')
        ->and($righe[0]->scheda_tipo)->toBe('assemblea')
        ->and($d->assemblea->stato->value)->toBe('registrata_a_mano')
        ->and($d->assemblea->verbale_documento_id)->toBeNull()
        ->and($d->deliberata_il->format('Y-m-d'))->toBe('2024-03-12');
});

it('l\'ordinaria (verbale n. 41) e la straordinaria (verbale n. 42) della stessa sera sono due riunioni; lo stesso numero, scritto diverso, è la stessa', function () {
    $s = ViaRoma12::nuovo($this);
    $uno = ViaRoma12::delibera($s, '2026-10-22', 'Rendiconto 2025', 'approvata', '41');
    $due = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '42', ['tipo' => 'straordinaria']);
    $tre = ViaRoma12::delibera($s, '2026-10-22', 'Preventivo 2027', 'approvata', ' 41 ');

    expect($uno->assemblea_id)->not->toBe($due->assemblea_id)
        ->and($tre->assemblea_id)->toBe($uno->assemblea_id)
        ->and($tre->punto->numero)->toBe(2)
        ->and(DeliberaAMano::fraseDiDestinazione(DeliberaAMano::riunioneDiDestinazione($s->c, ['giorno' => '2026-10-22', 'numero_verbale' => '42']), '2026-10-22'))
            ->toBe('Entra nella riunione del 22/10/2026, verbale n. 42')
        ->and(DeliberaAMano::fraseDiDestinazione(DeliberaAMano::riunioneDiDestinazione($s->c, ['giorno' => '2026-10-22', 'numero_verbale' => '43']), '2026-10-22'))
            ->toBe('Nasce una riunione nuova del 22/10/2026');
});

it('il giorno non può essere dopo oggi; il file chiede il permesso del verbale; con il permesso il verbale è un documento della riunione', function () {
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $s = ViaRoma12::nuovo($this);
    $marco = ViaRoma12::utente([Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE]);
    $file = UploadedFile::fake()->create('verbale.pdf', 100, 'application/pdf');

    expect(fn () => DeliberaAMano::registra($s->c, ['giorno' => '2099-01-01', 'testo' => 'X', 'esito' => 'approvata'], null, $s->admin))
        ->toThrow(ValidationException::class)
        ->and(fn () => DeliberaAMano::registra($s->c, ['giorno' => '2026-10-22', 'testo' => 'X', 'esito' => 'approvata'], $file, $marco))
        ->toThrow(AuthorizationException::class);

    $d = DeliberaAMano::registra($s->c, ['giorno' => '2026-10-22', 'testo' => 'Rendiconto 2025', 'esito' => 'approvata', 'numero_verbale' => '41'], $file, $s->admin);
    $verbale = $d->assemblea->verbale;
    expect($verbale)->not->toBeNull()
        ->and($verbale->documentable_type)->toBe((new Assemblea())->getMorphClass())
        ->and((bool) $verbale->is_published)->toBeFalse()
        ->and($verbale->name)->toBe('Verbale n. 41 del 22/10/2026');
});

it('aperta da una pagina, porta l\'origine e ciò di cui parla: non collega niente (R3)', function () {
    $s = ViaRoma12::nuovo($this)->gestione('Facciata 2026', 'straordinaria');
    $fattura = ViaRoma12::fatturaInSforo($s);
    $gestione = $s->gestioni['Facciata 2026']['g'];
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Ratifica della colonna', 'approvata', '41', [],
        ['oggetto_tipo' => 'fattura_passiva', 'oggetto_id' => $fattura->id, 'parla_di' => [['gestione', $gestione->id]]]);

    $oggetti = $d->punto->oggetti()->get()->map(fn ($o) => [$o->oggetto_tipo, $o->oggetto_id, $o->ruolo->value])->all();
    expect($oggetti)->toContain(['fattura_passiva', $fattura->id, 'origine'], ['gestione', $gestione->id, 'parla_di'])
        ->and($d->punto->chiave_fonte)->toBe('fattura:' . $fattura->id)
        ->and(\App\Models\Assemblee\CollegamentoAllaDelibera::query()->count())->toBe(0)
        ->and($fattura->fresh()->stato_approvazione)->toBe('sforo_motivato');
});

it('la materia della delibera a mano: quella scelta nella finestra dall\'archivio, o quella della pagina da cui nasce; e «Scegli la delibera» la propone', function () {
    $s = ViaRoma12::nuovo($this);
    $dispensa = \App\Models\Gestionale\DispensaSeiMesi::create(['condominio_id' => $s->c->id, 'data_delibera' => '2025-05-14', 'tutte_le_persone' => true]);
    $dallArchivio = ViaRoma12::delibera($s, '2025-05-14', 'Dispensa dall\'agire entro sei mesi', 'approvata', '2', ['materia' => 'dispensa']);
    $dallaPagina = ViaRoma12::delibera($s, '2025-06-10', 'Dispensa per il 2025', 'approvata', '3', [], ['oggetto_tipo' => 'dispensa_sei_mesi', 'oggetto_id' => $dispensa->id]);
    $senza = ViaRoma12::delibera($s, '2025-06-11', 'Il portone', 'approvata', '4');

    expect($dallArchivio->punto->materia->value)->toBe('dispensa')
        ->and($dallaPagina->punto->materia->value)->toBe('dispensa')
        ->and($senza->punto->materia->value)->toBe('altro');

    // Dalla rotta della finestra: la materia scelta passa.
    $r = $this->actingAs($s->admin)->postJson(route('admin.gestionale.delibere.a-mano', $s->c), ['giorno' => '2025-05-20', 'testo' => 'Regole del recupero',
        'esito' => 'approvata', 'materia' => 'regole'])->assertCreated();
    expect(\App\Models\Assemblee\Delibera::query()->findOrFail($r->json('delibera.id'))->punto->materia->value)->toBe('regole');

    $altra = \App\Models\Gestionale\DispensaSeiMesi::create(['condominio_id' => $s->c->id, 'data_delibera' => '2026-05-14', 'tutte_le_persone' => true]);
    $proposte = \App\Services\Assemblee\DelibereDelCondominio::scegli($s->c, 'dispensa_sei_mesi', $altra->id, 'dispensa', null)['proposte'];
    expect(array_column($proposte, 'id'))->toContain($dallArchivio->id);
});
