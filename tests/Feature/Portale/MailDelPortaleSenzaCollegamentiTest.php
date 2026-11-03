<?php

/**
 * # Le mail del portale: i testi scritti dai condòmini restano testo (decisione 136.3)
 *
 * Le mail partono dal gestionale e sono Markdown: un oggetto, un commento, il titolo o la descrizione di
 * un documento, un nome scritti con la sintassi di un collegamento (`[qui](https://…)`) non diventano un
 * collegamento cliccabile nella mail che arriva ai vicini o all'amministratore. (La priorità e lo stato
 * non servono: il database li tiene in un elenco chiuso.)
 *
 * Quei testi arrivano come testo: senza i caratteri che formano un collegamento o un'immagine. I
 * nomi come negli avvisi di sicurezza (testo semplice, su una riga, corti); gli altri testi tengono la
 * loro lunghezza e la punteggiatura che non forma collegamenti, perché chi legge deve capire di che cosa
 * si tratta. Le sole destinazioni delle mail restano il pulsante e gli indirizzi del gestionale.
 */

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as RuoloSpatie;

/** Un testo con un collegamento, un collegamento automatico e un'immagine verso un sito che non è il gestionale. */
const MAIL_PORTALE_COLLEGAMENTI = 'dettagli [qui](https://altrove.example/x) <https://altrove.example/y> ![foto](https://altrove.example/i.png)';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();
    config(['kondomanager.disco_documenti' => 'local']);
    Storage::fake('local');

    $this->aurora = Condominio::factory()->create(['nome' => "Condominio L'Aurora"]);

    $this->admin = User::factory()->create(['email_verified_at' => now(), 'name' => 'Studio Bianchi']);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);
    mailPortalePreferenze($this->admin);

    // L'autore si dà il nome dal profilo, e il nome della sua anagrafica: tutti e due con un collegamento.
    $this->mario = mailPortaleCondomino('Mario [Rossi](https://altrove.example/nome)', 'Mario [Rossi](https://altrove.example/anagrafica)',
        [Permission::CREATE_COMUNICAZIONI->value, Permission::CREATE_ARCHIVE_DOCUMENTS->value]);
    $this->luigi = mailPortaleCondomino('Luigi Verdi', 'Luigi Verdi');
});

function mailPortalePreferenze(User $user): void
{
    foreach (NotificationType::cases() as $tipo) {
        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'type' => $tipo->value, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function mailPortaleCondomino(string $nome, string $nomeAnagrafica, array $permessi = [], string $ruolo = 'utente'): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole($ruolo);
    if ($permessi !== []) {
        $user->givePermissionTo($permessi);
    }
    mailPortalePreferenze($user);
    Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nomeAnagrafica])->condomini()->attach(test()->aurora->id);

    return $user->fresh();
}

/**
 * Tutte le mail mandate finora, composte per chi le riceve e rese in HTML: la classe, l'HTML, il testo che
 * legge chi la apre e gli indirizzi di ogni collegamento e di ogni immagine. Gli indirizzi si leggono dagli
 * attributi del documento, non dal testo: un testo che contiene `href="…"` resta testo, e non è un collegamento.
 *
 * @return list<array{classe: string, html: string, testo: string, indirizzi: list<string>}>
 */
function mailPortaleRese(): array
{
    $rese = [];
    foreach (Notification::sentNotifications() as $perId) {
        foreach ($perId as $perClasse) {
            foreach ($perClasse as $classe => $invii) {
                foreach ($invii as $invio) {
                    if (! in_array('mail', $invio['channels'], true)) {
                        continue;
                    }
                    $html = (string) $invio['notification']->toMail($invio['notifiable'])->render();
                    $dom = new DOMDocument();
                    $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
                    $indirizzi = [];
                    foreach ((new DOMXPath($dom))->query('//@href | //@src') as $attributo) {
                        $indirizzi[] = $attributo->value;
                    }
                    $testo = trim(preg_replace('/\s+/u', ' ', $dom->getElementsByTagName('body')->item(0)?->textContent ?? '') ?? '');
                    $rese[] = ['classe' => class_basename($classe), 'html' => $html, 'testo' => $testo, 'indirizzi' => $indirizzi];
                }
            }
        }
    }

    return $rese;
}

