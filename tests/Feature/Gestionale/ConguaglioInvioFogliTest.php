<?php

/**
 * L'invio dei fogli del conguaglio per email, sul caso di Via Verdi: l'anteprima di chi riceve che cosa (gli indirizzi, chi non ne ha uno, il testo
 * dell'email, l'ora del calcolo, il tetto), l'email (un foglio per persona, il PDF del foglio allegato, a ogni indirizzo scelto di quella persona e
 * mai a un'altra), il registro con il testo usato e l'ora del calcolo, il PDF conservato, l'ultimo invio accanto a ogni persona e il rinvio di un
 * invio non partito.
 *
 * Le cifre dei fogli sono quelle di `RendicontoConguaglioTest`, rifatte a mano nel suo docblock: Neri A € 2.100,00, conguaglio +100,00; Bianchi
 * (181 giorni) A 624,82, +29,75; Rossi (184 giorni) A 635,18, +30,25; Verdi A 840,00 + 80,00 di spese personali, +120,00. L'inquilino (le cifre di
 * `ConsuntivoPerPersonaAltreGestioniTest`, «l'inquilino uscito nell'anno»): Gialli su U1 fino al 30/06, il 30 % delle voci, A 312,42, conguaglio
 * +14,89; Neri A 1.787,58, +85,11.
 *
 * Nessuna email vera: la coda è finta, o il trasporto è `array`, o un trasporto di prova che rifiuta un indirizzo come un server che non lo conosce.
 */

use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Gestionale\InvioDeiFogli;
use App\Services\PDF\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__ . '/ConguaglioMisuraScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['mail.default' => 'array']);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
    $this->withoutVite();
});

/**
 * Via Verdi intero (le rate e gli incassi, il passaggio di U2 da Bianchi a Rossi, le spese, la spesa personale di U3), con gli indirizzi
 * dell'invio: Neri ha anche la secondaria, Verdi ha solo la PEC.
 */
function ifcViaVerdi($test, array $opz = []): array
{
    $s = cpcViaVerdi($test, $opz);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    cpcSpese($s);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $s['persone']['Neri']->forceFill(['email_secondaria' => 'neri.casa@prova.it'])->save();
    $s['persone']['Verdi']->forceFill(['email' => null, 'pec' => 'verdi@pec.prova.it'])->save();

    return $s;
}

function ifcRotta(array $s, string $nome = '', array $altro = []): string
{
    return route('admin.gestionale.esercizi.rendiconto.conguaglio.invio' . ($nome === '' ? '' : '.' . $nome), ['condominio' => $s['c']->id, 'esercizio' => $s['e']->id] + $altro);
}

/** Le scelte della finestra: tutte le persone, la primaria e la secondaria, l'oggetto e il testo del programma. */
function ifcScelte(array $altro = []): array
{
    return array_merge(['chi' => 'tutte', 'indirizzi' => ['email', 'email_secondaria'], 'oggetto' => InvioDeiFogli::OGGETTO_DI_SERIE, 'testo' => InvioDeiFogli::TESTO_DI_SERIE], $altro);
}

/** Le righe del registro dei fogli, di una persona o di tutte. */
function ifcRighe(array $s, ?string $chi = null): \Illuminate\Support\Collection
{
    return RegistroInvio::where('condominio_id', $s['c']->id)->where('tipo', InvioDeiFogli::TIPO)
        ->when($chi !== null, fn ($q) => $q->where('anagrafica_id', $s['persone'][$chi]->id))->orderBy('id')->get();
}

/** Un servizio dei PDF che tiene i dati di ogni foglio che stampa, nell'ordine. */
function ifcSpia(): PdfService
{
    $spia = new class extends PdfService
    {
        public array $fogli = [];

        public function generate(string $view, array $data = [], array $config = []): \Mpdf\Mpdf
        {
            if ($view === 'pdf.gestionale.foglio_conguaglio') {
                $this->fogli[] = $data['foglio'];
            }

            return parent::generate($view, $data, $config);
        }
    };
    app()->instance(PdfService::class, $spia);

    return $spia;
}

/** Un server di posta che rifiuta un indirizzo, come uno che non lo conosce («550 User unknown»), e accetta gli altri. */
function ifcPostaCheRifiuta(string $rifiutato): void
{
    Mail::extend('rifiuta_fogli', fn () => new class($rifiutato) extends AbstractTransport
    {
        public function __construct(private string $rifiutato)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            foreach ($message->getEnvelope()->getRecipients() as $a) {
                if ($a->getAddress() === $this->rifiutato) {
                    throw new TransportException('Expected response code "250" but got code "550", with message "550 5.1.1 User unknown".');
                }
            }
        }

        public function __toString(): string
        {
            return 'rifiuta_fogli';
        }
    });
    config(['mail.mailers.rifiuta_fogli' => ['transport' => 'rifiuta_fogli'], 'mail.default' => 'rifiuta_fogli']);
    app('mail.manager')->forgetMailers();
}

/** Il testo di un messaggio partito, senza i tag e con gli apostrofi veri. */
function ifcTesto(\Symfony\Component\Mime\Email $email): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $email->getHtmlBody()), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** Le parole che il foglio e la sua email non dicono mai: il conguaglio è una proposta da approvare, non una richiesta di pagamento. */
const IFC_PAROLE_VIETATE = '/\b(dovut[oaie]|esigibil[ei]|da pagare entro|pront[oaie]|conform[ei])\b/i';

