<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Commenti alle comunicazioni: le migrazioni, l'impostazione e i valori di partenza (Coda 559, decisione 261.7)
 *
 * Su MySQL il DDL non è transazionale: un aggiornamento interrotto lascia le colonne scritte e la migrazione non
 * registrata, e al giro dopo la migrazione riparte da capo. Ogni migrazione, rieseguita, non cambia niente; quella della
 * chiave esterna interrotta fra la colonna e la chiave si riprende e mette una chiave sola. Le comunicazioni che ci sono
 * già hanno i commenti spenti: nessuna discussione si apre da sola. I nomi dei file sono fissati dal progetto: all'ingresso
 * nel core le righe entrano nel dataset di `UpgradeMigrationsRerunTest`.
 */

use App\Models\Comunicazione;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/CommentiScenario.php';

const CCM_MIGRAZIONI = [
    '2026_11_10_100000_add_commenti_moderati_to_comunicazioni',
    '2026_11_10_100100_add_discussione_user_id_to_commenti',
    '2026_11_10_100200_spegni_commenti_comunicazioni_esistenti',
    '2026_11_10_100400_seed_preferenze_notifica_commenti_comunicazioni',
];

function ccmFotografiaSchema(): array
{
    return collect(Schema::getTableListing())->sort()->values()->mapWithKeys(fn ($t) => [$t => [
        'colonne' => collect(Schema::getColumnListing($t))->sort()->values()->all(),
        'indici' => collect(Schema::getIndexes($t))->pluck('name')->sort()->values()->all(),
        'chiavi' => collect(Schema::getForeignKeys($t))->map(fn ($k) => implode(',', $k['columns']) . '>' . $k['foreign_table'])->sort()->values()->all(),
    ]])->all();
}

function ccmChiaviDiscussione(): \Illuminate\Support\Collection
{
    return collect(Schema::getForeignKeys('commenti'))->filter(fn ($k) => $k['columns'] === ['discussione_user_id']);
}

test('le colonne nuove ci sono: la moderazione delle comunicazioni e la persona della discussione', function () {
    expect(Schema::hasColumn('comunicazioni', 'commenti_moderati'))->toBeTrue('Manca comunicazioni.commenti_moderati')
        ->and(Schema::hasColumn('commenti', 'discussione_user_id'))->toBeTrue('Manca commenti.discussione_user_id');

    $chiavi = ccmChiaviDiscussione();
    expect($chiavi)->toHaveCount(1)
        ->and($chiavi->first()['foreign_table'])->toBe('users')
        ->and(strtolower((string) $chiavi->first()['on_delete']))->toBe('cascade');
});

test('ogni migrazione dei commenti, rieseguita dopo un\'interruzione, non cambia lo schema', function (string $file) {
    $path = database_path("migrations/{$file}.php");
    expect(file_exists($path))->toBeTrue("Migrazione non trovata: {$file}");
    $migrazione = require $path;

    $prima = ccmFotografiaSchema();
    $migrazione->up();
    expect(ccmFotografiaSchema())->toBe($prima);
})->with(CCM_MIGRAZIONI);

test('la migrazione della persona della discussione interrotta fra la colonna e la chiave esterna si riprende e mette una chiave sola', function () {
    $migrazione = require database_path('migrations/2026_11_10_100100_add_discussione_user_id_to_commenti.php');

    Schema::table('commenti', fn ($t) => $t->dropForeign(['discussione_user_id']));
    expect(ccmChiaviDiscussione())->toHaveCount(0);

    $migrazione->up();
    $migrazione->up();

    expect(ccmChiaviDiscussione())->toHaveCount(1);
});

test('le migrazioni si tolgono e si rimettono', function () {
    foreach (array_reverse(CCM_MIGRAZIONI) as $file) {
        (require database_path("migrations/{$file}.php"))->down();
    }
    expect(Schema::hasColumn('comunicazioni', 'commenti_moderati'))->toBeFalse()
        ->and(Schema::hasColumn('commenti', 'discussione_user_id'))->toBeFalse();

    foreach (CCM_MIGRAZIONI as $file) {
        (require database_path("migrations/{$file}.php"))->up();
    }
    expect(Schema::hasColumn('comunicazioni', 'commenti_moderati'))->toBeTrue()
        ->and(Schema::hasColumn('commenti', 'discussione_user_id'))->toBeTrue();
});

