<?php

/**
 * La scheda «Conguaglio» del rendiconto, il riquadro della Sintesi, le due stampe, il foglio della persona e il suo archivio, sul caso di Via
 * Verdi (il piano, le rate, gli incassi e il passaggio fatti dal programma).
 *
 * Le cifre sono quelle del progetto, rifatte a mano (positivo = debito): Neri A 2.100,00, B 2.000,00, conguaglio +100,00, posizione +300,00, saldo
 * +400,00; Bianchi (181 giorni) 624,82, 595,07, +29,75, saldi fuori dalle rate −604,93 (la coppia), saldo −575,18; Rossi (184 giorni) 635,18,
 * 604,93, +30,25, fuori +604,93, saldo +635,18; Verdi 840,00 + 80,00 di spese personali non chieste, 800,00, +120,00, saldo +120,00. Il condominio:
 * 4.200,00, 80,00, 4.000,00, +280,00, +300,00, 0,00, +580,00.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ConsuntivoPerPersona;
use App\Services\Gestionale\FascicoloDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__ . '/RendicontoViaRomaScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
});

afterEach(function () {
    (new ReflectionProperty(ConguaglioDelRendiconto::class, 'soglia'))->setValue(null, null);
});

/** Via Verdi intero: le rate e gli incassi, il passaggio di U2 con la coppia, le spese, il citofono di U3 e una voce senza tabella. */
function rcgViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    cpcSpese($s);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $s['varie'] = cpmVoce($s, 'Varie', [], 'spesa', 0);
    cpmSpesa($s, $s['giornale'], $s['varie'], 12345);

    return $s;
}

function rcgProps($test, array $s, array $query = []): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]).($query ? '?'.http_build_query($query) : ''))
        ->assertOk()->viewData('page')['props'];
}

/** Una riga della scheda per nome e unità, fra le righe di una gestione. */
function rcgRiga(array $props, array $s, string $chi, string $unita): array
{
    foreach ($props['conguaglio']['righe'] as $r) {
        if (! $r['subtotale'] && $r['anagrafica_id'] === $s['persone'][$chi]->id && $r['immobile_id'] === $s['unita'][$unita]->id) {
            return $r;
        }
    }
    throw new RuntimeException("nessuna riga per {$chi}");
}

/** Le parole che una proposta di conguaglio non dice mai. */
const RCG_PAROLE_VIETATE = '/\b(dovut[oaie]|esigibil[ei]|pront[oaie]|conform[ei]|corrett[oaie]|in regola)\b/i';

/** Un consuntivo per persona che conta le sue letture: la lettura per richiesta è una sola. */
function rcgContaLetture(): object
{
    $contatore = new class extends ConsuntivoPerPersona {
        public static int $letture = 0;

        public function perEsercizio(\App\Models\Esercizio $esercizio, array $righePer = [], ?string $alla = null, bool $perTabella = false): array
        {
            self::$letture++;

            return parent::perEsercizio($esercizio, $righePer, $alla, $perTabella);
        }
    };
    $contatore::$letture = 0;
    app()->instance(ConsuntivoPerPersona::class, $contatore);

    return $contatore;
}

