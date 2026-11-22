<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R1, R4 e §6.3 del progetto della tappa 2): il collegamento aggiunge la provenienza e non tocca i conti. Il piano «Facciata 2026 —
 * rate»: (a) in bozza con la stessa data, il collegamento e basta; (b) in bozza con un'altra data, la data che entra è quella della riunione (la scrive chi
 * chiama, per la strada di oggi); (c) emesso con un'altra data, la data **resta** e l'esito dice la differenza. La delibera di un altro condominio è un 404;
 * una respinta non si sceglie; «Cambia» toglie la riga e ne scrive una nuova; la riga del registro è sulla scheda, con il rimando alla delibera.
 *
 * Che cosa non copre: le rotte delle pagine e «Approva» del piano (sono delle pagine); i sette lettori uno per uno (`SetteLettoriInvariatiTest` guarda le
 * colonne che leggono e il riparto).
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('(a) e (b): il piano in bozza riceve la data della riunione, che scrive chi chiama; la riga è sulla scheda con il rimando', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Facciata 2026 — rate', 'Facciata 2026', ['Facciata' => 1450000], '2026-10-20');
    $piano = $s->piano('Facciata 2026 — rate');
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');
    $prima = ViaRoma12::ultimaRiga();

    $esito = CollegamentiDelleDelibere::collega($piano, 'delibera', $d, null, $s->admin);

    $righe = ViaRoma12::righe($prima);
    expect($esito->dataEntrata)->toBe('2026-10-22')->and($esito->differenza)->toBeNull()
        ->and($esito->conseguenza)->toBe('Con questa delibera le rate del piano passano a chi era titolare il 22/10/2026')
        ->and($piano->fresh()->data_delibera_assemblea->format('Y-m-d'))->toBe('2026-10-20')
        ->and($righe)->toHaveCount(1)
        ->and($righe[0]->oggetto_tipo)->toBe('piano_rate')
        ->and($righe[0]->campi[0])->toMatchArray(['etichetta' => 'Delibera', 'prima' => null, 'dopo' => 'riunione del 22/10/2026, punto 1 «Rifacimento della facciata»'])
        ->and($righe[0]->rimando)->toMatchArray(['tipo' => 'delibera', 'id' => $d->id]);
});

it('(c): sul piano emesso con un\'altra data la data resta, e l\'esito dice la differenza', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Facciata 2026 — rate', 'Facciata 2026', ['Facciata' => 1450000], '2026-10-20')
        ->genera('Facciata 2026 — rate');
    $s->emetti('Facciata 2026 — rate', '2026-12-31');
    $piano = $s->piano('Facciata 2026 — rate')->fresh();
    expect($piano->eImmutabile())->toBeTrue();
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');

    $esito = CollegamentiDelleDelibere::collega($piano, 'delibera', $d, null, $s->admin);

    expect($esito->dataEntrata)->toBeNull()
        ->and($esito->differenza)->toBe('La delibera è del 22/10/2026, il piano dice 20/10/2026')
        ->and($piano->fresh()->data_delibera_assemblea->format('Y-m-d'))->toBe('2026-10-20');
});

it('«Cambia» toglie la riga e ne scrive una nuova; scollega scrive «→ nessuna»', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Facciata 2026 — rate', 'Facciata 2026', ['Facciata' => 1450000], '2026-10-22');
    $piano = $s->piano('Facciata 2026 — rate');
    $uno = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');
    $due = ViaRoma12::delibera($s, '2026-10-22', 'Saldo della facciata', 'approvata', '42');
    CollegamentiDelleDelibere::collega($piano, 'delibera', $uno, null, $s->admin);
    CollegamentiDelleDelibere::collega($piano, 'delibera', $due, 'Il saldo', $s->admin);
    expect(CollegamentoAllaDelibera::query()->count())->toBe(1)->and(CollegamentiDelleDelibere::delibera($piano, 'delibera')->id)->toBe($due->id);

    $prima = ViaRoma12::ultimaRiga();
    CollegamentiDelleDelibere::scollega($piano, 'delibera', $s->admin);
    expect(CollegamentoAllaDelibera::query()->count())->toBe(0)
        ->and(ViaRoma12::righe($prima)[0]->campi[0]['dopo'])->toBeNull();
});

it('una delibera di un altro condominio è un 404; una respinta non si sceglie; una revocata nemmeno, e la frase lo dice (R4)', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Facciata 2026 — rate', 'Facciata 2026', ['Facciata' => 1450000], '2026-10-22');
    $piano = $s->piano('Facciata 2026 — rate');
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $fuori = ViaRoma12::delibera($altro, '2026-10-22', 'Una delibera di Corso Italia');
    $respinta = ViaRoma12::delibera($s, '2026-10-22', 'La facciata in rosso', 'respinta');
    $vecchia = ViaRoma12::delibera($s, '2026-05-14', 'Preventivo 2026');
    $nuova = ViaRoma12::delibera($s, '2026-10-22', 'Revoca del preventivo 2026');
    $vecchia->update(['revocata_il' => '2026-10-22', 'revocata_da_delibera_id' => $nuova->id]);

    expect(fn () => CollegamentiDelleDelibere::collega($piano, 'delibera', $fuori, null, $s->admin))->toThrow(NotFoundHttpException::class)
        ->and(fn () => CollegamentiDelleDelibere::collega($piano, 'delibera', $respinta, null, $s->admin))->toThrow(ValidationException::class)
        ->and(fn () => CollegamentiDelleDelibere::collega($piano, 'delibera', $vecchia->fresh(), null, $s->admin))->toThrow(ValidationException::class)
        ->and(\App\Services\Assemblee\DelibereDelCondominio::fraseDellaRevoca($vecchia->fresh()))
            ->toBe('Revocata il 22/10/2026 dalla delibera del 22/10/2026, punto 2');
});
