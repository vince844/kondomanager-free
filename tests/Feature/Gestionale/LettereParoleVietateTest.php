<?php

/**
 * Le parole vietate nei testi nuovi delle rate scadute e delle lettere: le frasi del server (le costanti dei servizi, delle notifiche e dei
 * controller), le lettere in PDF dei quattro tipi (anche in prova e in anteprima, con e senza lo studio), il messaggio email, la stampa
 * dell'elenco e le risposte dell'anteprima. Le eccezioni sono due: il testo dell'art. 1129 c. 9 citato fra virgolette (contiene «esigibile»)
 * e il nome del tipo «Messa in mora». I testi delle pagine montate li controlla la loro prova in vitest.
 */

use App\Models\User;
use App\Notifications\Gestionale\LetteraNotification;
use App\Services\Gestionale\Debiti\SeiMesi;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

const LTT_PAROLE_VIETATE = ['moros', 'esigibile', 'diffidat', 'è in mora', 'costituito in mora', 'in mora dal', 'ingiungibil', 'ingiunzion', 'decadut',
    'termine scaduto', 'grave irregolarità', 'consegnata', 'recapitata', 'notificata', 'valore legale', 'novazione', 'transazione'];

/** Le parole vietate che un testo contiene, tolte le due eccezioni. */
function lttVietateIn(string $testo): array
{
    $pulito = mb_strtolower(str_replace([SeiMesi::TESTO_NORMA, 'Messa in mora', 'MESSA IN MORA'], ' ', html_entity_decode(strip_tags($testo))));

    return array_values(array_filter(LTT_PAROLE_VIETATE, fn ($p) => str_contains($pulito, $p)));
}

/** Tutte le costanti di testo di una classe. */
function lttCostantiDi(string $classe): array
{
    $testi = [];
    foreach ((new ReflectionClass($classe))->getReflectionConstants() as $c) {
        $v = $c->getValue();
        $chiave = $classe . '::' . $c->getName();
        if (is_string($v)) {
            $testi[$chiave] = $v;
        } elseif (is_array($v)) {
            array_walk_recursive($v, function ($x) use (&$testi, $chiave) {
                if (is_string($x)) {
                    $testi[$chiave] = ($testi[$chiave] ?? '') . ' ' . $x;
                }
            });
        }
    }

    return $testi;
}

beforeEach(function () {
    Storage::fake('local');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

it('le frasi del server delle rate scadute, delle date, del pregresso e delle lettere non hanno parole vietate', function () {
    $classi = [
        \App\Services\Gestionale\Debiti\ElencoRateScadute::class, SeiMesi::class, \App\Services\Gestionale\Debiti\OriginePregresso::class,
        EmissioneLettere::class, \App\Services\Gestionale\Lettere\TestiLettere::class, \App\Services\Gestionale\Lettere\RegistroLettere::class,
        LetteraNotification::class, \App\Http\Controllers\Gestionale\Movimenti\LettereController::class,
        \App\Services\Gestionale\Invii\InviiPerPersona::class, \App\Services\Gestionale\Ricevute\LimiteAltroIndirizzo::class,
        \App\Http\Controllers\Gestionale\Movimenti\AccordiRientroController::class, \App\Services\Gestionale\Ricevute\AvvisiCompensazione::class,
        \App\Notifications\Gestionale\AvvisoCompensazioneNotification::class,
    ];
    $trovate = [];
    foreach ($classi as $classe) {
        foreach (lttCostantiDi($classe) as $nome => $testo) {
            if ($nome === SeiMesi::class . '::TESTO_NORMA') {
                continue;
            }
            foreach (lttVietateIn($testo) as $p) {
                $trovate[] = "{$nome}: {$p}";
            }
        }
    }
    expect($trovate)->toBe([])
        // L'eccezione c'è davvero: senza toglierla, la norma citata farebbe scattare la regola.
        ->and(SeiMesi::TESTO_NORMA)->toContain('esigibile');
});

it('le lettere dei quattro tipi in PDF, in prova e in anteprima, con e senza lo studio, il messaggio email e la stampa dell\'elenco non hanno parole vietate', function () {
    config(['mail.default' => 'array']);
    $s = lttScenario($this);
    $e = app(EmissioneLettere::class);
    $testi = [];
    foreach (EmissioneLettere::TIPI as $tipo) {
        $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['tipo' => $tipo, 'persone' => [$s['persone']['ugo']->id],
            'testo' => $tipo === 'promemoria' ? \App\Services\Gestionale\Lettere\TestiLettere::DI_SERIE['promemoria'] : \App\Services\Gestionale\Lettere\TestiLettere::DI_SERIE['sollecito']]));
    }
    foreach (lttRighe($s, 'ugo') as $riga) {
        $voce = $e->voceDallaRiga($riga);
        $testi[$riga->tipo] = View::make('pdf.gestionale.lettera', $e->datiDellaVista($s['c'], [$voce]))->render();
        $testi[$riga->tipo . ' prova'] = View::make('pdf.gestionale.lettera', $e->datiDellaVista($s['c'], [['etichetta' => EmissioneLettere::ETICHETTA_PROVA] + $voce]))->render();
        $testi[$riga->tipo . ' anteprima'] = View::make('pdf.gestionale.lettera', $e->datiDellaVista($s['c'], [['etichetta' => EmissioneLettere::ETICHETTA_ANTEPRIMA] + $voce]))->render();
        $messaggio = (new LetteraNotification([$riga->id]))->toMail(new \Illuminate\Notifications\AnonymousNotifiable);
        $testi[$riga->tipo . ' email'] = (string) $messaggio->render();
    }
    $settings = app(\App\Settings\PrintSettings::class);
    $settings->studio_nome = 'Studio Bianchi';
    $settings->save();
    $testi['con lo studio'] = View::make('pdf.gestionale.lettera', $e->datiDellaVista($s['c'], [$e->voceDallaRiga(lttRighe($s, 'ugo')->first())]))->render();
    $testi['anteprima json'] = json_encode($this->actingAs($this->user)->postJson(lttRotta($s, 'anteprima'), lttScelte($s))->json(), JSON_UNESCAPED_UNICODE);
    // La stampa dell'elenco: i dati che il controller passa alla vista, resi in HTML (il PDF è compresso).
    $dati = null;
    View::composer('pdf.gestionale.rate_scadute', function ($vista) use (&$dati) {
        $dati ??= $vista->getData();
    });
    $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.stampa', ['condominio' => $s['c']->id, 'tutte' => 1]))->assertOk();
    $testi['stampa dell\'elenco'] = View::make('pdf.gestionale.rate_scadute', $dati)->render();

    $trovate = [];
    foreach ($testi as $dove => $testo) {
        foreach (lttVietateIn($testo) as $p) {
            $trovate[] = "{$dove}: {$p}";
        }
    }
    expect(count($testi))->toBe(4 * 4 + 3)->and($trovate)->toBe([]);
});

