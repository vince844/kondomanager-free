<?php

/**
 * 1.11.0-beta.47: il prospetto degli oneri accessori dopo un cambio d'inquilino, e sull'unità mista con il ripiego.
 *
 * Il prospetto (`ProspettoOneriAccessori`, e la sua stampa) è il documento con cui il proprietario regola con il conduttore le
 * voci a carico dell'inquilino: deve dire ciò che ciascuno ha pagato davvero. Qui si presidia che lo dica anche quando la
 * titolarità è cambiata dopo la generazione del piano, con le cifre fatte a mano.
 *
 * Nasce dai difetti della locazione del rapporto della sessione 3-bis (§5.4–§5.6):
 * - **DL1**: il cambio d'inquilino («Fine locazione» con il nuovo inquilino) registrato dopo la generazione, con le rate a
 *   giornale e il conguaglio: il conguaglio dà a chi entra i suoi giorni, il prospetto li lasciava tutti a chi esce e non
 *   nominava chi entra. Due forme: un cambio solo, e due cambi di fila (il secondo divide la parte del primo entrato).
 *   La parte di chi entra è pagata con il conguaglio, e la stampa lo dice accanto alla sua voce;
 * - **DL4**: sull'unità mista il ripiego sta in due righe gemelle (usufruttuario e proprietario pieno, decisione 31.1) con lo
 *   stesso tratto, e i giorni «senza conduttore» si contavano due volte;
 * - **DL5**: sull'unità mista con il ripiego in due tratti, un inquilino registrato dopo la generazione non aveva niente da
 *   rimborsare: le due righe gemelle si toglievano i giorni l'una all'altra.
 * I controlli, verdi prima e dopo la correzione: il cambio d'inquilino annullato e la rinuncia al conguaglio lasciano il
 * prospetto com'era (decisione 43: con la rinuncia le parti hanno regolato fra loro).
 *
 * Le cifre: «Spese generali» € 1.200,00 l'anno, dodici rate dal 5 gennaio, 365 giorni; divisione per giorni con i resti
 * maggiori, come `ProRataTemporis` e il motore. Ogni test controlla prima che il conguaglio o il riparto diano le cifre a mano
 * (lo scenario è quello giusto), poi il prospetto.
 *
 * Cosa NON copre: la fine locazione **senza** nuovo inquilino su un piano a giornale (la decisione 32, non ancora costruita);
 * i co-inquilini; le frasi del pannello (DL2, DL3). Il campo con la data del conguaglio sulla riga non si guarda: la stampa sì.
 *
 * In fondo, i test dei rilievi della Fase 1-bis della .47 (rossi sul codice della .47, ciascuno con il suo «NON copre»): la
 * vendita della metà piena sull'unità mista nel prospetto (P9), il box locato insieme con il conguaglio, la rinuncia e il
 * conguaglio annullato dalla sua rotta, il già versato «dell'unità», la voce su due tabelle, il piede della stampa con due
 * piani nello stesso esercizio; e il controllo del perimetro (lo stesso inquilino su due unità).
 *
 * Più in fondo ancora, i due giri sulle correzioni. Del secondo (giro 47b): le gemelle con catene diverse, dove lo scarto del
 * centesimo va a chi ha pagato in origine e non si perde (oggi il totale vale € 1.200,01); il già versato della persona e dell'unità sulle
 * gemelle; il ripiego su due tabelle; la riga «già versato» del ripiego in stampa; il controllo del perimetro della catena dei
 * pagatori fra due unità. Dove l'usufruttuario muore si registra prima l'estinzione, poi la successione (la fermata del giro).
 * Del terzo (giro 47c): le gemelle su due tabelle, dove il prospetto arrotondava ancora tabella per tabella.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

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
 * Il condominio di `ruScenario` (un'unità da 1000 millesimi, «Spese generali» € 1.200,00, dodici rate dal 5 gennaio, Ugo
 * proprietario dal 2019), con la voce divisa fra i ruoli dati (somma 100). Il piano non è generato.
 */
function pciScenario(array $ripartizione): array
{
    $s = ruScenario('prima_rata', 0, soggetto: 'inquilino', genera: false);
    $ctm = (int) DB::table('conto_tabella_millesimale')
        ->join('conti', 'conti.id', '=', 'conto_tabella_millesimale.conto_id')
        ->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')
        ->where('piani_conti.gestione_id', $s['g']->id)
        ->value('conto_tabella_millesimale.id');
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->delete();
    foreach ($ripartizione as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $s;
}

/** Una persona del condominio dello scenario. */
function pciPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'pci' . $n . '-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'PCIPERSONA' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Una riga di titolarità censita dal 2019. */
function pciTitolare(array $s, Anagrafica $p, string $ruolo, float $quota = 100): void
{
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * L'unità mista: Ugo proprietario pieno al 50 % e usufruttuario dell'altro 50 %, Bice nuda proprietaria di quel 50 %.
 * Restituisce Bice.
 */
function pciMista(array $s): Anagrafica
{
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    pciTitolare($s, $s['v'], 'usufruttuario', 50);
    $bice = pciPersona($s, 'Bice Nuda');
    pciTitolare($s, $bice, 'nuda_proprietario', 50);

    return $bice;
}

/** La generazione del piano dello scenario, con la presa d'atto del cancello (2). */
function pciGenera(array $s): void
{
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: inquilini cambiati nell\'anno', esercizio: $s['e']);
}

/** «Inizio locazione» dalla rotta vera. */
function pciInizio($test, array $s, Anagrafica $entra, string $il): Subentro
{
    return ruRegistra($test, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $entra->id, 'decorrenza' => $il, 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'regime_contratto' => 'abitativo', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Contratto di locazione letto']);
}

/** «Fine locazione» dalla rotta vera, con il nuovo inquilino (il cambio d'inquilino) o senza. */
function pciFine($test, array $s, Anagrafica $esce, ?Anagrafica $entra, string $il, array $extra = []): Subentro
{
    $riga = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $esce->id)->where('tipologia', 'inquilino')
        ->whereNull('data_fine')->orderByDesc('id')->value('id');

    return ruRegistra($test, $s, ['tipo' => 'fine_locazione', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $entra?->id, 'decorrenza' => $il, 'quota' => 100,
        'tipologia' => 'inquilino', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Disdetta del contratto letta'] + $extra);
}

/** Il prospetto dell'unità dello scenario nell'esercizio 2026. */
function pciProspetto(array $s): array
{
    return app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
}

/**
 * Per conduttore: [totale, giorni delle voci, modi delle voci], in ordine di nome.
 *
 * @return array<string, array{0: int, 1: int, 2: string}>
 */
function pciConduttori(array $p): array
{
    $out = [];
    foreach ($p['conduttori'] as $c) {
        $out[$c['nome']] = [(int) $c['totale'], (int) collect($c['voci'])->sum('giorni'), collect($c['voci'])->pluck('modo')->unique()->sort()->implode(', ')];
    }
    ksort($out);

    return $out;
}

/**
 * Ciò che ciascuno paga del piano sull'unità: la quota pura delle quote a suo nome, più le righe dei conguagli. Senza gli zeri,
 * in ordine di nome.
 *
 * @return array<string, int>
 */
function pciNetti(array $s): array
{
    $netti = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.immobile_id', $s['unita']->id)->get(['rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.regole_calcolo']);
    foreach ($quote as $q) {
        $pura = json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? $q->importo;
        $netti[(int) $q->anagrafica_id] = ($netti[(int) $q->anagrafica_id] ?? 0) + (int) $pura;
    }
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->get() as $r) {
        $netti[(int) $r->anagrafica_id] = ($netti[(int) $r->anagrafica_id] ?? 0) + (int) $r->saldo_iniziale;
    }
    $nomi = Anagrafica::whereIn('id', array_keys($netti))->pluck('nome', 'id');
    $out = [];
    foreach ($netti as $id => $c) {
        if ($c !== 0) {
            $out[$nomi[$id]] = $c;
        }
    }
    ksort($out);

    return $out;
}

/**
 * DL1, prima forma: Ines inquilina dal 2019, «Spese generali» 30 % «Inquilino» (€ 360,00) e 70 % «Proprietario» (€ 840,00),
 * piano generato con Ines, sei rate emesse fino al 30/6; il 1/7 Ines lascia l'unità a Luca, con il conguaglio.
 *
 * @return array{0: array, 1: Anagrafica, 2: Anagrafica, 3: Subentro} lo scenario, Ines, Luca, il cambio d'inquilino
 */
function pciCambioDelPrimoLuglio($test, array $extra = []): array
{
    $s = pciScenario(['inquilino' => 30, 'proprietario' => 70]);
    $ines = pciPersona($s, 'Ines Inquilina');
    pciTitolare($s, $ines, 'inquilino');
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $luca = pciPersona($s, 'Luca Nuovo');
    $cambio = pciFine($test, $s, $ines, $luca, '2026-07-01', $extra);

    return [$s, $ines, $luca, $cambio];
}

it('DL1 — cambio d\'inquilino il 1/7 registrato dopo la generazione, con sei rate a giornale: il prospetto dà a Ines i suoi 181 giorni nelle sue rate e a Luca i suoi 184, pagati con il conguaglio', function () {
    [$s] = pciCambioDelPrimoLuglio($this);
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(6);

    // Il conguaglio: € 360,00 × 181/365 = € 178,5205 e × 184/365 = € 181,4795; resti maggiori: 17852 + 18148 = 36000.
    // Ines paga 36000 nelle rate e ha 18148 a credito (36000 − 18148 = 17852); Luca 18148 a debito; Ugo il «Proprietario» 84000.
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 17852, 'Luca Nuovo' => 18148, 'Venditore Ugo' => 84000]);

    $p = pciProspetto($s);
    // Il prospetto deve dire lo stesso: sono i soldi che ciascuno ha versato davvero per la parte dell'inquilino.
    // Ines 1/1–30/6: 31 + 28 + 31 + 30 + 31 + 30 = 181 giorni → 17852, nelle sue rate.
    // Luca 1/7–31/12: 31 + 31 + 30 + 31 + 30 + 31 = 184 giorni → 18148 (36000 − 17852), con il conguaglio.
    expect(pciConduttori($p))->toBe(['Ines Inquilina' => [17852, 181, 'rate'], 'Luca Nuovo' => [18148, 184, 'conguaglio']]);
    $ines = collect($p['conduttori'])->firstWhere('nome', 'Ines Inquilina');
    $luca = collect($p['conduttori'])->firstWhere('nome', 'Luca Nuovo');
    // Nessuno dei due deve niente al proprietario: Ines paga nelle sue rate, Luca con la sua riga del conguaglio.
    expect($ines['nelle_sue_rate'])->toBe(17852)->and($ines['da_rimborsare'])->toBe(0)
        ->and($luca['da_rimborsare'])->toBe(0)
        ->and($p['senza_conduttore']['totale'])->toBe(0)
        ->and($p['totale_inquilino'])->toBe(36000);
});

it('DL1, seconda forma — due cambi d\'inquilino, Ines a Luca il 1/4 e Luca a Teo il 1/7: il prospetto dà a ciascuno i suoi giorni, il secondo pezzo diviso sulla parte di Luca come il conguaglio', function () {
    $s = pciScenario(['inquilino' => 30, 'proprietario' => 70]);
    $ines = pciPersona($s, 'Ines Inquilina');
    pciTitolare($s, $ines, 'inquilino');
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-03-31');
    $luca = pciPersona($s, 'Luca Nuovo');
    pciFine($this, $s, $ines, $luca, '2026-04-01');
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $teo = pciPersona($s, 'Teo Terzo');
    pciFine($this, $s, $luca, $teo, '2026-07-01');

    // Primo cambio: Ines 1/1–31/3 = 31 + 28 + 31 = 90 giorni, € 360,00 × 90/365 = € 88,7671; il resto 275 giorni,
    // € 360,00 × 275/365 = € 271,2329; resti maggiori: 8877 + 27123 = 36000.
    // Secondo cambio, sulla parte di Luca (27123 per 275 giorni): Luca 1/4–30/6 = 30 + 31 + 30 = 91 giorni,
    // € 271,23 × 91/275 = € 89,7524; Teo 1/7–31/12 = 184 giorni, € 271,23 × 184/275 = € 181,4776; resti maggiori: 8975 + 18148 = 27123.
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 8877, 'Luca Nuovo' => 8975, 'Teo Terzo' => 18148, 'Venditore Ugo' => 84000]);

    $p = pciProspetto($s);
    expect(pciConduttori($p))->toBe(['Ines Inquilina' => [8877, 90, 'rate'], 'Luca Nuovo' => [8975, 91, 'conguaglio'], 'Teo Terzo' => [18148, 184, 'conguaglio']])
        ->and($p['senza_conduttore']['totale'])->toBe(0)
        ->and($p['totale_inquilino'])->toBe(36000);
});

it('controllo — il cambio d\'inquilino annullato: il conguaglio non c\'è più, e il prospetto torna a dare a Ines € 360,00 per 365 giorni nelle sue rate', function () {
    [$s, , , $cambio] = pciCambioDelPrimoLuglio($this);
    ruAnnulla($this, $s, $cambio)->assertStatus(302);
    expect(Subentro::whereKey($cambio->id)->exists())->toBeFalse();

    // Senza il passaggio: Ines inquilina tutto l'anno, 30 % di € 1.200,00 = 36000 per 365 giorni; Ugo 84000.
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 36000, 'Venditore Ugo' => 84000]);
    $p = pciProspetto($s);
    expect(pciConduttori($p))->toBe(['Ines Inquilina' => [36000, 365, 'rate']])
        ->and(collect($p['conduttori'])->firstWhere('nome', 'Ines Inquilina')['da_rimborsare'])->toBe(0)
        ->and($p['senza_conduttore']['totale'])->toBe(0);
});

it('controllo — il cambio d\'inquilino con la rinuncia al conguaglio: le parti hanno regolato fra loro, e il prospetto resta com\'era, € 360,00 a Ines per 365 giorni nelle sue rate', function () {
    [$s] = pciCambioDelPrimoLuglio($this, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino']);

    // Con la rinuncia nessuna coppia: Ines resta con le sue 36000 nelle rate, Luca non ha niente verso il condominio.
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 36000, 'Venditore Ugo' => 84000]);
    $p = pciProspetto($s);
    expect(pciConduttori($p))->toBe(['Ines Inquilina' => [36000, 365, 'rate']])
        ->and($p['senza_conduttore']['totale'])->toBe(0);
});

it('DL1 — la stampa del cambio d\'inquilino del 1/7 dice accanto alla voce di Luca che l\'ha pagata con il conguaglio, e nessuna voce dice «pagata da» senza un nome', function () {
    [$s] = pciCambioDelPrimoLuglio($this);
    $html = view('pdf.gestionale.prospetto_oneri_accessori', [
        'condominio' => $s['c'], 'esercizio' => $s['e'], 'immobile' => $s['unita'],
        'prospetto' => pciProspetto($s),
        'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => null,
    ])->render();
    $testo = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    // Una voce del modo nuovo che la stampa non sa dire diventerebbe «pagata da » seguito da niente (la cella si chiude subito).
    preg_match_all('/pagata da\s*<\/?[a-z]+/u', $html, $senzaNome);
    expect($senzaNome[0])->toBe([]);
    // La sezione di Luca, dal suo nome al suo totale (senza il totale di un'altra sezione in mezzo).
    preg_match('/Luca Nuovo (?:(?!Totale a carico di ).)*Totale a carico di Luca Nuovo/u', $testo, $m);
    expect($m[0] ?? '(nessuna sezione di Luca Nuovo nella stampa)')->toContain('con il conguaglio del cambio d\'inquilino del 01/07/2026');
});

it('DL4 — unità mista, Ines inquilina dal 1/3 al 31/8 registrata prima della generazione: i 181 giorni senza conduttore, pagati da Ugo con le sue due righe gemelle, sono 181 e non 362', function () {
    $s = pciScenario(['inquilino' => 30, 'proprietario' => 70]);
    pciMista($s);
    $ines = pciPersona($s, 'Ines Inquilina');
    pciInizio($this, $s, $ines, '2026-03-01');
    pciFine($this, $s, $ines, null, '2026-09-01');
    pciGenera($s);

    // Ines 1/3–31/8: 31 + 30 + 31 + 30 + 31 + 31 = 184 giorni; senza conduttore 59 (gennaio e febbraio) + 122 (settembre–dicembre)
    // = 181 giorni, che il ripiego dà a Ugo in due righe gemelle al 50 %: pesi 100 × 184, 50 × 181, 50 × 181 su 36500,
    // € 360,00 × 18400/36500 = € 181,4795 e × 9050/36500 = € 89,2603 per riga; resti maggiori: 18148 + 8926 + 8926 = 36000.
    $p = pciProspetto($s);
    expect(pciConduttori($p))->toBe(['Ines Inquilina' => [18148, 184, 'rate']])
        // 8926 + 8926 = 17852 = € 360,00 × 181/365 (€ 178,5205).
        ->and($p['senza_conduttore']['totale'])->toBe(17852)
        ->and((int) collect($p['senza_conduttore']['voci'])->sum('giorni'))->toBe(181);
});

