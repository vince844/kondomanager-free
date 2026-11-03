<?php

/**
 * 1.11.0-beta.55 (Coda 350) — due successioni con l'arretrato agli eredi di fila: la seconda non si ferma come una vendita, e la riga della coppia è dell'erede dell'erede.
 *
 * Con l'arretrato agli eredi la successione passa agli eredi tutta la posizione del defunto, coppia compresa (decisione 65): la riga di Mario è di Ugo dopo la sua
 * successione. Quando poi muore Ugo, anche lui con l'arretrato agli eredi, la parte della coppia che aveva avuto da Mario passa a Bruno con la sua posizione, come
 * la sua riga: l'arretrato di Ugo che passa a Bruno la contiene già. Prima la seconda successione si fermava come una vendita, «€ 38,47 restano a carico di Ugo …
 * registra dal Wallet € 38,47 a credito di Ugo e € 38,47 a debito di Bruno»: seguita, Bruno pagava due volte quei giorni e un defunto restava con un credito. E chi ha
 * le righe della coppia si leggeva a un livello solo di successioni (la riga di Mario era ancora di Ugo dopo la morte di Ugo): la rivendita di Bruno metteva la cifra
 * «a carico di Ugo», un defunto. Ora la seconda successione non ha fermate, e la riga si legge in catena: la rivendita di Bruno è sua, con il Wallet, come quella
 * dell'erede che non ha comprato niente; anche quando compra chi ha una sua riga della coppia (Anna), che con la .55 fa nascere la fermata.
 *
 * Fonte dell'atteso: il conto a mano. Mario 50 %, Anna 50 %, la fine di Ines il 1/7 con «Scrivi» (la coppia 18148: Mario 9074, Anna 9074); Mario muore il 1/10 (Ugo
 * erede), Ugo il 15/10 (Bruno erede), tutti e due con l'arretrato agli eredi. La rivendita di Bruno il 1/12 vale 18148 × 50 × 31/18400 = 1528,77 → 1529 (i resti più
 * grandi sono di Bruno, ,82, e di chi compra, ,77). La fermata che non c'è più era 18148 × 50 × 78/18400 = 3846,59 → 3847, i giorni di Ugo dopo il decesso.
 *
 * Che cosa NON copre: la successione agli eredi di chi ha anche comprato una quota fermata (le parti vengono da più origini); MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** Mario 50 %, Anna 50 %: Mario muore il 1/10 (Ugo erede), Ugo muore il 15/10 (Bruno erede), tutti e due con l'arretrato agli eredi. */
