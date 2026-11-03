<?php

/**
 * 1.11.0-beta.53 (Coda 244, decisione 100, punto 2) — i fatti dei saldi del Wallet legati a un passaggio nei casi che una strada calcolata per
 * combinazione non diceva bene: chi ha solo righe a zero, chi non ha righe, le righe di un esercizio che non è quello aperto, nessun esercizio
 * aperto, la fermata ridotta da un secondo inizio dopo la fine del conduttore.
 *
 * La frase dice, per ogni persona, quanto sommano oggi le sue righe legate (anche a zero) e quanto vale la sua parte; chi non ha righe e ha una
 * parte si nomina. Seguita dalle rotte (`sl53SeguiIFatti`: chi ha una riga che si modifica la corregge, chi non ne ha registra la differenza),
 * nessuna rotta rifiuta e le righe sommano la parte di ognuno. Senza un esercizio aperto la frase non propone il Wallet, che non si apre.
 *
 * Fonte dell'atteso: le cifre dei casi della fermata dopo «Scrivi» (`Beta53SaldoLegatoScenari`, `Beta51PassaggiParteCheRisaleTest`) e il conto a
 * mano scritto in ogni test.
 *
 * Che cosa NON copre: MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

/** Un piano «Rate di fine anno» che applica i saldi: assorbe le righe libere della gestione ed emette, e le righe restano ferme. */
function sl53hPianoEmesso($test, array $s): \App\Models\Gestionale\PianoRate
{
    $p = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Rate di fine anno', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-11-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    fds50Genera($s, $p);
    fds50Emetti($test, $s, '2026-12-31', $p);

    return $p;
}

/** Il caso INT fino alla vendita del 1/10 (€ 90,74 verso Elsa), senza righe legate. */
function sl53hVendita($test): array
{
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    fclPersona($s, 'Marco');
    fclPersona($s, 'Carlo');
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine_ines'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();
    $s['vendita'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    $s['cifra'] = (int) (fds50Fermate($s['vendita'])[0]['cifra'] ?? 0);
    expect($s['cifra'])->toBe(9074);
    $test->travel(1)->minutes();

    return $s;
}

/** La riga legata della persona che si modifica (non ferma), portata alla cifra dalla rotta del Wallet. */
function sl53hModifica($test, array $s, Subentro $passaggio, string $chi, int $cents): array
{
    $riga = Saldo::with('pianoRate')->where('passaggio_id', $passaggio->id)->where('anagrafica_id', $s['persone'][$chi]->id)->get()->reject(fn (Saldo $x) => $x->eBloccato())->sole();

    return sl53Errori($test->actingAs($test->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => $cents, 'gestione_id' => $s['g']->id]));
}

/** La frase di una voce del pannello che nomina il Wallet, con le parole date. */
function sl53hFrase(array $anteprima, string $con): string
{
    return (string) collect(fds50Testi($anteprima))->first(fn (string $t) => str_contains($t, $con) && str_contains($t, 'Wallet'));
}

/** L'avviso dell'annullamento del passaggio che contiene le parole date, dallo storico. */
function sl53hAvviso(array $s, Subentro $passaggio, string $con): string
{
    $storico = fds50Storico($s, $passaggio->fresh());
    expect($storico['annullabile']['motivo'] ?? null)->toBeNull();

    return (string) collect($storico['annullabile']['avvisi'] ?? [])->first(fn (string $t) => str_contains($t, $con));
}

/**
 * La vendita del 1/11 (fermata € 60,16), la riga di Ugo ferma in un piano emesso, quella di Elsa libera; l'inizio di Luca il 1/11 azzera la
 * fermata. Restituisce lo scenario e la frase dell'inizio, prima di registrarlo.
 */
