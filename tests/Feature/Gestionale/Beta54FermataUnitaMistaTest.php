<?php

/**
 * 1.11.0-beta.54 (Coda 340) — la fermata dopo «Scrivi» quando il passaggio rende mista l'unità, o la lascia mista.
 *
 * Ugo e Anna al 50 %, la fine di Ines il 1/7 con «Scrivi» (la coppia è 36000 × 184/365 = 18147,95 → 18148: € 90,74 a testa), poi Ugo costituisce a Elsa
 * l'usufrutto della sua metà il 1/10. Dal 1/10 l'unità è mista (decisione 31.1): Elsa usufruttuaria e Anna proprietaria piena dell'altra metà pagano
 * insieme, per le loro quote. La simulazione del passaggio dava alle righe nuove un predecessore a quota zero, che la catena del ripiego vedeva e
 * `UnitaMista` no: per la simulazione l'unità non era mista, Elsa pagava per intero e Anna risultava a credito. Ora `UnitaMista` legge la regola D7 su
 * tutte le righe attive della stessa tipologia, come il resto del motore.
 *
 * La stessa regola chiude le due varianti: la riserva d'usufrutto su un'unità mista (una fermata falsa, € 45,37 «di credito») e l'estinzione dell'usufrutto
 * su un'unità mista (nessuna fermata, € 45,37 restavano a Ugo in silenzio), e le vendite dopo l'estinzione e dopo la costituzione, che leggono la cifra
 * della fermata di prima.
 *
 * Fonte dell'atteso: il conto a mano. Pesi sui 184 giorni della coppia: prima Ugo 92 e Anna 92 (9074 a testa); dopo Ugo 46 (1/7–30/9), Anna 92, Elsa 46
 * (1/10–31/12): 18148 × 46/184 = 4537. La parte di chi riceve è la variazione fra prima e dopo, solo per chi cambia peso: Elsa +4537, Anna invariata. Con
 * il box (900 e 100 millesimi): 16333 e 1815, Elsa 4083 e 454. La vendita di Anna dell'1/11 pesa 50 × 61 giorni: 18148 × 3050/18400 = 3008,23.
 * Con un capitolo di entrata nei giorni della coppia (l'antenna di € 400,00 dal 1/10, 30 % all'inquilino) i pezzi hanno il segno e competenze diverse: la
 * spesa 18148 sui 184 giorni e l'antenna −12000 sui 92 giorni dal 1/10. Prima Ugo e Anna 3074 a testa; dopo Ugo 4537, Anna 9074 − 6000 = 3074, Elsa 4537 − 6000
 * = −1463: Elsa riceve un credito di € 14,63, Anna niente.
 *
 * Che cosa NON copre: due piani con competenze diverse sulla stessa gestione (i pezzi del capitolo di entrata sono di un piano solo); MySQL; i registri già
 * scritti dalle beta .50–.53 con le fermate sbagliate, che non si correggono (il changelog dice come ci si arriva).
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** La base: i titolari dati, Ines inquilina, il preventivo emesso fino al 30/6, la fine di Ines il 1/7 con «Scrivi», emesso fino al 30/9. */
function fum54Base($test, array $titolari, array $persone = ['Dora'], ?callable $prima = null): array
{
    $s = fds50Scenario($titolari);
    foreach ($persone as $nome) {
        if (! isset($s['persone'][$nome])) {
            fclPersona($s, $nome);
        }
    }
    if ($prima !== null) {
        $prima($s);
    }
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();

    return $s;
}

/** Un passaggio dalle rotte vere, e un minuto dopo: il successivo ha una data di creazione più tarda. */
function fum54Passo($test, array $s, array $dati): array
{
    $r = fds50Registra($test, $s, $dati);
    $test->travel(1)->minutes();

    return $r;
}

/** Una fermata in breve: [a carico di, cifra, [chi riceve => cifra]]. */
function fum54Fermata(array $f): array
{
    return [$f['persona'], (int) $f['cifra'], collect($f['parti'])->mapWithKeys(fn ($p) => [$p['nome'] => (int) $p['cifra']])->all()];
}

