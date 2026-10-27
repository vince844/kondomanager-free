<?php

/**
 * # Le frasi accanto alla tendina «Pubblicata» e alla casella «avvisa i destinatari» (Code 306 e 198)
 *
 * La modifica non approva (decisione 127.2): su un oggetto in moderazione scegliere «Pubblicata» e
 * spuntare «avvisa» non lo mostra a nessuno e non fa partire niente. Le frasi accanto ai due campi
 * dicono quindi «pubblicata **e approvata**», per segnalazioni, comunicazioni e documenti, in ogni
 * lingua; e la riga sotto la tendina, che la pagina mostra su un oggetto non approvato, esiste in ogni
 * lingua. La pagina la mostra leggendo `is_approved`, che la scheda di modifica deve ricevere.
 *
 * Lo stesso controllo per le frasi nuove di «Rendi pubblica» e dei residenti di una privata (Coda 304).
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/** La radice della parola «approvata» in ogni lingua del gestionale. */
function frasiRadiceApprovazione(): array
{
    return ['it' => 'approvat', 'en' => 'approved', 'es' => 'aprobad', 'pt' => 'aprovad'];
}

test('Coda 306 — le frasi della tendina «Pubblicata» e della casella «avvisa» dicono che serve anche l\'approvazione, in ogni lingua', function () {
    foreach (frasiRadiceApprovazione() as $lingua => $radice) {
        foreach (['segnalazioni', 'comunicazioni', 'documenti'] as $entita) {
            foreach (['tooltip.visibility', 'tooltip.notify_update'] as $chiave) {
                $frase = trans("{$entita}.{$chiave}", [], $lingua);

                expect($frase)->not->toBe("{$entita}.{$chiave}", "{$lingua}: manca {$entita}.{$chiave}")
                    ->and(str_contains(mb_strtolower($frase), $radice))->toBeTrue("{$lingua}: {$entita}.{$chiave} non parla dell'approvazione: {$frase}");
            }
        }
    }
});

test('Coda 306 — la riga sotto la tendina su un oggetto non approvato esiste in ogni lingua, per le tre entità', function () {
    foreach (array_keys(frasiRadiceApprovazione()) as $lingua) {
        foreach (['segnalazioni', 'comunicazioni', 'documenti'] as $entita) {
            $chiave = "{$entita}.publication.needs_approval";
            $frase = trans($chiave, [], $lingua);

            expect($frase)->not->toBe($chiave, "{$lingua}: manca {$chiave}")
                ->and(str_contains(mb_strtolower($frase), frasiRadiceApprovazione()[$lingua]))->toBeTrue("{$lingua}: {$chiave} non parla dell'approvazione: {$frase}");
        }
    }
});

test('Coda 304 — le frasi di «Rendi pubblica» su una privata non ancora visibile e dei residenti esistono in ogni lingua', function () {
    $chiavi = [
        'segnalazioni.privacy.make_public_description_pending',
        'segnalazioni.privacy.success_make_public_pending',
        'segnalazioni.privacy.residents_required_ask_approver',
        'notifications.ticket_made_public.subject_pending',
        'notifications.ticket_made_public.line_2_pending',
    ];

    foreach (array_keys(frasiRadiceApprovazione()) as $lingua) {
        foreach ($chiavi as $chiave) {
            expect(trans($chiave, [], $lingua))->not->toBe($chiave, "{$lingua}: manca {$chiave}");
        }
        // I segnaposto della conferma sono quelli che la pagina riempie.
        $conferma = trans('segnalazioni.privacy.make_public_description_pending', [], $lingua);
        expect($conferma)->toContain(':condominio')->toContain(':autore');
        expect(trans('notifications.ticket_made_public.line_2_pending', [], $lingua))->toContain(':condominio');
    }
});

