<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Chi carica un file nel gestionale.
 *
 * Il gestionale di un condominio si apre con l'accesso al pannello; caricare un file chiede in più un permesso che c'è già:
 * - nelle schede della contabilità (la fattura registrata con il suo file, l'XML da leggere, il verbale della dispensa dei sei mesi, l'accordo
 *   di rientro firmato, il PDF del rendiconto mandato e il verbale della sua approvazione, la ricevuta della PEC e la prova della spedizione)
 *   «Crea documenti archivio», lo stesso che chiede «Allega» nella scheda della fattura;
 * - nei documenti dell'unità «Modifica condomini», come la scheda dell'unità (i documenti di un'anagrafica e di un fornitore chiedono già il
 *   permesso di modifica della loro scheda).
 * Un ruolo su misura con il solo accesso al pannello prende 403, e non resta niente: né il file nel disco né la riga. L'amministratore e il
 * collaboratore di serie hanno i due permessi: per loro non cambia niente.
 *
 * Il documento dell'unità salva la visibilità scelta nel modulo, e la modifica non cambia chi l'ha caricato né se è approvato.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\DispensaSeiMesi;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\Immobile;
use App\Models\TipologiaImmobile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

const CGT_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->soloPannello = cgtUtente('Segreteria', [PermissionEnum::ACCESS_ADMIN_PANEL]);
    $this->conArchivio = cgtUtente('Segreteria con archivio', [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::CREATE_ARCHIVE_DOCUMENTS]);
    $this->conCondomini = cgtUtente('Segreteria con condomini', [PermissionEnum::ACCESS_ADMIN_PANEL, PermissionEnum::EDIT_CONDOMINI]);
    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);
    // Chi prepara lo scenario delle lettere (emette le rate, registra gli incassi).
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->assignRole(RoleEnum::AMMINISTRATORE->value);
});

/** Un ruolo su misura con i permessi dati, e un account che lo ha. */
function cgtUtente(string $ruolo, array $permessi): User
{
    $r = Role::create(['name' => $ruolo, 'guard_name' => 'web']);
    $r->givePermissionTo(array_map(fn (PermissionEnum $p) => $p->value, $permessi));
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($r);

    return $u;
}

/** Un file caricato vero: il tipo lo legge `finfo` dal contenuto. */
function cgtFile(string $nome, string $contenuto = CGT_PDF): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'cgt');
    file_put_contents($tmp, $contenuto);

    return new UploadedFile($tmp, $nome, null, null, true);
}

/** Nessun file nel disco e nessun documento: il rifiuto non ha lasciato niente. */
function cgtNienteScritto(): void
{
    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(Documento::count())->toBe(0);
}

