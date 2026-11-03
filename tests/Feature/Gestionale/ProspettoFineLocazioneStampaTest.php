<?php

/**
 * 1.11.0-beta.50 — la stampa del prospetto degli oneri accessori dopo la fine di una locazione: la rotta vera
 * (`immobili.prospetto-oneri`, il PDF di mPDF) risponde, e il testo si legge sulla vista resa con gli stessi dati (`pflStampa`). Le
 * cifre del servizio sono in `ProspettoFineLocazioneTest`.
 *
 * Le misure della Fase 1 della .50 hanno stampato il PDF con la vista di prima sui dati nuovi: diceva cose false in sette punti. Qui
 * si presidia che la stampa non le dica più, cercando le frasi false come le stampava la vista (non le frasi nuove, che il mockup per
 * Vincenzo può ancora cambiare): 1. «pagata da» senza un nome, accanto alla riga dei giorni da restituire (€ -181,48); 2. «di cui da
 * rimborsare a chi le ha pagate € -181,48» nel piede di chi esce, che è il contrario: gli si deve; 3. la testa della sezione «Giorni
 * senza conduttore», «restano a chi le ha pagate.», per giorni che vanno restituiti a chi è uscito; 4. «Totale che resta a chi ha
 * pagato» per gli stessi giorni; 5. il piede dell'unità, «… che restano a chi le ha pagate», per gli stessi giorni; 6. i giorni dopo
 * l'uscita nelle rate di chi esce stampati come una voce qualunque «nelle sue rate», senza dire che sono dopo l'uscita: la sezione di
 * chi esce deve nominare l'uscita; 7. nessuna parola sulla restituzione nella sezione di chi esce: la deve dire.
 * ➕ Revisione dei test della Fase 1 (11/10/2026): il punto 2 si cerca anche senza il meno («di cui da rimborsare a chi le ha pagate €
 *   181,48» nel piede di chi esce), dentro la sua sezione: la mutazione che lo stampava così sopravviveva. E nei casi vicini: con
 *   «Scrivi» i giorni dopo l'uscita «pagata da» chi sta al posto dell'inquilino; il cambio in due passi e in un passo con «Non
 *   scriverlo» (Luca rimborsa a Ines, e nessuna frase dice un conguaglio che non è stato scritto); il coinquilino che resta (nessuno
 *   al posto dell'inquilino; i giorni dopo l'uscita nelle rate di Ines, € 180,00, con la nota «lo decidono le parti»: scelta del
 *   verbale, 11/10/2026 notte); la fine senza la scelta nel registro («nessun conguaglio», non «non è stato scritto»); il box di
 *   Rita.
 *
 * Prima della .50 i test che mandano la scelta erano rossi perché la rotta rifiutava il campo `conguaglio` (il campo nuovo della
 * decisione 77); quelli del coinquilino e della fine senza scelta erano rossi sulla stampa. Con la vista di prima sui dati nuovi i
 * cinque della strada A′, del coinquilino e della fine senza scelta erano rossi sulle frasi false; quelli di «Scrivi» e del box erano
 * verdi, perché la vista di prima non stampava niente di falso.
 *
 * ➕ Fusione della Fase 1 (11/10/2026): la stampa si prova sulla vista resa, come `pciStampa`, non più con `pdftotext` (verbale della
 * .50, «Le scelte di progetto sulle questioni aperte dai test»: la suite non dipende da poppler). Le asserzioni sulle frasi restano: si
 * cercano frasi che stanno in una cella o in un paragrafo, e le cifre di un conduttore dentro la sua sezione (`pflSezioneNellaStampa`),
 * in qualunque ordine.
 *
 * Cosa NON copre: l'impaginazione (la sezione «senza conduttore» che va a pagina 2); il segno della riga di restituzione («€ -181,48»
 * nella colonna «Importo», da decidere con il mockup); le frasi nuove parola per parola; la testa del prospetto sui modi («pagata da …
 * perché nei giorni del piano nessun inquilino era registrato»), che con «Scrivi» non vale più per tutti i casi (da riscrivere nel
 * servizio, non misurata qui); il rinnovo; il già versato; le traduzioni.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariProspettoLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * Le frasi false della vista di prima sui giorni da restituire: nessuna deve comparire nel testo. Il punto 2 si cerca nella sezione
 * di chi esce con qualunque segno (revisione dei test della Fase 1: la mutazione che scrive «di cui da rimborsare a chi le ha pagate
 * € 181,48», senza il meno, nel piede di Ines sopravviveva); nella sezione di un conduttore che rimborsa davvero la frase è giusta.
 */
