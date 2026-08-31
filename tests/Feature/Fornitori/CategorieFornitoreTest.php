<?php

use App\Models\CategoriaFornitore;
use App\Models\Fornitore;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

/**
 * Le categorie di fornitore, gestibili dalla 1.11.0-beta.9.
 *
 * Fino a questa beta erano nove righe scritte da un seeder, senza controller, senza rotte e senza
 * schermata: **non esisteva nessun test** perché non esisteva niente da provare.
 *
 * ## Cosa questo file copre, e perché queste cose
 *
 * Le due che possono far perdere dati, prima di tutto:
 *
 * 1. **L'eliminazione di una categoria usata**, che il database lascerebbe passare azzerando in
 *    silenzio `fornitori.categoria_id` (`nullOnDelete`). Il rifiuto sta nel controller, quindi è
 *    codice che qualcuno può togliere: qui si verifica sia il rifiuto **sia** che il fornitore esca
 *    dal tentativo con la sua categoria intatta.
 * 2. **La risurrezione**, cioè il motivo per cui il seeder è stato sostituito da una migrazione: se
 *    qualcuno rimettesse un seeder, una categoria cancellata di proposito tornerebbe al primo
 *    `db:seed` e nessuno collegherebbe le due cose.
 *
 * ## Cosa NON copre, dichiarato
 *
 * - non copre la resa a schermo: verifica che le rotte rispondano e che i dati cambino, non che il
 *   messaggio si veda;
 * - non copre il percorso a permessi negati, come il resto dei test dei fornitori;
 * - l'idempotenza della migrazione sta in `tests/Feature/System/UpgradeMigrationsRerunTest.php`,
 *   dove sta quella di tutte le altre.
 */
beforeEach(function () {
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($this->user);
});

/**
 * ⚠️ **Il nome predefinito è un mestiere che il programma NON spedisce.**
 *
 * `RefreshDatabase` esegue le migrazioni, e fra queste c'è `seed_categorie_fornitore`: ogni test
 * parte con le **diciannove categorie iniziali già in tabella**, non con la tabella vuota. Usare qui
 * un nome spedito — la prima stesura usava «Autospurgo» — significa creare un doppione a mano e poi
 * misurare quel doppione invece della riga del test.
 */
function creaCategoria(string $nome = 'Vetraio', ?string $descrizione = null): CategoriaFornitore
{
    return CategoriaFornitore::create(['name' => $nome, 'description' => $descrizione]);
}

function creaFornitoreConCategoria(CategoriaFornitore $categoria): Fornitore
{
    return Fornitore::create([
        'ragione_sociale'            => 'Spurghi Tevere S.r.l.',
        'stato'                      => 'attivo',
        'giorni_scadenza'            => 30,
        'modalita_pagamento_default' => 'bonifico',
        'soggetto_ritenuta'          => false,
        'perc_imponibile_ritenuta'   => 100,
        'categoria_id'               => $categoria->id,
    ]);
}

/* ------------------------------------------------------------------ l'elenco */

it('l\'elenco arriva con il numero di fornitori per categoria', function () {
    $usata = creaCategoria('Vetraio');
    creaCategoria('Antennista di prova');
    creaFornitoreConCategoria($usata);

    $this->get(route('admin.categorie-fornitore.index'))
        ->assertOk()
        ->assertInertia(function ($pagina) use ($usata) {
            $categorie = collect($pagina->toArray()['props']['categorie']);

            // Il conteggio viaggia con l'elenco perché è quello che spegne la voce «Elimina» nel
            // menù: la schermata deve poter dire «questa non si può eliminare» **prima** che
            // l'utente ci provi.
            expect($categorie->firstWhere('id', $usata->id)['fornitori_count'])->toBe(1)
                ->and($categorie->firstWhere('name', 'Antennista di prova')['fornitori_count'])->toBe(0);
        });
});

it('⚠️ e arriva anche con i fornitori, non solo col loro numero', function () {
    // ⚠️ **Senza questo test la colonna si potrebbe svuotare restando verde.** Il numero e le righe
    // sono due dati diversi con due usi diversi — il numero spegne «Elimina», le righe le disegna
    // `AnagraficheStack` — e il test qui sopra non tocca le seconde: togliendo il `with(['fornitori'])`
    // dal controller resterebbe tutto verde e la colonna mostrerebbe un trattino per tutti.
    $categoria = creaCategoria('Vetraio');
    $fornitore = creaFornitoreConCategoria($categoria);

    $this->get(route('admin.categorie-fornitore.index'))
        ->assertOk()
        ->assertInertia(function ($pagina) use ($categoria, $fornitore) {
            $riga = collect($pagina->toArray()['props']['categorie'])->firstWhere('id', $categoria->id);

            expect($riga['fornitori'])->toHaveCount(1);

            $primo = $riga['fornitori'][0];

            // La forma è quella che `AnagraficheStack` si aspetta — `nome`, non `ragione_sociale` —
            // e l'adattamento lo fa il controller: se qualcuno gli passasse il modello così com'è,
            // il pannello mostrerebbe una fila di «?» al posto delle iniziali, senza nessun errore.
            expect($primo['nome'])->toBe($fornitore->ragione_sociale)
                ->and($primo['id'])->toBe($fornitore->id)
                ->and($primo['url'])->toBe(route('admin.fornitori.show', $fornitore->id));

            // E una categoria senza fornitori manda un elenco vuoto, non l'assenza della chiave:
            // il componente distingue i due casi solo per fortuna.
            $vuota = collect($pagina->toArray()['props']['categorie'])->firstWhere('name', 'Muratore');
            expect($vuota['fornitori'])->toBe([]);
        });
});

