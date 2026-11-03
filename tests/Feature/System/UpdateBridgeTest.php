<?php

use App\Models\User;
use App\Services\System\SystemFinalizer;
use App\Services\UpdateService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

/**
 * Preparazione dell'aggiornamento automatico: dove finisce il bridge, dove sta e
 * cosa contiene il file di scambio, e quando il sistema si considera "occupato".
 *
 * Il punto dolente che questi test presidiano è la POSIZIONE. Fino alla 1.9 il
 * bridge veniva copiato in base_path('index.php') e il browser mandato su
 * /index.php: funziona solo dove la cartella pubblica del server coincide con
 * la radice del progetto (hosting condivisi con l'.htaccess che scrive
 * l'installer). Su qualunque installazione con document root su public/ — VPS,
 * container, vhost Apache, l'immagine Docker standard — quel POST arrivava
 * invece al front controller di Laravel: l'aggiornamento non partiva e
 * restavano a terra il bridge e il JSON, con la pagina bloccata su
 * "aggiornamento in corso".
 *
 * Il file di scambio contiene il token del ponte e sta sotto storage/
 * (storage/app/update_bridge.json): l'.htaccess del setup nega storage/, e con
 * la radice del sito su public/ è fuori dal web. Nella radice del progetto un
 * file con lo stesso nome non vale, e si toglie: preparando l'aggiornamento, guardando se è in
 * corso e nella finalizzazione (che gira anche con l'aggiornamento automatico spento). Se non
 * si riesce a toglierlo, il registro lo dice con un avviso e non scrive che è stato tolto.
 *
 * Nota sugli effetti collaterali: prepareForUpgrade() scrive davvero due file
 * dentro il progetto (il file di scambio sotto storage/app e il bridge in
 * public/). L'afterEach qui sotto li rimuove sempre, anche se un test fallisce
 * a metà, insieme a un update_bridge.json nella radice (anche una cartella con
 * quel nome); lo stesso fa il beforeEach, per i resti di una corsa interrotta.
 */
function releaseFinta(): array
{
    return [
        'version' => '9.9.9',
        'url' => 'https://example.test/packages/km_v9.9.9.zip',
        'hash' => str_repeat('a', 64),
        'requirements' => ['php' => '8.0.0'],
    ];
}

/** Dove prepareForUpgrade() scrive il file di scambio. */
function updateBridgeFileDiScambio(): string
{
    return base_path('storage/app/update_bridge.json');
}

/** I file che questi test scrivono nel progetto: il file di scambio, un update_bridge.json nella radice (anche una cartella con quel nome) e il ponte. */
function updateBridgeToglieIResidui(): void
{
    foreach ([updateBridgeFileDiScambio(), base_path('update_bridge.json'), public_path('km-update.php')] as $residuo) {
        if (is_dir($residuo) && ! is_link($residuo)) {
            File::deleteDirectory($residuo);
        } elseif (File::exists($residuo)) {
            File::delete($residuo);
        }
    }
}

beforeEach(function () {
    updateBridgeToglieIResidui();
    config()->set('installer.run_installer', true);
});

afterEach(function () {
    updateBridgeToglieIResidui();
});

it('la pulizia dei test toglie anche una cartella con il nome del file di scambio nella radice', function () {
    // Il resto di una corsa interrotta nel test che crea quella cartella: non deve restare nel progetto né far fallire il test che segue.
    $nellaRadice = base_path('update_bridge.json');
    mkdir($nellaRadice);

    try {
        updateBridgeToglieIResidui();

        expect(file_exists($nellaRadice))->toBeFalse();
    } finally {
        @rmdir($nellaRadice);
    }
});

it('copia il bridge dentro public e non nella radice del progetto', function () {
    $service = app(UpdateService::class);

    $service->prepareForUpgrade(releaseFinta());

    expect(File::exists(public_path('km-update.php')))->toBeTrue()
        ->and(File::exists(base_path('index.php')))->toBeFalse();
});