function pflNessunaFraseFalsaSullaRestituzione(string $testo, string $cifra, string $chiEsce = 'Ines Moretti'): void
{
    expect(pflPagataDaSenzaNome($testo))->toBe([], 'la stampa dice «pagata da» senza un nome')
        ->and(str_contains(pflSezioneNellaStampa($testo, $chiEsce), 'di cui da rimborsare a chi le ha pagate'))->toBeFalse("il piede di {$chiEsce} dice che deve rimborsare, mentre le si deve restituire");
    foreach ([
        'di cui da rimborsare a chi le ha pagate € -',
        'nessun conduttore risulta registrato: restano a chi le ha pagate.',
        'Totale che resta a chi ha pagato ' . $cifra,
        $cifra . ' che restano a chi le ha pagate',
    ] as $falsa) {
        expect(str_contains($testo, $falsa))->toBeFalse("la stampa dice «{$falsa}»");
    }
}

it('stampa, fine senza nuovo inquilino con «Non scriverlo»: non dice più le sette cose false sui € 181,48 da restituire a Ines, nomina l\'uscita e la restituzione', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere');

    $testo = pflStampa($this, $s);
    // € 181,48 = 18148: i 184 giorni dopo l'uscita, 36000 × 184 / 365 = 18147,95 → 18148 (resti maggiori, 17852 + 18148 = 36000).
    pflNessunaFraseFalsaSullaRestituzione($testo, '€ 181,48');
    $ines = pflSezioneNellaStampa($testo, 'Ines Moretti');
    // La parte di Ines per i suoi 181 giorni, 17852 = € 178,52, è il suo totale; nella sua sezione i giorni dopo l'uscita (€ 181,48)
    // si dicono dopo l'uscita, e da restituirle (punti 6 e 7: con la vista di prima la sua sezione non nominava né l'una né
    // l'altra).
    expect($ines)->toContain('Totale a carico di Ines Moretti')->toContain('€ 178,52')->toContain('€ 181,48')
        ->and(mb_strtolower($ines))->toContain('uscita')
        ->and(mb_strtolower($ines))->toContain('restitu');
});

it('stampa, fine senza nuovo inquilino con «Scrivi»: i giorni dopo l\'uscita sono «pagata da Ugo Rinaldi», e nessuna voce dice «pagata da» senza un nome', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'scrivi');

    $testo = pflStampa($this, $s);
    expect(pflPagataDaSenzaNome($testo))->toBe([])
        // 18148 = € 181,48, con la coppia a Ugo; Ines € 178,52 nelle sue rate (36000 − 18148 = 17852).
        ->and($testo)->toContain('pagata da Ugo Rinaldi')
        ->and(pflSezioneNellaStampa($testo, 'Ines Moretti'))->toContain('Totale a carico di Ines Moretti')->toContain('€ 178,52');
});

it('stampa, cambio in due passi con «Non scriverlo» e Luca dal 1/9: Luca rimborsa a Ines (€ 120,33), e luglio–agosto (€ 61,15) non «restano a chi le ha pagate»', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere');
    pflInizio($this, $s, 'Luca Ferri', '2026-09-01');

    $testo = pflStampa($this, $s);
    // 18148 × 62 / 184 = 6115,09 → 6115 (€ 61,15) per luglio e agosto; 18148 × 122 / 184 = 12032,91 → 12033 (€ 120,33) per Luca.
    pflNessunaFraseFalsaSullaRestituzione($testo, '€ 61,15');
    expect($testo)->toContain('pagata da Ines Moretti')
        ->and(pflSezioneNellaStampa($testo, 'Luca Ferri'))->toContain('Totale a carico di Luca Ferri')->toContain('€ 120,33')
        // Ines: i € 181,48 dopo l'uscita sono da restituirle (€ 120,33 da Luca, € 61,15 fra le parti).
        ->and(mb_strtolower(pflSezioneNellaStampa($testo, 'Ines Moretti')))->toContain('restitu');
});

it('stampa, cambio d\'inquilino in un passo con «Non scriverlo»: Luca rimborsa a Ines i suoi 184 giorni, e la stampa non dice che li ha pagati con un conguaglio', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', 'non_scrivere');

    $testo = pflStampa($this, $s);
    expect(pflPagataDaSenzaNome($testo))->toBe([])
        ->and(str_contains($testo, 'di cui da rimborsare a chi le ha pagate € -'))->toBeFalse('il piede di Ines dice «da rimborsare € -181,48»')
        // Revisione dei test della Fase 1: il punto 2 con qualunque segno, nella sezione di Ines.
        ->and(str_contains(pflSezioneNellaStampa($testo, 'Ines Moretti'), 'di cui da rimborsare a chi le ha pagate'))->toBeFalse('il piede di Ines dice che deve rimborsare')
        // Luca 18148 = € 181,48, da rimborsare a chi li ha pagati: Ines, nelle sue rate.
        ->and($testo)->toContain('pagata da Ines Moretti')
        ->and(pflSezioneNellaStampa($testo, 'Luca Ferri'))->toContain('Totale a carico di Luca Ferri')->toContain('€ 181,48')
        // Il conguaglio non è stato scritto: la voce di Luca non è «con il conguaglio del cambio d'inquilino del 01/07/2026».
        ->and(str_contains($testo, 'con il conguaglio del cambio d\'inquilino del 01/07/2026'))->toBeFalse('la stampa dice che Luca ha pagato con il conguaglio');
});