it('DL5 — unità mista con il ripiego in due tratti (febbraio e da giugno), piano a giornale, Ivo inquilino dal 1/7: il prospetto gli dà da rimborsare a Ugo i suoi 184 giorni, e i 58 senza conduttore restano a Ugo', function () {
    $s = pciScenario(['inquilino' => 100]);
    pciMista($s);
    $ines = pciPersona($s, 'Ines Inquilina');
    pciTitolare($s, $ines, 'inquilino');
    pciFine($this, $s, $ines, null, '2026-02-01');
    $luca = pciPersona($s, 'Luca Secondo');
    pciInizio($this, $s, $luca, '2026-03-01');
    pciFine($this, $s, $luca, null, '2026-06-01');
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $ivo = pciPersona($s, 'Ivo Ultimo');
    pciInizio($this, $s, $ivo, '2026-07-01');

    // Il riparto (pesi quota × giorni su 36500): Ines 1/1–31/1, 100 × 31; Luca 1/3–31/5, 100 × (31 + 30 + 31) = 100 × 92;
    // il ripiego di Ugo, febbraio 28 + dal 1/6 al 31/12 214 = 242 giorni, in due righe gemelle 50 × 242.
    // € 1.200,00 × 3100/36500 = € 101,9178; × 9200/36500 = € 302,4658; × 12100/36500 = € 397,8082 per riga gemella;
    // resti maggiori: 10192 + 30246 + 39781 + 39781 = 120000. Ugo 79562 (= € 1.200,00 × 242/365, € 795,6164).
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Venditore Ugo' => 79562]);

    // Ivo dal 1/7: 184 dei 242 giorni del ripiego, divisi una volta: 79562 × 184/242 = 60493,15 → 60493 da rimborsare a Ugo;
    // restano a Ugo i 58 giorni di febbraio e giugno (28 + 30): 79562 − 60493 = 19069 (79562 × 58/242 = 19068,85).
    $p = pciProspetto($s);
    $ivoNelProspetto = collect($p['conduttori'])->firstWhere('nome', 'Ivo Ultimo');
    expect($ivoNelProspetto['totale'] ?? 0)->toBe(60493)
        ->and($ivoNelProspetto['da_rimborsare'] ?? 0)->toBe(60493)
        ->and((int) collect($ivoNelProspetto['voci'] ?? [])->sum('giorni'))->toBe(184)
        ->and($p['senza_conduttore']['totale'])->toBe(19069)
        ->and((int) collect($p['senza_conduttore']['voci'])->sum('giorni'))->toBe(58);
});

/*
|--------------------------------------------------------------------------
| Fase 1-bis della beta.47 — i rilievi confermati sul prospetto (lenti denaro e decide)
|--------------------------------------------------------------------------
|
| Ogni test nasce da un rilievo della revisione: è rosso sul codice della .47 per la ragione del difetto, e diventa verde con
| la correzione. I controlli (verdi già oggi) lo dicono nel nome. Le cifre sono fatte a mano, con la somma accanto.
*/

/** La sezione di un conduttore nel prospetto, o null se il prospetto non lo nomina. */
function pciSezione(array $p, string $nome): ?array
{
    return collect($p['conduttori'])->firstWhere('nome', $nome);
}

/**
 * Le voci di una sezione: [voce, modo, pagata da, importo, giorni], in ordine di voce, modo e pagatore.
 *
 * @return list<array{0: string, 1: string, 2: ?string, 3: int, 4: ?int}>
 */
function pciVociDi(?array $sezione): array
{
    return collect($sezione['voci'] ?? [])
        ->map(fn ($v) => [$v['conto'], $v['modo'], $v['pagato_da'] ?? null, (int) $v['importo'], $v['giorni'] === null ? null : (int) $v['giorni']])
        ->sortBy(fn ($v) => $v[0] . '|' . $v[1] . '|' . ($v[2] ?? ''))->values()->all();
}

/** La stampa del prospetto dell'unità dello scenario, come testo (tag tolti, spazi uniti). */
function pciStampa(array $s): string
{
    $html = view('pdf.gestionale.prospetto_oneri_accessori', [
        'condominio' => $s['c'], 'esercizio' => $s['e'], 'immobile' => $s['unita'],
        'prospetto' => pciProspetto($s),
        'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => null,
    ])->render();

    return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

// Rilievo «unità mista, ripiego in due tratti, vendita della metà piena» (alto, lente denaro): oggi rosso, Ivo rimborsa
// tutto a Elsa. NON copre: la vendita della nuda o con riserva (la riserva la provano i test del giro sulle correzioni, in
// coda), due persone diverse sui due ruoli del godimento, la stampa. La forma in un tratto solo la provano T12 e T13, in coda.
it('rilievo P9 — unità mista con il ripiego in due tratti, Ugo vende la metà piena a Elsa il 1/7 e Ivo entra il 1/7: Ivo rimborsa a Elsa la parte del pieno (€ 302,47) e a Ugo quella dell\'usufrutto, che resta sua (€ 302,46); i 58 giorni senza conduttore restano a Ugo', function () {
    $s = pciScenario(['inquilino' => 100]);
    pciMista($s);
    $ines = pciPersona($s, 'Ines Inquilina');
    pciTitolare($s, $ines, 'inquilino');
    pciFine($this, $s, $ines, null, '2026-02-01');
    $luca = pciPersona($s, 'Luca Secondo');
    pciInizio($this, $s, $luca, '2026-03-01');
    pciFine($this, $s, $luca, null, '2026-06-01');
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 50) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto: Ugo vende la sua metà piena a Elsa']);
    $ivo = pciPersona($s, 'Ivo Ultimo');
    pciInizio($this, $s, $ivo, '2026-07-01');

    // Il riparto è quello del DL5: Ines 10192, Luca 30246, e il ripiego di Ugo in due righe gemelle (proprietario pieno e
    // usufruttuario) da € 1.200,00 × 12100/36500 = 39780,82 → 39781 ciascuna; 10192 + 30246 + 39781 + 39781 = 120000.
    // La vendita della metà piena sposta solo la riga del pieno: Elsa con il conguaglio paga 39781 × 184/242 = 30246,71 →
    // 30247; Ugo 39781 + 39781 − 30247 = 49315. Somma: 30247 + 10192 + 30246 + 49315 = 120000.
    expect(pciNetti($s))->toBe(['Acquirente Elsa' => 30247, 'Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Venditore Ugo' => 49315]);

    $p = pciProspetto($s);
    $ivoNelProspetto = pciSezione($p, 'Ivo Ultimo');
    // Ivo dal 1/7: 184 dei 242 giorni del ripiego, divisi una volta come nel DL5: 79562 × 184/242 = 60493,15 → 60493.
    // Chi li ha pagati: la riga del pieno, dal 1/7, Elsa (con il conguaglio): 39781 × 184/242 = 30246,71 → 30247, quanto il
    // suo conguaglio; la riga dell'usufrutto è ancora di Ugo, che resta usufruttuario: il resto, 60493 − 30247 = 30246.
    // Somma: 30247 + 30246 = 60493. Due voci «pagata da», una per persona, ciascuna sui 184 giorni di Ivo.
    expect(pciVociDi($ivoNelProspetto))->toBe([
        ['Spese generali', 'proprietario', 'Acquirente Elsa', 30247, 184],
        ['Spese generali', 'proprietario', 'Venditore Ugo', 30246, 184],
    ])
        ->and($ivoNelProspetto['totale'] ?? null)->toBe(60493)
        ->and($ivoNelProspetto['da_rimborsare'] ?? null)->toBe(60493);
    // Senza conduttore: febbraio (28) e giugno (30) = 58 giorni, prima della vendita, pagati da Ugo con le due righe:
    // 79562 − 60493 = 19069 (79562 × 58/242 = 19068,85), una voce, 58 giorni contati una volta (DL4).
    expect(collect($p['senza_conduttore']['voci'])->map(fn ($v) => [$v['pagato_da'], (int) $v['importo'], (int) $v['giorni']])->all())->toBe([['Venditore Ugo', 19069, 58]])
        ->and($p['senza_conduttore']['totale'])->toBe(19069);
});

/**
 * Il box locato insieme: l'unità a 900 millesimi e il box, sua pertinenza, a 100; «Spese generali» € 1.200,00 tutta
 * sull'«Inquilino»; Ugo proprietario e Ines inquilina di tutti e due dal 2019; piano generato, sei rate emesse fino al 30/6;
 * il 1/7 Ines lascia unità e box a Luca (fine locazione con il nuovo inquilino e `pertinenze = [box]`), con `$extra` in più
 * nel modulo.
 *
 * @return array{0: array, 1: array, 2: Subentro} lo scenario dell'unità, lo stesso scenario sul box, il cambio d'inquilino (il passaggio padre)
 */
function pciBoxLocatoInsieme($test, array $extra = []): array
{
    $s = pciScenario(['inquilino' => 100]);
    $box = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->update(['valore' => 900.0]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    $sb = ['unita' => $box] + $s;
    pciTitolare($sb, $s['v'], 'proprietario');
    $ines = pciPersona($s, 'Ines Inquilina');
    pciTitolare($s, $ines, 'inquilino');
    pciTitolare($sb, $ines, 'inquilino');
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $luca = pciPersona($s, 'Luca Nuovo');
    $riga = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $ines->id)->where('tipologia', 'inquilino')->whereNull('data_fine')->value('id');
    $cambio = ruRegistra($test, $s, array_merge(['tipo' => 'fine_locazione', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $luca->id, 'decorrenza' => '2026-07-01', 'quota' => 100,
        'tipologia' => 'inquilino', 'copia_autentica' => false, 'pertinenze' => [$box->id], 'ho_letto' => true, 'nota_cancello' => 'Disdetta del contratto letta, con il box'], $extra));

    return [$s, $sb, $cambio];
}

/** Le righe di saldo dei passaggi su un'unità: nome → centesimi. */
function pciSaldiDelPassaggio(array $s): array
{
    $nomi = Anagrafica::pluck('nome', 'id');

    return DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->orderBy('anagrafica_id')->get()
        ->mapWithKeys(fn ($r) => [$nomi[(int) $r->anagrafica_id] => (int) $r->saldo_iniziale])->sortKeys()->all();
}

// Rilievo «box locato insieme» (medio, lente denaro): il controllo con il conguaglio, verde già oggi. NON copre la rinuncia
// e il conguaglio annullato (i due test sotto), la stampa, un box con titolari diversi dall'unità.
it('controllo del rilievo sul box locato insieme — cambio d\'inquilino il 1/7 con il box e con il conguaglio: sul box Ines i suoi 181 giorni nelle rate (€ 59,51) e Luca i suoi 184 con il conguaglio (€ 60,49), come sull\'unità', function () {
    [$s, $sb] = pciBoxLocatoInsieme($this);

    // Unità: € 1.200,00 × 900/1000 = 108000; box: × 100/1000 = 12000 (108000 + 12000 = 120000), tutto nelle rate di Ines.
    // Il conguaglio del box: 12000 × 181/365 = 5950,68 e × 184/365 = 6049,32; resti maggiori: 5951 + 6049 = 12000.
    expect(pciSaldiDelPassaggio($sb))->toBe(['Ines Inquilina' => -6049, 'Luca Nuovo' => 6049])
        ->and(pciNetti($sb))->toBe(['Ines Inquilina' => 5951, 'Luca Nuovo' => 6049]);
    // Unità: 108000 × 181/365 = 53556,16 e × 184/365 = 54443,84; resti maggiori: 53556 + 54444 = 108000.
    expect(pciConduttori(pciProspetto($s)))->toBe(['Ines Inquilina' => [53556, 181, 'rate'], 'Luca Nuovo' => [54444, 184, 'conguaglio']])
        ->and(pciConduttori(pciProspetto($sb)))->toBe(['Ines Inquilina' => [5951, 181, 'rate'], 'Luca Nuovo' => [6049, 184, 'conguaglio']]);
});

// Rilievo «box locato insieme» (medio, lente denaro): con la rinuncia il prospetto del box divide ancora fra Ines e Luca.
// NON copre la stampa (test sotto), il conguaglio annullato dalla sua rotta, la rinuncia senza pertinenze (già sopra).
it('rilievo box locato insieme — cambio d\'inquilino il 1/7 con il box e con la rinuncia al conguaglio: il prospetto del box resta a Ines, € 120,00 per 365 giorni nelle sue rate, come quello dell\'unità; Luca non c\'è', function () {
    [$s, $sb, $cambio] = pciBoxLocatoInsieme($this, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino']);

    // La rinuncia sta sul padre; il figlio del box non la porta, ma è il passaggio che il prospetto trova sul box.
    expect($cambio->fresh()->conguaglioRinunciato())->toBeTrue()
        ->and(Subentro::where('subentro_padre_id', $cambio->id)->where('immobile_id', $sb['unita']->id)->exists())->toBeTrue();
    // Con la rinuncia nessuna coppia, né sull'unità né sul box: Ines resta con 12000 (€ 1.200,00 × 100/1000) nelle rate del box.
    expect(pciSaldiDelPassaggio($s))->toBe([])->and(pciSaldiDelPassaggio($sb))->toBe([])
        ->and(pciNetti($sb))->toBe(['Ines Inquilina' => 12000]);
    // L'unità, giusta già oggi: 108000 per 365 giorni nelle rate di Ines.
    expect(pciConduttori(pciProspetto($s)))->toBe(['Ines Inquilina' => [108000, 365, 'rate']]);
    // Il box deve dire lo stesso: 12000 per 365 giorni (181 + 184), nelle rate di Ines; niente Luca «con il conguaglio».
    expect(pciConduttori(pciProspetto($sb)))->toBe(['Ines Inquilina' => [12000, 365, 'rate']]);
});

// Rilievo «box locato insieme» (medio, lente denaro): dopo l'annullamento del conguaglio dalla sua rotta il prospetto del
// box divide ancora. NON copre la stampa (test sotto), l'annullamento del passaggio intero (verde, controllo sopra senza box).
it('rilievo box locato insieme — cambio d\'inquilino il 1/7 con il box, poi il conguaglio annullato dalla sua rotta: il prospetto del box torna a Ines, € 120,00 per 365 giorni nelle sue rate', function () {
    [$s, $sb, $cambio] = pciBoxLocatoInsieme($this);
    expect(pciSaldiDelPassaggio($sb))->toBe(['Ines Inquilina' => -6049, 'Luca Nuovo' => 6049]);

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $cambio]),
        ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino'])->assertSessionHasNoErrors();

    // L'annullamento toglie le righe dell'unità e del box (5951 + 6049 = 12000 resta tutto a Ines) e segna solo il padre.
    expect($cambio->fresh()->conguaglioAnnullato())->toBeTrue()
        ->and(pciSaldiDelPassaggio($s))->toBe([])->and(pciSaldiDelPassaggio($sb))->toBe([])
        ->and(pciNetti($sb))->toBe(['Ines Inquilina' => 12000]);
    // L'unità, giusta già oggi: 108000 per 365 giorni nelle rate di Ines.
    expect(pciConduttori(pciProspetto($s)))->toBe(['Ines Inquilina' => [108000, 365, 'rate']]);
    // Il box: 12000 per 365 giorni nelle rate di Ines, non più 5951 + 6049 «con il conguaglio».
    expect(pciConduttori(pciProspetto($sb)))->toBe(['Ines Inquilina' => [12000, 365, 'rate']]);
});

// Rilievo «box locato insieme» (medio, lente denaro), la stampa: nei due casi il box non scrive un conguaglio che non
// esiste. NON copre le cifre (i due test sopra) né la frase in testa, che nomina il conguaglio in generale («del …»).
it('rilievo box locato insieme — la stampa del box, dopo la rinuncia e dopo il conguaglio annullato, non dice «con il conguaglio del cambio d\'inquilino del 01/07/2026» e non ha la sezione di Luca', function () {
    [, $sbRinuncia] = pciBoxLocatoInsieme($this, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino']);
    [$s, $sbAnnullato, $cambio] = pciBoxLocatoInsieme($this);
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $cambio]),
        ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino'])->assertSessionHasNoErrors();

    foreach (['rinuncia' => $sbRinuncia, 'conguaglio annullato' => $sbAnnullato] as $caso => $sb) {
        $testo = pciStampa($sb);
        // La testa spiega i modi con «con il conguaglio del cambio d'inquilino del …»: si cerca la frase con la data, che
        // sta solo accanto a una voce.
        // (`toContain` prende più frasi, non un messaggio: il caso si dice con `toBeFalse`.)
        expect(str_contains($testo, 'con il conguaglio del cambio d\'inquilino del 01/07/2026'))->toBeFalse("{$caso}: la stampa del box dice che Luca ha pagato con il conguaglio del 01/07/2026")
            ->and(str_contains($testo, 'Totale a carico di Luca Nuovo'))->toBeFalse("{$caso}: la stampa del box ha la sezione di Luca");
        // Ines 12000 (€ 120,00 = € 1.200,00 × 100/1000) per 365 giorni, una sezione sola.
        expect($testo)->toContain('Totale a carico di Ines Inquilina € 120,00');
    }
});

/**
 * Il già versato «dell'unità» (decisione 17, D8): `contributi_versati` senza persona, 12000 sulla voce «Spese generali»,
 * prima della generazione; la voce divisa fra i ruoli dati; Ines inquilina, piano generato, sei rate emesse fino al 30/6;
 * il 1/7 Ines lascia l'unità a Luca, con il conguaglio.
 */