/** Una versione del rendiconto presentata, scritta direttamente: la rotta la cerca nel suo esercizio. */
function cgtVersione(array $s): RendicontoVersione
{
    $id = DB::table('rendiconto_versioni')->insertGetId([
        'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'numero' => 1, 'stato' => RendicontoVersione::PRESENTATA, 'forma' => 3,
        'cifre' => '{}', 'calcolato_il' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return RendicontoVersione::findOrFail($id);
}

/** Il corpo di «Registra fattura» di una nota di credito da € 3,00 sulla fattura data, con il suo file. */
function cgtCorpoNota(array $ctx, FatturaPassiva $fattura, $capitolo): array
{
    return datiBase($ctx, [
        'tipo_documento' => 'nota_credito', 'stato_approvazione' => 'approvata', 'applica_ritenuta' => false, 'fattura_rettificata_id' => $fattura->id,
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null],
        'righe' => [['descrizione' => 'Rettifica', 'importo_imponibile' => 3, 'aliquota_iva' => 0, 'importo_iva' => 0, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false]],
        'file' => cgtFile('nota.pdf'),
    ]);
}

test('le schede della contabilità: con il solo accesso al pannello caricare un file risponde 403 e non lascia né il file né la riga', function () {
    $s = lttScenario($this);
    $riga = lttRigaScritta($s, 'ugo', 'diffida', 'stampata', 0);
    $versione = cgtVersione($s);
    $c = ['condominio' => $s['c']->id];
    $u = $this->soloPannello;

    $this->actingAs($u)->post(route('admin.gestionale.dispense.store', $c), [
        'data_delibera' => '2026-03-01', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1', 'verbale' => cgtFile('verbale.pdf'),
    ])->assertForbidden();
    $this->actingAs($u)->postJson(route('admin.gestionale.accordi-rientro.store', $c), [
        'anagrafica_id' => $s['persone']['ugo']->id, 'data_accordo' => '2026-03-20', 'fino_al' => '2026-09-30', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => $s['immobili']['A']->id, 'importo' => '60,00']], 'documento' => cgtFile('accordo.pdf'),
    ])->assertForbidden();
    $this->actingAs($u)->post(lttRotta($s, 'prove.store', ['invio' => $riga->id]), ['file' => cgtFile('prova.pdf')])->assertForbidden();
    $this->actingAs($u)->post(lttRotta($s, 'ricevuta', ['invio' => $riga->id]), ['ricevuta' => cgtFile('ricevuta.pdf')])->assertForbidden();
    $this->actingAs($u)->post(route('admin.gestionale.esercizi.rendiconto.versioni.carica', [$s['c'], $s['e'], $versione->id]), ['pdf' => cgtFile('rendiconto.pdf')])
        ->assertForbidden();
    $this->actingAs($u)->post(route('admin.gestionale.esercizi.rendiconto.versioni.approva', [$s['c'], $s['e'], $versione->id]), [
        'data_delibera' => '2026-03-15', 'riparto_approvato' => '1', 'verbale' => cgtFile('verbale.pdf'),
    ])->assertForbidden();
    $this->actingAs($u)->postJson(route('admin.gestionale.fatture.importa-xml', $c), [
        'file' => UploadedFile::fake()->createWithContent('fattura.xml', file_get_contents(base_path('tests/Fixtures/fatturapa/reale_anonimizzata_cassa_previdenziale.xml'))),
    ])->assertForbidden();

    cgtNienteScritto();
    expect(DispensaSeiMesi::count())->toBe(0)
        ->and(AccordoRientro::count())->toBe(0)
        ->and($riga->fresh()->stato)->toBe('stampata')
        ->and($versione->fresh()->stato)->toBe(RendicontoVersione::PRESENTATA)
        ->and($versione->fresh()->pdf)->toBeNull();
});

test('le schede della contabilità: con «Crea documenti archivio» il file entra, con la sua riga', function () {
    $s = lttScenario($this);
    $riga = lttRigaScritta($s, 'ugo', 'diffida', 'stampata', 0);
    $versione = cgtVersione($s);
    $c = ['condominio' => $s['c']->id];
    $u = $this->conArchivio;

    $this->actingAs($u)->post(route('admin.gestionale.dispense.store', $c), [
        'data_delibera' => '2026-03-01', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1', 'verbale' => cgtFile('verbale.pdf'),
    ])->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($u)->postJson(route('admin.gestionale.accordi-rientro.store', $c), [
        'anagrafica_id' => $s['persone']['ugo']->id, 'data_accordo' => '2026-03-20', 'fino_al' => '2026-09-30', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => $s['immobili']['A']->id, 'importo' => '60,00']], 'documento' => cgtFile('accordo.pdf'),
    ])->assertCreated();
    $this->actingAs($u)->post(lttRotta($s, 'prove.store', ['invio' => $riga->id]), ['file' => cgtFile('prova.pdf')])->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($u)->post(route('admin.gestionale.esercizi.rendiconto.versioni.carica', [$s['c'], $s['e'], $versione->id]), ['pdf' => cgtFile('rendiconto.pdf')])
        ->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($u)->post(route('admin.gestionale.esercizi.rendiconto.versioni.approva', [$s['c'], $s['e'], $versione->id]), [
        'data_delibera' => '2026-03-15', 'riparto_approvato' => '1', 'verbale' => cgtFile('verbale.pdf'),
    ])->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($u)->postJson(route('admin.gestionale.fatture.importa-xml', $c), [
        'file' => UploadedFile::fake()->createWithContent('fattura.xml', file_get_contents(base_path('tests/Fixtures/fatturapa/reale_anonimizzata_cassa_previdenziale.xml'))),
    ])->assertOk();
    // La ricevuta passa il controllo del permesso: che cosa ne fa il registro (una riga stampata non ha una PEC da confermare) è un'altra prova.
    expect($this->actingAs($u)->post(lttRotta($s, 'ricevuta', ['invio' => $riga->id]), ['ricevuta' => cgtFile('ricevuta.pdf')])->status())->not->toBe(403);

    expect(Documento::where('documentable_type', DispensaSeiMesi::class)->count())->toBe(1)
        ->and(Documento::where('documentable_type', AccordoRientro::class)->count())->toBe(1)
        ->and(Documento::where('documentable_type', RegistroInvio::class)->count())->toBe(1)
        ->and(Documento::where('documentable_type', RendicontoVersione::class)->count())->toBe(2)
        ->and($versione->fresh()->stato)->toBe('approvata');
});

