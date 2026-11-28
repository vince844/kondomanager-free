<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Dell'autore le pagine del pannello ricevono id e nome (Coda 411, A1)
 *
 * Le segnalazioni, le comunicazioni, i documenti e le scadenze mandano `created_by.user` con l'id e il nome: niente email, sospensione, verifica, ruoli
 * né permessi dell'account. Le pagine leggono solo quei due.
 */

use App\Enums\Role;
use App\Models\CategoriaEvento;
use App\Models\Documento;
use App\Models\Evento;
use Database\Seeders\RolesAndPermissionsSeeder;

require_once __DIR__ . '/../CommentiComunicazioni/CommentiScenario.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->aurora = \App\Models\Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->admin = ccmUtente([$this->aurora->id], 'Studio Bianchi', Role::AMMINISTRATORE->value);
    $this->mario = ccmUtente([$this->aurora->id], 'Mario Rossi');
    $this->comunicazione = ccmComunicazione([$this->aurora], $this->mario);
    $this->segnalazione = ccmSegnalazione($this->aurora, $this->mario);
    $this->documento = Documento::create([
        'name' => 'Verbale', 'description' => 'Descrizione', 'path' => 'documenti/verbale.pdf', 'mime_type' => 'application/pdf', 'file_size' => 20,
        'created_by' => $this->mario->id, 'is_published' => true, 'is_approved' => true,
    ]);
    $this->documento->condomini()->attach($this->aurora->id);
    $categoria = CategoriaEvento::firstOrCreate(['name' => 'Agenda'], ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']);
    $evento = Evento::create([
        'title' => 'Scadenza di prova', 'start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour(), 'created_by' => $this->mario->id,
        'category_id' => $categoria->id, 'visibility' => 'public', 'is_approved' => true, 'timezone' => config('app.timezone'),
    ]);
    $evento->condomini()->attach($this->aurora->id);
});

/** Tutti i `created_by.user` trovati nelle props di una pagina. @return list<array<string, mixed>> */
function arpAutori(mixed $nodo): array
{
    if (! is_array($nodo)) {
        return [];
    }
    $trovati = [];
    foreach ($nodo as $chiave => $valore) {
        if ($chiave === 'created_by' && is_array($valore) && is_array($valore['user'] ?? null)) {
            $trovati[] = $valore['user'];
        } else {
            array_push($trovati, ...arpAutori($valore));
        }
    }

    return $trovati;
}

/** Le props di una pagina Inertia, con anche quelle caricate dopo (`Inertia::scroll` e `defer` si risolvono con l'intestazione di Inertia). */
function arpProps($test, string $url): array
{
    $risposta = $test->actingAs($test->admin)->get($url)->assertOk();

    return json_decode(json_encode($risposta->viewData('page')['props']), true);
}

it('le pagine del pannello mandano dell\'autore solo id e nome', function (string $pagina) {
    $url = match ($pagina) {
        'comunicazioni' => route('admin.comunicazioni.index'),
        'comunicazione' => route('admin.comunicazioni.show', $this->comunicazione),
        'segnalazioni' => route('admin.segnalazioni.index'),
        'segnalazione' => route('admin.segnalazioni.show', $this->segnalazione),
        'documenti' => route('admin.documenti.index'),
        'dashboard' => route('admin.dashboard'),
    };

    $autori = arpAutori(arpProps($this, $url));

    expect($autori)->not->toBeEmpty("La pagina {$pagina} non porta nessun autore: la prova non prova niente");
    foreach ($autori as $autore) {
        expect(array_keys($autore))->toEqualCanonicalizing(['id', 'name'])
            ->and($autore['name'])->toBe('Mario Rossi')
            ->and($autore['id'])->toBe($this->mario->id);
    }
})->with(['comunicazioni', 'comunicazione', 'segnalazioni', 'segnalazione', 'documenti', 'dashboard']);

// Gli elenchi delle scadenze oggi non caricano l'autore: la risorsa, quando lo ha, ne manda id e nome come le altre.
it('la risorsa di una scadenza manda dell\'autore solo id e nome', function () {
    $evento = Evento::where('title', 'Scadenza di prova')->sole()->load('createdBy.anagrafica');

    $risorsa = json_decode(json_encode((new \App\Http\Resources\Evento\EventoResource($evento))->resolve()), true);

    expect(array_keys($risorsa['created_by']['user']))->toEqualCanonicalizing(['id', 'name'])
        ->and($risorsa['created_by']['user']['name'])->toBe('Mario Rossi');
});