test('Coda 306 — le schede di modifica del pannello ricevono is_approved, che decide la riga sotto la tendina', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $condominio = Condominio::factory()->create();
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(Role::AMMINISTRATORE->value);

    $s = Segnalazione::create([
        'subject' => 'Cancello', 'description' => 'Non chiude', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $condominio->id, 'created_by' => $admin->id, 'is_private' => false,
        'is_published' => true, 'is_approved' => false, 'is_featured' => false, 'can_comment' => false,
    ]);
    $c = Comunicazione::create([
        'subject' => 'Assemblea', 'description' => 'Il 12', 'priority' => 'media', 'created_by' => $admin->id,
        'is_featured' => false, 'is_private' => false, 'is_published' => true, 'is_approved' => false, 'can_comment' => false,
    ]);
    $c->condomini()->attach($condominio->id);
    $d = Documento::create([
        'name' => 'Verbale', 'description' => 'x', 'created_by' => $admin->id, 'is_published' => true, 'is_approved' => false,
        'path' => 'documenti/prova.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
    ]);
    $d->condomini()->attach($condominio->id);

    foreach ([
        ['admin.segnalazioni.edit', $s, 'segnalazione'],
        ['admin.comunicazioni.edit', $c, 'comunicazione'],
        ['admin.documenti.edit', $d, 'documento'],
    ] as [$rotta, $oggetto, $prop]) {
        $p = $this->actingAs($admin)->get(route($rotta, $oggetto))->assertOk()->viewData('page')['props'][$prop];
        $p = $p['data'] ?? $p;

        expect(array_key_exists('is_approved', $p))->toBeTrue("{$rotta}: manca is_approved")
            ->and((bool) $p['is_approved'])->toBeFalse();
    }
});

test('Coda 306 — le tre schede di modifica, aperte su un oggetto in moderazione e inviate senza toccare niente, non lo approvano e non cambiano niente', function () {
    // I corpi sono quelli dei tre moduli (`SegnalazioniEdit.vue`, `ComunicazioniEdit.vue`, `DocumentiEdit.vue`),
    // costruiti dalle props ricevute; dati scomodi: «Pubblicata» scelta e non approvata, in evidenza,
    // testo con apostrofi e virgolette su più righe, due destinatari.
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $condominio = Condominio::factory()->create();
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(Role::AMMINISTRATORE->value);
    $anna = Anagrafica::factory()->create();
    $anna->condomini()->attach($condominio->id);
    $bruno = Anagrafica::factory()->create();
    $bruno->condomini()->attach($condominio->id);
    $categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);

    $s = Segnalazione::create([
        'subject' => "L'ascensore dell'ala «B»", 'description' => "Prima riga\nseconda riga", 'priority' => 'urgente',
        'stato' => 'in lavorazione', 'condominio_id' => $condominio->id, 'created_by' => $admin->id, 'is_private' => false,
        'is_published' => true, 'is_approved' => false, 'is_featured' => true, 'can_comment' => false,
    ]);
    $s->anagrafiche()->attach([$anna->id, $bruno->id]);
    $c = Comunicazione::create([
        'subject' => "Lavori sull'impianto — «urgenti»", 'description' => "Prima riga\nseconda riga", 'priority' => 'urgente',
        'created_by' => $admin->id, 'is_featured' => true, 'is_private' => false, 'is_published' => true, 'is_approved' => false,
        'can_comment' => false,
    ]);
    $c->condomini()->attach($condominio->id);
    $c->anagrafiche()->attach([$anna->id, $bruno->id]);
    $d = Documento::create([
        'name' => "Verbale dell'assemblea «straordinaria»", 'description' => "Prima riga\nseconda riga", 'created_by' => $admin->id,
        'is_published' => true, 'is_approved' => false, 'path' => 'documenti/prova.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
    ]);
    $d->condomini()->attach($condominio->id);
    $d->anagrafiche()->attach([$anna->id, $bruno->id]);
    $d->categorie()->attach($categoria->id);

    $campi = fn ($m) => $m->fresh()->only(['subject', 'name', 'description', 'priority', 'stato', 'is_published', 'is_approved', 'is_featured', 'can_comment', 'created_by']);
    $prima = [$campi($s), $campi($c), $campi($d)];
    $props = fn (string $rotta, $m, string $chiave) => (fn ($p) => $p['data'] ?? $p)($this->actingAs($admin)->get(route($rotta, $m))->assertOk()->viewData('page')['props'][$chiave]);

    $p = $props('admin.segnalazioni.edit', $s, 'segnalazione');
    $this->actingAs($admin)->put(route('admin.segnalazioni.update', $s), [
        'subject' => $p['subject'], 'description' => $p['description'], 'priority' => $p['priority'], 'stato' => $p['stato'],
        'condominio_id' => $p['condominio']['option']['value'], 'can_comment' => (bool) $p['can_comment'],
        'is_featured' => (bool) $p['is_featured'], 'is_published' => (bool) $p['is_published'],
        'anagrafiche' => collect($p['anagrafiche'])->pluck('id')->all(), 'avvisa_destinatari' => false,
    ])->assertSessionHasNoErrors();

    $p = $props('admin.comunicazioni.edit', $c, 'comunicazione');
    $this->actingAs($admin)->put(route('admin.comunicazioni.update', $c), [
        'subject' => $p['subject'], 'description' => $p['description'], 'priority' => $p['priority'], 'stato' => '',
        'condomini_ids' => collect($p['condomini']['options'])->pluck('value')->all(), 'can_comment' => (bool) $p['can_comment'],
        'is_featured' => (bool) $p['is_featured'], 'is_published' => (bool) $p['is_published'],
        'anagrafiche' => collect($p['anagrafiche'])->pluck('id')->all(), 'avvisa_destinatari' => false,
    ])->assertSessionHasNoErrors();

    $p = $props('admin.documenti.edit', $d, 'documento');
    $this->actingAs($admin)->put(route('admin.documenti.update', $d), [
        'name' => $p['name'], 'description' => $p['description'],
        'condomini_ids' => collect($p['condomini']['options'])->pluck('value')->all(), 'is_published' => (bool) $p['is_published'],
        'anagrafiche' => collect($p['anagrafiche'])->pluck('id')->all(), 'categorie' => collect($p['categorie'])->pluck('id')->all(),
        'file' => null, 'avvisa_destinatari' => false, 'anagrafiche_nel_modulo' => true,
    ])->assertSessionHasNoErrors();

    expect([$campi($s), $campi($c), $campi($d)])->toEqual($prima);
    foreach ([$s, $c, $d] as $m) {
        expect($m->fresh()->anagrafiche()->pluck('anagrafiche.id')->sort()->values()->all())->toBe(collect([$anna->id, $bruno->id])->sort()->values()->all());
    }
    Notification::assertNothingSent();
});


