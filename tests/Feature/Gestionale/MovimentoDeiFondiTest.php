<?php

/**
 * Il movimento dei fondi, nella situazione patrimoniale e nella sua stampa: per ogni cassa fondo l'iniziale, l'accantonato, l'utilizzato e il finale (le righe
 * delle entrate e uscite per cassa dell'esercizio), il vincolo dichiarato e lo scoperto (gli stessi del controllo dei vincoli). Nessun dato nuovo.
 *
 * Il caso, con le cifre fatte a mano. Il fondo «Facciata», vincolato ai lavori, nasce con € 10.000,00 di apertura (al 1° gennaio 2026); il 15/03 un giroconto
 * porta € 2.000,00 dalla banca al fondo; nessun utilizzo; € 10.000,00 sono dichiarati vincolati su quel fondo. Il movimento: 10.000,00 + 2.000,00 − 0,00 =
 * 12.000,00; vincolo 10.000,00; scoperto max(0; 10.000,00 − 12.000,00) = 0,00. Il caso che non è nessuna: il 30/06 € 2.000,00 tornano dal fondo alla banca per
 * pagare la facciata (con la deroga dell'assemblea): utilizzati 2.000,00, finale 10.000,00, scoperto 0,00, e la tabella non dice per quale opera.
 *
 * Il vincolo è scritto come lo lascia la pagina dei contributi versati per un fondo che ha già il denaro (la riga del contributo con la sua cassa): alla prima
 * dichiarazione la pagina scriverebbe anche l'accantonamento, che qui è l'apertura del fondo.
 */

use App\Actions\Cassa\RegistraAperturaCassaAction;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Beta52VoceEntrataScenari.php';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Il giorno dell'amministratore, alle 10 di Roma. */
function mdfGiorno($test, string $giorno): void
{
    $test->travelTo(\Illuminate\Support\Carbon::parse($giorno.' 10:00', 'Europe/Rome'));
}

/** Un fondo dalla pagina delle casse, con la sua apertura in euro («10.000,00»); `$altro`: i campi in più (la deroga dell'assemblea). */
function mdfFondo($test, array $s, string $nome, string $apertura, string $sottotipo = 'vincolato_lavori', array $altro = []): Cassa
{
    $test->actingAs($test->user)->post(route('admin.gestionale.casse.store', $s['c']), array_merge([
        'nome' => $nome, 'tipo' => 'fondo', 'sottotipo_fondo' => $sottotipo, 'saldo_iniziale' => $apertura, 'vincolo_descrizione' => 'Lavori deliberati',
    ], $altro))->assertSessionHasNoErrors();

    return Cassa::where('condominio_id', $s['c']->id)->where('nome', $nome)->firstOrFail();
}

/** Un giroconto dalla pagina dei giroconti, in euro. */
function mdfGiroconto($test, array $s, Cassa $da, Cassa $a, float $euro, string $data, ?int $esercizioId = null): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.giroconti.store', $s['c']), [
        'cassa_origine_id' => $da->id, 'cassa_destinazione_id' => $a->id, 'importo' => $euro, 'data_operazione' => $data,
        'causale' => 'Giroconto del '.$data, 'gestione_id' => $s['g']->id, 'esercizio_id' => $esercizioId ?? $s['e']->id,
    ])->assertSessionHasNoErrors();
}

