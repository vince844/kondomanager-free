<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\ImportVerificaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * La porta di servizio della destinazione scelta a mano.
 *
 * `decidi()` risponde a un elenco chiuso — «unisci», «dividi», «salta» — e per questo non ha
 * bisogno di autorizzare niente: qualunque valore passi, è una risposta su dati che l'utente ha
 * caricato lui. Questa invece porta un **id**, e un id indica una riga dell'archivio che
 * l'utente potrebbe non poter toccare: senza il controllo, chi può soltanto creare condomìni
 * potrebbe scrivere quaranta unità dentro un condominio esistente passando l'id nella richiesta.
 *
 * L'esercizio si accetta solo se appartiene al condominio scelto. Due tendine indipendenti sono
 * due modi di sbagliare, e l'errore sarebbe silenzioso: le titolarità finirebbero su un esercizio
 * di un altro stabile con i totali che tornano lo stesso.
 */
function utenteCheImporta(bool $puoModificare = true): User
{
    // «Accesso pannello amministratore» non serve all'endpoint: lo pretende la pagina 403, che
    // altrimenti muore mentre disegna il rifiuto invece di mostrarlo.
    foreach ([
        App\Enums\Permission::CREATE_CONDOMINI->value,
        App\Enums\Permission::EDIT_CONDOMINI->value,
        App\Enums\Permission::IMPORTA_DATI->value,
        'Accesso pannello amministratore',
    ] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo(App\Enums\Permission::CREATE_CONDOMINI->value);
    // ⚠️ Dal 30/08/2026 importare vuole «Importa dati»: «Crea condomini» non basta più.
    $u->givePermissionTo(App\Enums\Permission::IMPORTA_DATI->value);

    if ($puoModificare) {
        $u->givePermissionTo(App\Enums\Permission::EDIT_CONDOMINI->value);
    }

    return $u;
}

function condominioEsistente(string $nome = 'CONDOMINIO ESISTENTE', string $cf = '97123456780'): Condominio
{
    return Condominio::create([
        'nome' => $nome,
        'codice_fiscale' => $cf,
        'indirizzo' => 'Via dell\'Archivio 1',
        'cap' => '00100',
        'comune' => 'Roma',
        'provincia' => 'RM',
    ]);
}

it('registra la destinazione scelta', function () {
    $lotto = ImportBatch::create(['sorgente' => 'danea']);
    $c = condominioEsistente();

    $this->actingAs(utenteCheImporta())
        ->put(route('import.destinazione', $lotto->uuid), ['condominio_id' => $c->id])
        ->assertRedirect();

    expect($lotto->fresh()->decisioni[ImportVerificaService::DESTINAZIONE_CONDOMINIO])->toBe($c->id);
});

it('l\'esercizio non è un parametro: lo risolve il programma, e ignora quello passato a mano', function () {
    // ⚠️ Per una manciata di ore l'esercizio è stato una seconda tendina. La sua data di inizio
    // diventa la `data_inizio` di **ogni** titolarità scritta, quindi l'anno sbagliato non dà
    // nessun errore: scrive numeri giusti nel periodo sbagliato. Ora si usa quello **aperto**,
    // come fa `HasEsercizio::getEsercizioCorrente()` in tutto il resto del prodotto, e un
    // `esercizio_id` nella richiesta non deve poter cambiare niente.
    $lotto = ImportBatch::create(['sorgente' => 'danea']);
    $c = condominioEsistente();

    $chiuso = Esercizio::create([
        'condominio_id' => $c->id,
        'nome' => '2023/2024',
        'data_inizio' => '2023-11-01',
        'data_fine' => '2024-10-31',
        'stato' => 'chiuso',
    ]);
    Esercizio::create([
        'condominio_id' => $c->id,
        'nome' => '2025/2026',
        'data_inizio' => '2025-11-01',
        'data_fine' => '2026-10-31',
        'stato' => 'aperto',
    ]);

    $this->actingAs(utenteCheImporta())
        ->put(route('import.destinazione', $lotto->uuid), [
            'condominio_id' => $c->id,
            'esercizio_id' => $chiuso->id,
        ]);

    $decisioni = $lotto->fresh()->decisioni;

    expect($decisioni)->not->toHaveKey('destinazione:esercizio');

    $letto = app(ImportVerificaService::class)->verifica($lotto->fresh());

    expect($letto['canonici']['esercizi']->etichetta)->toBe('2025/2026');
});

it('chi non può modificare i condomìni non può importarci dentro', function () {
    // Aggiungere quaranta unità a un condominio esistente **è** modificarlo: il permesso di
    // creare condomìni basta a farne uno nuovo, non a scrivere dentro quelli degli altri.
    $lotto = ImportBatch::create(['sorgente' => 'danea']);
    $c = condominioEsistente();

    $this->actingAs(utenteCheImporta(puoModificare: false))
        ->put(route('import.destinazione', $lotto->uuid), ['condominio_id' => $c->id])
        ->assertForbidden();

    expect($lotto->fresh()->decisioni ?? [])->toBe([]);
});

it('cambiando destinazione non lascia in giro le decisioni della scelta scartata', function () {
    // ⚠️ **Il residuo non era inerte.** Un `salta` firmato su un condominio poi scartato
    // **zittisce** la domanda «in archivio esiste già, unisci o lascia com'è?» se più avanti un
    // file dichiara proprio quello. Misurato affiancando due lotti identici: quello pulito poneva
    // la domanda, quello con il residuo la saltava e scriveva nel condominio sbagliato senza un
    // solo rilievo.
    $lotto = ImportBatch::create(['sorgente' => 'danea']);
    $primo = condominioEsistente('PRIMO', '97000000001');
    $secondo = condominioEsistente('SECONDO', '97000000002');
    $utente = utenteCheImporta();

    $this->actingAs($utente)->put(route('import.destinazione', $lotto->uuid), ['condominio_id' => $primo->id]);
    $this->actingAs($utente)->put(route('import.destinazione', $lotto->uuid), ['condominio_id' => $secondo->id]);

    $decisioni = $lotto->fresh()->decisioni;

    expect($decisioni[ImportVerificaService::DESTINAZIONE_CONDOMINIO])->toBe($secondo->id)
        ->and(array_keys($decisioni))->not->toContain('condominio:#'.$primo->id);
});

it('non tocca le decisioni prese sui file, che restano valide', function () {
    // Cambiare il condominio di arrivo non rende sbagliata la risposta a «questo nome ne contiene
    // due»: quella riguarda il file, non la destinazione.
    $lotto = ImportBatch::create([
        'sorgente' => 'danea',
        'decisioni' => ['soggetto:rossi mario / bianchi anna' => 'dividi'],
    ]);
    $c = condominioEsistente();

    $this->actingAs(utenteCheImporta())
        ->put(route('import.destinazione', $lotto->uuid), ['condominio_id' => $c->id]);

    expect($lotto->fresh()->decisioni['soggetto:rossi mario / bianchi anna'])->toBe('dividi');
});

it('a chi non può modificare i condomìni non mostra nemmeno l\'elenco', function () {
    // ⚠️ La tendina mandava a **tutti** nome e codice fiscale di ogni condominio dell'archivio,
    // anche a un ruolo a cui l'elenco condomìni è chiuso: bastava caricare un file qualsiasi per
    // avere l'anagrafe completa degli stabili gestiti. E chi la usava senza il permesso di
    // modifica, dopo aver scelto, sbatteva in un 403 a schermo pieno.
    $lotto = ImportBatch::create(['sorgente' => 'danea']);
    condominioEsistente();

    $this->actingAs(utenteCheImporta(puoModificare: false))
        ->get(route('import.verifica', $lotto->uuid))
        ->assertInertia(fn ($p) => $p
            ->where('destinazione.senza_permesso', true)
            ->where('destinazione.condomini', [])
        );
});

it('la conferma ricontrolla il permesso, che fra la scelta e la scrittura può essere revocato', function () {
    // Il Gate di `destinazione()` autorizza il momento in cui la scelta viene **registrata**.
    // La scrittura vera avviene alla conferma, magari giorni dopo: senza un secondo controllo,
    // l'importazione dentro un condominio esistente partiva lo stesso «perché era già decisa».
    $lotto = ImportBatch::create(['sorgente' => 'danea']);
    $c = condominioEsistente();
    $utente = utenteCheImporta();

    $this->actingAs($utente)->put(route('import.destinazione', $lotto->uuid), ['condominio_id' => $c->id]);

    $utente->revokePermissionTo(App\Enums\Permission::EDIT_CONDOMINI->value);
    $utente->forgetCachedPermissions();

    $this->actingAs($utente->fresh())
        ->post(route('import.conferma', $lotto->uuid))
        ->assertForbidden();
});

it('rifiuta un condominio che non esiste', function () {
    $lotto = ImportBatch::create(['sorgente' => 'danea']);

    $this->actingAs(utenteCheImporta())
        ->put(route('import.destinazione', $lotto->uuid), ['condominio_id' => 999999])
        ->assertSessionHasErrors('condominio_id');
});
