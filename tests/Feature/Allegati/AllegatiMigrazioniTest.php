<?php

/**
 * # Allegati: le migrazioni si riprendono dopo un'interruzione (regola del progetto, come
 * `tests/Feature/System/UpgradeMigrationsRerunTest.php`)
 *
 * Su MySQL il DDL non è transazionale: un aggiornamento interrotto lascia le colonne scritte e la migrazione non
 * registrata, e al giro dopo la migrazione riparte da capo. Ogni migrazione degli allegati, rieseguita, non cambia
 * niente; quella della chiave esterna interrotta fra la colonna e la chiave si riprende e mette una chiave sola. I
 * nomi dei file sono fissati dal progetto: all'ingresso nel core le righe entrano nel dataset di
 * `UpgradeMigrationsRerunTest`.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ALG_MIGRAZIONI = [
    '2026_11_08_200000_add_allegati_to_segnalazioni_e_comunicazioni',
    '2026_11_08_200100_add_moderazione_to_documenti',
    '2026_11_08_200300_seed_preferenze_notifica_allegati',
];

function algFotografiaSchema(): array
{
    $tabelle = collect(Schema::getTableListing())->sort()->values()->all();

    return collect($tabelle)->mapWithKeys(fn ($t) => [$t => [
        'colonne' => collect(Schema::getColumnListing($t))->sort()->values()->all(),
        'indici' => collect(Schema::getIndexes($t))->pluck('name')->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys($t))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'])->sort()->values()->all(),
    ]])->all();
}

test('le colonne nuove ci sono, con i valori di partenza', function () {
    foreach (['segnalazioni', 'comunicazioni'] as $tabella) {
        expect(Schema::hasColumn($tabella, 'allegati_accesso'))->toBeTrue("Manca {$tabella}.allegati_accesso")
            ->and(Schema::hasColumn($tabella, 'allegati_moderati'))->toBeTrue("Manca {$tabella}.allegati_moderati");
    }
    expect(Schema::hasColumn('documenti', 'moderato_da'))->toBeTrue()
        ->and(Schema::hasColumn('documenti', 'moderato_at'))->toBeTrue();

    $chiavi = collect(Schema::getForeignKeys('documenti'))->filter(fn ($k) => $k['columns'] === ['moderato_da']);
    expect($chiavi)->toHaveCount(1)
        ->and($chiavi->first()['foreign_table'])->toBe('users')
        ->and(strtolower((string) $chiavi->first()['on_delete']))->toBe('set null');
});

test('ogni migrazione degli allegati, rieseguita dopo un\'interruzione, non cambia lo schema', function (string $file) {
    $path = database_path("migrations/{$file}.php");
    expect(file_exists($path))->toBeTrue("Migrazione non trovata: {$file}");
    $migrazione = require $path;

    $prima = algFotografiaSchema();
    $migrazione->up();

    expect(algFotografiaSchema())->toBe($prima);
})->with(ALG_MIGRAZIONI);

test('la migrazione della moderazione interrotta fra la colonna e la chiave esterna si riprende e mette una chiave sola', function () {
    $migrazione = require database_path('migrations/2026_11_08_200100_add_moderazione_to_documenti.php');
    Schema::table('documenti', fn ($t) => $t->dropForeign(['moderato_da']));
    expect(collect(Schema::getForeignKeys('documenti'))->filter(fn ($k) => $k['columns'] === ['moderato_da']))->toHaveCount(0);

    $migrazione->up();
    $migrazione->up();

    expect(collect(Schema::getForeignKeys('documenti'))->filter(fn ($k) => $k['columns'] === ['moderato_da']))->toHaveCount(1);
});

test('le migrazioni si tolgono e si rimettono', function () {
    foreach (array_reverse(ALG_MIGRAZIONI) as $file) {
        (require database_path("migrations/{$file}.php"))->down();
    }
    expect(Schema::hasColumn('segnalazioni', 'allegati_accesso'))->toBeFalse()
        ->and(Schema::hasColumn('documenti', 'moderato_da'))->toBeFalse();

    foreach (ALG_MIGRAZIONI as $file) {
        (require database_path("migrations/{$file}.php"))->up();
    }
    expect(Schema::hasColumn('segnalazioni', 'allegati_accesso'))->toBeTrue()
        ->and(Schema::hasColumn('documenti', 'moderato_da'))->toBeTrue();
});

test('le righe che esistono già prendono gli allegati aperti e in moderazione', function () {
    $user = \App\Models\User::factory()->create();
    $condominio = \App\Models\Condominio::factory()->create();
    $id = DB::table('segnalazioni')->insertGetId([
        'subject' => 'Vecchia', 'description' => 'x', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $condominio->id, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $riga = DB::table('segnalazioni')->find($id);
    expect($riga->allegati_accesso)->toBe('aperti')
        ->and((bool) $riga->allegati_moderati)->toBeTrue();
});

test('l\'impostazione generale nasce accesa e la sua migrazione si riprende', function () {
    $path = database_path('settings/2026_11_08_200200_add_allegati_moderati_di_serie_to_general_settings.php');
    expect(file_exists($path))->toBeTrue('Manca la migrazione dell\'impostazione');
    (require $path)->up();

    expect(DB::table('settings')->where('group', 'general')->where('name', 'allegati_moderati_di_serie')->count())->toBe(1)
        ->and(app(\App\Settings\GeneralSettings::class)->allegati_moderati_di_serie)->toBeTrue();
});
