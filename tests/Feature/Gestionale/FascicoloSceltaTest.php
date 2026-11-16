<?php

/**
 * Che cosa ci metti nel fascicolo (decisione 395 (4)): di serie tutto; l'amministratore toglie un esercizio, una rata o una parte, e l'archivio, l'indice e il
 * prospetto dicono solo ciò che è dentro. La cifra è quella delle rate scelte. Il registro della persona tiene anche ciò che è stato tolto. Il server rifiuta una
 * rata che non è fra le rate scadute della persona, e il fascicolo senza rate.
 */

use App\Models\Gestionale\RendicontoVersione;
use App\Models\Gestionale\RispostaPromemoria;

require_once __DIR__.'/FascicoloLegaleScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/** Rossi con una diffida spedita senza prova, il rendiconto 2025 approvato e un accordo sulla rata 3. */
function fscScenario($test): array
{
    $s = fslScenario($test);
    fslLettera($s, 'rossi', 'diffida', 'spedita', '2026-03-02 10:15', [1, 2, 3, 4, 5]);
    fslRendiconto($s, $s['e2025'], RendicontoVersione::APPROVATA);
    fslAccordo($s, 'rossi', [3]);

    return $s;
}

it('tolto un esercizio: le sue rate, il suo rendiconto e la sua cifra non ci sono; il registro tiene ciò che è stato tolto', function () {
    fslSenzaPdftotext($this);
    $s = fscScenario($this);

    [$r, $file] = fslScarica($this, $s, 'rossi', fslChiavi($s, 'rossi', [3, 4, 5]));
    $indice = fslTesto($file['00 Indice.pdf']);
    $prospetto = fslTesto($file['01 Rate non versate.pdf']);

    expect($r->getStatusCode())->toBe(200)
        ->and(array_keys($file))->not->toContain('04 Rendiconto 2025 approvato.pdf')->not->toContain('04 Rendiconto 2025 - verbale.pdf')
        ->and($indice)->toContain('Le rate scadute in questo fascicolo, al 20/05/2026: € 300,00')
        ->toContain('di cui dell\'esercizio 2026: € 300,00 (data dei sei mesi: 30/06/2027)')
        ->not->toContain('dell\'esercizio 2025')
        ->and($prospetto)->toContain('rata 3')->not->toContain('rata 1 ')->not->toContain('05/11/2025')
        ->toContain('Totale € 300,00');

    $ultima = collect(RispostaPromemoria::where('argomento', 'persona')->sole()->registro)->last();
    expect($ultima['dati']['tolti'])->toBe(['rate' => fslChiavi($s, 'rossi', [1, 2]), 'parti' => []])
        ->and($ultima['dati']['cifra'])->toBe(30000);
});

it('tolta una rata: la cifra è quella delle rate scelte', function () {
    fslSenzaPdftotext($this);
    $s = fscScenario($this);

    [, $file] = fslScarica($this, $s, 'rossi', fslChiavi($s, 'rossi', [1, 2, 3, 4]));

    expect(fslTesto($file['00 Indice.pdf']))->toContain('Le rate scadute in questo fascicolo, al 20/05/2026: € 400,00')
        ->toContain('di cui dell\'esercizio 2026: € 200,00 (data dei sei mesi: 30/06/2027)')
        ->and(fslTesto($file['01 Rate non versate.pdf']))->not->toContain('05/05/2026')->toContain('Totale € 400,00');
});

it('tolte le lettere: niente lettere nell\'archivio e nell\'indice, e niente «manca la prova»', function () {
    fslSenzaPdftotext($this);
    $s = fscScenario($this);

    [, $file] = fslScarica($this, $s, 'rossi', null, ['estratti', 'rendiconto', 'accordo']);
    $indice = fslTesto($file['00 Indice.pdf']);

    expect(collect(array_keys($file))->filter(fn ($n) => str_starts_with($n, '03 '))->all())->toBe([])
        ->and($indice)->not->toContain('Diffida')->not->toContain('prova di spedizione')->not->toContain('Nessuna lettera')
        ->and(collect(RispostaPromemoria::where('argomento', 'persona')->sole()->registro)->last()['dati']['tolti'])->toBe(['rate' => [], 'parti' => ['lettere']]);
});

