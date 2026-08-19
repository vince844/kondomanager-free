<?php

/**
 * Il limite di caricamento dei file: tre numeri diversi, e vinceva quello che nessuno dichiarava.
 *
 * ## Origine: segnalazione dal forum, 18/08/2026
 *
 * *«Cercando di fare l'upload di un file grande 4376KB si ottiene il generico errore "L'upload di
 * file è fallito", nonostante è riportato un limite massimo di 10MB (provando con un file grande
 * 1926KB l'upload va a buon fine).»*
 *
 * I numeri in gioco erano tre:
 *
 * | Dove | Limite |
 * | :--- | :--- |
 * | la schermata | 10 MB, scritto a mano nel testo |
 * | la validazione | `max:20480`, cioè 20 MB |
 * | il server di chi segnala | ~2 MB (`upload_max_filesize` di PHP) |
 *
 * A vincere è sempre il terzo, ed è l'unico che non dichiaravamo. Quando il file lo supera, **non
 * arriva mai al programma**: lo scarta PHP, e a Laravel resta solo la regola `uploaded`, che produce
 * il messaggio generico che l'amministratore ha visto.
 *
 * ## Cosa questo file prova
 *
 * Che il limite mostrato è quello **effettivo**, cioè il più basso dei tre, e che quando il file
 * viene scartato il messaggio dice il numero vero invece di «l'upload è fallito».
 */

use App\Support\LimiteCaricamento;

it('il limite effettivo è il più basso dei tre, non quello scritto nel testo', function () {
    $effettivo = LimiteCaricamento::megabyte();

    expect($effettivo)->toBeLessThanOrEqual(LimiteCaricamento::daIni('upload_max_filesize') / 1048576)
        ->and($effettivo)->toBeLessThanOrEqual(LimiteCaricamento::daIni('post_max_size') / 1048576)
        ->and($effettivo)->toBeLessThanOrEqual(20.0); // il tetto della nostra regola di validazione
});

it('sa leggere le sigle di php.ini, che non sono numeri', function () {
    // `upload_max_filesize = 2M` non è «2»: è la ragione per cui un confronto ingenuo con un numero
    // di byte darebbe sempre lo stesso esito sbagliato.
    expect(LimiteCaricamento::interpreta('2M'))->toBe(2 * 1048576)
        ->and(LimiteCaricamento::interpreta('128M'))->toBe(128 * 1048576)
        ->and(LimiteCaricamento::interpreta('1G'))->toBe(1073741824)
        ->and(LimiteCaricamento::interpreta('512K'))->toBe(512 * 1024)
        ->and(LimiteCaricamento::interpreta('-1'))->toBe(PHP_INT_MAX);
});

it('in php.ini «0» significa «nessun limite», non «zero byte»', function () {
    // ⚠️ Reperto della Fase 1-bis. `post_max_size = 0` è la configurazione **documentata da PHP**
    // per togliere il limite — la scelta di chi vuole caricamenti grandi. La prima stesura la
    // leggeva come zero byte: la schermata annunciava «max 0 MB» e respingeva qualunque file,
    // anche da 1 KB. Cioè la correzione del limite si sarebbe trasformata in un blocco totale
    // proprio sull'installazione di chi il limite lo aveva tolto apposta.
    expect(LimiteCaricamento::interpreta('0'))->toBe(PHP_INT_MAX)
        ->and(LimiteCaricamento::interpreta('0M'))->toBe(PHP_INT_MAX)
        ->and(LimiteCaricamento::interpreta('-1'))->toBe(PHP_INT_MAX);
});

it('legge le sigle minuscole e con spazi, che php.ini ammette', function () {
    expect(LimiteCaricamento::interpreta('2m'))->toBe(2 * 1048576)
        ->and(LimiteCaricamento::interpreta(' 8M '))->toBe(8 * 1048576)
        ->and(LimiteCaricamento::interpreta('1g'))->toBe(1073741824);
});

it('il limite del server è cosa diversa dal tetto che ci diamo noi', function () {
    // ⚠️ Reperto della Fase 1-bis: il messaggio della regola `uploaded` scatta quando è **PHP** ad
    // aver scartato il file, e in quel momento il nostro tetto di 20 MB non è mai entrato in gioco.
    // Dichiararlo lì portava il limite dei documenti su schermate che ne hanno altri — l'importatore
    // ne dichiara 25 — cioè sostituiva una bugia generica con una bugia informata.
    $server = min(LimiteCaricamento::daIni('upload_max_filesize'), LimiteCaricamento::daIni('post_max_size'));

    expect(LimiteCaricamento::etichettaServer())->toContain('MB')
        ->and(LimiteCaricamento::byteServer())->toBe($server);
});

