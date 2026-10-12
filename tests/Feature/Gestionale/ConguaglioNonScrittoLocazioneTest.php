<?php

/**
 * 1.11.0-beta.50 — il conguaglio «non scritto» della locazione nelle frasi che lo ricordano dopo la registrazione, e nel registro.
 *
 * Con la .50 la fine di una locazione senza un nuovo inquilino e il cambio d'inquilino hanno «Non scriverlo» (decisione 77), come la
 * successione della .48. Le frasi che ricordano un conguaglio non scritto erano fisse sulla successione: l'avviso dell'annullamento del
 * passaggio (`AnnullaPassaggioAction::avvisi`, lo stesso che lo storico dell'unità mostra in `annullabile.avvisi`) e la frase del piano
 * fermo (`PianoRate::fraseRegolatoFuori`, nel rifiuto del ricalcolo, dell'eliminazione e della rimozione di una voce). La misura S8
 * della revisione della Fase 1 ha mostrato che tutte e due dicevano «Il conguaglio della successione non è stato scritto (€ 181,48 …)»
 * anche per la fine locazione. Devono dire il tipo del passaggio: «della fine locazione», «del cambio d'inquilino».
 *
 * E il registro: `regolato_fuori.persone` portava solo chi riceve (Ugo 18148). Deve portare la cifra di ciascuno, anche di chi esce,
 * come per gli eredi della .48 — segno dei saldi: positivo = debito, negativo = credito.
 *
 * Le cifre a mano: € 1.200,00, 30 % «Inquilino» = 36000; Ines esce il 1/7; 36000 × 184 / 365 = 18147,95 → 18148 (resti maggiori:
 * 17852 + 18148 = 36000), la parte dei giorni dopo l'uscita che il conguaglio non scritto lascia nelle rate di Ines: € 181,48 a credito
 * di Ines (−18148) e a debito di chi l'avrebbe presa (+18148): Ugo, al posto dell'inquilino, o Luca, il nuovo inquilino.
 *
 * Fissano le frasi e il registro del conguaglio non scritto della locazione. Prima della .50 la rotta rifiutava il campo `conguaglio`
 * fuori dalla successione (il campo nuovo della decisione 77).
 *
 * Cosa NON copre: la successione e il legato (già nei loro test: la frase resta «della successione»); il testo della pagina dopo la
 * registrazione (`PassaggioController`, «la posizione resta com'è») e la scheda Vue dello storico (`TitolaritaSheet`: «Conguaglio non
 * scritto», senza tipo); «Annulla il conguaglio» dopo «Scrivi» (le parti hanno regolato: «Le parti hanno già regolato fra loro …»); i
 * passaggi registrati prima della .50, che il «non scritto» non l'hanno.
 */

use App\Actions\Subentro\AnnullaPassaggioAction;
use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariProspettoLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

it('«Non scriverlo» nella fine senza nuovo inquilino: il registro porta la cifra di ciascuno, Ines −18148 e Ugo +18148', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    $fine = pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere');

    expect($fine->registro['conguaglio']['scelta'] ?? null)->toBe(Subentro::CONGUAGLIO_NON_SCRITTO)
        // 18148 dei giorni dopo l'uscita: a credito di Ines (resta nelle sue rate), a debito di Ugo (che li avrebbe presi).
        ->and(pflRegolatoFuori($fine))->toBe(['Ordinaria 2026' => ['Ines Moretti' => -18148, 'Ugo Rinaldi' => 18148]]);
});

it('«Non scriverlo» nella fine senza nuovo inquilino: l\'avviso dell\'annullamento (nello storico) e la frase del piano fermo dicono «della fine locazione», non «della successione»', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    $fine = pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere');

    $avvisi = implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($fine->fresh()));
    $storico = pflAvvisiDelloStorico($s, $fine);
    $piano = (string) $s['piano']->fresh()->fraseRegolatoFuori();
    foreach (['avviso dell\'annullamento' => $avvisi, 'storico' => $storico, 'frase del piano fermo' => $piano] as $dove => $frase) {
        expect(str_contains($frase, 'della fine locazione'))->toBeTrue("{$dove}: «{$frase}»")
            ->and($frase)->toContain('della fine locazione')
            ->and($frase)->not->toContain('della successione')
            // € 181,48: la cifra dei giorni dopo l'uscita rimasta nelle rate di Ines.
            ->and($frase)->toContain('€ 181,48')
            ->and($frase)->toContain('Ines Moretti');
    }
});

it('«Non scriverlo» nel cambio d\'inquilino in un passo: il registro porta Ines −18148 e Luca +18148, e le frasi dicono «del cambio d\'inquilino», non «della successione»', function () {
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    $cambio = pflFine($this, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', 'non_scrivere');

    expect($cambio->registro['conguaglio']['scelta'] ?? null)->toBe(Subentro::CONGUAGLIO_NON_SCRITTO)
        ->and(pflRegolatoFuori($cambio))->toBe(['Ordinaria 2026' => ['Ines Moretti' => -18148, 'Luca Ferri' => 18148]]);

    $avvisi = implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($cambio->fresh()));
    $storico = pflAvvisiDelloStorico($s, $cambio);
    $piano = (string) $s['piano']->fresh()->fraseRegolatoFuori();
    foreach (['avviso dell\'annullamento' => $avvisi, 'storico' => $storico, 'frase del piano fermo' => $piano] as $dove => $frase) {
        expect(str_contains($frase, 'del cambio d\'inquilino'))->toBeTrue("{$dove}: «{$frase}»")
            ->and($frase)->toContain('del cambio d\'inquilino')
            ->and($frase)->not->toContain('della successione')
            ->and($frase)->toContain('€ 181,48');
    }
});