it('l\'anteprima dice chi riceve il foglio e a quali indirizzi, chi non ne ha uno e che cosa fare, il testo dell\'email riempito, l\'ora del calcolo e il tetto', function () {
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $a = $this->actingAs($this->user)->postJson(ifcRotta($s, 'anteprima'), ifcScelte())->assertOk()->json();
    $persona = fn (string $chi) => collect($a['persone'])->firstWhere('id', $s['persone'][$chi]->id);
    $nome = (string) $s['c']->nome;

    expect(array_column($a['persone'], 'nome'))->toBe(['Bianchi', 'Neri', 'Rossi', 'Verdi'])
        ->and(array_column($persona('Neri')['indirizzi'], 'indirizzo'))->toBe([$s['persone']['Neri']->email, 'neri.casa@prova.it'])
        ->and(array_column($persona('Bianchi')['indirizzi'], 'indirizzo'))->toBe([$s['persone']['Bianchi']->email])
        // La PEC non è proposta: Verdi, che ha solo quella, resta senza indirizzo fra quelli scelti, con che cosa fare.
        ->and($persona('Verdi')['indirizzi'])->toBe([])
        ->and($persona('Verdi')['solo_pec'])->toBeTrue()
        ->and($a['senza_indirizzo'])->toBe(['Verdi'])
        ->and($a['messaggi'])->toBe(4)
        ->and($a['tetto_messaggi'])->toBe(50)
        ->and($a['tetto_fogli'])->toBe(200)
        ->and($a['partono'])->toBe(['persone' => 3, 'messaggi' => 4])
        ->and($a['senza_server_posta'])->toBeFalse()
        ->and($a['data']['finito'])->toBeTrue()
        ->and($a['data']['calcolato_il'])->toBe('10/01/2027 alle 11:00')
        // Il messaggio della prima persona, con i campi riempiti.
        ->and($a['esempio']['persona'])->toBe('Bianchi')
        ->and($a['esempio']['oggetto'])->toBe($nome . ': la sua parte delle spese e la proposta di conguaglio, Esercizio 2026')
        ->and($a['esempio']['saluto'])->toBe('Gentile Bianchi,')
        ->and(implode(' ', $a['esempio']['righe']))->toContain('Esercizio 2026')->toContain('proposta di conguaglio, da approvare dall\'assemblea')
        ->toContain('non è una richiesta di pagamento')
        ->toContain('Le cifre sono quelle al 31/12/2026, alla fine dell\'esercizio, calcolate il 10/01/2027 alle 11:00.')
        ->and(preg_match(IFC_PAROLE_VIETATE, json_encode($a['esempio'], JSON_UNESCAPED_UNICODE)))->toBe(0);

    // Con la PEC spuntata Verdi la riceve lì.
    $conPec = $this->actingAs($this->user)->postJson(ifcRotta($s, 'anteprima'), ifcScelte(['indirizzi' => ['email', 'pec']]))->assertOk()->json();
    expect(array_column(collect($conPec['persone'])->firstWhere('nome', 'Verdi')['indirizzi'], 'indirizzo'))->toBe(['verdi@pec.prova.it'])
        ->and($conPec['senza_indirizzo'])->toBe([]);
});

it('l\'invio: un foglio per persona, il PDF conservato e allegato a ogni suo indirizzo e mai a un altro; il registro con il testo usato e l\'ora del calcolo; chi è uscito nell\'anno ha il suo foglio', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $spia = ifcSpia();
    $r = $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte())->assertOk()->json();

    expect($r)->toMatchArray(['persone' => 3, 'messaggi' => 4, 'in_coda' => 4, 'non_partite' => 0, 'senza_indirizzo' => ['Verdi'], 'restanti' => null]);
    $righe = ifcRighe($s);
    expect($righe->pluck('destinatario')->all())->toBe([$s['persone']['Bianchi']->email, $s['persone']['Neri']->email, 'neri.casa@prova.it', $s['persone']['Rossi']->email])
        ->and($righe->pluck('stato')->unique()->all())->toBe(['in_coda'])
        ->and(ifcRighe($s, 'Verdi'))->toHaveCount(0);

    // Neri: due righe, un foglio solo (lo stesso PDF conservato, la stessa impronta).
    $neri = ifcRighe($s, 'Neri');
    $documento = DB::table('documenti')->where('id', $neri[0]->dati['documento_id'])->first();
    $conservato = Storage::disk('local')->get($documento->path);
    expect($neri->pluck('dati.documento_id')->unique())->toHaveCount(1)
        ->and($neri->pluck('dati.foglio')->unique())->toHaveCount(1)
        ->and($neri->pluck('impronta')->unique()->all())->toBe([hash('sha256', $conservato)])
        ->and($documento->documentable_type)->toBe(RegistroInvio::class)
        ->and(DB::table('documenti')->where('documentable_type', RegistroInvio::class)->count())->toBe(3);

    // Il registro tiene il testo usato, l'oggetto, l'esercizio e l'ora del calcolo delle cifre.
    $dati = $neri[0]->dati;
    expect($neri[0]->documentabile_type)->toBe(\App\Models\Esercizio::class)
        ->and($neri[0]->documentabile_id)->toBe($s['e']->id)
        ->and($dati['esercizio_id'])->toBe($s['e']->id)
        ->and($dati['alla'])->toBe('2026-12-31')->and($dati['finito'])->toBeTrue()
        ->and($dati['calcolato_il'])->toBe('10/01/2027 alle 11:00')
        ->and($dati['testo_modello'])->toBe(InvioDeiFogli::TESTO_DI_SERIE)
        ->and($dati['testo'])->toContain('Esercizio 2026')->not->toContain('[esercizio]')
        ->and($dati['oggetto'])->toBe($s['c']->nome . ': la sua parte delle spese e la proposta di conguaglio, Esercizio 2026')
        ->and($dati['cifre'])->toMatchArray(['a' => 210000, 'conguaglio' => 10000, 'saldo' => 40000]);

    // Chi è uscito nell'anno ha il suo foglio, con i suoi giorni; chi è entrato il suo.
    $foglio = fn (string $chi) => collect($spia->fogli)->firstWhere('persona.id', $s['persone'][$chi]->id);
    expect($foglio('Bianchi')['gestioni'][0]['unita'][0])->toMatchArray(['unita' => 'Int. U2', 'giorni' => 181, 'a' => 62482])
        ->and($foglio('Rossi')['gestioni'][0]['unita'][0])->toMatchArray(['unita' => 'Int. U2', 'giorni' => 184, 'a' => 63518])
        ->and(count($spia->fogli))->toBe(3);

    // I messaggi partono cinque ore dopo, quando una spesa nuova ha cambiato il foglio di Neri: l'allegato è il foglio di quando è stato inviato,
    // quello conservato, non uno rifatto. Uno per indirizzo, ognuno con il foglio della sua persona; le righe diventano «inviata».
    $this->travelTo('2027-01-10 15:00:00');
    cpcSpesaPersonale($s, 'U1', 5000, '2026-11-10');
    foreach (Queue::pushed(SendQueuedNotifications::class) as $lavoro) {
        $lavoro->handle(app(ChannelManager::class));
    }
    $messaggi = collect(app('mailer')->getSymfonyTransport()->messages())->map->getOriginalMessage();
    $a = fn (string $indirizzo) => $messaggi->first(fn ($m) => $m->getTo()[0]->getAddress() === $indirizzo);
    $perNeri = $a('neri.casa@prova.it');
    expect($messaggi)->toHaveCount(4)
        ->and($messaggi->map(fn ($m) => count($m->getTo()))->unique()->all())->toBe([1])
        ->and(hash('sha256', $perNeri->getAttachments()[0]->getBody()))->toBe(hash('sha256', $conservato))
        ->and($perNeri->getAttachments()[0]->getFilename())->toBe(PdfService::nomeFile('conguaglio', $s['c']->nome, '2026', 'neri'))
        ->and($perNeri->getSubject())->toBe($dati['oggetto'])
        ->and(ifcTesto($perNeri))->toContain('Gentile Neri,')->toContain('proposta di conguaglio, da approvare dall\'assemblea')
        ->toContain('calcolate il 10/01/2027 alle 11:00')
        ->and(preg_match(IFC_PAROLE_VIETATE, ifcTesto($perNeri)))->toBe(0)
        // Il foglio di Bianchi va a Bianchi, non a Rossi che ha l'unità oggi.
        ->and(hash('sha256', $a($s['persone']['Bianchi']->email)->getAttachments()[0]->getBody()))->toBe(ifcRighe($s, 'Bianchi')->sole()->impronta)
        ->and(ifcRighe($s)->pluck('stato')->unique()->all())->toBe(['inviata']);
});

