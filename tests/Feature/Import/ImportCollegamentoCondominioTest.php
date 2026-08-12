<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\Canonical\CanonicalSoggetto;
use App\Services\Import\ImportContext;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloSoggetti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * beta.49 — Le persone importate risultano **di quel condominio**.
 *
 * ## Il difetto, e perché era un blocco di rilascio della 1.10
 *
 * L'importatore popolava `anagrafica_immobile` e **mai** `anagrafica_condominio`. Il pivot è
 * quello che il gestionale legge per sapere chi appartiene a uno stabile, e in particolare è
 * quello che `StoreIncassoRateRequest` chiedeva per validare il pagante.
 *
 * Conseguenza: un amministratore importava il suo condominio — persone, unità, millesimi, saldi
 * quadrati al centesimo — e poi **non poteva registrare un solo incasso**. Misurato l'11/08/2026
 * su «Le Terrazze», entrato con la beta.47: 16 anagrafiche con unità, **0** collegate.
 *
 * La validazione è stata allargata nella stessa beta (accetta anche chi possiede un'unità qui,
 * vedi `PaganteDelCondominioTest`), il che sblocca da solo anche i condomìni **già** importati
 * senza toccarne i dati. Questa metà resta necessaria per due ragioni: il pivot lo leggono anche
 * altre parti del gestionale, e il dato va scritto **dove nasce il fatto**.
 *
 * ## Cosa questo file NON copre
 *
 * - Il percorso manuale (`ImmobileAnagraficaController`), che ha la stessa lacuna e la sua
 *   correzione a parte.
 * - I condomìni creati da **script** di prova, che restano scollegati: è un problema dei dati
 *   di prova, annotato in `flusso_di_lavoro_rilascio.md`.
 */
function contestoSoggetti(array $soggetti, ?Condominio $condominio = null): ImportContext
{
    $condominio ??= Condominio::factory()->create(['nome' => 'Condominio Importato']);

    return (new ImportContext(ImportBatch::create(['sorgente' => 'danea'])))
        ->conCanonico(LivelloSoggetti::CHIAVE, $soggetti)
        ->risolvi(LivelloCondominio::CHIAVE, $condominio);
}

function soggetto(string $nome, ?string $cf = null): CanonicalSoggetto
{
    return new CanonicalSoggetto(
        nome: $nome,
        codiceFiscale: $cf,
        indirizzo: 'Via Roma 1',
    );
}

test('una persona creata dall\'import risulta del condominio importato', function () {
    $condominio = Condominio::factory()->create(['nome' => 'Le Terrazze']);
    $ctx = contestoSoggetti(['k1' => soggetto('DAL PONTE GIOVANNI', 'DLPGNN70A01H501U')], $condominio);

    (new LivelloSoggetti)->commit($ctx);

    $anagrafica = Anagrafica::where('nome', 'DAL PONTE GIOVANNI')->firstOrFail();

    expect(DB::table('anagrafica_condominio')
        ->where('anagrafica_id', $anagrafica->id)
        ->where('condominio_id', $condominio->id)
        ->exists())->toBeTrue();
});

test('anche chi si sceglie di LASCIARE COM\'È viene collegato', function () {
    // È il ramo che si dimentica. La decisione «salta» riguarda i **dati** della persona
    // («lascialo com'è»), non la sua appartenenza allo stabile: le unità gli vengono collegate
    // lo stesso — lo dice il testo del rilievo — quindi senza il pivot otterrebbe un
    // proprietario che non può pagare.
    $condominio = Condominio::factory()->create();
    $esistente = Anagrafica::factory()->create(['codice_fiscale' => 'RSSMRA80A01H501U']);

    $ctx = contestoSoggetti(['k1' => soggetto('ROSSI MARIO', 'RSSMRA80A01H501U')], $condominio)
        ->conDecisioni([LivelloSoggetti::CHIAVE.':k1' => 'salta']);

    (new LivelloSoggetti)->commit($ctx);

    expect(DB::table('anagrafica_condominio')
        ->where('anagrafica_id', $esistente->id)
        ->where('condominio_id', $condominio->id)
        ->exists())->toBeTrue();
});

test('chi possiede già unità altrove non perde i suoi condomìni', function () {
    // La persona è unica e può possedere in più stabili — è la premessa dichiarata del livello.
    // `syncWithoutDetaching` e non `sync`: importare il secondo condominio non deve staccarla
    // dal primo.
    $primo   = Condominio::factory()->create(['nome' => 'Il primo']);
    $secondo = Condominio::factory()->create(['nome' => 'Il secondo']);

    $esistente = Anagrafica::factory()->create(['codice_fiscale' => 'VRDNNA80A41H501K']);
    $esistente->condomini()->attach($primo->id);

    $ctx = contestoSoggetti(['k1' => soggetto('VERDI ANNA', 'VRDNNA80A41H501K')], $secondo)
        ->conDecisioni([LivelloSoggetti::CHIAVE.':k1' => 'unisci']);

    (new LivelloSoggetti)->commit($ctx);

    expect($esistente->fresh()->condomini->pluck('id')->sort()->values()->all())
        ->toBe(collect([$primo->id, $secondo->id])->sort()->values()->all());
});

test('un secondo import dello stesso condominio non crea righe doppie', function () {
    $condominio = Condominio::factory()->create();
    $soggetti = ['k1' => soggetto('BIANCHI LUCA', 'BNCLCU80A01H501U')];

    (new LivelloSoggetti)->commit(contestoSoggetti($soggetti, $condominio));

    $anagrafica = Anagrafica::where('nome', 'BIANCHI LUCA')->firstOrFail();

    (new LivelloSoggetti)->commit(
        contestoSoggetti($soggetti, $condominio)
            ->conDecisioni([LivelloSoggetti::CHIAVE.':k1' => 'unisci'])
    );

    expect(DB::table('anagrafica_condominio')
        ->where('anagrafica_id', $anagrafica->id)
        ->where('condominio_id', $condominio->id)
        ->count())->toBe(1);
});