/** Il vincolo dichiarato su un fondo, in centesimi: la riga del contributo versato con la sua cassa. */
function mdfVincolo(array $s, Cassa $fondo, int $centesimi): void
{
    DB::table('contributi_versati')->insert([
        'condominio_id' => $s['c']->id, 'target_type' => 'App\\Models\\Gestionale\\Conto', 'target_id' => 1, 'immobile_id' => $s['unita']['A']->id,
        'esercizio_id' => $s['e']->id, 'importo_cents' => $centesimi, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione',
        'liquidita_stato' => 'registrata_in_cassa', 'cassa_id' => $fondo->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Il condominio del caso: la banca con € 10.000,00 di apertura a giornale, il fondo «Facciata» con i suoi € 10.000,00 vincolati, il giroconto del 15/03. */
function mdfFacciata($test): array
{
    mdfGiorno($test, '2026-01-02');
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    app(RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $s['facciata'] = mdfFondo($test, $s, 'Facciata', '10.000,00');
    mdfVincolo($s, $s['facciata'], 1000000);
    mdfGiorno($test, '2026-03-15');
    mdfGiroconto($test, $s, $s['cassa'], $s['facciata'], 2000.00, '2026-03-15');

    return $s;
}

/** La pagina della situazione patrimoniale, letta dal servizio, a un giorno. */
function mdfPagina(array $s, string $oggi): array
{
    return app(StatoPatrimonialePaginaService::class)->costruisci($s['c']->fresh(), $s['e']->fresh(), $oggi);
}

/** Le colonne di una riga del movimento, per leggerle in un colpo. */
function mdfColonne(array $riga): array
{
    return [$riga['iniziale'], $riga['accantonato'], $riga['utilizzato'], $riga['finale'], $riga['vincolo'], $riga['scoperto']];
}

it('il fondo «Facciata»: € 10.000,00 al 1° gennaio, € 2.000,00 accantonati, niente utilizzato → € 12.000,00; vincolo € 10.000,00, scoperto zero', function () {
    $s = mdfFacciata($this);
    $p = mdfPagina($s, '2026-09-12');
    $m = $p['movimento_fondi'];

    // Una riga sola: la banca non è un fondo.
    expect($m['righe'])->toHaveCount(1)
        ->and($m['righe'][0]['cassa'])->toBe('Facciata')
        ->and(mdfColonne($m['righe'][0]))->toBe([1000000, 200000, 0, 1200000, 1000000, 0])
        ->and($m['righe'][0]['saldo_alla_data'])->toBe(1200000)
        ->and($m['totale'])->toBe(['iniziale' => 1000000, 'accantonato' => 200000, 'utilizzato' => 0, 'finale' => 1200000, 'vincolo' => 1000000, 'scoperto' => 0])
        ->and($m['nota'])->toBe(StatoPatrimonialePaginaService::FRASE_MOVIMENTO_FONDI)
        ->and($m['nota_vincoli'])->toBeNull()
        ->and($m['nota_saldo'])->toBeNull();
});

it('una sola fonte: le quattro colonne sono la riga del fondo nelle entrate e uscite per cassa, vincolo e scoperto quelli del controllo dei vincoli', function () {
    $s = mdfFacciata($this);
    $p = mdfPagina($s, '2026-09-12');
    $riga = $p['movimento_fondi']['righe'][0];
    $cassa = collect($p['riepilogo']['righe'])->firstWhere('cassa_id', $s['facciata']->id);
    $fondo = collect($p['liquidita']['fondi'])->firstWhere('cassa_id', $s['facciata']->id);

    expect([$riga['iniziale'], $riga['accantonato'], $riga['utilizzato'], $riga['finale']])->toBe([$cassa['iniziale'], $cassa['entrate'], $cassa['uscite'], $cassa['finale']])
        ->and([$riga['vincolo'], $riga['scoperto'], $riga['saldo_alla_data']])->toBe([$fondo['vincolo_registrato'], $fondo['scoperto'], $fondo['saldo']])
        ->and($riga['conto_contabile_id'])->toBe($s['facciata']->conto_contabile_id)
        // Il totale del vincolo e dello scoperto è quello del controllo.
        ->and($p['movimento_fondi']['totale']['vincolo'])->toBe($p['liquidita']['vincolo_su_fondi'])
        ->and($p['movimento_fondi']['totale']['scoperto'])->toBe($p['liquidita']['scoperto_fondi'])
        ->and($p['movimento_fondi']['totale']['finale'])->toBe($p['liquidita']['in_fondi']);
});

it('il caso che non è nessuna: € 2.000,00 tornati dal fondo alla banca per pagare la facciata sono «utilizzati», e la tabella non dice per quale opera', function () {
    mdfGiorno($this, '2026-01-02');
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    app(RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    // Il fondo vincolato si usa fuori dalla sua destinazione solo con la deroga dell'assemblea.
    $s['facciata'] = mdfFondo($this, $s, 'Facciata', '10.000,00', 'vincolato_lavori', ['is_override_assemblea' => true, 'motivazione_override' => 'Delibera del 10/06/2026: pagamento della facciata']);
    mdfVincolo($s, $s['facciata'], 1000000);
    mdfGiorno($this, '2026-03-15');
    mdfGiroconto($this, $s, $s['cassa'], $s['facciata'], 2000.00, '2026-03-15');
    mdfGiorno($this, '2026-06-30');
    mdfGiroconto($this, $s, $s['facciata'], $s['cassa'], 2000.00, '2026-06-30');

    $m = mdfPagina($s, '2026-09-12')['movimento_fondi'];

    // 10.000,00 + 2.000,00 − 2.000,00 = 10.000,00; vincolo 10.000,00; scoperto max(0; 10.000,00 − 10.000,00) = 0,00.
    expect(mdfColonne($m['righe'][0]))->toBe([1000000, 200000, 200000, 1000000, 1000000, 0])
        ->and($m['nota'])->toContain('utilizzato quanto ne è uscito, senza dire per quale opera')
        ->toContain('Il residuo vincolato di un\'opera si legge con l\'importo votato dall\'assemblea, che questo prospetto non riporta.');
});

it('un fondo scoperto e uno senza vincolo: lo scoperto è quello del controllo dei vincoli, fondo per fondo, e la riga in fondo somma le righe', function () {
    $s = mdfFacciata($this);
    // Altri € 5.000,00 dichiarati sullo stesso fondo: vincolo 15.000,00 contro 12.000,00, scoperto 3.000,00.
    mdfVincolo($s, $s['facciata'], 500000);
    $s['riserva'] = mdfFondo($this, $s, 'Riserva', '500,00', 'generico');

    $p = mdfPagina($s, '2026-09-12');
    $m = $p['movimento_fondi'];
    $per = collect($m['righe'])->keyBy('cassa');

    expect(mdfColonne($per['Facciata']))->toBe([1000000, 200000, 0, 1200000, 1500000, 300000])
        ->and(mdfColonne($per['Riserva']))->toBe([50000, 0, 0, 50000, 0, 0])
        ->and($m['totale'])->toBe(['iniziale' => 1050000, 'accantonato' => 200000, 'utilizzato' => 0, 'finale' => 1250000, 'vincolo' => 1500000, 'scoperto' => 300000])
        // Il controllo dei vincoli dice gli stessi € 3.000,00.
        ->and($p['liquidita']['scoperto_fondi'])->toBe(300000)
        ->and(collect($p['controlli'])->firstWhere('id', 'R8')['esito'])->toBe('non_quadra');
});

it('un esercizio finito: il movimento resta; il vincolo è quello di oggi, lo scoperto non si misura, come nel controllo, e la nota lo dice', function () {
    $s = mdfFacciata($this);
    mdfVincolo($s, $s['facciata'], 500000);

    $p = mdfPagina($s, '2027-01-15');
    $m = $p['movimento_fondi'];

    expect($p['stato_esercizio'])->toBe('chiuso')
        ->and(mdfColonne($m['righe'][0]))->toBe([1000000, 200000, 0, 1200000, 1500000, null])
        ->and($m['totale']['scoperto'])->toBeNull()
        ->and($m['nota_vincoli'])->toBe(StatoPatrimonialePaginaService::FRASE_FONDI_VINCOLI_A_OGGI)
        ->and(collect($p['controlli'])->firstWhere('id', 'R8')['esito'])->toBe('segnalazione');
});

it('un fondo con l\'apertura non ancora a giornale: il finale la conta, il saldo alla data no, e la nota nomina il fondo e il suo saldo', function () {
    $s = mdfFacciata($this);
    // Una cassa fondo con il saldo di apertura in colonna, come una nata prima che l'apertura andasse a giornale.
    $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'ruolo' => 'fondo_riserva', 'codice' => '1010.99', 'nome' => 'Fondo ascensore',
        'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);
    Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $conto, 'nome' => 'Ascensore', 'tipo' => 'fondo', 'sottotipo_fondo' => 'vincolato_lavori',
        'saldo_iniziale' => 300000, 'attiva' => true]);

    $m = mdfPagina($s, '2026-09-12')['movimento_fondi'];
    $per = collect($m['righe'])->keyBy('cassa');

    expect(mdfColonne($per['Ascensore']))->toBe([300000, 0, 0, 300000, 0, 0])
        ->and($per['Ascensore']['saldo_alla_data'])->toBe(0)
        ->and($m['nota_saldo'])->toBe('Il saldo al 12/09/2026 è diverso dal finale per «Ascensore» (€ 0,00): il finale conta le scritture dell\'esercizio e il saldo '
            .'di apertura non ancora registrato a giornale, il saldo alla data le registrazioni a giornale fino a quel giorno.');
});

it('senza fondi il movimento dei fondi non c\'è: né righe nella pagina, né la parte nella stampa', function () {
    mdfGiorno($this, '2026-01-02');
    $s = be52Condominio();
    app(RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $p = mdfPagina($s, '2026-09-12');

    $html = view('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'pagina' => $p, 'parte' => 'situazione'])->render();

    expect($p['movimento_fondi']['righe'])->toBe([])
        ->and($html)->not->toContain('Movimento dei fondi');
});

it('la stampa della situazione ha il movimento dei fondi dopo la liquidità, con le cifre e le note della pagina', function () {
    $s = mdfFacciata($this);
    $p = mdfPagina($s, '2026-09-12');
    $html = view('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'pagina' => $p, 'parte' => 'situazione'])->render();
    $testo = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#</?(td|th|tr|p|div|br|h1|h2|table)\b[^>]*>#i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    expect($testo)->toContain('§ 4.1 Movimento dei fondi dal 01/01/2026 al 12/09/2026')
        ->toContain('Fondo Iniziale Accantonato Utilizzato Finale Vincolo Scoperto')
        ->toContain('Facciata € 10.000,00 € 2.000,00 € 0,00 € 12.000,00 € 10.000,00 € 0,00')
        ->toContain('Fondi € 10.000,00 € 2.000,00 € 0,00 € 12.000,00 € 10.000,00 € 0,00')
        ->toContain(StatoPatrimonialePaginaService::FRASE_MOVIMENTO_FONDI)
        // Dopo la liquidità, nella parte della situazione.
        ->and(strpos($testo, 'Movimento dei fondi'))->toBeGreaterThan(strpos($testo, 'Liquidità: libera e accantonata'));
});

it('la stampa di un esercizio finito scrive «—» dove lo scoperto non si misura, e la nota del vincolo di oggi', function () {
    $s = mdfFacciata($this);
    $p = mdfPagina($s, '2027-01-15');
    $html = view('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'pagina' => $p, 'parte' => 'situazione'])->render();
    $testo = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#</?(td|th|tr|p|div|br|h1|h2|table)\b[^>]*>#i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    expect($testo)->toContain('€ 10.000,00 € 2.000,00 € 0,00 € 12.000,00 € 10.000,00 —')
        ->toContain(StatoPatrimonialePaginaService::FRASE_FONDI_VINCOLI_A_OGGI);
});

it('la liquidità di un esercizio finito non dice «mancano»: il vincolo assegnato resta, il confronto no, come nel movimento e nel controllo', function () {
    $s = mdfFacciata($this);
    mdfVincolo($s, $s['facciata'], 500000);
    $testo = function (string $oggi) use ($s): string {
        $html = view('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'pagina' => mdfPagina($s, $oggi), 'parte' => 'situazione'])->render();

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    };

    // Aperto: vincolo 15.000,00 contro 12.000,00, mancano 3.000,00. Finito: il vincolo è quello di oggi, e il confronto non si fa.
    expect($testo('2026-09-12'))->toContain('vincolo assegnato € 15.000,00 — mancano € 3.000,00')
        ->and($testo('2027-01-15'))->toContain('vincolo assegnato € 15.000,00')->not->toContain('mancano');
});

it('la pagina e la stampa dalla loro rotta: la pagina riceve il movimento, il PDF lo ha', function () {
    $s = mdfFacciata($this);
    mdfGiorno($this, '2026-09-12');

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    expect(mdfColonne($props['pagina']['movimento_fondi']['righe'][0]))->toBe([1000000, 200000, 0, 1200000, 1000000, 0]);

    $risposta = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).'?parte=situazione')->assertOk();
    expect($risposta->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(strlen((string) $risposta->getContent()))->toBeGreaterThan(1000);
});

it('la nota dei vincoli di un esercizio finito dice il movimento accanto, e perché il residuo vincolato di un\'opera non c\'è', function () {
    expect(StatoPatrimonialePaginaService::NOTA_VINCOLI_A_OGGI)
        ->toContain('Il movimento dei fondi dice per ogni fondo l\'iniziale, l\'accantonato e l\'utilizzato dell\'esercizio')
        ->toContain('il residuo vincolato di un\'opera si legge anche con l\'importo votato dall\'assemblea, che questo prospetto non riporta.');
});

/**
 * Dieci fondi con i nomi lunghi e gli importi a sei cifre, tre sotto zero: le aperture dalla pagina delle casse, i movimenti scritti a giornale fra il fondo e
 * la banca (un fondo che va sotto zero non passa da un giroconto, che lo ferma), i vincoli su cinque fondi.
 */
function mdfDieciFondi($test): array
{
    mdfGiorno($test, '2026-01-02');
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    $s['cassa']->update(['saldo_iniziale' => 99999900]);
    app(RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $nomi = [
        'Fondo straordinario per il rifacimento completo della facciata lato giardino e dei balconi', 'Fondo di riserva ordinario (art. 1135 c.c.)',
        'Ascensore scala B: sostituzione della cabina e dell\'argano deliberata il 12/05/2025', 'Fondo TFR del portiere', 'Fondo morosità',
        'Impermeabilizzazione del lastrico solare e delle terrazze condominiali', 'Rifacimento dell\'impianto di riscaldamento centralizzato con caldaia a condensazione',
        'Fondo cortile', 'Manutenzione straordinaria del cancello carraio e del sistema di videosorveglianza', 'Fondo spese legali per il contenzioso con l\'impresa costruttrice',
    ];
    $sottotipi = ['vincolato_lavori', 'generico', 'vincolato_lavori', 'tfr', 'morosita', 'vincolato_lavori', 'vincolato_lavori', 'generico', 'vincolato_lavori', 'vincolato_lavori'];
    $aperture = ['123.456,78', '987.654,32', '500.000,00', '12.345,67', '0,00', '456.789,01', '888.888,88', '100,00', '333.333,33', '999.999,99'];
    $fondi = [];
    foreach ($nomi as $i => $nome) {
        $fondi[$i] = mdfFondo($test, $s, $nome, $aperture[$i], $sottotipi[$i]);
    }
    $scrivi = function (int $dare, int $avere, int $importo, string $data) use ($s): void {
        $sc = ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'data_registrazione' => $data,
            'data_competenza' => $data, 'causale' => 'Movimento del fondo', 'tipo_movimento' => 'regolazione_immediata', 'stato' => 'registrata']);
        RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $dare, 'tipo_riga' => 'dare', 'importo' => $importo]);
        RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $avere, 'tipo_riga' => 'avere', 'importo' => $importo]);
    };
    $banca = (int) $s['cassa']->conto_contabile_id;
    foreach ([0 => 23456789, 2 => 76543210, 5 => 12121212, 8 => 99887766] as $i => $importo) {
        $scrivi((int) $fondi[$i]->conto_contabile_id, $banca, $importo, '2026-03-15');
    }
    foreach ([1 => 111111032, 4 => 45678900, 6 => 90000000, 9 => 19999999] as $i => $importo) {
        $scrivi($banca, (int) $fondi[$i]->conto_contabile_id, $importo, '2026-06-30');
    }
    foreach ([0 => 99999999, 2 => 12345678, 3 => 5000000, 5 => 90000000, 9 => 88888888] as $i => $importo) {
        mdfVincolo($s, $fondi[$i], $importo);
    }

    return $s;
}

/** Le parole di un PDF lette da pdftotext, con il riquadro in punti: [testo, xMin, yMin, xMax, yMax, pagina, larghezza della pagina]. */
function mdfParole(string $pdf): array
{
    $file = tempnam(sys_get_temp_dir(), 'mdf');
    file_put_contents($file, $pdf);
    $xml = (string) shell_exec('pdftotext -bbox '.escapeshellarg($file).' - 2>/dev/null');
    unlink($file);
    $parole = [];
    preg_match_all('#<page width="([\d.]+)" height="[\d.]+">(.*?)</page>#s', $xml, $pagine, PREG_SET_ORDER);
    foreach ($pagine as $n => [, $larghezza, $corpo]) {
        preg_match_all('#<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*?)</word>#', $corpo, $w, PREG_SET_ORDER);
        foreach ($w as [, $x0, $y0, $x1, $y1, $t]) {
            $parole[] = [html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), (float) $x0, (float) $y0, (float) $x1, (float) $y1, $n + 1, (float) $larghezza];
        }
    }

    return $parole;
}

it('dieci fondi con i nomi lunghi e gli importi a sei cifre col meno: la tabella sta nel foglio, ogni cifra intera, i nomi tutti', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questo computer.');
    }
    $s = mdfDieciFondi($this);
    mdfGiorno($this, '2026-09-12');
    $m = mdfPagina($s, '2026-09-12')['movimento_fondi'];
    $pdf = (string) $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]).'?parte=situazione')->assertOk()->getContent();
    $parole = mdfParole($pdf);
    $testo = implode(' ', array_column($parole, 0));

    // Tre fondi sotto zero, e il movimento ha le dieci righe.
    expect($m['righe'])->toHaveCount(10)
        ->and(collect($m['righe'])->filter(fn ($r) => $r['finale'] < 0)->count())->toBe(3);
    // Ogni cifra della tabella è nel PDF intera, «€» e il numero, col meno dove c'è.
    $fmt = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
    foreach ([...$m['righe'], $m['totale']] as $r) {
        foreach (['iniziale', 'accantonato', 'utilizzato', 'finale', 'vincolo', 'scoperto'] as $colonna) {
            expect($testo)->toContain($fmt($r[$colonna]));
        }
    }
    // Ogni parola dei nomi c'è: un nome lungo va a capo, non si taglia.
    foreach ($m['righe'] as $r) {
        expect(array_diff(preg_split('/\s+/u', $r['cassa']), array_column($parole, 0)))->toBe([]);
    }
    // Nessuna parola esce dal margine destro delle stampe (12 mm, quello di PdfService).
    $fuori = array_filter($parole, fn ($p) => $p[3] > $p[6] - 12 / 25.4 * 72 + 0.5);
    expect(array_values(array_map(fn ($p) => $p[0].' a pagina '.$p[5], $fuori)))->toBe([]);
    // E la tabella non si stringe per starci: mPDF rimpicciolisce una tabella troppo larga, e le cifre del movimento sarebbero più basse di quelle della
    // liquidità, scritte con lo stesso carattere e la stessa misura.
    $inizio = array_key_first(array_filter($parole, fn ($p, $i) => $p[0] === 'Movimento' && ($parole[$i + 1][0] ?? '') === 'dei' && ($parole[$i + 2][0] ?? '') === 'fondi', ARRAY_FILTER_USE_BOTH));
    $cifra = fn ($p) => preg_match('/^-?\d{1,3}(\.\d{3})*,\d{2}$/', $p[0]) === 1;
    $altezze = fn (array $ps) => array_map(fn ($p) => $p[4] - $p[2], array_values(array_filter($ps, $cifra)));
    $prima = $altezze(array_slice($parole, 0, $inizio));
    sort($prima);
    expect($inizio)->not->toBeNull()
        ->and(min($altezze(array_slice($parole, $inizio))))->toBeGreaterThan(0.95 * $prima[intdiv(count($prima), 2)]);
});

