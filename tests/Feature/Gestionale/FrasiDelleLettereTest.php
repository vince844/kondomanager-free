<?php

/**
 * Le frasi delle lettere e delle pagine vicine che vengono dal server, come le legge l'amministratore o il condòmino: vere sul codice e in italiano
 * semplice. La colonna «Ritardo» della lettera non dice stati interni del programma; togliere l'origine non si chiama «dichiararla»; il tetto
 * dello .zip non è quello di un PDF; l'elenco degli indirizzi dello .zip dice di registrare la spedizione; il nome del condominio non ripete la
 * parola; i testi di partenza di diffida e messa in mora dicono che il condominio «potrà procedere».
 */

use App\Http\Controllers\Gestionale\Movimenti\AccordiRientroController;
use App\Http\Controllers\Gestionale\Movimenti\LettereController;
use App\Models\Saldo;
use App\Models\User;
use App\Notifications\Gestionale\AvvisoCompensazioneNotification;
use App\Services\Gestionale\Debiti\OriginePregresso;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\TestiLettere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** La lettera di Ugo come la scrive il PDF dell'anteprima, in testo. */
function fdlHtml($test, array $s, array $altro = []): string
{
    $dati = null;
    View::composer('pdf.gestionale.lettera', function ($vista) use (&$dati) {
        $dati = $vista->getData();
    });
    $test->actingAs($test->user)->post(lttRotta($s, 'pdf-anteprima'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]] + $altro))->assertOk();

    return html_entity_decode(preg_replace('/\s+/', ' ', strip_tags(View::make('pdf.gestionale.lettera', $dati)->render())), ENT_QUOTES);
}

it('la colonna «Ritardo» della lettera: i giorni, o di quale esercizio è il pregresso; mai lo stato interno dell\'origine', function () {
    expect(EmissioneLettere::ritardo(['giorni_ritardo' => 75]))->toBe('75 giorni')
        ->and(EmissioneLettere::ritardo(['giorni_ritardo' => 1]))->toBe('1 giorno')
        ->and(EmissioneLettere::ritardo(['giorni_ritardo' => null, 'pregresso' => ['origine' => OriginePregresso::ESERCIZIO, 'esercizio' => ['nome' => 'Esercizio 2025']]]))
        ->toBe('pregresso dell\'esercizio 2025')
        ->and(EmissioneLettere::ritardo(['giorni_ritardo' => null, 'pregresso' => ['origine' => OriginePregresso::NON_DICHIARATA]]))->toBe('pregresso di esercizi precedenti')
        ->and(EmissioneLettere::ritardo(['giorni_ritardo' => null, 'pregresso' => ['origine' => OriginePregresso::PRIMA_DEL_PRIMO]]))->toBe('pregresso di esercizi precedenti');
});

it('la lettera: in testa il nome e l\'indirizzo senza «Gentile», con lo studio «Amministratore del Condominio …» una volta; «Da versare» anche nel promemoria', function () {
    $s = lttScenario($this);
    $s['c']->update(['nome' => 'Le Rose']);
    $impostazioni = app(\App\Settings\PrintSettings::class);
    $impostazioni->studio_nome = 'Studio Bianchi';
    $impostazioni->save();
    $html = fdlHtml($this, $s);
    expect($html)->toContain('Amministratore del Condominio Le Rose')
        ->and(substr_count($html, 'Gentile'))->toBe(1);

    $this->travelTo('2026-03-25 10:00:00');
    $promemoria = fdlHtml($this, $s, ['tipo' => 'promemoria', 'giorni' => 30, 'testo' => TestiLettere::DI_SERIE['promemoria']]);
    expect($promemoria)->toContain('Da versare')->not->toContain('Importo')
        ->and($promemoria)->toContain('le ricordiamo le sue rate del condominio Le Rose che scadono nei prossimi giorni');
});

