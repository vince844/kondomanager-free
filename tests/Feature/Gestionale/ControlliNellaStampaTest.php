<?php

/**
 * La pagina dei controlli nella stampa, per chi la riceve: il titolo dice che è il dettaglio dei controlli interni; le righe fisse senza «ammanco» e senza
 * «il programma»; «da leggere» con accanto che cosa vuol dire; in fondo la frase delle date (la prima pagina del fascicolo ne dice una più corta). Le
 * varianti per la stampa delle frasi della banca e delle registrazioni fuori da ogni esercizio, senza «il programma».
 */

use App\Services\Gestionale\AnnoACavallo;
use App\Services\Gestionale\ControlliDelConguaglio;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\ParoleDellaStampa;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    \Illuminate\Support\Facades\Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

it('le righe fisse dei controlli non dicono «ammanco» né «il programma»', function () {
    foreach ([ControlliDelRendiconto::RIGA_FISSA, ControlliDelRendiconto::RIGA_ESITI, ControlliDelRendiconto::RIGA_NON_CALCOLATI] as $riga) {
        expect($riga)->not->toContain('ammanco')->not->toContain('programma');
    }
    expect(ControlliDelRendiconto::RIGA_ESITI)->toContain('«Da leggere»: il controllo non può dire da solo se le cifre vanno bene; l\'amministratore lo spiega in assemblea.');
});

it('le varianti per la stampa della banca e delle registrazioni fuori da ogni esercizio non dicono «il programma»', function () {
    expect(ParoleDellaStampa::frase(StatoPatrimonialePaginaService::FRASE_BANCA))->not->toContain('programma')
        ->and(ParoleDellaStampa::frase(AnnoACavallo::RIMEDIO_FUORI))->not->toContain('programma');
});

it('la pagina dei controlli del fascicolo: il titolo del dettaglio, «da leggere» con che cosa vuol dire, e in fondo la frase delle date', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $t = vrmTestiDelFascicolo($s, $s['e'], ['controlli'], false);
    $testo = $t['controlli']['testo'];

    // Via Roma finito: «Vincoli e casse fondo» è da leggere (per un esercizio finito il confronto non si fa).
    // Il titolo della pagina dice il dettaglio; l'indice e il piede del fascicolo chiamano la parte con il suo nome, «Controlli del rendiconto».
    expect($testo)->toContain('Controlli interni, per chi vuole il dettaglio')
        // Il badge è scritto in minuscolo e stampato in maiuscolo («DA LEGGERE»).
        ->toContain('da leggere l\'amministratore lo spiega in assemblea')
        ->not->toContain('ammanco')
        ->toEndWith('Esercizio finito al 31/12/2026: sono alla data di fine la situazione patrimoniale, le fatture aperte, il pagato e le quote dei piani; costi, quote '
            .'registrate, risultato ed entrate e uscite per cassa contano le registrazioni dell\'esercizio, anche con la data dopo la fine; i vincoli dei fondi sono a oggi.');
});

it('la pagina dei controlli con un passaggio non registrato: la frase del buco e il suo rimedio senza «il programma» e senza istruzioni', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    // Bianchi fino al 30/06, Rossi dal 15/07, senza il passaggio registrato.
    \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_fine' => '2026-06-30']);
    \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Rossi']->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'proprietario',
        'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-07-15', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $testo = vrmTestiDelFascicolo($s, $s['e']->fresh(), ['controlli', 'conguaglio'], false)['controlli']['testo'];

    expect($testo)->toContain('Int. U2: Bianchi fino al 30/06/2026, Rossi dal 15/07/2026, senza un passaggio registrato. Così Rossi conta dall\'inizio dell\'anno; '
        .'con il passaggio registrato conterebbe dal 15/07/2026.')
        ->toContain('Che cosa si fa: Con il passaggio registrato, chi entra conta dal giorno in cui entra.')
        ->not->toContain('programma')->not->toContain('Registra il passaggio');
});

it('il rimedio delle spese personali che non tornano, nella stampa, dice che cosa si guarda senza dare istruzioni', function () {
    expect(ParoleDellaStampa::frase(ControlliDelConguaglio::RIMEDIO_PERSONALI))
        ->toBe('Una spesa per una sola unità non è né fra quelle da chiedere né in un piano: l\'amministratore la cerca fra le fatture dell\'unità e i piani che le chiedono.')
        ->and(ParoleDellaStampa::frase(ControlliDelConguaglio::RIMEDIO_PASSAGGIO_ANCHE))->not->toContain('Registra');
});
