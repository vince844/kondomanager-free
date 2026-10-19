<?php

/**
 * La riga di una voce nell'albero del piano dei conti (`ContoResource`) quando un piano ne tiene la copia nulla («lascia vuoto»).
 *
 * Con la copia nulla il piano usa l'importo della voce, per intero, qualunque cosa facciano gli altri piani che la tengono: il motore la legge così
 * (`CalcoloQuoteService::processaConti`, il ramo senza importo del piano), e così la conta la copertura (`BudgetCoverageService`), da cui viene
 * l'impegnato della riga. La riga dà quindi a ogni piano la cifra che addebita: alla copia scritta il suo valore assoluto, alla copia nulla l'importo
 * della voce dal lato del suo tipo (`Conto::partiDelTipo`); per un capitolo a € 0,00, la somma dei suoi sottoconti, e ai sottoconti la loro parte, dal
 * capitolo. L'importo è il preventivo scritto: uno speso oltre il preventivo alza il fabbisogno della riga, non la cifra del piano. Le righe sommano
 * l'impegnato, e nessuna nomina un piano che non c'è: il ripiego «Piano rate straordinario» resta per la copertura che nessun piano della voce spiega.
 *
 * Fonte dell'atteso: i conti a mano dello scenario di `Beta53SpesaNegativaScenari.php` («Rimborso vecchio» € 240,00, o −24000 centesimi; il
 * capitolo «Manutenzione» con «Ascensore» € 600,00 e «Pulizia scale» € 100,00) e quanto il motore ripartisce su ogni piano, riletto in sola lettura.
 *
 * Cosa NON copre: un capitolo con un importo suo diverso da zero accanto ai sottoconti (la copertura conta il solo importo del capitolo, il motore
 * anche i sottoconti); un capitolo i cui sottoconti sono nati tutti dopo il piano (la copertura non lo conta, il motore sì); un capitolo in due piani
 * (la parte dei sottoconti va al primo); i piani straordinari da fatture; la scheda della voce montata (`DettaglioConto.test.ts`); i database diversi
 * da sqlite.
 */

use App\Models\Esercizio;
use App\Services\Gestionale\BudgetCoverageService;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Il secondo piano della voce: una rata, con le voci scelte (`capitoli_config`). */
function b53rcSecondoPiano($test, array $s, array $config, string $nome = 'Integrativo 2026')
{
    return b52tPiano($test, $s, [
        'capitoli_ids' => array_column($config, 'id'), 'capitoli_config' => $config,
        'numero_rate' => 1, 'data_prima_scadenza' => '2026-06-05', 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova',
    ], $nome);
}

/**
 * «Ordinario 2026» con le voci orfane (la copia di «Rimborso vecchio» scritta, 24000) e «Integrativo 2026» con la sola voce e la copia nulla. Con
 * `$negativa` la voce e la copia di «Ordinario 2026» sono poi riscritte a −24000.
 */