it('«Supercondominio» come «Condominio»: il titolo non gli mette davanti la parola, e il testo non la ripete', function () {
    expect(TestiLettere::condominioConNome('Supercondominio Le Torri'))->toBe('Supercondominio Le Torri')
        ->and(TestiLettere::condominioConNome('Le Torri'))->toBe('Condominio Le Torri')
        ->and(TestiLettere::riempi('del condominio [condominio].', 'Ugo', 100, null, 'Supercondominio Le Torri'))->toBe('del Supercondominio Le Torri.');
});

it('togliere l\'origine di un saldo si dice «tolta», non «dichiarata»', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [['ugo', 'A', 1000], ['bice', 'B', 2000]]);
    $saldi = Saldo::where('condominio_id', $s['c']->id)->pluck('id')->all();
    $rotta = route('admin.gestionale.saldi.origine', ['condominio' => $s['c']->id]);
    $this->actingAs($this->user)->post($rotta, ['saldi' => [$saldi[0]], 'origine' => OriginePregresso::NON_DICHIARATA])
        ->assertSessionHas('message', fn ($m) => $m['message'] === 'Origine tolta: il saldo torna «non dichiarata». L\'importo non cambia.');
    $this->actingAs($this->user)->post($rotta, ['saldi' => $saldi, 'origine' => OriginePregresso::NON_DICHIARATA])
        ->assertSessionHas('message', fn ($m) => $m['message'] === 'Origine tolta da 2 saldi: tornano «non dichiarata». Gli importi non cambiano.');
    $this->actingAs($this->user)->post($rotta, ['saldi' => $saldi, 'origine' => OriginePregresso::CON_IL_PIANO])
        ->assertSessionHas('message', fn ($m) => $m['message'] === 'Origine dichiarata per 2 saldi: gli importi non cambiano.');
});

it('lo .zip: il tetto è dello .zip, e l\'elenco degli indirizzi dice come spedire e di registrare la spedizione', function () {
    $tetto = new ReflectionMethod(EmissioneLettere::class, 'dentroIlTettoDelPdf');
    try {
        $tetto->invoke(app(EmissioneLettere::class), array_fill(1, EmissioneLettere::TETTO_PDF + 49, []), 'zip');
        $frase = null;
    } catch (\App\Exceptions\Gestionale\LetteraNonEmettibileException $e) {
        $frase = $e->getMessage();
    }
    expect($frase)->toStartWith('Le lettere da scaricare sono 249: in un .zip ne stanno al massimo 200.');

    $s = lttScenario($this);
    $zip = $this->actingAs($this->user)->post(lttRotta($s, 'pdf-per-persona'), lttScelte($s, ['tipo' => 'diffida', 'termine_forma' => 'giorni', 'termine' => 15,
        'testo' => TestiLettere::DI_SERIE['diffida']]))->assertOk()->getContent();
    $file = tempnam(sys_get_temp_dir(), 'fdl');
    file_put_contents($file, $zip);
    $archivio = new ZipArchive();
    $archivio->open($file);
    $indirizzi = (string) $archivio->getFromName(\App\Services\Gestionale\Invii\InviiPerPersona::FILE_DEGLI_INDIRIZZI);
    $archivio->close();
    expect($indirizzi)->toContain(EmissioneLettere::COME_SPEDIRE_I_PDF)
        ->and(EmissioneLettere::COME_SPEDIRE_I_PDF)->toBe('Un PDF per persona, da stampare e spedire, o da mandare con la tua PEC o il tuo programma di posta. Poi registra '
            . 'la spedizione nel programma: finché non la registri, la lettera non conta fra quelle scritte.')
        ->and($indirizzi)->not->toContain('da mandare dal tuo programma di posta.');
});

it('l\'email dell\'avviso di compensazione non ripete la parola «condominio»', function () {
    expect(AvvisoCompensazioneNotification::testo('Condominio Le Rose'))->toBe('In allegato l\'avviso del Condominio Le Rose: un credito è stato usato per le sue rate, '
        . 'senza un versamento nuovo. Le rate e l\'importo sono nel PDF.')
        ->and(AvvisoCompensazioneNotification::testo('Le Rose'))->toStartWith('In allegato l\'avviso del condominio Le Rose:');
});