it('la persona con due unità ha un foglio solo, con le due unità; le cifre del foglio inviato sono quelle del foglio scaricato', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    // Neri compra anche U3 a metà con Verdi dal 2020: due unità di Neri.
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['U3']->id)->update(['quota' => 50]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Neri']->id, 'immobile_id' => $s['unita']['U3']->id, 'tipologia' => 'proprietario', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $this->travelTo('2027-01-10 10:00:00');
    $spia = ifcSpia();
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email']]))->assertOk();
    $inviato = collect($spia->fogli)->firstWhere('persona.id', $s['persone']['Neri']->id);

    expect(ifcRighe($s, 'Neri'))->toHaveCount(1)
        ->and(array_column($inviato['gestioni'][0]['unita'], 'unita'))->toBe(['Int. U1', 'Int. U3']);

    $spia->fogli = [];
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.foglio', [$s['c'], $s['e'], $s['persone']['Neri']->id]))->assertOk();
    expect($spia->fogli)->toHaveCount(1)
        ->and($spia->fogli[0])->toBe($inviato);
});

it('l\'inquilino riceve il suo foglio, non quello del proprietario, e il proprietario il suo', function () {
    Queue::fake();
    $s = cpcViaVerdi($this, ['inquilino' => ['U1', 'Gialli', 30, '2020-01-01', '2026-06-30']]);
    cpcRateEIncassi($this, $s, [1, 2, 3, 4], []);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $spia = ifcSpia();
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte())->assertOk();
    $foglio = fn (string $chi) => collect($spia->fogli)->firstWhere('persona.id', $s['persone'][$chi]->id);

    expect(ifcRighe($s, 'Gialli')->sole()->destinatario)->toBe($s['persone']['Gialli']->email)
        ->and(ifcRighe($s, 'Neri')->sole()->destinatario)->toBe($s['persone']['Neri']->email)
        ->and($foglio('Gialli')['gestioni'][0]['unita'][0])->toMatchArray(['unita' => 'Int. U1', 'giorni' => 181, 'a' => 31242, 'conguaglio' => 1489])
        ->and($foglio('Neri')['gestioni'][0]['unita'][0])->toMatchArray(['unita' => 'Int. U1', 'a' => 178758, 'conguaglio' => 8511])
        // Il PDF di ogni riga è il foglio della sua persona.
        ->and(ifcRighe($s, 'Gialli')->sole()->dati['persona']['id'])->toBe($s['persone']['Gialli']->id)
        ->and(ifcRighe($s, 'Gialli')->sole()->dati['cifre'])->toMatchArray(['a' => 31242, 'conguaglio' => 1489]);
});

it('per l\'esercizio aperto l\'email e il registro dicono «a oggi» e l\'ora del calcolo', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $this->travelTo('2026-12-15 10:00:00');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email']]))->assertOk();
    $riga = ifcRighe($s, 'Bianchi')->sole();

    expect($riga->dati['alla'])->toBe('2026-12-15')
        ->and($riga->dati['finito'])->toBeFalse()
        ->and($riga->dati['calcolato_il'])->toBe('15/12/2026 alle 11:00');

    Queue::pushed(SendQueuedNotifications::class)->first()->handle(app(ChannelManager::class));
    $email = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
    expect(ifcTesto($email))->toContain('L\'esercizio è ancora aperto: le cifre sono quelle a oggi, calcolate il 15/12/2026 alle 11:00, '
        . 'e possono cambiare con le registrazioni che seguono.');
});

it('senza il servizio della coda partono al massimo cinquanta messaggi per volta, a persone intere: l\'anteprima lo dice prima, e la risposta dice quante restano', function () {
    Queue::fake();
    config(['queue.default' => 'sync']);
    $this->travelTo('2027-01-10 10:00:00');
    // 55 unità, una su dieci passa di mano il 01/07: 61 persone, una email ciascuna, tutte a debito (nessun piano).
    $s = cgmCondominio(false, 0, 55, 1);
    $rotta = fn (string $nome = '') => route('admin.gestionale.esercizi.rendiconto.conguaglio.invio' . ($nome === '' ? '' : '.' . $nome), [$s['c'], $s['e']]);
    $a = $this->actingAs($this->user)->postJson($rotta('anteprima'), ifcScelte(['indirizzi' => ['email']]))->assertOk()->json();
    expect($s['persone'])->toBe(61)
        ->and($a['messaggi'])->toBe(61)
        ->and($a['partono'])->toBe(['persone' => 50, 'messaggi' => 50]);

    $r = $this->actingAs($this->user)->postJson($rotta(), ifcScelte(['indirizzi' => ['email'], 'viste' => array_column($a['persone'], 'id')]))->assertOk()->json();
    expect(Queue::pushed(SendQueuedNotifications::class))->toHaveCount(50)
        ->and($r['messaggi'])->toBe(50)
        ->and(count($r['restanti']['persone']))->toBe(11)
        ->and($r['restanti']['messaggi'])->toBe(11)
        ->and($r['restanti']['persone'])->toBe(array_slice(array_column($a['persone'], 'id'), 50));

    // «Invia le altre»: le undici che restano, e nessuna due volte.
    $poi = $this->actingAs($this->user)->postJson($rotta(), ifcScelte(['indirizzi' => ['email'], 'viste' => $r['restanti']['persone']]))->assertOk()->json();
    expect(Queue::pushed(SendQueuedNotifications::class))->toHaveCount(61)
        ->and($poi['restanti'])->toBeNull()
        ->and(RegistroInvio::where('tipo', InvioDeiFogli::TIPO)->distinct()->count('anagrafica_id'))->toBe(61);
});