function b53rcScrittaENulla($test, bool $negativa): array
{
    $s = b53snBase($test);
    $s['rimborso'] = b52tVoce($test, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $s['piano'] = b52tPiano($test, $s, [], 'Ordinario 2026');
    $s['secondo'] = b53rcSecondoPiano($test, $s, [['id' => $s['rimborso']->id, 'importo' => '', 'note' => '']]);
    if ($negativa) {
        b53snRiscrivi($s['rimborso'], -24000, [$s['piano']]);
    }

    return $s;
}

/** Il capitolo «Manutenzione» (€ 0,00, senza tabella sua) con i sottoconti «Ascensore» € 600,00 e «Pulizia scale» € 100,00 su «Proprietà». */
function b53rcCapitolo($test): array
{
    $s = b53snBase($test);
    $s['capitolo'] = b52tVoce($test, $s, 'Manutenzione', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null,
        'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    $s['ascensore'] = b52tVoce($test, $s, 'Ascensore', 'spesa', '600,00', $s['tabella'], $s['capitolo']->id, ['isSottoConto' => true]);
    $s['pulizia'] = b52tVoce($test, $s, 'Pulizia scale', 'spesa', '100,00', $s['tabella'], $s['capitolo']->id, ['isSottoConto' => true]);

    return $s;
}

/** I numeri della copertura della riga. */
function b53rcCopertura(array $riga): array
{
    return collect($riga)->only(['impegnato', 'importo', 'importo_raw', 'stato_copertura', 'percentuale_copertura'])->all();
}

/** Le righe sommano l'impegnato, ognuna nomina un piano della gestione, e fra i piani collegati non c'è un piano che non esiste. */
function b53rcNessunPianoInventato(array $riga): void
{
    $dettaglio = collect($riga['dettaglio_copertura']);

    expect($dettaglio->sum('importo'))->toBe($riga['impegnato'])
        ->and($dettaglio->pluck('piano_rate_id')->contains(null))->toBeFalse()
        ->and($dettaglio->pluck('piano')->all())->not->toContain('Piano rate straordinario')
        ->and($riga['piani_collegati'])->not->toContain('Piano Rate Straordinario');
}

// ─── Una copia scritta e una copia nulla ────────────────────────────────────────────────────────────────────────

it('la voce di spesa scritta con il meno, copia −24000 su «Ordinario 2026» e copia nulla su «Integrativo 2026»: due righe da € 240,00, una per piano', function () {
    $s = b53rcScrittaENulla($this, negativa: true);
    $id = (int) $s['rimborso']->id;

    $riga = b53snRiga($this, $s, 'Rimborso vecchio');

    // ciascun piano addebita la voce per € 240,00: è la cifra della sua riga
    expect(b53snCopie($s, $s['rimborso']))->toBe([$s['piano']->id => -24000, $s['secondo']->id => null])
        ->and(b53snRipartito($s, $s['piano'])[$id] ?? 0)->toBe(24000)
        ->and(b53snRipartito($s, $s['secondo'])[$id] ?? 0)->toBe(24000)
        ->and(b53snCopertura($s, 'Rimborso vecchio')['pianificato'])->toBe(48000)
        ->and(b53rcCopertura($riga))->toEqual([
            'impegnato' => 48000, 'importo' => '€ 240,00', 'importo_raw' => 24000, 'stato_copertura' => 'over', 'percentuale_copertura' => 200,
        ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $s['piano']->id, 24000], ['Integrativo 2026', $s['secondo']->id, 24000]])
        ->and(collect($riga['piani_collegati'])->sort()->values()->all())->toBe(['Integrativo 2026', 'Ordinario 2026']);
    b53rcNessunPianoInventato($riga);
});

it('la voce di spesa positiva, copia +24000 su «Ordinario 2026» e copia nulla su «Integrativo 2026»: due righe da € 240,00, il segno non c\'entra', function () {
    $s = b53rcScrittaENulla($this, negativa: false);
    $id = (int) $s['rimborso']->id;

    $riga = b53snRiga($this, $s, 'Rimborso vecchio');

    expect(b53snCopie($s, $s['rimborso']))->toBe([$s['piano']->id => 24000, $s['secondo']->id => null])
        ->and(b53snRipartito($s, $s['piano'])[$id] ?? 0)->toBe(24000)
        ->and(b53snRipartito($s, $s['secondo'])[$id] ?? 0)->toBe(24000)
        ->and(b53snCopertura($s, 'Rimborso vecchio')['pianificato'])->toBe(48000)
        ->and(b53rcCopertura($riga))->toEqual([
            'impegnato' => 48000, 'importo' => '€ 240,00', 'importo_raw' => 24000, 'stato_copertura' => 'over', 'percentuale_copertura' => 200,
        ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $s['piano']->id, 24000], ['Integrativo 2026', $s['secondo']->id, 24000]]);
    b53rcNessunPianoInventato($riga);
});

// ─── Una copia sola, o due copie scritte ────────────────────────────────────────────────────────────────────────