function sl53hAzzerata($test): array
{
    $s = sl53Scenario($test);
    expect($s['cifra'])->toBe(6016);
    expect(sl53Errori(sl53Posta($test, $s, 'Ugo', -6016, (int) $s['vendita']->id)))->toBe([]);
    sl53hPianoEmesso($test, $s);
    $test->travel(1)->minutes();
    expect(sl53Errori(sl53Posta($test, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    $test->travel(1)->minutes();

    return [$s, sl53hFrase(fds50Anteprima($test, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01')), 'Con questo inizio')];
}

it('l\'inizio che azzera la fermata, con la riga di Ugo ferma e quella di Elsa libera: la frase dice la parte che resta, € 0,00, e le due strade; seguita, le righe sommano zero; l\'annullamento dell\'inizio nomina Elsa, che ha la riga a € 0,00; seguito, le righe tornano a € 60,16', function () {
    [$s, $frase] = sl53hAzzerata($this);
    expect($frase)->toEndWith(' I saldi del Wallet legati alla vendita del 1 novembre 2026 sommano oggi € 60,16 a credito di Ugo e € 60,16 a debito di Elsa; la parte che resta è € 0,00. Non si modifica più quello nel piano «Rate di fine anno», che non si riscrive più (€ 60,16 a credito di Ugo). Il programma non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla vendita del 1 novembre 2026.');

    // Seguita: la riga di Elsa a zero dal Wallet, la differenza di Ugo (+6016) dalla porta.
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => 0, 'Elsa' => 0], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 0, 'Ugo' => 0]);
    $this->travel(1)->minutes();

    // L'annullamento dell'inizio: Elsa ha solo la riga a € 0,00, e la frase la nomina con la sua parte.
    $avviso = sl53hAvviso($s, $inizio, 'è scesa');
    expect($avviso)->toEndWith(' I saldi del Wallet legati alla vendita del 1 novembre 2026 sommano oggi € 0,00 per Ugo e € 0,00 per Elsa; la parte che torna è € 60,16 a credito di Ugo e € 60,16 a debito di Elsa. Non si modifica più quello nel piano «Rate di fine anno», che non si riscrive più (€ 60,16 a credito di Ugo). Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano.');
    expect(sl53Errori(sl53Annulla($this, $s, $inizio, 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6016, 'Elsa' => 6016], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016, 'Ugo' => -6016]);
});

it('l\'inizio che azzera la fermata, con la riga di Elsa eliminata: l\'annullamento dell\'inizio dice che Elsa non ha righe e la sua parte; seguito, la porta accetta la sua e le righe tornano a € 60,16', function () {
    [$s, $frase] = sl53hAzzerata($this);
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    $this->travel(1)->minutes();
    // La frase dell'inizio, con la riga di Elsa eliminata invece che portata a zero, e la differenza di Ugo dalla porta.
    $elsa = Saldo::where('passaggio_id', $s['vendita']->id)->where('anagrafica_id', $s['persone']['Elsa']->id)->sole();
    expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s['c'], $elsa]))))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => 0], $frase))->toBe([]);
    $this->travel(1)->minutes();

    $avviso = sl53hAvviso($s, $inizio, 'è scesa');
    expect($avviso)->toEndWith(' I saldi del Wallet legati alla vendita del 1 novembre 2026 sommano oggi € 0,00 per Ugo, e Elsa non ne ha; la parte che torna è € 60,16 a credito di Ugo e € 60,16 a debito di Elsa. Non si modifica più quello nel piano «Rate di fine anno», che non si riscrive più (€ 60,16 a credito di Ugo). Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla vendita del 1 novembre 2026.');
    expect(sl53Errori(sl53Annulla($this, $s, $inizio, 'Registrato per sbaglio, lo rifaccio dopo')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6016, 'Elsa' => 6016], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016, 'Ugo' => -6016]);
});

it('l\'inizio di Luca con la riga di Ugo ferma e quella di Elsa portata a € 0,00: la frase nomina Elsa, € 0,00 oggi e la sua parte, e le due strade; seguita, la porta accetta Ugo, il Wallet corregge Elsa, e le righe sommano € 30,58', function () {
    $s = sl53hVendita($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -9074, (int) $s['vendita']->id)))->toBe([]);
    sl53hPianoEmesso($this, $s);
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 9074, (int) $s['vendita']->id)))->toBe([])
        ->and(sl53hModifica($this, $s, $s['vendita'], 'Elsa', 0))->toBe([]);
    $this->travel(1)->minutes();

    $frase = sl53hFrase(fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01')), 'Con questo inizio');
    expect($frase)->toEndWith(' I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 90,74 a credito di Ugo e € 0,00 per Elsa; la parte che resta è € 30,58 a credito di Ugo e € 30,58 a debito di Elsa. Non si modifica più quello nel piano «Rate di fine anno», che non si riscrive più (€ 90,74 a credito di Ugo). Il programma non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato alla vendita del 1 ottobre 2026.');

    // Seguita. Conto a mano: Ugo −9074 + 6016 = −3058 dalla porta; la riga di Elsa da 0 a 3058.
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});

