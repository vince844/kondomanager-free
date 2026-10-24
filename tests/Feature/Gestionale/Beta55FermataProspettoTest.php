<?php

/**
 * 1.11.0-beta.55 — il prospetto degli oneri accessori non dice due volte la stessa persona nella riga di una fermata.
 *
 * Ogni voce dei giorni senza conduttore dice chi li ha pagati («pagata da Ugo con il conguaglio della fine locazione del …») e, per ogni fermata dei passaggi dopo la
 * fine, chi è entrato se le parti hanno regolato fra loro la parte che quel passaggio non ha spostato («, o da Elsa, se le parti hanno regolato fra loro …»). Se chi è
 * entrato con la fermata è già la persona che la riga nomina come chi ha pagato, l'alternativa gira in tondo («pagata da Ugo …; Ugo, se le parti hanno regolato …»): non
 * si scrive. Le alternative delle altre fermate restano.
 *
 * Ugo 75 % e Anna 25 %, la fine di Ines il 1/7 con «Scrivi» (la coppia 18148: Ugo 13611, Anna 4537). Anna vende a Elsa il 1/10 (4537 × 92/184 = 2268,5, il centesimo ad Anna);
 * Elsa vende a Ugo il 1/11 (18148 × 25 × 61/18400 = 1504,11 → 1504 a carico di Anna). I giorni dopo l'uscita di Ines sono 184: 92 fino al 30/9 (la coppia di Ugo e Anna,
 * 181,48 × 92/184 = 90,74), 31 in ottobre (Ugo e Anna, con la vendita a Elsa: 181,48 × 31/184 = 30,58) e 61 da novembre (181,48 × 61/184 = 60,16, la parte di Ugo).
 *
 * Coda 352 (1.11.0-beta.55): dopo una fermata i giorni della quota che il passaggio non ha spostato li ha pagati chi è rimasto a carico della fermata, o chi l'ha
 * ricevuta se le parti l'hanno regolata. La voce dopo una fermata si divide per chi l'ha pagata: la parte di chi riceve, sua, senza alternative, e quella della quota,
 * «pagata da» chi ne risponde, con l'alternativa di chi l'ha ricevuta e delle fermate di prima della stessa quota. Le alternative non girano in tondo: la riga «pagata da
 * Ugo» della sua parte non ne ha. Dopo la seconda vendita: ottobre 3058 → Ugo 3058 × 75/100 = 2293,5 → 2294 (a pari resto la sua parte, che viene prima), Anna 764 (la
 * quota di Elsa, a carico di Anna); da novembre 6016 → Ugo 4512 e Anna 1504; le due voci di Ugo senza alternativa sono una, 2294 + 4512 = 6806 per 92 giorni.
 *
 * Che cosa NON copre: le cifre dei conduttori dopo una fermata (`Beta55FermataChiHaPagatoTest`); MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** La riga di una voce dei giorni senza conduttore (quella che comincia con i giorni e la cifra dati), fino al totale, nella stampa vera del prospetto. */
function fer55RigaSenzaConduttore(string $stampa, string $giorni, string $cifra): string
{
    preg_match('/Spese generali ' . preg_quote($giorni, '/') . ' giorni ' . preg_quote($cifra, '/') . ' (.*?)(?= (?:Spese generali|Totale) )/u', $stampa, $m);

    return $m[1] ?? '';
}

it('Prospetto (la fermata il cui verso è già il nome del pezzo) — dopo la seconda vendita a Ugo la riga dei 61 giorni dice «pagata da Ugo» e non lo ripete come alternativa', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 25));

    $stampa = fclStampa($s);
    $alternativaDiElsa = ', o da Elsa, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/10/2026 non ha spostato';
    $alternativaDiUgo = '; Ugo, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/11/2026 non ha spostato';

    // Coda 352: da novembre la parte di Ugo (4512) è sua, senza alternative; quella della quota comprata da Elsa (1504) l'ha pagata Anna, o Elsa se la prima fermata è
    // regolata, o Ugo se lo è anche la seconda: qui l'alternativa di Ugo non gira in tondo, perché la riga è «pagata da Anna». Prima la riga dei 61 giorni era una sola,
    // «pagata da Ugo … o da Elsa», e quella di ottobre «pagata da Ugo e Anna … o da Elsa».
    expect(fer55RigaSenzaConduttore($stampa, '61', '€ 15,04'))->toBe('pagata da Anna con il conguaglio della fine locazione del 01/07/2026' . $alternativaDiElsa . $alternativaDiUgo)
        ->and(fer55RigaSenzaConduttore($stampa, '31', '€ 7,64'))->toBe('pagata da Anna con il conguaglio della fine locazione del 01/07/2026' . $alternativaDiElsa)
        ->and(fer55RigaSenzaConduttore($stampa, '92', '€ 68,06'))->toBe('pagata da Ugo con il conguaglio della fine locazione del 01/07/2026')
        ->and(fer55RigaSenzaConduttore($stampa, '92', '€ 90,74'))->toBe('pagata da Ugo e Anna con il conguaglio della fine locazione del 01/07/2026');
    expect($stampa)->not->toContain('pagata da Ugo con il conguaglio della fine locazione del 01/07/2026, o da');
});