function pciGiaVersatoDellUnita($test, array $ripartizione): array
{
    $s = pciScenario($ripartizione);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    $ines = pciPersona($s, 'Ines Inquilina');
    pciTitolare($s, $ines, 'inquilino');
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    pciFine($test, $s, $ines, pciPersona($s, 'Luca Nuovo'), '2026-07-01');

    return $s;
}

// Rilievo «già versato dell'unità sulla voce dell'inquilino» (medio, lente denaro), forma P1: voce tutta sull'inquilino.
// NON copre: il già versato «della persona» (decisione 17), due cambi di fila, il ripiego, come la stampa lo scrive.
it('rilievo già versato dell\'unità, voce tutta sull\'inquilino — € 120,00 versati dall\'unità e cambio d\'inquilino il 1/7: il prospetto divide come il conguaglio, Luca € 544,44 con il conguaglio e Ines € 535,56', function () {
    $s = pciGiaVersatoDellUnita($this, ['inquilino' => 100]);

    // Il conguaglio divide il netto: € 1.200,00 − € 120,00 = 108000 nelle rate di Ines; 108000 × 184/365 = 54443,84 → 54444
    // a Luca, Ines 108000 − 54444 = 53556. Somma: 53556 + 54444 = 108000.
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 53556, 'Luca Nuovo' => 54444]);

    $p = pciProspetto($s);
    $luca = pciSezione($p, 'Luca Nuovo');
    // La parte di Luca «con il conguaglio» è la sua riga del conguaglio, 54444 (non il lordo 120000 × 184/365 = 60493,15);
    // a Ines il resto, 53556 in tutto (non 59507 − 12000 = 47507). 53556 + 54444 = 108000, il totale dell'inquilino.
    expect((int) collect($luca['voci'] ?? [])->where('modo', 'conguaglio')->sum('importo'))->toBe(54444)
        ->and($luca['totale'] ?? null)->toBe(54444)
        ->and(pciSezione($p, 'Ines Inquilina')['totale'] ?? null)->toBe(53556)
        ->and($p['totale_inquilino'])->toBe(108000);
});

// Rilievo «già versato dell'unità sulla voce dell'inquilino» (medio, lente denaro), la forma 30/70 dello scettico. NON
// copre: il già versato «della persona», la parte di Ugo (il «Proprietario» non è nel prospetto), la stampa.
it('rilievo già versato dell\'unità, voce 30/70 — € 120,00 versati dall\'unità e cambio d\'inquilino il 1/7: il prospetto divide come il conguaglio, Luca € 163,33 con il conguaglio e Ines € 160,67', function () {
    $s = pciGiaVersatoDellUnita($this, ['inquilino' => 30, 'proprietario' => 70]);

    // Il versato dell'unità sconta il lordo dell'unità prima della divisione per ruolo (D8): (120000 − 12000) = 108000,
    // 30 % a Ines = 32400 e 70 % a Ugo = 75600 (32400 + 75600 = 108000). Il conguaglio sul netto di Ines: 32400 × 184/365
    // = 16333,15 → 16333 a Luca, Ines 32400 − 16333 = 16067. Somma: 16067 + 16333 + 75600 = 108000.
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 16067, 'Luca Nuovo' => 16333, 'Venditore Ugo' => 75600]);

    $p = pciProspetto($s);
    $luca = pciSezione($p, 'Luca Nuovo');
    // Luca «con il conguaglio» 16333 (non il lordo 36000 × 184/365 = 18147,95 → 18148); Ines il resto, 16067 in tutto (non
    // 17852 − 3600 = 14252). 16067 + 16333 = 32400, il totale dell'inquilino.
    expect((int) collect($luca['voci'] ?? [])->where('modo', 'conguaglio')->sum('importo'))->toBe(16333)
        ->and($luca['totale'] ?? null)->toBe(16333)
        ->and(pciSezione($p, 'Ines Inquilina')['totale'] ?? null)->toBe(16067)
        ->and($p['totale_inquilino'])->toBe(32400);
});

// Rilievo «una voce su due tabelle» (basso, lente denaro), scenario P8. NON copre: due voci diverse (lì i conti tornano già),
// le tabelle con coefficienti diversi, il ripiego, la stampa.
it('rilievo voce su due tabelle — € 100,00 tutta sull\'inquilino su due tabelle a metà, cambio d\'inquilino il 1/7: il prospetto dà a Luca € 50,41 come il conguaglio, e a Ines € 49,59', function () {
    $s = pciScenario(['inquilino' => 100]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conti.id');
    DB::table('conti')->where('id', $contoId)->update(['importo' => 10000]);
    DB::table('conto_tabella_millesimale')->where('conto_id', $contoId)->update(['coefficiente' => 50]);
    $scale = \App\Models\Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Scale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $scale->id, 'immobile_id' => $s['unita']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $contoId, 'tabella_id' => $scale->id, 'coefficiente' => 50, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $ines = pciPersona($s, 'Ines Inquilina');
    pciTitolare($s, $ines, 'inquilino');
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    pciFine($this, $s, $ines, pciPersona($s, 'Luca Nuovo'), '2026-07-01');

    // Due righe da 5000 (10000 × 50/100 per tabella) nelle rate di Ines. Il conguaglio somma la voce sulle due tabelle e
    // arrotonda una volta: 10000 × 184/365 = 5041,10 → 5041 a Luca; Ines 10000 − 5041 = 4959. Somma: 4959 + 5041 = 10000.
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 4959, 'Luca Nuovo' => 5041]);

    $p = pciProspetto($s);
    // Il prospetto deve dire lo stesso, non 5000 × 184/365 = 2520,55 → 2521 per tabella, due volte 5042 (e Ines 2479 × 2 = 4958).
    expect(pciSezione($p, 'Luca Nuovo')['totale'] ?? null)->toBe(5041)
        ->and(collect(pciSezione($p, 'Luca Nuovo')['voci'] ?? [])->pluck('modo')->unique()->values()->all())->toBe(['conguaglio'])
        ->and(pciSezione($p, 'Ines Inquilina')['totale'] ?? null)->toBe(4959)
        ->and($p['totale_inquilino'])->toBe(10000);
});

/**
 * Lo scenario «Combustibile» della sonda sul piede: Ugo proprietario, «Preventivo 2026» (30/70) generato con l'unità senza
 * inquilino e le rate di gennaio e febbraio emesse; Ines entra il 1/3 (rotta vera); dopo si genera il piano «Combustibile
 * 2026» (gestione a sé, € 730,00 tutto sull'«Inquilino», 4 rate dal 5/4); le rate dei due piani si emettono fino al 30/6;
 * il 1/7 Ines lascia l'unità a Luca, con il conguaglio.
 */
function pciCombustibile($test): array
{
    $s = pciScenario(['inquilino' => 30, 'proprietario' => 70]);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-02-28');
    $ines = pciPersona($s, 'Ines Inquilina');
    pciInizio($test, $s, $ines, '2026-03-01');

    $g = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g->id);
    $pc = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC riscaldamento']);
    $conto = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Combustibile', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 73000]);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $combustibile = \App\Models\Gestionale\PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Combustibile 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 4, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-04-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);
    app(GeneratePianoRateAction::class)->execute($combustibile, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: inquilina entrata nell\'anno', esercizio: $s['e']);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    ruEmettiBozze($s, $combustibile, '2026-06-30');
    pciFine($test, $s, $ines, pciPersona($s, 'Luca Nuovo'), '2026-07-01');

    return $s + ['combustibile' => $combustibile];
}

// Rilievo «il piede della stampa: di cui già nelle sue rate conta anche il conguaglio» (medio, lente denaro), i campi della
// sezione. NON copre la stampa (test sotto), un conduttore con tutti e tre i modi, due cambi di fila.
it('rilievo piede della stampa, i campi — Luca con «Combustibile» con il conguaglio (€ 368,00) e «Spese generali» pagata da Ugo (€ 181,48): nelle sue rate 0, con il conguaglio 36800, da rimborsare 18148', function () {
    $s = pciCombustibile($this);

    // Combustibile: 73000 / 365 = 200 al giorno; Ines dal 1/3, 306 giorni → 61200 nelle sue rate (a Ugo il ripiego di gennaio
    // e febbraio, 59 × 200 = 11800; 61200 + 11800 = 73000); il conguaglio: 61200 × 184/306 = 36800 a Luca (184 × 200).
    expect(DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->where('gestione_id', $s['combustibile']->gestione_id)
        ->orderBy('saldo_iniziale')->pluck('saldo_iniziale')->map(fn ($x) => (int) $x)->all())->toBe([-36800, 36800]);

    $p = pciProspetto($s);
    $luca = pciSezione($p, 'Luca Nuovo');
    // Spese generali: 36000 (30 % di € 1.200,00) pagate da Ugo per ripiego; i 306 giorni di conduzione: 36000 × 306/365 =
    // 30180,82 → 30181 (senza conduttore 5819; 30181 + 5819 = 36000), a Luca 30181 × 184/306 = 18148,06 → 18148.
    expect(pciVociDi($luca))->toBe([
        ['Combustibile', 'conguaglio', null, 36800, 184],
        ['Spese generali', 'proprietario', 'Venditore Ugo', 18148, 184],
    ])
        // 36800 + 18148 = 54948: nelle sue rate niente (Luca non ha quote), con il conguaglio 36800, da rimborsare 18148.
        ->and($luca['totale'])->toBe(54948)
        ->and($luca['nelle_sue_rate'])->toBe(0)
        ->and($luca['con_il_conguaglio'] ?? null)->toBe(36800)
        ->and($luca['da_rimborsare'])->toBe(18148);
    // Ines: Combustibile 122 × 200 = 24400 nelle sue rate; Spese generali 30181 − 18148 = 12033 pagata da Ugo. 24400 + 12033 = 36433.
    // Senza conduttore, a Ugo, gennaio e febbraio: 11800 + 5819 = 17619. Somma: 54948 + 36433 + 17619 = 109000 = 73000 + 36000.
    $ines = pciSezione($p, 'Ines Inquilina');
    expect($p['senza_conduttore']['totale'])->toBe(17619)
        ->and($ines['totale'])->toBe(36433)
        ->and($ines['nelle_sue_rate'])->toBe(24400)
        ->and($ines['con_il_conguaglio'] ?? null)->toBe(0)
        ->and($ines['da_rimborsare'])->toBe(12033);
});

// Rilievo «il piede della stampa» (medio, lente denaro), la stampa. NON copre i campi (test sopra), l'ordine delle righe
// del piede, la sezione di Ines (dove il piede è già giusto).
it('rilievo piede della stampa — il piede di Luca dice «di cui con il conguaglio del cambio d\'inquilino € 368,00», non «di cui già nelle sue rate € 368,00»', function () {
    $s = pciCombustibile($this);
    $testo = pciStampa($s);

    // Il piede di Luca: dal suo totale fino alla sezione che segue (i giorni senza conduttore) o alla frase finale.
    preg_match('/Totale a carico di Luca Nuovo (?:(?!Totale a carico di |Giorni senza conduttore|Totale delle voci ).)*/u', $testo, $m);
    $piede = $m[0] ?? '(nessun piede di Luca Nuovo nella stampa)';
    // 36800 + 18148 = 54948, € 549,48.
    expect($piede)->toContain('Totale a carico di Luca Nuovo € 549,48')
        ->toContain('di cui con il conguaglio del cambio d\'inquilino € 368,00')
        ->not->toContain('di cui già nelle sue rate € 368,00')
        ->toContain('di cui da rimborsare a chi le ha pagate € 181,48');
});

// Rilievo «perimetro della catena dei conduttori» (basso, lente sicurezza): un controllo, verde già oggi. NON copre due
// condomini, il cambio d'inquilino su A e non su B (simmetrico), la catena dei pagatori (vendite) fra due unità.
it('controllo del perimetro — lo stesso inquilino su due unità, cambio d\'inquilino il 1/7 solo su B: il prospetto di A resta a Ines (€ 180,00, 365 giorni, nelle sue rate) e la sua stampa non nomina Luca; quello di B divide fra Ines e Luca', function () {
    $s = pciScenario(['inquilino' => 30, 'proprietario' => 70]);
    $b = \App\Models\Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'PCI-B-' . $s['unita']->id, 'nome' => 'Interno 2', 'interno' => '2']);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $b->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $sb = ['unita' => $b] + $s;
    pciTitolare($sb, $s['v'], 'proprietario');
    $ines = pciPersona($s, 'Ines Inquilina');
    pciTitolare($s, $ines, 'inquilino');
    pciTitolare($sb, $ines, 'inquilino');
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    pciFine($this, $sb, $ines, pciPersona($s, 'Luca Nuovo'), '2026-07-01');

    // € 1.200,00 × 1000/2000 = 60000 per unità; 30 % all'inquilino = 18000, 70 % a Ugo = 42000 (18000 + 42000 = 60000).
    // B: 18000 × 181/365 = 8926,03 → 8926 a Ines e × 184/365 = 9073,97 → 9074 a Luca (8926 + 9074 = 18000).
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 18000, 'Venditore Ugo' => 42000])
        ->and(pciNetti($sb))->toBe(['Ines Inquilina' => 8926, 'Luca Nuovo' => 9074, 'Venditore Ugo' => 42000]);
    expect(pciConduttori(pciProspetto($s)))->toBe(['Ines Inquilina' => [18000, 365, 'rate']])
        ->and(pciConduttori(pciProspetto($sb)))->toBe(['Ines Inquilina' => [8926, 181, 'rate'], 'Luca Nuovo' => [9074, 184, 'conguaglio']]);
    expect(pciStampa($s))->toContain('Totale a carico di Ines Inquilina € 180,00')->not->toContain('Luca Nuovo');
});

/*
|--------------------------------------------------------------------------
| Giro sulle correzioni della Fase 1-bis della beta.47 — i rilievi confermati sul prospetto
|--------------------------------------------------------------------------
|
| Ogni test nasce da un rilievo del giro (lenti denaro e sicurezza, con il loro scettico): è rosso sul codice di oggi per la
| ragione del difetto, e diventa verde con la correzione. I controlli, verdi già oggi e da tenere verdi, lo dicono nel nome.
| Le cifre sono fatte a mano, con la somma accanto; le divisioni per giorni sono quelle del motore, con i resti maggiori
| (`MoneyHelper::ripartisciPerQuote`).
*/

/** La riga aperta (la più recente) di una persona con un ruolo sull'unità dello scenario. */
function pciRigaAperta(array $s, Anagrafica $p, string $tipologia): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $p->id)->where('tipologia', $tipologia)
        ->whereNull('data_fine')->orderByDesc('id')->value('id');
}

/** Un passaggio con il modulo di `ruPassaggio`, dalla rotta vera, con la spunta del cancello e la sua nota. */
function pciRegistra($test, array $s, array $dati, string $nota): Subentro
{
    return ruRegistra($test, $s, $dati + ['ho_letto' => true, 'nota_cancello' => $nota]);
}

/**
 * La locazione del DL5 e del P9, dalle rotte vere: Ines inquilina censita dal 2019 con la fine locazione il 1/2 (inquilina
 * fino al 31/1, 31 giorni nel 2026); Luca dal 1/3 con la fine locazione il 1/6 (31 + 30 + 31 = 92 giorni). Il ripiego di chi
 * possiede è febbraio (28) più dal 1/6 al 31/12 (214): 242 giorni. 31 + 92 + 242 = 365.
 *
 * @return array{0: Anagrafica, 1: Anagrafica} Ines e Luca
 */
function pciLocazioneDl5($test, array $s): array
{
    $ines = pciPersona($s, 'Ines Inquilina');
    pciTitolare($s, $ines, 'inquilino');
    pciFine($test, $s, $ines, null, '2026-02-01');
    $luca = pciPersona($s, 'Luca Secondo');
    pciInizio($test, $s, $luca, '2026-03-01');
    pciFine($test, $s, $luca, null, '2026-06-01');

    return [$ines, $luca];
}

/**
 * Le voci senza conduttore: [pagata da, importo, giorni], in ordine di pagatore.
 *
 * @return list<array{0: ?string, 1: int, 2: ?int}>
 */
function pciSenza(array $p): array
{
    return collect($p['senza_conduttore']['voci'])->map(fn ($v) => [$v['pagato_da'] ?? null, (int) $v['importo'], $v['giorni'] === null ? null : (int) $v['giorni']])
        ->sortBy(fn ($v) => (string) $v[0])->values()->all();
}

/** Il già versato sulla voce «Spese generali» (decisione 17), prima della generazione: dell'unità (senza persona) o della persona data. */
function pciVersato(array $s, int $importo, ?Anagrafica $persona = null): void
{
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => $persona?->id, 'importo_cents' => $importo, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
}

/** Le righe di saldo di un passaggio: nome → centesimi, sommate per persona, in ordine di nome. */
function pciSaldiDi(Subentro $passaggio): array
{
    $nomi = Anagrafica::pluck('nome', 'id');

    return DB::table('saldi')->where('subentro_id', $passaggio->id)->get()->groupBy('anagrafica_id')
        ->mapWithKeys(fn ($g, $id) => [$nomi[(int) $id] ?? '(unità)' => (int) $g->sum('saldo_iniziale')])->sortKeys()->all();
}

// --- 1. La riserva gira il ruolo di tutta la catena ----------------------------------------------------------------------