it('la parte che risale con le righe di Ugo già alla sua parte e Elsa senza righe: la frase nomina Elsa e la sua parte nuova; seguita, la porta la accetta e le righe sommano € 61,15', function () {
    $s = sl53hVendita($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -9074, (int) $s['vendita']->id)))->toBe([]);
    sl53hPianoEmesso($this, $s);
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', 2959, (int) $s['vendita']->id)))->toBe([]);
    $this->travel(1)->minutes();

    $frase = (string) collect(fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-01'))['rate']['frasi'])->first(fn (string $t) => str_contains($t, 'sale da'));
    expect($frase)->toEndWith(' I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 61,15 a credito di Ugo, e Elsa non ne ha; la parte nuova è € 61,15 a credito di Ugo e € 61,15 a debito di Elsa. Non si modifica più quello nel piano «Rate di fine anno», che non si riscrive più (€ 90,74 a credito di Ugo). Il programma non li cambia: registra dal Wallet la differenza con un saldo legato alla vendita del 1 ottobre 2026.');

    fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
});

it('l\'inizio che azzera la fermata, con le righe ferme di ognuno e le altre a € 0,00: la frase dice la parte che resta, € 0,00, e di correggere dal Wallet; seguita, le righe sommano zero', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    sl53hPianoEmesso($this, $s);
    $this->travel(1)->minutes();
    foreach ([['Ugo', 100], ['Elsa', -100]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([])
            ->and(sl53hModifica($this, $s, $s['vendita'], $chi, 0))->toBe([]);
    }
    $this->travel(1)->minutes();

    $frase = sl53hFrase(fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01')), 'Con questo inizio');
    expect($frase)->toEndWith(' I saldi del Wallet legati alla vendita del 1 novembre 2026 sommano oggi € 60,16 a credito di Ugo e € 60,16 a debito di Elsa; la parte che resta è € 0,00. Non si modificano più quelli nel piano «Rate di fine anno», che non si riscrive più (€ 60,16 a credito di Ugo, € 60,16 a debito di Elsa). Il programma non li cambia: correggi dal Wallet i saldi che si modificano.')
        ->and($frase)->not->toContain('si registrano');

    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => 0, 'Elsa' => 0], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 0, 'Ugo' => 0]);
});

/** Il 2026 si chiude e si apre il 2027 con la stessa gestione; con `$apri` falso nessun esercizio resta aperto. */
function sl53hChiudiIl2026(array $s, bool $apri = true): void
{
    $s['e']->update(['stato' => 'chiuso']);
    if ($apri) {
        $nuovo = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
        legaAEsercizio($nuovo, $s['g']->id);
    }
}

it('le righe libere nel 2026 chiuso, la parte che risale nel 2027: la frase dice di registrare la differenza; seguita; poi l\'annullamento della fine dice di correggere le righe del 2027; seguito, le righe tornano a € 30,58', function () {
    $s = sl53hVendita($this);
    sl53DueLegate($this, $s);
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    foreach (['Ugo' => -3058, 'Elsa' => 3058] as $chi => $cents) {
        expect(sl53hModifica($this, $s, $s['vendita'], $chi, $cents))->toBe([]);
    }
    sl53hChiudiIl2026($s);
    $this->travel(1)->minutes();

    $frase = (string) collect(fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-01'))['rate']['frasi'])->first(fn (string $t) => str_contains($t, 'sale da'));
    expect($frase)->toEndWith(' I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 30,58 a credito di Ugo e € 30,58 a debito di Elsa; la parte nuova è € 61,15 a credito di Ugo e € 61,15 a debito di Elsa. Non si modificano più quelli nell\'esercizio «Esercizio 2026», che è chiuso (€ 30,58 a credito di Ugo, € 30,58 a debito di Elsa). Il programma non li cambia: registra dal Wallet la differenza con un saldo legato alla vendita del 1 ottobre 2026.');
    $fine = fds50Registra($this, $s, fds50ModuloFine($s, Subentro::NON_SCRIVERE_IL_CONGUAGLIO, '2026-12-01', 'Luca'))['subentro'];
    $this->travel(1)->minutes();
    // Conto a mano: −6115 + 3058 = −3057 per Ugo, 3057 per Elsa, nel 2027.
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -6115, 'Elsa' => 6115], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
    $this->travel(1)->minutes();

    $avviso = sl53hAvviso($s, $fine, 'è risalita');
    expect($avviso)->toEndWith(' I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 61,15 a credito di Ugo e € 61,15 a debito di Elsa; la parte che torna è € 30,58 a credito di Ugo e € 30,58 a debito di Elsa. Non si modificano più quelli nell\'esercizio «Esercizio 2026», che è chiuso (€ 30,58 a credito di Ugo, € 30,58 a debito di Elsa). Annullare il passaggio non li cambia: correggi dal Wallet i saldi che si modificano.');
    expect(sl53Errori(sl53Annulla($this, $s, $fine, 'Registrata per sbaglio, la locazione continua')))->toBe([]);
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $avviso))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});