/** Le fermate del registro del passaggio, in breve. */
function fum54Registro(Subentro $passaggio): array
{
    return array_map('fum54Fermata', fds50Fermate($passaggio->fresh()));
}

/** Le fermate dell'anteprima di un passaggio non ancora registrato, in breve. */
function fum54Anteprima($test, array $s, array $dati): array
{
    return array_map('fum54Fermata', fds50Anteprima($test, $s, $dati)['rate']['fermate_dopo_scrivi'] ?? []);
}

const FUM54_UGO_ANNA = [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]];

it('Coda 340 — Ugo e Anna al 50 %, Ugo costituisce a Elsa l\'usufrutto della sua metà il 1/10: la fermata è € 45,37 a Elsa, a carico di Ugo, e niente ad Anna; seguita la frase, la vendita di Anna dell\'1/11 ferma € 30,08', function () {
    $s = fum54Base($this, FUM54_UGO_ANNA);
    expect(fds50Coppia($s, $s['fine']))->toBe(['Anna' => 9074, 'Ines' => -18148, 'Ugo' => 9074]);

    $r = fum54Passo($this, $s, ['quota' => 50] + fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'));
    // Conto a mano: Elsa +4537, nessuna parte ad Anna (il suo peso resta 92 su 184).
    expect(fum54Registro($r['subentro']))->toBe([['Ugo', 4537, ['Elsa' => 4537]]]);

    $frase = (string) collect(fds50Testi($r['anteprima']))->first(fn (string $t) => str_contains($t, 'Wallet') && str_contains($t, 'Ines'));
    expect($frase)->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'Con questo passaggio la parte di quei giorni dal 1 ottobre 2026 tocca a Elsa, ma il programma non la sposta: € 45,37 restano a carico di Ugo. '
        . 'Se vanno a Elsa, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 45,37 a credito di Ugo e € 45,37 a debito di Elsa. '
        . 'Un saldo legato non occupa il posto di un riporto, e si toglie se annulli il passaggio.');
    expect(fds50Con(fds50Storico($s, $r['subentro']), '45,37'))->toContain('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: '
        . '€ 45,37 dei giorni dal 1 ottobre 2026 sono rimasti a carico di Ugo sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Elsa.');

    // Seguita la frase: le due righe legate, e nessuna rotta rifiuta.
    foreach ([['Ugo', -4537], ['Elsa', 4537]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $r['subentro']->id)))->toBe([]);
    }
    $this->travel(1)->minutes();

    // Anna pesa ancora il 50 %: 18148 × (50 × 61)/(100 × 184) = 3008,23 → Bruno +3008, a carico di Anna.
    expect(fum54Anteprima($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-11-01', 50)))->toBe([['Anna', 3008, ['Bruno' => 3008]]]);
});

