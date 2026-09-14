<?php

use App\Models\User;
use App\Services\RipartoTabelleService;
use Database\Seeders\CondominioStampeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Le stampe del riparto su un condominio con la forma di uno vero (1.11.0-beta.27).
 *
 * Il condominio è `CondominioStampeSeeder`: 38 unità, 48 righe, sei tabelle, € 18.350,00 — la forma
 * (non i dati, che sono costruiti) della stampa che un amministratore ha messo a confronto con
 * quella del suo programma precedente, su carta. La sua era un A4 orizzontale a una pagina; la
 * nostra usciva in A3 e, ristampata su A4, era illeggibile. Qui si tengono fermi il formato, le
 * pagine e i numeri.
 *
 * ⚠️ Le misure sul PDF si leggono nel PDF stesso, non nelle variabili del template: `/MediaBox` dice
 * il foglio, gli oggetti `/Type /Page` dicono quante pagine. mPDF comprime i flussi ma non i
 * dizionari delle pagine, quindi le due espressioni regolari reggono.
 */
function stampeAdmin(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

/** @return array{pagine: int, larghezza_mm: float, altezza_mm: float} */
function misuraPdf(string $pdf): array
{
    preg_match_all('#/Type\s*/Page(?!s)#', $pdf, $pagine);
    if (! preg_match('#/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $box)) {
        throw new RuntimeException('Nessun /MediaBox nel PDF: primi byte «'.substr($pdf, 0, 60).'», lunghezza '.strlen($pdf));
    }

    return [
        'pagine'       => count($pagine[0]),
        'larghezza_mm' => round(((float) $box[1]) / 72 * 25.4, 1),
        'altezza_mm'   => round(((float) $box[2]) / 72 * 25.4, 1),
    ];
}

function condominioDelleStampe(): array
{
    $seeder = new CondominioStampeSeeder();
    $seeder->run();

    return [$seeder->condominio(), $seeder->pianoRate()];
}

test('il condominio di prova ha la forma di quello vero: 38 unità, 48 righe, sei tabelle, € 18.350,00', function () {
    [$condominio, $piano] = condominioDelleStampe();

    $matrice = app(RipartoTabelleService::class)->buildMatrice($piano);

    $righe = 0;
    foreach ($matrice['righe'] as $riga) {
        $righe += count($riga['soggetti']);
    }

    expect($condominio->immobili()->count())->toBe(38)
        ->and(count($matrice['righe']))->toBe(38)
        ->and($righe)->toBe(48)
        ->and(count($matrice['tabelle']))->toBe(6)
        // A mano: 2.350 + 1.700 + 800 + 6.150 + 450 + 6.900 = 18.350, in centesimi.
        ->and($matrice['gran_totale'])->toBe(1835000);
});

test('la riga di ogni soggetto vale esattamente le sue quote, anche su 48 righe e sei tabelle', function () {
    [, $piano] = condominioDelleStampe();

    $matrice = app(RipartoTabelleService::class)->buildMatrice($piano);

    $quote = \App\Models\Gestionale\RataQuote::query()
        ->whereIn('rata_id', $piano->rate()->pluck('id'))
        ->get()
        ->groupBy(fn ($q) => $q->anagrafica_id.'|'.$q->immobile_id)
        ->map(fn ($g) => (int) round($g->sum('importo')));

    foreach ($matrice['righe'] as $immobileId => $riga) {
        foreach ($riga['soggetti'] as $anagraficaId => $soggetto) {
            $celle = array_sum(array_map(fn ($c) => (int) ($c['importo'] ?? 0), $soggetto['per_tabella'] ?? []));
            expect($soggetto['totale'])->toBe($quote[$anagraficaId.'|'.$immobileId])
                ->and($celle)->toBe($soggetto['totale']);
        }
    }
});

test('il riparto per tabella con sei tabelle esce in A4 orizzontale, non in A3, e sta in due pagine al massimo', function () {
    [$condominio, $piano] = condominioDelleStampe();
    $esercizio = $condominio->esercizi()->firstOrFail();

    $risposta = $this->actingAs(stampeAdmin())
        ->get("/admin/gestionale/{$condominio->id}/esercizi/{$esercizio->id}/piani-rate/{$piano->id}/print-riparto-tabelle");

    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($risposta->headers->get('Content-Disposition'))
        ->toBe('inline; filename="riparto-tabelle-condominio-prova-stampe-'.$esercizio->data_inizio->format('Y').'.pdf"');
    $misura = misuraPdf($risposta->getContent());

    // A4 orizzontale: 297 × 210 mm. Il suo programma ci mette 48 righe in una pagina sola.
    expect($misura['larghezza_mm'])->toBe(297.0)
        ->and($misura['altezza_mm'])->toBe(210.0)
        ->and($misura['pagine'])->toBeLessThanOrEqual(2);
});

test('il riparto per capitolo esce in A4 orizzontale anche con più di cinque capitoli', function () {
    [$condominio, $piano] = condominioDelleStampe();
    $esercizio = $condominio->esercizi()->firstOrFail();

    $risposta = $this->actingAs(stampeAdmin())
        ->get("/admin/gestionale/{$condominio->id}/esercizi/{$esercizio->id}/piani-rate/{$piano->id}/print-riparto-capitoli");

    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($risposta->headers->get('Content-Disposition'))
        ->toBe('inline; filename="riparto-capitoli-condominio-prova-stampe-'.$esercizio->data_inizio->format('Y').'.pdf"');
    $misura = misuraPdf($risposta->getContent());

    // Cinque capitoli radice più «Fuori riparto»: un blocco solo da sei colonne, con le quote della
    // prima tabella di ognuno («—» per l'acqua, che ha due voci su due tabelle).
    expect($misura['larghezza_mm'])->toBe(297.0)
        ->and($misura['altezza_mm'])->toBe(210.0)
        ->and($misura['pagine'])->toBeLessThanOrEqual(2);
});

/**
 * Una firma vera (PNG 300 × 100) sul disco finto, come la configura l'amministratore: senza, il
 * blocco della firma non gira mai in suite (`PrintSettings::$firma_stampe_path` è null).
 */
function configuraFirmaPerLeStampe(): void
{
    \Illuminate\Support\Facades\Storage::fake('public');
    $img = imagecreatetruecolor(300, 100);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imageline($img, 10, 80, 290, 20, imagecolorallocate($img, 30, 58, 95));
    ob_start();
    imagepng($img);
    \Illuminate\Support\Facades\Storage::disk('public')->put('firme/prova.png', ob_get_clean());
    $settings = app(\App\Settings\PrintSettings::class);
    $settings->firma_stampe_path = 'firme/prova.png';
    $settings->save();
}

test('con la firma configurata le due stampe restano in due pagine, con il blocco compatto e il piede compatto', function () {
    // Le due pagine «con firma» della prima verifica esistevano solo perché il piè di pagina
    // standard (10,6 mm) invadeva il contenuto: con un piede che stesse nel margine la firma
    // finiva da sola in terza pagina. Qui il piede è quello compatto, e le pagine devono restare due.
    configuraFirmaPerLeStampe();
    [$condominio, $piano] = condominioDelleStampe();
    $esercizio = $condominio->esercizi()->firstOrFail();

    foreach (['tabelle', 'capitoli'] as $tipo) {
        $risposta = $this->actingAs(stampeAdmin())
            ->get("/admin/gestionale/{$condominio->id}/esercizi/{$esercizio->id}/piani-rate/{$piano->id}/print-riparto-{$tipo}");
        $risposta->assertOk();
        expect(misuraPdf($risposta->getContent())['pagine'])->toBeLessThanOrEqual(2);
    }
});

test('il blocco compatto della firma e il piede compatto escono solo dove sono chiesti', function () {
    configuraFirmaPerLeStampe();
    $percorso = \Illuminate\Support\Facades\Storage::disk('public')->path('firme/prova.png');
    $matrice = matriceSintetica(2, 'tabelle', 'per_tabella');
    $base = ['condominio' => new \App\Models\Condominio(['nome' => 'Condominio di prova', 'indirizzo' => 'Via di prova 1', 'codice_fiscale' => '91000000009']),
        'esercizio' => new \App\Models\Esercizio(['nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']),
        'pianoRate' => new \App\Models\Gestionale\PianoRate(['nome' => 'Piano rate 2026', 'stato' => 'approvato']),
        'matrice' => $matrice, 'nTabelle' => 2, 'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => $percorso];

    $compatta = view('pdf.gestionale.riparto_tabelle', $base + ['firma_compatta' => true, 'piede_compatto' => true])->render();
    $standard = view('pdf.gestionale.riparto_tabelle', $base)->render();

    expect($compatta)->toContain('max-height: 42px')->not->toContain('margin-top: 40px')
        ->and(substr_count($compatta, 'font-size: 7pt; line-height: 1.15;'))->toBe(2) // tabella principale + piede compatto
        ->and($standard)->toContain('margin-top: 40px')->not->toContain('max-height: 42px')
        ->and(substr_count($standard, 'font-size: 7pt; line-height: 1.15;'))->toBe(1);
});

test('l\'altezza delle righe non dipende dalle cifre del totale unità: cinque cifre e quattro cifre fanno le stesse pagine', function (string $vista, string $chiaveColonne, string $chiaveCella, string $chiaveConteggio) {
    // Al 6,5% la colonna del totale unità teneva «€ 9.500,00» ma non «€ 12.255,00» in grassetto:
    // mPDF spezzava «€» / «12.255,00» su due righe, ogni riga cresceva di un millimetro e quaranta
    // unità passavano da due a tre pagine — nei piani straordinari i totali a cinque cifre sono la
    // norma. Una regex sul PDF non è praticabile (flussi compressi, glifi subsettati): si confronta
    // il numero di pagine di due matrici uguali in tutto tranne le cifre.
    $matricePer = function (int $importoCella) use ($chiaveColonne, $chiaveCella): array {
        $m = matriceSintetica(5, $chiaveColonne, $chiaveCella);
        $righe = [];
        for ($i = 1; $i <= 40; $i++) {
            $righe[$i] = $m['righe'][1];
            $righe[$i]['nome_immobile'] = 'Interno '.$i;
            $righe[$i]['interno'] = (string) $i;
            $righe[$i]['soggetti'] = [10 + $i => $m['righe'][1]['soggetti'][11]];
            foreach ($righe[$i]['soggetti'][10 + $i][$chiaveCella] as &$cella) {
                $cella['importo'] = $importoCella;
            }
            unset($cella);
            $righe[$i]['soggetti'][10 + $i]['totale'] = $importoCella * 5;
            $righe[$i]['totale_immobile'] = $importoCella * 5;
        }
        $m['righe'] = $righe;
        $m['gran_totale'] = $importoCella * 5 * 40;
        $m['tot_per_tabella'] = array_fill_keys(range(1, 5), $importoCella * 40);
        $m['tot_per_capitolo'] = $m['tot_per_tabella'];

        return $m;
    };
    $pagine = function (int $importoCella) use ($vista, $chiaveConteggio, $matricePer): int {
        $dati = ['condominio' => new \App\Models\Condominio(['nome' => 'Condominio di prova', 'indirizzo' => 'Via di prova 1', 'codice_fiscale' => '91000000009']),
            'esercizio' => new \App\Models\Esercizio(['nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']),
            'pianoRate' => new \App\Models\Gestionale\PianoRate(['nome' => 'Piano rate 2026', 'stato' => 'approvato']),
            'matrice' => $matricePer($importoCella), $chiaveConteggio => 5, 'firma_compatta' => true, 'piede_compatto' => true];
        $mpdf = app(\App\Services\PDF\PdfService::class)->generate($vista, $dati, [
            'format' => 'A4-L', 'orientation' => 'L', 'margin_top' => 31, 'margin_left' => 8, 'margin_right' => 8, 'margin_bottom' => 12,
        ]);

        return misuraPdf($mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN))['pagine'];
    };

    // 250000 → totale unità € 12.500,00 e gran totale € 500.000,00; 190000 → € 9.500,00 e € 380.000,00.
    expect($pagine(250000))->toBe($pagine(190000));
})->with([
    ['pdf.gestionale.riparto_tabelle', 'tabelle', 'per_tabella', 'nTabelle'],
    ['pdf.gestionale.riparto_capitoli', 'capitoli', 'per_capitolo', 'nCapitoli'],
]);

test('le righe escono per interno in ordine naturale, poi le unità senza interno per nome; il codice è solo spareggio', function () {
    [$condominio, $piano] = condominioDelleStampe();
    $tabella = $condominio->tabelle()->where('nome', 'Generale')->firstOrFail();
    $tipologia = \Illuminate\Support\Facades\DB::table('tipologie_immobili')->value('id');
    $persona = \App\Models\Anagrafica::query()->where('nome', 'Serena Lombardi')->firstOrFail();

    // Cinque unità nuove, create in quest'ordine e SENZA codice: sono le unità di ogni installazione
    // vera, dove `Immobile::booted()` assegna C{id}-NNNN nell'ordine di creazione.
    foreach ([['10', 'Interno dieci'], ['2', 'Interno due'], ['4 BIS', 'Interno quattro bis'], ['', 'Box Z'], ['1', 'Interno uno']] as [$interno, $nome]) {
        $u = \App\Models\Immobile::create(['condominio_id' => $condominio->id, 'tipologia_id' => $tipologia, 'nome' => $nome, 'interno' => $interno, 'piano' => '1']);
        \Illuminate\Support\Facades\DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $u->id, 'valore' => 1, 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persona->id, 'immobile_id' => $u->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()->subYears(3), 'created_at' => now(), 'updated_at' => now()]);
    }
    // Il piano rate va rigenerato: le righe della matrice vengono dalle quote delle rate.
    $piano->rate()->delete();
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano->refresh(), accettaScoperti: false);

    $nomiNuovi = ['Interno dieci', 'Interno due', 'Interno quattro bis', 'Box Z', 'Interno uno'];
    foreach ([RipartoTabelleService::class, \App\Services\RipartoCapitoliService::class] as $servizio) {
        $matrice = app($servizio)->buildMatrice($piano->refresh());
        $ordine = array_values(array_filter(array_column($matrice['righe'], 'nome_immobile'), fn ($n) => in_array($n, $nomiNuovi, true)));
        expect($ordine)->toBe(['Interno uno', 'Interno due', 'Interno quattro bis', 'Interno dieci', 'Box Z']);
    }
});

