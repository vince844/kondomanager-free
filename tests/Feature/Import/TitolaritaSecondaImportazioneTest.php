<?php

/**
 * La seconda importazione non deve raddoppiare le titolarità.
 *
 * ## Il difetto, accertato sul database condiviso il 15/08/2026
 *
 * `LivelloTitolarita` guarda la tripla `(immobile, anagrafica, ruolo)` e impedisce di riscrivere la
 * **stessa** riga: fa il suo lavoro. Ma alla seconda importazione l'anagrafica è spesso **diversa**
 * — la coppia come soggetto unico e i due coniugi separati sono tre `anagrafica_id` distinti —
 * quindi nessuna tripla collide, tutte le righe entrano, e sull'unità la somma delle quote diventa
 * 200. Sul condominio 33 è successo su due unità su undici.
 *
 * Reimportare un file corretto è **il** gesto normale di una migrazione, non un caso limite.
 *
 * ## La strada scelta (roadmap, coda ㉔, opzione b)
 *
 * L'importazione **non chiede niente e non cancella niente**: salta le unità in conflitto, le
 * elenca nel rapporto e le lascia all'amministratore. La strada con la scelta *sostituisci/salta*
 * è stata scritta nella beta.52 e ritirata dalla revisione con quattro reperti «alta» — il primo
 * era che la decisione richiesta non era raggiungibile da nessuna schermata.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la sostituzione (non esiste), né il caso di due file diversi importati insieme nello
 * stesso lotto: qui il conflitto è sempre fra il file e ciò che è **già in archivio**.
 */

use App\Enums\RuoloAnagraficaImmobile;
use App\Enums\RuoloAnagraficaImmobile as ImportRuolo;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Esegue il livello con un file che porta i soggetti indicati come proprietari dell'unità.
 *
 * @param  list<Anagrafica>  $dalFile
 */
function eseguiLivelloTitolarita(Condominio $condominio, Immobile $immobile, array $dalFile): EsitoCommit
{
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id]);

    $ctx = new ImportContext(ImportBatch::create(['sorgente' => 'danea']));
    $ctx->risolvi(LivelloEsercizi::CHIAVE, $esercizio);
    $ctx->risolviMolti(LivelloUnita::CHIAVE, ['u1' => $immobile]);
    $ctx->risolviMolti(
        LivelloSoggetti::CHIAVE,
        collect($dalFile)->mapWithKeys(fn ($a, $i) => ['sog'.$i => $a])->all(),
    );
    $ctx->conCanonico(LivelloTitolarita::CHIAVE, collect($dalFile)->map(fn ($a, $i) => new CanonicalTitolarita(
        immobileRef: 'u1',
        soggettoRef: 'sog'.$i,
        ruolo: ImportRuolo::PROPRIETARIO,
    ))->all());

    return (new LivelloTitolarita)->commit($ctx);
}

/** `Immobile` non ha una factory: si crea come negli altri test dell'importatore. */
function unitaDiProva(Condominio $condominio, string $interno = '1'): Immobile
{
    return Immobile::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Unità '.$interno,
        'descrizione' => 'Unità '.$interno,
        'interno' => $interno,
    ]);
}

