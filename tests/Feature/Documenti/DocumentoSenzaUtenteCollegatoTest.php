<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Resources\Allegati\AllegatoResource;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Http\Resources\Portale\DocumentoPortaleResource;
use App\Models\Documento;
use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\InvioDeiFogli;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Un documento che il programma crea quando nessun utente è collegato (una lettera, un foglio del conguaglio, l'allegato di una fattura) non ha
 * autore: la colonna resta vuota, non porta il nome di chi ha il numero 1. E ogni vista che mostra l'autore regge l'autore vuoto.
 */
require_once __DIR__ . '/../Gestionale/LettereScenario.php';
require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__ . '/../Gestionale/ConguaglioMisuraScenario.php';
require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['mail.default' => 'array']);
    // L'utente con il numero 1 esiste: è proprio quello che non deve comparire come autore.
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->primo = $this->user = User::factory()->create(['name' => 'Primo utente', 'email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

/** Le viste che mostrano l'autore di un documento, con la relazione caricata e vuota. */
function dsucLeVisteReggono(Documento $documento): void
{
    $documento = $documento->fresh()->load('createdBy.anagrafica');
    $amministratore = (new DocumentoResource($documento))->resolve();
    $portale = (new DocumentoPortaleResource($documento))->resolve();
    $allegato = (new AllegatoResource($documento))->toArray(Request::create('/'));

    expect($documento->created_by)->toBeNull()
        ->and($amministratore['created_by'])->toBe(['user' => ['id' => null, 'name' => 'utente eliminato'], 'anagrafica' => null])
        ->and($portale['created_by'])->toBe(['user' => ['id' => null, 'name' => null]])
        ->and($allegato['autore'])->toBe('')
        ->and(RegoleAllegati::nomeAutore($documento))->toBe('Utente');
}

it('la lettera emessa senza utente collegato ha l\'autore vuoto, non l\'utente 1', function () {
    $s = lttScenario($this);

    $r = app(EmissioneLettere::class)->stampa($s['c'], lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]), null);

    $documento = Documento::where('mime_type', 'application/pdf')->firstOrFail();
    expect($r)->not->toBeEmpty()
        ->and(Documento::count())->toBe(1)
        ->and($documento->created_by)->not->toBe($this->primo->id);
    dsucLeVisteReggono($documento);
});

it('il foglio del conguaglio inviato senza utente collegato ha l\'autore vuoto, non l\'utente 1', function () {
    Queue::fake();
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);

    app(InvioDeiFogli::class)->invia($s['c'], $s['e'], [
        'chi' => 'tutte', 'indirizzi' => ['email'], 'oggetto' => InvioDeiFogli::OGGETTO_DI_SERIE, 'testo' => InvioDeiFogli::TESTO_DI_SERIE,
    ], null);

    $documenti = Documento::where('mime_type', 'application/pdf')->get();
    expect($documenti)->not->toBeEmpty()
        ->and($documenti->pluck('created_by')->filter()->all())->toBe([]);
    dsucLeVisteReggono($documenti->first());
});

it('l\'allegato della fattura aggiunto senza utente collegato ha l\'autore vuoto, non l\'utente 1', function () {
    $ctx = setupContabile();
    [, , , , $capitolo] = $ctx;
    $fattura = registraFatturaServiceTest($ctx, ['righe' => [[
        'descrizione' => 'Servizio Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
    ]]]);

    $documento = (new FatturaPassivaService())->aggiungiDocumento($fattura, UploadedFile::fake()->create('fattura.pdf', 100, 'application/pdf'));

    dsucLeVisteReggono($documento);
});

it('con un utente collegato l\'autore resta l\'utente collegato', function () {
    $autore = User::factory()->create(['name' => 'Chi scrive']);
    $s = lttScenario($this);

    app(EmissioneLettere::class)->stampa($s['c'], lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]), $autore);

    expect(Documento::where('mime_type', 'application/pdf')->firstOrFail()->created_by)->toBe($autore->id);
});