it('un indirizzo che il server rifiuta: il foglio non parte, l\'errore si legge in chiaro accanto alla persona, e il rinvio lo rifà con le cifre di oggi, una volta', function () {
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    ifcPostaCheRifiuta('neri.casa@prova.it');
    $r = $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte())->assertOk()->json();

    expect($r)->toMatchArray(['messaggi' => 4, 'inviate' => 3, 'non_partite' => 1])
        ->and($r['errori'])->toBe(['Il server di posta non ha accettato il messaggio (codice 550).']);
    $rifiutata = ifcRighe($s, 'Neri')->firstWhere('destinatario', 'neri.casa@prova.it');
    expect($rifiutata->stato)->toBe('non_partita');

    // Nella scheda, accanto a Neri: l'ultimo invio, con la riga non partita e la frase; il conto delle persone da rimandare.
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    $ultimo = $props['invio_fogli']['ultimi'][$s['persone']['Neri']->id];
    expect($ultimo['stato'])->toBe('in_parte')
        ->and($ultimo['quando'])->toBe('2027-01-10 11:00')
        ->and(collect($ultimo['righe'])->firstWhere('destinatario', 'neri.casa@prova.it')['errore'])->toBe('Il server di posta non ha accettato il messaggio (codice 550).')
        ->and($ultimo['da_rimandare'])->toBeTrue()
        ->and($props['invio_fogli']['ultimi'][$s['persone']['Bianchi']->id]['stato'])->toBe('inviato')
        ->and($props['invio_fogli']['da_rimandare'])->toBe(1);

    // Il server ora accetta: il rinvio di Neri rifà il foglio, solo all'indirizzo non partito, in una riga nuova; la riga di prima resta.
    config(['mail.default' => 'array']);
    app('mail.manager')->forgetMailers();
    $this->travelTo('2027-01-11 09:00:00');
    $di = $this->actingAs($this->user)->postJson(ifcRotta($s, 'riprova'), ['anagrafica_id' => $s['persone']['Neri']->id])->assertOk()->json();
    $righe = ifcRighe($s, 'Neri');
    expect($di)->toMatchArray(['fogli' => 1, 'messaggi' => 1, 'inviate' => 1])
        ->and($righe)->toHaveCount(3)
        ->and($righe[2]->destinatario)->toBe('neri.casa@prova.it')
        ->and($righe[2]->stato)->toBe('inviata')
        ->and($righe[2]->dati['rifatto_da'])->toBe($rifiutata->id)
        ->and($righe[2]->dati['calcolato_il'])->toBe('11/01/2027 alle 10:00')
        ->and($righe[2]->dati['testo'])->toBe($rifiutata->dati['testo'])
        ->and($rifiutata->fresh()->stato)->toBe('non_partita');

    // Una seconda volta non rifà niente: l'invio di prima è già stato rimandato.
    $ancora = $this->actingAs($this->user)->postJson(ifcRotta($s, 'riprova'), [])->assertOk()->json();
    expect($ancora['fogli'])->toBe(0)->and(ifcRighe($s, 'Neri'))->toHaveCount(3);
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    expect($props['invio_fogli']['da_rimandare'])->toBe(0);

    // Il foglio partito si riapre dal registro: è il file conservato.
    $scaricato = $this->actingAs($this->user)->get(ifcRotta($s, 'pdf', ['invio' => $righe[2]->id]))->assertOk()->streamedContent();
    expect(hash('sha256', $scaricato))->toBe($righe[2]->impronta);
    // Dall'indirizzo di un altro esercizio dello stesso condominio, no.
    $altroEsercizio = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio.pdf', [$s['c'], $altroEsercizio, $righe[2]->id]))->assertNotFound();
});

it('un errore del programma mentre il messaggio si accoda: la riga è «non partita», con la frase del programma e non come un rifiuto del server', function () {
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    // Una coda che non si apre: il messaggio non si accoda nemmeno.
    config(['queue.connections.chiusa' => ['driver' => 'chiusa'], 'queue.default' => 'chiusa']);
    $r = $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email']]))->assertOk()->json();
    $riga = ifcRighe($s, 'Bianchi')->sole();

    expect($r['non_partite'])->toBe(3)
        ->and($riga->stato)->toBe('non_partita')
        ->and($riga->errore)->toStartWith('Errore del programma: ')
        ->and($r['errori'])->toBe([\App\Services\Gestionale\Ricevute\ErroreDiPosta::FRASE_DEL_PROGRAMMA]);
});

it('chi non entra nel gestionale non manda niente; un esercizio, una persona o una riga del registro di un altro condominio fanno 404 e non scrivono niente', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $altro = cpcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');

    $condomino = User::factory()->create();
    $this->actingAs($condomino)->postJson(ifcRotta($s), ifcScelte())->assertForbidden();
    $this->actingAs($condomino)->postJson(ifcRotta($s, 'anteprima'), ifcScelte())->assertForbidden();
    $this->actingAs($condomino)->getJson(ifcRotta($s, 'opzioni'))->assertForbidden();

    $sbagliato = ['condominio' => $s['c']->id, 'esercizio' => $altro['e']->id];
    foreach (['anteprima', '', 'riprova'] as $nome) {
        $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio' . ($nome === '' ? '' : '.' . $nome), $sbagliato), ifcScelte())->assertNotFound();
    }
    // La persona di un altro condominio fra quelle viste: rifiutata per tutta la richiesta.
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['viste' => [$s['persone']['Neri']->id, $altro['persone']['Neri']->id]]))->assertNotFound();
    $this->actingAs($this->user)->postJson(ifcRotta($s, 'riprova'), ['anagrafica_id' => $altro['persone']['Neri']->id])->assertNotFound();
    expect(ifcRighe($s))->toHaveCount(0)->and(Queue::pushed(SendQueuedNotifications::class))->toHaveCount(0);

    // Una riga del registro di un altro condominio, o un altro documento: 404.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio', [$altro['c'], $altro['e']]), ifcScelte(['indirizzi' => ['email']]))->assertOk();
    $sua = RegistroInvio::where('condominio_id', $altro['c']->id)->where('tipo', InvioDeiFogli::TIPO)->first();
    $this->actingAs($this->user)->get(ifcRotta($s, 'pdf', ['invio' => $sua->id]))->assertNotFound();
});