it('Coda 340 (guardia) — la costituzione su un\'unità che non è mista (Ugo al 100 %): la fermata è € 90,74 a Elsa, com\'era', function () {
    $s = fum54Base($this, [['Ugo', 'proprietario', 100]]);
    $r = fum54Passo($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'));

    expect(fum54Registro($r['subentro']))->toBe([['Ugo', 9074, ['Elsa' => 9074]]]);
});

it('Coda 340 (riserva) — la riserva d\'usufrutto sulla metà di Ugo (Anna piena sull\'altra): Ugo resta al suo posto per la sua metà, e non c\'è nessuna fermata né nessun motivo del cancello', function () {
    $s = fum54Base($this, FUM54_UGO_ANNA);
    $dati = ['quota' => 50] + fds50ModuloRiserva($s, 'Ugo', 'Elsa', '2026-10-01');
    $anteprima = fds50Anteprima($this, $s, $dati);
    $r = fum54Passo($this, $s, $dati);

    // Conto a mano: dopo la riserva Ugo usufruttuario 50 e Anna 50 pagano come prima (il nudo, Elsa, non paga i giorni): i pesi non cambiano.
    expect(fds50Fermate($r['subentro']))->toBe([])
        ->and($anteprima['rate']['fermate_dopo_scrivi'] ?? [])->toBe([])
        ->and(collect($anteprima['cancello']['motivi'] ?? [])->filter(fn (string $m) => str_contains($m, 'Ines'))->values()->all())->toBe([]);
    // La vendita di Anna dopo la riserva pesa come prima: Bruno +3008.
    expect(fum54Anteprima($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-11-01', 50)))->toBe([['Anna', 3008, ['Bruno' => 3008]]]);
});

it('Coda 340 (estinzione) — l\'estinzione dell\'usufrutto di Ugo su un\'unità mista dal censimento (Anna piena 50, Ugo usufruttuario 50, Elsa nuda 50): la fermata è € 45,37 a Elsa, a carico di Ugo; la vendita di Elsa dopo la dice a carico di Ugo, o di Elsa se la fermata dell\'estinzione è regolata', function () {
    $s = fum54Base($this, [['Anna', 'proprietario', 50], ['Ugo', 'usufruttuario', 50], ['Elsa', 'nuda_proprietario', 50]]);
    expect(fds50Coppia($s, $s['fine']))->toBe(['Anna' => 9074, 'Ines' => -18148, 'Ugo' => 9074]);

    $r = fum54Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Ugo', '2026-10-01'));
    // Conto a mano: prima Anna 92 e Ugo 92; dopo Anna 92, Ugo 46, Elsa 46 (da piena): Elsa +4537, Anna invariata. Ugo tiene € 45,37.
    expect(fum54Registro($r['subentro']))->toBe([['Ugo', 4537, ['Elsa' => 4537]]]);

    // Elsa vende a Bruno l'1/11: 18148 × (50 × 61)/(100 × 184) = 3008,23 → 3008. La parte viene dalla fermata dell'estinzione, a carico di Ugo.
    $f = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50))['rate']['fermate_dopo_scrivi'] ?? [];
    expect(array_map('fum54Fermata', $f))->toBe([['Ugo', 3008, ['Bruno' => 3008]]])
        ->and($f[0]['uscente'] ?? null)->toBe('Elsa')
        ->and($f[0]['dopo_la_fermata'] ?? null)->toBe(['passaggio' => 'l\'estinzione dell\'usufrutto del 1 ottobre 2026', 'cifra' => 4537])
        ->and($f[0]['wallet'] ?? null)->toBeFalse();
});

it('Coda 340 (guardia) — l\'estinzione su un\'unità che non è mista (Ugo usufruttuario 100, Elsa nuda 100): la fermata è € 90,74 a Elsa, com\'era', function () {
    $s = fum54Base($this, [['Ugo', 'usufruttuario', 100], ['Elsa', 'nuda_proprietario', 100]]);
    $r = fum54Passo($this, $s, fds50ModuloEstinzione($s, 'Ugo', '2026-10-01'));

    expect(fum54Registro($r['subentro']))->toBe([['Ugo', 9074, ['Elsa' => 9074]]]);
});

it('Coda 340 (tre passaggi di fila) — la costituzione (€ 45,37), poi Ugo vende la nuda a Dora il 15/10, poi l\'usufrutto di Elsa si estingue il 15/11: Dora riceve € 23,18, e la fermata di prima che l\'alternativa nomina è di € 45,37, non di € 90,74', function () {
    $s = fum54Base($this, FUM54_UGO_ANNA, ['Dora']);
    $p1 = fum54Passo($this, $s, ['quota' => 50] + fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'));
    expect(fum54Registro($p1['subentro']))->toBe([['Ugo', 4537, ['Elsa' => 4537]]]);
    // La nuda proprietà non paga i giorni: la vendita di Ugo a Dora non ferma niente.
    $p2 = fum54Passo($this, $s, ['tipologia' => 'nuda_proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'nuda_proprietario')] + fds50ModuloVendita($s, 'Ugo', 'Dora', '2026-10-15', 50));
    expect(fds50Fermate($p2['subentro']))->toBe([]);

    // Conto a mano: Dora è nuda dal 15/10 e piena dal 15/11, con Elsa usufruttuaria fino al 14/11; dal 15/11 pagano Anna e Dora 50 e 50: Dora ha 50 × 47 giorni
    // (15/11–31/12) su 18400: 18148 × 2350/18400 = 2317,82 → 2318.
    $f = fds50Anteprima($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Elsa', '2026-11-15'))['rate']['fermate_dopo_scrivi'] ?? [];
    expect(array_map('fum54Fermata', $f))->toBe([['Ugo', 2318, ['Dora' => 2318]]])
        ->and($f[0]['uscente'] ?? null)->toBe('Elsa')
        ->and($f[0]['dopo_la_fermata'] ?? null)->toBe(['passaggio' => 'la costituzione d\'usufrutto del 1 ottobre 2026', 'cifra' => 4537]);
});

it('Coda 340 (con il box) — la costituzione con il box (Interno 1 da 900 millesimi, Box 12 da 100, anche il box al 50 % fra Ugo e Anna e locato a Ines): Elsa riceve € 40,83 sull\'Interno e € 4,54 sul box', function () {
    $s = fclScenario(FUM54_UGO_ANNA, ['box' => [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50], ['Ines', 'inquilino', 100]]]);
    foreach (['Elsa', 'Carlo'] as $nome) {
        fclPersona($s, $nome);
    }
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fds50Registra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    $this->travel(1)->minutes();
    fclEmetti($this, $s, '2026-09-30');
    $this->travel(1)->minutes();

    $r = fum54Passo($this, $s, ['quota' => 50, 'pertinenze' => [(int) $s['box']->id]] + fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'));
    // Conto a mano, per unità: coppia 36000 × 900/1000 × 184/365 = 16333; dopo Ugo 4083, Anna 8167, Elsa 4083: Elsa +4083. Il box: 36000 × 100/1000 × 184/365 = 1815;
    // dopo Ugo 454, Anna 907, Elsa 454: Elsa +454. Il centesimo di resto di Anna non è una parte.
    $perUnita = collect(fds50Fermate($r['subentro']->fresh()))->mapWithKeys(fn (array $f) => [$f['unita'] => fum54Fermata($f)])->all();
    expect($perUnita)->toBe(['Interno 1' => ['Ugo', 4083, ['Elsa' => 4083]], 'Box 12' => ['Ugo', 454, ['Elsa' => 454]]]);
});

it('Coda 340 (guardia) — la costituzione datata il 1/6, prima della fine di Ines, e registrata dopo: l\'unità è mista per tutti i giorni della coppia, e Elsa riceve € 90,74', function () {
    $s = fum54Base($this, FUM54_UGO_ANNA);
    $r = fum54Passo($this, $s, ['quota' => 50] + fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-06-01'));

    // Conto a mano: dal 1/6 Elsa usufruttuaria e Anna pagano 50 e 50 per tutti i 184 giorni: Elsa 18148 × 92/184 = 9074, e Ugo non paga più niente.
    expect(fum54Registro($r['subentro']))->toBe([['Ugo', 9074, ['Elsa' => 9074]]]);
});

it('Coda 340 (capitolo di entrata) — la costituzione su un\'unità mista con un capitolo di entrata nei giorni della coppia (antenna € 400,00 dal 1/10): la parte di Elsa è un credito di € 14,63, e Anna non riceve niente', function () {
    $s = fum54Base($this, FUM54_UGO_ANNA, ['Dora'], fn (array $s) => b51aCapitolo($s, 'Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31'));
    // La coppia: la spesa 18148 (184 giorni) meno l'antenna 40000 × 30 % = 12000 (92 giorni): 6148, metà a testa.
    expect(fds50Coppia($s, $s['fine']))->toBe(['Anna' => 3074, 'Ines' => -6148, 'Ugo' => 3074]);

    $r = fum54Passo($this, $s, ['quota' => 50] + fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'));
    // Conto a mano, due pezzi con il segno. Spesa 18148 sui 184 giorni: dopo la costituzione Ugo 46, Anna 92, Elsa 46 su 184, cioè 4537, 9074, 4537. Antenna −12000
    // sui 92 giorni dal 1/10: Anna e Elsa 50 e 50, −6000 ciascuna. Dopo: Ugo 4537, Anna 9074 − 6000 = 3074, Elsa 4537 − 6000 = −1463. Prima: 3074 e 3074.
    // Elsa −1463, Anna invariata: la parte di Elsa è un credito di € 14,63.
    expect(fum54Registro($r['subentro']))->toBe([['Ugo', -1463, ['Elsa' => -1463]]]);
});