/** Una riga di titolarità già in archivio, come l'avrebbe scritta la prima importazione. */
function titolaritaInArchivio(Immobile $immobile, Anagrafica $anagrafica, float $quota = 100.00): void
{
    DB::table('anagrafica_immobile')->insert([
        'immobile_id' => $immobile->getKey(),
        'anagrafica_id' => $anagrafica->getKey(),
        'tipologia' => RuoloAnagraficaImmobile::PROPRIETARIO->value,
        'quota' => $quota,
        'data_inizio' => now()->startOfYear(),
        'attivo' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('non aggiunge titolari a un\'unità che ne ha già al cento per cento', function () {
    $condominio = Condominio::factory()->create();
    $immobile = unitaDiProva($condominio);

    $giaPresente = Anagrafica::factory()->create(['nome' => 'D\'Amico e Sala']);
    titolaritaInArchivio($immobile, $giaPresente);

    // Il file della seconda importazione porta i due coniugi separati: anagrafiche diverse,
    // quindi nessuna tripla collide.
    $primo = Anagrafica::factory()->create(['nome' => 'D\'Amico']);
    $secondo = Anagrafica::factory()->create(['nome' => 'Sala']);

    $esito = eseguiLivelloTitolarita($condominio, $immobile, [$primo, $secondo]);

    $somma = DB::table('anagrafica_immobile')
        ->where('immobile_id', $immobile->getKey())
        ->where('attivo', true)
        ->sum('quota');

    expect((float) $somma)->toBe(100.00)
        ->and($esito->avvisi)->not->toBeEmpty();
});

it('l\'avviso nomina l\'unità saltata, così l\'amministratore sa dove guardare', function () {
    $condominio = Condominio::factory()->create();
    $immobile = unitaDiProva($condominio, '4B');

    titolaritaInArchivio($immobile, Anagrafica::factory()->create());

    $esito = eseguiLivelloTitolarita($condominio, $immobile, [Anagrafica::factory()->create()]);

    $testo = collect($esito->avvisi)->map(fn ($a) => $a->messaggio.' '.$a->rimedio)->implode(' ');

    // Il ruolo sta nell'etichetta perché il file porta anche gli inquilini: senza, l'avviso
    // mandava a guardare i proprietari quando a non concordare era il conduttore.
    expect($testo)->toContain('4B')
        ->and($testo)->toContain('proprietario');
});

it('su un\'unità libera scrive normalmente', function () {
    // Controprova: la guardia non deve fermare la **prima** importazione, che è il caso normale.
    $condominio = Condominio::factory()->create();
    $immobile = unitaDiProva($condominio);

    $esito = eseguiLivelloTitolarita($condominio, $immobile, [Anagrafica::factory()->create()]);

    expect($esito->creati)->toBe(1)
        ->and(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->getKey())->count())->toBe(1);
});

it('una titolarità cessata non blocca la riscrittura, e non passa in silenzio', function () {
    // ⚠️ Reperto della revisione della beta.56, ed è il **quarto reperto** che affossò la prima
    // versione di questa correzione, ricomparso dall'altro lato: la guardia sulla tripla non
    // filtrava `attivo`, la lettura sì. Con una riga spenta per la stessa terna, il file non
    // veniva scritto («esiste già») e nessun avviso lo diceva: l'unità restava senza titolare
    // attivo e nessuno se ne accorgeva.
    $condominio = Condominio::factory()->create();
    $immobile = unitaDiProva($condominio);

    $cessato = Anagrafica::factory()->create();
    titolaritaInArchivio($immobile, $cessato);
    DB::table('anagrafica_immobile')
        ->where('immobile_id', $immobile->getKey())
        ->update(['attivo' => false, 'data_fine' => now()->subDay()]);

    $esito = eseguiLivelloTitolarita($condominio, $immobile, [$cessato]);

    $attive = DB::table('anagrafica_immobile')
        ->where('immobile_id', $immobile->getKey())
        ->where('attivo', true)
        ->count();

    expect($attive)->toBe(1)
        ->and($esito->creati)->toBe(1);
});

it('la stessa persona già presente resta saltata senza allarmi', function () {
    // Era già così e deve restare: reimportare lo stesso file identico non è un conflitto,
    // è un no-op. L'avviso nuovo non deve trasformarlo in un caso da guardare.
    $condominio = Condominio::factory()->create();
    $immobile = unitaDiProva($condominio);

    $stessa = Anagrafica::factory()->create();
    titolaritaInArchivio($immobile, $stessa);

    $esito = eseguiLivelloTitolarita($condominio, $immobile, [$stessa]);

    expect($esito->saltati)->toBe(1)
        ->and($esito->avvisi)->toBeEmpty();
});