test('«Registra fattura» con il file: con il solo accesso al pannello 403 e nessuna fattura; con «Crea documenti archivio» la nota nasce con il suo documento', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = setupContabile();
    $ctx = [$condominio, $esercizio, $gestione, $fornitore];
    $fattura = FatturaPassiva::create([
        'condominio_id' => $condominio->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura', 'numero_documento' => 'FT-1', 'data_documento' => now()->format('Y-m-d'), 'data_scadenza' => now()->addDays(30)->format('Y-m-d'),
        'is_pregresso' => false, 'importo_imponibile' => 10000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 10000, 'netto_a_pagare' => 10000,
        'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
    ]);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => $capitolo->id, 'descrizione' => 'Lavori', 'aliquota_iva' => 0,
        'importo_imponibile' => 10000, 'importo_iva' => 0, 'is_sopravvenienza' => false, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $prima = FatturaPassiva::count();

    $this->actingAs($this->soloPannello)->post(route('admin.gestionale.fatture.store', $condominio->id), cgtCorpoNota($ctx, $fattura, $capitolo))->assertForbidden();
    expect(FatturaPassiva::count())->toBe($prima);
    cgtNienteScritto();

    $this->actingAs($this->conArchivio)->post(route('admin.gestionale.fatture.store', $condominio->id), cgtCorpoNota($ctx, $fattura, $capitolo))
        ->assertSessionHasNoErrors()->assertRedirect();
    $nota = FatturaPassiva::where('tipo_documento', 'nota_credito')->sole();
    expect(Documento::where('documentable_type', FatturaPassiva::class)->where('documentable_id', $nota->id)->count())->toBe(1);
});