function fer55DueSuccessioni($test): array
{
    $s = fer55Base($test, [['Mario', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Ugo');
    $s['p1'] = fer55Passo($test, $s, fer55Successione($s, 'Mario', 'proprietario', '2026-10-01', 50, ['Ugo' => 50]));
    $s['p2'] = fer55Passo($test, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 50]));

    return $s;
}

it('Coda 350 (la seconda successione agli eredi) — Mario muore (Ugo erede), poi Ugo (Bruno erede), tutti e due con l\'arretrato agli eredi: la successione di Ugo non ha fermate, e non manda a registrare dal Wallet un credito di Ugo', function () {
    $s = fer55Base($this, [['Mario', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Ugo');
    fer55Passo($this, $s, fer55Successione($s, 'Mario', 'proprietario', '2026-10-01', 50, ['Ugo' => 50]));
    $dati = fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 50]);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p2 = fer55Passo($this, $s, $dati);

    // Conto a mano: la riga di Mario (9074) è di Ugo dopo la prima successione, e passa a Bruno con la seconda, come l'arretrato di Ugo: i giorni di Ugo dopo il decesso,
    // 3847, non restano a carico di nessuno.
    expect(fer55Proposte($pannello))->toBe([])
        ->and(fer55Registro($p2))->toBe([])
        ->and(fds50Con($pannello, 'restano a carico'))->toBe([])
        ->and(fds50Con($pannello, '38,47'))->toBe([])
        ->and(fds50Con($pannello, 'registra dal Wallet i saldi legati a questo passaggio'))->toBe([]);
    expect(fer55Storico($s, $p2))->toBe('');
});

it('Coda 350 (la rivendita dopo due successioni agli eredi) — Bruno, erede di Ugo che era erede di Mario, vende il suo 50 % a Carlo: € 15,29 restano a carico di Bruno, con il Wallet', function () {
    $s = fer55DueSuccessioni($this);
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: i giorni di Bruno stanno nella riga di Mario, che è sua: 18148 × 1550/18400 = 1528,77 → 1529, a carico di Bruno, con il Wallet.
    $fermata = [['persone' => ['Bruno'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);
    // La frase dice da dove viene la parte di Bruno: la riga di Mario, passata a Bruno con la successione di Ugo, che è l'ultima della catena.
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Mario ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'Con la successione del 15 ottobre 2026 una parte, € 90,74, è passata a Bruno. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 15,29 restano a carico di Bruno. '
        . 'Se vanno a Carlo, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 15,29 a credito di Bruno e € 15,29 a debito di Carlo. '
        . 'Un saldo legato non occupa il posto di un riporto, e si toglie se annulli il passaggio.');
    foreach (fds50Con($pannello, '15,29') as $testo) {
        expect($testo)->not->toContain('Ugo');
    }
    expect(sl53Errori(sl53Posta($this, $s, 'Bruno', -1529, (int) $p3->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Carlo', 1529, (int) $p3->id)))->toBe([])
        ->and(sl53Legate($s, $p3))->toBe(['Bruno' => -1529, 'Carlo' => 1529]);
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 350 (la rivendita a chi ha la coppia) — Bruno vende il suo 50 % ad Anna, che ha la sua riga: € 15,29 restano a carico di Bruno, con il Wallet, non di Ugo', function () {
    $s = fer55DueSuccessioni($this);
    $dati = fds50ModuloVendita($s, 'Bruno', 'Anna', '2026-12-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: Anna passa da 9074 a 18148 × 10750/18400 = 10602,77 → 10603 (i resti più grandi sono di Bruno e di Anna): 1529, nella riga di Bruno, non in quella di Anna.
    $fermata = [['persone' => ['Bruno'], 'cifra' => 1529, 'riceve' => ['Anna' => 1529], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toContain('€ 15,29 restano a carico di Bruno. Se vanno ad Anna, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: '
        . '€ 15,29 a credito di Bruno e € 15,29 a debito di Anna.');
    foreach (fds50Con($pannello, '15,29') as $testo) {
        expect($testo)->not->toContain('Ugo');
    }
});

it('Coda 350 (l\'erede che muore a sua volta) — Ugo 75 %, Anna 25 %: Ugo muore (Bruno erede), poi Bruno (eredi Anna e Dora), tutti e due con l\'arretrato agli eredi: la successione di Bruno non ha fermate, e nessun credito resta a un defunto', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 75]));
    $dati = fer55Successione($s, 'Bruno', 'proprietario', '2026-10-18', 75, ['Anna' => 37.5, 'Dora' => 37.5]);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p2 = fer55Passo($this, $s, $dati);

    // Conto a mano: la riga di Ugo (13611) è di Bruno, e con la sua successione passa ad Anna e a Dora con la sua posizione. La fermata che non c'è più era 18148 × 75 × 75/18400
    // = 5547,96 → 5548 (2774 e 2774), a credito di Bruno.
    expect(fer55Proposte($pannello))->toBe([])
        ->and(fer55Registro($p2))->toBe([])
        ->and(fds50Con($pannello, 'restano a carico'))->toBe([])
        ->and(fds50Con($pannello, '55,48'))->toBe([])
        ->and(fds50Con($pannello, 'a credito di Bruno'))->toBe([]);
});

it('Coda 350 (guardia: la seconda successione con l\'arretrato a nome del defunto e «Scrivi») — la posizione di Ugo resta a suo nome, e la fermata c\'è: € 38,47 restano a carico di Ugo, con il Wallet', function () {
    $s = fer55Base($this, [['Mario', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Ugo');
    fer55Passo($this, $s, fer55Successione($s, 'Mario', 'proprietario', '2026-10-01', 50, ['Ugo' => 50]));
    $dati = fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 50], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p2 = fer55Passo($this, $s, $dati);

    // Conto a mano: con l'arretrato a nome del defunto la posizione di Ugo, con la parte della coppia avuta da Mario, resta a lui; i suoi giorni dopo il decesso sono di Bruno:
    // 18148 × 50 × 78/18400 = 3846,59 → 3847, a carico di Ugo, con il Wallet, come la fermata di ogni successione con «Scrivi».
    $fermata = [['persone' => ['Ugo'], 'cifra' => 3847, 'riceve' => ['Bruno' => 3847], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p2))->toBe($fermata)
        ->and(fer55Frase($pannello, 'Con questo passaggio'))->toContain('€ 38,47 restano a carico di Ugo. Se vanno a Bruno, registra dal Wallet i saldi legati a questo passaggio');
});

it('Coda 350 — la successione agli eredi di chi aveva comprato con una fermata, senza la coppia, ha la sua fermata: € 19,23 a carico di Anna, o di Elsa', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    $dati = fer55Successione($s, 'Elsa', 'proprietario', '2026-10-15', 25, ['Dora' => 25]);
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano: 18148 × 25 × 78/18400 = 1923,3 → 1923, i giorni di Elsa dopo il decesso, che stanno nella riga di Anna. Elsa non ha la coppia: la sua
    // successione non è quella dell'erede che ha già i giorni, e la fermata resta.
    expect(fer55Proposte($pannello))->toBe([['persone' => ['Anna'], 'cifra' => 1923, 'riceve' => ['Dora' => 1923], 'uscente' => 'Elsa',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268], 'wallet' => false, 'strada' => null]]);
    fer55Passo($this, $s, $dati);
    expect(fer55Storico($s, $p1))->toEndWith('di questi, € 19,23 sono passati a Dora con la successione del 15 ottobre 2026.');
});

it('Coda 355 — l\'erede che ha anche comprato una quota fermata muore con l\'arretrato agli eredi: la fermata della sua successione è la sola quota di Anna, € 19,23; la parte avuta da Mario passa con la posizione', function () {
    // Mario 50 %, Anna 25 %, Carlo 25 % (la coppia 18148: Mario 9074, Anna 4537, Carlo 4537). Mario muore il 1/10 (Ugo erede, agli eredi: nessuna fermata, Coda 350); Anna
    // vende a Ugo il 5/10: 18148 × 25 × 88/18400 = 2169,87 → 2170, a carico di Anna; Ugo ha una riga sola da 75 (50 avuti da Mario, 25 comprati). Ugo muore il 15/10, Bruno
    // erede, agli eredi: Bruno prende i 78 giorni della riga di Ugo, 18148 × 75 × 78/18400 = 5769,93 → 5770, per quota 50 e 25: 3846,67 e 1923,33 → 3847 e 1923. La parte
    // avuta da Mario passa a Bruno con la posizione di Ugo (Coda 350); resta da dire la quota di Anna, € 19,23, come la fermata di chi aveva comprato con una fermata (la
    // guardia qui sopra). Prima: «€ 57,70 restano a carico di Ugo e Anna», un defunto e una persona insieme, con la parte di Mario dentro.
    $s = fer55Base($this, [['Mario', 'proprietario', 50], ['Anna', 'proprietario', 25], ['Carlo', 'proprietario', 25]]);
    fclPersona($s, 'Ugo');
    fer55Passo($this, $s, fer55Successione($s, 'Mario', 'proprietario', '2026-10-01', 50, ['Ugo' => 50]));
    $vendita = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-05', 25));
    expect(fer55Registro($vendita))->toBe([['persone' => ['Anna'], 'cifra' => 2170, 'riceve' => ['Ugo' => 2170], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    $dati = fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 75]);
    $pannello = fds50Anteprima($this, $s, $dati);

    expect(fer55Proposte($pannello))->toBe([['persone' => ['Anna'], 'cifra' => 1923, 'riceve' => ['Bruno' => 1923], 'uscente' => 'Ugo',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 5 ottobre 2026', 'cifra' => 2170], 'wallet' => false, 'strada' => null]])
        ->and(fds50Con($pannello, '€ 57,70'))->toBe([])
        // Ugo è chi muore, e con l'arretrato agli eredi la sua posizione passa a Bruno, che riceve quei giorni: se la fermata della vendita è già stata regolata la parte è di Ugo, cioè
        // di Bruno, e non c'è niente da regolare. La frase non nomina il defunto come chi risponde («o di Ugo se …»).
        ->and(fer55Frase($pannello, 'tocca a Bruno'))->toContain('€ 19,23 restano a carico di Anna, o di Bruno, che ha la posizione di Ugo, se la fermata della vendita del 5 ottobre 2026 è già stata regolata')
        ->and(fer55Frase($pannello, 'tocca a Bruno'))->not->toContain('o di Ugo se')
        ->and(implode(' ', $pannello['cancello']['motivi']))->toContain('€ 19,23 dei giorni dopo l\'uscita di Ines restano a carico di Anna, o di Bruno, che ha la posizione di Ugo, se la fermata della vendita del 5 ottobre 2026 è già stata regolata (gestione Ordinaria 2026)');
    fer55SenzaNomiMancanti($pannello);

    $successione = fer55Passo($this, $s, $dati);
    expect(fer55Storico($s, $vendita))->toEndWith('di questi, € 19,23 sono passati a Bruno con la successione del 15 ottobre 2026.')
        // Lo storico della successione dice la stessa cosa: da regolare a mano con Bruno; se la fermata della vendita era già stata regolata, la posizione di Ugo è passata a Bruno.
        ->and(fer55Storico($s, $successione))->toEndWith('€ 19,23 dei giorni dal 15 ottobre 2026 sono rimasti a carico di Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Bruno; '
            . 'se la fermata della vendita del 5 ottobre 2026 era già stata regolata, non resta niente da regolare: la posizione di Ugo è passata a Bruno.')
        ->and(fer55Storico($s, $successione))->not->toContain('fra Ugo e Bruno');
});

it('Coda 355 — dopo la successione di Ugo, Luca entra il 1/12: la fermata della successione scende ai soli giorni della quota di Anna, € 11,59 che restano e € 7,64 da restituire, non a zero; e nel prospetto i giorni di Luca sulla riga di Bruno li ha pagati Anna per la sua quota, € 7,64, e il resto le righe della coppia', function () {
    // La catena del test qui sopra: la coppia 18148 (Mario 9074, Anna 4537, Carlo 4537); la vendita di Anna a Ugo il 5/10, 4537 × 88/184 = 2169,87 → 2170, a carico di Anna; la successione
    // di Ugo il 15/10, agli eredi, la sola quota di Anna nella riga di Ugo, 4537 × 78/184 = 1923,29 → 1923, verso Bruno. Luca entra il 1/12 al 100 % (31 giorni): della successione restano i
    // 47 giorni dal 15/10 al 30/11, 4537 × 47/184 = 1158,91 → 1159 (decisione 112.1: il residuo sui soli giorni della quota della fermata, il 25 % di Anna nella riga di Bruno, non la riga intera
    // da 75); se Anna e Bruno avevano già regolato i € 19,23, Anna restituisce a Bruno 1923 − 1159 = 764, i giorni di dicembre (4537 × 31/184 = 764,39). La vendita resta come prima, da
    // 2170 a 1405 (4537 × 57/184 = 1405,48), la parte di Ugo. Nel prospetto i giorni di Luca dal 1/12, la sua parte del pezzo dal 15/10 (18148 × 78/184 = 7693,17 → 7693; Luca 31 dei 78
    // giorni, 3057,47 → 3057), li ha pagati con la coppia chi stava al posto dell'inquilino: la riga di Carlo (25), la riga di Bruno (75: la posizione di Mario, 50, e la quota di Anna, 25).
    // La quota di Anna è sua, con la fermata, 3057 × 25/100 = 764,25 → 764; il resto, 2293, l'hanno pagato le righe della coppia di Mario (ora di Bruno) e di Carlo, e il pezzo le nomina
    // come i pezzi senza fermate, con chi aveva la riga nella coppia. Era: la successione a zero («non resta niente da regolare», «€ 19,23 vanno restituiti a Bruno»), perché Luca toglieva
    // i giorni della riga di Bruno da 75; e nel prospetto «€ 22,93 pagata da Anna».
    $s = fer55Base($this, [['Mario', 'proprietario', 50], ['Anna', 'proprietario', 25], ['Carlo', 'proprietario', 25]]);
    fclPersona($s, 'Ugo');
    fclPersona($s, 'Bruno');
    fclPersona($s, 'Luca');
    fer55Passo($this, $s, fer55Successione($s, 'Mario', 'proprietario', '2026-10-01', 50, ['Ugo' => 50]));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-05', 25));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 75]));
    $dati = fds50ModuloInizio($s, 'Luca', '2026-12-01');
    $pannello = fds50Anteprima($this, $s, $dati);

    expect(fer55Ridotte($pannello))->toBe([
        ['passaggio' => 'la vendita del 5 ottobre 2026', 'cifra' => 2170, 'prima' => 2170, 'residuo' => 1405, 'parti' => ['Ugo' => 1405]],
        ['passaggio' => 'la successione del 15 ottobre 2026', 'cifra' => 1923, 'prima' => 1923, 'residuo' => 1159, 'parti' => ['Bruno' => 1159]],
    ])->and(fer55Frase($pannello, 'La successione del 15 ottobre 2026'))->toContain('resta da regolare € 11,59 con Bruno. Se chi ne risponde e Bruno hanno già regolato € 19,23, € 7,64 vanno restituiti a Bruno.')
        ->and(fds50Con($pannello, 'non resta niente da regolare'))->toBe([]);

    // Con il residuo che non è zero la spunta resta, con la cifra che resta (decisione 86.2: senza spunta solo quando l'inizio azzera la fermata).
    expect(implode(' ', $pannello['cancello']['motivi']))->toContain('€ 11,59');
    $inizio = fer55Passo($this, $s, $dati);
    expect(implode(' ', fds50Storico($s, $inizio)['annullabile']['avvisi']))->toContain('da € 19,23 a € 11,59')
        ->and(implode(' ', fds50Storico($s, $inizio)['fermate']))->not->toContain('non resta niente da regolare');
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->firstWhere('parole', 'la successione del 15 ottobre 2026')['con_parte_a_mano'] ?? null)->toBeTrue();
    $luca = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Luca');
    expect(collect($luca['voci'])->filter(fn ($v) => ! empty($v['pagato_da']))->groupBy('pagato_da')->map(fn ($g) => (int) $g->sum('importo'))->sortKeys()->all())
        ->toBe(['Anna' => 764, 'Mario e Carlo' => 2293]);
});

it('Coda 357 (la parte più grande del tutto) — l\'erede con due righe della coppia: Mario e Ugo muoiono agli eredi, e Bruno vende tutto a Carlo; la frase dice chi aveva preso tutte e due le righe, non una sola', function () {
    // Mario 50 %, Ugo 50 % (la coppia 18148: Mario 9074, Ugo 9074). Mario muore il 1/10 (Ugo erede), Ugo il 15/10 (Bruno erede), tutti e due agli eredi: le due righe della
    // coppia sono di Bruno. Bruno vende tutto a Carlo il 1/12: 18148 × 100 × 31/18400 = 3057,54 → 3057 (i resti più grandi sono di Ugo, ,83, e di Bruno, ,63). Prima la frase
    // diceva «Ugo ha preso € 90,74 …. Con la successione del 15 ottobre 2026 una parte, € 181,48, è passata a Bruno»: una parte più grande del tutto.
    $s = fer55Base($this, [['Mario', 'proprietario', 50], ['Ugo', 'proprietario', 50]]);
    fer55Passo($this, $s, fer55Successione($s, 'Mario', 'proprietario', '2026-10-01', 50, ['Ugo' => 50]));
    $quota = (float) \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Ugo']->id)
        ->whereNull('data_fine')->orderByDesc('id')->value('quota');
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', $quota, ['Bruno' => $quota]));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', $quota));

    expect(fer55Proposte($pannello))->toBe([['persone' => ['Bruno'], 'cifra' => 3057, 'riceve' => ['Carlo' => 3057], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']])
        ->and(fer55Frase($pannello, 'tocca a Carlo'))->toContain('dei giorni dopo la sua uscita Mario e Ugo hanno preso € 181,48 sulla gestione Ordinaria 2026 (Interno 1). Con la successione del 15 ottobre 2026 una parte, € 181,48, è passata a Bruno.')
        ->and(fer55Frase($pannello, 'tocca a Carlo'))->not->toContain('Ugo ha preso € 90,74');
});
