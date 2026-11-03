<?php

use App\Models\CategoriaDocumento;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * La categoria degli allegati delle fatture si ritrova per **chiave stabile**, non per etichetta.
 *
 * ## Il difetto — Coda 106
 *
 * `FatturaPassivaService` cercava `CategoriaDocumento::where('name', 'Fatture')->first()` e, quando
 * non la trovava, scriveva `category_id` a **null**. L'etichetta però è dell'amministratore, che può
 * rinominarla quando vuole: da quel momento **ogni allegato di fattura entrava in archivio senza
 * categoria**, non compariva in nessuna vista per categoria, e nessun messaggio lo diceva.
 *
 * ⚠️ La **cancellazione** era protetta per caso — `destroy()` rifiuta se la categoria ha documenti —
 * mentre la **rinomina** non lo era affatto, ed è l'azione più probabile delle due.
 *
 * ## Cosa questo file NON copre
 *
 * Non esercita `FatturaPassivaService` da capo a fondo (servirebbero condominio, esercizio, conti e
 * un file): verifica il **metodo che il servizio chiama**, e che il servizio lo chiami. Non copre le
 * tre ricerche per nome sulle categorie **eventi** (`Scadenze amministrative`), che hanno
 * `firstOrFail()` e quindi **lanciano** invece di degradare: oggi non sono raggiungibili perché
 * quelle categorie non hanno una pagina di gestione, ed è la ragione per cui la conversione del loro
 * seeder è stata rinviata.
 */
beforeEach(function () {
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($this->user);
});

it('⚠️ rinominando la categoria, il programma la trova lo stesso — prima non la trovava', function () {
    $categoria = CategoriaDocumento::where('slug', 'fatture')->firstOrFail();

    $this->put(route('admin.categorie.update', ['categoria' => $categoria->id]), [
        'name'        => 'Fatture fornitori 2026',
        'description' => 'Rinominata dall\'amministratore, come è suo diritto.',
    ])->assertRedirect();

    // ⚠️ **Questa riga è il difetto**: la ricerca di prima, dopo la rinomina, non trova più niente.
    expect(CategoriaDocumento::where('name', 'Fatture')->first())->toBeNull();

    // E questa è la correzione: la chiave stabile non è cambiata, quindi il programma la ritrova.
    $trovata = CategoriaDocumento::perFatture();

    expect($trovata)->not->toBeNull()
        ->and($trovata->id)->toBe($categoria->id)
        ->and($trovata->name)->toBe('Fatture fornitori 2026');
});

it('la rinomina non tocca la chiave stabile', function () {
    $categoria = CategoriaDocumento::where('slug', 'fatture')->firstOrFail();

    $this->put(route('admin.categorie.update', ['categoria' => $categoria->id]), [
        'name'        => 'Un nome qualunque',
        'description' => 'Descrizione qualunque.',
    ]);

    expect($categoria->refresh()->slug)->toBe('fatture');
});

it('⚠️ la chiave stabile non si può spostare da un modulo', function () {
    // ⚠️ Se `slug` fosse assegnabile in massa, un amministratore potrebbe spostare la chiave
    // «fatture» su una categoria qualunque — o toglierla — e il difetto tornerebbe da un'altra
    // porta. Non sta nel `$fillable`, e questo test lo tiene fermo.
    $creata = CategoriaDocumento::create([
        'name'        => 'Categoria mia',
        'description' => 'Creata dall\'amministratore.',
        'slug'        => 'fatture',
    ]);

    expect($creata->refresh()->slug)->toBeNull();

    // E la chiave è ancora dov'era.
    expect(CategoriaDocumento::perFatture()->name)->toBe('Fatture');
});

it('⚠️ la ricerca è un superset di quella di prima: senza slug ricade sull\'etichetta', function () {
    // È il caso di un'installazione in cui la categoria è stata ricreata a mano dopo questa
    // versione: ha il nome giusto e nessuna chiave. Deve funzionare come funzionava prima —
    // altrimenti la correzione avrebbe tolto un caso invece di aggiungerne.
    CategoriaDocumento::where('slug', 'fatture')->delete();

    $rifatta = CategoriaDocumento::create([
        'name'        => 'Fatture',
        'description' => 'Ricreata a mano.',
    ]);

    expect($rifatta->slug)->toBeNull();

    $trovata = CategoriaDocumento::perFatture();

    expect($trovata)->not->toBeNull()
        ->and($trovata->id)->toBe($rifatta->id);
});

it('senza né chiave né etichetta restituisce null, e chi chiama deve reggerlo', function () {
    // È il comportamento che il servizio già aveva — `category_id` a null — e toglierlo
    // trasformerebbe una degradazione silenziosa in un errore in faccia a chi registra una fattura.
    CategoriaDocumento::query()->delete();

    expect(CategoriaDocumento::perFatture())->toBeNull();
});

it('il servizio delle fatture usa la chiave stabile, non l\'etichetta', function () {
    // Guardia strutturale: se qualcuno rimettesse `where('name', 'Fatture')` nel servizio, il
    // difetto tornerebbe e nessuno degli altri test se ne accorgerebbe — perché tutti provano il
    // metodo del modello, non il suo chiamante.
    //
    // ⚠️ Il conteggio era 2 (una chiamata in registraFattura(), una in aggiornaFattura(), stesso
    // blocco duplicato). La 1.11.0-beta.12 (Coda 102) ha estratto quel blocco in un unico metodo
    // privato — creaDocumentoFattura(), condiviso anche dal nuovo aggiungiDocumento() — quindi la
    // chiamata resta una sola: il conteggio doveva scendere, non essere aggirato.
    $servizio = file_get_contents(app_path('Services/Gestionale/FatturaPassivaService.php'));

    expect(substr_count($servizio, 'CategoriaDocumento::perFatture()'))->toBe(1)
        ->and(str_contains($servizio, "CategoriaDocumento::where('name', 'Fatture')"))->toBeFalse();
});
