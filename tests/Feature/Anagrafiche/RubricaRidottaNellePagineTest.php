<?php

/**
 * # Le pagine che mostrano o fanno scegliere persone ricevono della rubrica solo i campi che leggono
 *
 * Codice fiscale, telefoni, email, PEC, documento d'identità, nascita e note di una persona stanno nella sua scheda, che
 * chiede «Visualizza anagrafiche» (`AnagraficaController`, `AnagraficaDocumentoController`, e la scheda del fornitore con
 * i suoi referenti). Le altre pagine del pannello e del gestionale che mostrano persone, o le fanno scegliere in una
 * tendina, ricevono di ogni persona i campi che leggono:
 *
 * - `id` e `nome`: le tendine di «Nuova segnalazione» e della sua modifica, la persona collegata nella scheda di un
 *   utente, i destinatari e l'autore di segnalazioni, comunicazioni, scadenze e documenti negli elenchi, nelle schede,
 *   nelle pagine di modifica e nella dashboard, la tendina (bloccata) della modifica di una titolarità;
 * - `id`, `nome` e `indirizzo` dove la pagina mostra l'indirizzo sotto il nome: le tendine di «Nuovo fornitore», dei
 *   referenti del fornitore, di «Nuovo utente» e della sua modifica, di «Associa anagrafica» all'unità; i destinatari dei
 *   documenti dell'archivio, la persona collegata nell'elenco utenti e le persone dell'elenco condomìni (il pannello con
 *   l'elenco completo mostra l'indirizzo);
 * - `id`, `nome` e il legame con l'unità (`pivot`) nella modifica di una titolarità e nei saldi iniziali.
 *
 * L'elenco degli incassi non riceve persone: non ne legge nessuna. Le pagine si aprono ai ruoli di serie e a chi ha il
 * loro permesso.
 *
 * Cosa NON copre: la tendina con tutta la rubrica nelle pagine di modifica di comunicazioni, scadenze e documenti
 * (`PagineDiModificaConLaRubricaRidottaTest.php`; qui si guardano solo i destinatari già scelti); la tendina
 * `fetch-anagrafiche` (`TendinaDeiDestinatariTest.php`); le pagine che leggono i campi (i vitest delle pagine).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Fornitore;
use App\Models\Gestionale\PianoConto;
use App\Models\Immobile;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as RuoloSpatie;

/** Ciò che di una persona resta nella sua scheda. */
const RUBRICA_SCHEDA = ['DNGMRA80A01H501U', '0612345678', '3331234567', 'mario.dangelo@example.com', 'mario@pec.example.com',
    'Nota riservata', 'CA12345XY', 'BNCLRA70B01H501W', 'laura.bianchi@example.com', '3479876543'];

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    config(['kondomanager.disco_documenti' => 'local']);
    Storage::fake('local');

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    Esercizio::factory()->create(['condominio_id' => $this->aurora->id, 'stato' => 'aperto']);
    PianoConto::factory()->create(['condominio_id' => $this->aurora->id]);

    // Il condòmino, con un account; e l'autore dei contenuti, con la sua persona in rubrica.
    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(Role::UTENTE->value);
    $this->mario = Anagrafica::create([
        'nome' => "Mario D'Àngelo", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'DNGMRA80A01H501U', 'telefono' => '0612345678',
        'cellulare' => '3331234567', 'email' => 'mario.dangelo@example.com', 'pec' => 'mario@pec.example.com',
        'numero_documento' => 'CA12345XY', 'note' => 'Nota riservata', 'user_id' => $this->condomino->id,
    ]);
    $this->mario->condomini()->attach($this->aurora->id);

    $this->autore = User::factory()->create(['email_verified_at' => now()]);
    $this->autore->assignRole(Role::AMMINISTRATORE->value);
    $this->laura = Anagrafica::create([
        'nome' => 'Laura Bianchi', 'indirizzo' => 'Via dei Platani 9', 'codice_fiscale' => 'BNCLRA70B01H501W',
        'email' => 'laura.bianchi@example.com', 'cellulare' => '3479876543', 'user_id' => $this->autore->id,
    ]);
    $this->laura->condomini()->attach($this->aurora->id);

    $this->segnalazione = Segnalazione::create([
        'subject' => 'Ascensore fermo', 'description' => 'Bloccato al secondo piano.', 'priority' => 'alta', 'stato' => 'aperta',
        'can_comment' => true, 'is_featured' => false, 'is_published' => true, 'is_approved' => true,
        'created_by' => $this->autore->id, 'condominio_id' => $this->aurora->id,
    ]);
    $this->segnalazione->anagrafiche()->attach($this->mario->id);

    $this->comunicazione = Comunicazione::create([
        'subject' => 'Avviso di prova', 'description' => 'Testo', 'priority' => 'media', 'created_by' => $this->autore->id, 'is_private' => false,
        'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => false,
    ]);
    $this->comunicazione->condomini()->attach($this->aurora->id);
    $this->comunicazione->anagrafiche()->attach($this->mario->id);

    $this->evento = Evento::create([
        'title' => 'Assemblea di prova', 'start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour(),
        'created_by' => $this->autore->id, 'visibility' => 'public', 'is_approved' => true,
    ]);
    $this->evento->condomini()->attach($this->aurora->id);
    $this->evento->anagrafiche()->attach($this->mario->id);

    Storage::disk('local')->put('documenti/verbale.pdf', '%PDF-1.4 verbale');
    $this->documento = Documento::create([
        'name' => 'Verbale di prova', 'description' => 'Testo', 'path' => 'documenti/verbale.pdf', 'mime_type' => 'application/pdf',
        'file_size' => 16, 'created_by' => $this->autore->id, 'is_published' => true, 'is_approved' => true,
    ]);
    $this->documento->condomini()->attach($this->aurora->id);
    $this->documento->anagrafiche()->attach($this->mario->id);

    $this->fornitore = Fornitore::create([
        'ragione_sociale' => 'Idraulica Prova S.r.l.', 'stato' => 'attivo', 'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico', 'soggetto_ritenuta' => false, 'perc_imponibile_ritenuta' => 100,
    ]);

    $this->interno7 = Immobile::create(['condominio_id' => $this->aurora->id, 'nome' => 'Interno 7', 'descrizione' => 'Prova', 'interno' => '7']);
    $this->interno7->anagrafiche()->attach($this->mario->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);
    $this->riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $this->mario->id)->value('id');
});

function rubUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

/** Un ruolo su misura con l'accesso al pannello e i permessi della pagina, senza «Visualizza anagrafiche». */
function rubSuMisura(string $nome, array $permessi): User
{
    RuoloSpatie::findOrCreate($nome, 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], array_map(fn (Permission $p) => $p->value, $permessi)));

    return rubUtente($nome);
}

/** Chi apre la pagina: il ruolo su misura con il suo permesso, il collaboratore e l'amministratore di serie. */
function rubChi(array $permessi): array
{
    return [
        'su misura' => rubSuMisura('Su misura '.md5(serialize($permessi)), $permessi),
        'collaboratore' => rubUtente(Role::COLLABORATORE->value),
        'amministratore' => rubUtente(Role::AMMINISTRATORE->value),
    ];
}

/** Ogni persona ha esattamente le chiavi attese. */
function rubChiavi(iterable $persone, array $chiavi, string $dove): void
{
    $persone = collect($persone)->values();
    expect($persone, "$dove: nessuna persona")->not->toBeEmpty();

    foreach ($persone as $persona) {
        expect(array_keys((array) $persona), $dove)->toBe($chiavi);
    }
}

/** La risposta non porta niente della scheda delle persone. */
function rubSenzaScheda(\Illuminate\Testing\TestResponse $risposta, string $dove): void
{
    foreach (RUBRICA_SCHEDA as $dato) {
        expect($risposta->getContent(), "$dove: $dato")->not->toContain($dato);
    }
}