it('Prospetto (la voce di un conduttore) — la riga dei giorni di Luca dopo la seconda vendita non ha l\'alternativa che nomina Ugo, già nel pezzo', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 25));
    fer55Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    $stampa = fclStampa($s);

    // Conto a mano: Luca copre 17 giorni (15/12–31/12): 181,48 × 17/184 = 16,77. Coda 352: di questi la parte di Ugo, 1677 × 75/100 = 1257,75 → € 12,58, e quella della
    // quota comprata da Elsa, 419,25 → € 4,19, pagata da Anna; i 44 giorni da novembre fino a Luca restano senza conduttore (Anna € 10,85). Prima la riga di Luca era una
    // sola, € 16,77 «pagata da Ugo … o da Elsa».
    $alternativaDiElsa = ', o da Elsa, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/10/2026 non ha spostato';
    $alternativaDiUgo = '; Ugo, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/11/2026 non ha spostato';
    preg_match('/ 17 € 12,58 (.*?) 17 € 4,19 (.*?) Totale a carico di Luca/u', $stampa, $riga);
    expect($riga[1] ?? '')->toContain('pagata da Ugo con il conguaglio della fine locazione del 01/07/2026')
        ->and($riga[1] ?? '')->not->toContain(', o da')
        ->and($riga[2] ?? '')->toBe('pagata da Anna con il conguaglio della fine locazione del 01/07/2026' . $alternativaDiElsa . $alternativaDiUgo);
    expect(fer55RigaSenzaConduttore($stampa, '44', '€ 10,85'))->toBe('pagata da Anna con il conguaglio della fine locazione del 01/07/2026' . $alternativaDiElsa . $alternativaDiUgo);
    expect($stampa)->not->toContain('pagata da Ugo con il conguaglio della fine locazione del 01/07/2026, o da');
});

it('Prospetto (la prima vendita fra comproprietari) — Anna vende a Ugo: dei giorni dopo la vendita la parte di Ugo è «pagata da Ugo», senza «o da Ugo»; quella della quota di Anna, «pagata da Anna, o da Ugo»', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 50));

    $stampa = fclStampa($s);

    // Conto a mano: 181,48 × 92/184 = 90,74 fino al 30/9 (Ugo e Anna) e altrettanti da ottobre. Coda 352: da ottobre la metà di Ugo è sua, € 45,37, e la metà comprata da
    // Anna, che sta nella riga di Anna, l'ha pagata Anna, o Ugo se le parti hanno regolato la fermata: € 45,37. Prima la riga da ottobre era una, € 90,74 «pagata da Ugo».
    expect(fer55RigaSenzaConduttore($stampa, '92', '€ 90,74'))->toBe('pagata da Ugo e Anna con il conguaglio della fine locazione del 01/07/2026');
    expect(preg_match_all('/Spese generali 92 giorni € 45,37 (.*?)(?= (?:Spese generali|Totale) )/u', $stampa, $m))->toBe(2)
        ->and($m[1])->toBe(['pagata da Ugo con il conguaglio della fine locazione del 01/07/2026',
            'pagata da Anna con il conguaglio della fine locazione del 01/07/2026, o da Ugo, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/10/2026 non ha spostato']);
});

it('Prospetto (le alternative che restano) — Anna vende a Ugo, Ugo muore, Bruno rivende a Carlo: dal 1/12 la riga dice solo l\'alternativa di Carlo, non quella di Ugo, già nel pezzo', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 100, ['Bruno' => 100]));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100));

    $stampa = fclStampa($s);

    // Conto a mano: 181,48 × 92/184 = 90,74 fino al 30/9, × 61/184 = 60,16 in ottobre e novembre, × 31/184 = 30,58 da dicembre (la coppia si divide sui giorni). Coda 352:
    // da ottobre la metà comprata da Anna l'ha pagata Anna (o Ugo, se le parti hanno regolato la fermata), anche dopo la successione: con l'arretrato agli eredi la riga di
    // Ugo passa a Bruno con la sua storia, e la quota si segue nella riga dell'erede (prima si fermava al 14/10: € 6,90 ad Anna e € 53,26 a Ugo, e i 47 giorni dal 15/10 al
    // 30/11 della metà comprata, € 23,18, «pagata da Ugo»). Nel pezzo di ottobre e novembre i pesi sono 50 × 14 + 50 × 47 = 3050 per la quota e altrettanti per Ugo:
    // 6016 × 3050/6100 = 3008 ad Anna e 3008 a Ugo. Dal 1/12 la vendita di Bruno a Carlo si è fermata a carico di Anna e Bruno, che hanno pagato quei giorni; Carlo, se le parti
    // hanno regolato la fermata.
    expect(fer55RigaSenzaConduttore($stampa, '61', '€ 30,08'))->toBe('pagata da Ugo con il conguaglio della fine locazione del 01/07/2026')
        ->and(preg_match_all('/Spese generali 61 giorni € 30,08 (.*?)(?= (?:Spese generali|Totale) )/u', $stampa, $m))->toBe(2)
        ->and($m[1][1] ?? '')->toBe('pagata da Anna con il conguaglio della fine locazione del 01/07/2026, '
            . 'o da Ugo, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/10/2026 non ha spostato')
        ->and(fer55RigaSenzaConduttore($stampa, '31', '€ 30,58'))->toBe('pagata da Anna e Bruno con il conguaglio della fine locazione del 01/07/2026, '
            . 'o da Carlo, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/12/2026 non ha spostato');
});