it('il testo: i campi che l\'email del foglio non ha si rifiutano, l\'oggetto serve; senza server di posta non parte niente; la finestra riparte dal modello, non dal testo scritto a mano', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');

    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['testo' => 'Gentile [nome], il totale è [totale].']))->assertStatus(422)
        ->assertJson(['message' => InvioDeiFogli::CAMPI_CHE_NON_CI_SONO]);
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['oggetto' => '  ']))->assertStatus(422)->assertJson(['message' => InvioDeiFogli::SERVE_L_OGGETTO]);
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => []]))->assertStatus(422)->assertJson(['message' => InvioDeiFogli::SCEGLI_GLI_INDIRIZZI]);
    config(['mail.default' => 'log']);
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte())->assertStatus(422)->assertJson(['message' => InvioDeiFogli::SENZA_SERVER_POSTA]);
    expect(ifcRighe($s))->toHaveCount(0);

    // Di serie: l'oggetto e il testo del programma, nessun modello, la primaria e la secondaria, e nessuna parola che chiede un pagamento.
    config(['mail.default' => 'array']);
    $o = $this->actingAs($this->user)->getJson(ifcRotta($s, 'opzioni'))->assertOk()->json();
    expect($o['oggetto'])->toBe(InvioDeiFogli::OGGETTO_DI_SERIE)
        ->and($o['testo_di_serie'])->toBe(InvioDeiFogli::TESTO_DI_SERIE)
        ->and($o['modelli'])->toBe([])
        ->and($o['modello_ricordato'])->toBeNull()
        ->and($o['frase_proposta'])->toBe(InvioDeiFogli::FRASE_PROPOSTA)
        ->and($o['indirizzi'])->toBe(['email', 'email_secondaria'])
        ->and(array_keys($o['campi']))->toBe(['[nome]', '[condominio]', '[esercizio]'])
        ->and(preg_match(IFC_PAROLE_VIETATE, InvioDeiFogli::TESTO_DI_SERIE . InvioDeiFogli::OGGETTO_DI_SERIE . InvioDeiFogli::FRASE_PROPOSTA))->toBe(0);

    // Dopo un invio con un testo scritto a mano: l'oggetto e gli indirizzi si ricordano; il testo no (come le lettere, si ricorda il modello).
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['oggetto' => 'Il foglio di [nome]', 'testo' => 'Il suo foglio, [nome].', 'indirizzi' => ['email', 'pec']]))->assertOk();
    $o = $this->actingAs($this->user)->getJson(ifcRotta($s, 'opzioni'))->assertOk()->json();
    expect($o['oggetto'])->toBe('Il foglio di [nome]')
        ->and($o['modello_ricordato'])->toBeNull()
        ->and($o['indirizzi'])->toBe(['email', 'pec'])
        ->and(ifcRighe($s, 'Verdi')->sole()->dati['oggetto'])->toBe('Il foglio di Verdi');
});

it('la frase della proposta è in ogni email, fuori dal testo che si cambia: un testo che non la dice non la toglie, e non la ripete', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $a = $this->actingAs($this->user)->postJson(ifcRotta($s, 'anteprima'), ifcScelte(['testo' => 'Il suo foglio.']))->assertOk()->json();
    expect($a['esempio']['righe'])->toBe(['Il suo foglio.', InvioDeiFogli::FRASE_PROPOSTA,
        'Le cifre sono quelle al 31/12/2026, alla fine dell\'esercizio, calcolate il 10/01/2027 alle 11:00.'])
        ->and(InvioDeiFogli::TESTO_DI_SERIE)->not->toContain('richiesta di pagamento');

    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['testo' => 'Il suo foglio.', 'indirizzi' => ['email']]))->assertOk();
    Queue::pushed(SendQueuedNotifications::class)->first()->handle(app(ChannelManager::class));
    $testo = ifcTesto(app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage());
    expect(substr_count($testo, 'proposta di conguaglio, da approvare dall\'assemblea'))->toBe(1)
        ->and(substr_count($testo, 'non è una richiesta di pagamento'))->toBe(1);
});

it('chi è uscito nell\'anno e l\'amministratore ha staccato dal condominio riceve il suo foglio e si rimanda: è nella lettura dell\'esercizio', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $s['persone']['Bianchi']->condomini()->detach($s['c']->id);
    $this->travelTo('2027-01-10 10:00:00');
    $a = $this->actingAs($this->user)->postJson(ifcRotta($s, 'anteprima'), ifcScelte())->assertOk()->json();
    expect(array_column($a['persone'], 'nome'))->toContain('Bianchi');

    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['viste' => array_column($a['persone'], 'id'), 'in_anteprima' => array_column($a['persone'], 'id')]))->assertOk();
    $sua = ifcRighe($s, 'Bianchi')->sole();
    expect($sua->destinatario)->toBe($s['persone']['Bianchi']->email);

    $sua->update(['stato' => 'non_partita', 'errore' => 'x']);
    $r = $this->actingAs($this->user)->postJson(ifcRotta($s, 'riprova'), ['anagrafica_id' => $s['persone']['Bianchi']->id])->assertOk()->json();
    expect($r['fogli'])->toBe(1)->and(ifcRighe($s, 'Bianchi'))->toHaveCount(2);
});

it('lo stato degli invii che la scheda rilegge: l\'ultimo invio delle persone chieste, i non partiti da rimandare, i fogli in coda da più di un\'ora', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte())->assertOk();
    $stato = fn () => $this->actingAs($this->user)->getJson(ifcRotta($s, 'stato') . '?' . http_build_query(['persone' => [$s['persone']['Neri']->id, $s['persone']['Bianchi']->id]]))
        ->assertOk()->json();

    // Dieci minuti dopo: in coda, niente da dire.
    $this->travelTo('2027-01-10 10:10:00');
    $p = $stato();
    expect(array_keys($p['ultimi']))->toEqualCanonicalizing([$s['persone']['Neri']->id, $s['persone']['Bianchi']->id])
        ->and($p['ultimi'][$s['persone']['Neri']->id]['stato'])->toBe('in_coda')
        ->and($p['in_coda_da_tempo'])->toBe(0)->and($p['da_rimandare'])->toBe(0);

    // Due ore dopo: tre fogli in coda da più di un'ora (Neri a due indirizzi è un foglio).
    $this->travelTo('2027-01-10 12:10:00');
    expect($stato()['in_coda_da_tempo'])->toBe(3);

    // Il servizio della coda li manda, e il server rifiuta la secondaria di Neri: lo stato riletto dice «in parte» e uno da rimandare.
    ifcPostaCheRifiuta('neri.casa@prova.it');
    foreach (Queue::pushed(SendQueuedNotifications::class) as $lavoro) {
        try {
            $lavoro->handle(app(ChannelManager::class));
        } catch (\Throwable $e) {
            $lavoro->failed($e);
        }
    }
    $p = $stato();
    expect($p['ultimi'][$s['persone']['Neri']->id]['stato'])->toBe('in_parte')
        ->and($p['ultimi'][$s['persone']['Neri']->id]['da_rimandare'])->toBeTrue()
        ->and($p['da_rimandare'])->toBe(1)->and($p['in_coda_da_tempo'])->toBe(0);

    // Lo stato di un altro condominio: 404.
    $altro = cpcViaVerdi($this);
    $this->actingAs($this->user)->getJson(route('admin.gestionale.esercizi.rendiconto.conguaglio.invio.stato', [$s['c'], $altro['e']]))->assertNotFound();
});