/**
 * Una matrice sintetica con N colonne (tabelle o capitoli) e due unità: serve a provare il
 * template — i blocchi, i totali — senza passare dal motore, che ha i suoi test.
 */
function matriceSintetica(int $colonne, string $chiaveColonne, string $chiaveCella): array
{
    $info = [];
    $totali = [];
    for ($i = 1; $i <= $colonne; $i++) {
        $info[$i] = ['nome' => 'Colonna '.$i, 'quota_label' => 'mill. ‰', 'quota_tipo' => 'millesimi', 'decimali' => 2, 'tot_quota' => 1000.0, 'tot_importo' => 10000];
        $totali[$i] = 10000;
    }
    $celle = fn () => array_combine(range(1, $colonne), array_fill(0, $colonne, ['quota' => 500.0, 'importo' => 5000]));
    $righe = [];
    foreach ([[1, 'Interno 1', '1', 'Piano terra'], [2, 'Interno 2', '2', 'Primo piano']] as [$id, $nome, $interno, $piano]) {
        $righe[$id] = [
            'codice_immobile' => 'C'.$id, 'interno' => $interno, 'piano' => $piano, 'nome_immobile' => $nome,
            'soggetti' => [10 + $id => ['nome' => 'Soggetto '.$id, 'ruolo' => 'P', 'ruolo_raw' => 'proprietario', 'quota_sogg' => 100, $chiaveCella => $celle(), 'totale' => 5000 * $colonne]],
            'totale_immobile' => 5000 * $colonne,
        ];
    }

    return [
        $chiaveColonne => $info,
        'righe' => $righe,
        'gran_totale' => 10000 * $colonne,
        'tot_per_tabella' => $totali,
        'tot_per_capitolo' => $totali,
        'tot_quota_per_tabella' => array_fill_keys(array_keys($info), 1000.0),
    ];
}