it('la scheda «Conguaglio» ha una riga per persona e unità con le colonne del progetto, i giorni di chi ha avuto l\'unità per una parte dell\'anno, la riga del condominio e le uscite che nessuno paga', function () {
    $s = rcgViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $p = rcgProps($this, $s);
    $colonne = fn (array $r) => array_intersect_key($r, array_flip(['a', 'personali', 'b', 'conguaglio', 'posizione', 'fuori', 'saldo', 'giorni']));

    expect(array_map(fn ($r) => $r['nome'], $p['conguaglio']['righe']))->toBe(['Bianchi', 'Neri', 'Rossi', 'Verdi'])
        ->and($colonne(rcgRiga($p, $s, 'Neri', 'U1')))->toBe(['giorni' => null, 'a' => 210000, 'personali' => 0, 'b' => 200000, 'conguaglio' => 10000, 'posizione' => 30000, 'fuori' => 0, 'saldo' => 40000])
        ->and($colonne(rcgRiga($p, $s, 'Bianchi', 'U2')))->toBe(['giorni' => 181, 'a' => 62482, 'personali' => 0, 'b' => 59507, 'conguaglio' => 2975, 'posizione' => 0, 'fuori' => -60493, 'saldo' => -57518])
        ->and($colonne(rcgRiga($p, $s, 'Rossi', 'U2')))->toBe(['giorni' => 184, 'a' => 63518, 'personali' => 0, 'b' => 60493, 'conguaglio' => 3025, 'posizione' => 0, 'fuori' => 60493, 'saldo' => 63518])
        ->and($colonne(rcgRiga($p, $s, 'Verdi', 'U3')))->toBe(['giorni' => null, 'a' => 84000, 'personali' => 8000, 'b' => 80000, 'conguaglio' => 12000, 'posizione' => 0, 'fuori' => 0, 'saldo' => 12000])
        ->and(array_intersect_key($p['conguaglio']['condominio'], array_flip(['a', 'personali', 'b', 'conguaglio', 'posizione', 'fuori_dalle_rate', 'saldo', 'non_attribuite'])))
        ->toBe(['a' => 420000, 'personali' => 8000, 'b' => 400000, 'conguaglio' => 28000, 'posizione' => 30000, 'fuori_dalle_rate' => 0, 'saldo' => 58000, 'non_attribuite' => 12345])
        // La voce senza tabella: nessuno la paga, con il motivo e che cosa fare.
        ->and($p['conguaglio']['gestioni'][0]['non_attribuite'])->toBe([['conto_id' => $s['varie']->id, 'voce' => 'Varie', 'piano_conto_id' => (int) $s['varie']->piano_conto_id,
            'importo' => 12345, 'motivo' => 'La voce non ha una tabella millesimale. Collega una tabella con i millesimi alla voce.']])
        // La coppia del passaggio: una nota per l'amministratore.
        ->and(rcgRiga($p, $s, 'Bianchi', 'U2')['note'])->toBe(['Nelle quote € -604,93 della coppia del passaggio.'])
        ->and($p['conguaglio']['data']['frase'])->toStartWith('Alla fine dell\'esercizio, il 31/12/2026; calcolato il ')
        ->and($p['frasi']['proposta'])->toBe('Proposta di conguaglio da approvare dall\'assemblea: diventa una richiesta solo con l\'approvazione.')
        ->and(preg_match(RCG_PAROLE_VIETATE, json_encode($p['conguaglio'], JSON_UNESCAPED_UNICODE).json_encode($p['frasi'], JSON_UNESCAPED_UNICODE)))->toBe(0);
});

it('chi ha più unità ha il subtotale dopo le sue righe, che restano: mai un netto fra unità', function () {
    $s = rcgViaVerdi($this);
    // Neri compra anche U3 a metà con Verdi dal 2020: due righe di Neri (U1 e U3) e il suo totale.
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['U3']->id)->update(['quota' => 50]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Neri']->id, 'immobile_id' => $s['unita']['U3']->id, 'tipologia' => 'proprietario', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $this->travelTo('2027-01-10 10:00:00');
    $righe = rcgProps($this, $s)['conguaglio']['righe'];
    $diNeri = array_values(array_filter($righe, fn ($r) => $r['anagrafica_id'] === $s['persone']['Neri']->id));

    expect(count($diNeri))->toBe(3)
        ->and($diNeri[2]['subtotale'])->toBeTrue()
        ->and($diNeri[2]['nome'])->toBe('Totale di Neri')
        ->and($diNeri[2]['a'])->toBe($diNeri[0]['a'] + $diNeri[1]['a'])
        ->and($diNeri[2]['saldo'])->toBe($diNeri[0]['saldo'] + $diNeri[1]['saldo']);
});

it('per l\'esercizio aperto la scheda dice «a oggi», con la data di oggi', function () {
    $s = rcgViaVerdi($this);
    $this->travelTo('2026-12-15 10:00:00');
    $p = rcgProps($this, $s);

    expect($p['conguaglio']['data']['alla'])->toBe('2026-12-15')
        ->and($p['conguaglio']['data']['finito'])->toBeFalse()
        ->and($p['conguaglio']['data']['frase'])->toStartWith('Esercizio aperto: a oggi, 15/12/2026, e cambia a ogni registrazione; calcolato il 15/12/2026 alle ');
});