test('le comunicazioni che esistono già hanno i commenti spenti, e la migrazione non tocca nient\'altro', function () {
    $autore = User::factory()->create();
    $accesa = DB::table('comunicazioni')->insertGetId([
        'subject' => 'Vecchia', 'slug' => 'vecchia', 'description' => 'x', 'priority' => 'media', 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => true, 'can_comment' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $spenta = DB::table('comunicazioni')->insertGetId([
        'subject' => 'Vecchia spenta', 'slug' => 'vecchia-spenta', 'description' => 'x', 'priority' => 'alta', 'created_by' => $autore->id,
        'is_private' => true, 'is_published' => false, 'is_approved' => false, 'is_featured' => false, 'can_comment' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $prima = DB::table('comunicazioni')->whereIn('id', [$accesa, $spenta])->orderBy('id')->get()
        ->map(fn ($r) => collect((array) $r)->except(['can_comment', 'updated_at'])->all())->all();

    $migrazione = require database_path('migrations/2026_11_10_100200_spegni_commenti_comunicazioni_esistenti.php');
    $migrazione->up();
    $migrazione->up();

    expect(DB::table('comunicazioni')->whereIn('id', [$accesa, $spenta])->pluck('can_comment')->map(fn ($v) => (bool) $v)->all())
        ->toBe([false, false]);
    $dopo = DB::table('comunicazioni')->whereIn('id', [$accesa, $spenta])->orderBy('id')->get()
        ->map(fn ($r) => collect((array) $r)->except(['can_comment', 'updated_at'])->all())->all();
    expect($dopo)->toBe($prima, 'La migrazione ha cambiato altre colonne oltre a «Consenti commenti»');
});

test('l\'impostazione generale nasce accesa, e la sua migrazione rieseguita non tocca un valore già scritto', function () {
    $path = database_path('settings/2026_11_10_100300_add_commenti_comunicazioni_moderati_di_serie_to_general_settings.php');
    expect(file_exists($path))->toBeTrue('Manca la migrazione dell\'impostazione');
    $riga = fn () => DB::table('settings')->where('group', 'general')->where('name', 'commenti_comunicazioni_moderati_di_serie');

    expect($riga()->count())->toBe(1)
        ->and(app(\App\Settings\GeneralSettings::class)->commenti_comunicazioni_moderati_di_serie)->toBeTrue();

    $riga()->update(['payload' => json_encode(false)]);
    (require $path)->up();
    expect($riga()->count())->toBe(1)
        ->and($riga()->value('payload'))->toBe(json_encode(false));
});

test('una comunicazione nuova nasce con i commenti spenti, se chi la crea non li accende, e con la moderazione dell\'impostazione generale', function () {
    $autore = User::factory()->create();
    $base = ['subject' => 'Nuova', 'description' => 'x', 'priority' => 'media', 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false];

    $senza = Comunicazione::create($base)->fresh();
    expect((bool) $senza->can_comment)->toBeFalse('Una comunicazione creata senza scegliere i commenti li ha accesi')
        ->and((bool) $senza->commenti_moderati)->toBeTrue();

    $accesa = Comunicazione::create($base + ['can_comment' => true])->fresh();
    expect((bool) $accesa->can_comment)->toBeTrue();

    $impostazioni = app(\App\Settings\GeneralSettings::class);
    $impostazioni->commenti_comunicazioni_moderati_di_serie = false;
    $impostazioni->save();
    expect((bool) Comunicazione::create($base)->fresh()->commenti_moderati)->toBeFalse();
});

test('le quattro preferenze dei commenti delle comunicazioni nascono come quelle dei commenti di oggi, e la ripresa non le tocca', function () {
    $coppie = [
        'new_communication_comment' => 'new_comment',
        'communication_comment_under_moderation' => 'comment_under_moderation',
        'communication_comment_approved' => 'comment_approved',
        'communication_comment_deleted' => 'comment_deleted',
    ];
    $path = database_path('migrations/2026_11_10_100400_seed_preferenze_notifica_commenti_comunicazioni.php');
    expect(file_exists($path))->toBeTrue('Manca la migrazione delle preferenze');
    foreach (array_keys($coppie) as $nuova) {
        expect(\App\Enums\NotificationType::tryFrom($nuova))->not->toBeNull("Manca il tipo di notifica «{$nuova}»");
    }

    $acceso = User::factory()->create();
    $spento = User::factory()->create();
    DB::table('notification_preferences')->whereIn('user_id', [$acceso->id, $spento->id])->delete();
    foreach ($coppie as $sorella) {
        DB::table('notification_preferences')->insert([
            ['user_id' => $acceso->id, 'type' => $sorella, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $spento->id, 'type' => $sorella, 'enabled' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
    $stato = fn (User $u, string $tipo) => DB::table('notification_preferences')->where('user_id', $u->id)->where('type', $tipo)->pluck('enabled')->map(fn ($v) => (bool) $v)->all();

    $migrazione = require $path;
    $migrazione->up();
    $migrazione->up();
    foreach (array_keys($coppie) as $nuova) {
        expect($stato($acceso, $nuova))->toBe([true], "«{$nuova}» di chi aveva acceso la sorella")
            ->and($stato($spento, $nuova))->toBe([false], "«{$nuova}» di chi aveva spento la sorella");
    }

    // Una scelta già fatta sulla preferenza nuova non si tocca.
    DB::table('notification_preferences')->where('user_id', $spento->id)->where('type', 'new_communication_comment')->update(['enabled' => true]);
    $migrazione->up();
    expect($stato($spento, 'new_communication_comment'))->toBe([true]);
});
