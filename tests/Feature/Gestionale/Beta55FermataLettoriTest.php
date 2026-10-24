<?php

/**
 * 1.11.0-beta.55 (Coda 245, famiglia delle fermate) — i lettori degli stati che le Code 354 e 356 scrivono nel registro, dalle rotte della pagina: l'avviso
 * dell'annullamento di una successione che ha ridotto o azzerato una fermata, il prospetto che la legge per dire chi ha pagato i giorni dopo la successione, e
 * l'annullamento di una rivendita con due voci.
 *
 * I CONTI A MANO (centesimi; la coppia di Ines 18148 sui 184 giorni dal 1/7: 36000 × 184/365 = 18147,95 → 18148; Ugo proprietario al 100 %):
 * - Ugo vende a Elsa il 1/10: la fermata sui 92 giorni dal 1/10, 18148 × 92/184 = 9074, a carico di Ugo. Elsa muore il 15/10 e Ugo è il suo erede: i giorni di Elsa
 *   fino al decesso sono 14 (dal 1/10 al 14/10), 18148 × 14/184 = 1380,83 → 1381; quelli dopo, 78, 18148 × 78/184 = 7693,17 → 7693 (1381 + 7693 = 9074).
 *   - Con l'arretrato agli eredi la posizione di Elsa passa a Ugo: la fermata scende da 9074 a 0 («da € 90,74 a € 0,00»).
 *   - Con l'arretrato a nome della defunta e «Scrivi» restano a carico di Elsa i suoi 14 giorni: la fermata scende da 9074 a 1381 («da € 90,74 a € 13,81»).
 *   Nel prospetto i giorni senza conduttore sono i 92 prima del 1/10 e i 78 dopo il 14/10, 170 giorni, 18148 × 170/184 = 16767,17 → 16767 («€ 167,67»), pagati da Ugo, e i
 *   14 giorni di Elsa, 1381 («€ 13,81»), pagati da Ugo, o da Elsa se le parti hanno regolato fra loro la fermata della vendita.
 * - La rivendita con due voci: Bruno 50 % proprietario, Ugo nudo e Zeno usufruttuario al 50 % (la coppia: Bruno 9074 e Zeno 9074); la nuda di Ugo passa a Bruno il 1/10 e
 *   l'usufrutto di Zeno si estingue lo stesso giorno (la fermata di Zeno, 18148 × 92/184 × 50/100 = 4537); Bruno rivende tutto a Carlo il 1/12, 31 giorni: 18148 × 3100/18400
 *   = 3057,54 → 3058, 1529 + 1529. Annullata la rivendita, le righe del Wallet legate se ne vanno con lei e la fermata dell'estinzione torna a 4537 («€ 45,37»).
 *
 * Che cosa NON copre: le cifre della fermata (`Beta55FermataRicompraTest`, `Beta55FermataNudaEstinzioneTest`); MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** Le voci dei giorni senza conduttore della stampa vera del prospetto, ognuna con i giorni, la cifra e il suo «pagata da …». */
function fflRigheSenzaConduttore(string $stampa): array
{
    preg_match_all('/(Spese generali \d+ giorni € [0-9.,]+ .*?)(?= (?:Spese generali|Totale) )/u', $stampa, $m);

    return $m[1];
}

const FFL_PAGATA_DA_UGO = 'pagata da Ugo con il conguaglio della fine locazione del 01/07/2026';
const FFL_O_DA_ELSA = ', o da Elsa, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/10/2026 non ha spostato';