it('i testi di partenza di diffida e messa in mora: «potrà procedere», senza «altri rinvii» né il doppione «per iscritto»', function () {
    expect(TestiLettere::DI_SERIE['diffida'])->toBe("Gentile [nome],\ndalla contabilità del condominio [condominio] risultano ancora da versare le rate indicate qui sotto, "
        . "per un totale di [totale].\nLe chiediamo con fermezza di versare il totale entro [termine].\nSe il versamento non arriva entro quel termine, il condominio potrà "
        . "procedere al recupero del credito.\nSe ha già versato, ci mandi la ricevuta del bonifico.")
        ->and(TestiLettere::DI_SERIE['messa_in_mora'])->toBe("Gentile [nome],\ncon questa lettera il condominio [condominio] le chiede formalmente il pagamento delle rate "
            . "indicate qui sotto, per un totale di [totale], entro [termine].\nSe il pagamento non arriva entro quel termine, il condominio potrà procedere al recupero del "
            . "credito.\nSe ha già versato, ci mandi la ricevuta del bonifico.");
});

it('le frasi del server: la data del termine, nessuna persona, «nessun termine», il PDF che si riapre, l\'accordo, la persona che non si cancella', function () {
    expect(EmissioneLettere::DATA_DEL_TERMINE)->toBe('Scegli come termine una data da domani a un anno da oggi.')
        ->and(EmissioneLettere::NESSUNA_PERSONA)->toBe('Per nessuna delle persone scelte c\'è qualcosa da mettere in questa lettera.')
        ->and(EmissioneLettere::TERMINE_NEL_TESTO)->toBe('Il testo usa [termine], ma hai scelto «nessun termine nella lettera»: togli [termine] dal testo, o scegli il termine.')
        ->and(LettereController::FRASE_RIAPRI_PDF)->toEndWith('le lettere già emesse si leggono nella pagina dell\'estratto conto di ogni persona; di sollecito, diffida e '
            . 'messa in mora anche il PDF.')
        ->and(AccordiRientroController::RATA_NON_COPRIBILE)->toBe('Una delle rate scelte non era scaduta, o non aveva un residuo, il giorno dell\'accordo: toglila, o cambia '
            . 'la data dell\'accordo.')
        ->and(AccordiRientroController::SENZA_AUTORIZZAZIONE)->toEndWith('diventa «sì» quando registri la delibera.')
        ->and(\App\Services\Gestionale\Debiti\SeiMesi::FRASE_PIU_SEI_MESI)->toBe('Più sei mesi: lo stesso giorno del sesto mese dopo, o l\'ultimo giorno di quel mese se '
            . 'quel giorno manca (31/08 → 28/02, o 29/02 negli anni bisestili). È una scelta prudente del programma: fra i modi di aggiungere sei mesi a una data, quello che '
            . 'dà la data più presto.')
        ->and(__('impostazioni.header.mail_settings_description', [], 'it'))->toBe('Configura il metodo di invio per rate, solleciti e comunicazioni ai condòmini.');
});

it('la stampa dell\'elenco e lo stato patrimoniale: le frasi della riservatezza, della data in evidenza, dell\'elenco che ora c\'è', function () {
    $elenco = preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path('views/pdf/gestionale/rate_scadute.blade.php')));
    expect($elenco)->toContain('come il condòmino o il creditore non ancora pagato che lo chiedono. Non va esposto in bacheca né in altri spazi che vedono anche gli estranei.')
        ->and($elenco)->toContain('qui la più vicina senza una dispensa');
    $stato = preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path('views/pdf/gestionale/stato_patrimoniale.blade.php')));
    expect($stato)->not->toContain('modulo morosi')
        ->and($stato)->toContain('l\'elenco per persona è nella pagina «Rate scadute» dei movimenti, con lo scaduto di ognuno senza togliere il credito.');
});
