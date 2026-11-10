<?php

/**
 * # Lo scenario dei test degli allegati di segnalazioni e comunicazioni (Coda 460)
 *
 * Due palazzi, Aurora e Borea, con i ruoli di serie (il seeder vero, come all'installazione e a ogni
 * aggiornamento), le preferenze di notifica tutte accese e i file caricati veri: il tipo lo legge `finfo`
 * dal contenuto, come in produzione. Il disco finto è quello che il codice usa davvero
 * (`ArchivioDocumenti::nomeDisco()`), non un nome scritto a mano.
 *
 * Le rotte, i permessi e le classi nuove si nominano per stringa: finché non esistono, il test fallisce sul
 * punto che manca, non su un errore di sintassi del file.
 */

use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const ALG_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
const ALG_HTML = "<!DOCTYPE html>\n<html><script>alert(1)</script></html>\n";
const ALG_SVG = "<svg xmlns=\"http://www.w3.org/2000/svg\"><script>alert(1)</script></svg>\n";

/** I permessi nuovi, con i nomi che il progetto fissa. */
const ALG_PERMESSI = [
    'segnalazioni' => [
        'carica' => 'Carica allegati segnalazioni',
        'pubblica' => 'Pubblica allegati segnalazioni',
        'approva' => 'Approva allegati segnalazioni',
        'modifica' => 'Modifica allegati segnalazioni',
        'modifica_propri' => 'Modifica propri allegati segnalazioni',
        'elimina' => 'Elimina allegati segnalazioni',
        'elimina_propri' => 'Elimina propri allegati segnalazioni',
        'visualizza' => 'Visualizza allegati segnalazioni',
    ],
    'comunicazioni' => [
        'carica' => 'Carica allegati comunicazioni',
        'pubblica' => 'Pubblica allegati comunicazioni',
        'approva' => 'Approva allegati comunicazioni',
        'modifica' => 'Modifica allegati comunicazioni',
        'modifica_propri' => 'Modifica propri allegati comunicazioni',
        'elimina' => 'Elimina allegati comunicazioni',
        'elimina_propri' => 'Elimina propri allegati comunicazioni',
        'visualizza' => 'Visualizza allegati comunicazioni',
    ],
];

/** Le classi delle email nuove. */
const ALG_MAIL_NUOVO = 'App\\Notifications\\Allegati\\NuovoAllegatoNotification';
const ALG_MAIL_IN_ATTESA = 'App\\Notifications\\Allegati\\AllegatoInAttesaNotification';
const ALG_MAIL_APPROVATO = 'App\\Notifications\\Allegati\\AllegatoApprovatoNotification';
const ALG_MAIL_ELIMINATO = 'App\\Notifications\\Allegati\\AllegatoEliminatoNotification';
const ALG_MAIL_COMUNICAZIONE = 'App\\Notifications\\Allegati\\AllegatiComunicazioneNotification';

/** I palazzi, l'amministratore e il disco: da chiamare nel `beforeEach`. */
function algPrepara($test): void
{
    $test->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());

    $test->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $test->borea = Condominio::factory()->create(['nome' => 'Condominio Borea']);
    $test->admin = algUtente([$test->aurora->id, $test->borea->id], 'Studio Bianchi', Role::AMMINISTRATORE->value);
}

/** Un utente con il suo ruolo, la sua anagrafica nei palazzi dati e tutte le preferenze di notifica accese. */
function algUtente(array $condominiIds, string $nome, string $ruolo = 'utente'): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'name' => $nome,
        'email' => Str::slug($nome, '.') . '.' . Str::lower(Str::random(5)) . '@esempio.invalid',
    ]);
    $user->assignRole($ruolo);
    foreach (NotificationType::cases() as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'type' => $tipo->value, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome]);
    $anagrafica->condomini()->attach($condominiIds);

    return $user->fresh();
}

/** Un ruolo su misura con i permessi dati (per nome). */
function algRuolo(string $nome, array $permessi): string
{
    \Spatie\Permission\Models\Role::create(['name' => $nome, 'guard_name' => 'web'])->givePermissionTo($permessi);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    return $nome;
}

