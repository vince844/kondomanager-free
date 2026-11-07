<?php

/**
 * Le lettere dall'elenco delle rate scadute (promemoria, sollecito, diffida, messa in mora): l'anteprima di chi riceve che cosa con il canale
 * proposto per persona, la stampa, il .zip per persona, l'email dal programma solo per promemoria e sollecito, il PDF conservato per sollecito,
 * diffida e messa in mora, la guardia alla partenza, «riprova» che rifà la lettera con le cifre di oggi, le persone dell'anteprima come confine
 * dell'invio, il modello salvato dalla finestra, la prova a sé. Le cifre: Ugo € 60,00 a rata, Bice € 40,00 (dai millesimi dello scenario).
 *
 * Nessuna email vera: la coda è finta e il trasporto è `array` (i messaggi restano in memoria).
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Notifications\Gestionale\LetteraNotification;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

it('l\'anteprima: chi riceve che cosa, il canale proposto per persona dalla sua ultima lettera, il credito accanto, il tipo mai ricordato', function () {
    $s = lttScenario($this);
    $a = $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s))->assertOk()->json();

    $persone = collect($a['persone'])->keyBy('nome');
    // Ugo: le rate 1, 2, 3 (€ 180,00); Bice: le rate 2 e 3 (€ 80,00) e € 20,00 di credito. Tutti e due hanno l'email: si propone l'email.
    expect($persone['Ugo'])->toMatchArray(['totale' => 18000, 'righe' => 3, 'canale' => 'email', 'credito' => 0])
        ->and($persone['Bice'])->toMatchArray(['totale' => 8000, 'righe' => 2, 'canale' => 'email', 'credito' => 2000])
        ->and($a['credito'])->toBe([['nome' => 'Bice', 'credito' => 2000]])
        ->and($a['conteggi'])->toBe(['email' => 2, 'stampa' => 0, 'zip' => 0])
        ->and($a['studio_compilato'])->toBeFalse();

    // Bice senza email: la stampa. Dopo un sollecito stampato a Ugo, per Ugo si propone la stampa anche se l'email c'è.
    $s['persone']['bice']->update(['email' => null]);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk();
    $a = $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s))->assertOk()->json();
    expect(collect($a['persone'])->pluck('canale', 'nome')->all())->toEqual(['Ugo' => 'stampa', 'Bice' => 'stampa']);

    // Il tipo non si ricorda: le scelte ricordate dicono il termine (per tipo di lettera) e il conto, non il tipo.
    $o = $this->actingAs($this->user)->getJson(lttRotta($s, 'opzioni'))->assertOk()->json();
    expect($o['scelta_ricordata'])->toMatchArray(['termini' => ['sollecito' => ['forma' => 'giorni', 'giorni' => 15]], 'cassa_id' => $s['cassa']->id])
        ->and($o['scelta_ricordata'])->not->toHaveKey('tipo');
});

it('la diffida: l\'email è rifiutata con la frase; la stampa fa una riga «stampata» con il PDF conservato; «Registra la spedizione» la fa «spedita» con l\'evento; la prova non si apre dall\'archivio né dal portale e si scarica dal registro', function () {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = lttScenario($this);
    $scelte = lttScelte($s, ['tipo' => 'diffida', 'persone' => [$s['persone']['ugo']->id], 'testo' => 'Il testo della diffida dello studio.']);

    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), $scelte)->assertStatus(422);
    expect($r->json('message'))->toBe(EmissioneLettere::SOLO_CARTA)
        ->and(lttRighe($s, 'ugo'))->toHaveCount(0);
    Queue::assertNothingPushed();

    $pdf = $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), $scelte)->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf');
    $riga = lttRighe($s, 'ugo')->sole();
    $documenti = lttDocumentiDellaRiga($riga);
    expect($riga->tipo)->toBe('diffida')->and($riga->canale)->toBe('stampa')->and($riga->stato)->toBe('stampata')
        ->and($riga->documentabile_type)->toBe(Anagrafica::class)->and($riga->documentabile_id)->toBe($s['persone']['ugo']->id)
        ->and($riga->dati['totale'])->toBe(18000)
        ->and(collect($riga->dati['righe'])->pluck('residuo')->all())->toBe([6000, 6000, 6000])
        ->and($riga->dati['persona']['nome'])->toBe('Ugo')
        ->and($documenti)->toHaveCount(1)
        ->and($riga->impronta)->toBe(hash('sha256', Storage::disk('local')->get($documenti[0]->path)));

    // La spedizione: raccomandata n. 1234, il 21/03. La riga diventa «spedita», con l'evento.
    $this->travelTo('2026-03-21 11:00:00');
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), [
        'invii' => [$riga->id], 'canale' => 'raccomandata', 'data' => '2026-03-21', 'numeri' => [$riga->id => '1234'], 'nota' => 'Ufficio di via Roma',
    ])->assertOk();
    $riga->refresh();
    expect($riga->stato)->toBe('spedita')
        ->and($riga->dati['spedizione'])->toMatchArray(['canale' => 'raccomandata', 'data' => '2026-03-21', 'numero' => '1234'])
        ->and(collect($riga->dati['eventi'])->pluck('evento')->all())->toBe(['spedizione']);

    // La prova: l'avviso di ricevimento in PDF. Non si apre dall'archivio né dal portale; si scarica dal registro.
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('avviso.pdf', '%PDF-1.4 prova');
    $this->actingAs($this->user)->post(lttRotta($s, 'prove.store', ['invio' => $riga->id]), ['file' => $file])->assertRedirect();
    $prova = DB::table('documenti')->where('documentable_type', RegistroInvio::class)->where('documentable_id', $riga->id)->where('name', 'avviso.pdf')->first();
    expect($prova)->not->toBeNull()
        ->and(collect($riga->fresh()->dati['eventi'])->pluck('evento')->all())->toBe(['spedizione', 'prova_allegata']);
    $archivio = Permission::firstOrCreate(['name' => \App\Enums\Permission::VIEW_ARCHIVE_DOCUMENTS->value, 'guard_name' => 'web']);
    $this->user->givePermissionTo($archivio);
    $this->actingAs($this->user->fresh())->get(route('admin.documenti.download', ['documento' => $prova->id]))->assertNotFound();
    // Dal portale: Ugo con il suo utente e il permesso dell'archivio non la apre.
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    $condomino->givePermissionTo($archivio);
    $s['persone']['ugo']->forceFill(['user_id' => $condomino->id])->save();
    $this->actingAs($condomino)->get(route('user.documenti.download', ['documento' => $prova->id]))->assertForbidden();
    $this->actingAs($this->user)->get(lttRotta($s, 'prove.scarica', ['invio' => $riga->id, 'documento' => $prova->id]))->assertOk();

    // La spedizione si registra una volta: una riga già spedita non si rispedisce.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$riga->id], 'canale' => 'a_mano', 'data' => '2026-03-21'])->assertStatus(422);
});

it('il PDF conservato: un sollecito per email a due indirizzi fa due righe e un documento; il lavoro rifatto non rifà il PDF; l\'impronta è quella del file scaricato dal registro; il promemoria non conserva niente', function () {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = lttScenario($this);
    $s['persone']['ugo']->update(['email_secondaria' => 'ugo.casa@prova.it']);

    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk()->json();
    expect($r)->toMatchArray(['persone' => 1, 'messaggi' => 2, 'in_coda' => 2]);
    $righe = lttRighe($s, 'ugo');
    expect($righe)->toHaveCount(2)
        ->and($righe->pluck('destinatario')->sort()->values()->all())->toEqual(collect([$s['persone']['ugo']->email, 'ugo.casa@prova.it'])->sort()->values()->all())
        ->and($righe->pluck('dati.lettera')->unique())->toHaveCount(1)
        ->and($righe->pluck('dati.documento_id')->unique())->toHaveCount(1);
    $documento = DB::table('documenti')->where('id', $righe[0]->dati['documento_id'])->first();
    $conservato = Storage::disk('local')->get($documento->path);
    expect($documento->documentable_type)->toBe(RegistroInvio::class)
        ->and(DB::table('documenti')->where('documentable_type', RegistroInvio::class)->count())->toBe(1)
        ->and($righe->pluck('impronta')->unique()->all())->toBe([hash('sha256', $conservato)]);

    // Il lavoro parte due volte (un nuovo tentativo): niente secondo PDF, e l'allegato è il file conservato.
    $lavori = Queue::pushed(SendQueuedNotifications::class);
    expect($lavori)->toHaveCount(2);
    $lavori->first()->handle(app(ChannelManager::class));
    $lavori->first()->handle(app(ChannelManager::class));
    $messaggi = app('mailer')->getSymfonyTransport()->messages();
    $allegato = $messaggi[0]->getOriginalMessage()->getAttachments()[0];
    expect(DB::table('documenti')->where('documentable_type', RegistroInvio::class)->count())->toBe(1)
        ->and(hash('sha256', $allegato->getBody()))->toBe(hash('sha256', $conservato));

    // Scaricato dal registro, il file ha l'impronta della riga.
    $scaricato = $this->actingAs($this->user)->get(lttRotta($s, 'pdf', ['invio' => $righe[0]->id]))->assertOk()->streamedContent();
    expect(hash('sha256', $scaricato))->toBe($righe[0]->fresh()->impronta);

    // Il promemoria della rata 4 (05/04, in scadenza nei prossimi 30 giorni): nessun documento.
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['tipo' => 'promemoria', 'persone' => [$s['persone']['bice']->id], 'testo' => 'Promemoria']))->assertOk();
    $promemoria = lttRighe($s, 'bice')->sole();
    expect($promemoria->tipo)->toBe('promemoria')
        ->and(collect($promemoria->dati['righe'])->pluck('numero_rata')->all())->toBe([4])
        ->and($promemoria->dati)->not->toHaveKey('documento_id')
        ->and(lttDocumentiDellaRiga($promemoria))->toHaveCount(0);
});

it('la guardia alla partenza: se il debito della lettera cambia fra l\'accodamento e la partenza il messaggio non parte, e «riprova» rifà la lettera con le cifre di oggi in una riga nuova', function (string $variante) {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = lttScenario($this);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk();
    $lavoro = Queue::pushed(SendQueuedNotifications::class)->sole();

    // Prima della partenza: Ugo versa tutto (€ 180,00), o una rata sola (€ 60,00 sulla rata 1); oppure l'emissione della rata 3 è annullata.
    $this->travelTo('2026-03-20 15:00:00');
    match ($variante) {
        'versa tutto' => lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 6000, lcdQuota($s, 'ugo', 2) => 6000, lcdQuota($s, 'ugo', 3) => 6000], '2026-03-20'),
        'versa una rata' => lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 6000], '2026-03-20'),
        'emissione annullata' => $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $s['piano'], lcdRata($s, 3)])),
    };
    $lavoro->handle(app(ChannelManager::class));

    $riga = lttRighe($s, 'ugo')->sole();
    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($riga->stato)->toBe('non_partita')
        ->and($riga->errore)->toBe(LetteraNotification::CAMBIATO_PRIMA_DELL_INVIO)
        // È una frase del programma: si legge com'è, non come un rifiuto del server di posta.
        ->and(ErroreDiPosta::frase($riga->errore))->toBe(LetteraNotification::CAMBIATO_PRIMA_DELL_INVIO);

    // «Riprova»: una riga nuova con le cifre di oggi; quella di prima resta com'è. Senza niente da scrivere, niente.
    $esito = $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'), [])->assertOk()->json();
    $righe = lttRighe($s, 'ugo');
    if ($variante === 'versa tutto') {
        expect($righe)->toHaveCount(1)->and($esito['saldate'])->toBe(1);
    } else {
        expect($righe)->toHaveCount(2)
            ->and($righe[0]->fresh()->stato)->toBe('non_partita')
            ->and($righe[1]->stato)->toBe('in_coda')
            ->and($righe[1]->dati['totale'])->toBe(12000)
            ->and($righe[1]->dati['lettera'])->not->toBe($righe[0]->dati['lettera'])
            ->and($righe[1]->dati['testo'])->toContain('€ 120,00');
    }
})->with(['versa tutto', 'versa una rata', 'emissione annullata']);

it('un sollecito in coda da due ore non è «una ricevuta in coda» nella pagina delle ricevute', function () {
    $s = lttScenario($this);
    $riga = lttRigaScritta($s, 'ugo', 'sollecito', 'in_coda', 0, 'email');
    $riga->forceFill(['emesso_il' => now()->subHours(2)])->save();

    $p = $this->actingAs($this->user)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];
    expect($p['in_coda_da_tempo'])->toBe(0);
});

it('due persone: il testo scritto nella lettera di Ugo non è in quella di Bice; nessuna lettera salva un modello: lo salva «Salva come modello», con il permesso', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'testo' => 'Caro Ugo, ne abbiamo parlato al telefono.']))->assertOk();
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['bice']->id], 'testo' => 'Gentile [nome], restano [totale].']))->assertOk();

    $ugo = lttRighe($s, 'ugo')->sole();
    $bice = lttRighe($s, 'bice')->sole();
    $html = View::make('pdf.gestionale.lettera', app(EmissioneLettere::class)->datiDellaVista($s['c'], [app(EmissioneLettere::class)->voceDallaRiga($bice)]))->render();
    expect($ugo->dati['testo'])->toBe('Caro Ugo, ne abbiamo parlato al telefono.')
        ->and($bice->dati['testo'])->toBe('Gentile Bice, restano € 80,00.')
        ->and($html)->toContain('Gentile Bice, restano € 80,00.')
        ->and($html)->not->toContain('ne abbiamo parlato');

    // Nessuna emissione salva il testo: un modello si salva solo con il gesto.
    $o = $this->actingAs($this->user)->getJson(lttRotta($s, 'opzioni'))->assertOk()->json();
    expect($o['testi']['sollecito'])->toBe(\App\Services\Gestionale\Lettere\TestiLettere::DI_SERIE['sollecito'])
        ->and($o['modelli'])->toBe([])
        ->and(\App\Models\ModelloTesto::count())->toBe(0);

    $modello = ['tipo' => 'sollecito', 'nome' => 'Sollecito dello studio', 'testo' => 'Il testo dello studio, [nome].', 'per_tutti' => true];
    $this->actingAs($this->user)->postJson(lttRotta($s, 'modelli.store'), $modello)->assertForbidden();
    $this->user->givePermissionTo(Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    $this->actingAs($this->user->fresh())->postJson(lttRotta($s, 'modelli.store'), $modello)->assertCreated();
    $o = $this->actingAs($this->user->fresh())->getJson(lttRotta($s, 'opzioni'))->assertOk()->json();
    expect(collect($o['modelli'])->map(fn ($m) => [$m['tipo'], $m['nome'], $m['testo']])->all())->toBe([['sollecito', 'Sollecito dello studio', 'Il testo dello studio, [nome].']])
        // I testi del programma restano quelli del programma.
        ->and($o['testi']['sollecito'])->toBe(\App\Services\Gestionale\Lettere\TestiLettere::DI_SERIE['sollecito'])
        ->and($o['testi']['diffida'])->toBe(\App\Services\Gestionale\Lettere\TestiLettere::DI_SERIE['diffida']);
});

it('le persone dell\'anteprima sono il confine dell\'invio: chi entra nel filtro dopo non riceve la lettera, e la risposta lo dice', function () {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = lttScenario($this);
    $filtri = ['fascia' => '31_90'];
    $a = $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s, ['persone' => null, 'tutte' => 1, 'filtri' => $filtri]))->assertOk()->json();
    // Al 20/03 hanno qualcosa fra 31 e 90 giorni Ugo (rate 1 e 2) e Bice (rata 2): nell'ordine della busta, per nome.
    expect(collect($a['persone'])->pluck('nome')->all())->toBe(['Bice', 'Ugo']);

    // Fra l'anteprima e l'invio entra Carla, con una rata emessa e scaduta da 40 giorni.
    $carla = Anagrafica::create(['nome' => 'Carla', 'email' => 'carla@prova.it', 'indirizzo' => 'Via Roma 3', 'codice_fiscale' => 'CRLXXX00000000001']);
    $carla->condomini()->attach($s['c']->id);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $carla->id, 'immobile_id' => $s['immobili']['B']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
    $quota = DB::table('rate_quote')->where('rata_id', lcdRata($s, 2))->where('anagrafica_id', $s['persone']['bice']->id)->first();
    DB::table('rate_quote')->where('id', $quota->id)->update(['anagrafica_id' => $carla->id]);
    DB::table('righe_scritture')->where('rata_id', lcdRata($s, 2))->where('anagrafica_id', $s['persone']['bice']->id)->update(['anagrafica_id' => $carla->id]);

    $viste = collect($a['persone'])->pluck('id')->all();
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => null, 'tutte' => 1, 'filtri' => $filtri, 'viste' => $viste]))->assertOk()->json();
    expect($r['aggiunte'])->toBe(1)
        ->and(RegistroInvio::where('anagrafica_id', $carla->id)->count())->toBe(0)
        ->and(lttRighe($s, 'ugo'))->toHaveCount(1);
});

it('la persona cancellata dopo la lettera: la riga resta con il suo nome nell\'istantanea e il PDF conservato si scarica ancora', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk();
    $riga = lttRighe($s, 'ugo')->sole();

    // La persona sparisce con tutto quello che la nomina; il registro resta (la chiave va a nullo).
    $ugo = $s['persone']['ugo']->id;
    $quote = DB::table('rate_quote')->where('anagrafica_id', $ugo)->pluck('id');
    DB::table('quota_scrittura')->whereIn('rate_quota_id', $quote)->delete();
    DB::table('rate_quote')->whereIn('id', $quote)->delete();
    DB::table('righe_scritture')->where('anagrafica_id', $ugo)->update(['anagrafica_id' => null]);
    DB::table('anagrafica_immobile')->where('anagrafica_id', $ugo)->delete();
    DB::table('anagrafica_condominio')->where('anagrafica_id', $ugo)->delete();
    DB::table('anagrafiche')->where('id', $ugo)->delete();

    $riga = RegistroInvio::find($riga->id);
    expect($riga)->not->toBeNull()
        ->and($riga->anagrafica_id)->toBeNull()
        ->and($riga->dati['persona']['nome'])->toBe('Ugo');
    $this->actingAs($this->user)->get(lttRotta($s, 'pdf', ['invio' => $riga->id]))->assertOk();
});

it('il .zip per persona: i PDF conservati e l\'elenco degli indirizzi con il titolo delle lettere; una riga «stampata» per lettera', function () {
    $s = lttScenario($this);
    $r = $this->actingAs($this->user)->post(lttRotta($s, 'pdf-per-persona'), lttScelte($s))->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/zip');
    $percorso = tempnam(sys_get_temp_dir(), 'ltt');
    file_put_contents($percorso, $r->getContent());
    $zip = new ZipArchive;
    $zip->open($percorso);
    $nomi = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nomi[] = $zip->getNameIndex($i);
    }
    $elenco = $zip->getFromName('Indirizzi email.txt');
    $ugo = lttRighe($s, 'ugo')->sole();
    $documento = DB::table('documenti')->where('id', $ugo->dati['documento_id'])->first();
    expect($nomi)->toContain('Ugo.pdf')->toContain('Bice.pdf')->toContain('Indirizzi email.txt')
        ->and($elenco)->toContain('Solleciti — ')->toContain('Ugo — 1 lettera — Ugo.pdf')
        ->and($ugo->canale)->toBe('stampa')->and($ugo->stato)->toBe('stampata')
        ->and(hash('sha256', $zip->getFromName('Ugo.pdf')))->toBe(hash('sha256', Storage::disk('local')->get($documento->path)));
    $zip->close();
    @unlink($percorso);
});

it('«mandane una a me»: il messaggio della prima persona all\'indirizzo di chi lavora, con PROVA in testa, senza righe nel registro', function () {
    config(['mail.default' => 'array']);
    $s = lttScenario($this);
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'prova'), lttScelte($s))->assertOk()->json();
    $messaggi = app('mailer')->getSymfonyTransport()->messages();
    expect($r['indirizzo'])->toBe('studio@prova.it')
        ->and($messaggi)->toHaveCount(1)
        ->and($messaggi[0]->getOriginalMessage()->getSubject())->toStartWith('Prova — ')
        ->and(RegistroInvio::whereIn('tipo', EmissioneLettere::TIPI)->count())->toBe(0);
});

it('le rotte: una persona di un altro condominio è un 404 per tutta la richiesta; un condòmino non apre le lettere', function () {
    $s = lttScenario($this);
    $altro = Anagrafica::create(['nome' => 'Estraneo', 'indirizzo' => 'Via Altrove 1', 'codice_fiscale' => 'STRXXX00000000001']);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id, $altro->id]]))->assertNotFound();
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$altro->id]]))->assertNotFound();
    expect(RegistroInvio::count())->toBe(0);

    $condomino = User::factory()->create();
    $this->actingAs($condomino)->postJson(lttRotta($s, 'anteprima'), lttScelte($s))->assertForbidden();

    // Una riga del registro di un altro condominio: 404.
    $t = lttScenario($this);
    $riga = lttRigaScritta($t, 'ugo', 'sollecito', 'stampata', 1);
    $this->actingAs($this->user)->get(lttRotta($s, 'pdf', ['invio' => $riga->id]))->assertNotFound();
});

it('l\'altro indirizzo solo nella lettera di una persona, con il limite di dieci al minuto; la frase del limite non dice «ricevute»', function () {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = lttScenario($this);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['altro_indirizzo' => 'avvocato@prova.it']))->assertStatus(422);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'altro_indirizzo' => 'avvocato@prova.it']))->assertOk();
    expect(lttRighe($s, 'ugo')->pluck('destinatario'))->toContain('avvocato@prova.it')
        ->and(\App\Services\Gestionale\Ricevute\LimiteAltroIndirizzo::FRASE)->not->toContain('ricevut');
});

it('la lettera: lo studio in testa se c\'è, il condominio, il destinatario, la tabella con il residuo e i giorni, la situazione al giorno, il termine, l\'IBAN; per diffida e messa in mora lo spazio della firma', function () {
    $s = lttScenario($this);
    $e = app(EmissioneLettere::class);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk();
    $voce = $e->voceDallaRiga(lttRighe($s, 'ugo')->sole());
    $html = View::make('pdf.gestionale.lettera', $e->datiDellaVista($s['c'], [$voce]))->render();
    expect($html)->toContain('Sollecito')->toContain('Ugo')->toContain('Via Roma 1')
        ->toContain('€ 180,00')->toContain('74 giorni')->not->toContain('in ritardo di 74 giorni')
        ->toContain('Situazione al 20/03/2026: i versamenti registrati dopo non sono compresi.')
        ->toContain('15 giorni')->toContain('IT60X0542811101000000123456')
        ->not->toContain('Firma dell\'amministratore');

    $settings = app(\App\Settings\PrintSettings::class);
    $settings->studio_nome = 'Studio Bianchi';
    $settings->studio_pec = 'studio@pec.it';
    $settings->save();
    $diffida = ['tipo' => 'diffida', 'titolo' => EmissioneLettere::NOMI['diffida']] + $e->voceDallaRiga(lttRighe($s, 'ugo')->sole());
    $html = View::make('pdf.gestionale.lettera', $e->datiDellaVista($s['c'], [$diffida]))->render();
    expect($html)->toContain('Studio Bianchi')->toContain('studio@pec.it')->toContain('Diffida')->toContain('Firma dell\'amministratore');
});