/** Nessuna mail porta a un indirizzo che non sia del gestionale; e le classi attese ci sono tutte. */
function mailPortaleSoloIndirizziDelGestionale(array $classiAttese): void
{
    $rese = mailPortaleRese();
    $classi = array_values(array_unique(array_column($rese, 'classe')));
    sort($classi);
    $attese = $classiAttese;
    sort($attese);
    expect(array_values(array_diff($attese, $classi)))->toBe([], 'mail attese che non sono partite');

    // Per classe, gli indirizzi fuori dal gestionale: devono non essercene, in nessuna mail.
    $fuori = [];
    foreach ($rese as $resa) {
        foreach ($resa['indirizzi'] as $indirizzo) {
            if (str_contains($indirizzo, 'altrove.example')) {
                $fuori[$resa['classe']][] = $indirizzo;
            }
        }
    }
    ksort($fuori);
    expect(array_map(fn ($l) => array_values(array_unique($l)), $fuori))->toBe([], 'un testo scritto da altri è diventato un collegamento');
}

test('decisione 136 — le mail delle segnalazioni e dei commenti non portano collegamenti dai testi dei condòmini', function () {
    RuoloSpatie::findOrCreate('Condòmino moderato', 'web')->syncPermissions([
        Permission::VIEW_SEGNALAZIONI->value, Permission::COMMENT_SEGNALAZIONI->value, Permission::VIEW_COMMENTS_SEGNALAZIONI->value,
    ]);
    $gino = mailPortaleCondomino('Gino [Neri](https://altrove.example/gino)', 'Gino Neri', [], 'Condòmino moderato');

    // L'autore apre la segnalazione dal portale. Va in moderazione: l'amministratore riceve «da approvare».
    $this->actingAs($this->mario)->post(route('user.segnalazioni.store'), [
        'subject' => 'Perdita in cantina: '.MAIL_PORTALE_COLLEGAMENTI, 'description' => 'x', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false,
    ])->assertSessionHasNoErrors();
    $s = Segnalazione::query()->latest('id')->firstOrFail();

    // L'approvazione: «approvata» all'autore, «nuova» a Luigi.
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s))->assertRedirect();

    // Un commento di Luigi, subito pubblico; uno di Gino, in moderazione e poi approvato.
    $this->actingAs($this->luigi)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Guardate '.MAIL_PORTALE_COLLEGAMENTI])->assertRedirect();
    $this->actingAs($gino)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Anche da me: '.MAIL_PORTALE_COLLEGAMENTI])->assertRedirect();
    $delGino = Commento::query()->where('user_id', $gino->id)->firstOrFail();
    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $delGino))->assertRedirect();

    // Il commento di Luigi nascosto dall'amministratore: «commento rimosso» a Luigi.
    $diLuigi = Commento::query()->where('user_id', $this->luigi->id)->firstOrFail();
    $this->actingAs($this->admin)->post(route('admin.commenti.modera', $diLuigi))->assertRedirect();

    // La modifica con l'avviso a chi la vede già: «aggiornata».
    $s = $s->fresh();
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $s), [
        'subject' => $s->subject, 'description' => $s->description, 'priority' => $s->priority, 'stato' => $s->stato,
        'condominio_id' => $this->aurora->id, 'can_comment' => true, 'is_featured' => false, 'is_published' => true,
        'avvisa_destinatari' => true,
    ])->assertSessionHasNoErrors();

    // Una privata dell'autore resa pubblica: «resa pubblica» all'autore.
    $this->actingAs($this->mario)->post(route('user.segnalazioni.store'), [
        'subject' => 'Muffa: '.MAIL_PORTALE_COLLEGAMENTI, 'description' => 'x', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => true,
    ])->assertSessionHasNoErrors();
    $privata = Segnalazione::query()->latest('id')->firstOrFail();
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.rendi-pubblica', $privata))->assertRedirect();

    mailPortaleSoloIndirizziDelGestionale([
        'ApproveSegnalazioneNotification', 'ApprovedSegnalazioneNotification', 'NewSegnalazioneNotification',
        'UpdatedSegnalazioneNotification', 'SegnalazioneResaPubblicaNotification', 'NuovoCommentoNotification',
        'CommentoInAttesaNotification', 'CommentoApprovatoNotification', 'CommentoEliminatoNotification',
    ]);
});