// Rilievo «la riserva rende usufruttuario tutta la catena» (alto, lente denaro) e «la riserva gira il ruolo» (alto, lente
// sicurezza), forma 1: l'unità mista nata da una riserva del 2025 e da una successione dopo. NON copre: la successione e
// l'estinzione dello stesso giorno (T2 della lente), due righe dello stesso ruolo della stessa persona, la stampa.
it('rilievo riserva che gira il ruolo, forma 1 — Ugo e Rita pieni al 50 %, Ugo dona a Bice la nuda della sua metà con riserva (1/6/2025) ed eredita la metà di Rita (1/10/2025), poi il P9: Ivo rimborsa a Elsa la parte del pieno (€ 302,47) e a Ugo quella dell\'usufrutto (€ 302,46)', function () {
    $s = pciScenario(['inquilino' => 100]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rita = pciPersona($s, 'Rita Moglie');
    pciTitolare($s, $rita, 'proprietario', 50);
    $bice = pciPersona($s, 'Bice Figlia');
    pciRegistra($this, $s, ruPassaggio('riserva', $s['rigaV'], $bice, '2025-06-01', 50), 'Donazione letta: Ugo dona a Bice la nuda della sua metà, con riserva d\'usufrutto');
    pciRegistra($this, $s, ruPassaggio('successione', pciRigaAperta($s, $rita, 'proprietario'), $s['v'], '2025-10-01', 50), 'Successione di Rita letta: la sua metà a Ugo');
    pciLocazioneDl5($this, $s);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    pciRegistra($this, $s, ruPassaggio('vendita', pciRigaAperta($s, $s['v'], 'proprietario'), $s['a'], '2026-07-01', 50), 'Rogito letto: Ugo vende a Elsa la sua metà piena');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Il riparto è quello del P9 (pesi quota × giorni su 36500): Ines 100 × 31 → 10191,78; Luca 100 × 92 → 30246,58; il ripiego
    // di Ugo in due righe gemelle 50 × 242 (il pieno avuto da Rita e l'usufrutto della riserva) → 39780,82 ciascuna; resti
    // maggiori: 10192 + 30246 + 39781 + 39781 = 120000. La vendita sposta la sola riga del pieno: Elsa con il conguaglio
    // 39781 × 184/242 = 30246,71 → 30247; Ugo 39781 + 39781 − 30247 = 49315. Somma: 30247 + 10192 + 30246 + 49315 = 120000.
    expect(pciNetti($s))->toBe(['Acquirente Elsa' => 30247, 'Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Venditore Ugo' => 49315]);

    $p = pciProspetto($s);
    // Ivo: 79562 × 184/242 = 60493,42 → 60493. La riga del pieno, dal 1/7, l'ha pagata Elsa: 30247, quanto il suo conguaglio; la
    // riga dell'usufrutto resta di Ugo (la riserva del 2025 riguarda l'altra metà, non la riga che Ugo vende): 60493 − 30247 =
    // 30246. Somma: 30247 + 30246 = 60493.
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([
        ['Spese generali', 'proprietario', 'Acquirente Elsa', 30247, 184],
        ['Spese generali', 'proprietario', 'Venditore Ugo', 30246, 184],
    ]);
    // Senza conduttore: febbraio e giugno, 28 + 30 = 58 giorni, prima della vendita: 79562 − 60493 = 19069 a Ugo.
    expect(pciSenza($p))->toBe([['Venditore Ugo', 19069, 58]]);
});

// Rilievo «la riserva gira il ruolo» (alto, lente sicurezza), forma 2: fra due eredi la riserva di uno ferma la vendita
// dell'altro (una regressione della correzione). NON copre: gli eredi con ruoli diversi nella stessa catena, la stampa.
it('rilievo riserva che gira il ruolo, forma 2 — Ugo muore il 1/3 (Anna 60 %, Bruno 40 %), Anna dona a Dario con riserva il 1/5, Bruno vende a Elsa il 1/7, Ivo dal 1/9: Ivo rimborsa € 401,09 ad Anna e a Elsa, non ad Anna e a Bruno', function () {
    $s = pciScenario(['inquilino' => 100]);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-02-28');
    $anna = pciPersona($s, 'Anna Erede');
    $bruno = pciPersona($s, 'Bruno Erede');
    pciRegistra($this, $s, ruPassaggio('successione_due', $s['rigaV'], $anna, '2026-03-01', 100, secondoErede: $bruno), 'Successione di Ugo letta: Anna 60 %, Bruno 40 %');
    pciRegistra($this, $s, ruPassaggio('riserva', pciRigaAperta($s, $anna, 'proprietario'), pciPersona($s, 'Dario Nudo'), '2026-05-01', 60), 'Donazione letta: Anna dona a Dario la nuda, con riserva d\'usufrutto');
    pciRegistra($this, $s, ruPassaggio('vendita', pciRigaAperta($s, $bruno, 'proprietario'), $s['a'], '2026-07-01', 40), 'Rogito letto: Bruno vende a Elsa la sua parte');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-09-01');

    // Nessun inquilino alla generazione: il ripiego di Ugo, 120000 per 365 giorni, una riga. Elsa paga il 40 % dal 1/7:
    // 48000 × 184/365 = 24197,26 → 24197.
    expect(pciNetti($s)['Acquirente Elsa'] ?? null)->toBe(24197);

    $p = pciProspetto($s);
    // La catena: Ugo 1/1–28/2 (31 + 28 = 59 giorni), Anna e Bruno 1/3–30/6 (31 + 30 + 31 + 30 = 122), Anna ed Elsa 1/7–31/12
    // (184); 59 + 122 + 184 = 365. 120000 × 59/365 = 19397,26; × 122/365 = 40109,59; × 184/365 = 60493,15; resti maggiori:
    // 19397 + 40110 + 60493 = 120000. Ivo, dal 1/9, ha 122 dei 184 giorni dell'ultimo pezzo, che si divide per conto suo:
    // 60493 × 122/184 = 40109,49 e × 62/184 = 20383,51; resti maggiori: 40109 a Ivo e 20384 senza conduttore (40109 + 20384 =
    // 60493). Non 40110: il rilievo faceva 60493 × 122/184 = 40109,6, ma è 40109,49. Il pezzo unisce le persone come oggi, con « e ».
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([['Spese generali', 'proprietario', 'Anna Erede e Acquirente Elsa', 40109, 122]]);
    // Senza conduttore: 19397 + 40110 + 20384 = 79891; con i 40109 di Ivo, 120000.
    expect(pciSenza($p))->toBe([
        ['Anna Erede e Acquirente Elsa', 20384, 62],
        ['Anna Erede e Bruno Erede', 40110, 122],
        ['Venditore Ugo', 19397, 59],
    ]);
});

// Rilievo «la riserva rende usufruttuario tutta la catena» (alto, lente denaro), T8: l'unità piena con due eredi, la riserva
// di Mara ferma la vendita di Bice. NON copre: la riserva dell'erede che poi vende anche la nuda, la stampa.
it('rilievo riserva che gira il ruolo, T8 — Ugo muore il 1/5 (Mara 60 %, Bice 40 %), Mara dona a Dino con riserva il 1/7, Bice vende a Elio il 1/9, Ivo dal 1/9: Ivo rimborsa € 401,10 a Mara e a Elio, che ha pagato la parte di Bice con il conguaglio', function () {
    $s = pciScenario(['inquilino' => 100]);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-04-30');
    $mara = pciPersona($s, 'Mara Erede');
    $bice = pciPersona($s, 'Bice Erede');
    pciRegistra($this, $s, ruPassaggio('successione_due', $s['rigaV'], $mara, '2026-05-01', 100, secondoErede: $bice), 'Successione di Ugo letta: Mara 60 %, Bice 40 %');
    pciRegistra($this, $s, ruPassaggio('riserva', pciRigaAperta($s, $mara, 'proprietario'), pciPersona($s, 'Dino Nudo'), '2026-07-01', 60), 'Donazione letta: Mara dona a Dino la nuda, con riserva d\'usufrutto');
    $elio = pciPersona($s, 'Elio Compratore');
    $vendita = pciRegistra($this, $s, ruPassaggio('vendita', pciRigaAperta($s, $bice, 'proprietario'), $elio, '2026-09-01', 40), 'Rogito letto: Bice vende a Elio la sua parte');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-09-01');

    // Il conguaglio della vendita: il 40 % di Bice dal 1/9, 120000 × 40 % × 122/365 = 16043,84 → 16044, da Bice a Elio.
    expect(pciSaldiDi($vendita))->toBe(['Bice Erede' => -16044, 'Elio Compratore' => 16044]);

    $p = pciProspetto($s);
    // La catena: Ugo 1/1–30/4 (31 + 28 + 31 + 30 = 120 giorni), Mara e Bice 1/5–31/8 (31 + 30 + 31 + 31 = 123), Mara ed Elio
    // 1/9–31/12 (30 + 31 + 30 + 31 = 122); 120 + 123 + 122 = 365. 120000 × 120/365 = 39452,05; × 123/365 = 40438,36;
    // × 122/365 = 40109,59; resti maggiori: 39452 + 40438 + 40110 = 120000. Ivo copre tutto l'ultimo pezzo: 40110 per 122 giorni.
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([['Spese generali', 'proprietario', 'Mara Erede e Elio Compratore', 40110, 122]]);
    // Senza conduttore: 39452 + 40438 = 79890; con i 40110 di Ivo, 120000.
    expect(pciSenza($p))->toBe([['Mara Erede e Bice Erede', 40438, 123], ['Venditore Ugo', 39452, 120]]);
});

// Rilievo «la riserva gira il ruolo» (alto, lente sicurezza): il controllo che giustifica la riserva nella catena, la riserva
// registrata dopo la generazione con la decorrenza prima del periodo, poi l'estinzione. Verde oggi, deve restare verde con la
// correzione. NON copre la riserva con la decorrenza dentro il periodo (ProspettoOneriAccessoriTest, «beta.38 — riserva
// d'usufrutto e poi estinzione»), né la stampa.
it('controllo della riserva che gira il ruolo — riserva di Ugo a Bice registrata dopo la generazione con decorrenza 1/12/2025, estinzione il 1/9, Ivo dal 1/9: Ivo rimborsa a Bice € 401,10', function () {
    $s = pciScenario(['inquilino' => 100]);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-08-31');
    $bice = pciPersona($s, 'Bice Figlia');
    pciRegistra($this, $s, ruPassaggio('riserva', $s['rigaV'], $bice, '2025-12-01', 100), 'Donazione del 2025 letta, registrata ora: Ugo dona a Bice la nuda, con riserva d\'usufrutto');
    pciRegistra($this, $s, ruPassaggio('estinzione', pciRigaAperta($s, $s['v'], 'usufruttuario'), null, '2026-09-01', 100), 'Estinzione per morte dell\'usufruttuario, letta');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-09-01');

    $p = pciProspetto($s);
    // Il piano, generato prima della riserva, ha la riga del pieno di Ugo (120000, 365 giorni): da lì Ugo è usufruttuario, e
    // l'estinzione del 1/9 la passa a Bice. Ugo 1/1–31/8 (243 giorni), Bice 1/9–31/12 (122); 120000 × 243/365 = 79890,41 e
    // × 122/365 = 40109,59; resti maggiori: 79890 + 40110 = 120000.
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([['Spese generali', 'proprietario', 'Bice Figlia', 40110, 122]])
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 79890, 243]]);
});

// --- 2. L'estinzione con due nudi -----------------------------------------------------------------------------------------

/**
 * T10: Ugo usufruttuario al 100 % dal 2019 e i nudi dati (nome → quota), censiti dal 2019; la locazione del DL5, il piano
 * generato, le rate emesse fino al 30/6; l'estinzione dell'usufrutto di Ugo il 1/7 (senza accrescimento: i nudi tornano
 * pieni); Ivo dal 1/7.
 */
function pciEstinzioneConNudi($test, array $nudi): array
{
    $s = pciScenario(['inquilino' => 100]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    foreach ($nudi as $nome => $quota) {
        pciTitolare($s, pciPersona($s, $nome), 'nuda_proprietario', $quota);
    }
    pciLocazioneDl5($test, $s);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    pciRegistra($test, $s, ruPassaggio('estinzione', $s['rigaV'], null, '2026-07-01', 100), 'Estinzione per morte dell\'usufruttuario, letta');
    pciInizio($test, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    return $s;
}

// Rilievo «estinzione dell'usufrutto con due nudi: la catena passa solo al primo nudo» (alto, lente denaro), T10. NON copre:
// i registri di prima della .43 senza `nudi`, i nudi con quote diverse, la pertinenza, la stampa.
it('rilievo estinzione con due nudi, T10 — Ugo usufruttuario, Bice e Carla nude al 50 %, l\'usufrutto si estingue il 1/7 e Ivo entra il 1/7: Ivo rimborsa € 604,93 a «Bice Nuda e Carla Nuda», che l\'hanno pagata con il conguaglio, non alla sola Bice', function () {
    $s = pciEstinzioneConNudi($this, ['Bice Nuda' => 50, 'Carla Nuda' => 50]);

    // Il riparto del DL5 su una riga sola (usufruttuario 100): Ines 100 × 31 → 10191,78; Luca 100 × 92 → 30246,58; Ugo 100 ×
    // 242 → 79561,64; resti maggiori: 10192 + 30246 + 79562 = 120000. L'estinzione fa pagare ai due nudi i 184 giorni dal
    // 1/7: 79562 × 184/242 = 60493,42 → 60493, a metà 30246,50: 30247 a Bice e 30246 a Carla (a parità di resto vince chi viene
    // prima); a Ugo 79562 − 60493 = 19069.
    // Somma: 30247 + 30246 + 10192 + 30246 + 19069 = 120000.
    expect(pciNetti($s))->toBe(['Bice Nuda' => 30247, 'Carla Nuda' => 30246, 'Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Venditore Ugo' => 19069]);

    $p = pciProspetto($s);
    // Ivo: i 184 giorni dal 1/7, 60493, pagati dai due nudi tornati pieni (30247 + 30246): un pezzo solo, con i nomi uniti.
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([['Spese generali', 'proprietario', 'Bice Nuda e Carla Nuda', 60493, 184]])
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 19069, 58]]);
});

// Rilievo «estinzione dell'usufrutto con due nudi» (alto, lente denaro): il controllo con un nudo solo, verde oggi. NON copre
// i due nudi (il test sopra), la stampa.
it('controllo dell\'estinzione con due nudi — un nudo solo, Bice al 100 %: Ivo rimborsa a Bice € 604,93, e i 58 giorni senza conduttore restano a Ugo', function () {
    $s = pciEstinzioneConNudi($this, ['Bice Nuda' => 100]);

    // Bice paga i 184 giorni dal 1/7: 79562 × 184/242 = 60493,42 → 60493; Ugo 79562 − 60493 = 19069.
    // Somma: 60493 + 10192 + 30246 + 19069 = 120000.
    expect(pciNetti($s))->toBe(['Bice Nuda' => 60493, 'Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Venditore Ugo' => 19069]);
    $p = pciProspetto($s);
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([['Spese generali', 'proprietario', 'Bice Nuda', 60493, 184]])
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 19069, 58]]);
});

// --- 3. Le righe di ripiego si dividono al lordo del già versato «dell'unità» -----------------------------------------------

/**
 * T5 e B: l'unità piena di Ugo con € 120,00 di già versato «dell'unità» (senza persona) sulla voce, la voce divisa fra i ruoli
 * dati, la locazione del DL5, il piano generato, le rate emesse fino al 30/6; con `$vendita` Ugo vende tutto a Elsa il 1/7.
 * Ivo dal 1/7.
 *
 * @return array{0: array, 1: ?Subentro} lo scenario e la vendita
 */
function pciRipiegoConVersato($test, array $ripartizione, bool $vendita): array
{
    $s = pciScenario($ripartizione);
    pciVersato($s, 12000);
    pciLocazioneDl5($test, $s);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $passaggio = $vendita ? pciRegistra($test, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 100), 'Rogito letto: Ugo vende tutto a Elsa') : null;
    pciInizio($test, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    return [$s, $passaggio];
}

/** Il già versato che il riparto del piano scrive per persona (righe `netting`): nome → centesimi (negativi), in ordine di nome. */
function pciVersatoNelRiparto(array $s): array
{
    $nomi = Anagrafica::pluck('nome', 'id');

    return DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('immobile_id', $s['unita']->id)->where('tipo', 'netting')->get()->groupBy('anagrafica_id')
        ->mapWithKeys(fn ($g, $id) => [$nomi[(int) $id] ?? '(unità)' => (int) $g->sum('importo')])->sortKeys()->all();
}

