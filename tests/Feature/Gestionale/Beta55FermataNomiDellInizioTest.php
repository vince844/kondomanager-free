<?php

/**
 * 1.11.0-beta.55 — nelle frasi dell'inizio di un conduttore, dopo una fine «Scrivi» e le fermate dei passaggi dopo, i nomi di chi ha pagato i giorni dopo l'uscita si dicono per
 * persona, una volta sola, e chi è morto con l'arretrato agli eredi si dice con i suoi eredi, che hanno la sua riga.
 *
 * Il pannello dell'inizio nomina chi ha preso i giorni dopo l'uscita di Ines con il conguaglio e chi li rimborsa Luca. I nomi venivano dai pezzi del prospetto, uno per ogni
 * momento in cui chi pagava al posto dell'inquilino è cambiato, e un pezzo con più persone era un nome solo («Ugo e Anna»): con la seconda vendita a Ugo la frase diceva «Ugo e
 * Anna e Ugo», e con Ugo morto agli eredi «Zeno e Ugo», una persona che non ha più la riga.
 *
 * Fonte dell'atteso: il conto a mano delle persone, che non dipende dalle cifre. Ugo 75 % e Anna 25 %, la fine di Ines il 1/7 con «Scrivi»: la coppia la prendono Ugo e Anna.
 * Anna vende a Elsa il 1/10 e Elsa a Ugo il 1/11: dal 1/11 i giorni li ha pagati Ugo, e Luca (dal 15/12) li rimborsa a lui. L'unità mista (Ugo pieno della metà A e nudo della metà B,
 * Zeno usufruttuario della metà B): la coppia la prendono Ugo e Zeno; Ugo vende la metà piena a Elsa (1/10), muore il 15/10 (la nuda ai suoi eredi Bruno e Dora, con l'arretrato
 * agli eredi) e Elsa vende a Bruno (1/11): la riga della coppia di Ugo è di Bruno e di Dora.
 *
 * Coda 352 (1.11.0-beta.55): dopo una fermata i giorni della quota che il passaggio non ha spostato li ha pagati chi è rimasto a carico della fermata. Dei giorni di Luca
 * dopo la seconda vendita a Ugo, il 25 % della quota comprata da Elsa sta nella riga di Anna: Luca li rimborsa a Ugo e ad Anna (`Beta55FermataChiHaPagatoTest`, le cifre).
 *
 * Che cosa NON copre: il prospetto stampato e le cifre di chi ha pagato i giorni dopo una fermata (`Beta55FermataChiHaPagatoTest`); MySQL.
 */

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** La frase dell'inizio che dice chi ha preso i giorni dopo l'uscita di Ines e chi li rimborsa Luca, senza il resto del paragrafo (che nomina anche chi ha le quote emesse). */
function fer55FraseDeiPagatori(array $pannello): string
{
    preg_match('/i giorni dopo la sua uscita .*?, con il prospetto degli oneri accessori\./u', fer55Frase($pannello, 'La parte a carico dell\'inquilino dal 15 dicembre 2026'), $m);

    return $m[0] ?? '';
}

it('Frasi dell\'inizio (un nome una volta sola) — la seconda vendita a Ugo: i giorni dopo l\'uscita li hanno presi Ugo e Anna, non «Ugo e Anna e Ugo», e li rimborsa Luca a Ugo e Anna', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 25));

    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // Fino al 31/10 ha pagato la coppia Ugo e Anna; da novembre Ugo la sua parte (75) e Anna la quota che Elsa ha venduto a Ugo con la fermata (25, nella riga di Anna:
    // Coda 352). Prima la frase diceva «… e quelli dal giorno dell'inizio li rimborsa Luca a Ugo», e Ugo prendeva anche i € 4,19 della quota.
    expect(fer55FraseDeiPagatori($pannello))->toBe('i giorni dopo la sua uscita li hanno presi con il conguaglio Ugo e Anna, e li rimborsa Luca a Ugo e Anna, con il prospetto degli oneri accessori.');
    fer55SenzaNomiMancanti($pannello);
});

it('Frasi dell\'inizio (gli eredi al posto del defunto) — Ugo muore agli eredi e Elsa vende a Bruno: i giorni li hanno presi Zeno, Bruno e Dora, e li rimborsa Luca a loro, non a Ugo', function () {
    $s = fer55Mista($this);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 25, 'Dora' => 25]));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50));

    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // La riga della coppia di Ugo (9074) è passata ai suoi eredi con la successione; quella di Zeno (9074) è rimasta a lui.
    expect(fer55FraseDeiPagatori($pannello))->toBe('i giorni dopo la sua uscita li hanno presi con il conguaglio Zeno, Bruno e Dora, e li rimborsa Luca a Zeno, Bruno e Dora, con il prospetto degli oneri accessori.');
    fer55SenzaNomiMancanti($pannello);
});

it('Frasi dell\'inizio (la riga resta al defunto) — con l\'arretrato a nome del defunto Ugo ha ancora la sua riga, e la frase lo nomina con Anna, al plurale', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 75], \App\Models\Gestionale\Subentro::ARRETRATO_AL_DEFUNTO,
        \App\Models\Gestionale\Subentro::NON_SCRIVERE_IL_CONGUAGLIO));

    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // La riga della coppia di Ugo (13611) non è passata a nessuno: è ancora sua. Sono due persone, e il verbo è al plurale (prima: «li ha presi … Ugo e Anna»).
    expect(fer55FraseDeiPagatori($pannello))->toBe('i giorni dopo la sua uscita li hanno presi con il conguaglio Ugo e Anna, e li rimborsa Luca a Ugo e Anna, con il prospetto degli oneri accessori.');
    fer55SenzaNomiMancanti($pannello);
});

it('Frasi dell\'inizio (una persona sola) — un proprietario unico: «li ha presi … Ugo», come prima', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);

    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    expect(fer55FraseDeiPagatori($pannello))->toBe('i giorni dopo la sua uscita li ha presi con il conguaglio Ugo, e li rimborsa Luca a Ugo, con il prospetto degli oneri accessori.');
    fer55SenzaNomiMancanti($pannello);
});