test('decisione 136 — le mail delle comunicazioni non portano collegamenti dai testi dei condòmini', function () {
    $this->actingAs($this->mario)->post(route('user.comunicazioni.store'), [
        'subject' => 'Turni delle pulizie: '.MAIL_PORTALE_COLLEGAMENTI, 'description' => 'x',
        'priority' => 'media', 'is_featured' => false, 'is_private' => false,
        'condomini_ids' => [$this->aurora->id],
    ])->assertSessionHasNoErrors();
    $c = Comunicazione::query()->latest('id')->firstOrFail();

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', $c))->assertRedirect();

    $c = $c->fresh();
    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), [
        'subject' => $c->subject, 'description' => $c->description, 'priority' => $c->priority,
        'can_comment' => (bool) $c->can_comment, 'is_featured' => (bool) $c->is_featured, 'is_published' => true,
        'is_approved' => true, 'created_by' => $c->created_by, 'condomini_ids' => [$this->aurora->id],
        'anagrafiche' => [], 'avvisa_destinatari' => true,
    ])->assertSessionHasNoErrors();

    mailPortaleSoloIndirizziDelGestionale([
        'ApproveComunicazioneNotification', 'ApprovedComunicazioneNotification', 'NewComunicazioneNotification',
        'UpdatedComunicazioneNotification',
    ]);
});