it('l\'ultimo invio dice lo stato dell\'ultimo foglio: un indirizzo non partito prima e poi superato da un foglio partito non fa «in parte»', function () {
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    ifcPostaCheRifiuta('neri.casa@prova.it');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['viste' => [$s['persone']['Neri']->id]]))->assertOk();
    config(['mail.default' => 'array']);
    app('mail.manager')->forgetMailers();
    // Un secondo invio alla sola primaria, partito.
    $this->travelTo('2027-01-11 10:00:00');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email'], 'viste' => [$s['persone']['Neri']->id]]))->assertOk();
    $u = app(InvioDeiFogli::class)->ultimi($s['c'], $s['e'], [$s['persone']['Neri']->id])[$s['persone']['Neri']->id];

    expect($u['stato'])->toBe('inviato')
        ->and($u['da_rimandare'])->toBeFalse()
        ->and(array_column($u['righe'], 'destinatario'))->toBe([$s['persone']['Neri']->email])
        ->and($u['quando'])->toBe('2027-01-11 11:00');
});

it('il rinvio: all\'indirizzo di oggi dello stesso tipo, solo le righe di questo esercizio, e il PDF accanto alla persona è quello rifatto', function () {
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    ifcPostaCheRifiuta('neri.casa@prova.it');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['viste' => [$s['persone']['Neri']->id]]))->assertOk();
    $rifiutata = ifcRighe($s, 'Neri')->firstWhere('destinatario', 'neri.casa@prova.it');
    // Una riga non partita di Neri in un altro esercizio dello stesso condominio: non è di questa scheda, e non si rimanda da qui.
    $e2025 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);
    $vecchia = $rifiutata->replicate();
    $vecchia->documentabile_id = $e2025->id;
    $vecchia->dati = ['foglio' => 'del-2025'] + (array) $rifiutata->dati;
    $vecchia->save();

    config(['mail.default' => 'array']);
    app('mail.manager')->forgetMailers();
    $s['persone']['Neri']->forceFill(['email_secondaria' => 'neri.nuova@prova.it'])->save();
    $r = $this->actingAs($this->user)->postJson(ifcRotta($s, 'riprova'), [])->assertOk()->json();
    $nuova = ifcRighe($s, 'Neri')->last();

    expect($r['fogli'])->toBe(1)
        ->and($nuova->destinatario)->toBe('neri.nuova@prova.it')
        ->and($nuova->documentabile_id)->toBe($s['e']->id)
        ->and(app(InvioDeiFogli::class)->ultimi($s['c'], $s['e'], [$s['persone']['Neri']->id])[$s['persone']['Neri']->id]['pdf'])->toBe($nuova->id)
        ->and(app(InvioDeiFogli::class)->daRimandare($s['c'], $s['e']))->toBe(0)
        ->and($vecchia->fresh()->stato)->toBe('non_partita');
});

it('una riga non partita di un altro esercizio dello stesso condominio non è di questa scheda e non si rimanda da qui', function () {
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['viste' => [$s['persone']['Neri']->id]]))->assertOk();
    $e2025 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);
    $vecchia = ifcRighe($s, 'Neri')->last()->replicate();
    $vecchia->documentabile_id = $e2025->id;
    $vecchia->stato = 'non_partita';
    $vecchia->dati = ['foglio' => 'del-2025'] + (array) $vecchia->dati;
    $vecchia->save();
    $invio = app(InvioDeiFogli::class);

    expect($invio->daRimandare($s['c'], $s['e']))->toBe(0)
        ->and($invio->ultimi($s['c'], $s['e'], [$s['persone']['Neri']->id])[$s['persone']['Neri']->id]['stato'])->toBe('inviato')
        ->and($this->actingAs($this->user)->postJson(ifcRotta($s, 'riprova'), [])->assertOk()->json('fogli'))->toBe(0)
        ->and(ifcRighe($s, 'Neri'))->toHaveCount(3);
});

it('il rinvio senza il servizio della coda rifà al massimo cinquanta fogli per volta, a persone intere, e dice quante persone restano', function () {
    Queue::fake();
    $this->travelTo('2027-01-10 10:00:00');
    $s = cgmCondominio(false, 0, 51, 1);
    $rotta = fn (string $nome = '') => route('admin.gestionale.esercizi.rendiconto.conguaglio.invio' . ($nome === '' ? '' : '.' . $nome), [$s['c'], $s['e']]);
    config(['queue.default' => 'database']);
    $this->actingAs($this->user)->postJson($rotta(), ifcScelte(['indirizzi' => ['email']]))->assertOk();
    RegistroInvio::where('tipo', InvioDeiFogli::TIPO)->update(['stato' => 'non_partita', 'errore' => 'x']);
    config(['queue.default' => 'sync']);
    $prima = Queue::pushed(SendQueuedNotifications::class)->count();

    $r = $this->actingAs($this->user)->postJson($rotta('riprova'), [])->assertOk()->json();
    expect($s['persone'])->toBe(57)
        ->and(Queue::pushed(SendQueuedNotifications::class)->count() - $prima)->toBe(50)
        ->and($r['fogli'])->toBe(50)
        ->and($r['restanti'])->toBe(['persone' => 7, 'messaggi' => 7]);
});

it('un nome o un testo con la forma di un collegamento non diventa un collegamento nell\'email', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $s['persone']['Neri']->forceFill(['nome' => 'Neri [Paga qui](https://truffa.example)'])->save();
    $this->travelTo('2027-01-10 10:00:00');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email'], 'viste' => [$s['persone']['Neri']->id],
        'testo' => 'Il suo foglio. Vedi [qui](https://truffa.example).']))->assertOk();
    Queue::pushed(SendQueuedNotifications::class)->first()->handle(app(ChannelManager::class));
    $html = (string) app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage()->getHtmlBody();

    expect($html)->not->toContain('href="https://truffa.example"')->toContain('Gentile Neri Paga quihttps://truffa.example,');
});

it('l\'anteprima dice chi ha un foglio con una parte «non disponibile»: parte lo stesso, e lo si sa prima', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $passaggio = cpcPassaggio($this, $s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito del 01/07/2026, notaio Gialli']);
    $registro = $passaggio->registro;
    unset($registro['regolato_fuori']);
    DB::table('subentri')->where('id', $passaggio->id)->update(['registro' => json_encode($registro)]);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $a = $this->actingAs($this->user)->postJson(ifcRotta($s, 'anteprima'), ifcScelte())->assertOk()->json();
    $nd = collect($a['persone'])->mapWithKeys(fn ($p) => [$p['nome'] => $p['non_disponibile']])->all();

    expect($nd)->toBe(['Bianchi' => true, 'Neri' => false, 'Rossi' => true, 'Verdi' => false])
        ->and($a['non_disponibili'])->toBe(2);
});

