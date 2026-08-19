<?php

/**
 * La ricerca del Comune: il pulsante accanto al campo che resta libero.
 *
 * ## Cosa deve fare, e perché in questa forma
 *
 * Restituisce pochi risultati, ognuno con **la sua provincia**. La provincia non è un ornamento:
 * misurata sulla fonte, ci sono **cinque denominazioni ripetute** su comuni diversi — Samone, Livo,
 * Peglio, Castro, San Teodoro — e senza provincia si sceglie a caso fra due codici catastali
 * diversi. Un aiuto che fa scegliere a caso è peggio del campo vuoto.
 *
 * ## I casi che la fonte impone e che nessuno indovina
 *
 * - **121 comuni bilingui** («Aldino/Aldein»): chi cerca in tedesco deve trovarli, o l'aiuto non
 *   serve proprio dove serve di più.
 * - Si cerca **anche per codice**: chi ha il codice sotto mano e vuole il nome fa il giro inverso.
 * - Le denominazioni portano accenti e apostrofi («Romano d'Ezzelino»): la ricerca non può
 *   pretendere che si scrivano.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la resa del pulsante e della finestra a video (tre viste), né il fatto che il campo
 * resti scrivibile a mano: quello è in `CampoComuneRestaLiberoTest`.
 */

use App\Models\Comune;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate([
        'name' => 'Accesso pannello amministratore', 'guard_name' => 'web',
    ]));

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    Artisan::call('kondomanager:aggiorna-comuni');
});

function cerca(string $q): array
{
    return test()->actingAs(test()->user)
        ->getJson(route('comuni.cerca', ['q' => $q]))
        ->assertOk()
        ->json();
}

it('cerca per nome e restituisce il codice catastale', function () {
    $esito = cerca('linguaglossa');

    expect($esito['comuni'][0]['nome'])->toBe('Linguaglossa')
        ->and($esito['comuni'][0]['codice_catasto'])->toBe('E602');
});

it('su un nome ripetuto restituisce entrambi, e la provincia è ciò che li distingue', function () {
    // Samone esiste in provincia di Torino (H753) e di Trento (H754). Senza la provincia a video,
    // chi sceglie sta tirando a indovinare fra due codici catastali diversi.
    $trovati = collect(cerca('Samone')['comuni'])->where('nome', 'Samone');

    expect($trovati)->toHaveCount(2)
        ->and($trovati->pluck('provincia')->sort()->values()->all())->toBe(['Torino', 'Trento'])
        ->and($trovati->pluck('codice_catasto')->unique())->toHaveCount(2);
});

it('trova i comuni bilingui anche cercandoli nell\'altra lingua', function () {
    $esito = cerca('Aldein');

    expect(collect($esito['comuni'])->pluck('nome'))->toContain('Aldino');
});

it('cerca anche per codice catastale, per fare il giro inverso', function () {
    $esito = cerca('H501');

    expect($esito['comuni'][0]['nome'])->toBe('Roma');
});

it('non pretende le maiuscole giuste', function () {
    expect(collect(cerca('ROMA')['comuni'])->pluck('nome'))->toContain('Roma')
        ->and(collect(cerca('roma')['comuni'])->pluck('nome'))->toContain('Roma');
});

it('dichiara la data della fonte, che è il motivo per cui l\'aiuto è accettabile', function () {
    // La coda ㊹ lo mette come condizione, non come ornamento: un elenco che non dice a quando è
    // aggiornato invecchia in silenzio, e chi lo legge si fida lo stesso.
    $doc = json_decode(file_get_contents(resource_path('data/comuni/comuni-italiani.json')), true);

    expect(cerca('Roma')['aggiornato_al'])->toBe($doc['aggiornato_al']);
});

it('non restituisce l\'elenco intero a chi cerca poco o niente', function () {
    // Una richiesta senza testo non deve scaricare 7.894 righe nel browser.
    expect(cerca('')['comuni'])->toBe([])
        ->and(count(cerca('a')['comuni']))->toBeLessThanOrEqual(20);
});

it('senza accesso al pannello non si cerca', function () {
    $estraneo = User::factory()->create();

    $this->actingAs($estraneo)
        ->getJson(route('comuni.cerca', ['q' => 'Roma']))
        ->assertForbidden();
});

it('non trova niente non è un errore: è la risposta giusta per un comune fuso o scritto male', function () {
    $esito = cerca('Comune Che Non Esiste');

    expect($esito['comuni'])->toBe([])
        ->and($esito['aggiornato_al'])->not->toBeNull();
});