it('non chiama mai il bridge index.php, che sarebbe il front controller', function () {
    $service = app(UpdateService::class);

    $service->prepareForUpgrade(releaseFinta());

    // Il file di Laravel deve restare intatto: se il bridge prendesse quel nome,
    // il deploy lo sovrascriverebbe e l'autodistruzione lo cancellerebbe,
    // lasciando il sito irraggiungibile.
    expect(File::get(public_path('index.php')))
        ->not->toContain('Auto-Update Engine');
});

it('restituisce l\'indirizzo pubblico da aprire nel browser', function () {
    $service = app(UpdateService::class);

    $esito = $service->prepareForUpgrade(releaseFinta());

    expect($esito['url'])->toEndWith('/km-update.php')
        ->and($esito['token'])->toHaveLength(64);
});

it('scrive nel file di scambio la radice del progetto, invece di farla dedurre', function () {
    $service = app(UpdateService::class);

    $service->prepareForUpgrade(releaseFinta());

    $bridge = json_decode(File::get(updateBridgeFileDiScambio()), true);

    expect($bridge['paths']['base'])->toBe(base_path())
        ->and($bridge['paths']['public'])->toBe(public_path())
        ->and($bridge['package']['version'])->toBe('9.9.9');
});

it('tiene il file di scambio sotto storage, fuori dalla radice e dalla cartella pubblica, perche contiene il token', function () {
    $service = app(UpdateService::class);

    $service->prepareForUpgrade(releaseFinta());

    // public/storage porta dentro storage/app/public: anche quella cartella è pubblica.
    expect(File::exists(updateBridgeFileDiScambio()))->toBeTrue()
        ->and(File::exists(base_path('update_bridge.json')))->toBeFalse()
        ->and(File::exists(public_path('update_bridge.json')))->toBeFalse()
        ->and(File::exists(storage_path('app/public/update_bridge.json')))->toBeFalse();
});

it('il deploy non sovrascrive il file di scambio: le esclusioni ne coprono il percorso', function () {
    app(UpdateService::class)->prepareForUpgrade(releaseFinta());

    $bridge = json_decode(File::get(updateBridgeFileDiScambio()), true);
    $relativo = 'storage/app/update_bridge.json';

    // La stessa regola del deploy nel ponte: la voce stessa, o una cartella che la contiene.
    $coperto = collect($bridge['package']['exclude'])
        ->contains(fn ($voce) => $relativo === $voce || str_starts_with($relativo, $voce.'/'));

    expect($coperto)->toBeTrue();
});

it('nella radice del progetto un update_bridge.json non vale: non tiene occupato il sistema e si toglie', function () {
    File::put(base_path('update_bridge.json'), json_encode([
        'security' => ['token' => str_repeat('b', 64), 'expires_at' => time() + 600],
        'package' => ['version' => '9.9.8'],
    ]));

    expect(app(UpdateService::class)->isUpgradeInProgress())->toBeFalse()
        ->and(File::exists(base_path('update_bridge.json')))->toBeFalse();
});

it('preparando un aggiornamento toglie un update_bridge.json dalla radice del progetto', function () {
    File::put(base_path('update_bridge.json'), json_encode([
        'security' => ['token' => str_repeat('b', 64), 'expires_at' => time() + 600],
        'package' => ['version' => '9.9.8'],
    ]));

    app(UpdateService::class)->prepareForUpgrade(releaseFinta());

    expect(File::exists(base_path('update_bridge.json')))->toBeFalse()
        ->and(File::exists(updateBridgeFileDiScambio()))->toBeTrue();
});

it('se non riesce a togliere un update_bridge.json dalla radice, non scrive di averlo tolto e lo avvisa', function () {
    // Una cartella con quel nome non si toglie con `File::delete`: il file che resta nella radice è un fatto, e il registro lo dice.
    $nellaRadice = base_path('update_bridge.json');
    mkdir($nellaRadice);
    Log::spy();

    try {
        app(UpdateService::class)->isUpgradeInProgress();

        Log::shouldNotHaveReceived('info');
        Log::shouldHaveReceived('warning')->with('Update bridge file in the project root could not be removed')->once();
        expect(file_exists($nellaRadice))->toBeTrue();
    } finally {
        @rmdir($nellaRadice);
    }
});

