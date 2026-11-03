<?php

/**
 * # Chi vede solo le proprie: i conteggi e la dashboard del portale (decisione 134.4)
 *
 * Il permesso «Visualizza proprie segnalazioni» (e il gemello delle comunicazioni) lo dà solo un ruolo
 * su misura: la scheda gli apre le proprie e nient'altro (`SegnalazionePolicy::show`). I conteggi
 * del portale e la dashboard guardano quale dei due permessi l'utente ha, come la scheda: non
 * mostrano le segnalazioni del palazzo che la scheda gli nega. Lo stesso per chi non ha nessuno dei
 * due permessi, e per i documenti a chi non ha «Visualizza documenti archivio», che lo scaricamento
 * nega.
 *
 * La regola: l'elenco mostra ciò che la scheda lascia aprire. Il permesso entra nella regola sola
 * (`scopeVisibiliNelPortale()`), che usano la scheda, l'elenco, i conteggi e la dashboard.
 */

use App\Enums\Permission;
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
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->borea  = Condominio::factory()->create(['nome' => 'Condominio Borea']);
    $this->vicino = User::factory()->create(['name' => 'Luigi Verdi']);

    RuoloSpatie::findOrCreate('Proprie', 'web')->syncPermissions([
        Permission::CREATE_SEGNALAZIONI->value, Permission::VIEW_OWN_SEGNALAZIONI->value,
        Permission::CREATE_COMUNICAZIONI->value, Permission::VIEW_OWN_COMUNICAZIONI->value,
        Permission::VIEW_ARCHIVE_DOCUMENTS->value,
    ]);
    RuoloSpatie::findOrCreate('Tutte e proprie', 'web')->syncPermissions([
        Permission::VIEW_SEGNALAZIONI->value, Permission::VIEW_OWN_SEGNALAZIONI->value,
        Permission::VIEW_COMUNICAZIONI->value, Permission::VIEW_OWN_COMUNICAZIONI->value,
        Permission::VIEW_ARCHIVE_DOCUMENTS->value,
    ]);
    RuoloSpatie::findOrCreate('Solo agenda', 'web')->syncPermissions([Permission::VIEW_EVENTS->value]);
});

function proprieUtente(string $ruolo, array $condominiIds): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => 'Mario Rossi']);
    $user->assignRole($ruolo);
    Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => 'Mario Rossi'])->condomini()->attach($condominiIds);

    return $user->fresh();
}