// Rilievo «le righe di ripiego si dividono al lordo» (medio, lente denaro), T5. NON copre: il già versato «della persona» sul
// ripiego, l'unità mista (T11), la voce divisa fra i ruoli (F), la stampa.
it('rilievo ripiego al lordo, T5 — unità piena con € 120,00 versati dall\'unità, locazione del DL5, Ugo vende tutto a Elsa il 1/7 e Ivo entra il 1/7: Ivo rimborsa a Elsa € 544,44, quanto il suo conguaglio, e non il lordo € 604,93', function () {
    [$s] = pciRipiegoConVersato($this, ['inquilino' => 100], true);

    // Il riparto del DL5 su una riga: Ines 10192, Luca 30246, Ugo 79562 (10192 + 30246 + 79562 = 120000). Il versato
    // «dell'unità» in proporzione ai lordi: 12000 × 10192/120000 = 1019,2; × 30246/120000 = 3024,6; × 79562/120000 = 7956,2;
    // resti maggiori: 1019 + 3025 + 7956 = 12000.
    expect(pciVersatoNelRiparto($s))->toBe(['Ines Inquilina' => -1019, 'Luca Secondo' => -3025, 'Venditore Ugo' => -7956]);
    // La vendita divide il netto di Ugo: 79562 − 7956 = 71606, a Elsa 71606 × 184/242 = 54444,23 → 54444 (le rate in bozza
    // passate a lei più la sua riga del conguaglio). Netti: Elsa 54444, Ines 10192 − 1019 = 9173, Luca 30246 − 3025 = 27221,
    // Ugo 71606 − 54444 = 17162. Somma: 54444 + 9173 + 27221 + 17162 = 108000 = 120000 − 12000.
    expect(pciNetti($s))->toBe(['Acquirente Elsa' => 54444, 'Ines Inquilina' => 9173, 'Luca Secondo' => 27221, 'Venditore Ugo' => 17162]);

    $p = pciProspetto($s);
    // Dal secondo giro sulle correzioni il conduttore vede la voce al lordo e accanto la riga del già versato «dell'unità» che la
    // sconta (stampa, art. 9): qui conta il netto per chi ha pagato, cioè la somma delle due righe.
    // Ivo: i 184 giorni dal 1/7 del netto di Ugo, pagati da Elsa: 54444 (non 79562 × 184/242 = 60493,42 → 60493).
    expect(pciPerPagatore(pciSezione($p, 'Ivo Ultimo')['voci'] ?? []))->toBe(['Acquirente Elsa' => 54444])
        // Senza conduttore: 71606 − 54444 = 17162 a Ugo (non 19069), febbraio e giugno, 58 giorni.
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 17162, 58]])
        // Il totale dell'inquilino è il netto: 120000 − 12000 = 108000 (9173 + 27221 + 54444 + 17162).
        ->and($p['totale_inquilino'])->toBe(108000);
});

// Rilievo «le righe di ripiego si dividono al lordo» (medio, lente denaro), B: senza la vendita il difetto è lo stesso. NON
// copre: la vendita (T5), l'unità mista (T11), la stampa.
it('rilievo ripiego al lordo, B — la stessa unità senza la vendita: Ivo rimborsa a Ugo € 544,44, i 184 giorni del netto che Ugo ha pagato, e a Ugo restano € 171,62', function () {
    [$s] = pciRipiegoConVersato($this, ['inquilino' => 100], false);

    // Netti: Ugo 79562 − 7956 = 71606, Ines 9173, Luca 27221. Somma: 71606 + 9173 + 27221 = 108000.
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 9173, 'Luca Secondo' => 27221, 'Venditore Ugo' => 71606]);

    $p = pciProspetto($s);
    // 71606 × 184/242 = 54444,23 e × 58/242 = 17161,77; resti maggiori: 54444 + 17162 = 71606.
    // Dal secondo giro sulle correzioni il conduttore vede la voce al lordo e accanto la riga del già versato «dell'unità» che la
    // sconta (stampa, art. 9): qui conta il netto per chi ha pagato, cioè la somma delle due righe.
    expect(pciPerPagatore(pciSezione($p, 'Ivo Ultimo')['voci'] ?? []))->toBe(['Venditore Ugo' => 54444])
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 17162, 58]]);
});

// Rilievo «le righe di ripiego si dividono al lordo» (medio, lente denaro), T11: il P9 con il versato; chi è entrato dopo
// riceve quanto il suo conguaglio. NON copre: il già versato «della persona», la stampa.
it('rilievo ripiego al lordo, T11 — il P9 con € 120,00 versati dall\'unità: Ivo rimborsa a Elsa quanto il suo conguaglio (€ 272,22) e a Ugo la parte dell\'usufrutto (€ 272,22); a Ugo restano € 171,62 senza conduttore', function () {
    $s = pciScenario(['inquilino' => 100]);
    pciMista($s);
    pciVersato($s, 12000);
    pciLocazioneDl5($this, $s);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $vendita = pciRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 50), 'Rogito letto: Ugo vende a Elsa la sua metà piena');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Il riparto del P9: Ines 10192, Luca 30246, Ugo due gemelle da 39781. Il versato «dell'unità»: Ines 1019, Luca 3025, Ugo
    // 7956 (come in T5), e quello di Ugo sulle due gemelle, 7956 × 39781/79562 = 3978 ciascuna (3978 + 3978 = 7956).
    expect(pciVersatoNelRiparto($s))->toBe(['Ines Inquilina' => -1019, 'Luca Secondo' => -3025, 'Venditore Ugo' => -7956]);
    // La vendita sposta il netto della riga del pieno: 39781 − 3978 = 35803, a Elsa 35803 × 184/242 = 27222,12 → 27222, tutto
    // con la sua riga del conguaglio.
    expect(pciSaldiDi($vendita))->toBe(['Acquirente Elsa' => 27222, 'Venditore Ugo' => -27222]);
    // Netti: Elsa 27222, Ines 9173, Luca 27221, Ugo 79562 − 7956 − 27222 = 44384. Somma: 27222 + 9173 + 27221 + 44384 = 108000.
    expect(pciNetti($s))->toBe(['Acquirente Elsa' => 27222, 'Ines Inquilina' => 9173, 'Luca Secondo' => 27221, 'Venditore Ugo' => 44384]);

    $p = pciProspetto($s);
    // Ivo: il netto unito, 71606 × 184/242 = 54444,23 → 54444; a Elsa la riga del pieno, 27222 (il suo saldo, sopra); a Ugo la
    // riga dell'usufrutto, 35803 × 184/242 = 27222,12 → 27222. Somma: 27222 + 27222 = 54444.
    // Dal secondo giro sulle correzioni il conduttore vede la voce al lordo e accanto la riga del già versato «dell'unità» che la
    // sconta (stampa, art. 9): qui conta il netto per chi ha pagato, cioè la somma delle due righe.
    expect(pciPerPagatore(pciSezione($p, 'Ivo Ultimo')['voci'] ?? []))->toBe(['Acquirente Elsa' => 27222, 'Venditore Ugo' => 27222])
        // Senza conduttore: 71606 − 54444 = 17162 (8581 + 8581, una per riga), 58 giorni contati una volta.
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 17162, 58]]);
});

// Rilievo «le righe di ripiego si dividono al lordo» (medio, lente denaro), F: la voce 30/70, dove il versato «dell'unità» di Ugo
// si spalma anche sulla sua parte «Proprietario», che nel prospetto non c'è. NON copre: la sezione di Ines e di Luca, la stampa.
it('rilievo ripiego al lordo, F — voce 30/70 con € 120,00 versati dall\'unità, locazione del DL5, Ugo vende tutto a Elsa il 1/7, Ivo dal 1/7: Ivo rimborsa a Elsa € 163,33, la parte netta del ripiego che lei ha pagato con il conguaglio, non € 181,48', function () {
    [$s] = pciRipiegoConVersato($this, ['inquilino' => 30, 'proprietario' => 70], true);

    // Il riparto: il «Proprietario» 84000 a Ugo; l'«Inquilino», 36000, per giorni: Ines 36000 × 31/365 = 3057,53, Luca × 92/365 =
    // 9073,97, il ripiego di Ugo × 242/365 = 23868,49; resti maggiori: 3058 + 9074 + 23868 = 36000. Il versato «dell'unità» lo
    // scrive il motore (D8, sul lordo dell'unità prima della spaccatura per ruolo): Ugo −10786, Ines −307, Luca −907
    // (10786 + 307 + 907 = 12000).
    expect(pciVersatoNelRiparto($s))->toBe(['Ines Inquilina' => -307, 'Luca Secondo' => -907, 'Venditore Ugo' => -10786]);
    // Il conguaglio della vendita spalma i 10786 di Ugo su tutte le sue righe della voce, in proporzione ai lordi:
    // 10786 × 84000/107868 = 8399,36 e × 23868/107868 = 2386,64 → 8399 + 2387 = 10786. Elsa paga il netto di ciascuna per i suoi
    // giorni: (84000 − 8399) × 184/365 = 38111,18 → 38111 e (23868 − 2387) × 184/242 = 16332,66 → 16333; 38111 + 16333 = 54444.
    // Netti: 54444 + Ines 3058 − 307 = 2751 + Luca 9074 − 907 = 8167 + Ugo 84000 + 23868 − 10786 − 54444 = 42638; somma 108000.
    expect(pciNetti($s))->toBe(['Acquirente Elsa' => 54444, 'Ines Inquilina' => 2751, 'Luca Secondo' => 8167, 'Venditore Ugo' => 42638]);

    $p = pciProspetto($s);
    // Ivo dal 1/7 ha tutti i 184 giorni di Elsa sul ripiego: la sua parte netta, 16333 (non il lordo 23868 × 184/242 = 18147,64
    // → 18148). Il versato di Ugo si toglie con la stessa ripartizione del conguaglio (2387), non tutto dal ripiego.
    // Dal secondo giro sulle correzioni il conduttore vede la voce al lordo e accanto la riga del già versato «dell'unità» che la
    // sconta (stampa, art. 9): qui conta il netto per chi ha pagato, cioè la somma delle due righe.
    expect(pciPerPagatore(pciSezione($p, 'Ivo Ultimo')['voci'] ?? []))->toBe(['Acquirente Elsa' => 16333]);
});

// --- 4. Le gemelle senza tratto -----------------------------------------------------------------------------------------

/**
 * T12, T13, T14: l'unità mista (`pciMista`) senza nessun inquilino alla generazione: il ripiego di Ugo sta in due righe gemelle
 * senza tratto (usufruttuario e proprietario pieno, 120000 × 50 % = 60000 ciascuna, tutto l'anno); rate emesse fino al 30/6.
 */
function pciMistaSenzaInquilino(): array
{
    $s = pciScenario(['inquilino' => 100]);
    pciMista($s);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');

    return $s;
}

// Rilievo «le gemelle senza tratto non si uniscono» (medio, lente denaro), T12. NON copre: le catene diverse (T13), la mista
// senza nessun inquilino (T14), le gemelle senza tratto su due tabelle.
it('rilievo gemelle senza tratto, T12 — unità mista senza inquilino alla generazione, Ivo dal 1/7: Ivo rimborsa a Ugo € 604,93 per 184 giorni, e a Ugo restano € 595,07 per 181 giorni; la stampa non scrive 368 giorni', function () {
    $s = pciMistaSenzaInquilino();
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    $p = pciProspetto($s);
    // Le due gemelle sono una parte sola dell'unità e si dividono una volta: 120000 × 184/365 = 60493,15 e × 181/365 = 59506,85;
    // resti maggiori: 60493 + 59507 = 120000 (non 30247 × 2 = 60494 per 368 giorni e 29753 × 2 = 59506 per 362).
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([['Spese generali', 'proprietario', 'Venditore Ugo', 60493, 184]])
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 59507, 181]]);
    // La stampa: nella colonna dei giorni 184 (e non 368), fra i giorni senza conduttore 181 (e non 362).
    $testo = pciStampa($s);
    expect($testo)->toContain('01/01/2026–31/12/2026 184 € 604,93 pagata da Venditore Ugo')
        ->not->toContain(' 368 € ')
        ->toContain('181 giorni € 595,07 pagata da Venditore Ugo')
        ->not->toContain('362 giorni');
});

// Rilievo «le gemelle senza tratto non si uniscono» (medio, lente denaro), T13: le catene si separano con la vendita della metà
// piena. NON copre: la stampa, la vendita della nuda.
it('rilievo gemelle senza tratto, T13 — la stessa unità, Ugo vende a Elsa la metà piena il 1/7 e Ivo entra il 1/7: Ivo rimborsa a Elsa € 302,47, quanto il suo conguaglio, e a Ugo € 302,46; a Ugo restano € 595,07 per 181 giorni', function () {
    $s = pciMistaSenzaInquilino();
    $vendita = pciRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 50), 'Rogito letto: Ugo vende a Elsa la sua metà piena');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Elsa con il conguaglio, la riga del pieno dal 1/7: 60000 × 184/365 = 30246,58 → 30247; Ugo 120000 − 30247 = 89753.
    expect(pciSaldiDi($vendita))->toBe(['Acquirente Elsa' => 30247, 'Venditore Ugo' => -30247])
        ->and(pciNetti($s))->toBe(['Acquirente Elsa' => 30247, 'Venditore Ugo' => 89753]);

    $p = pciProspetto($s);
    // Unite: 120000 × 184/365 = 60493,15 → 60493; a Elsa quanto il suo conguaglio, 30247; a Ugo 60493 − 30247 = 30246.
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([
        ['Spese generali', 'proprietario', 'Acquirente Elsa', 30247, 184],
        ['Spese generali', 'proprietario', 'Venditore Ugo', 30246, 184],
    ])
        // Senza conduttore, prima della vendita: 120000 − 60493 = 59507 per 181 giorni.
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 59507, 181]]);
});

// Rilievo «le gemelle senza tratto non si uniscono» (medio, lente denaro), T14: la mista che non ha mai avuto un inquilino.
// NON copre le gemelle con un inquilino (T12), le catene diverse (T13).
it('rilievo gemelle senza tratto, T14 — unità mista che non ha mai avuto un inquilino: senza conduttore € 1.200,00 per 365 giorni, una voce, e la stampa non scrive 730 giorni', function () {
    $s = pciMistaSenzaInquilino();

    $p = pciProspetto($s);
    // 60000 + 60000 = 120000, i 365 giorni dell'esercizio contati una volta (non 365 + 365 = 730).
    expect($p['conduttori'])->toBe([])
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 120000, 365]]);
    expect(pciStampa($s))->toContain('365 giorni € 1.200,00 pagata da Venditore Ugo')
        ->not->toContain('730 giorni');
});

// --- 5. Catene di persone diverse che finiscono sullo stesso pagatore -----------------------------------------------------

// Rilievo «catene di persone diverse sullo stesso pagatore: la stampa somma i giorni» (basso, lente denaro), T9: l'accrescimento.
// NON copre: la stampa (qui i campi), l'accrescimento con più nudi (rifiutato dalla rotta).
it('rilievo giorni sommati, T9 — Ugo e Carlo usufruttuari al 50 %, Bice nuda; Carlo muore il 1/7 e il suo usufrutto si accresce a Ugo; Ivo dal 1/7: la voce di Ivo «pagata da Ugo» vale € 604,94 per 184 giorni, non per 368', function () {
    $s = pciScenario(['inquilino' => 100]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario', 'quota' => 50]);
    $carlo = pciPersona($s, 'Carlo Usufruttuario');
    pciTitolare($s, $carlo, 'usufruttuario', 50);
    pciTitolare($s, pciPersona($s, 'Bice Nuda'), 'nuda_proprietario', 100);
    pciLocazioneDl5($this, $s);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $estinzione = pciRegistra($this, $s, ruPassaggio('estinzione', pciRigaAperta($s, $carlo, 'usufruttuario'), null, '2026-07-01', 50) + ['accrescimento' => true],
        'Estinzione per morte di Carlo letta: l\'atto prevede l\'accrescimento a Ugo');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Il riparto: Ines 10192, Luca 30246, e il ripiego in due righe di persone diverse, Ugo e Carlo, 50 × 242 → 39780,82 ciascuna;
    // resti maggiori: 10192 + 30246 + 39781 + 39781 = 120000. L'accrescimento porta a Ugo i 184 giorni di Carlo dal 1/7:
    // 39781 × 184/242 = 30246,71 → 30247. Netti: Ugo 39781 + 30247 = 70028, Carlo 39781 − 30247 = 9534.
    // Somma: 70028 + 9534 + 10192 + 30246 = 120000.
    expect(pciSaldiDi($estinzione))->toBe(['Carlo Usufruttuario' => -30247, 'Venditore Ugo' => 30247])
        ->and(pciNetti($s))->toBe(['Carlo Usufruttuario' => 9534, 'Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Venditore Ugo' => 70028]);

    $p = pciProspetto($s);
    // Ivo: dalla riga di Ugo 39781 × 184/242 → 30247, dalla riga di Carlo, passata a Ugo dal 1/7, 30247: 30247 + 30247 = 60494,
    // pagati da Ugo negli stessi 184 giorni (dal 1/7 al 31/12), che si contano una volta.
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([['Spese generali', 'proprietario', 'Venditore Ugo', 60494, 184]])
        // Senza conduttore, febbraio e giugno: 39781 − 30247 = 9534 per riga, 58 giorni ciascuno. 9534 + 9534 + 60494 = 79562.
        ->and(pciSenza($p))->toBe([['Carlo Usufruttuario', 9534, 58], ['Venditore Ugo', 9534, 58]]);
});

// Rilievo «catene di persone diverse sullo stesso pagatore» (basso, lente denaro): due comproprietari che vendono allo stesso
// acquirente. NON copre: le vendite in giorni diversi, la stampa.
it('rilievo giorni sommati — Ugo e Rita comproprietari al 50 % vendono entrambi a Elsa il 1/7, Ivo dal 1/7: la voce di Ivo «pagata da Elsa» vale € 604,94 per 184 giorni, non per 368', function () {
    $s = pciScenario(['inquilino' => 100]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rita = pciPersona($s, 'Rita Comproprietaria');
    pciTitolare($s, $rita, 'proprietario', 50);
    pciLocazioneDl5($this, $s);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    pciRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 50), 'Rogito letto: Ugo vende a Elsa la sua metà');
    pciRegistra($this, $s, ruPassaggio('vendita', pciRigaAperta($s, $rita, 'proprietario'), $s['a'], '2026-07-01', 50), 'Rogito letto: Rita vende a Elsa la sua metà');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Il riparto: Ines 10192, Luca 30246, Ugo e Rita 39781 ciascuno (come T9). Elsa paga i 184 giorni di ciascuna riga:
    // 39781 × 184/242 = 30246,71 → 30247, due volte; Ugo e Rita 39781 − 30247 = 9534. Somma: 60494 + 9534 + 9534 + 10192 + 30246 = 120000.
    expect(pciNetti($s))->toBe(['Acquirente Elsa' => 60494, 'Ines Inquilina' => 10192, 'Luca Secondo' => 30246, 'Rita Comproprietaria' => 9534, 'Venditore Ugo' => 9534]);

    $p = pciProspetto($s);
    // 30247 + 30247 = 60494, pagati da Elsa negli stessi 184 giorni.
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([['Spese generali', 'proprietario', 'Acquirente Elsa', 60494, 184]])
        ->and(pciSenza($p))->toBe([['Rita Comproprietaria', 9534, 58], ['Venditore Ugo', 9534, 58]]);
});