function algSegnalazione(Condominio $condominio, User $autore, array $campi = [], array $collegate = []): Segnalazione
{
    $s = Segnalazione::create(array_merge([
        'subject' => 'Il cancello non chiude', 'description' => 'Da due giorni il cancello dell\'ingresso B resta aperto.',
        'priority' => 'media', 'stato' => 'aperta', 'condominio_id' => $condominio->id, 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $campi));
    if ($collegate !== []) {
        $s->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $s->fresh();
}

function algComunicazione(array $condomini, User $autore, array $campi = [], array $collegate = []): Comunicazione
{
    $c = Comunicazione::create(array_merge([
        'subject' => 'Lavori al tetto', 'description' => 'I lavori cominciano lunedì.',
        'priority' => 'media', 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => false,
    ], $campi));
    $c->condomini()->attach(array_map(fn (Condominio $k) => $k->id, $condomini));
    if ($collegate !== []) {
        $c->anagrafiche()->attach(array_map(fn (User $u) => $u->anagrafica->id, $collegate));
    }

    return $c->fresh();
}

/** I byte veri di una piccola immagine nel formato dato. */
function algBytesImmagine(string $formato): string
{
    $img = imagecreatetruecolor(8, 8);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
    ob_start();
    match ($formato) {
        'jpg' => imagejpeg($img),
        'png' => imagepng($img),
        'webp' => imagewebp($img),
        'gif' => imagegif($img),
    };

    return (string) ob_get_clean();
}

/** Un file caricato vero, con il nome e il contenuto dati. */
function algFile(string $nome, string $contenuto): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'alg');
    file_put_contents($tmp, $contenuto);

    return new UploadedFile($tmp, $nome, null, null, true);
}

function algFoto(string $nome = 'foto-cancello.jpg'): UploadedFile
{
    return algFile($nome, algBytesImmagine(strtolower(pathinfo($nome, PATHINFO_EXTENSION)) === 'jpeg' ? 'jpg' : strtolower(pathinfo($nome, PATHINFO_EXTENSION))));
}

function algPdf(string $nome = 'preventivo.pdf'): UploadedFile
{
    return algFile($nome, ALG_PDF);
}

/** «segnalazioni» o «comunicazioni»: il pezzo del nome delle rotte e della cartella. */
function algTipo(Model $elemento): string
{
    return $elemento instanceof Segnalazione ? 'segnalazioni' : 'comunicazioni';
}

/**
 * Un allegato già a database, con il suo file sul disco dei documenti, nella cartella del progetto
 * (`documenti/allegati/<tipo>/<id>/`): serve a preparare gli stati senza passare dal caricamento.
 */
function algAllegatoSalvato(Model $elemento, User $autore, string $nome = 'foto.jpg', bool $approvato = true): int
{
    $estensione = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    $contenuto = $estensione === 'pdf' ? ALG_PDF : algBytesImmagine($estensione === 'jpeg' ? 'jpg' : $estensione);
    $path = 'documenti/allegati/' . algTipo($elemento) . '/' . $elemento->getKey() . '/' . Str::random(40) . '.' . $estensione;
    app(ArchivioDocumenti::class)->disco()->put($path, $contenuto);

    return DB::table('documenti')->insertGetId([
        'name' => $nome,
        'path' => $path,
        'mime_type' => $estensione === 'pdf' ? 'application/pdf' : ($estensione === 'png' ? 'image/png' : ($estensione === 'webp' ? 'image/webp' : 'image/jpeg')),
        'file_size' => strlen($contenuto),
        'created_by' => $autore->id,
        'is_published' => true,
        'is_approved' => $approvato,
        'documentable_type' => $elemento->getMorphClass(),
        'documentable_id' => $elemento->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Gli allegati di un elemento, a database. */
function algRighe(Model $elemento): \Illuminate\Support\Collection
{
    return DB::table('documenti')
        ->where('documentable_type', $elemento->getMorphClass())
        ->where('documentable_id', $elemento->getKey())
        ->orderBy('id')
        ->get();
}

function algCarica($test, User $user, Model $elemento, array $file, string $porta = 'user'): TestResponse
{
    return $test->actingAs($user)->post(route($porta . '.' . algTipo($elemento) . '.allegati.store', $elemento), ['allegati' => $file]);
}

/** Le props della scheda, o `null` se la scheda non si apre. */
function algScheda($test, User $user, Model $elemento, string $porta = 'user'): ?array
{
    $risposta = $test->actingAs($user)->get(route($porta . '.' . algTipo($elemento) . '.show', $elemento));
    if (in_array($risposta->status(), [403, 404], true)) {
        return null;
    }
    $risposta->assertOk();

    return $risposta->viewData('page')['props'];
}

/** Gli id degli allegati che la scheda mostra, in ordine; `null` se la scheda non si apre. */
function algIdInScheda($test, User $user, Model $elemento, string $porta = 'user'): ?array
{
    $props = algScheda($test, $user, $elemento, $porta);
    if ($props === null) {
        return null;
    }

    return collect($props['allegati'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

function algScarica($test, User $user, int $id, string $porta = 'user'): TestResponse
{
    return $test->actingAs($user)->get(route($porta . '.allegati.scarica', ['allegato' => $id]));
}

/** La risposta è un rifiuto: chi non vede un file non sa nemmeno se esiste. */
function algRifiutato(TestResponse $risposta): bool
{
    return in_array($risposta->status(), [403, 404], true);
}

/** Le chiavi degli errori di validazione che cominciano con «allegati». */
function algErroriAllegati(TestResponse $risposta): array
{
    $errori = session('errors');

    return $errori ? collect($errori->getBag('default')->keys())->filter(fn ($k) => str_starts_with($k, 'allegati'))->values()->all() : [];
}