it('i modelli dello studio: la finestra propone quelli dell\'email dei fogli validi nel condominio e ricorda l\'ultimo scelto; uno di un altro condominio fa 404; si salvano con la strada delle lettere', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $altro = cpcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $nuovo = fn (string $nome, string $testo, ?int $condominio) => \App\Models\ModelloTesto::create(['ambito' => \App\Models\ModelloTesto::AMBITO_LETTERA,
        'tipo' => InvioDeiFogli::TIPO, 'nome' => $nome, 'testo' => $testo, 'condominio_id' => $condominio, 'created_by' => $this->user->id]);
    $suo = $nuovo('Foglio cortese', 'Ecco il suo foglio, [nome].', $s['c']->id);
    $nuovo('Per tutti', 'Il foglio di [esercizio].', null);
    $estraneo = $nuovo('Di un altro', 'Altro.', $altro['c']->id);
    \App\Models\ModelloTesto::create(['ambito' => 'lettera', 'tipo' => 'sollecito', 'nome' => 'Un sollecito', 'testo' => 'Sollecito.', 'condominio_id' => null]);

    $o = $this->actingAs($this->user)->getJson(ifcRotta($s, 'opzioni'))->assertOk()->json();
    expect(collect($o['modelli'])->map(fn ($m) => [$m['nome'], $m['per_tutti']])->all())->toBe([['Foglio cortese', false], ['Per tutti', true]])
        ->and($o['modello_ricordato'])->toBeNull()
        ->and($o['puo_salvare_il_testo'])->toBeFalse();

    // L'invio con il modello: la riga ne tiene il numero e il nome; la finestra dopo riparte da lì. Cancellato, lo dice.
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email'], 'modello_id' => $suo->id, 'testo' => $suo->testo]))->assertOk();
    $riga = ifcRighe($s, 'Neri')->sole();
    expect($riga->dati['modello_id'])->toBe($suo->id)->and($riga->dati['modello_nome'])->toBe('Foglio cortese')->and($riga->dati['testo'])->toBe('Ecco il suo foglio, Neri.')
        ->and($this->actingAs($this->user)->getJson(ifcRotta($s, 'opzioni'))->json('modello_ricordato'))->toBe(['id' => $suo->id, 'nome' => 'Foglio cortese', 'mancante' => false]);
    $suo->delete();
    expect($this->actingAs($this->user)->getJson(ifcRotta($s, 'opzioni'))->json('modello_ricordato'))->toBe(['id' => null, 'nome' => 'Foglio cortese', 'mancante' => true]);

    // Il modello di un altro condominio: 404 per tutta la richiesta, niente scritto.
    $prima = ifcRighe($s)->count();
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['modello_id' => $estraneo->id]))->assertNotFound();
    $this->actingAs($this->user)->postJson(ifcRotta($s, 'anteprima'), ifcScelte(['modello_id' => $estraneo->id]))->assertNotFound();
    expect(ifcRighe($s)->count())->toBe($prima);

    // «Salva come modello…» dalla finestra: la rotta dei modelli delle lettere, con il tipo dell'email dei fogli; chi gestisce le impostazioni.
    $corpo = ['tipo' => InvioDeiFogli::TIPO, 'nome' => 'Breve', 'testo' => 'Il foglio di [nome].', 'per_tutti' => false];
    $this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.modelli.store', ['condominio' => $s['c']->id]), $corpo)->assertForbidden();
    $this->user->givePermissionTo(Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->actingAs($this->user->fresh())->postJson(route('admin.gestionale.lettere.modelli.store', ['condominio' => $s['c']->id]), $corpo)->assertCreated();
    $this->actingAs($this->user->fresh())->postJson(route('admin.gestionale.lettere.modelli.store', ['condominio' => $s['c']->id]),
        ['nome' => 'Con il totale', 'testo' => 'Il totale è [totale].'] + $corpo)->assertStatus(422)->assertJsonValidationErrors('testo');
    $o = $this->actingAs($this->user->fresh())->getJson(ifcRotta($s, 'opzioni'))->assertOk()->json();
    expect(collect($o['modelli'])->pluck('nome')->all())->toBe(['Breve', 'Per tutti'])
        ->and($o['puo_salvare_il_testo'])->toBeTrue();
});

it('la scheda porta i fogli in coda da più di un\'ora, e il foglio di chi è uscito ed è staccato dal condominio si scarica', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $s['persone']['Bianchi']->condomini()->detach($s['c']->id);
    $this->travelTo('2027-01-10 10:00:00');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email']]))->assertOk();
    $this->travelTo('2027-01-10 12:00:00');
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];

    expect($props['invio_fogli']['in_coda_da_tempo'])->toBe(3)
        ->and($props['invio_fogli']['da_rimandare'])->toBe(0);
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.foglio', [$s['c'], $s['e'], $s['persone']['Bianchi']->id]))->assertOk();
});

it('i fogli in coda da più di un\'ora sono quelli dell\'esercizio della scheda: una riga ferma di un altro esercizio si conta nel suo', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email'], 'viste' => [$s['persone']['Neri']->id]]))->assertOk();
    $e2025 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);
    ifcRighe($s, 'Neri')->sole()->update(['documentabile_id' => $e2025->id]);
    $this->travelTo('2027-01-10 13:00:00');
    $invio = app(InvioDeiFogli::class);

    expect($invio->inCodaDaTempo($s['c'], $s['e']))->toBe(0)
        ->and($invio->inCodaDaTempo($s['c'], $e2025))->toBe(1)
        ->and($invio->perLaScheda($s['c'], $s['e'], [$s['persone']['Neri']->id])['in_coda_da_tempo'])->toBe(0);
});

it('il rinvio rifà il foglio con il testo e il modello di allora, anche se il modello è stato eliminato dopo', function () {
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $buono = \App\Models\ModelloTesto::create(['ambito' => \App\Models\ModelloTesto::AMBITO_LETTERA, 'tipo' => InvioDeiFogli::TIPO, 'nome' => 'Buono',
        'testo' => 'Il foglio di [nome].', 'condominio_id' => $s['c']->id, 'created_by' => $this->user->id]);
    ifcPostaCheRifiuta('neri.casa@prova.it');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['viste' => [$s['persone']['Neri']->id], 'modello_id' => $buono->id, 'testo' => $buono->testo]))->assertOk();
    $buono->delete();
    config(['mail.default' => 'array']);
    app('mail.manager')->forgetMailers();
    $this->actingAs($this->user)->postJson(ifcRotta($s, 'riprova'), [])->assertOk();
    $nuova = ifcRighe($s, 'Neri')->last();

    expect($nuova->dati['rifatto_da'])->not->toBeNull()
        ->and($nuova->dati['testo'])->toBe('Il foglio di Neri.')
        ->and($nuova->dati['testo_modello'])->toBe('Il foglio di [nome].')
        ->and($nuova->dati['modello_id'])->toBe($buono->id)
        ->and($nuova->dati['modello_nome'])->toBe('Buono');
});