// Rilievo «catene di persone diverse sullo stesso pagatore» (basso, lente denaro): il controllo dei tratti disgiunti dello stesso
// pagatore, dove la somma dei giorni è giusta (e il massimo sbaglierebbe). Verde oggi, deve restare verde. NON copre la stampa.
it('controllo dei giorni sommati — Ugo vende a Elsa il 1/4, Elsa rivende a Ugo il 1/10, Ivo dal 1/3: la voce di Ivo «pagata da Ugo» ha 123 giorni (31 + 92), e quella di Elsa 183', function () {
    $s = pciScenario(['inquilino' => 100]);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-03-31');
    pciRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-04-01', 100), 'Rogito letto: Ugo vende a Elsa');
    pciRegistra($this, $s, ruPassaggio('vendita', pciRigaAperta($s, $s['a'], 'proprietario'), $s['v'], '2026-10-01', 100), 'Rogito letto: Elsa rivende a Ugo');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-03-01');

    $p = pciProspetto($s);
    // La catena: Ugo 1/1–31/3 (31 + 28 + 31 = 90 giorni), Elsa 1/4–30/9 (30 + 31 + 30 + 31 + 31 + 30 = 183), Ugo 1/10–31/12
    // (31 + 30 + 31 = 92); 90 + 183 + 92 = 365. 120000 × 90/365 = 29589,04; × 183/365 = 60164,38; × 92/365 = 30246,58; resti
    // maggiori: 29589 + 60164 + 30247 = 120000. Ivo dal 1/3: del primo pezzo i 31 giorni di marzo, 29589 × 31/90 = 10191,77 e
    // × 59/90 = 19397,23 → 10192 + 19397 = 29589; il secondo e il terzo tutti. Ugo: 10192 + 30247 = 40439 per 31 + 92 = 123
    // giorni; Elsa 60164 per 183. Somma: 40439 + 60164 + 19397 = 120000.
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([
        ['Spese generali', 'proprietario', 'Acquirente Elsa', 60164, 183],
        ['Spese generali', 'proprietario', 'Venditore Ugo', 40439, 123],
    ])
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 19397, 59]]);
});

// --- 6. Gemelle con catene diverse, senza chi ha pagato in origine ----------------------------------------------------------

// Rilievo «gemelle con catene diverse senza chi ha pagato in origine» (basso, lente denaro), T3. Dal giro 47b l'ordine è quello
// che la fermata della successione chiede (rilievo alto: un usufruttuario che muore si registra prima con l'estinzione, con la
// stessa data, poi con la successione). NON copre: l'ordine inverso (la successione prima dell'estinzione, che la fermata
// rifiuta: si prova sulla rotta, non qui), l'arretrato a nome del defunto, la stampa.
it('rilievo gemelle senza chi ha pagato in origine, T3 — unità mista, il 1/7 Ugo muore: l\'usufrutto si estingue (Bice piena) e la metà piena va a Carla (successione); Ivo dal 1/7: Ivo rimborsa € 302,47 a Carla e € 302,47 a Bice, la parte di ciascuna riga, e a Ugo restano € 190,68', function () {
    $s = pciScenario(['inquilino' => 100]);
    pciMista($s);
    pciLocazioneDl5($this, $s);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $estinzione = pciRegistra($this, $s, ruPassaggio('estinzione', pciRigaAperta($s, $s['v'], 'usufruttuario'), null, '2026-07-01', 50), 'Estinzione per morte di Ugo, letta');
    $carla = pciPersona($s, 'Carla Erede');
    pciRegistra($this, $s, ruPassaggio('successione', $s['rigaV'], $carla, '2026-07-01', 50), 'Successione di Ugo letta: la metà piena a Carla');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Il riparto del P9: Ines 10192, Luca 30246, Ugo due gemelle da 39781 (10192 + 30246 + 39781 + 39781 = 120000). L'estinzione
    // fa pagare a Bice i 184 giorni della riga dell'usufrutto: 39781 × 184/242 = 30246,71 → 30247.
    expect(pciSaldiDi($estinzione))->toBe(['Bice Nuda' => 30247, 'Venditore Ugo' => -30247]);
    // In quest'ordine il saldo dell'estinzione è una fonte dell'arretrato: la successione con l'arretrato agli eredi porta a Carla
    // la coppia della riga del pieno, 30247, più l'arretrato di Ugo, 79562 − 30247 − 30247 = 19068: Carla 30247 + 19068 = 49315,
    // e Ugo si chiude a zero (79562 − 30247 − 49315 = 0). Somma: 30247 + 49315 + 10192 + 30246 = 120000.
    expect(pciNetti($s))->toBe(['Bice Nuda' => 30247, 'Carla Erede' => 49315, 'Ines Inquilina' => 10192, 'Luca Secondo' => 30246]);

    $p = pciProspetto($s);
    // Le due gemelle sono passate tutte e due ad altri il 1/7, e Ugo non ha voci per Ivo: ciascuna resta la sua parte, 39781 ×
    // 184/242 = 30246,71 → 30247, quanto il conguaglio della riga — a Carla il pieno, a Bice l'usufrutto (non 60493 unite con lo
    // scarto −1 a una delle due). Somma 30247 + 30247 = 60494.
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([
        ['Spese generali', 'proprietario', 'Bice Nuda', 30247, 184],
        ['Spese generali', 'proprietario', 'Carla Erede', 30247, 184],
    ])
        // Senza conduttore, ciò che Ugo ha tenuto davvero: (39781 − 30247) × 2 = 9534 + 9534 = 19068 (non 19069), febbraio e giugno,
        // 58 giorni contati una volta (DL4). 60494 + 19068 = 79562.
        ->and(pciSenza($p))->toBe([['Venditore Ugo', 19068, 58]]);
});

// --- 7. Già versato della persona e dell'unità insieme, due gruppi ----------------------------------------------------------

// Rilievo «già versato della persona e dell'unità insieme su una voce con due gruppi» (basso, lente denaro), T4. NON copre: il
// ripiego di Ugo in aprile e maggio (il rilievo del ripiego al lordo), la stampa, due cambi di fila.
it('rilievo versato della persona e dell\'unità, T4 — € 120,00 versati dall\'unità e € 10,09 da Ines, Ines inquilina fino al 31/3 e di nuovo dal 1/6, cambio d\'inquilino a Luca il 1/9: Luca € 361,05 come la sua riga del conguaglio, Ines € 528,54', function () {
    $s = pciScenario(['inquilino' => 100]);
    $ines = pciPersona($s, 'Ines Inquilina');
    pciTitolare($s, $ines, 'inquilino');
    pciVersato($s, 12000);
    pciVersato($s, 1009, $ines);
    pciFine($this, $s, $ines, null, '2026-04-01');
    pciInizio($this, $s, $ines, '2026-06-01');
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-08-31');
    $cambio = pciFine($this, $s, $ines, pciPersona($s, 'Luca Nuovo'), '2026-09-01');

    // Il riparto: Ines 1/1–31/3 (31 + 28 + 31 = 90 giorni) 120000 × 90/365 = 29589,04; Ugo per ripiego 1/4–31/5 (30 + 31 = 61)
    // 20054,79; Ines 1/6–31/12 (214) 70356,16; resti maggiori: 29589 + 20055 + 70356 = 120000. Il versato: 1009 della persona a
    // Ines; quello dell'unità sui lordi al netto di quello della persona, 12000 × 98936/118991 = 9977,49 a Ines e × 20055/118991
    // = 2022,51 a Ugo; resti maggiori: 9977 + 2023 = 12000. Ines −(1009 + 9977) = −10986.
    expect(pciVersatoNelRiparto($s))->toBe(['Ines Inquilina' => -10986, 'Venditore Ugo' => -2023]);
    // Il conguaglio, gruppo per gruppo: il totale R(10986) sui lordi [29589, 70356] = [3252,44 → 3252, 7733,56 → 7734], meno la
    // parte della persona R(1009) = [298,72 → 299, 710,28 → 710]: la parte dell'unità del secondo gruppo è 7734 − 710 = 7024.
    // Luca: (70356 − 7024) × 122/214 = 63332 × 122/214 = 36105,16 → 36105.
    expect(pciSaldiDi($cambio))->toBe(['Ines Inquilina' => -36105, 'Luca Nuovo' => 36105]);
    // Netti: Ines 29589 + 70356 − 10986 − 36105 = 52854, Luca 36105, Ugo 20055 − 2023 = 18032.
    // Somma: 52854 + 36105 + 18032 = 106991 = 120000 − 12000 − 1009.
    expect(pciNetti($s))->toBe(['Ines Inquilina' => 52854, 'Luca Nuovo' => 36105, 'Venditore Ugo' => 18032]);

    $p = pciProspetto($s);
    // Il prospetto deve dire lo stesso: Luca 36105, la sua riga del conguaglio (non R(9977) = [2953,72 → 2954, 7023,28 → 7023] e
    // 63333 × 122/214 = 36105,73 → 36106); Ines 52854 (non 52853). 36105 + 52854 = 88959 = 29589 + 70356 − 10986.
    expect(pciSezione($p, 'Luca Nuovo')['totale'] ?? null)->toBe(36105)
        ->and(pciSezione($p, 'Ines Inquilina')['totale'] ?? null)->toBe(52854);
});

/*
|--------------------------------------------------------------------------
| Secondo giro sulle correzioni della beta.47 (giro 47b) — i rilievi confermati sul prospetto
|--------------------------------------------------------------------------
|
| Ogni test nasce da un rilievo del giro 47b (lenti denaro e sicurezza, con il loro scettico): è rosso sul codice di oggi per la
| ragione del difetto, e diventa verde con la correzione; il controllo del perimetro è verde già oggi e lo dice nel nome. Dove
| l'usufruttuario muore, l'ordine è quello della fermata della successione (rilievo alto del giro): prima l'estinzione
| dell'usufrutto, con la stessa data, poi la successione. Le cifre sono fatte a mano, con la somma accanto; le divisioni per
| giorni sono quelle del motore, con i resti maggiori (`MoneyHelper::ripartisciPerQuote`).
*/

/** Voci (di un conduttore, o senza conduttore) sommate per chi le ha pagate: nome → centesimi, in ordine di nome. */
function pciPerPagatore(array $voci): array
{
    return collect($voci)->groupBy(fn ($v) => (string) ($v['pagato_da'] ?? ''))->map(fn ($g) => (int) $g->sum('importo'))->sortKeys()->all();
}

/**
 * Le sonde 3, 3c e 3t del giro 47b: l'unità mista (`pciMista`), «Spese generali» tutta sull'«Inquilino». Con `$tratto` Ines
 * inquilina censita dal 2019 con la fine locazione il 1/2, prima della generazione (il ripiego di Ugo ha un tratto, dal 1/2);
 * senza, nessun inquilino alla generazione (le gemelle senza tratto). Piano generato, rate emesse fino al 30/6. Poi, dalle rotte
 * vere: Teo inquilino dal 1/1 (dal 1/2 con il tratto) e la sua fine locazione il 1/7, senza nuovo inquilino; il 1/7 Ugo muore:
 * prima l'estinzione del suo usufrutto (Bice torna piena), poi la successione della sua metà piena a Carla, con l'arretrato a nome
 * del defunto; con `$ivo` Ivo inquilino dal 1/7.
 */