it('Coda 356 (la successione agli eredi, dalla rotta) — Elsa muore il 15/10 e Ugo è l\'erede: l\'avviso dell\'annullamento dice la fermata che scende da € 90,74 a € 0,00; il prospetto chiude l\'alternativa di Elsa ai giorni dopo la successione; annullata, la vendita torna a € 90,74', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $vendita = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    $successione = fer55Passo($this, $s, fer55Successione($s, 'Elsa', 'proprietario', '2026-10-15', 100, ['Ugo' => 100]));

    expect(fds50Storico($s, $successione)['annullabile']['avvisi'])->toBe(['Con questo passaggio la parte verso Elsa della fermata della vendita del 1 ottobre 2026 è scesa da € 90,74 a € 0,00: '
        . 'annullandolo torna da € 0,00 a € 90,74. Se le parti hanno già regolato la differenza, o per questo hai registrato un saldo manuale dal Wallet, rivedilo: annullare il passaggio non lo toglie.']);

    // I 170 giorni dopo la successione e prima della vendita sono di Ugo senza alternative; solo i 14 di Elsa dicono «o da Elsa».
    expect(fflRigheSenzaConduttore(fclStampa($s)))->toBe([
        'Spese generali 170 giorni € 167,67 ' . FFL_PAGATA_DA_UGO,
        'Spese generali 14 giorni € 13,81 ' . FFL_PAGATA_DA_UGO . FFL_O_DA_ELSA,
    ]);

    expect(sl53Annulla($this, $s, $successione)->status())->toBe(302)
        ->and(fer55Storico($s, $vendita))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 90,74 dei giorni dal 1 ottobre 2026 sono rimasti a carico di Ugo '
            . 'sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Elsa.');
});

it('Coda 356 (la successione a nome della defunta, dalla rotta) — con «Scrivi» restano a Elsa i suoi 14 giorni: l\'avviso dell\'annullamento dice da € 90,74 a € 13,81, e il prospetto è quello di prima', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $vendita = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    $successione = fer55Passo($this, $s, fer55Successione($s, 'Elsa', 'proprietario', '2026-10-15', 100, ['Ugo' => 100], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO));

    expect(fds50Storico($s, $successione)['annullabile']['avvisi'])->toBe(['Con questo passaggio la parte verso Elsa della fermata della vendita del 1 ottobre 2026 è scesa da € 90,74 a € 13,81: '
        . 'annullandolo torna da € 13,81 a € 90,74. Se le parti hanno già regolato la differenza, o per questo hai registrato un saldo manuale dal Wallet, rivedilo: annullare il passaggio non lo toglie.'])
        ->and(fflRigheSenzaConduttore(fclStampa($s)))->toBe([
            'Spese generali 170 giorni € 167,67 ' . FFL_PAGATA_DA_UGO,
            'Spese generali 14 giorni € 13,81 ' . FFL_PAGATA_DA_UGO . FFL_O_DA_ELSA,
        ])
        ->and(fer55Storico($s, $vendita))->toEndWith('; con la successione del 15 ottobre 2026 i giorni dal 15 ottobre 2026 sono passati a Ugo, e ne restano da regolare € 13,81.');
});

it('Coda 354 (annullare la rivendita con due voci, dalla rotta) — le righe del Wallet legate alla rivendita se ne vanno con lei, e lo storico dell\'estinzione torna a € 45,37', function () {
    $s = fer55Base($this, [['Bruno', 'proprietario', 50], ['Ugo', 'nuda_proprietario', 50], ['Zeno', 'usufruttuario', 50]]);
    fer55Passo($this, $s, ['tipo' => 'vendita', 'tipologia' => 'nuda_proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'nuda_proprietario'),
        'anagrafica_entrante_id' => $s['persone']['Bruno']->id, 'decorrenza' => '2026-10-01', 'quota' => 50, 'copia_autentica' => false,
        'estremi_titolo' => 'rogito notaio Verdi, rep. 4321', 'pertinenze' => []]);
    $estinzione = fer55Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-10-01'));
    $rivendita = fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100));
    expect(sl53Errori(sl53Posta($this, $s, 'Bruno', -1529, (int) $rivendita->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Carlo', 1529, (int) $rivendita->id)))->toBe([])
        ->and(count(sl53Legate($s, $rivendita)))->toBe(2)
        // Prima dell'annullamento la fermata dell'estinzione dice che € 15,29 sono passati a Carlo con la rivendita.
        ->and(fer55Storico($s, $estinzione))->toEndWith('di questi, € 15,29 sono passati a Carlo con la vendita del 1 dicembre 2026.');

    expect(sl53Annulla($this, $s, $rivendita)->status())->toBe(302)
        ->and(sl53Legate($s, $rivendita))->toBe([])
        ->and(fer55Storico($s, $estinzione))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 45,37 dei giorni dal 1 ottobre 2026 sono rimasti '
            . 'a carico di Zeno sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Bruno.');
});