function rubProps(\Illuminate\Testing\TestResponse $risposta): array
{
    return $risposta->assertOk()->viewData('page')['props'];
}

test('«Nuova segnalazione» e la sua modifica: la tendina delle persone e i destinatari con id e nome', function () {
    foreach (rubChi([Permission::CREATE_SEGNALAZIONI, Permission::EDIT_SEGNALAZIONI]) as $chi => $utente) {
        $nuova = $this->actingAs($utente)->get(route('admin.segnalazioni.create'));
        rubChiavi(rubProps($nuova)['anagrafiche'], ['id', 'nome'], "$chi, nuova");
        rubSenzaScheda($nuova, "$chi, nuova");

        $modifica = $this->actingAs($utente)->get(route('admin.segnalazioni.edit', $this->segnalazione));
        $props = rubProps($modifica);
        rubChiavi($props['anagrafiche'], ['id', 'nome'], "$chi, modifica");
        rubChiavi($props['segnalazione']['anagrafiche'], ['id', 'nome'], "$chi, modifica, destinatari");
        rubSenzaScheda($modifica, "$chi, modifica");
    }
});

test('gli elenchi, le schede e la dashboard di segnalazioni, comunicazioni, scadenze e documenti: destinatari e autore senza la scheda', function () {
    foreach (rubChi([Permission::VIEW_SEGNALAZIONI, Permission::VIEW_COMUNICAZIONI, Permission::VIEW_EVENTS, Permission::VIEW_ARCHIVE_DOCUMENTS]) as $chi => $utente) {
        $segnalazioni = $this->actingAs($utente)->get(route('admin.segnalazioni.index'));
        rubChiavi(rubProps($segnalazioni)['segnalazioni'][0]['anagrafiche'], ['id', 'nome'], "$chi, segnalazioni");
        rubSenzaScheda($segnalazioni, "$chi, segnalazioni");

        $scheda = $this->actingAs($utente)->get(route('admin.segnalazioni.show', $this->segnalazione));
        rubSenzaScheda($scheda->assertOk(), "$chi, scheda della segnalazione");

        $comunicazioni = $this->actingAs($utente)->get(route('admin.comunicazioni.index'));
        $prima = rubProps($comunicazioni)['comunicazioni'][0];
        rubChiavi($prima['anagrafiche'], ['id', 'nome'], "$chi, comunicazioni");
        rubSenzaScheda($comunicazioni, "$chi, comunicazioni");

        $comunicazione = $this->actingAs($utente)->get(route('admin.comunicazioni.show', $this->comunicazione));
        rubChiavi([rubProps($comunicazione)['comunicazione']['created_by']['anagrafica']], ['id', 'nome'], "$chi, scheda della comunicazione, autore");
        rubSenzaScheda($comunicazione, "$chi, scheda della comunicazione");

        $eventi = $this->actingAs($utente)->get(route('admin.eventi.index'));
        rubChiavi(rubProps($eventi)['eventi'][0]['anagrafiche'], ['id', 'nome'], "$chi, scadenze");
        rubSenzaScheda($eventi, "$chi, scadenze");

        $documenti = $this->actingAs($utente)->get(route('admin.documenti.index'));
        rubChiavi(rubProps($documenti)['documenti'][0]['anagrafiche'], ['id', 'nome', 'indirizzo'], "$chi, documenti");
        rubSenzaScheda($documenti, "$chi, documenti");

        $dashboard = $this->actingAs($utente)->get(route('admin.dashboard'));
        $blocchi = rubProps($dashboard);
        rubChiavi($blocchi['segnalazioni'][0]['anagrafiche'], ['id', 'nome'], "$chi, dashboard, segnalazioni");
        rubChiavi([$blocchi['segnalazioni'][0]['created_by']['anagrafica']], ['id', 'nome'], "$chi, dashboard, autore");
        rubChiavi($blocchi['comunicazioni'][0]['anagrafiche'], ['id', 'nome'], "$chi, dashboard, comunicazioni");
        rubChiavi($blocchi['documenti'][0]['anagrafiche'], ['id', 'nome', 'indirizzo'], "$chi, dashboard, documenti");
        rubSenzaScheda($dashboard, "$chi, dashboard");
    }
});