test('Coda 306 — la riga per chi non approva esiste in ogni lingua, dice chi approva e non parla dello scudo', function () {
    // Lo scudo dell'elenco lo vede solo chi ha il permesso di approvare: a chi non ce l'ha la riga
    // dice chi approva, senza indicargli un comando che non ha.
    $chiApprova = ['it' => 'chi approva', 'en' => 'whoever approves', 'es' => 'quien aprueba', 'pt' => 'quem aprova'];
    $scudo = ['it' => 'scudo', 'en' => 'shield', 'es' => 'escudo', 'pt' => 'escudo'];

    foreach ($chiApprova as $lingua => $parole) {
        foreach (['segnalazioni', 'comunicazioni', 'documenti'] as $entita) {
            $chiave = "{$entita}.publication.waiting_approver";
            $frase = trans($chiave, [], $lingua);

            expect($frase)->not->toBe($chiave, "{$lingua}: manca {$chiave}")
                ->and(str_contains(mb_strtolower($frase), $parole))->toBeTrue("{$lingua}: {$chiave} non dice chi approva: {$frase}")
                ->and(str_contains(mb_strtolower($frase), $scudo[$lingua]))->toBeFalse("{$lingua}: {$chiave} parla dello scudo: {$frase}");
        }
    }
});

test('Coda 304 — le frasi degli avvisi all\'autore che non apre la scheda, della conferma e della guida «Chi la vede» esistono in ogni lingua, con i segnaposto', function () {
    foreach (array_keys(frasiRadiceApprovazione()) as $lingua) {
        foreach ([
            'notifications.ticket_made_public.line_1_pending' => [':user'],
            'notifications.author_cannot_open.outside_building' => [':condominio'],
            'notifications.author_cannot_open.no_permission' => [],
            'segnalazioni.privacy.make_public_author_loses_access' => [':autore', ':condominio'],
            'segnalazioni.privacy.guide_private_pending' => [],
            'segnalazioni.privacy.guide_not_private_pending' => [],
        ] as $chiave => $segnaposto) {
            $frase = trans($chiave, [], $lingua);
            expect($frase)->not->toBe($chiave, "{$lingua}: manca {$chiave}");
            foreach ($segnaposto as $s) {
                expect($frase)->toContain($s);
            }
        }
    }
});