function pciGemelleCateneDiverse($test, bool $tratto, bool $ivo): array
{
    $s = pciScenario(['inquilino' => 100]);
    pciMista($s);
    if ($tratto) {
        $ines = pciPersona($s, 'Ines Inquilina');
        pciTitolare($s, $ines, 'inquilino');
        pciFine($test, $s, $ines, null, '2026-02-01');
    }
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $teo = pciPersona($s, 'Teo Primo');
    pciInizio($test, $s, $teo, $tratto ? '2026-02-01' : '2026-01-01');
    pciFine($test, $s, $teo, null, '2026-07-01');
    pciRegistra($test, $s, ruPassaggio('estinzione', pciRigaAperta($s, $s['v'], 'usufruttuario'), null, '2026-07-01', 50), 'Estinzione per morte di Ugo, letta');
    pciRegistra($test, $s, ruPassaggio('successione_defunto', $s['rigaV'], pciPersona($s, 'Carla Erede'), '2026-07-01', 50),
        'Successione di Ugo letta: la metà piena a Carla, l\'arretrato a nome del defunto');
    if ($ivo) {
        pciInizio($test, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');
    }

    return $s;
}

// --- 8. Gemelle con catene diverse: lo scarto a chi ha pagato in origine, mai perso ----------------------------------------

// Rilievo «gemelle con catene diverse: il resto si perde e il totale vale € 1.200,01» (medio, lente denaro), sonda 3: un conduttore
// per ogni giorno, e nei giorni di Ivo chi ha pagato in origine non c'è più. NON copre: il tratto (sonda 3t), i giorni senza
// conduttore (sonda 3c), l'arretrato agli eredi, le gemelle su due tabelle.
it('rilievo gemelle con catene diverse, sonda 3 — unità mista vuota alla generazione, Teo dal 1/1 al 30/6, il 1/7 l\'usufrutto di Ugo si estingue (Bice) e la metà piena va a Carla, Ivo dal 1/7: Teo rimborsa a Ugo € 595,06, Ivo € 302,47 a Bice e € 302,47 a Carla, e il totale è € 1.200,00, non € 1.200,01', function () {
    $s = pciGemelleCateneDiverse($this, false, true);

    // Il riparto: nessun inquilino alla generazione, il ripiego di Ugo in due gemelle senza tratto, 120000 × 50 % = 60000 ciascuna,
    // 365 giorni. L'estinzione e la successione spostano i 184 giorni dal 1/7 di ciascuna riga: 60000 × 184/365 = 30246,58 e
    // × 181/365 = 29753,42 → 30247 + 29753 = 60000; a Bice l'usufrutto, a Carla il pieno (con l'arretrato a nome del defunto la
    // successione scrive solo la coppia). Ugo 120000 − 30247 − 30247 = 59506. Somma: 30247 + 30247 + 59506 = 120000.
    expect(pciNetti($s))->toBe(['Bice Nuda' => 30247, 'Carla Erede' => 30247, 'Venditore Ugo' => 59506]);

    $p = pciProspetto($s);
    // Ivo, dal 1/7: Ugo non c'è più fra chi ha pagato, e restano le parti separate, quanto i due conguagli: 30247 + 30247 = 60494
    // (non la parte unita, 120000 × 184/365 = 60493,15 → 60493).
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([
        ['Spese generali', 'proprietario', 'Bice Nuda', 30247, 184],
        ['Spese generali', 'proprietario', 'Carla Erede', 30247, 184],
    ]);
    // Teo, 1/1–30/6 (31 + 28 + 31 + 30 + 31 + 30 = 181 giorni), tutto pagato da Ugo: lo scarto va alla voce di chi ha pagato in
    // origine, così la somma è l'importo: 120000 − 60494 = 59506 (le separate, 29753 + 29753; non la parte unita 120000 × 181/365 =
    // 59506,85 → 59507, che con le separate di Ivo fa 59507 + 60494 = 120001). È il netto di Ugo.
    expect(pciVociDi(pciSezione($p, 'Teo Primo')))->toBe([['Spese generali', 'proprietario', 'Venditore Ugo', 59506, 181]])
        // Nessun giorno senza conduttore (181 + 184 = 365); il totale dell'inquilino è il riparto: 59506 + 60494 = 120000.
        ->and(pciSenza($p))->toBe([])
        ->and($p['totale_inquilino'])->toBe(120000);
    // La stampa dichiara il totale del riparto, € 1.200,00.
    $testo = pciStampa($s);
    expect($testo)->toContain('Totale delle voci che il riparto pone sul ruolo inquilino su questa unità nell\'esercizio: € 1.200,00');
    expect($testo)->not->toContain('1.200,01');
});

// Rilievo «gemelle con catene diverse» (medio, lente denaro), sonda 3c: senza conduttori dopo il 1/7 lo scarto finiva su una persona
// nuova. NON copre: il conduttore dopo il 1/7 (sonda 3), il tratto (sonda 3t), la stampa.
it('rilievo gemelle con catene diverse, sonda 3c — la stessa unità senza Ivo: Teo rimborsa a Ugo € 595,06, e i 184 giorni senza conduttore restano a Bice (€ 302,47) e a Carla (€ 302,47), quanto i loro conguagli', function () {
    $s = pciGemelleCateneDiverse($this, false, false);

    // I netti della sonda 3: Bice 30247 (il saldo dell'estinzione), Carla 30247, Ugo 59506; somma 120000.
    expect(pciNetti($s))->toBe(['Bice Nuda' => 30247, 'Carla Erede' => 30247, 'Venditore Ugo' => 59506]);

    $p = pciProspetto($s);
    // Senza conduttore, dal 1/7: le parti separate, quanto i conguagli, 30247 + 30247 = 60494 (non Bice 30246: lo scarto −1 della
    // parte unita non va a una persona nuova). Teo: lo scarto a chi ha pagato in origine, 120000 − 60494 = 59506.
    // Somma: 59506 + 30247 + 30247 = 120000.
    expect(pciSenza($p))->toBe([['Bice Nuda', 30247, 184], ['Carla Erede', 30247, 184]])
        ->and(pciVociDi(pciSezione($p, 'Teo Primo')))->toBe([['Spese generali', 'proprietario', 'Venditore Ugo', 59506, 181]])
        ->and($p['totale_inquilino'])->toBe(120000);
});

// Rilievo «gemelle con catene diverse» (medio, lente denaro), sonda 3t: il difetto non dipende dalle gemelle senza tratto. NON copre:
// i giorni senza conduttore (sonda 3c), la stampa, la sezione di Ines (giusta già oggi).
it('rilievo gemelle con catene diverse, sonda 3t — la stessa unità con Ines fino al 31/1 prima della generazione, Teo dal 1/2 al 30/6, Ivo dal 1/7: Teo rimborsa a Ugo € 493,14, quanto il netto di Ugo, e il totale è € 1.200,00', function () {
    $s = pciGemelleCateneDiverse($this, true, true);

    // Il riparto (pesi quota × giorni su 36500): Ines 1/1–31/1, 100 × 31 → 10191,78; il ripiego di Ugo dal 1/2 al 31/12 (334
    // giorni) in due gemelle 50 × 334 → 54904,11 ciascuna; resti maggiori: 10192 + 54904 + 54904 = 120000. Dal 1/7, 184 dei 334
    // giorni di ciascuna riga: 54904 × 184/334 = 30246,51 e × 150/334 = 24657,49 → 30247 + 24657 = 54904; a Bice l'usufrutto e a
    // Carla il pieno. Ugo 54904 + 54904 − 30247 − 30247 = 49314. Somma: 10192 + 30247 + 30247 + 49314 = 120000.
    expect(pciNetti($s))->toBe(['Bice Nuda' => 30247, 'Carla Erede' => 30247, 'Ines Inquilina' => 10192, 'Venditore Ugo' => 49314]);

    $p = pciProspetto($s);
    // Ivo: le separate, 30247 + 30247 = 60494. Teo, 1/2–30/6 (28 + 31 + 30 + 31 + 30 = 150 giorni): lo scarto a Ugo,
    // 109808 − 60494 = 49314 (le separate, 24657 + 24657; non la parte unita 109808 × 150/334 = 49314,97 → 49315).
    expect(pciVociDi(pciSezione($p, 'Ivo Ultimo')))->toBe([
        ['Spese generali', 'proprietario', 'Bice Nuda', 30247, 184],
        ['Spese generali', 'proprietario', 'Carla Erede', 30247, 184],
    ])
        ->and(pciVociDi(pciSezione($p, 'Teo Primo')))->toBe([['Spese generali', 'proprietario', 'Venditore Ugo', 49314, 150]])
        ->and(pciSenza($p))->toBe([])
        // 10192 + 49314 + 60494 = 120000, non 120001.
        ->and($p['totale_inquilino'])->toBe(120000);
});

// --- 9. Gemelle con il già versato della persona e dell'unità insieme -----------------------------------------------------

// Rilievo «gemelle con già versato della persona e dell'unità insieme» (basso, lente denaro): il prospetto divide la parte dell'unità
// fra le gemelle in due passi, il conguaglio in uno. NON copre: le gemelle diverse (mista 30/70 o 45/55: lì torna già), la voce divisa
// fra i ruoli, il già versato della sola persona, la stampa.
it('rilievo gemelle con versato della persona e dell\'unità — il P9 con € 120,00 versati dall\'unità e € 10,09 da Ugo: Ivo rimborsa a Elsa € 272,34, quanto il suo conguaglio, e a Ugo € 272,35', function () {
    $s = pciScenario(['inquilino' => 100]);
    pciMista($s);
    pciVersato($s, 12000);
    pciVersato($s, 1009, $s['v']);
    pciLocazioneDl5($this, $s);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    $vendita = pciRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 50), 'Rogito letto: Ugo vende a Elsa la sua metà piena');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Il riparto del P9: Ines 10192, Luca 30246, Ugo due gemelle da 39781 (79562). Il versato: prima quello della persona, 1009,
    // sul lordo di Ugo (79562 − 1009 = 78553); poi quello dell'unità sui lordi così scontati, 10192 + 30246 + 78553 = 118991:
    // 12000 × 10192/118991 = 1027,84, × 30246/118991 = 3050,25, × 78553/118991 = 7921,91; 1027 + 3050 + 7921 = 11998, e il resto, 2,
    // il motore lo dà alla riga con il resto più alto finché ha capienza: tutto a Ugo, 7923. 1027 + 3050 + 7923 = 12000; Ugo
    // 1009 + 7923 = 8932.
    expect(pciVersatoNelRiparto($s))->toBe(['Ines Inquilina' => -1027, 'Luca Secondo' => -3050, 'Venditore Ugo' => -8932]);
    // Il conguaglio, gemella per gemella: il totale R(8932) su [39781, 39781] = [4466, 4466], meno la parte della persona R(1009) =
    // [504,5 → 505, 504,5 → 504] (a parità di resto vince la prima: qui l'usufrutto, come dice il saldo di Elsa sotto): le parti dell'unità sono 3961 per l'usufrutto e
    // 3962 per il pieno. Elsa, la riga del pieno dal 1/7: (39781 − 3962) × 184/242 = 35819 × 184/242 = 27234,28 e × 58/242 =
    // 8584,72; resti maggiori: 27234 + 8585 = 35819. (Il rilievo scrive 27233,95: il conto è 27234,28, l'esito è lo stesso.)
    expect(pciSaldiDi($vendita))->toBe(['Acquirente Elsa' => 27234, 'Venditore Ugo' => -27234]);

    $p = pciProspetto($s);
    // Il ripiego di Ugo al netto della parte dell'unità: 79562 − 7923 = 71639 (i 1009 della persona restano dentro: li ha pagati
    // lui). Senza conduttore, febbraio e giugno: 71639 × 58/242 = 17169,68 e × 184/242 = 54469,32 → 17170 + 54469 = 71639.
    // Il totale dell'inquilino: Ines 10192 − 1027 = 9165, Luca 30246 − 3050 = 27196, il ripiego 71639; 9165 + 27196 + 71639 = 108000.
    expect(pciSenza($p))->toBe([['Venditore Ugo', 17170, 58]])
        ->and($p['totale_inquilino'])->toBe(108000);
    // Ivo, i 184 giorni: 54469. A Elsa quanto il suo saldo, 27234 (non 27235: oggi il prospetto divide i 7923 fra le gemelle in un
    // passo solo, R(7923) = [3962, 3961], e al pieno toccano 3961: (39781 − 3961) × 184/242 = 35820 × 184/242 = 27235,04 → 27235);
    // a Ugo, l'usufrutto che resta suo, 54469 − 27234 = 27235. Somma: 27234 + 27235 = 54469.
    // Dal secondo giro sulle correzioni il conduttore vede la voce al lordo e accanto la riga del già versato «dell'unità» che la
    // sconta (stampa, art. 9): qui conta il netto per chi ha pagato, cioè la somma delle due righe.
    expect(pciPerPagatore(pciSezione($p, 'Ivo Ultimo')['voci'] ?? []))->toBe(['Acquirente Elsa' => 27234, 'Venditore Ugo' => 27235]);
});

// --- 10. Il ripiego su due tabelle -----------------------------------------------------------------------------------------

// Rilievo «ripiego su due tabelle» (basso, lente denaro), scenario 2c: la chiave delle gemelle contiene la tabella e il conguaglio no,
// e il prospetto arrotonda due volte. NON copre: le righe «nelle sue rate» su due tabelle (P8, sopra), le tabelle con coefficienti
// diversi, come i 5041 si ripartiscono fra le tabelle, la stampa.
it('rilievo ripiego su due tabelle — € 100,00 tutta sull\'inquilino su due tabelle a metà, nessun inquilino alla generazione, Ugo vende tutto a Elsa il 1/7, Ivo dal 1/7: Ivo rimborsa a Elsa € 50,41 in tutto, quanto il suo conguaglio, e a Ugo restano € 49,59', function () {
    $s = pciScenario(['inquilino' => 100]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conti.id');
    DB::table('conti')->where('id', $contoId)->update(['importo' => 10000]);
    DB::table('conto_tabella_millesimale')->where('conto_id', $contoId)->update(['coefficiente' => 50]);
    $scale = \App\Models\Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Scale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $scale->id, 'immobile_id' => $s['unita']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $contoId, 'tabella_id' => $scale->id, 'coefficiente' => 50, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    pciRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 100), 'Rogito letto: Ugo vende tutto a Elsa');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Due righe di ripiego di Ugo da 5000 (10000 × 50/100 per tabella), 365 giorni. Il conguaglio somma la voce sulle due tabelle e
    // arrotonda una volta: 10000 × 184/365 = 5041,10 → 5041 a Elsa (× 181/365 = 4958,90 → 4959 a Ugo). Somma: 5041 + 4959 = 10000.
    expect(pciNetti($s))->toBe(['Acquirente Elsa' => 5041, 'Venditore Ugo' => 4959]);

    $p = pciProspetto($s);
    $ivo = pciSezione($p, 'Ivo Ultimo');
    // Ivo, i 184 giorni dal 1/7, pagati da Elsa: 5041 in tutto, comunque si ripartano fra le tabelle (non 5000 × 184/365 =
    // 2520,55 → 2521 per tabella, due volte, 5042). Senza conduttore a Ugo, 1/1–30/6: 4959 in tutto (non 5000 × 181/365 = 2479,45
    // → 2479, due volte, 4958). 5041 + 4959 = 10000.
    expect(pciPerPagatore($ivo['voci'] ?? []))->toBe(['Acquirente Elsa' => 5041])
        ->and($ivo['totale'] ?? null)->toBe(5041)
        ->and(pciPerPagatore($p['senza_conduttore']['voci']))->toBe(['Venditore Ugo' => 4959])
        ->and($p['totale_inquilino'])->toBe(10000);
});

// --- 11. Il ripiego al netto del già versato, in stampa --------------------------------------------------------------------

// Rilievo «il ripiego al netto del già versato compare in stampa senza la riga già versato» (basso, lente denaro), scenario T5: il
// conduttore deve poter ricostruire la sua voce, come Ines e Luca con la loro riga «già versato». NON copre: il ripiego senza la
// vendita (B), l'unità mista (T11), la voce divisa fra i ruoli (F), il già versato della persona, i giorni della riga «già versato».
it('rilievo già versato del ripiego in stampa, T5 — Ivo ha la voce «Spese generali» al lordo, € 604,93 pagata da Elsa, e la riga «Spese generali — già versato» di € -60,49 pagata da Elsa: totale € 544,44; a Ugo, senza conduttore, € 171,62 al netto', function () {
    [$s] = pciRipiegoConVersato($this, ['inquilino' => 100], true);

    // Le cifre del T5 (sopra): il ripiego di Ugo 79562 (242 giorni), la sua parte del versato dell'unità 12000 × 79562/120000 =
    // 7956,2 → 7956; Elsa con il conguaglio (79562 − 7956) × 184/242 = 71606 × 184/242 = 54444,23 → 54444.
    expect(pciNetti($s)['Acquirente Elsa'] ?? null)->toBe(54444);

    $p = pciProspetto($s);
    $ivo = pciSezione($p, 'Ivo Ultimo');
    $voci = collect($ivo['voci'] ?? []);
    $lorda = $voci->firstWhere('conto', 'Spese generali');
    $versato = $voci->firstWhere('conto', 'Spese generali — già versato');
    // I giorni senza conduttore restano al netto: 71606 × 58/242 = 17161,77 → 17162 a Ugo (71606 − 54444 = 17162), febbraio e
    // giugno. Il totale dell'inquilino non cambia: 108000 = 120000 − 12000.
    expect(pciSenza($p))->toBe([['Venditore Ugo', 17162, 58]])
        ->and($p['totale_inquilino'])->toBe(108000);
    // La voce al lordo: 79562 × 184/242 = 60493,42 → 60493, i 184 giorni di Ivo, pagata da Elsa (non il netto 54444 da solo).
    expect($lorda === null ? null : [$lorda['modo'], $lorda['pagato_da'], (int) $lorda['importo'], (int) $lorda['giorni']])->toBe(['proprietario', 'Acquirente Elsa', 60493, 184])
        // Il già versato tolto: 60493 − 54444 = 6049 (7956 × 184/242 = 6049,19 → 6049), con lo stesso modo e lo stesso «pagata da».
        ->and($versato === null ? null : [$versato['modo'], $versato['pagato_da'], (int) $versato['importo']])->toBe(['proprietario', 'Acquirente Elsa', -6049])
        ->and($voci->count())->toBe(2)
        // 60493 − 6049 = 54444, quanto il conguaglio di Elsa, tutto da rimborsare.
        ->and($ivo['totale'] ?? null)->toBe(54444)
        ->and($ivo['da_rimborsare'] ?? null)->toBe(54444);

    // La stampa, nella sezione di Ivo: la voce al lordo e la riga del già versato, con il totale.
    preg_match('/Ivo Ultimo (?:(?!Totale a carico di ).)*Totale a carico di Ivo Ultimo € [0-9.,]+/u', pciStampa($s), $m);
    expect($m[0] ?? '(nessuna sezione di Ivo Ultimo nella stampa)')->toContain('€ 604,93 pagata da Acquirente Elsa')
        ->toContain('Spese generali — già versato')
        ->toContain('€ -60,49 pagata da Acquirente Elsa')
        ->toContain('Totale a carico di Ivo Ultimo € 544,44');
});

// --- 12. Il perimetro della catena dei pagatori ----------------------------------------------------------------------------

// Rilievo «le query con il perimetro dell'unità non hanno un test negativo» (basso, lente sicurezza), la catena dei pagatori: un
// controllo, verde oggi, che deve restare verde. Tolto `where('immobile_id', …)` dalla catena, il nudo dell'Interno 2 entrerebbe
// nel prospetto dell'Interno 1 (la mutazione dello scettico). NON copre: «c'è ancora» dell'inizio locazione
// (InizioLocazionePianoFermoTest), due condomini, la vendita fra due unità, l'anteprima del passaggio.
it('controllo del perimetro della catena dei pagatori — Ugo usufruttuario dell\'Interno 1 (Bice e Carla nude al 50 %) e dell\'Interno 2 (Zeno nudo), estinzione sull\'Interno 2 il 1/6 e sull\'Interno 1 il 1/7, Ivo nell\'Interno 1 dal 1/7 e Kai nell\'Interno 2 dal 1/6: il prospetto dell\'Interno 1 non nomina Zeno, quello dell\'Interno 2 non nomina Bice, Carla né Ivo', function () {
    $s = pciScenario(['inquilino' => 100]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    pciTitolare($s, pciPersona($s, 'Bice Nuda'), 'nuda_proprietario', 50);
    pciTitolare($s, pciPersona($s, 'Carla Nuda'), 'nuda_proprietario', 50);
    $b = \App\Models\Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'PCI-Z-' . $s['unita']->id, 'nome' => 'Interno 2', 'interno' => '2']);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $b->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $sb = ['unita' => $b] + $s;
    pciTitolare($sb, $s['v'], 'usufruttuario');
    pciTitolare($sb, pciPersona($s, 'Zeno Altrui'), 'nuda_proprietario');
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-05-31');
    pciRegistra($this, $sb, ruPassaggio('estinzione', pciRigaAperta($sb, $s['v'], 'usufruttuario'), null, '2026-06-01', 100), 'Estinzione dell\'usufrutto di Ugo sull\'Interno 2, letta');
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    pciRegistra($this, $s, ruPassaggio('estinzione', $s['rigaV'], null, '2026-07-01', 100), 'Estinzione dell\'usufrutto di Ugo sull\'Interno 1, letta');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');
    pciInizio($this, $sb, pciPersona($s, 'Kai Inquilino'), '2026-06-01');

    // € 1.200,00 × 1000/2000 = 60000 per unità, tutto il ripiego di Ugo (nessun inquilino alla generazione). Interno 1: i nudi
    // pagano i 184 giorni dal 1/7, 60000 × 184/365 = 30246,58 e × 181/365 = 29753,42 → 30247 + 29753 = 60000; a metà, 15123,50:
    // 15124 a Bice e 15123 a Carla (a parità di resto vince chi viene prima). Somma: 15124 + 15123 + 29753 = 60000.
    // Interno 2: Zeno i 214 giorni dal 1/6 (30 + 31 + 31 + 30 + 31 + 30 + 31), 60000 × 214/365 = 35178,08 e × 151/365 = 24821,92
    // → 35178 + 24822 = 60000.
    expect(pciNetti($s))->toBe(['Bice Nuda' => 15124, 'Carla Nuda' => 15123, 'Venditore Ugo' => 29753])
        ->and(pciNetti($sb))->toBe(['Venditore Ugo' => 24822, 'Zeno Altrui' => 35178]);

    $pa = pciProspetto($s);
    $pb = pciProspetto($sb);
    // Interno 1: Ivo 30247 per 184 giorni, pagati dai due nudi tornati pieni; a Ugo 29753 per 181 (1/1–30/6). 30247 + 29753 = 60000.
    expect(pciVociDi(pciSezione($pa, 'Ivo Ultimo')))->toBe([['Spese generali', 'proprietario', 'Bice Nuda e Carla Nuda', 30247, 184]])
        ->and(pciSenza($pa))->toBe([['Venditore Ugo', 29753, 181]]);
    // Interno 2: Kai 35178 per 214 giorni, pagati da Zeno; a Ugo 24822 per 151 (1/1–31/5). 35178 + 24822 = 60000.
    expect(pciVociDi(pciSezione($pb, 'Kai Inquilino')))->toBe([['Spese generali', 'proprietario', 'Zeno Altrui', 35178, 214]])
        ->and(pciSenza($pb))->toBe([['Venditore Ugo', 24822, 151]]);
    // Nessun nome dell'altra unità, né nel prospetto né nella stampa (un `not->toContain` per nome).
    expect(json_encode($pa, JSON_UNESCAPED_UNICODE))->not->toContain('Zeno');
    expect(pciStampa($s))->not->toContain('Zeno');
    foreach (['Bice', 'Carla', 'Ivo'] as $nome) {
        expect(json_encode($pb, JSON_UNESCAPED_UNICODE))->not->toContain($nome);
        expect(pciStampa($sb))->not->toContain($nome);
    }
});