test('le pagine di modifica di comunicazioni, scadenze e documenti: i destinatari già scelti hanno solo i campi che la pagina legge', function () {
    $amministratore = rubUtente(Role::AMMINISTRATORE->value);

    $comunicazione = rubProps($this->actingAs($amministratore)->get(route('admin.comunicazioni.edit', $this->comunicazione)));
    rubChiavi($comunicazione['comunicazione']['anagrafiche'], ['id', 'nome'], 'modifica della comunicazione');
    $evento = rubProps($this->actingAs($amministratore)->get(route('admin.eventi.edit', $this->evento)));
    rubChiavi($evento['evento']['anagrafiche'], ['id', 'nome'], 'modifica della scadenza');
    // Il documento porta i destinatari con la forma dell'elenco dell'archivio; la modifica ne carica id e nome.
    $documento = rubProps($this->actingAs($amministratore)->get(route('admin.documenti.edit', $this->documento)));
    expect($documento['documento']['anagrafiche'])->toBe([['id' => $this->mario->id, 'nome' => "Mario D'Àngelo", 'indirizzo' => null]]);
});

test('«Nuovo fornitore» e «Associa anagrafica» del fornitore: la tendina con id, nome e indirizzo', function () {
    foreach (rubChi([Permission::CREATE_ANAGRAFICHE, Permission::EDIT_ANAGRAFICHE]) as $chi => $utente) {
        $nuovo = $this->actingAs($utente)->get(route('admin.fornitori.create'));
        rubChiavi(rubProps($nuovo)['anagrafiche'], ['id', 'nome', 'indirizzo'], "$chi, nuovo fornitore");
        rubSenzaScheda($nuovo, "$chi, nuovo fornitore");

        $referente = $this->actingAs($utente)->get(route('admin.fornitori.anagrafiche.create', $this->fornitore));
        rubChiavi(rubProps($referente)['anagrafiche'], ['id', 'nome', 'indirizzo'], "$chi, referente");
        rubSenzaScheda($referente, "$chi, referente");
    }

    expect(collect(rubProps($this->actingAs(rubUtente(Role::AMMINISTRATORE->value))->get(route('admin.fornitori.create')))['anagrafiche'])->firstWhere('id', $this->mario->id))
        ->toBe(['id' => $this->mario->id, 'nome' => "Mario D'Àngelo", 'indirizzo' => 'Via Roma 1']);
});

test('«Nuovo utente», la scheda dell\'utente e l\'elenco utenti: la persona con id, nome e indirizzo, quella collegata con id e nome', function () {
    foreach (rubChi([Permission::VIEW_USERS, Permission::CREATE_USERS, Permission::EDIT_USERS]) as $chi => $utente) {
        $nuovo = $this->actingAs($utente)->get(route('utenti.create'));
        rubChiavi(rubProps($nuovo)['anagrafiche'], ['id', 'nome', 'indirizzo'], "$chi, nuovo utente");
        rubSenzaScheda($nuovo, "$chi, nuovo utente");

        $scheda = $this->actingAs($utente)->get(route('utenti.edit', $this->condomino));
        $props = rubProps($scheda);
        rubChiavi($props['anagrafiche'], ['id', 'nome', 'indirizzo'], "$chi, scheda dell'utente");
        expect($props['user']['anagrafica'], "$chi, scheda dell'utente")->toBe(['id' => $this->mario->id, 'nome' => "Mario D'Àngelo"]);
        rubSenzaScheda($scheda, "$chi, scheda dell'utente");

        $elenco = $this->actingAs($utente)->get(route('utenti.index'));
        $collegato = collect(rubProps($elenco)['users'])->firstWhere('id', $this->condomino->id)['anagrafica'];
        expect($collegato, "$chi, elenco utenti")->toBe(['id' => $this->mario->id, 'nome' => "Mario D'Àngelo", 'indirizzo' => 'Via Roma 1']);
        rubSenzaScheda($elenco, "$chi, elenco utenti");
    }
});