it('controprova — la sola copia nulla, nessun altro piano: una riga «Ordinario 2026» da € 240,00 su € 240,00', function () {
    $s = b53snBase($this);
    $s['rimborso'] = b52tVoce($this, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $piano = b52tPiano($this, $s, ['capitoli_ids' => [$s['rimborso']->id], 'capitoli_config' => [['id' => $s['rimborso']->id, 'importo' => '', 'note' => '']]], 'Ordinario 2026');

    $riga = b53snRiga($this, $s, 'Rimborso vecchio');

    expect(b53snCopie($s, $s['rimborso']))->toBe([$piano->id => null])
        ->and(b53snRipartito($s, $piano)[$s['rimborso']->id] ?? 0)->toBe(24000)
        ->and(b53rcCopertura($riga))->toEqual([
            'impegnato' => 24000, 'importo' => '€ 240,00', 'importo_raw' => 24000, 'stato_copertura' => 'full', 'percentuale_copertura' => 100,
        ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $piano->id, 24000]]);
    b53rcNessunPianoInventato($riga);
});

it('la sola copia nulla con uno speso di € 300,00 oltre il preventivo: la riga dice i € 240,00 che il piano addebita, non il fabbisogno', function () {
    $s = b53snBase($this);
    $s['rimborso'] = b52tVoce($this, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $id = (int) $s['rimborso']->id;
    $piano = b52tPiano($this, $s, ['capitoli_ids' => [$id], 'capitoli_config' => [['id' => $id, 'importo' => '', 'note' => '']]], 'Ordinario 2026');
    $this->app->instance(SpesaPerVoceService::class, new class($id) extends SpesaPerVoceService
    {
        public function __construct(private int $voceId) {}

        public function perEsercizio(Esercizio $esercizio, ?array $vociIds = null, ?int $escludiFatturaId = null): array
        {
            return [$this->voceId => 30000];
        }
    });

    $riga = b53snRiga($this, $s, 'Rimborso vecchio');
    $copertura = collect(app(BudgetCoverageService::class)->analyze($s['g']->fresh(), [$id => 30000])['items'])->firstWhere('id', $id);

    // il motore legge l'importo della voce, € 240,00; il fabbisogno è lo speso, € 300,00, coperto all'80 %
    expect(b53snRipartito($s, $piano)[$id] ?? 0)->toBe(24000)
        ->and([$copertura['budget'], $copertura['pianificato']])->toBe([30000, 24000])
        ->and(b53rcCopertura($riga))->toEqual([
            'impegnato' => 24000, 'importo' => '€ 300,00', 'importo_raw' => 30000, 'stato_copertura' => 'partial', 'percentuale_copertura' => 80,
        ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $piano->id, 24000]]);
    b53rcNessunPianoInventato($riga);
});