/** I testi letterali di un file PHP (le stringhe, non i nomi né i commenti); per una vista Blade il testo senza i commenti Blade. */
function lttLetteraliDi(string $percorso): array
{
    $sorgente = (string) file_get_contents($percorso);
    if (str_ends_with($percorso, '.blade.php')) {
        return [preg_replace('/\{\{--.*?--\}\}/s', ' ', $sorgente)];
    }
    $testi = [];
    foreach (token_get_all($sorgente) as $t) {
        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $testi[] = stripcslashes(trim($t[1], '\'"'));
        }
    }

    return $testi;
}

it('i testi scritti dentro il codice delle rate scadute e delle lettere, non solo le costanti, non hanno parole vietate', function () {
    $file = [
        'app/Services/Gestionale/Debiti/ElencoRateScadute.php', 'app/Services/Gestionale/Debiti/SeiMesi.php', 'app/Services/Gestionale/Debiti/OriginePregresso.php',
        'app/Services/Gestionale/Lettere/EmissioneLettere.php', 'app/Services/Gestionale/Lettere/RegistroLettere.php', 'app/Services/Gestionale/Lettere/TestiLettere.php',
        'app/Services/Gestionale/Invii/InviiPerPersona.php', 'app/Notifications/Gestionale/LetteraNotification.php', 'app/Exceptions/Gestionale/LetteraNonEmettibileException.php',
        'app/Http/Controllers/Gestionale/Movimenti/LettereController.php', 'app/Http/Controllers/Gestionale/Movimenti/RateScaduteController.php',
        'app/Http/Controllers/Gestionale/Saldi/DichiaraOrigineSaldiController.php', 'app/Http/Requests/Gestionale/Saldi/DichiaraOrigineSaldiRequest.php',
        'app/Http/Controllers/Gestionale/Esercizi/DispensaSeiMesiController.php',
        'resources/views/pdf/gestionale/lettera.blade.php', 'resources/views/pdf/gestionale/rate_scadute.blade.php',
        // L'accordo di rientro e l'avviso di compensazione.
        'app/Services/Gestionale/Debiti/AccordiRientro.php', 'app/Http/Controllers/Gestionale/Movimenti/AccordiRientroController.php',
        'app/Models/Gestionale/AccordoRientro.php', 'app/Models/Gestionale/AccordoRientroRata.php',
        'app/Services/Gestionale/Ricevute/AvvisiCompensazione.php', 'app/Http/Controllers/Gestionale/Movimenti/AvvisiCompensazioneController.php',
        'app/Notifications/Gestionale/AvvisoCompensazioneNotification.php', 'resources/views/pdf/gestionale/avviso_compensazione.blade.php',
        // Le frasi delle persone con un accordo che non si cancellano, dei file caricati, degli annullamenti da comunicare.
        'app/Http/Controllers/Anagrafiche/AnagraficaController.php', 'app/Support/FileComeIlNome.php', 'app/Services/Gestionale/Ricevute/EmissioneRicevute.php',
        'app/Http/Controllers/Gestionale/Movimenti/RicevuteIncassiController.php',
        // I modelli delle lettere.
        'app/Services/Gestionale/Lettere/ModelliLettere.php', 'app/Http/Controllers/Impostazioni/ModelliLettereController.php', 'app/Models/ModelloTesto.php',
    ];
    $trovate = [];
    foreach ($file as $f) {
        foreach (lttLetteraliDi(base_path($f)) as $testo) {
            // Un pezzo del testo della norma, scritto su più righe: è l'eccezione.
            if (trim($testo) !== '' && str_contains(SeiMesi::TESTO_NORMA, trim($testo))) {
                continue;
            }
            foreach (lttVietateIn($testo) as $p) {
                $trovate[] = "{$f}: {$p} in «" . mb_substr(trim($testo), 0, 60) . '»';
            }
        }
    }

    expect($trovate)->toBe([]);
});