it('la regola di validazione non promette più di quanto il server accetti', function () {
    // Prima la regola diceva 20 MB su qualunque installazione. Su un server da 2 MB era una
    // promessa che il server smentiva prima ancora che la regola venisse valutata.
    expect(LimiteCaricamento::regolaMax())->toBeLessThanOrEqual(20480)
        ->and(LimiteCaricamento::regolaMax())->toBe((int) floor(LimiteCaricamento::megabyte() * 1024));
});

it('quando il file viene scartato dal server, il messaggio dice il limite vero', function () {
    // Si riproduce il caso reale: un file che PHP ha già respinto arriva con il codice d'errore
    // `UPLOAD_ERR_INI_SIZE`, `isValid()` è falso e scatta la regola `uploaded` — la stessa che
    // produceva «L'upload di file è fallito» sul server di chi ha segnalato.
    // NB: `trans()` da solo non basterebbe, perché il sostitutore di `:limite` agisce durante la
    // validazione e non nella semplice traduzione. Era l'errore della prima stesura di questo test.
    $scartato = new \Illuminate\Http\UploadedFile(
        __FILE__, 'grande.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, false
    );

    $validatore = \Illuminate\Support\Facades\Validator::make(
        ['documento' => $scartato],
        ['documento' => 'file'],
    );

    expect($validatore->fails())->toBeTrue();

    $messaggio = $validatore->errors()->first('documento');

    // `etichettaServer()` e non `etichetta()`: quando questa regola scatta il file l'ha scartato
    // **PHP**, e il nostro tetto di 20 MB non è mai entrato in gioco. La prima stesura del test
    // fissava il comportamento sbagliato, ed è caduta quando è stato corretto — come doveva.
    expect($messaggio)->toContain(LimiteCaricamento::etichettaServer())
        ->and($messaggio)->toContain('upload_max_filesize')
        ->and($messaggio)->not->toContain(':limite');
});

/**
 * ## Le due schermate della stessa funzione, e la seconda che era rimasta indietro
 *
 * La segnalazione parlava del **caricamento**, e la correzione è partita da lì. Ma il documento di
 * un'unità si carica da una schermata e si sostituisce da un'altra, e la seconda continuava a
 * scrivere «Max 20MB» a mano mentre `UpdateImmobileDocumentoRequest` validava già con il limite del
 * server: la stessa promessa non mantenuta che il forum aveva segnalato, una schermata più in là.
 *
 * Questi due test tengono le due schermate insieme. Non provano un numero — il numero dipende dal
 * `php.ini` della macchina che li esegue — ma che entrambe **ricevano quello del server**.
 */
describe('il limite arriva a tutte e due le schermate del documento', function () {
    beforeEach(function () {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        foreach (['Accesso pannello amministratore', \App\Enums\Permission::PUBLISH_ARCHIVE_DOCUMENTS->value] as $nome) {
            $ruolo->givePermissionTo(
                \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web'])
            );
        }

        $this->user = \App\Models\User::factory()->create();
        $this->user->assignRole($ruolo);

        $this->condominio = \App\Models\Condominio::factory()->create();
        \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id]);
        \App\Models\Gestionale\PianoConto::factory()->create(['condominio_id' => $this->condominio->id]);

        $this->immobile = \App\Models\Immobile::create([
            'condominio_id' => $this->condominio->id,
            'nome'          => 'Appartamento 1',
            'interno'       => '1',
        ]);
    });

    it('la schermata di caricamento riceve il limite del server', function () {
        $this->actingAs($this->user)
            ->get(route('admin.gestionale.immobili.documenti.create', [
                'condominio' => $this->condominio->id,
                'immobile'   => $this->immobile->id,
            ]))
            ->assertInertia(fn ($page) => $page->where('limiteFile', LimiteCaricamento::etichetta()));
    });

    it('anche la schermata di modifica lo riceve, e non lo scrive più a mano', function () {
        $documento = $this->immobile->documenti()->create([
            'name'         => 'Visura catastale',
            'path'         => 'documenti/visura.pdf',
            'mime_type'    => 'application/pdf',
            'file_size'    => 122880,
            'is_published' => true,
            'is_approved'  => true,
            'created_by'   => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('admin.gestionale.immobili.documenti.edit', [
                'condominio' => $this->condominio->id,
                'immobile'   => $this->immobile->id,
                'documento'  => $documento->id,
            ]))
            ->assertInertia(fn ($page) => $page->where('limiteFile', LimiteCaricamento::etichetta()));
    });
});