test('decisione 136 — le mail dei documenti caricati dal portale non portano collegamenti dal titolo e dalla descrizione', function () {
    $categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'x']);

    $this->actingAs($this->mario)->post(route('user.documenti.store'), [
        'name' => 'Verbale: '.MAIL_PORTALE_COLLEGAMENTI, 'description' => 'Leggete '.MAIL_PORTALE_COLLEGAMENTI,
        'condomini_ids' => [$this->aurora->id], 'is_private' => false, 'categorie' => [$categoria->id],
        'file' => UploadedFile::fake()->create('verbale.pdf', 12, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $d = Documento::query()->latest('id')->firstOrFail();

    $this->actingAs($this->admin)->put(route('admin.documenti.toggle-approval', $d))->assertRedirect();

    $d = $d->fresh();
    $this->actingAs($this->admin)->put(route('admin.documenti.update', $d), [
        'name' => $d->name, 'description' => $d->description, 'created_by' => $d->created_by,
        'is_approved' => true, 'is_published' => true, 'categorie' => [$categoria->id],
        'condomini_ids' => [$this->aurora->id], 'anagrafiche' => [], 'avvisa_destinatari' => true,
    ])->assertSessionHasNoErrors();

    mailPortaleSoloIndirizziDelGestionale([
        'ApproveDocumentoNotification', 'ApprovedDocumentoNotification', 'NewDocumentoNotification',
        'UpdatedDocumentoNotification',
    ]);
});

test('decisione 136 — un testo comune arriva nella mail com\'è, parentesi e apostrofi compresi', function () {
    // La controprova: la correzione toglie solo ciò che forma un collegamento.
    $this->actingAs($this->luigi)->post(route('user.segnalazioni.store'), [
        'subject' => "Perdita d'acqua (scala B) al 2° piano", 'description' => 'x', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false,
    ])->assertSessionHasNoErrors();

    $rese = array_values(array_filter(mailPortaleRese(), fn ($r) => $r['classe'] === 'ApproveSegnalazioneNotification'));
    expect($rese)->not->toBe([])
        ->and(html_entity_decode($rese[0]['html'], ENT_QUOTES))->toContain("Perdita d'acqua (scala B) al 2° piano")
        ->and(html_entity_decode($rese[0]['html'], ENT_QUOTES))->toContain('Luigi Verdi');
});

test('decisione 136 — il saluto delle mail «nuova» e «da approvare» non porta collegamenti dal nome di chi le riceve', function () {
    // Le «nuove» arrivano all'anagrafica del vicino, le «da approvare» all'amministratore: i due nomi li scrive chi
    // gestisce gli account e le anagrafiche, e qui hanno la sintassi di un collegamento.
    $this->admin->update(['name' => 'Studio [Bianchi](https://altrove.example/saluto-amministratore)']);
    $this->luigi->update(['name' => 'Luigi [Verdi](https://altrove.example/saluto-utente)']);
    $this->luigi->anagrafica->update(['nome' => 'Luigi [Verdi](https://altrove.example/saluto-anagrafica)']);
    $categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'x']);

    // Una segnalazione, una comunicazione e un documento dall'autore: «da approvare» all'amministratore; approvati, «nuova» al vicino.
    $this->actingAs($this->mario)->post(route('user.segnalazioni.store'), [
        'subject' => 'Perdita in cantina', 'description' => 'x', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', Segnalazione::query()->latest('id')->firstOrFail()))->assertRedirect();

    $this->actingAs($this->mario)->post(route('user.comunicazioni.store'), [
        'subject' => 'Turni delle pulizie', 'description' => 'x', 'priority' => 'media', 'is_featured' => false,
        'is_private' => false, 'condomini_ids' => [$this->aurora->id],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('admin.comunicazioni.toggle-approval', Comunicazione::query()->latest('id')->firstOrFail()))->assertRedirect();

    $this->actingAs($this->mario)->post(route('user.documenti.store'), [
        'name' => 'Verbale di giugno', 'description' => 'Il verbale', 'condomini_ids' => [$this->aurora->id],
        'is_private' => false, 'categorie' => [$categoria->id],
        'file' => UploadedFile::fake()->create('verbale.pdf', 12, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('admin.documenti.toggle-approval', Documento::query()->latest('id')->firstOrFail()))->assertRedirect();

    $classi = [
        'NewSegnalazioneNotification', 'NewComunicazioneNotification', 'NewDocumentoNotification',
        'ApproveSegnalazioneNotification', 'ApproveComunicazioneNotification', 'ApproveDocumentoNotification',
    ];
    mailPortaleSoloIndirizziDelGestionale($classi);

    // Il saluto c'è, con il nome di chi la riceve come testo: ogni classe è arrivata almeno una volta a uno dei due nomi.
    $salutati = [];
    foreach (mailPortaleRese() as $resa) {
        foreach (['Salve Luigi Verdihttps://altrove.example/saluto-anagrafica', 'Salve Studio Bianchihttps://altrove.example/saluto-amministratore'] as $saluto) {
            if (in_array($resa['classe'], $classi, true) && str_contains($resa['testo'], $saluto)) {
                $salutati[] = $resa['classe'];
            }
        }
    }
    $salutati = array_values(array_unique($salutati));
    sort($salutati);
    sort($classi);
    expect($salutati)->toBe($classi);
});

test('decisione 136 — un oggetto con le parentesi angolari arriva intero nel testo della mail', function () {
    // Le angolari non formano collegamenti nelle mail: il modello scrive ogni riga come testo. Il testo resta com'è.
    $this->actingAs($this->luigi)->post(route('user.segnalazioni.store'), [
        'subject' => 'Temperatura < 18 gradi in caldaia, > 25 in sala', 'description' => 'x', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false,
    ])->assertSessionHasNoErrors();

    $rese = array_values(array_filter(mailPortaleRese(), fn ($r) => $r['classe'] === 'ApproveSegnalazioneNotification'));
    expect($rese)->not->toBe([])
        ->and($rese[0]['testo'])->toContain('Temperatura < 18 gradi in caldaia, > 25 in sala');
});

test('decisione 136 — un indirizzo fra parentesi angolari o un collegamento HTML scritti da un condòmino restano testo', function () {
    $oggetto = 'Vedi <https://altrove.example/automatico> e <a href="https://altrove.example/html">qui</a> <img src="https://altrove.example/i.png">';
    $this->actingAs($this->luigi)->post(route('user.segnalazioni.store'), [
        'subject' => $oggetto, 'description' => 'x', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', Segnalazione::query()->latest('id')->firstOrFail()))->assertRedirect();

    mailPortaleSoloIndirizziDelGestionale(['ApproveSegnalazioneNotification', 'ApprovedSegnalazioneNotification', 'NewSegnalazioneNotification']);

    // Ciò che il condòmino ha scritto arriva come lo ha scritto, da leggere e non da cliccare.
    $rese = array_values(array_filter(mailPortaleRese(), fn ($r) => $r['classe'] === 'NewSegnalazioneNotification'));
    expect($rese)->not->toBe([])
        ->and($rese[0]['testo'])->toContain($oggetto);
});
