<?php

use App\Models\Anagrafica;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 10:00:00');
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->segnalazione = algSegnalazione($this->aurora, $this->mario);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('il filtro autore nella pagina allegati trova l\'autore per anagrafica senza errori SQL su cognome', function () {
    // Mario Rossi ha una anagrafica con nome 'Mario Rossi' (senza colonna cognome)
    $anagrafica = Anagrafica::create([
        'user_id' => $this->mario->id,
        'nome' => 'Mario Rossi',
        'indirizzo' => 'Via Roma 1',
    ]);

    $allegatoId = algAllegatoSalvato($this->segnalazione, $this->mario, 'prova.jpg');

    $risposta = $this->actingAs($this->admin)->get(route('admin.allegati.index', ['autore' => 'Rossi']))->assertOk();
    $props = $risposta->viewData('page')['props'];

    expect($props['allegati']['data'])->toHaveCount(1)
        ->and($props['allegati']['data'][0]['id'])->toBe($allegatoId);
});