it('la tabella regge la ricerca senza scandire tutto: il codice catastale è univoco a database', function () {
    expect(fn () => Comune::create([
        'codice_catasto' => 'H501',
        'nome'           => 'Doppione',
        'sigla'          => 'XX',
        'provincia'      => 'X',
        'regione'        => 'X',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

/**
 * ## I modi in cui la gente scrive davvero i nomi dei Comuni
 *
 * Aggiunti dopo la revisione avversariale della beta.59, che ha misurato tre buchi su una ricerca
 * che cercava **solo per prefisso e solo sulla forma esatta**:
 *
 * | Cosa si scrive | Prima | Perché |
 * | :--- | :--- | :--- |
 * | `Reggio Emilia` | 0 risultati | il nome vero è «Reggio nell'Emilia», e la ricerca partiva da sinistra |
 * | `Spezia` | 0 risultati | il nome vero è «La Spezia» |
 * | `Sant’Agata` | 0 risultati | 314 comuni hanno l'apostrofo, nessuno nella forma tipografica |
 * | `Sant Agata` | 0 risultati | è il modo più comune di batterlo a tastiera |
 *
 * **2.687 nomi su 7.894 sono multi-parola**: la ricerca per solo prefisso non li trova a chi non
 * ricorda la prima parola. E il difetto peggiore non era il vuoto: era il messaggio che il vuoto
 * produceva, che dava la colpa a una fusione — falso per La Spezia.
 */
describe('la ricerca regge i modi in cui i nomi si scrivono davvero', function () {
    it('trova un comune anche partendo da una parola che non è la prima', function () {
        expect(collect(cerca('Spezia')['comuni'])->pluck('nome'))->toContain('La Spezia');
    });

    it('trova un nome multi-parola saltando le particelle in mezzo', function () {
        expect(collect(cerca('Reggio Emilia')['comuni'])->pluck('nome'))->toContain('Reggio nell\'Emilia');
    });

    it('non pretende l\'apostrofo tipografico, che è quello che scrivono i telefoni', function () {
        $dritto = collect(cerca("Sant'Agata")['comuni'])->pluck('nome');
        $tipografico = collect(cerca('Sant’Agata')['comuni'])->pluck('nome');

        expect($tipografico->all())->toBe($dritto->all())
            ->and($tipografico)->not->toBeEmpty();
    });

    it('non pretende nemmeno l\'apostrofo, perché a tastiera si salta', function () {
        expect(collect(cerca('Sant Agata')['comuni'])->pluck('nome')->first())
            ->toStartWith('Sant\'Agata');
    });

    it('non pretende gli accenti', function () {
        expect(collect(cerca('Forli')['comuni'])->pluck('nome'))->toContain('Forlì')
            ->and(collect(cerca('Cefalu')['comuni'])->pluck('nome'))->toContain('Cefalù');
    });

    it('dice quanti risultati ha tagliato, invece di tacere', function () {
        // «Castel» dà 193 comuni e la finestra ne mostra venti: senza il totale, chi cerca
        // «Castelvetro» e non lo vede pensa che non ci sia.
        $esito = cerca('Castel');

        expect(count($esito['comuni']))->toBe(20)
            ->and($esito['totale'])->toBeGreaterThan(100);
    });

    it('quando il testo basta a restringere, il totale coincide con i risultati', function () {
        $esito = cerca('Linguaglossa');

        expect($esito['totale'])->toBe(count($esito['comuni']));
    });

    it('quello che hai scritto sta in cima, anche se alfabeticamente verrebbe dopo', function () {
        // ⚠️ È la regressione che la ricerca «per parole in mezzo» ha introdotto e che va tenuta
        // chiusa: cercando «Roma» esistono venti comuni che contengono «roma» — Barbarano Romano,
        // Bassano Romano, Roccaromana — e in ordine alfabetico **Roma non era fra i venti**. La
        // ricerca migliorata perdeva il caso più facile di tutti.
        expect(cerca('Roma')['comuni'][0]['nome'])->toBe('Roma')
            ->and(cerca('Samone')['comuni'][0]['nome'])->toBe('Samone')
            ->and(cerca('Castel')['comuni'][0]['nome'])->toStartWith('Castel');
    });

    it('una riga scritta fuori dal comando è cercabile lo stesso', function () {
        // `upsert` non fa scattare gli eventi del model, quindi il comando calcola `nome_ricerca` a
        // mano. Tutte le altre strade — un `create()`, uno script, una riga aggiunta di corsa —
        // passano dal model: senza l'aggancio, una riga così entrerebbe e non si troverebbe mai.
        Comune::create([
            'codice_catasto' => 'Z998',
            'nome'           => "Sant'Ù Prova",
            'sigla'          => 'ZZ',
            'provincia'      => 'Prova',
            'regione'        => 'Prova',
            'fonte_al'       => '2026-02-21',
        ]);

        expect(collect(cerca('Sant U Prova')['comuni'])->pluck('nome'))->toContain("Sant'Ù Prova");
    });

    it('un parametro malformato non fa esplodere la pagina', function () {
        // `?q[]=Roma` faceva scattare un warning PHP su `(string) $array`, e in una richiesta HTTP
        // Laravel trasforma i warning in eccezioni: 500 invece di una lista vuota.
        $this->actingAs($this->user)
            ->getJson(route('comuni.cerca').'?q[]=Roma')
            ->assertOk()
            ->assertJsonPath('comuni', []);
    });
});
