<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\TipologiaImmobile;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate([
        'name' => 'Accesso pannello amministratore', 'guard_name' => 'web',
    ]));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->actingAs($this->user);
    $this->condominio = Condominio::factory()->create();
    $this->tipologia = TipologiaImmobile::firstOrCreate(
        ['nome' => 'Box'], ['categoria' => 'pertinenza'],
    );

    $this->creaUnita = fn (string $nome, array $attributi = []) => Immobile::create(array_merge([
        'condominio_id' => $this->condominio->id,
        'nome' => $nome,
        'descrizione' => 'Unità di prova',
        'tipologia_id' => $this->tipologia->id,
    ], $attributi));

    $this->collega = function (Immobile $unita, string $nome, array $pivot = []) {
        $soggetto = Anagrafica::factory()->create(['nome' => $nome]);
        $unita->anagrafiche()->attach($soggetto, array_merge([
            'tipologia' => 'proprietario', 'quota' => 100,
            'attivo' => true, 'data_inizio' => '2020-01-01',
        ], $pivot));

        return $soggetto;
    };

    $this->cerca = function (string $testo, array $filtri = []) {
        $risposta = $this->get(route('admin.gestionale.immobili.index', array_merge([
            'condominio' => $this->condominio, 'nome' => $testo,
        ], $filtri)));
        $risposta->assertOk();

        return $risposta->viewData('page')['props'];
    };
});

it('trova l’unità cercando parte del nome di un soggetto collegato', function () {
    $unita = ($this->creaUnita)('Interno 1');
    ($this->collega)($unita, 'Laura Rossi');
    ($this->creaUnita)('Interno 2');

    $props = ($this->cerca)('Ross');

    expect(array_column($props['immobili'], 'id'))->toBe([$unita->id]);
});

it('continua a trovare l’unità per nome con o senza soggetti', function (bool $conSoggetto) {
    $unita = ($this->creaUnita)('Interno cercato');
    if ($conSoggetto) {
        ($this->collega)($unita, 'Laura Bianchi');
    }
    ($this->creaUnita)('Altra unità');

    $props = ($this->cerca)('cercato');

    expect(array_column($props['immobili'], 'id'))->toBe([$unita->id]);
})->with([true, false]);

it('esclude le unità di un altro condominio anche con lo stesso soggetto o nome', function () {
    $unita = ($this->creaUnita)('Interno 1');
    $soggetto = ($this->collega)($unita, 'Laura Rossi');
    $altra = ($this->creaUnita)('Rossi', ['condominio_id' => Condominio::factory()->create()->id]);
    $altra->anagrafiche()->attach($soggetto, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01',
    ]);

    $props = ($this->cerca)('Rossi');

    expect(array_column($props['immobili'], 'id'))->toBe([$unita->id])
        ->and($props['meta']['total'])->toBe(1);
});

it('combina la ricerca con il filtro delle pertinenze', function (string $filtro, int $indice) {
    $abitativa = TipologiaImmobile::firstOrCreate(
        ['nome' => 'Appartamento'], ['categoria' => 'unita_abitativa'],
    );
    $principale = ($this->creaUnita)('Principale', ['tipologia_id' => $abitativa->id]);
    $collegata = ($this->creaUnita)('Box collegato', ['pertinenza_di_immobile_id' => $principale->id]);
    $esterna = ($this->creaUnita)('Box esterno', ['pertinenza_di_esterna' => 'Unità esterna']);
    $libera = ($this->creaUnita)('Box libero');
    ($this->creaUnita)('Non corrisponde');
    $unita = [$principale, $collegata, $esterna, $libera];
    foreach ($unita as $immobile) {
        ($this->collega)($immobile, 'Laura Rossi');
    }

    $props = ($this->cerca)('Rossi', ['pertinenze' => $filtro]);
    $attese = $filtro === 'collegate' ? [$collegata->id, $esterna->id] : [$unita[$indice]->id];
    // Anche il box libero è principale per il filtro esistente: non ha legami dichiarati.
    if ($filtro === 'principali') {
        $attese = [$libera->id, $principale->id];
    }

    expect(array_column($props['immobili'], 'id'))->toBe($attese);
})->with([['principali', 0], ['collegate', 1], ['da_collegare', 3]]);

it('restituisce una sola riga se corrispondono due soggetti anche quando corrisponde il nome', function (bool $nomeCorrisponde) {
    $unita = ($this->creaUnita)($nomeCorrisponde ? 'Interno Rossi' : 'Interno 1');
    ($this->collega)($unita, 'Laura Rossi');
    ($this->collega)($unita, 'Marco Rossi');

    $props = ($this->cerca)('Rossi');

    expect(array_column($props['immobili'], 'id'))->toBe([$unita->id])
        ->and($props['meta']['total'])->toBe(1);
})->with([true, false]);

it('non cerca nei collegamenti inattivi che la colonna soggetti nasconde', function () {
    $unita = ($this->creaUnita)('Interno 1');
    ($this->collega)($unita, 'Laura Rossi', ['attivo' => false]);
    ($this->collega)($unita, 'Marco Bianchi');

    $props = ($this->cerca)('Rossi');

    expect($props['immobili'])->toBe([])->and($props['meta']['total'])->toBe(0);
});

it('cerca nei collegamenti attivi anche con data di fine passata come la colonna soggetti', function () {
    $unita = ($this->creaUnita)('Interno 1');
    ($this->collega)($unita, 'Laura Rossi', ['data_fine' => '2020-12-31']);

    $props = ($this->cerca)('Rossi');

    expect(array_column($props['immobili'], 'id'))->toBe([$unita->id]);
});

it('mantiene ricerca ordinamento e righe per pagina passando alla seconda pagina', function () {
    foreach (range(1, 12) as $numero) {
        $unita = ($this->creaUnita)('Interno '.str_pad((string) $numero, 2, '0', STR_PAD_LEFT));
        ($this->collega)($unita, 'Laura Rossi');
    }
    ($this->creaUnita)('Altra unità');

    $filtri = ['per_page' => 10, 'sort' => 'nome', 'direction' => 'desc'];
    $prima = ($this->cerca)('Rossi', $filtri);
    $seconda = ($this->cerca)('Rossi', array_merge($filtri, ['page' => 2]));

    expect($prima['meta'])->toMatchArray(['total' => 12, 'per_page' => 10, 'last_page' => 2])
        ->and($prima['immobili'])->toHaveCount(10)
        ->and(array_column($seconda['immobili'], 'nome'))->toBe(['Interno 02', 'Interno 01'])
        ->and($seconda['meta']['current_page'])->toBe(2)
        ->and($seconda['filters']['nome'])->toBe('Rossi')
        ->and($seconda['sort'])->toBe('nome')
        ->and($seconda['direction'])->toBe('desc');
});