function proprieSegnalazione(Condominio $condominio, User $autore, string $oggetto, string $priorita, array $stato = []): Segnalazione
{
    return Segnalazione::create(array_merge([
        'subject' => $oggetto, 'description' => "Dal terzo piano\nnon scende", 'priority' => $priorita, 'stato' => 'aperta',
        'condominio_id' => $condominio->id, 'created_by' => $autore->id, 'is_private' => false,
        'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $stato));
}

function proprieComunicazione(Condominio $condominio, User $autore, string $oggetto): Comunicazione
{
    $c = Comunicazione::create([
        'subject' => $oggetto, 'description' => 'x', 'priority' => 'media', 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => false,
    ]);
    $c->condomini()->attach($condominio->id);

    return $c;
}

/** Gli id che la dashboard del portale manda alla pagina, per una delle sue tre schede. */
function proprieDashboard(User $user, string $scheda): array
{
    $props = test()->actingAs($user)->get(route('user.dashboard'))->assertOk()->viewData('page')['props'];

    return collect($props[$scheda])->pluck('id')->sort()->values()->all();
}

/** I conteggi del portale, sommati. */
function proprieConteggi(User $user): int
{
    $conteggi = test()->actingAs($user)->getJson(route('segnalazioni.stats'))->assertOk()->json();

    return (int) array_sum(array_map('intval', $conteggi));
}

test('decisione 134 — chi vede solo le proprie segnalazioni non trova nei conteggi del portale quelle del palazzo che la scheda gli nega', function () {
    $mario = proprieUtente('Proprie', [$this->aurora->id]);
    $delVicino = proprieSegnalazione($this->aurora, $this->vicino, 'Ascensore fermo', 'alta');
    proprieSegnalazione($this->aurora, $mario, "Perdita nel box «2»", 'bassa');

    $this->actingAs($mario)->get(route('user.segnalazioni.show', $delVicino))->assertForbidden();
    expect(proprieConteggi($mario))->toBe(1);
});

test('decisione 134 — chi vede solo le proprie segnalazioni e comunicazioni trova nella dashboard del portale solo le proprie', function () {
    $mario = proprieUtente('Proprie', [$this->aurora->id]);
    $delVicino = proprieSegnalazione($this->aurora, $this->vicino, 'Ascensore fermo', 'alta');
    $propria = proprieSegnalazione($this->aurora, $mario, "Perdita nel box «2»", 'bassa');
    $comVicino = proprieComunicazione($this->aurora, $this->vicino, 'Turni delle pulizie');
    $comPropria = proprieComunicazione($this->aurora, $mario, "L'assemblea di giugno");

    $this->actingAs($mario)->get(route('user.segnalazioni.show', $delVicino))->assertForbidden();
    $this->actingAs($mario)->get(route('user.comunicazioni.show', $comVicino))->assertForbidden();

    expect(proprieDashboard($mario, 'segnalazioni'))->toBe([$propria->id])
        ->and(proprieDashboard($mario, 'comunicazioni'))->toBe([$comPropria->id]);
});

test('decisione 134 — chi non vede né le segnalazioni né le comunicazioni né i documenti non le trova nella dashboard né nei conteggi', function () {
    $mario = proprieUtente('Solo agenda', [$this->aurora->id]);
    $s = proprieSegnalazione($this->aurora, $this->vicino, 'Ascensore fermo', 'alta');
    $c = proprieComunicazione($this->aurora, $this->vicino, 'Turni delle pulizie');
    $cat = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'x']);
    $d = Documento::create(['name' => "Verbale dell'assemblea", 'description' => 'x', 'created_by' => $this->vicino->id,
        'is_published' => true, 'is_approved' => true, 'path' => 'documenti/v.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10]);
    $d->condomini()->attach($this->aurora->id);
    $d->categorie()->attach($cat->id);

    $this->actingAs($mario)->get(route('user.segnalazioni.show', $s))->assertForbidden();
    $this->actingAs($mario)->get(route('user.comunicazioni.show', $c))->assertForbidden();
    $this->actingAs($mario)->get(route('user.documenti.download', $d))->assertForbidden();

    expect(proprieDashboard($mario, 'segnalazioni'))->toBe([])
        ->and(proprieDashboard($mario, 'comunicazioni'))->toBe([])
        ->and(proprieDashboard($mario, 'documenti'))->toBe([])
        ->and(proprieConteggi($mario))->toBe(0);
});

test('decisione 134 — i conteggi e la dashboard del portale mostrano ciò che la scheda lascia aprire, per ogni ruolo', function () {
    // Quattro utenti: il condòmino di serie, chi vede solo le proprie, chi vede tutte e anche le
    // proprie, chi non le vede. Le segnalazioni: del vicino nell'Aurora e nella Borea, la propria
    // nell'Aurora, la propria nella Borea da cui l'utente è uscito, la propria privata nella Borea,
    // la propria in moderazione nell'Aurora. L'utente resta solo nell'Aurora.
    //
    // Gli insiemi attesi sono scritti a mano dalla regola, non chiesti al programma: l'elenco mostra le
    // pubblicate e approvate (le proprie in moderazione si aprono per indirizzo e non compaiono in
    // elenco: Coda 389) che la scheda apre.
    // - di serie: quella del vicino nell'Aurora, la propria nell'Aurora, la propria privata (una privata
    //   resta del suo autore); non la propria non privata della Borea, da cui è uscito;
    // - solo le proprie: le sue tre pubblicate e approvate, dovunque siano;
    // - tutte e proprie: l'unione delle due;
    // - nessuna: niente.
    $attesiPerCaso = [
        'utente di serie' => ['Del vicino, Aurora', 'Propria, Aurora', 'Propria privata, Borea'],
        'solo le proprie' => ['Propria, Aurora', 'Propria, Borea', 'Propria privata, Borea'],
        'tutte e proprie' => ['Del vicino, Aurora', 'Propria, Aurora', 'Propria, Borea', 'Propria privata, Borea'],
        'nessuna'         => [],
    ];
    $utenti = [
        'utente di serie' => proprieUtente(Role::UTENTE->value, [$this->aurora->id, $this->borea->id]),
        'solo le proprie' => proprieUtente('Proprie', [$this->aurora->id, $this->borea->id]),
        'tutte e proprie' => proprieUtente('Tutte e proprie', [$this->aurora->id, $this->borea->id]),
        'nessuna'         => proprieUtente('Solo agenda', [$this->aurora->id, $this->borea->id]),
    ];

    foreach ($utenti as $caso => $utente) {
        Segnalazione::query()->delete();
        $tutte = collect([
            proprieSegnalazione($this->aurora, $this->vicino, 'Del vicino, Aurora', 'alta'),
            proprieSegnalazione($this->borea, $this->vicino, 'Del vicino, Borea', 'media'),
            proprieSegnalazione($this->aurora, $utente, 'Propria, Aurora', 'bassa'),
            proprieSegnalazione($this->borea, $utente, 'Propria, Borea', 'urgente'),
            proprieSegnalazione($this->borea, $utente, 'Propria privata, Borea', 'alta', ['is_private' => true]),
            proprieSegnalazione($this->aurora, $utente, 'Propria in moderazione', 'alta', ['is_approved' => false]),
        ])->keyBy('subject');
        $utente->anagrafica->condomini()->detach($this->borea->id);
        $utente = $utente->fresh();

        $attese = collect($attesiPerCaso[$caso])->map(fn (string $oggetto) => $tutte[$oggetto]->id)->sort()->values()->all();

        // La dashboard ne mostra al più tre, le più recenti: tutte fra quelle attese.
        $nellaDashboard = proprieDashboard($utente, 'segnalazioni');
        expect(array_values(array_diff($nellaDashboard, $attese)))->toBe([], $caso)
            ->and(count($nellaDashboard))->toBe(min(3, count($attese)), $caso)
            ->and(proprieConteggi($utente))->toBe(count($attese), $caso);

        // E la scheda le apre: l'elenco non promette una pagina che poi rifiuta.
        foreach ($attese as $id) {
            $this->actingAs($utente)->get(route('user.segnalazioni.show', $id))->assertOk();
        }
    }
});

test('decisione 134 — chi vede tutte e anche le proprie trova nella dashboard la propria di un palazzo da cui è uscito, che la scheda gli apre', function () {
    $mario = proprieUtente('Tutte e proprie', [$this->aurora->id, $this->borea->id]);
    $propria = proprieSegnalazione($this->borea, $mario, 'Propria, Borea', 'alta');
    $mario->anagrafica->condomini()->detach($this->borea->id);
    $mario = $mario->fresh();

    $this->actingAs($mario)->get(route('user.segnalazioni.show', $propria))->assertOk();
    expect(proprieDashboard($mario, 'segnalazioni'))->toBe([$propria->id])
        ->and(proprieConteggi($mario))->toBe(1);
});

test('decisione 134 — l\'elenco del portale non mostra a chi vede solo le proprie le segnalazioni e le comunicazioni del palazzo', function () {
    // La pagina dell'elenco a questo ruolo si apre (decisione 134.4: `ElencoDiChiVedeSoloLeProprieTest`),
    // e non porta quelle del palazzo.
    $mario = proprieUtente('Proprie', [$this->aurora->id]);
    proprieSegnalazione($this->aurora, $this->vicino, 'Ascensore fermo del vicino', 'alta');
    proprieComunicazione($this->aurora, $this->vicino, 'Turni delle pulizie del vicino');

    expect($this->actingAs($mario)->get(route('user.segnalazioni.index'))->assertOk()->getContent())->not->toContain('Ascensore fermo del vicino')
        ->and($this->actingAs($mario)->get(route('user.comunicazioni.index'))->assertOk()->getContent())->not->toContain('Turni delle pulizie del vicino');
});

test('decisione 134 — chi vede i documenti, e non le segnalazioni, trova i documenti del palazzo nella dashboard e li scarica', function () {
    // Il caso vicino: il permesso che conta per i documenti è «Visualizza documenti archivio».
    config(['kondomanager.disco_documenti' => 'local']);
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Storage::disk('local')->put('documenti/v.pdf', '%PDF-1.4');
    $mario = proprieUtente('Proprie', [$this->aurora->id]);
    $cat = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'x']);
    $d = Documento::create(['name' => "Verbale dell'assemblea", 'description' => 'x', 'created_by' => $this->vicino->id,
        'is_published' => true, 'is_approved' => true, 'path' => 'documenti/v.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10]);
    $d->condomini()->attach($this->aurora->id);
    $d->categorie()->attach($cat->id);

    $this->actingAs($mario)->get(route('user.documenti.download', $d))->assertOk();
    expect(proprieDashboard($mario, 'documenti'))->toBe([$d->id]);
});