/* ---------------------------------------------------------------- creazione */

it('una categoria nuova si crea, anche senza descrizione', function () {
    // ⚠️ **Questo test non provava niente, ed è il difetto più grave della beta.9.**
    //
    // Postava «Autospurgo», che è una delle diciannove categorie che la migrazione **spedisce**:
    // la richiesta veniva bocciata da `unique`, `assertRedirect()` era verde lo stesso — un rifiuto
    // di validazione *è* un redirect — e `exists()` era vero per la riga della migrazione, non per
    // la richiesta. Risultato: **cancellando `CategoriaFornitore::create()` dal controller, cioè la
    // funzione centrale di questa beta, l'intera suite restava verde.** Nessun altro test manda a
    // quella rotta una richiesta che debba riuscire.
    //
    // La trappola era già scritta nel docblock di `creaCategoria()` qui sopra, e il valore
    // predefinito dell'helper era stato corretto: la correzione era stata applicata a metà.
    $this->post(route('admin.categorie-fornitore.store'), ['name' => 'Vetraio'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $creata = CategoriaFornitore::where('name', 'Vetraio')->first();

    expect($creata)->not->toBeNull()
        // La descrizione è **facoltativa**, al contrario delle categorie dei documenti: il momento
        // d'uso è dentro il modulo del fornitore, e chiederne una obbligatoria lì è attrito nel
        // punto in cui serve meno. Che resti nulla è la metà che il test dichiarava di coprire e
        // non toccava.
        ->and($creata->description)->toBeNull();
});

it('⚠️ il nome si salva ripulito degli spazi, ed è quello che il «+» va a cercare', function () {
    // `TrimStrings` toglie gli spazi lato server. Il componente del «+» emette al genitore il nome
    // **ripulito**, perché è quello che finisce a database: emettendo il nome grezzo, la ricerca
    // per nome nella tendina fallirebbe e la categoria appena creata non resterebbe selezionata —
    // senza nessun errore, cioè nel modo in cui un pulsante smette di servire a qualcosa.
    $this->post(route('admin.categorie-fornitore.store'), ['name' => '  Vetraio  '])
        ->assertSessionHasNoErrors();

    expect(CategoriaFornitore::where('name', 'Vetraio')->exists())->toBeTrue()
        ->and(CategoriaFornitore::where('name', '  Vetraio  ')->exists())->toBeFalse();
});

it('due categorie non possono chiamarsi allo stesso modo', function () {
    // Il caso vero: si prova a creare una categoria che il programma **spedisce già**, perché è
    // esattamente quello che fa chi non ha scorso l'elenco fino in fondo.
    expect(CategoriaFornitore::where('name', 'Autospurgo')->count())->toBe(1);

    $this->post(route('admin.categorie-fornitore.store'), ['name' => 'Autospurgo'])
        ->assertSessionHasErrors('name');

    expect(CategoriaFornitore::where('name', 'Autospurgo')->count())->toBe(1);
});

it('una categoria senza nome non si crea', function () {
    $prima = CategoriaFornitore::count();

    $this->post(route('admin.categorie-fornitore.store'), ['name' => '', 'description' => 'Solo descrizione'])
        ->assertSessionHasErrors('name');

    // Un conteggio assoluto qui misurerebbe le categorie iniziali, non l'esito della richiesta.
    expect(CategoriaFornitore::count())->toBe($prima);
});

/* ----------------------------------------------------------------- modifica */

it('una categoria si rinomina', function () {
    $categoria = creaCategoria('Vetraio');

    $this->put(route('admin.categorie-fornitore.update', ['categoria' => $categoria->id]), [
        'name'        => 'Vetraio e serramenti',
        'description' => 'Vetri, specchi e serramenti.',
    ])->assertRedirect();

    expect($categoria->refresh()->name)->toBe('Vetraio e serramenti')
        ->and($categoria->description)->toBe('Vetri, specchi e serramenti.');
});

it('rinominare non inciampa nel proprio nome', function () {
    $categoria = creaCategoria('Vetraio', 'Vecchia descrizione');

    // Il caso che rompe una `unique` scritta male: si salva la stessa riga senza cambiarle il nome.
    $this->put(route('admin.categorie-fornitore.update', ['categoria' => $categoria->id]), [
        'name'        => 'Vetraio',
        'description' => 'Nuova descrizione',
    ])->assertSessionHasNoErrors();

    expect($categoria->refresh()->description)->toBe('Nuova descrizione');
});

it('una categoria non può prendere il nome di un\'altra', function () {
    $altra = creaCategoria('Vetraio');

    $this->put(route('admin.categorie-fornitore.update', ['categoria' => $altra->id]), ['name' => 'Autospurgo'])
        ->assertSessionHasErrors('name');

    expect($altra->refresh()->name)->toBe('Vetraio');
});

/* ------------------------------------------------------------- eliminazione */

it('una categoria che non usa nessuno si elimina', function () {
    $categoria = creaCategoria('Vetraio');

    $this->delete(route('admin.categorie-fornitore.destroy', ['categoria' => $categoria->id]))
        ->assertRedirect();

    expect(CategoriaFornitore::find($categoria->id))->toBeNull();
});

it('⚠️ una categoria usata da un fornitore non si elimina, e il fornitore la conserva', function () {
    $categoria = creaCategoria('Vetraio');
    $fornitore = creaFornitoreConCategoria($categoria);

    $this->delete(route('admin.categorie-fornitore.destroy', ['categoria' => $categoria->id]))
        ->assertRedirect();

    // ⚠️ **Il vincolo a database è `nullOnDelete`**: senza il rifiuto nel controller la richiesta
    // sarebbe riuscita, la categoria del fornitore sarebbe diventata `null` e nessun messaggio
    // l'avrebbe detto. La seconda aspettativa è quella che conta: non basta che la categoria ci sia
    // ancora, deve essere ancora **collegata**.
    expect(CategoriaFornitore::find($categoria->id))->not->toBeNull()
        ->and($fornitore->refresh()->categoria_id)->toBe($categoria->id);
});

it('il rifiuto dice quanti fornitori usano la categoria', function () {
    $categoria = creaCategoria('Vetraio');
    creaFornitoreConCategoria($categoria);
    creaFornitoreConCategoria($categoria);

    $risposta = $this->delete(route('admin.categorie-fornitore.destroy', ['categoria' => $categoria->id]));

    // Senza il numero l'amministratore non sa se deve spostare un fornitore o quaranta prima di
    // riprovare: è la differenza fra un rifiuto e un rifiuto utile.
    $messaggio = $risposta->getSession()->get('message')['message'] ?? '';

    expect($messaggio)->toContain('2')
        ->and($messaggio)->toContain('Vetraio');
});

/* ------------------------------------------------- le guardie di costruzione */

it('⚠️ nessun seeder può far risorgere una categoria cancellata', function () {
    // È **la ragione per cui questa beta ha sostituito il seeder con una migrazione**. Con un
    // `firstOrCreate` in un seeder, una categoria cancellata di proposito tornerebbe al primo
    // `db:seed`, e nessuno collegherebbe la ricomparsa a quel comando.
    // ⚠️ Non `class_exists()`: la classmap di composer può ancora contenere la voce di una classe
    // cancellata, e il tentativo di aprirne il file fa fallire il test con un errore di inclusione
    // invece che con la misura. Il file o c'è o non c'è, e non passa da nessun autoloader.
    expect(file_exists(database_path('seeders/CategoriaFornitoreSeeder.php')))->toBeFalse();

    $colpevoli = collect(glob(database_path('seeders/*.php')))
        ->filter(fn (string $file) => str_contains(file_get_contents($file), 'CategoriaFornitore::'))
        ->map(fn (string $file) => basename($file))
        ->values()
        ->all();

    expect($colpevoli)->toBe([]);
});

it('le rotte registrate sono solo quelle che il controller sa fare', function () {
    // La famiglia di guasti già arrivata dal forum due volte: `Route::resource` registra sette
    // rotte, il controller ne implementa quattro, e le tre di troppo rispondono **500** a chi ci
    // arriva per URL.
    $nomi = collect(Route::getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $n) => str_starts_with($n, 'admin.categorie-fornitore.'))
        ->sort()
        ->values()
        ->all();

    expect($nomi)->toBe([
        'admin.categorie-fornitore.destroy',
        'admin.categorie-fornitore.index',
        'admin.categorie-fornitore.store',
        'admin.categorie-fornitore.update',
    ]);
});

it('⚠️ un\'installazione si ritrova le categorie iniziali, non una tabella vuota', function () {
    // È la lezione della beta.8 e della beta.59, applicata prima che costi: una tabella che nasce
    // vuota non dà errori, non lascia log e si nota solo aprendo la tendina. Qui la consegna la fa
    // una migrazione, quindi basta che le migrazioni siano girate — cioè quello che questo test
    // verifica, dato che `RefreshDatabase` le esegue tutte.
    expect(CategoriaFornitore::count())->toBe(19)
        ->and(CategoriaFornitore::where('name', 'Elettricista')->exists())->toBeTrue()
        ->and(CategoriaFornitore::where('name', 'Utenze')->exists())->toBeTrue()
        ->and(CategoriaFornitore::where('name', 'Altro')->exists())->toBeTrue();
});
