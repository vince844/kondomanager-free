<?php

/**
 * Il collegamento delle notifiche porta a una pagina che esiste — per tutti e due i ruoli.
 *
 * ## Il difetto
 *
 * Le tre notifiche sui documenti costruivano il collegamento concatenando il prefisso di ruolo a
 * un percorso scritto a mano: `url("/{$prefix}/categorie-documenti/")`. Misurando i dodici
 * collegamenti delle notifiche contro il router, **tre su ventiquattro** non rispondevano, e
 * stavano tutti e tre qui:
 *
 * | Notifica | Ruolo | Indirizzo | Esito |
 * | :--- | :--- | :--- | :--- |
 * | «nuovo documento» | amministratore | `/admin/categorie-documenti/` | 404, non è mai esistito |
 * | «documento approvato» | amministratore | `/admin/categorie-documenti/` | 404 |
 * | «documento da approvare» | condòmino | `/user/documenti/` | **500** fino alla beta.62 |
 *
 * Il terzo era il peggiore: la rotta esisteva ed era una delle diciassette che puntavano a un
 * metodo assente, quindi chi cliccava non trovava «pagina non trovata» ma un errore del server.
 *
 * ## Perché nessuno l'aveva segnalato
 *
 * Perché un collegamento in una mail che porta a una pagina vuota **non sembra un difetto del
 * programma**: sembra di aver sbagliato qualcosa, o che il documento sia stato tolto. E perché
 * il difetto vive solo per **un** ruolo alla volta — l'amministratore che prova la notifica del
 * condòmino non la riceve mai.
 *
 * ## La regola che questo file fissa
 *
 * Il collegamento si costruisce **da un nome di rotta**, non concatenando un percorso: `route()`
 * fallisce rumorosamente su un nome sbagliato, una stringa concatenata no. È lo stesso
 * presupposto che questa beta ha smontato in altri due posti — `RotteSenzaMetodoTest` e
 * `NomiDiRottaCheNonEsistonoTest` — e qui prende la sua terza forma.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre le altre nove notifiche (comunicazioni, segnalazioni, commenti): i loro collegamenti
 * sono stati misurati e **rispondono tutti**, per entrambi i ruoli, quindi non c'era niente da
 * correggere e non si allarga una correzione per simmetria. Restano costruiti a mano: il giorno
 * che una di quelle rotte cambia, si romperanno in silenzio come queste. La voce è in roadmap.
 *
 * Non copre il contenuto della mail né i permessi di chi la riceve.
 *
 * ⚠️ **E soprattutto: `ilPercorsoRisponde()` verifica l'instradamento, non la risposta.**
 * `Route::getRoutes()->match()` trova la rotta e si ferma lì — non invoca il controller e non
 * guarda se il metodo dietro esiste. Misurato dalla revisione avversariale rimettendo la forma
 * pre-beta di `routes/user.php`: `/user/documenti` risultava «una pagina che esiste» mentre
 * `Documenti\Utenti\DocumentoController@index` non c'era, cioè proprio la riga che la tabella qui
 * sopra classifica come «500 fino alla beta.62».
 *
 * La copertura in aggregato regge — che il metodo esista lo garantisce
 * `tests/Feature/System/RotteSenzaMetodoTest.php`, per **tutte** le rotte — ma i due presidi vanno
 * letti insieme: questo dice «l'indirizzo è instradato», quello dice «dietro c'è del codice».
 * Nessuno dei due, da solo, dice che il pulsante funziona.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Documento;
use App\Models\User;
use App\Notifications\Documenti\ApproveDocumentoNotification;
use App\Notifications\Documenti\ApprovedDocumentoNotification;
use App\Notifications\Documenti\NewDocumentoNotification;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * L'indirizzo del pulsante della notifica, estratto dal messaggio vero.
 *
 * Si legge dal `MailMessage` costruito da `toMail()` e non dal sorgente: è l'unico modo perché
 * il test veda quello che vede chi riceve la mail.
 */
function indirizzoDelPulsante(object $notifica, User $destinatario): string
{
    return $notifica->toMail($destinatario)->actionUrl;
}

/** Il percorso risponde a una GET? È la domanda che l'utente pone cliccando. */
function ilPercorsoRisponde(string $url): bool
{
    $percorso = parse_url($url, PHP_URL_PATH) ?: '/';

    try {
        Route::getRoutes()->match(Illuminate\Http\Request::create($percorso, 'GET'));

        return true;
    } catch (\Throwable) {
        return false;
    }
}

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $autore = User::factory()->create();

    $this->documento = Documento::create([
        'name'         => 'Verbale assemblea 2026',
        'description'  => 'Approvazione del consuntivo',
        'path'         => 'documenti/a1b2c3d4.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 122880,
        'category_id'  => CategoriaDocumento::firstOrCreate(['name' => 'Verbali'])->id,
        'is_published' => true,
        'is_approved'  => true,
        'created_by'   => $autore->id,
    ]);

    $ruoloAdmin = SpatieRole::firstOrCreate(['name' => Role::AMMINISTRATORE->value, 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo(
        SpatiePermission::firstOrCreate(['name' => Permission::ACCESS_ADMIN_PANEL->value, 'guard_name' => 'web'])
    );

    $this->amministratore = User::factory()->create();
    $this->amministratore->assignRole($ruoloAdmin);

    $this->condomino = User::factory()->create();
    Anagrafica::factory()->create(['user_id' => $this->condomino->id]);
});

dataset('le tre notifiche sui documenti', [
    'nuovo documento'      => [fn () => new NewDocumentoNotification($this->documento)],
    // Il secondo parametro è l'autore da nominare nel testo: qui non conta, conta il pulsante.
    'documento approvato'  => [fn () => new ApprovedDocumentoNotification($this->documento, $this->amministratore)],
    'documento da approvare' => [fn () => new ApproveDocumentoNotification($this->documento)],
]);

it("porta l'amministratore su una pagina che esiste", function (object $notifica) {
    $url = indirizzoDelPulsante($notifica, $this->amministratore);

    expect(ilPercorsoRisponde($url))->toBeTrue("Il pulsante manda l'amministratore su {$url}, che non risponde.")
        ->and($url)->toContain('/admin/documenti');
})->with('le tre notifiche sui documenti');

it('e il condòmino su una pagina che esiste', function (object $notifica) {
    // ⚠️ È la metà che mancava, ed è quella che nessuno può accorgersi che manca: chi riceve
    // questa mail non ha modo di sapere se il pulsante è rotto per tutti o solo per lui.
    $url = indirizzoDelPulsante($notifica, $this->condomino);

    expect(ilPercorsoRisponde($url))->toBeTrue("Il pulsante manda il condòmino su {$url}, che non risponde.")
        ->and($url)->toContain('/user/categorie-documenti');
})->with('le tre notifiche sui documenti');