it('Prospetto (la quota oltre la successione agli eredi) — Anna vende a Ugo, Ugo muore (Bruno erede), Luca entra il 1/12: la fermata della vendita scende, e il pannello e il prospetto dicono gli stessi giorni della quota di Anna', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Luca');
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 100, ['Bruno' => 100]));
    $dati = fds50ModuloInizio($s, 'Luca', '2026-12-01');
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano: la fermata della vendita è la metà comprata dei 92 giorni dal 1/10, 18148 × 50 × 92/18400 = 4537, a carico di Anna; dal 15/10 la riga è di Bruno, con la
    // metà comprata dentro. Luca dal 1/12 prende 31 giorni: della metà comprata restano 50 × (14 + 47) = 3050, 18148 × 3050/18400 = 3008,23 → 3008 (la quota al centesimo più
    // vicino). Prima la fermata non scendeva: la quota non si seguiva nella riga di Bruno. Luca 18148 × 3100/18400 = 3057,54 → 3058, pagati metà da Ugo (la sua riga della
    // coppia, ora di Bruno) e metà da Anna: 1529 e 1529.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 4537, 'prima' => 4537, 'residuo' => 3008, 'parti' => ['Ugo' => 3008]]])
        ->and(collect($pannello['rate']['giorni_del_conduttore'])->pluck('pagato_da')->all())->toBe([['Ugo', 'Anna']]);

    fer55Passo($this, $s, $dati);
    $luca = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Luca');
    expect(collect($luca['voci'])->map(fn ($v) => [(int) $v['importo'], $v['pagato_da']])->all())->toBe([[1529, 'Ugo'], [1529, 'Anna']]);
});

it('Prospetto (la quota oltre la successione a due eredi) — Anna vende a Ugo, Ugo muore e lascia a Bruno 25 e Dora 75: la metà comprata da Anna si segue nelle righe di tutti e due, in proporzione', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 100, ['Bruno' => 25, 'Dora' => 75]));

    $stampa = fclStampa($s);

    // Conto a mano: da ottobre la coppia è 181,48 × 92/184 = 90,74. La metà comprata da Anna (50 della riga di Ugo) passa agli eredi con la riga, 12,5 a Bruno e 37,5 a Dora:
    // i pesi sono 50 × 14 + (12,5 + 37,5) × 78 = 4600 per la quota e altrettanti per Ugo, € 45,37 e € 45,37. Con la quota tutta alla prima riga, quella di Bruno da 25, la
    // quota peserebbe 50 × 14 + 25 × 78 = 2650: € 26,14 ad Anna.
    expect(preg_match_all('/Spese generali 92 giorni € 45,37 (.*?)(?= (?:Spese generali|Totale) )/u', $stampa, $m))->toBe(2)
        ->and($m[1])->toContain('pagata da Anna con il conguaglio della fine locazione del 01/07/2026, o da Ugo, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/10/2026 non ha spostato');
});

it('Prospetto (la successione a nome del defunto) — Anna vende a Ugo, Ugo muore con l\'arretrato a nome suo e «Scrivi», Luca entra il 1/12: la quota non si segue nella riga dell\'erede, che ha la sua fermata, e scende solo quella', function () {
    // Con l'arretrato a nome del defunto la successione di Ugo ha la sua fermata verso Bruno: 18148 × 100 × 78/18400 = 7693,17 → 7693 (i resti di Ugo, ,83, e di Bruno, ,17: il
    // centesimo a Ugo), per quota della riga di Ugo, metà sua e metà comprata da Anna: 3847 a carico di Ugo, 3846 a carico di Anna, o di Ugo se la vendita è regolata. Luca dal
    // 1/12 lascia a Bruno 47 giorni, 4635,63 → 4636 (i due centesimi a Ugo e a Bruno): scende la fermata della successione, da 7693 a 4636. Quella della vendita no: i giorni
    // di Ugo, fino al 14/10, Luca non li tocca, e la quota nella riga di Bruno è già della fermata della successione.
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Luca');
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 50));
    $successione = fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 100, ['Bruno' => 100], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO));
    expect(array_map(fn ($f) => [$f['persone'] ?? [$f['persona']], (int) $f['cifra']], fds50Fermate($successione)))->toBe([[['Ugo'], 3847], [['Anna'], 3846]]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'));

    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la successione del 15 ottobre 2026', 'cifra' => 7693, 'prima' => 7693, 'residuo' => 4636, 'parti' => ['Bruno' => 4636]]]);
});