it('la successione ad Anna e Bruno con le righe libere nel 2026 chiuso: la parte che risale dice la parte nuova di ognuno e di registrare la differenza; seguita nel 2027, le righe sommano € 61,15', function () {
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    $s['fine_ines'] = fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    $this->travel(1)->minutes();
    $s['successione'] = fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-10-01'))['subentro'];
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    foreach ([['Ugo', -3058], ['Anna', 1529], ['Bruno', 1529]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['successione']->id)))->toBe([]);
    }
    sl53hChiudiIl2026($s);
    $this->travel(1)->minutes();

    $frase = (string) collect(fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-01'))['rate']['frasi'])->first(fn (string $t) => str_contains($t, 'sale da'));
    expect($frase)->toEndWith(' I saldi del Wallet legati alla successione del 1 ottobre 2026 sommano oggi € 30,58 a credito di Ugo, € 15,29 a debito di Anna e € 15,29 a debito di Bruno; la parte nuova è € 61,15 a credito di Ugo, € 30,58 a debito di Anna e € 30,57 a debito di Bruno. Non si modificano più quelli nell\'esercizio «Esercizio 2026», che è chiuso (€ 30,58 a credito di Ugo, € 15,29 a debito di Anna, € 15,29 a debito di Bruno). Il programma non li cambia: registra dal Wallet la differenza con un saldo legato alla successione del 1 ottobre 2026.');
    fds50Registra($this, $s, fds50ModuloFine($s, Subentro::NON_SCRIVERE_IL_CONGUAGLIO, '2026-12-01', 'Luca'));
    $this->travel(1)->minutes();
    // Conto a mano: Ugo −3058 − 3057 = −6115, Anna 1529 + 1529 = 3058, Bruno 1529 + 1528 = 3057.
    expect(sl53SeguiIFatti($this, $s, $s['successione'], ['Ugo' => -6115, 'Anna' => 3058, 'Bruno' => 3057], $frase))->toBe([]);
    expect(sl53Legate($s, $s['successione']))->toBe(['Anna' => 3058, 'Bruno' => 3057, 'Ugo' => -6115]);
});

it('nessun esercizio aperto: la frase non propone il Wallet, che non si apre, e dice di aprirne uno; aperto il 2027, la frase dice di registrare la differenza, e seguita le righe sommano € 30,58', function () {
    $s = sl53hVendita($this);
    sl53DueLegate($this, $s);
    $this->travel(1)->minutes();
    sl53hChiudiIl2026($s, apri: false);

    $frase = sl53hFrase(fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01')), 'Con questo inizio');
    expect($frase)->toEndWith(' I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 90,74 a credito di Ugo e € 90,74 a debito di Elsa; la parte che resta è € 30,58 a credito di Ugo e € 30,58 a debito di Elsa. Non si modificano più quelli nell\'esercizio «Esercizio 2026», che è chiuso (€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa). Oggi non c\'è un esercizio aperto, e la pagina dei saldi corregge e registra solo nell\'esercizio aperto: aprine uno prima.')
        ->and($frase)->not->toContain('la differenza con un saldo legato');
    // Che cosa dice la pagina: la porta rifiuta, la pagina dei saldi non si apre.
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', 6016, (int) $s['vendita']->id)))->not->toBe([]);

    // Aperto il 2027, la frase indica la porta, e seguita le righe sommano la parte che resta.
    $nuovo = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($nuovo, $s['g']->id);
    $frase = sl53hFrase(fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01')), 'Con questo inizio');
    expect($frase)->toEndWith(' Il programma non li cambia: registra dal Wallet la differenza con un saldo legato alla vendita del 1 ottobre 2026.');
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -3058, 'Elsa' => 3058], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 3058, 'Ugo' => -3058]);
});

