<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il calcolo dell'estratto conto di una persona sta in un servizio con l'esercizio come parametro ({@see \App\Services\Gestionale\EstrattoContoDellaPersona}):
 * il fascicolo per il legale lo stampa per gli esercizi dei piani delle rate, la pagina per l'esercizio corrente. Spostarlo non cambia la stampa della pagina:
 * il testo del PDF è quello di prima, parola per parola (scritto qui com'era prima dello spostamento), e il calcolo della pagina è quello del servizio.
 */

use App\Http\Controllers\Gestionale\PianiRate\EstrattoContoAnagraficaController;

require_once __DIR__.'/FascicoloLegaleScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

it('spostato il calcolo in un servizio, il PDF dell\'estratto conto della pagina dice lo stesso, e il calcolo della pagina è quello del servizio', function () {
    fslSenzaPdftotext($this);
    $s = fslScenario($this);
    $s['c']->update(['indirizzo' => 'Via Verdi 12, 20121 Milano (MI)']);
    $this->travelTo('2026-04-10 10:00:00');
    lcdIncassa($this, $s, 'rossi', [lcdQuota($s, 'rossi', 1) => 10000, lcdQuota($s, 'rossi', 2) => 4000], '2026-04-10');
    $this->travelTo('2026-05-20 10:00:00');
    $rossi = $s['persone']['rossi'];

    $r = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', ['condominio' => $s['c']->id, 'anagrafica' => $rossi->id]));
    expect($r->status())->toBe(200);
    $testo = str_replace([(string) $rossi->codice_fiscale, (string) $rossi->email], ['[CF]', '[EMAIL]'], fslTesto((string) $r->getContent(), '-layout'));
    fwrite(STDERR, "\n" . $testo . "\n");

    expect($testo)->toBe(ECS_ATTESO);

    // Il calcolo della pagina (`buildLedger`, che `show()` e `print()` usano) è quello del servizio, e il PDF del servizio per lo stesso esercizio è quello della pagina.
    $servizio = app(\App\Services\Gestionale\EstrattoContoDellaPersona::class);
    $esercizio = $s['e2025'];
    $metodo = new ReflectionMethod(EstrattoContoAnagraficaController::class, 'buildLedger');
    $dellaPagina = $metodo->invoke(app(EstrattoContoAnagraficaController::class), $s['c'], $esercizio, $rossi);
    expect($servizio->ledger($s['c'], $esercizio, $rossi))->toEqual($dellaPagina);
    $pdfDelServizio = $servizio->pdf($s['c'], $esercizio, $rossi);
    expect(str_replace([(string) $rossi->codice_fiscale, (string) $rossi->email], ['[CF]', '[EMAIL]'], fslTesto($pdfDelServizio, '-layout')))->toBe(ECS_ATTESO);

    // Con un altro esercizio come parametro: quello del piano delle rate (2026), che la pagina non stampa perché il suo esercizio corrente è il 2025.
    $altro = fslTesto($servizio->pdf($s['c'], $s['e'], $rossi));
    expect($altro)->toContain('Esercizio: Esercizio 2026 | (dal 01/01/2026 al 31/12/2026)')
        ->toContain('MOVIMENTI CONTABILI ESERCIZIO 2026');
});

/** Il testo del PDF della pagina com'era prima dello spostamento (il codice fiscale e l'email della persona tolti: cambiano da una prova all'altra). */
const ECS_ATTESO = 'Via Verdi 12 Via Verdi 12, 20121 Milano (MI) C.F. ESTRATTO CONTO — ROSSI Esercizio: Esercizio 2025 | (dal 01/01/2025 al 31/12/2025) | Stampato il '
    . '20/05/2026 12:00 ANAGRAFICA UNITÀ IMMOBILIARI Rossi Int. A (proprietario · 100%) CF: [CF] ✉ [EMAIL] SALDO INIZIALE TOTALE ADDEBITI TOTALE VERSATO '
    . 'SALDO FINALE € 0,00 € 1.200,00 € 140,00 € 1.060,00 PAREGGIO RATE EMESSE E ADDEBITI INCASSI REGISTRATI DA VERSARE MOVIMENTI CONTABILI ESERCIZIO 2025 '
    . 'ADDEBITI PAGAMENTI DATA PROTOCOLLO DESCRIZIONE SALDO (DARE) (AVERE) 01/01/2025 — Saldo iniziale esercizio — — € 0,00 02/01/2026 SCR-2026-00001 '
    . 'Emissione Rata n.1 - Preventivo 2026 Ordinaria 2026 € 100,00 — € 100,00 ▲ Quota Rata n.1 - Preventivo 2026 • Rata n.1 (Scad. 05/11/2025) PAGATA • '
    . 'Interno A (Int. A) 02/01/2026 SCR-2026-00002 Emissione Rata n.2 - Preventivo 2026 Ordinaria 2026 € 100,00 — € 200,00 ▲ Quota Rata n.2 - Preventivo '
    . '2026 • Rata n.2 (Scad. 05/12/2025) PARZIALE • Interno A (Int. A) 02/01/2026 SCR-2026-00003 Emissione Rata n.3 - Preventivo 2026 Ordinaria 2026 € '
    . '100,00 — € 300,00 ▲ Quota Rata n.3 - Preventivo 2026 • Rata n.3 (Scad. 05/03/2026) NON PAGATA • Interno A (Int. A) 02/01/2026 SCR-2026-00004 Emissione '
    . 'Rata n.4 - Preventivo 2026 Ordinaria 2026 € 100,00 — € 400,00 ▲ Quota Rata n.4 - Preventivo 2026 • Rata n.4 (Scad. 05/04/2026) NON PAGATA • Interno A '
    . '(Int. A) 02/01/2026 SCR-2026-00005 Emissione Rata n.5 - Preventivo 2026 Ordinaria 2026 € 100,00 — € 500,00 ▲ Quota Rata n.5 - Preventivo 2026 • Rata '
    . 'n.5 (Scad. 05/05/2026) NON PAGATA • Interno A (Int. A) 02/01/2026 SCR-2026-00006 Emissione Rata n.6 - Preventivo 2026 Ordinaria 2026 € 100,00 — € '
    . '600,00 ▲ Quota Rata n.6 - Preventivo 2026 • Rata n.6 (Scad. 05/06/2026) NON PAGATA • Interno A (Int. A) 02/01/2026 SCR-2026-00007 Emissione Rata n.7 - '
    . 'Preventivo 2026 Ordinaria 2026 € 100,00 — € 700,00 ▲ Quota Rata n.7 - Preventivo 2026 • Rata n.7 (Scad. 05/07/2026) NON PAGATA • Interno A (Int. A) '
    . '02/01/2026 SCR-2026-00008 Emissione Rata n.8 - Preventivo 2026 Ordinaria 2026 € 100,00 — € 800,00 ▲ Quota Rata n.8 - Preventivo 2026 • Rata n.8 (Scad. '
    . '05/08/2026) NON PAGATA • Interno A (Int. A) 02/01/2026 SCR-2026-00009 Emissione Rata n.9 - Preventivo 2026 Ordinaria 2026 € 100,00 — € 900,00 ▲ Quota '
    . 'Rata n.9 - Preventivo 2026 • Rata n.9 (Scad. 05/09/2026) NON PAGATA • Interno A (Int. A) 02/01/2026 SCR-2026-00010 Emissione Rata n.10 - Preventivo '
    . '2026 Ordinaria 2026 € 100,00 — € 1.000,00 ▲ Quota Rata n.10 - Preventivo 2026 • Rata n.10 (Scad. 05/10/2026) NON PAGATA • Interno A (Int. A) '
    . '02/01/2026 SCR-2026-00011 Emissione Rata n.11 - Preventivo 2026 Ordinaria 2026 € 100,00 — € 1.100,00 ▲ Quota Rata n.11 - Preventivo 2026 • Rata n.11 '
    . '(Scad. 05/11/2026) NON PAGATA • Interno A (Int. A) 02/01/2026 SCR-2026-00012 Emissione Rata n.12 - Preventivo 2026 Ordinaria 2026 € 100,00 — € '
    . '1.200,00 ▲ Quota Rata n.12 - Preventivo 2026 • Rata n.12 (Scad. 05/12/2026) NON PAGATA • Interno A (Int. A) Documento emesso il 20/05/2026 Pagina 1 di '
    . '2 Professione esercitata ai sensi della legge 14 gennaio 2013, n.4 Via Verdi 12 Via Verdi 12, 20121 Milano (MI) C.F. ADDEBITI PAGAMENTI DATA '
    . 'PROTOCOLLO DESCRIZIONE SALDO (DARE) (AVERE) 10/04/2026 INC-2026-00001 Bonifico Ordinaria 2026 — € 100,00 € 1.100,00 ▲ Incasso rata n.1 • A copertura '
    . 'Rata n.1 (Scad. 05/11/2025) • Interno A (Int. A) 10/04/2026 INC-2026-00001 Bonifico Ordinaria 2026 — € 40,00 € 1.060,00 ▲ Incasso rata n.2 • A '
    . 'copertura Rata n.2 (Scad. 05/12/2025) • Interno A (Int. A) SALDO FINALE TOTALE ADDEBITI TOTALE VERSATO € 1.060,00 € 1.200,00 € 140,00 DA VERSARE '
    . 'Documento redatto ai sensi dell\'art. 63 disp. att. c.c. (riscossione dei contributi condominiali). Il mancato pagamento entro la scadenza indicata '
    . 'può comportare gli interessi di mora al tasso del condominio (artt. 1224 e 1282 c.c.). Documento emesso il '
    . '20/05/2026 Pagina 2 di 2 Professione esercitata ai sensi della legge 14 gennaio 2013, n.4';