// Secondo giro sulle correzioni, la memoria della catena dei pagatori: si azzera a ogni calcolo. Lo stesso oggetto calcola il prospetto
// prima e dopo una vendita registrata in mezzo, e il secondo calcolo la vede. NON copre: due unità calcolate dallo stesso oggetto (la
// chiave della memoria comprende l'unità).
it('controllo — lo stesso calcolatore prima e dopo una vendita: la catena dei pagatori non resta quella di prima', function () {
    $s = pciScenario(['inquilino' => 100]);
    pciLocazioneDl5($this, $s);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');
    $calcolatore = new ProspettoOneriAccessori();

    // Prima della vendita i 184 giorni di Ivo li ha pagati Ugo: 79562 × 184/242 = 60493,42 → 60493.
    $prima = $calcolatore->calcola($s['unita'], $s['e']);
    expect(pciPerPagatore(pciSezione($prima, 'Ivo Ultimo')['voci'] ?? []))->toBe(['Venditore Ugo' => 60493]);

    pciRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 100), 'Rogito letto: Ugo vende tutto a Elsa');

    // Dopo, gli stessi giorni li ha pagati Elsa con il conguaglio, e lo stesso calcolatore lo dice.
    $dopo = $calcolatore->calcola($s['unita'], $s['e']);
    expect(pciPerPagatore(pciSezione($dopo, 'Ivo Ultimo')['voci'] ?? []))->toBe(['Acquirente Elsa' => 60493]);
});

/*
|--------------------------------------------------------------------------
| Terzo giro sulle correzioni della beta.47 (giro 47c) — le gemelle su due tabelle
|--------------------------------------------------------------------------
|
| Il rilievo basso della lente denaro «Gemelle su due tabelle: il prospetto arrotonda ancora tabella per tabella». Il conguaglio somma
| le tabelle di ciascun ruolo e arrotonda una volta (la chiave del gruppo ha il ruolo, non la tabella); il prospetto lavora ancora riga
| per riga, cioè per ruolo e per tabella, in due punti: dove divide le gemelle con catene diverse (A, C) e dove spalma il già versato
| «dell'unità» sulle righe (D). Il totale torna sempre; un centesimo, a volte due, va alla persona sbagliata. I tre scenari della sonda,
| dalle rotte vere: rossi sul codice di oggi, verdi con la correzione. Nessun inquilino alla generazione, rate emesse fino al 30/6, Ivo
| inquilino dal 1/7; la seconda tabella «Scale» montata come nel test «ripiego su due tabelle». Le cifre sono fatte a mano, con la
| somma accanto; le divisioni per giorni con i resti maggiori (`MoneyHelper::ripartisciPerQuote`).
*/

/**
 * La seconda tabella della voce «Spese generali», «Scale», con l'unità a 1000 millesimi anche lì. La voce si divide fra «Proprietà» e
 * «Scale» con i coefficienti dati (somma 100), e su «Scale» fra i ruoli dati, come su «Proprietà» (`pciScenario`).
 */
function pciSecondaTabella(array $s, int $coefficientePrima, int $coefficienteSeconda, array $ripartizione): void
{
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conti.id');
    DB::table('conto_tabella_millesimale')->where('conto_id', $contoId)->update(['coefficiente' => $coefficientePrima]);
    $scale = \App\Models\Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Scale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $scale->id, 'immobile_id' => $s['unita']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $contoId, 'tabella_id' => $scale->id, 'coefficiente' => $coefficienteSeconda, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($ripartizione as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
}

/**
 * Le righe del riparto del piano sull'unità, senza il già versato: [tabella, ruolo richiesto, ruolo risolto, importo], in ordine.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: int}>
 */
function pciRigheDelRiparto(array $s): array
{
    return DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('immobile_id', $s['unita']->id)->where('tipo', '!=', 'netting')->get()
        ->map(fn ($r) => [(string) $r->tabella_nome, (string) $r->ruolo_richiesto, (string) $r->ruolo_risolto, (int) $r->importo])->sort()->values()->all();
}

/**
 * Gli scenari A e C: l'unità mista (`pciMista`: Ugo pieno al 50 % e usufruttuario dell'altro 50 %, Bice nuda di quel 50 %), «Spese
 * generali» € 1.200,00 tutta sull'«Inquilino», divisa a metà fra «Proprietà» e «Scale»; nessun inquilino alla generazione; piano
 * generato, rate emesse fino al 30/6.
 */
function pciMistaSuDueTabelle(): array
{
    $s = pciScenario(['inquilino' => 100]);
    pciMista($s);
    pciSecondaTabella($s, 50, 50, ['inquilino' => 100]);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');

    return $s;
}

// Rilievo «gemelle su due tabelle» (basso, lente denaro, giro 47c), scenario A: le gemelle con catene diverse (la vendita della metà
// piena) divise tabella per tabella. NON copre: la vendita della nuda, le gemelle con un tratto, coefficienti diversi sulla mista, la stampa.
it('rilievo gemelle su due tabelle, A — unità mista con la voce a metà su due tabelle, nessun inquilino alla generazione, Ugo vende a Elsa la metà piena il 1/7 e Ivo entra il 1/7: Ivo rimborsa a Elsa € 302,47, quanto il suo conguaglio, e a Ugo € 302,46', function () {
    $s = pciMistaSuDueTabelle();
    $vendita = pciRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 50), 'Rogito letto: Ugo vende a Elsa la sua metà piena');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Il riparto: € 1.200,00 × 50/100 = 60000 per tabella, e su ciascuna le due gemelle del ripiego di Ugo al 50 %, 30000 per riga,
    // 365 giorni. 4 × 30000 = 120000.
    expect(pciRigheDelRiparto($s))->toBe([
        ['Proprietà', 'inquilino', 'proprietario', 30000],
        ['Proprietà', 'inquilino', 'usufruttuario', 30000],
        ['Scale', 'inquilino', 'proprietario', 30000],
        ['Scale', 'inquilino', 'usufruttuario', 30000],
    ]);
    // Il conguaglio somma le due tabelle della riga del pieno e arrotonda una volta: 60000 × 184/365 = 30246,58 → 30247 a Elsa
    // (× 181/365 = 29753,42 → 29753 resta a Ugo; 30247 + 29753 = 60000). Ugo 120000 − 30247 = 89753.
    expect(pciSaldiDi($vendita))->toBe(['Acquirente Elsa' => 30247, 'Venditore Ugo' => -30247])
        ->and(pciNetti($s))->toBe(['Acquirente Elsa' => 30247, 'Venditore Ugo' => 89753]);

    $p = pciProspetto($s);
    // Ivo, i 184 giorni dal 1/7: la parte unita, 120000 × 184/365 = 60493,15 → 60493. A Elsa la riga del pieno come la conta il
    // conguaglio, con le tabelle sommate: 30247 (non 30000 × 184/365 = 15123,29 → 15123 per tabella, due volte 30246); a Ugo
    // l'usufrutto, che resta suo, 60493 − 30247 = 30246 (non 30247). Somma: 30247 + 30246 = 60493.
    expect(pciPerPagatore(pciSezione($p, 'Ivo Ultimo')['voci'] ?? []))->toBe(['Acquirente Elsa' => 30247, 'Venditore Ugo' => 30246])
        // Senza conduttore, 1/1–30/6, prima della vendita, a Ugo: 120000 − 60493 = 59507 (120000 × 181/365 = 59506,85), giusto già oggi.
        ->and(pciPerPagatore($p['senza_conduttore']['voci']))->toBe(['Venditore Ugo' => 59507])
        ->and($p['totale_inquilino'])->toBe(120000);
});

// Rilievo «gemelle su due tabelle» (basso, lente denaro, giro 47c), scenario C: le due gemelle passate ad altri il 1/7, prima
// l'estinzione e poi la successione con l'arretrato a nome del defunto. NON copre: l'arretrato agli eredi, le gemelle con un tratto, la stampa.
it('rilievo gemelle su due tabelle, C — la stessa unità, il 1/7 Ugo muore: l\'usufrutto si estingue (Bice piena) e la metà piena va a Carla con l\'arretrato a nome del defunto; Ivo dal 1/7: Ivo rimborsa € 302,47 a Bice e € 302,47 a Carla, e a Ugo restano € 595,06', function () {
    $s = pciMistaSuDueTabelle();
    $estinzione = pciRegistra($this, $s, ruPassaggio('estinzione', pciRigaAperta($s, $s['v'], 'usufruttuario'), null, '2026-07-01', 50), 'Estinzione per morte di Ugo, letta');
    $successione = pciRegistra($this, $s, ruPassaggio('successione_defunto', $s['rigaV'], pciPersona($s, 'Carla Erede'), '2026-07-01', 50),
        'Successione di Ugo letta: la metà piena a Carla, l\'arretrato a nome del defunto');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Il riparto dello scenario A: quattro righe di Ugo da 30000. I conguagli, ciascuno sulla sua riga con le due tabelle sommate:
    // 60000 × 184/365 = 30246,58 → 30247, a Bice l'usufrutto (l'estinzione) e a Carla il pieno (la successione, che con l'arretrato
    // a nome del defunto scrive solo la coppia). Ugo 120000 − 30247 − 30247 = 59506. Somma: 30247 + 30247 + 59506 = 120000.
    expect(pciSaldiDi($estinzione))->toBe(['Bice Nuda' => 30247, 'Venditore Ugo' => -30247])
        ->and(pciSaldiDi($successione))->toBe(['Carla Erede' => 30247, 'Venditore Ugo' => -30247])
        ->and(pciNetti($s))->toBe(['Bice Nuda' => 30247, 'Carla Erede' => 30247, 'Venditore Ugo' => 59506]);

    $p = pciProspetto($s);
    // Ivo: Ugo non c'è più fra chi ha pagato, e restano le parti separate, una per riga con le tabelle sommate, quanto i due
    // conguagli: 30247 + 30247 = 60494 (non 30000 × 184/365 = 15123,29 → 15123 per riga e per tabella, 15123 + 15123 = 30246 a testa).
    expect(pciPerPagatore(pciSezione($p, 'Ivo Ultimo')['voci'] ?? []))->toBe(['Bice Nuda' => 30247, 'Carla Erede' => 30247])
        // Senza conduttore, 1/1–30/6: ciò che Ugo ha tenuto davvero, 120000 − 60494 = 59506 (le separate, 60000 × 181/365 = 29753,42 →
        // 29753, due volte), non 59508 (30000 × 181/365 = 14876,71 → 14877, quattro volte). Somma: 60494 + 59506 = 120000.
        ->and(pciPerPagatore($p['senza_conduttore']['voci']))->toBe(['Venditore Ugo' => 59506])
        ->and($p['totale_inquilino'])->toBe(120000);
});

// Rilievo «gemelle su due tabelle» (basso, lente denaro, giro 47c), scenario D: il già versato «dell'unità» pesato riga per riga (il
// ripiego tabella per tabella, più il «Proprietario»), non per gruppo come il conguaglio; una catena sola. NON copre: il già versato della
// persona, l'unità mista con il versato su due tabelle, un inquilino alla generazione, i giorni della riga «già versato».
it('rilievo gemelle su due tabelle, D — unità piena, la voce 70/30 su due tabelle e 30/70 fra i ruoli, € 120,05 versati dall\'unità, Ugo vende tutto a Elsa il 1/7, Ivo dal 1/7: Ivo rimborsa a Elsa € 163,32, la parte del ripiego del suo conguaglio, e il totale dell\'inquilino è € 323,98', function () {
    $s = pciScenario(['inquilino' => 30, 'proprietario' => 70]);
    pciSecondaTabella($s, 70, 30, ['inquilino' => 30, 'proprietario' => 70]);
    pciVersato($s, 12005);
    pciGenera($s);
    ruEmettiBozze($s, $s['piano'], '2026-06-30');
    pciRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-07-01', 100), 'Rogito letto: Ugo vende tutto a Elsa');
    pciInizio($this, $s, pciPersona($s, 'Ivo Ultimo'), '2026-07-01');

    // Il riparto: «Proprietà» 120000 × 70/100 = 84000, «Scale» × 30/100 = 36000; su ciascuna il 30 % «Inquilino», a Ugo per ripiego
    // (25200 e 10800), e il 70 % «Proprietario» (58800 e 25200). 25200 + 10800 + 58800 + 25200 = 120000. Il versato, tutto a Ugo.
    expect(pciRigheDelRiparto($s))->toBe([
        ['Proprietà', 'inquilino', 'proprietario', 25200],
        ['Proprietà', 'proprietario', 'proprietario', 58800],
        ['Scale', 'inquilino', 'proprietario', 10800],
        ['Scale', 'proprietario', 'proprietario', 25200],
    ])->and(pciVersatoNelRiparto($s))->toBe(['Venditore Ugo' => -12005]);
    // Il conguaglio somma le tabelle di ciascun gruppo: il ripiego 25200 + 10800 = 36000, il «Proprietario» 58800 + 25200 = 84000; il
    // versato su [36000, 84000]: 12005 × 36000/120000 = 3601,5 e × 84000/120000 = 8403,5; a parità di resto vince il primo: 3602 + 8403
    // = 12005. Elsa: (36000 − 3602) × 184/365 = 32398 × 184/365 = 16332,14 → 16332, e (84000 − 8403) × 184/365 = 75597 × 184/365 =
    // 38109,17 → 38109; 16332 + 38109 = 54441. Ugo 120000 − 12005 − 54441 = 53554. Somma: 54441 + 53554 = 107995 = 120000 − 12005.
    expect(pciNetti($s))->toBe(['Acquirente Elsa' => 54441, 'Venditore Ugo' => 53554]);

    $p = pciProspetto($s);
    $voci = collect(pciSezione($p, 'Ivo Ultimo')['voci'] ?? []);
    // Ivo, i 184 giorni dal 1/7, pagati da Elsa: il netto del ripiego come lo conta il conguaglio, 16332. Non 16333: il versato con un
    // peso per riga, R(12005; 25200, 10800, 84000) = 2521,05 / 1080,45 / 8403,5 → 2521 + 1080 + 8404, al ripiego 3601, netto 32399,
    // e 32399 × 184/365 = 16332,65 → 16333.
    expect(pciPerPagatore($voci->all()))->toBe(['Acquirente Elsa' => 16332])
        ->and(pciSezione($p, 'Ivo Ultimo')['totale'] ?? null)->toBe(16332)
        // La voce al lordo, 36000 × 184/365 = 18147,95 → 18148 (sulle due tabelle insieme), e la riga del già versato che la sconta:
        // 18148 − 16332 = 1816 (3602 × 184/365 = 1815,78 → 1816), non 1815.
        ->and((int) $voci->where('conto', 'Spese generali')->sum('importo'))->toBe(18148)
        ->and((int) $voci->where('conto', 'Spese generali — già versato')->sum('importo'))->toBe(-1816)
        // Senza conduttore, 1/1–30/6, a Ugo: 32398 − 16332 = 16066 (32398 × 181/365 = 16065,86), quanto oggi (32399 − 16333).
        ->and(pciPerPagatore($p['senza_conduttore']['voci']))->toBe(['Venditore Ugo' => 16066])
        // Il totale dell'inquilino è il ripiego netto del conguaglio, 36000 − 3602 = 32398 (16332 + 16066), non 32399.
        ->and($p['totale_inquilino'])->toBe(32398);
    // La stampa dichiara lo stesso totale, € 323,98, e non € 323,99.
    expect(pciStampa($s))->toContain('Totale delle voci che il riparto pone sul ruolo inquilino su questa unità nell\'esercizio: € 323,98')
        ->not->toContain('€ 323,99');
});