it('tolti gli estratti conto, il rendiconto e l\'accordo: i loro file e le loro righe non ci sono', function () {
    fslSenzaPdftotext($this);
    $s = fscScenario($this);

    [, $file] = fslScarica($this, $s, 'rossi', null, ['lettere']);
    $indice = fslTesto($file['00 Indice.pdf']);

    expect(collect(array_keys($file))->filter(fn ($n) => preg_match('/^0[245] /', $n))->all())->toBe([])
        ->and($indice)->not->toContain('Il saldo dell\'estratto conto')->not->toContain('Rendiconto 2025')->not->toContain('Accordo di rientro')
        ->and(fslTesto($file['01 Rate non versate.pdf']))->not->toContain('accordo di rientro');
});

it('il server rifiuta una rata che non è fra le rate scadute della persona (di un\'altra persona, non ancora scaduta), il fascicolo senza rate e una parte che non c\'è', function () {
    $s = fscScenario($this);
    $prepara = fn (array $dati) => $this->actingAs($this->user)->postJson(fslRotta($s, 'rossi', 'anagrafiche.fascicolo-legale.prepara'), $dati);

    $prepara(['rate' => [...fslChiavi($s, 'rossi', [1]), ...fslChiavi($s, 'bianchi', [1])], 'parti' => []])
        ->assertStatus(422)->assertJsonPath('message', 'Questa rata non è fra le rate scadute della persona.');
    // La rata 6 scade il 05/06/2026: oggi (20/05/2026) non è scaduta.
    $prepara(['rate' => fslChiavi($s, 'rossi', [6]), 'parti' => []])
        ->assertStatus(422)->assertJsonPath('message', 'Questa rata non è fra le rate scadute della persona.');
    $prepara(['rate' => [], 'parti' => ['lettere']])->assertStatus(422)->assertJsonPath('message', 'Scegli almeno una rata.');
    $prepara(['rate' => fslChiavi($s, 'rossi', [1]), 'parti' => ['lettere', 'tutto']])->assertStatus(422);

    expect(RispostaPromemoria::where('argomento', 'persona')->count())->toBe(0);
});

it('l\'anteprima segna ogni pezzo, ogni fatto e ogni mancanza con la parte e le rate a cui appartiene: la pagina filtra con quei segni', function () {
    $s = fscScenario($this);
    $a = fslAnteprima($this, $s, 'rossi');
    $dell2025 = fslChiavi($s, 'rossi', [1, 2]);
    $pezzo = fn (string $tipo) => collect($a['pezzi'])->firstWhere('tipo', $tipo);

    expect(array_column($a['esercizi'], 'nome'))->toBe(['Esercizio 2025', 'Esercizio 2026'])
        ->and(array_column($a['esercizi'][0]['rate'], 'chiave'))->toBe($dell2025)
        ->and($a['esercizi'][0]['data'])->toBe('2026-06-30')
        ->and($a['parti'])->toBe(['lettere', 'estratti', 'rendiconto', 'accordo'])
        ->and($pezzo('indice')['parte'])->toBeNull()->and($pezzo('indice')['rate'])->toBeNull()
        ->and($pezzo('prospetto')['parte'])->toBeNull()
        ->and($pezzo('lettera')['parte'])->toBe('lettere')->and($pezzo('lettera')['rate'])->toBeNull()
        ->and($pezzo('estratto')['parte'])->toBe('estratti')->and($pezzo('estratto')['rate'])->toBe(fslChiavi($s, 'rossi', [1, 2, 3, 4, 5]))
        ->and($pezzo('rendiconto')['parte'])->toBe('rendiconto')->and($pezzo('rendiconto')['rate'])->toBe($dell2025)
        ->and($pezzo('accordo')['parte'])->toBe('accordo')->and($pezzo('accordo')['rate'])->toBe(fslChiavi($s, 'rossi', [3]))
        ->and(collect($a['mancanze'])->firstWhere('frase', 'Il piano «Preventivo 2026» non ha la data della delibera: scrivila nel piano rate.')['rate'])
        ->toBe(fslChiavi($s, 'rossi', [1, 2, 3, 4, 5]));
});