test('i documenti dell\'unità: con il solo accesso al pannello caricare, modificare ed eliminare rispondono 403 e il documento resta com\'era', function () {
    [$condominio, $unita] = cgtUnita();
    $doc = cgtDocumentoDellUnita($unita, $this->collaboratore);
    $u = $this->soloPannello;
    $r = fn (string $nome, array $altro = []) => route('admin.gestionale.immobili.documenti.'.$nome, ['condominio' => $condominio->id, 'immobile' => $unita->id] + $altro);

    $this->actingAs($u)->get($r('create'))->assertForbidden();
    $this->actingAs($u)->post($r('store'), ['name' => 'Rogito', 'is_published' => '0', 'file' => cgtFile('rogito.pdf')])->assertForbidden();
    $this->actingAs($u)->get($r('edit', ['documento' => $doc->id]))->assertForbidden();
    $this->actingAs($u)->put($r('update', ['documento' => $doc->id]), ['name' => 'Altro titolo'])->assertForbidden();
    $this->actingAs($u)->delete($r('destroy', ['documento' => $doc->id]))->assertForbidden();

    expect(Documento::count())->toBe(1)->and($doc->fresh()->name)->toBe('Planimetria')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('i documenti dell\'unità: con «Modifica condomini» si caricano, si modificano e si eliminano; il collaboratore di serie come oggi', function () {
    [$condominio, $unita] = cgtUnita();
    $r = fn (string $nome, array $altro = []) => route('admin.gestionale.immobili.documenti.'.$nome, ['condominio' => $condominio->id, 'immobile' => $unita->id] + $altro);

    foreach ([$this->conCondomini, $this->collaboratore] as $u) {
        $this->actingAs($u)->get($r('create'))->assertOk();
        $this->actingAs($u)->post($r('store'), ['name' => 'Rogito', 'is_published' => '1', 'file' => cgtFile('rogito.pdf')])->assertSessionHasNoErrors()->assertRedirect();
        $doc = Documento::where('name', 'Rogito')->sole();
        $this->actingAs($u)->get($r('edit', ['documento' => $doc->id]))->assertOk();
        $this->actingAs($u)->put($r('update', ['documento' => $doc->id]), ['name' => 'Rogito 2024'])->assertSessionHasNoErrors()->assertRedirect();
        expect($doc->fresh()->name)->toBe('Rogito 2024');
        $this->actingAs($u)->delete($r('destroy', ['documento' => $doc->id]))->assertRedirect();
        expect(Documento::whereKey($doc->id)->exists())->toBeFalse();
    }
});

test('il documento dell\'unità caricato «ad uso interno» nasce non pubblicato; la modifica di un altro non cambia chi l\'ha caricato né se è approvato', function () {
    [$condominio, $unita] = cgtUnita();
    $r = fn (string $nome, array $altro = []) => route('admin.gestionale.immobili.documenti.'.$nome, ['condominio' => $condominio->id, 'immobile' => $unita->id] + $altro);
    $giulia = $this->conCondomini;

    $this->actingAs($giulia)->post($r('store'), ['name' => 'Contratto di locazione', 'is_published' => '0', 'file' => cgtFile('contratto.pdf')])
        ->assertSessionHasNoErrors()->assertRedirect();
    $doc = Documento::where('name', 'Contratto di locazione')->sole();
    expect((bool) $doc->is_published)->toBeFalse()
        ->and((int) $doc->created_by)->toBe($giulia->id);

    // Giulia non ha «Pubblica documenti archivio»: il documento è approvato (il valore di serie) e la modifica dell'amministratore non lo tocca.
    $approvato = (bool) $doc->is_approved;
    $amministratore = User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);
    $this->actingAs($amministratore)->put($r('update', ['documento' => $doc->id]), ['name' => 'Contratto di locazione 2026', 'created_by' => $amministratore->id,
        'is_approved' => ! $approvato])->assertSessionHasNoErrors()->assertRedirect();

    $doc->refresh();
    expect($doc->name)->toBe('Contratto di locazione 2026')
        ->and((int) $doc->created_by)->toBe($giulia->id)
        ->and((bool) $doc->is_approved)->toBe($approvato);
});

/** Condominio Aurora con l'Appartamento 7. */
function cgtUnita(): array
{
    $tipologia = TipologiaImmobile::firstOrCreate(['nome' => 'Appartamento'], ['categoria' => 'unita_abitativa']);
    $condominio = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $unita = Immobile::create(['condominio_id' => $condominio->id, 'nome' => 'Appartamento 7', 'interno' => '7', 'descrizione' => 'Unità', 'tipologia_id' => $tipologia->id]);

    return [$condominio, $unita];
}

/** Un documento dell'unità già caricato, senza file sul disco: la prova guarda la riga. */
function cgtDocumentoDellUnita(Immobile $unita, User $autore): Documento
{
    $doc = $unita->documenti()->create(['name' => 'Planimetria', 'path' => 'documenti/planimetria.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
        'created_by' => $autore->id]);
    $doc->condomini()->attach($unita->condominio_id);

    return $doc;
}
