<?php

/**
 * 1.11.0-beta.55 (Coda 351) — chi ricompra la sua stessa parte non ha una fermata nuova, ma quella di prima si riduce: la seconda vendita lo dice.
 *
 * Ugo, proprietario unico, vende a Elsa il 1/10: la fermata della vendita lascia a carico di Ugo i giorni di Elsa dopo l'uscita di Ines, € 90,74 (con il Wallet). Elsa gli
 * rivende il 1/11: i giorni dal 1/11 tornano nella riga della coppia di Ugo, che è l'unica persona a carico di quei giorni, e la seconda vendita non ha una fermata (giusto,
 * Coda 347). Ma la fermata della prima non si riduceva, e nessuna frase lo diceva: lo storico della prima diceva ancora € 90,74 da regolare con Elsa, e seguite le righe
 * legate Elsa pagava € 60,16 in più. Ora la seconda vendita dice quanto resta della fermata di prima, come un inizio di locazione (`fermate_ridotte`): la frase, il motivo
 * del cancello con la spunta, e con le righe legate la strada per correggerle dal Wallet; il registro lo conserva (`fermata_ridotta`, con chi ricompra) e lo storico della
 * prima lo ricorda. Vale per ogni passaggio che riporta i giorni all'unica persona a carico (anche l'estinzione dell'usufrutto che Ugo aveva costituito a Elsa), non per la
 * successione: con l'erede che prende la posizione di chi muore la parte non si dice qui.
 *
 * Fonte dell'atteso: il conto a mano. La coppia 18148 sui 184 giorni dal 1/7 al 31/12. La fermata della prima vendita è 18148 × 9200/18400 = 9074; dopo la seconda Elsa ha
 * 31 giorni, 18148 × 3100/18400 = 3057,54 → 3058 (il centesimo a Elsa, ,54 contro ,46 di Ugo): restano 3058, e se Ugo e Elsa avevano già regolato la fermata ne vanno
 * restituiti 9074 − 3058 = 6016. L'erede unico che ricompra (unità mista, la piena di Ugo venduta a Elsa e la nuda a Bruno per successione): la fermata è 4537, dopo restano
 * i 31 giorni di Elsa, 1528,77 → 1529.
 *
 * Che cosa NON copre: chi riceve fra più persone a carico, o insieme ad altri (la fermata nuova dice la parte degli altri); la successione di chi aveva ricevuto (il limite
 * resta); MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

it('Coda 351 (chi ricompra la sua stessa parte) — Ugo vende tutto a Elsa e la ricompra il 1/11: della fermata di € 90,74 restano € 30,58, il pannello e lo storico della prima lo dicono, e seguita la frase le righe legate sono € 30,58', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -9074, (int) $p1->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Elsa', 9074, (int) $p1->id)))->toBe([]);
    $this->travel(1)->minutes();
    $dati = fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano: dei giorni della prima fermata (92 dal 1/10) a Elsa ne restano 31: 3058. I giorni dal 1/11 sono di nuovo di Ugo, nella sua riga della coppia.
    expect(fer55Proposte($pannello))->toBe([])
        ->and(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 9074, 'prima' => 9074, 'residuo' => 3058, 'parti' => ['Elsa' => 3058]]])
        ->and($pannello['cancello']['richiesto'])->toBeTrue();
    $frase = fer55Frase($pannello, 'tornano a Ugo');
    expect($frase)->toBe('La vendita del 1 ottobre 2026 ha lasciato a carico di Ugo € 90,74 dei giorni dopo l\'uscita di Ines, da regolare a mano con Elsa. '
        . 'Con questo passaggio i giorni dal 1 novembre 2026 tornano a Ugo, che li ha già nella sua parte della coppia: resta da regolare € 30,58 con Elsa. '
        . 'Se Ugo e Elsa hanno già regolato € 90,74, € 60,16 vanno restituiti a Elsa. '
        . 'I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 90,74 a credito di Ugo e € 90,74 a debito di Elsa; la parte che resta è € 30,58 a credito di Ugo e € 30,58 a debito di Elsa. '
        . 'Il programma non li cambia: correggi dal Wallet i saldi che si modificano.');
    expect(fds50Testi($pannello))->toContain('della fermata della vendita del 1 ottobre 2026 restano da regolare fra Ugo e Elsa € 30,58, non più € 90,74: '
        . 'i giorni dal 1 novembre 2026 tornano a Ugo, che li ha già nella sua parte della coppia');

    // Registrata, la seconda vendita conserva quanto resta, il suo storico lo dice e quello della prima lo ricorda.
    $p2 = fer55Passo($this, $s, $dati);
    expect(array_map(fn (array $f) => [(int) $f['prima'], (int) $f['residuo'], $f['ricompra'] ?? null], $p2->fresh()->registro['fermata_ridotta'] ?? []))->toBe([[9074, 3058, 'Ugo']])
        ->and(collect(fds50Testi(fds50Storico($s, $p2)))->first(fn (string $t) => str_starts_with($t, 'Con questo passaggio')))
        ->toBe('Con questo passaggio, della fermata della vendita del 1 ottobre 2026 (€ 90,74 rimasti a carico di Ugo) restano da regolare fra Ugo e Elsa € 30,58: '
            . 'i giorni dal 1 novembre 2026 tornano a Ugo, che li ha già nella sua parte della coppia.')
        ->and(fer55Storico($s, $p1))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 90,74 dei giorni dal 1 ottobre 2026 '
            . 'sono rimasti a carico di Ugo sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Elsa; con la vendita del 1 novembre 2026 i giorni dal 1 novembre 2026 '
            . 'sono tornati a Ugo, e ne restano da regolare € 30,58.');

    // Seguita dalle rotte la strada che la frase dice, le righe legate sono la parte che resta.
    expect(sl53SeguiIFatti($this, $s, $p1, ['Ugo' => -3058, 'Elsa' => 3058], $frase))->toBe([])
        ->and(sl53Legate($s, $p1))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
    fer55SenzaNomiMancanti($pannello);

    // Annullata la seconda vendita, l'avviso dice che la parte torna com'era, € 90,74, con i fatti delle righe legate (seguite, sommano € 30,58).
    $this->travel(1)->minutes();
    $annullata = sl53Annulla($this, $s, $p2);
    expect(sl53Errori($annullata))->toBe([])
        ->and(sl53Messaggio($annullata)['message'])->toContain('Con questo passaggio la parte verso Elsa della fermata della vendita del 1 ottobre 2026 è scesa da € 90,74 a € 30,58: '
            . 'annullandolo torna da € 30,58 a € 90,74. Se le parti hanno già regolato la differenza fra loro, quell\'accordo va rivisto. '
            . 'I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 30,58 a credito di Ugo e € 30,58 a debito di Elsa; '
            . 'la parte che torna è € 90,74 a credito di Ugo e € 90,74 a debito di Elsa. Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano.')
        // e lo storico della prima non ricorda più la rivendita annullata.
        ->and(fer55Storico($s, $p1))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 90,74 dei giorni dal 1 ottobre 2026 '
            . 'sono rimasti a carico di Ugo sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Elsa.');
});

it('Coda 351 (l\'erede unico che ricompra) — Ugo vende la metà piena a Elsa, muore (la nuda a Bruno), e Bruno la ricompra il 1/11: della fermata restano € 15,29, non € 45,37', function () {
    $s = fer55Mista($this);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 50]));
    $dati = fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano: la fermata della vendita a Elsa è 18148 × 50 × 92/18400 = 4537, a carico di Ugo, cioè di Bruno, suo unico erede. Bruno ricompra: dei giorni di Elsa ne restano 31,
    // 18148 × 1550/18400 = 1528,77 → 1529 (il centesimo a Elsa, ,77 contro ,23 di Bruno).
    expect(fer55Proposte($pannello))->toBe([])
        ->and(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 4537, 'prima' => 4537, 'residuo' => 1529, 'parti' => ['Elsa' => 1529]]])
        ->and($pannello['cancello']['richiesto'])->toBeTrue()
        ->and(fer55Frase($pannello, 'tornano a Bruno'))->toContain('Con questo passaggio i giorni dal 1 novembre 2026 tornano a Bruno, che li ha già nella sua parte della coppia: resta da regolare € 15,29 con Elsa.');
    fer55Passo($this, $s, $dati);
    expect(fer55Storico($s, $p1))->toEndWith('; con la vendita del 1 novembre 2026 i giorni dal 1 novembre 2026 sono tornati a Bruno, e ne restano da regolare € 15,29.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 351 (l\'estinzione dell\'usufrutto) — Ugo costituisce l\'usufrutto a Elsa il 1/10, e l\'usufrutto si estingue il 1/11: i giorni tornano a Ugo, e della fermata restano € 30,58', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $p1 = fer55Passo($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'));
    expect(fer55Registro($p1))->toBe([['persone' => ['Ugo'], 'cifra' => 9074, 'riceve' => ['Elsa' => 9074], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloEstinzione($s, 'Elsa', '2026-11-01'));

    // Conto a mano: come la vendita e il riacquisto: Elsa usufruttuaria paga i giorni dal 1/10 al 31/10, 3058; dal 1/11 Ugo torna pieno e li ha nella sua riga.
    expect(fer55Proposte($pannello))->toBe([])
        ->and(fer55Ridotte($pannello))->toBe([['passaggio' => 'la costituzione d\'usufrutto del 1 ottobre 2026', 'cifra' => 9074, 'prima' => 9074, 'residuo' => 3058, 'parti' => ['Elsa' => 3058]]])
        ->and(fer55Frase($pannello, 'tornano a Ugo'))->toContain('resta da regolare € 30,58 con Elsa. Se Ugo e Elsa hanno già regolato € 90,74, € 60,16 vanno restituiti a Elsa.');
});

it('Coda 351 (la fermata che non lascia niente) — Ugo vende a Elsa il 1/10, Luca è inquilino dal 1/10 al 31/10, e Elsa rivende a Ugo il 1/11: della fermata non resta niente, e il cancello non chiede una spunta per questo', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    fer55Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $fine = fer55Passo($this, $s, fds50ModuloFine($s, Subentro::NON_SCRIVERE_IL_CONGUAGLIO, '2026-11-01', 'Luca'));
    expect(array_map(fn (array $f) => [(int) $f['prima'], (int) $f['dopo']], $fine->fresh()->registro['fermata_risale'] ?? []))->toBe([[0, 6016]]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 100));

    // Conto a mano: con Luca dal 1/10 al 31/10 i giorni di Elsa sono il 1/11–31/12, 18148 × 6100/18400 = 6016,46 → 6016 (il centesimo a Luca, ,54): la fermata vale 6016 dopo la
    // fine di Luca. La rivendita del 1/11 riporta quei giorni a Ugo, e i giorni di ottobre li ha Luca: non resta niente. È un'informazione (decisione 86.2), non un motivo del cancello.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 9074, 'prima' => 6016, 'residuo' => 0, 'parti' => []]])
        ->and(fer55Frase($pannello, 'tornano a Ugo'))->toContain('Con questo passaggio i giorni dal 1 novembre 2026 tornano a Ugo, che li ha già nella sua parte della coppia: '
            . 'non resta niente da regolare fra le parti. Se Ugo e Elsa hanno già regolato € 60,16, € 60,16 vanno restituiti a Elsa.')
        ->and(collect($pannello['cancello']['motivi'])->filter(fn (string $m) => str_contains($m, 'della fermata della vendita del 1 ottobre 2026'))->all())->toBe([]);
});

it('Coda 351 (guardia: niente da ridurre) — Luca entra il 1/11 e poi Elsa rivende a Ugo il 1/11: la fermata è già ridotta ai giorni di Elsa, e la seconda vendita non dice niente', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    $luca = fer55Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    expect(array_map(fn (array $f) => [(int) $f['prima'], (int) $f['residuo']], $luca->fresh()->registro['fermata_ridotta'] ?? []))->toBe([[9074, 3058]]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 100));

    // Conto a mano: con Luca dal 1/11 i giorni di Elsa sono già 31, 3058; la vendita del 1/11 non li cambia.
    expect(fer55Proposte($pannello))->toBe([])
        ->and(fer55Ridotte($pannello))->toBe([])
        ->and(fds50Con($pannello, 'tornano a Ugo'))->toBe([]);
});

it('Coda 356 (la successione agli eredi) — Elsa, che aveva comprato da Ugo, muore il 15/10 e Ugo è il suo erede, con l\'arretrato agli eredi: la posizione di Elsa passa a Ugo, e della fermata non resta niente', function () {
    // Coda 356 (1.11.0-beta.55): prima la successione non diceva niente della fermata di prima, e lo storico della vendita diceva ancora € 90,74 da regolare con Elsa. Con
    // l'arretrato agli eredi la posizione di Elsa, con i suoi giorni dal 1/10 e la sua parte della fermata, passa a Ugo, che è la persona a carico: la parte si estingue. È
    // un'informazione, senza la spunta (decisione 86.2), e il pannello non dice che i giorni «tornano» a Ugo: è la posizione di Elsa che passa.
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    $dati = fer55Successione($s, 'Elsa', 'proprietario', '2026-10-15', 100, ['Ugo' => 100]);
    $pannello = fds50Anteprima($this, $s, $dati);

    expect(fer55Proposte($pannello))->toBe([])
        ->and(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 9074, 'prima' => 9074, 'residuo' => 0, 'parti' => []]])
        ->and(fer55Frase($pannello, 'ha lasciato a carico di Ugo'))->toBe('La vendita del 1 ottobre 2026 ha lasciato a carico di Ugo € 90,74 dei giorni dopo l\'uscita di Ines, da regolare a mano con Elsa. '
            . 'Con questo passaggio la posizione di Elsa, con l\'arretrato, passa a Ugo, che ha già i suoi giorni nella sua parte della coppia: non resta niente da regolare fra le parti.')
        ->and(collect($pannello['cancello']['motivi'])->filter(fn (string $m) => str_contains($m, 'della fermata della vendita del 1 ottobre 2026'))->all())->toBe([])
        ->and(fds50Con($pannello, 'tornano a Ugo'))->toBe([]);

    fer55Passo($this, $s, $dati);
    expect(fer55Storico($s, $p1))->toEndWith('; con la successione del 15 ottobre 2026 la posizione di Elsa è passata a Ugo, e non resta niente da regolare.');
    // La pagina dei saldi non segna più la vendita con una parte da regolare a mano: la fermata è azzerata.
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect(collect($pagina['immobili'])->firstWhere('id', (int) $s['unita']->id)['passaggi_legabili'])->firstWhere('id', (int) $p1->id)['con_parte_a_mano'])->toBeFalse();
});

it('Coda 356 (la successione a nome della defunta) — Elsa muore il 15/10, Ugo erede, l\'arretrato resta a nome di Elsa: con «Scrivi» della fermata restano i 14 giorni di Elsa, € 13,81; prima della scelta lo dice «se scrivi»', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));

    // Conto a mano: dei 92 giorni della fermata a Elsa restano i 14 fino alla morte, 18148 × 14/184 = 1380,83 → 1381 (il centesimo a Elsa, ,83 contro ,17 di Ugo); dal 15/10
    // i giorni passano a Ugo, l'erede, che li ha già nella sua parte della coppia. Se le parti avevano regolato € 90,74, a Elsa ne vanno restituiti 9074 − 1381 = 7693.
    $prima = fds50Anteprima($this, $s, fer55Successione($s, 'Elsa', 'proprietario', '2026-10-15', 100, ['Ugo' => 100], Subentro::ARRETRATO_AL_DEFUNTO));
    expect(fer55Ridotte($prima))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 9074, 'prima' => 9074, 'residuo' => 1381, 'parti' => ['Elsa' => 1381]]])
        ->and(fer55Frase($prima, 'passano con la successione di Elsa'))->toContain('Se scrivi il conguaglio, con questo passaggio i giorni dal 15 ottobre 2026 passano con la successione di Elsa a Ugo')
        ->and($prima['cancello']['motivi'])->toContain('se scrivi il conguaglio, della fermata della vendita del 1 ottobre 2026 restano da regolare fra Ugo e Elsa € 13,81, non più € 90,74: '
            . 'i giorni dal 15 ottobre 2026 passano con la successione di Elsa a Ugo, che li ha già nella sua parte della coppia');

    $dati = fer55Successione($s, 'Elsa', 'proprietario', '2026-10-15', 100, ['Ugo' => 100], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO);
    $pannello = fds50Anteprima($this, $s, $dati);
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 9074, 'prima' => 9074, 'residuo' => 1381, 'parti' => ['Elsa' => 1381]]])
        ->and(fer55Frase($pannello, 'passano con la successione di Elsa'))->toContain('La vendita del 1 ottobre 2026 ha lasciato a carico di Ugo € 90,74 dei giorni dopo l\'uscita di Ines, da regolare a mano con Elsa. '
            . 'Con questo passaggio i giorni dal 15 ottobre 2026 passano con la successione di Elsa a Ugo, che li ha già nella sua parte della coppia: resta da regolare € 13,81 con Elsa. '
            . 'Se Ugo e Elsa hanno già regolato € 90,74, € 76,93 vanno restituiti a Elsa.')
        ->and($pannello['cancello']['motivi'])->toContain('della fermata della vendita del 1 ottobre 2026 restano da regolare fra Ugo e Elsa € 13,81, non più € 90,74: '
            . 'i giorni dal 15 ottobre 2026 passano con la successione di Elsa a Ugo, che li ha già nella sua parte della coppia');

    fer55Passo($this, $s, $dati);
    expect(fer55Storico($s, $p1))->toEndWith('; con la successione del 15 ottobre 2026 i giorni dal 15 ottobre 2026 sono passati a Ugo, e ne restano da regolare € 13,81.');
});

it('Coda 356 (la successione a nome della defunta con «Non scriverlo») — la posizione di Elsa resta com\'è (decisione 77): la fermata della vendita non si riduce, e lo storico dice ancora € 90,74', function () {
    // Con «Non scriverlo» la successione non sposta niente della posizione della defunta, neanche i suoi giorni dopo la morte: le parti li regolano fra loro, e con loro la
    // fermata della vendita, che resta € 90,74 fra Ugo, erede, e la posizione di Elsa. Nessuna voce ridotta, nessun motivo.
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    $dati = fer55Successione($s, 'Elsa', 'proprietario', '2026-10-15', 100, ['Ugo' => 100], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO);
    $pannello = fds50Anteprima($this, $s, $dati);
    expect(fer55Ridotte($pannello))->toBe([])
        ->and(fds50Con($pannello, '€ 13,81'))->toBe([]);

    $p2 = fer55Passo($this, $s, $dati);
    expect($p2->fresh()->registro)->not->toHaveKey('fermata_ridotta')
        ->and(fer55Storico($s, $p1))->toEndWith('sono rimasti a carico di Ugo sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Elsa.');
});

it('Coda 351 (ricompera) — «Annulla il conguaglio» della prima vendita dice la parte di oggi, € 30,58 e non € 90,74, prima e dopo aver seguito la frase della ricompera', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    sl53Posta($this, $s, 'Ugo', -9074, (int) $p1->id);
    sl53Posta($this, $s, 'Elsa', 9074, (int) $p1->id);
    $this->travel(1)->minutes();
    $dati = fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 100);
    $frase = fer55Frase(fds50Anteprima($this, $s, $dati), 'tornano a Ugo');
    fer55Passo($this, $s, $dati);

    // Conto a mano: dei 92 giorni della prima fermata a Elsa ne restano 31: 18148 × 3100/18400 = 3057,54 → 3058. Con le righe legate ancora a ±9074 l'avviso dice
    // che la parte di oggi è € 30,58, e non che le righe sono già la parte della fermata.
    $avvisi = implode(' ', fds50Storico($s, $p1)['conguaglio']['avvisi']);
    expect($avvisi)->toContain('la parte della fermata di questo passaggio è € 30,58 a credito di Ugo e € 30,58 a debito di Elsa')
        ->and($avvisi)->not->toContain('sono già la parte della fermata di questo passaggio');

    // Seguita la frase della ricompera (±3058), l'avviso non rimanda a € 90,74.
    expect(sl53SeguiIFatti($this, $s, $p1, ['Ugo' => -3058, 'Elsa' => 3058], $frase))->toBe([]);
    $avvisi = implode(' ', fds50Storico($s, $p1)['conguaglio']['avvisi']);
    expect($avvisi)->toContain('sono già la parte della fermata di questo passaggio')
        ->and($avvisi)->not->toContain('€ 90,74 a credito di Ugo');
});

it('Coda 351 (la seconda ricompera) — Ugo vende a Elsa, la ricompra, le rivende il 15/11 e la ricompra il 1/12: delle due fermate restano € 46,36, non € 106,52; lo storico della prima lo ricorda', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 100));
    $p3 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-15', 100));
    // Conto a mano: la coppia 18148 sui 184 giorni dal 1/7. La prima fermata è 18148 × 92/184 = 9074; la ricompera del 1/11 la riduce ai 31 giorni di Elsa, 3057,54 →
    // 3058. La rivendita del 15/11 porta Elsa da 31 a 31 + 47 = 78 giorni: 18148 × 78/184 = 7693,17 → 7693 (il centesimo a Ugo, ,83): la sua fermata è 7693 − 3058 = 4635.
    expect(fer55Registro($p3))->toBe([['persone' => ['Ugo'], 'cifra' => 4635, 'riceve' => ['Elsa' => 4635], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);

    // Elsa gli rivende il 1/12: a Elsa restano i 31 giorni di ottobre e i 16 dal 15/11 al 30/11, 18148 × 47/184 = 4635,63 → 4636. Delle due fermate (€ 137,09) prima di
    // questo passaggio restavano 3058 + 4635 = 7693: la ricompera del 1/11 ne aveva già tolto 9074 − 3058 = 6016.
    $dati = fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-12-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 13709, 'prima' => 7693, 'residuo' => 4636, 'parti' => ['Elsa' => 4636]]])
        ->and(fer55Frase($pannello, 'tornano a Ugo'))->toContain('hanno lasciato a carico di Ugo € 137,09 dei giorni dopo l\'uscita di Ines, da regolare a mano con Elsa (con i passaggi registrati da allora, € 76,93). '
            . 'Con questo passaggio i giorni dal 1 dicembre 2026 tornano a Ugo, che li ha già nella sua parte della coppia: resta da regolare € 46,36 con Elsa. '
            . 'Se Ugo e Elsa hanno già regolato € 76,93, € 30,57 vanno restituiti a Elsa.')
        ->and(fds50Con($pannello, '€ 106,52'))->toBe([]);

    $p4 = fer55Passo($this, $s, $dati);
    expect(fer55Storico($s, $p1))->toEndWith('; con la vendita del 1 novembre 2026 i giorni dal 1 novembre 2026 sono tornati a Ugo, e ne restano da regolare € 30,58'
        . '; con la vendita del 1 dicembre 2026 i giorni dal 1 dicembre 2026 sono tornati a Ugo, e ne restano da regolare € 46,36.');

    // Annullata la seconda ricompera, la parte torna a € 76,93 (non a € 137,09), e lo storico della prima non la ricorda più.
    $this->travel(1)->minutes();
    $annullata = sl53Annulla($this, $s, $p4);
    expect(sl53Errori($annullata))->toBe([])
        ->and(sl53Messaggio($annullata)['message'])->toContain('è scesa da € 76,93 a € 46,36: annullandolo torna da € 46,36 a € 76,93.')
        ->and(fer55Storico($s, $p1))->toEndWith('; con la vendita del 1 novembre 2026 i giorni dal 1 novembre 2026 sono tornati a Ugo, e ne restano da regolare € 30,58.');
});

it('Coda 351 (l\'inizio dopo una rivendita) — dopo la ricompera del 1/11 e la rivendita del 15/11, Luca entra il 15/12: delle due fermate restano € 60,16, non € 120,32', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 100));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-15', 100));
    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // Conto a mano: prima dell'inizio restano 3058 + 4635 = 7693 (Elsa con 78 giorni). Luca dal 15/12 prende i 17 giorni di Elsa dal 15/12: Elsa ne ha 61, 18148 ×
    // 61/184 = 6016,46, Luca 18148 × 17/184 = 1676,72, Ugo 10454,70 → i due centesimi a Luca e a Ugo: Elsa 6016. Restano 7693 − 1677 = 6016.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 13709, 'prima' => 7693, 'residuo' => 6016, 'parti' => ['Elsa' => 6016]]])
        ->and(fer55Frase($pannello, 'hanno lasciato a carico di Ugo'))->toContain('resta da regolare € 60,16 con Elsa. Se Ugo e Elsa hanno già regolato € 76,93, € 16,77 vanno restituiti a Elsa.')
        ->and(fds50Con($pannello, '€ 120,32'))->toBe([]);
});

it('Coda 351 (la ricompera dopo un conduttore) — Luca inquilino dal 15/10 al 31/10, poi Elsa rivende a Ugo il 15/11: dopo, la parte di oggi è quella che la ricompera ha detto, € 27,62, e il conduttore di prima non si toglie due volte', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    sl53Posta($this, $s, 'Ugo', -9074, (int) $p1->id);
    sl53Posta($this, $s, 'Elsa', 9074, (int) $p1->id);
    fer55Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-15'));
    fer55Passo($this, $s, fds50ModuloFine($s, Subentro::NON_SCRIVERE_IL_CONGUAGLIO, '2026-11-01', 'Luca'));
    $dati = fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-15', 100);
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano (la coppia 18148 sui 184 giorni dal 1/7): dopo la fine di Luca Elsa ha 14 giorni di ottobre e i 61 da novembre, 18148 × 75/184 = 7397,28, Luca 17 giorni
    // 1676,72, Ugo 9074 → il centesimo a Luca: Elsa 7397. La rivendita del 15/11 le lascia 14 + 14 = 28 giorni: 2761,70, Luca 1676,72, Ugo 139 giorni 13709,57 → i due
    // centesimi a Luca e a Elsa: 2762. Dopo, la parte di oggi riparte da 2762, con le righe che hanno già Luca: senza, si toglierebbe ancora la sua parte (1676) e la parte di
    // oggi sarebbe 1086; togliendo la riduzione (7397 − 2762) da 9074 sarebbe 4439.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 9074, 'prima' => 7397, 'residuo' => 2762, 'parti' => ['Elsa' => 2762]]]);
    fer55Passo($this, $s, $dati);
    expect(implode(' ', fds50Storico($s, $p1)['conguaglio']['avvisi']))->toContain('la parte della fermata di questo passaggio è € 27,62 a credito di Ugo e € 27,62 a debito di Elsa');
});