it('dopo la fine di Luca seguita, l\'inizio di Marco il 15/12 riduce di nuovo la fermata: la frase dice la parte che resta di ognuno; seguita, le righe sommano quella parte', function () {
    $s = sl53hVendita($this);
    sl53DueLegate($this, $s);
    sl53hPianoEmesso($this, $s);
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    foreach ([['Ugo', 6016], ['Elsa', -6016]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }
    $this->travel(1)->minutes();
    fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'));
    $this->travel(1)->minutes();
    expect(sl53hModifica($this, $s, $s['vendita'], 'Ugo', 2959))->toBe([])
        ->and(sl53hModifica($this, $s, $s['vendita'], 'Elsa', -2959))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6115, 'Ugo' => -6115]);
    $this->travel(1)->minutes();

    $a = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-12-15'));
    $voce = collect($a['rate']['fermate_ridotte'] ?? [])->first();
    $resta = (int) ($voce['residuo'] ?? 0);
    expect($resta)->toBeGreaterThan(0)->toBeLessThan(6115);
    $frase = sl53hFrase($a, 'Con questo inizio');
    $cifra = \App\Helpers\MoneyHelper::format($resta);
    expect($frase)->toEndWith(sprintf(' I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 61,15 a credito di Ugo e € 61,15 a debito di Elsa; la parte che resta è %s a credito di Ugo e %s a debito di Elsa. Non si modificano più quelli nel piano «Rate di fine anno», che non si riscrive più (€ 90,74 a credito di Ugo, € 90,74 a debito di Elsa). Il programma non li cambia: correggi dal Wallet i saldi che si modificano.', $cifra, $cifra));

    fds50Registra($this, $s, fds50ModuloInizio($s, 'Marco', '2026-12-15'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita'], ['Ugo' => -$resta, 'Elsa' => $resta], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => $resta, 'Ugo' => -$resta]);
});

it('due vendite sulle stesse persone ridotte in una voce sola: la frase nomina tutte e due le vendite e la parte di chi resta a carico insieme; seguita, le righe sommano la parte che resta', function () {
    // Ugo e Anna al 50 % vendono a Elsa il 12/3 e il 1/4 (fermate € 153,33 e € 142,94); l'inizio di Marco del 1/6 le riduce in una voce sola. Le
    // righe legate sono della vendita di Anna.
    $s = b51aDl7($this, ['titolari' => [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], 'senzaLuca' => true]);
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12', 50);
    $s['vendita_anna'] = b51aVendi($this, $s, 'Anna', 'Elsa', '2026-04-01', 50);
    foreach ([['Anna', -14294], ['Elsa', 14294]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita_anna']->id)))->toBe([]);
    }

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-06-01'));
    expect((int) ($a['rate']['fermate_ridotte'][0]['residuo'] ?? 0))->toBe(7381);
    $frase = sl53hFrase($a, 'Con questo inizio');
    expect($frase)->toEndWith(' I saldi del Wallet legati alla vendita del 12 marzo 2026 e alla vendita del 1 aprile 2026 sommano oggi € 142,94 a credito di Anna e € 142,94 a debito di Elsa, e Ugo non ne ha; la parte che resta è € 73,81 a credito di Ugo e Anna insieme e € 73,81 a debito di Elsa. Il programma non li cambia: correggi dal Wallet i saldi che si modificano, e per chi non ne ha uno registra la differenza con un saldo legato a uno di quei passaggi.');

    // Seguita, con la divisione fra Ugo e Anna che decide l'amministratore (qui tutta ad Anna): le righe libere di Anna e di Elsa si correggono.
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Marco', '2026-06-01'));
    $this->travel(1)->minutes();
    expect(sl53SeguiIFatti($this, $s, $s['vendita_anna'], ['Anna' => -7381, 'Elsa' => 7381], $frase))->toBe([]);
    expect(sl53Legate($s, $s['vendita_anna']))->toBe(['Anna' => -7381, 'Elsa' => 7381]);
});