test('l\'elenco dei condomìni: le persone di ogni palazzo con id, nome e indirizzo', function () {
    foreach (rubChi([Permission::VIEW_CONDOMINI]) as $chi => $utente) {
        $elenco = $this->actingAs($utente)->get(route('condomini.index'));
        $palazzo = collect(rubProps($elenco)['buildings'])->firstWhere('id', $this->aurora->id);
        rubChiavi($palazzo['anagrafiche'], ['id', 'nome', 'indirizzo'], "$chi, condomìni");
        rubSenzaScheda($elenco, "$chi, condomìni");
    }
});

test('il gestionale: «Associa anagrafica» all\'unità, la modifica di una titolarità, i saldi iniziali, gli incassi', function () {
    foreach (rubChi([]) as $chi => $utente) {
        $associa = $this->actingAs($utente)->get(route('admin.gestionale.immobili.anagrafiche.create', [$this->aurora, $this->interno7]));
        rubChiavi(rubProps($associa)['anagrafiche'], ['id', 'nome', 'indirizzo'], "$chi, associa anagrafica");
        rubSenzaScheda($associa, "$chi, associa anagrafica");

        $titolarita = $this->actingAs($utente)->get(route('admin.gestionale.immobili.anagrafiche.edit', [$this->aurora, $this->interno7, $this->riga]));
        $props = rubProps($titolarita);
        rubChiavi($props['anagrafiche'], ['id', 'nome'], "$chi, titolarità");
        expect(array_keys($props['anagrafica']), "$chi, titolarità")->toBe(['id', 'nome', 'pivot'])
            ->and($props['anagrafica']['id'])->toBe($this->mario->id)
            ->and($props['anagrafica']['pivot']['id'])->toBe($this->riga)
            ->and($props['anagrafica']['pivot']['tipologia'])->toBe('proprietario');
        rubSenzaScheda($titolarita, "$chi, titolarità");

        $saldi = $this->actingAs($utente)->get(route('admin.gestionale.saldi.index', [$this->aurora]));
        $unita = collect(rubProps($saldi)['immobili'])->firstWhere('id', $this->interno7->id);
        rubChiavi($unita['anagrafiche'], ['id', 'nome', 'pivot'], "$chi, saldi iniziali");
        rubSenzaScheda($saldi, "$chi, saldi iniziali");

        $incassi = $this->actingAs($utente)->get(route('admin.gestionale.movimenti-rate.index', [$this->aurora]));
        expect(rubProps($incassi), "$chi, incassi")->not->toHaveKey('soggetti');
        rubSenzaScheda($incassi, "$chi, incassi");
    }
});

test('la scheda della persona e quella del fornitore, che chiedono «Visualizza anagrafiche», restano complete', function () {
    $amministratore = rubUtente(Role::AMMINISTRATORE->value);
    $this->fornitore->referenti()->attach($this->mario->id, ['ruolo' => 'titolare']);

    $this->actingAs($amministratore)->get(route('admin.anagrafiche.show', $this->mario))->assertOk()->assertSee('DNGMRA80A01H501U')->assertSee('3331234567');
    $referenti = rubProps($this->actingAs($amministratore)->get(route('admin.fornitori.show', $this->fornitore)))['fornitore']['referenti'];
    expect($referenti[0]['codice_fiscale'])->toBe('DNGMRA80A01H501U')->and($referenti[0]['email'])->toBe('mario.dangelo@example.com');
});