it('la scheda, le stampe e i fogli stanno dietro il pannello e nel loro condominio: un esercizio o una persona di un altro fanno 404', function () {
    $s = rcgViaVerdi($this);
    $altro = cpcViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    foreach (['admin.gestionale.esercizi.rendiconto.conguaglio', 'admin.gestionale.esercizi.rendiconto.conguaglio.stampa',
        'admin.gestionale.esercizi.rendiconto.conguaglio.per-tabella.stampa', 'admin.gestionale.esercizi.rendiconto.conguaglio.fogli'] as $rotta) {
        $this->actingAs($this->user)->get(route($rotta, [$s['c'], $altro['e']]))->assertNotFound();
    }
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.foglio', [$s['c'], $s['e'], $altro['persone']['Neri']->id]))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertForbidden();
});

it('il riquadro della Sintesi ha le cifre del condominio dalla stessa lettura della scheda, letta una volta; sopra la soglia rimanda alla scheda e non legge', function () {
    $s = rcgViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $contatore = rcgContaLetture();
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    $r = $props['sintesi']['riquadri']['conguaglio'];

    expect($contatore::$letture)->toBe(1)
        ->and(array_intersect_key($r['cifre'], array_flip(['a', 'personali', 'b', 'conguaglio', 'saldo'])))->toBe(['a' => 420000, 'personali' => 8000, 'b' => 400000, 'conguaglio' => 28000, 'saldo' => 58000])
        ->and($r['peso'])->toBe(3 * 1);

    (new ReflectionProperty(ConguaglioDelRendiconto::class, 'soglia'))->setValue(null, 2);
    $contatore::$letture = 0;
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['sintesi']['riquadri']['conguaglio'];
    expect($contatore::$letture)->toBe(0)
        ->and($r['cifre'])->toBeNull()
        ->and($r['frase'])->toBe(ConguaglioDelRendiconto::FRASE_RIMANDA);
});