it('stampa, coinquilino che resta (Ines e Dora al 50 %, Ines esce il 1/7): la regola del piano, «con il conguaglio della fine locazione del 01/07/2026», Ines € 119,34 e Dora € 240,66; con «Non scriverlo» € 60,66 da restituire a Ines; mai «lo decidono le parti»', function (string $scelta) {
    // Coda 245 (1.11.0-beta.55): la fine con un coinquilino che resta ha la scelta e la regola del piano. Prima la stampa diceva i € 90,74 dopo l'uscita nelle rate di
    // Ines, «lo decidono le parti», e «Totale a carico di Ines Moretti € 180,00». Le cifre: Ines 36000 × 9050/27300 = 11934,07 → € 119,34, Dora € 240,66; la coppia
    // € 60,66 (con «Scrivi» scritta, con «Non scriverlo» Dora la rimborsa a Ines). Nessuno paga «al posto dell'inquilino»: niente «Giorni senza conduttore».
    $s = pflScenario(inquilini: [['Ines Moretti', 50], ['Dora Conti', 50]]);
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', $scelta);

    $testo = pflStampa($this, $s);
    $ines = pflSezioneNellaStampa($testo, 'Ines Moretti');
    $dora = pflSezioneNellaStampa($testo, 'Dora Conti');
    expect(pflPagataDaSenzaNome($testo))->toBe([])
        ->and($ines)->toContain('Totale a carico di Ines Moretti € 119,34')
        ->and($dora)->toContain('Totale a carico di Dora Conti € 240,66')
        ->and($testo)->toContain('con la regola del piano')
        ->and($testo)->not->toContain('lo decidono le parti')
        ->and($testo)->not->toContain('€ -')
        ->and(str_contains($testo, 'Giorni senza conduttore'))->toBeFalse('la stampa mette dei giorni senza conduttore, con Dora');
    if ($scelta === 'scrivi') {
        expect($ines)->toContain('con il conguaglio della fine locazione del 01/07/2026, a suo credito € 60,66')
            ->and($dora)->toContain('con il conguaglio della fine locazione del 01/07/2026, a suo debito € 60,66');
    } else {
        expect($ines)->toContain('da restituire a Ines Moretti da Dora Conti: la fine della locazione del 01/07/2026 non ha scritto il conguaglio € 60,66')
            ->and($dora)->toContain('da rimborsare a Ines Moretti: la fine della locazione del 01/07/2026 non ha scritto il conguaglio € 60,66');
    }
})->with(['Scrivi' => 'scrivi', 'Non scriverlo' => 'non_scrivere']);

it('stampa, fine senza la scelta nel registro (piano emesso dopo la fine, senza ricalcolo): dice «nessun conguaglio», non «non è stato scritto», e non le cose false sulla restituzione', function () {
    $s = pflScenario();
    pflGenera($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', null);
    pflEmetti($this, $s, '2026-12-31', '2026-07-02');

    $testo = pflStampa($this, $s);
    pflNessunaFraseFalsaSullaRestituzione($testo, '€ 181,48');
    expect(mb_strtolower($testo))->toContain('nessun conguaglio')
        ->and($testo)->not->toContain('non è stato scritto')
        ->and(pflSezioneNellaStampa($testo, 'Ines Moretti'))->toContain('Totale a carico di Ines Moretti')->toContain('€ 178,52');
});

it('stampa del box di Rita, fine di Ines con il box spuntato e «Scrivi»: i giorni dopo l\'uscita sul box sono «pagata da Rita Bassi», non da Ugo', function () {
    $s = pflScenario(box: true, titolariBox: [['Rita Bassi', 'proprietario', 100]]);
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'scrivi', [$s['box']]);

    $testo = pflStampa($this, $s, $s['box']);
    // Box: 3600 × 181 / 365 = 1785,21 → 1785 (€ 17,85) a Ines; 3600 × 184 / 365 = 1814,79 → 1815 (€ 18,15) a Rita con la coppia.
    expect(pflPagataDaSenzaNome($testo))->toBe([])
        ->and($testo)->toContain('pagata da Rita Bassi')
        ->and(str_contains($testo, 'pagata da Ugo Rinaldi'))->toBeFalse('la stampa del box nomina Ugo, che non è del box')
        ->and(pflSezioneNellaStampa($testo, 'Ines Moretti'))->toContain('Totale a carico di Ines Moretti')->toContain('€ 17,85');
});