it('la finalizzazione dell\'aggiornamento toglie un update_bridge.json dalla radice anche con l\'aggiornamento automatico spento', function () {
    // Chi aggiorna a mano passa dalla finalizzazione e non dalla pagina di avvio, che si ferma al cancello dell'aggiornamento automatico.
    config()->set('installer.run_installer', false);
    $this->mock(SystemFinalizer::class, fn ($finalizzatore) => $finalizzatore->shouldReceive('finalize')->once());

    Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $amministratore = User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole('amministratore');

    File::put(base_path('update_bridge.json'), json_encode([
        'security' => ['token' => str_repeat('b', 64), 'expires_at' => time() + 600],
        'package' => ['version' => '9.9.8'],
    ]));

    $this->actingAs($amministratore)->post(route('system.upgrade.run'))->assertRedirect(route('system.upgrade.changelog'));

    expect(File::exists(base_path('update_bridge.json')))->toBeFalse();
});

it('considera l\'aggiornamento in corso finche il token e valido', function () {
    $service = app(UpdateService::class);

    $service->prepareForUpgrade(releaseFinta());

    expect($service->isUpgradeInProgress())->toBeTrue();
});

it('non resta bloccato per sempre su un bridge scaduto', function () {
    $service = app(UpdateService::class);

    $service->prepareForUpgrade(releaseFinta());

    // Un lancio non andato a buon fine (pagina chiusa, indirizzo che non
    // risponde) lasciava il file lì e la pagina aggiornamenti mostrava
    // "in corso" a vita, senza modo di uscirne.
    $bridge = json_decode(File::get(updateBridgeFileDiScambio()), true);
    $bridge['security']['expires_at'] = time() - 60;
    File::put(updateBridgeFileDiScambio(), json_encode($bridge));

    expect($service->isUpgradeInProgress())->toBeFalse()
        ->and(File::exists(updateBridgeFileDiScambio()))->toBeFalse();
});

it('un file di lock orfano nella cartella temporanea non blocca piu l aggiornamento', function () {
    // Il controllo tolto cercava `km_lock_*.lock` in `sys_get_temp_dir()`. Cercava un file che
    // questo prodotto NON HA MAI SCRITTO: verificato su tutta la cronologia, `km_lock_` compare
    // in un solo file — UpdateService — dal primo commit dell'aggiornamento automatico.
    //
    // Non era inerte. Il `glob` non guardava l'età e non qualificava il proprietario, e su un
    // hosting condiviso la cartella temporanea è comune a più siti: un file con quel nome —
    // vecchio di mesi, o di qualcun altro — bloccava l'aggiornamento **per sempre**, mostrando
    // solo un pulsante grigio senza spiegazione. E chi sta su hosting condiviso non ha una
    // console per andare a cancellarlo.
    //
    // Trovato il 27/08/2026 da uno screenshot di un amministratore su Altervista, fermo con
    // «Scarica e Installa» disabilitato mentre PHP e requisiti erano a posto.
    $orfano = sys_get_temp_dir().'/km_lock_'.uniqid().'.lock';
    File::put($orfano, 'residuo di un meccanismo mai costruito');

    try {
        expect(app(UpdateService::class)->isUpgradeInProgress())->toBeFalse(
            'Un file di lock orfano sta ancora bloccando l aggiornamento.'
        );
    } finally {
        @unlink($orfano);
    }
});

it('il bridge porta la firma che la pulizia post-aggiornamento cerca', function () {
    // cleanupInstallerJunk() rimuove i file residui riconoscendoli dal contenuto.
    // Cercava 'Bridge-Only', stringa che il bridge non ha mai contenuto: una
    // copia rimasta indietro non veniva quindi mai rimossa.
    expect(File::get(base_path('resources/installer/index.php')))
        ->toContain('Auto-Update Engine');
});

it('rifiuta di preparare l\'aggiornamento se gli automatismi sono disattivati', function () {
    config()->set('installer.run_installer', false);

    expect(fn () => app(UpdateService::class)->prepareForUpgrade(releaseFinta()))
        ->toThrow(Exception::class);

    expect(File::exists(public_path('km-update.php')))->toBeFalse();
});