it('un testo con i segni di un blocco di codice non mette la frase della proposta e l\'ora del calcolo in un blocco di codice', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email'], 'viste' => [$s['persone']['Neri']->id],
        'testo' => "Il suo foglio.\n```\n~~~ php"]))->assertOk();
    Queue::pushed(SendQueuedNotifications::class)->first()->handle(app(ChannelManager::class));
    $email = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
    $html = (string) $email->getHtmlBody();

    expect($html)->not->toContain('<code')->not->toContain('<pre')->not->toContain('&amp;#039;')
        ->and(ifcTesto($email))->toContain(InvioDeiFogli::FRASE_PROPOSTA)->toContain('calcolate il 10/01/2027 alle 11:00');
});

it('il rinvio di una persona con un numero che non è un numero fa 404, e non rimanda i fogli di tutti', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email']]))->assertOk();
    ifcRighe($s)->each->update(['stato' => 'non_partita', 'errore' => 'x']);
    $prima = ifcRighe($s)->count();

    // Anche la chiave vuota: «anagrafica_id=» non è «tutte» (con `filled()` al posto di `has()` il rinvio ripartirebbe per tutti).
    foreach (['abc', [1], 0, -3, '1.5', '', null] as $sbagliato) {
        $this->actingAs($this->user)->postJson(ifcRotta($s, 'riprova'), ['anagrafica_id' => $sbagliato])->assertNotFound();
    }
    expect(ifcRighe($s)->count())->toBe($prima);
    // Senza la chiave: tutte le persone, come prima.
    expect($this->actingAs($this->user)->postJson(ifcRotta($s, 'riprova'), [])->assertOk()->json('fogli'))->toBe(3);
});

it('l\'email di partenza dice chi la manda, che cosa c\'è nel PDF e che cosa fare; la proposta non si paga finché non è approvata, le rate già chieste restano da versare', function () {
    Queue::fake();
    $s = ifcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email'], 'viste' => [$s['persone']['Neri']->id]]))->assertOk();
    Queue::pushed(SendQueuedNotifications::class)->first()->handle(app(ChannelManager::class));
    $email = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
    $testo = ifcTesto($email);
    $condominio = \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $s['c']->nome, 'condominio');

    expect($email->getSubject())->toBe($s['c']->nome . ': la sua parte delle spese e la proposta di conguaglio, Esercizio 2026')
        ->and($testo)->toContain('Gentile Neri,')
        ->toContain('Le mando in PDF il riepilogo delle spese del ' . $condominio . ' (Esercizio 2026), con la sua parte e la proposta di conguaglio.')
        ->toContain('Se ha dubbi, risponda a questa email.')
        ->toContain('È una proposta di conguaglio, da approvare dall\'assemblea: finché non è approvata non c\'è niente da pagare per il conguaglio, '
            . 'e questa email non è una richiesta di pagamento. Le rate già chieste restano da versare come sempre.')
        ->toContain('Le cifre sono quelle al 31/12/2026, alla fine dell\'esercizio, calcolate il 10/01/2027 alle 11:00.')
        ->toContain('Cordiali saluti, l\'amministratore del ' . $condominio)
        ->not->toContain('programma')->not->toContain('Danea')->not->toContain('non è ancora calcolata')->not->toContain('pront')
        ->and(substr_count($testo, 'Le rate già chieste restano da versare come sempre'))->toBe(1)
        // Il testo, poi la frase della proposta, poi l'ora del calcolo.
        ->and(strpos($testo, 'Se ha dubbi') < strpos($testo, 'È una proposta di conguaglio'))->toBeTrue()
        ->and(strpos($testo, 'È una proposta di conguaglio') < strpos($testo, 'Le cifre sono quelle al'))->toBeTrue()
        ->and(preg_match(IFC_PAROLE_VIETATE, $testo))->toBe(0);
});

it('l\'email di chi ha un foglio con una parte non ancora calcolata lo dice in una riga fissa, con le parole del foglio; quella degli altri no', function () {
    Queue::fake();
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $passaggio = cpcPassaggio($this, $s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito del 01/07/2026, notaio Gialli']);
    $registro = $passaggio->registro;
    unset($registro['regolato_fuori']);
    DB::table('subentri')->where('id', $passaggio->id)->update(['registro' => json_encode($registro)]);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    // L'anteprima: il messaggio della prima persona (Bianchi, con una parte non ancora calcolata) ha la riga.
    $a = $this->actingAs($this->user)->postJson(ifcRotta($s, 'anteprima'), ifcScelte(['indirizzi' => ['email']]))->assertOk()->json();
    expect($a['esempio']['persona'])->toBe('Bianchi')->and($a['esempio']['righe'])->toContain(InvioDeiFogli::FRASE_NON_PRONTO);

    $this->actingAs($this->user)->postJson(ifcRotta($s), ifcScelte(['indirizzi' => ['email']]))->assertOk();
    foreach (Queue::pushed(SendQueuedNotifications::class) as $lavoro) {
        $lavoro->handle(app(ChannelManager::class));
    }
    $per = fn (string $chi) => ifcTesto(collect(app('mailer')->getSymfonyTransport()->messages())->map->getOriginalMessage()
        ->first(fn ($m) => $m->getTo()[0]->getAddress() === $s['persone'][$chi]->email));

    expect(InvioDeiFogli::FRASE_NON_PRONTO)->toBe('Una parte del suo foglio non è ancora calcolata: l\'amministratore le scriverà appena possibile.')
        ->and($per('Bianchi'))->not->toContain('pront')
        ->and($per('Bianchi'))->toContain(InvioDeiFogli::FRASE_NON_PRONTO)
        ->and($per('Rossi'))->toContain(InvioDeiFogli::FRASE_NON_PRONTO)
        ->and($per('Neri'))->not->toContain('non è ancora calcolata')
        ->and($per('Verdi'))->not->toContain('non è ancora calcolata')
        ->and(ifcRighe($s, 'Bianchi')->sole()->dati['non_disponibile'] ?? null)->toBeTrue();
});