it('la stampa del conguaglio per persona: nove colonne, «Come leggere» con il segno e la proposta, nessuna nota sui passaggi e nessuna parola vietata', function () {
    $s = rcgViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $risposta = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.stampa', [$s['c'], $s['e']]))->assertOk();
    expect($risposta->headers->get('Content-Type'))->toBe('application/pdf');

    $c = app(ConguaglioDelRendiconto::class);
    $righe = $c->righe($s['c'], $s['e'], $c->leggi($s['e']), null, true);
    $testo = vrmTesto(view('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'conguaglio' => $righe])->render());

    foreach (['Unità', 'Soggetto', 'Giorni', 'Spese dell\'esercizio', 'Spese personali non chieste', 'Quote dei piani', 'Proposta di conguaglio, da approvare',
        'Già da versare', 'Saldo se l\'assemblea approva'] as $colonna) {
        expect($testo)->toContain($colonna);
    }
    // Il perché della voce senza tabella è quello per chi legge: la causa, senza l'istruzione della scheda.
    expect($testo)->toContain(ConguaglioDelRendiconto::FRASE_SEGNO)->toContain(\App\Services\Gestionale\ParoleDelConguaglio::PROPOSTA)->toContain('calcolato il')
        ->toContain('€ +29,75')->toContain('€ -604,93')->toContain('€ +580,00')->toContain('Varie')->toContain(\App\Services\Gestionale\ParoleDelConguaglio::MOTIVI['conto_senza_tabella'])
        ->not->toContain('Collega una tabella con i millesimi alla voce')
        ->not->toContain('regolat')->not->toContain('coppia del passaggio')->not->toContain('Controllalo')
        ->and(preg_match(RCG_PAROLE_VIETATE, $testo))->toBe(0)
        // Le righe senza note, anche nell'array della stampa.
        ->and(array_merge(...array_map(fn ($r) => $r['note'], $righe['gestioni'][0]['righe'])))->toBe([]);
});

it('la stampa del conguaglio per persona va a blocchi di 100 righe: 250 righe, tre tabelle', function () {
    $riga = ['anagrafica_id' => 1, 'nome' => 'Persona', 'immobile_id' => 1, 'unita' => 'U', 'giorni' => null, 'a' => 100, 'personali' => 0, 'b' => 100, 'b_non_emesse' => 0,
        'conguaglio' => 0, 'posizione' => 0, 'fuori' => 0, 'saldo' => 0, 'non_disponibile' => null, 'note' => [], 'subtotale' => false];
    $totali = ['a' => 25000, 'personali' => 0, 'b' => 25000, 'conguaglio' => 0, 'posizione' => 0, 'fuori_dalle_rate' => 0, 'saldo' => 0, 'non_disponibili' => 0];
    $html = view('pdf.gestionale.conguaglio_per_persona', ['condominio' => \App\Models\Condominio::factory()->make(['nome' => 'Prova']), 'esercizio' => new \App\Models\Esercizio(['nome' => '2026']),
        'conguaglio' => ['gestioni' => [['id' => 1, 'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'non_calcolabile' => null, 'righe' => array_fill(0, 250, $riga), 'totali' => $totali, 'non_attribuite' => []]],
            'da_altre_gestioni' => ['nome' => ConguaglioDelRendiconto::NOME_DA_ALTRE, 'righe' => [], 'totali' => null], 'fuori_dai_capitoli' => [],
            'condominio' => $totali + ['non_attribuite' => 0], 'data' => ['frase' => 'Alla fine dell\'esercizio.'], 'secondi' => 0.1]])->render();

    expect(substr_count($html, '<thead>'))->toBe(3);
});

it('il consuntivo per tabella: i millesimi e la spesa di ogni unità e persona sulla tabella, e il totale è la colonna delle spese dell\'esercizio', function () {
    $s = rcgViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $t = $c->perTabella($s['c'], $s['e'], $c->leggi($s['e'], [], true));
    $g = $t['gestioni'][0];
    $tid = $s['generali']->id;
    $riga = fn (string $chi) => collect($g['righe'])->firstWhere('anagrafica_id', $s['persone'][$chi]->id);

    expect(array_column($g['tabelle'], 'nome'))->toBe(['Generali'])
        ->and(array_map(fn ($chi) => [$riga($chi)['unita'], $riga($chi)['ruolo'], (float) $riga($chi)['tabelle'][$tid]['quota'], $riga($chi)['tabelle'][$tid]['importo'], $riga($chi)['totale']],
            ['Neri', 'Bianchi', 'Rossi', 'Verdi']))
        ->toBe([['Int. U1', 'P', 500.0, 210000, 210000], ['Int. U2', 'P', 300.0, 62482, 62482], ['Int. U2', 'P', 300.0, 63518, 63518], ['Int. U3', 'P', 200.0, 84000, 84000]])
        ->and($g['totali'][$tid])->toBe(420000)
        ->and($g['totale'])->toBe(420000)
        ->and($riga('Bianchi')['giorni'])->toBe(181);

    $risposta = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.per-tabella.stampa', [$s['c'], $s['e']]))->assertOk();
    expect($risposta->headers->get('Content-Type'))->toBe('application/pdf');
    $testo = vrmTesto(view('pdf.gestionale.consuntivo_per_tabella', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'consuntivo' => $t])->render());
    expect($testo)->toContain('Generali')->toContain('500,00')->toContain('€ 2.100,00')->toContain('€ 4.200,00')->toContain('181 giorni')
        ->and(preg_match(RCG_PAROLE_VIETATE, $testo))->toBe(0);
});

it('il foglio della persona: le sue unità voce per voce con la tabella, i millesimi e i giorni, A, B, il conguaglio, il saldo e le righe che spiegano', function () {
    $s = rcgViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $risposta = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.foglio', [$s['c'], $s['e'], $s['persone']['Bianchi']->id]))->assertOk();
    expect($risposta->headers->get('Content-Type'))->toBe('application/pdf');

    $c = app(ConguaglioDelRendiconto::class);
    $f = app(\App\Services\Gestionale\FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e'], $c->leggi($s['e'], [], true), $s['persone']['Bianchi']->id);
    $u = $f['gestioni'][0]['unita'][0];

    expect($u['unita'])->toBe('Int. U2')->and($u['giorni'])->toBe(181)
        ->and($u['voci'])->toBe([
            ['voce' => 'Pulizie', 'tabella' => 'Generali', 'millesimi' => '300,00 su 1.000,00', 'importo' => 49093],
            ['voce' => 'Manutenzioni', 'tabella' => 'Generali', 'millesimi' => '300,00 su 1.000,00', 'importo' => 13389],
        ])
        ->and([$u['a'], $u['b']['totale'], $u['conguaglio'], $u['posizione'], $u['fuori'], $u['saldo']])->toBe([62482, 59507, 2975, 0, -60493, -57518])
        ->and($f['spiegano'])->toMatchArray(['versato_in_cassa' => 120000, 'usato_un_credito' => 0, 'anno_prima' => 0]);

    $testo = vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'foglio' => $f])->render());
    expect($testo)->toContain(ConguaglioDelRendiconto::FRASE_SEGNO)->toContain('Versato in cassa nell\'esercizio')->toContain('€ 1.200,00')->toContain('181 giorni')
        ->toContain('€ +29,75')->not->toContain('regolat')->not->toContain('coppia')
        ->and(preg_match(RCG_PAROLE_VIETATE, $testo))->toBe(0);

    // Il credito di Verdi dall'anno prima (−50,00) è una riga che spiega.
    $v = app(\App\Services\Gestionale\FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e'], $c->leggi($s['e'], [], true), $s['persone']['Verdi']->id);
    expect($v['spiegano'])->toMatchArray(['anno_prima' => -5000, 'versato_in_cassa' => 75000]);
});

it('i fogli in un archivio: le persone scelte, un PDF a testa e l\'elenco degli indirizzi; la scelta si ricorda; nessuna persona scelta non fa un archivio vuoto', function () {
    $s = rcgViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $leggiZip = function ($risposta): array {
        $percorso = $risposta->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive();
        $zip->open($percorso);
        $nomi = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nomi[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($nomi);

        return $nomi;
    };

    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.fogli', [$s['c'], $s['e']]).'?chi=tutte')->assertOk();
    expect($leggiZip($r))->toBe(['Bianchi.pdf', 'Indirizzi email.txt', 'Neri.pdf', 'Rossi.pdf', 'Verdi.pdf']);

    // Tutti e quattro hanno l'email; a debito sono tutti e quattro. Senza l'email Rossi esce da «con l'email».
    DB::table('anagrafiche')->where('id', $s['persone']['Rossi']->id)->update(['email' => null]);
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.fogli', [$s['c'], $s['e']]).'?chi=con_email')->assertOk();
    expect($leggiZip($r))->toBe(['Bianchi.pdf', 'Indirizzi email.txt', 'Neri.pdf', 'Verdi.pdf']);

    // La scheda ricorda l'ultima scelta e dice quante persone per ogni scelta.
    $p = rcgProps($this, $s);
    expect($p['fogli']['ricordata'])->toBe('con_email')
        ->and($p['fogli']['tutto'])->toBe(['tutte' => 4, 'a_debito' => 4, 'con_email' => 3, 'non_disponibili' => 0])
        ->and($p['fogli']['tetto'])->toBe(200);
});

it('il fascicolo ha la parte «Riparto del consuntivo e conguaglio» dopo i condòmini, con le due tabelle, dalla stessa lettura dei controlli', function () {
    $s = rcgViaVerdi($this);
    $this->travelTo('2027-01-10 10:00:00');
    $contatore = rcgContaLetture();
    $esito = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['conguaglio', 'condomini']);

    expect($contatore::$letture)->toBe(1)
        ->and(array_column($esito['indice'], 'titolo'))->toBe(['Posizioni dei condòmini', 'Crediti per anzianità', 'Consuntivo per tabella', 'Conguaglio per persona'])
        ->and(array_column($esito['indice'], 'parte'))->toBe(['condomini', 'condomini', 'conguaglio', 'conguaglio'])
        ->and(FascicoloDelRendiconto::PARTI)->toBe(['riepilogo', 'situazione', 'cassa', 'condomini', 'conguaglio', 'registro', 'controlli']);
    // Le righe della parte nella stima, prima di leggere: le quattro titolarità della gestione (Neri, Bianchi, Rossi, Verdi), il consuntivo per tabella in
    // celle (4 righe × (1 tabella + 9)) e il peso, 3 unità × una tabella.
    expect(FascicoloDelRendiconto::righeDelConguaglio($s['e']))->toBe(['conguaglio_lettura' => 3, 'conguaglio_persona' => 4, 'conguaglio_tabella' => 40]);
});