it('controprova — due copie scritte, € 100,00 su «Ordinario 2026» e € 140,00 su «Integrativo 2026»: una riga per piano, € 240,00 su € 240,00', function () {
    $s = b53snBase($this);
    $s['rimborso'] = b52tVoce($this, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $id = (int) $s['rimborso']->id;
    $primo = b52tPiano($this, $s, ['capitoli_ids' => [$id], 'capitoli_config' => [['id' => $id, 'importo' => '100,00', 'note' => '']]], 'Ordinario 2026');
    $secondo = b53rcSecondoPiano($this, $s, [['id' => $id, 'importo' => '140,00', 'note' => '']]);

    $riga = b53snRiga($this, $s, 'Rimborso vecchio');

    expect(b53snCopie($s, $s['rimborso']))->toBe([$primo->id => 10000, $secondo->id => 14000])
        ->and(b53snRipartito($s, $primo)[$id] ?? 0)->toBe(10000)
        ->and(b53snRipartito($s, $secondo)[$id] ?? 0)->toBe(14000)
        ->and(b53rcCopertura($riga))->toEqual([
            'impegnato' => 24000, 'importo' => '€ 240,00', 'importo_raw' => 24000, 'stato_copertura' => 'full', 'percentuale_copertura' => 100,
        ])
        ->and(b53snDettaglio($riga))->toBe([['Ordinario 2026', $primo->id, 10000], ['Integrativo 2026', $secondo->id, 14000]]);
    b53rcNessunPianoInventato($riga);
});

// ─── La voce con sottoconti ─────────────────────────────────────────────────────────────────────────────────────

it('il capitolo con la copia nulla: la sua riga dice «Ordinario 2026» per € 700,00, quelle dei sottoconti la loro parte dal capitolo', function () {
    $s = b53rcCapitolo($this);
    $piano = b52tPiano($this, $s, ['capitoli_ids' => [$s['capitolo']->id], 'capitoli_config' => [['id' => $s['capitolo']->id, 'importo' => '', 'note' => '']]], 'Ordinario 2026');

    $capitolo = b53snRiga($this, $s, 'Manutenzione');
    $ascensore = b53snRiga($this, $s, 'Ascensore');
    $pulizia = b53snRiga($this, $s, 'Pulizia scale');
    $fonti = fn (array $riga) => collect($riga['dettaglio_copertura'])->pluck('fonte')->all();

    // il motore addebita i due sottoconti per il loro importo: € 600,00 + € 100,00
    expect(b53snCopie($s, $s['capitolo']))->toBe([$piano->id => null])
        ->and(b53snRipartito($s, $piano))->toEqual([$s['ascensore']->id => 60000, $s['pulizia']->id => 10000])
        ->and($capitolo['impegnato'])->toBe(70000)
        ->and(b53snDettaglio($capitolo))->toBe([['Ordinario 2026', $piano->id, 70000]])
        ->and(b53rcCopertura($ascensore))->toEqual([
            'impegnato' => 60000, 'importo' => '€ 600,00', 'importo_raw' => 60000, 'stato_copertura' => 'full', 'percentuale_copertura' => 100,
        ])
        ->and(b53snDettaglio($ascensore))->toBe([['Ordinario 2026', $piano->id, 60000]])
        ->and($fonti($ascensore))->toBe(['indiretta'])
        ->and(b53rcCopertura($pulizia))->toEqual([
            'impegnato' => 10000, 'importo' => '€ 100,00', 'importo_raw' => 10000, 'stato_copertura' => 'full', 'percentuale_copertura' => 100,
        ])
        ->and(b53snDettaglio($pulizia))->toBe([['Ordinario 2026', $piano->id, 10000]])
        ->and($fonti($pulizia))->toBe(['indiretta']);
    b53rcNessunPianoInventato($capitolo);
    b53rcNessunPianoInventato($ascensore);
    b53rcNessunPianoInventato($pulizia);
});

it('il capitolo con la copia nulla e uno speso di € 800,00 su «Ascensore»: il capitolo resta a € 700,00, i preventivi scritti dei sottoconti', function () {
    $s = b53rcCapitolo($this);
    $piano = b52tPiano($this, $s, ['capitoli_ids' => [$s['capitolo']->id], 'capitoli_config' => [['id' => $s['capitolo']->id, 'importo' => '', 'note' => '']]], 'Ordinario 2026');
    $this->app->instance(SpesaPerVoceService::class, new class((int) $s['ascensore']->id) extends SpesaPerVoceService
    {
        public function __construct(private int $voceId) {}

        public function perEsercizio(Esercizio $esercizio, ?array $vociIds = null, ?int $escludiFatturaId = null): array
        {
            return [$this->voceId => 80000];
        }
    });

    $capitolo = b53snRiga($this, $s, 'Manutenzione');
    $ascensore = b53snRiga($this, $s, 'Ascensore');

    // il piano addebita «Ascensore» per il preventivo, € 600,00: lo speso alza il fabbisogno, non la copertura
    expect(b53snRipartito($s, $piano))->toEqual([$s['ascensore']->id => 60000, $s['pulizia']->id => 10000])
        ->and($capitolo['impegnato'])->toBe(70000)
        ->and(b53snDettaglio($capitolo))->toBe([['Ordinario 2026', $piano->id, 70000]])
        ->and(b53rcCopertura($ascensore))->toEqual([
            'impegnato' => 60000, 'importo' => '€ 800,00', 'importo_raw' => 80000, 'stato_copertura' => 'partial', 'percentuale_copertura' => 75,
        ])
        ->and(b53snDettaglio($ascensore))->toBe([['Ordinario 2026', $piano->id, 60000]]);
    b53rcNessunPianoInventato($capitolo);
    b53rcNessunPianoInventato($ascensore);
});

it('il sottoconto con la copia scritta su «Ordinario 2026» (dal capitolo scelto) e la copia nulla su «Integrativo 2026»: due righe da € 600,00', function () {
    $s = b53rcCapitolo($this);
    $primo = b52tPiano($this, $s, ['capitoli_ids' => [$s['capitolo']->id], 'capitoli_config' => []], 'Ordinario 2026');
    $secondo = b53rcSecondoPiano($this, $s, [['id' => $s['ascensore']->id, 'importo' => '', 'note' => '']]);

    $ascensore = b53snRiga($this, $s, 'Ascensore');
    $pulizia = b53snRiga($this, $s, 'Pulizia scale');

    expect(b53snCopie($s, $s['ascensore']))->toBe([$primo->id => 60000, $secondo->id => null])
        ->and(b53snRipartito($s, $primo))->toEqual([$s['ascensore']->id => 60000, $s['pulizia']->id => 10000])
        ->and(b53snRipartito($s, $secondo))->toEqual([$s['ascensore']->id => 60000])
        ->and(b53rcCopertura($ascensore))->toEqual([
            'impegnato' => 120000, 'importo' => '€ 600,00', 'importo_raw' => 60000, 'stato_copertura' => 'over', 'percentuale_copertura' => 200,
        ])
        ->and(b53snDettaglio($ascensore))->toBe([['Ordinario 2026', $primo->id, 60000], ['Integrativo 2026', $secondo->id, 60000]])
        ->and(b53snDettaglio($pulizia))->toBe([['Ordinario 2026', $primo->id, 10000]]);
    b53rcNessunPianoInventato($ascensore);
    b53rcNessunPianoInventato($pulizia);
});

it('il sottoconto con la copia nulla su «Integrativo 2026» e il capitolo su «Ordinario 2026» per € 700,00: la riga diretta e quella dal capitolo, € 600,00 ciascuna', function () {
    $s = b53rcCapitolo($this);
    $primo = b52tPiano($this, $s, ['capitoli_ids' => [$s['capitolo']->id], 'capitoli_config' => [['id' => $s['capitolo']->id, 'importo' => '700,00', 'note' => '']]], 'Ordinario 2026');
    $secondo = b53rcSecondoPiano($this, $s, [['id' => $s['ascensore']->id, 'importo' => '', 'note' => '']]);

    $ascensore = b53snRiga($this, $s, 'Ascensore');
    $perFonte = collect($ascensore['dettaglio_copertura'])->map(fn ($d) => [$d['piano'], $d['fonte'], (int) $d['importo']])->sortBy(1)->values()->all();

    expect(b53snCopie($s, $s['capitolo']))->toBe([$primo->id => 70000])
        ->and(b53snCopie($s, $s['ascensore']))->toBe([$secondo->id => null])
        ->and(b53snRipartito($s, $primo))->toEqual([$s['ascensore']->id => 60000, $s['pulizia']->id => 10000])
        ->and(b53snRipartito($s, $secondo))->toEqual([$s['ascensore']->id => 60000])
        ->and(b53rcCopertura($ascensore))->toEqual([
            'impegnato' => 120000, 'importo' => '€ 600,00', 'importo_raw' => 60000, 'stato_copertura' => 'over', 'percentuale_copertura' => 200,
        ])
        ->and($perFonte)->toBe([['Integrativo 2026', 'diretta', 60000], ['Ordinario 2026', 'indiretta', 60000]]);
    b53rcNessunPianoInventato($ascensore);
});