function rendiStampa(string $vista, array $matrice, string $chiaveConteggio): string
{
    $condominio = new \App\Models\Condominio(['nome' => 'Condominio di prova', 'indirizzo' => 'Via di prova 1', 'codice_fiscale' => '91000000009']);
    $esercizio = new \App\Models\Esercizio(['nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $piano = new \App\Models\Gestionale\PianoRate(['nome' => 'Piano rate 2026', 'stato' => 'approvato']);

    return view($vista, [
        'condominio' => $condominio, 'esercizio' => $esercizio, 'pianoRate' => $piano,
        'matrice' => $matrice, $chiaveConteggio => count($matrice[$chiaveConteggio === 'nTabelle' ? 'tabelle' : 'capitoli']),
        'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => null,
    ])->render();
}

test('dieci tabelle escono in due blocchi bilanciati da cinque, e i totali stanno solo nell\'ultimo', function () {
    $html = rendiStampa('pdf.gestionale.riparto_tabelle', matriceSintetica(10, 'tabelle', 'per_tabella'), 'nTabelle');

    // Blocchi: uno per tabella HTML principale; la seconda ha «Blocco 2 di 2» in testa.
    expect(substr_count($html, 'Blocco 2 di 2'))->toBe(1)
        ->and(substr_count($html, '<pagebreak />'))->toBe(1);

    // Cinque tabelle per blocco: le intestazioni a due colonne sono 5 in ognuno, non 8 + 2.
    $blocchi = preg_split('#<pagebreak />#', $html);
    expect(substr_count($blocchi[0], 'colspan="2"'))->toBe(5)
        ->and(substr_count($blocchi[1], 'colspan="2"'))->toBe(5);

    // I totali di documento — colonne e riga — solo nell'ultimo blocco (Coda 79).
    expect(substr_count($blocchi[0], 'TOT. SOGG.'))->toBe(0)
        ->and(substr_count($blocchi[0], 'Totali delle colonne di questo blocco'))->toBe(1)
        ->and(substr_count($blocchi[0], 'Continua nel blocco successivo'))->toBe(1)
        ->and(substr_count($blocchi[1], 'TOT. SOGG.'))->toBe(1)
        ->and(substr_count($blocchi[1], 'TOT. IMMOB.'))->toBe(1)
        ->and(substr_count($blocchi[1], 'Totali generali'))->toBe(1);

    // Il corpo è per blocco: due tabelle principali a 7pt (la stringa con `line-height` è lo stile
    // della sola tabella principale; «font-size: 7pt;» da solo sta anche nel piè di pagina).
    expect(substr_count($html, 'font-size: 7pt; line-height: 1.15;'))->toBe(2);
});

test('con sette o otto tabelle il blocco dei totali non supera le sei, e i blocchi restano bilanciati', function (int $n, array $attesi) {
    // Nel blocco con i totali le colonne fisse valgono il 45%: a sette o otto tabelle «1.000,00» e
    // «€ 1.021,25» non stanno nelle loro colonne e mPDF li spezza su due righe, nowrap o no.
    $html = rendiStampa('pdf.gestionale.riparto_tabelle', matriceSintetica($n, 'tabelle', 'per_tabella'), 'nTabelle');
    $blocchi = preg_split('#<pagebreak />#', $html);

    expect(array_map(fn ($b) => substr_count($b, 'colspan="2"'), $blocchi))->toBe($attesi)
        ->and(substr_count(end($blocchi), 'Totali generali'))->toBe(1)
        ->and(substr_count($html, 'font-size: 7pt; line-height: 1.15;'))->toBe(count($attesi));
})->with([[7, [4, 3]], [8, [4, 4]], [14, [5, 5, 4]], [16, [6, 6, 4]]]);

test('sette capitoli escono in due blocchi da quattro e tre, mai sei più uno', function () {
    $html = rendiStampa('pdf.gestionale.riparto_capitoli', matriceSintetica(7, 'capitoli', 'per_capitolo'), 'nCapitoli');

    $blocchi = preg_split('#<pagebreak />#', $html);
    expect(count($blocchi))->toBe(2)
        ->and(substr_count($blocchi[0], 'colspan="2"'))->toBe(4)
        ->and(substr_count($blocchi[1], 'colspan="2"'))->toBe(3)
        ->and(substr_count($blocchi[0], 'TOT. SOGG.'))->toBe(0)
        ->and(substr_count($blocchi[1], 'TOT. SOGG.'))->toBe(1)
        ->and(substr_count($blocchi[1], 'Totali generali'))->toBe(1);
});

test('nel riparto per capitolo le quote seguono i decimali della tabella, come nel riparto per tabella', function () {
    // Prima erano tre decimali fissi: a sei colonne «159,570» non stava nella cella e si spezzava
    // in «159,57» + «0»; e lo stesso millesimo usciva «38,80» in una stampa e «38,400» nell'altra.
    $html = rendiStampa('pdf.gestionale.riparto_capitoli', matriceSintetica(6, 'capitoli', 'per_capitolo'), 'nCapitoli');

    expect($html)->toContain('500,00')->not->toContain('500,000');
});

test('la cella dell\'unità etichetta il piano quando il dato è nudo, e non lo ripete quando lo porta già', function () {
    foreach (['pdf.gestionale.riparto_tabelle' => ['tabelle', 'per_tabella', 'nTabelle'], 'pdf.gestionale.riparto_capitoli' => ['capitoli', 'per_capitolo', 'nCapitoli']] as $vista => [$chiaveColonne, $chiaveCella, $chiaveConteggio]) {
        $matrice = matriceSintetica(2, $chiaveColonne, $chiaveCella);
        $matrice['righe'][1]['interno'] = '3';
        $matrice['righe'][1]['piano'] = '1';          // dal form e dall'importatore il piano è nudo: «1», «T»
        $matrice['righe'][2]['piano'] = 'Primo piano'; // dai seeder porta già la parola
        $html = rendiStampa($vista, $matrice, $chiaveConteggio);

        expect($html)->toContain('int. 3 · piano 1')
            ->toContain('Primo piano')
            ->not->toContain('piano Primo piano');
    }
});

test('con sei tabelle il blocco è uno solo, con i totali, e il corpo è a sette punti', function () {
    $html = rendiStampa('pdf.gestionale.riparto_tabelle', matriceSintetica(6, 'tabelle', 'per_tabella'), 'nTabelle');

    expect(substr_count($html, '<pagebreak />'))->toBe(0)
        ->and(substr_count($html, 'TOT. SOGG.'))->toBe(1)
        ->and(substr_count($html, 'Totali generali'))->toBe(1)
        // «font-size: 7pt;» da solo sta anche nel piè di pagina di pdf.base: si conta lo stile
        // della tabella principale, e si esclude il corpo ridotto.
        ->and(substr_count($html, 'font-size: 7pt; line-height: 1.15;'))->toBe(1)
        ->and(substr_count($html, 'font-size: 6.5pt; line-height: 1.15;'))->toBe(0);
});