it('un giroconto fra due fondi sta nelle sue colonne: accantonato e utilizzato sono il denaro entrato nei fondi da fuori e uscito verso fuori', function () {
    mdfGiorno($this, '2026-01-02');
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    app(RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $riserva = mdfFondo($this, $s, 'Riserva', '10.000,00', 'generico');
    mdfGiorno($this, '2026-05-02');
    $ascensore = mdfFondo($this, $s, 'Ascensore', '0,00', 'vincolato_lavori', ['is_override_assemblea' => true, 'motivazione_override' => 'Delibera']);
    mdfGiorno($this, '2026-05-10');
    mdfGiroconto($this, $s, $riserva, $ascensore, 3000.00, '2026-05-10');
    mdfGiorno($this, '2026-06-30');
    mdfGiroconto($this, $s, $ascensore, $s['cassa'], 1000.00, '2026-06-30');
    $p = mdfPagina($s, '2026-09-12');
    $m = $p['movimento_fondi'];
    $riga = fn (string $nome) => collect($m['righe'])->firstWhere('cassa', $nome);
    $colonne = fn (array $r) => [$r['iniziale'], $r['accantonato'], $r['giroconti_entrata'], $r['giroconti_uscita'], $r['utilizzato'], $r['finale']];

    // Riserva: 10.000,00, 3.000,00 dati all'Ascensore, 7.000,00. Ascensore: 3.000,00 ricevuti dalla Riserva, 1.000,00 utilizzati (alla banca), 2.000,00.
    // I fondi: niente accantonato da fuori, 1.000,00 utilizzati; i giroconti 3.000,00 in entrata e 3.000,00 in uscita. Prima: accantonati 3.000,00, utilizzati 4.000,00.
    expect($colonne($riga('Riserva')))->toBe([1000000, 0, 0, 300000, 0, 700000])
        ->and($colonne($riga('Ascensore')))->toBe([0, 0, 300000, 0, 100000, 200000])
        ->and($colonne($m['totale']))->toBe([1000000, 0, 300000, 300000, 100000, 900000])
        ->and($m['con_giroconti'])->toBeTrue()
        ->and($m['nota_giroconti'])->toBe('I giroconti da un fondo a un altro, € 3.000,00, stanno nelle loro colonne: non sono denaro entrato nei fondi né speso.');
    // La stampa ha le due colonne e la nota.
    $html = view('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'pagina' => $p, 'parte' => 'situazione'])->render();
    $testo = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#</?(td|th|tr|p|div|br|h1|h2|table)\b[^>]*>#i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    expect($testo)->toContain('Giroconti in entrata')->toContain('Giroconti in uscita')->toContain($m['nota_giroconti'])
        ->toContain('Fondi € 10.000,00 € 0,00 € 3.000,00 € 3.000,00 € 1.000,00 € 9.000,00');
    // Senza giroconti fra due fondi niente colonne, niente nota.
    $senza = mdfPagina(mdfFacciata($this), '2026-09-12')['movimento_fondi'];
    expect($senza['nota_giroconti'])->toBeNull()->and($senza['con_giroconti'])->toBeFalse();
});

it('la stampa della situazione parla a chi la riceve: il fondo detto una volta, nessun esito di controllo accanto all\'accantonato, niente «il programma» nelle frasi dei fondi', function () {
    $s = mdfFacciata($this);
    $p = mdfPagina($s, '2026-09-12');
    $html = view('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'pagina' => $p, 'parte' => 'situazione'])->render();
    $testo = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#</?(td|th|tr|p|div|br|h1|h2|table)\b[^>]*>#i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    // «Facciata · vincolato ai lavori», non «vincolato ai lavori, vincolato»; la riga «Accantonato nelle casse fondo» senza «torna» accanto (lo stato è nella
    // frase sotto la tabella); il residuo vincolato di un'opera detto senza «il programma».
    expect($testo)->toContain('Facciata · vincolato ai lavori')->not->toContain('vincolato ai lavori, vincolato')
        ->and($html)->not->toMatch('/Accantonato nelle casse fondo\s*<span class="badge/')
        ->and($testo)->toContain('Accantonato nelle casse fondo € '.number_format($p['liquidita']['in_fondi'] / 100, 2, ',', '.'))
        ->and($testo)->not->toContain('che il programma non ha')
        ->toContain('Il residuo vincolato di un\'opera si legge con l\'importo votato dall\'assemblea, che questo prospetto non riporta.');
});

it('la stampa della situazione ha uno spazio fra il numero della sezione e il titolo', function () {
    $s = mdfFacciata($this);
    $html = view('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'pagina' => mdfPagina($s, '2026-09-12'), 'parte' => 'situazione'])->render();
    $testo = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    expect($testo)->toContain('§ 1 Situazione patrimoniale')->toContain('§ 4.1 Movimento dei fondi')->not->toMatch('/§ [0-9.]+[A-Z]/u');
});
