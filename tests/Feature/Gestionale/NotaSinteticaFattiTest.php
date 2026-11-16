<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\User;
use App\Services\Gestionale\FattiDellaNota;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__ . '/RendicontoViaRomaScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        Permission::findOrCreate($permesso->value, 'web');
    }
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
});


/** Lo spazio che lega «€» alla cifra. */
const NSF_SP = "\u{00A0}";

/** La cifra come la scrivono le frasi: «€ 1.234,56» con lo spazio che non va a capo. */
function nsfE(string $cifra): string
{
    return '€'.NSF_SP.$cifra;
}

/**
 * Via Verdi, il caso del conguaglio, con le spese scritte con le fatture (come le prove delle versioni del rendiconto): U1 di Neri 500, U2 di Bianchi poi Rossi dal 01/07 300,
 * U3 di Verdi 200; preventivo € 4.000,00 in quattro rate emesse; Pulizie € 3.300,00 e Manutenzioni € 900,00; il citofono di U3 € 80,00; Neri versa
 * € 1.700,00 su € 2.000,00. Il giorno della nota: 01/03/2027.
 */
function nsfViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, []);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000, []);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $test->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));

    return $s;
}

/** I fatti proposti, per chiave, con il loro testo; e le letture che li hanno fatti. */
function nsfFatti(array $s): array
{
    $letture = app(NotaSinteticaDelRendiconto::class)->letture($s['c'], $s['e']);
    $f = app(FattiDellaNota::class)->perEsercizio($s['c'], $s['e'], $letture);
    $vuota = NotaSinteticaDelRendiconto::normalizza([]);
    $fatti = [];
    foreach ($f['fatti'] as $x) {
        $fatti[$x['chiave']] = $x + ['testo' => NotaSinteticaDelRendiconto::testoDelFatto($x, $vuota)];
    }

    return ['fatti' => $fatti, 'non' => array_column($f['non_proposti'], 'perche', 'chiave'), 'letture' => $letture];
}

/** Le parole che il programma non scrive mai nelle frasi della nota. */
const NSF_PAROLE_VIETATE = '/\b(conform|regolar|in regola|corrett|pront[oaie]\b|dovut|esigibil|approvabil|null[oaie]\b|invalid|illegittim|rilevant|moros|fisiologic|decadut|nessuna lite|nessuna questione|congru)/iu';

it('Via Verdi: ogni fatto con la frase esatta e la cifra della scheda da cui viene', function () {
    $s = nsfViaVerdi($this);
    ['fatti' => $f, 'non' => $non, 'letture' => $l] = nsfFatti($s);
    $sintesi = $l['sintesi'];

    expect(array_keys($f))->toBe(['K1', 'K2', 'K3', 'K4', 'G1', 'G2', 'N3', 'G6', 'G7', 'G9:R6', 'G9:R8', 'G11', 'R3', 'Q1', 'Q3', 'N4', 'N5'])
        ->and($f['K1']['testo'])->toBe(FattiDellaNota::K1)
        // Esercizio finito, nessun fondo: la seconda frase della stampa, sui vincoli dei fondi, non riguarda questo condominio.
        ->and($f['K2']['testo'])->toBe('Le cifre sono quelle dell\'esercizio finito il 31/12/2026.')
        ->and($sintesi['stato']['frase_stampa'])->toStartWith($f['K2']['testo'])
        ->and($f['K3']['testo'])->toBe('Ogni voce è divisa fino al centesimo. I pochi centesimi che la divisione lascia vanno, uno per uno, a chi nel calcolo ne aveva la frazione più grande: per questo non c\'è una riga di arrotondamenti.')
        ->and($f['K4']['testo'])->toBe(FattiDellaNota::K4)
        // G1: 3.300,00 + 900,00 = 4.200,00 di spese; 4 rate da 1.000,00 = 4.000,00; 4.000,00 − 4.200,00 = −200,00.
        ->and($f['G1']['testo'])->toBe('Le spese dell\'esercizio sono '.nsfE('4.200,00').' e le quote chieste ai condòmini con le rate sono '.nsfE('4.000,00').': le spese superano le quote di '.nsfE('200,00').' (disavanzo).')
        ->and([$sintesi['prospetto']['costi'], $sintesi['prospetto']['quote_giornale'], $sintesi['prospetto']['risultato']])->toBe([420000, 400000, -20000])
        // G2: due voci, tutte e due mostrate: niente «di più» e niente «le altre voci».
        ->and($f['G2']['testo'])->toBe("Le voci che si sono scostate da quanto l'assemblea ha approvato con le rate:\n– Pulizie: ".nsfE('3.300,00').' contro '.nsfE('3.000,00').' ('.nsfE('300,00')." in più).\n– Manutenzioni: ".nsfE('900,00').' contro '.nsfE('1.000,00').' ('.nsfE('100,00').' in meno).')
        ->and($f['N3']['testo'])->toBe('Nella contabilità del condominio non ci sono fondi.')
        // G6: il saldo iniziale della banca 1.000,00 + gli incassi 1.700,00 + 1.200,00 + 750,00 = 4.650,00, senza pagamenti.
        ->and($f['G6']['testo'])->toBe('La liquidità secondo la contabilità è '.nsfE('4.650,00').'. Non è il saldo dell\'estratto conto della banca: il confronto lo fa l\'amministratore.')
        ->and($sintesi['riquadri']['liquidita']['liquidita'])->toBe(465000)
        // G7: Neri +100,00, Bianchi +29,75, Rossi +30,25, Verdi +120,00 (40,00 + 80,00) = 280,00; le personali 80,00; le comuni 200,00.
        ->and($f['G7']['testo'])->toBe('La proposta di conguaglio, da approvare dall\'assemblea, è di '.nsfE('280,00').' in tutto: '.nsfE('200,00').' di spese comuni e '.nsfE('80,00').' di spese personali non ancora chieste. 4 persone hanno un conguaglio da versare, nessuna a credito.')
        ->and(\App\Services\Gestionale\ConguaglioDelRendiconto::totaliDelCondominio($l['letto'])['conguaglio'])->toBe(28000)
        ->and($f['G7']['voto'])->toBe('La proposta di conguaglio: '.nsfE('280,00').' in tutto.')
        ->and($f['G9:R6']['testo'])->toBe('Il controllo «Quadratura patrimoniale» non torna.')
        ->and($f['G9:R8']['testo'])->toBe('Il controllo «Vincoli e casse fondo» è da leggere.')
        ->and($f['G11']['testo'])->toBe('Nell\'esercizio è stato registrato un passaggio di proprietà: unità '.$s['unita']['U2']->etichetta.', dal 01/07/2026.')
        // R3: le tre fatture non pagate, 3.300,00 + 900,00 + 80,00 = 4.280,00, scadute il 31/10 e il 10/09.
        ->and($f['R3']['testo'])->toBe('Alla fine dell\'esercizio restavano da pagare 3 fatture per '.nsfE('4.280,00').', tutte con la scadenza passata. Dopo la fine dell\'esercizio, fino al 01/03/2027, non ne è stata pagata nessuna.')
        ->and($l['controlli']['pagina']['debiti']['totale_residui'])->toBe(428000)
        // Q1: Neri, la quarta rata, 500,00 − 200,00 = 300,00, scaduta da oltre 90 giorni. Le quote dei piani già scadute alla fine dell'esercizio (le quattro
        // rate): 4.000,00 meno il credito di Verdi dell'anno prima, 50,00, nella prima rata = 3.950,00; non versate 300,00; 300 / 3.950 = 7,59 % → 7,6 %.
        ->and($f['Q1']['testo'])->toBe('Alla fine dell\'esercizio 1 persona aveva rate scadute e non versate per '.nsfE('300,00').', tutte da oltre 90 giorni. Delle quote dei piani dell\'esercizio già scadute, '.nsfE('3.950,00').', non sono state versate '.nsfE('300,00').': il 7,6'.NSF_SP.'%. Dopo la fine dell\'esercizio, fino al 01/03/2027, non ne è stato versato niente.')
        ->and([$sintesi['prospetto']['quote_piani'], $sintesi['prospetto']['quote_non_pagate']])->toBe([395000, 30000])
        ->and([$sintesi['prospetto']['quote_scadute'], $sintesi['prospetto']['quote_scadute_non_pagate']])->toBe([395000, 30000])
        // Q3: la rata è del piano ordinario del 2026 e scade nel 2026: le due letture danno la stessa data, 31/12/2026 + sei mesi.
        ->and($f['Q3']['testo'])->toBe('Per le rate scadute nell\'esercizio e non versate, il termine di sei mesi entro cui l\'amministratore deve agire (art. 1129, comma 9, c.c.) cade il 30/06/2027.')
        ->and($f['N4']['testo'])->toBe(FattiDellaNota::N4)
        ->and($non)->toHaveKeys(['K5', 'G3', 'G4', 'G8', 'G10', 'G12', 'R1', 'R2', 'R4', 'R5', 'R6', 'Q2', 'Q4', 'Q5', 'Q6', 'Q8'])
        ->and($non['G3'])->toBe('È il primo esercizio nel programma.');
    $consuntivo = app(\App\Services\Gestionale\PosizioniDeiCondomini::class)->crediti($s['c'], $s['e']);
    expect($consuntivo['totali']['scaduto'])->toBe(30000)->and($consuntivo['totali']['fasce']['oltre_90'])->toBe(30000);
});

it('Via Roma: ogni fatto con la frase esatta e la cifra della scheda da cui viene', function () {
    $s = vrmScenario($this);
    $this->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));
    ['fatti' => $f, 'letture' => $l] = nsfFatti($s);
    $crediti = app(\App\Services\Gestionale\PosizioniDeiCondomini::class)->crediti($s['c'], $s['e']);

    expect(array_keys($f))->toBe(['K1', 'K2', 'K3', 'K4', 'G1', 'G2', 'G4', 'N3', 'G6', 'G7', 'G8', 'G9:R8', 'G12', 'R3', 'Q1', 'Q3', 'Q4', 'N4', 'N5'])
        // G1: i costi del giornale 4.131,00 e le quote 4.110,00 (12 rate da 342,50): 4.110,00 − 4.131,00 = −21,00.
        ->and($f['G1']['testo'])->toBe('Le spese dell\'esercizio sono '.nsfE('4.131,00').' e le quote chieste ai condòmini con le rate sono '.nsfE('4.110,00').': le spese superano le quote di '.nsfE('21,00').' (disavanzo).')
        // G2: Pulizie 2.900,00 − 3.000,00; Spese bancarie 96,00 + 2,50 − 150,00; Manutenzioni 1.220,00 (1.000,00 + IVA 22 %) − 1.200,00, la contestata fuori.
        ->and($f['G2']['testo'])->toBe("Le voci che si sono scostate da quanto l'assemblea ha approvato con le rate:\n– Pulizie: ".nsfE('2.900,00').' contro '.nsfE('3.000,00').' ('.nsfE('100,00')
            ." in meno).\n– Spese bancarie: ".nsfE('98,50').' contro '.nsfE('150,00').' ('.nsfE('51,50')." in meno).\n– Manutenzioni: ".nsfE('1.220,00').' contro '.nsfE('1.200,00').' ('.nsfE('20,00').' in più).')
        ->and($f['G4']['testo'])->toBe('Nell\'anno sono entrati '.nsfE('240,00').' che non sono quote dei condòmini (Affitto antenna): riducono le spese da dividere.')
        // G6: 5.000,00 + 3.700,00 + 240,00 − (2.402,50 + 1.182,50 + 40,00 + 96,00) = 5.219,00.
        ->and($f['G6']['testo'])->toBe('La liquidità secondo la contabilità è '.nsfE('5.219,00').'. Non è il saldo dell\'estratto conto della banca: il confronto lo fa l\'amministratore.')
        // G7: divise 4.131,00 − 2,50 senza voce − 150,00 contestata = 3.978,50; 3.978,50 − 4.110,00 = −131,50, a credito di Ugo e di Bice.
        ->and($f['G7']['testo'])->toBe('La proposta di conguaglio, da approvare dall\'assemblea, è di '.nsfE('131,50').' a credito in tutto: '.nsfE('131,50').' di quote chieste oltre le spese comuni. Nessuna persona ha un conguaglio da versare; 2 persone sono a credito, per '.nsfE('131,50').'.')
        ->and($f['G8']['testo'])->toBe(nsfE('2,50').' di commissioni senza voce e '.nsfE('150,00').' di fatture contestate non sono divisi fra i condòmini: sono nel risultato, non nel conguaglio.')
        ->and($f['G12']['testo'])->toBe('Il piano delle rate «Preventivo 2026» è stato approvato dall\'assemblea del 02/01/2026.')
        // R3: FT-1 2.900,00 − 2.400,00 = 500,00 e la FT-3 contestata 150,00 = 650,00; scadute il 10/04.
        ->and($f['R3']['testo'])->toBe('Alla fine dell\'esercizio restavano da pagare 2 fatture per '.nsfE('650,00').', tutte con la scadenza passata; di queste 1 è contestata. Dopo la fine dell\'esercizio, fino al 01/03/2027, non ne è stata pagata nessuna.')
        // Q1: Bice, 2.055,00 − 1.645,00 = 410,00; 410 / 4.110 = 9,98 % → 10 %.
        ->and($f['Q1']['testo'])->toBe('Alla fine dell\'esercizio 1 persona aveva rate scadute e non versate per '.nsfE('410,00').': '.nsfE(number_format($crediti['totali']['fasce']['1_30'] / 100, 2, ',', '.')).' da non più di 30 giorni, '
            .nsfE(number_format($crediti['totali']['fasce']['31_90'] / 100, 2, ',', '.')).' da 31 a 90 giorni. Delle quote dei piani dell\'esercizio già scadute, '.nsfE('4.110,00').', non sono state versate '.nsfE('410,00').': il 10'.NSF_SP.'%. Dopo la fine dell\'esercizio, fino al 01/03/2027, non ne è stato versato niente.')
        ->and($crediti['totali']['scaduto'])->toBe(41000)
        ->and($f['Q4']['testo'])->toBe('Una fattura di '.nsfE('150,00').' è contestata: non è divisa fra i condòmini, è fra i debiti e nel risultato.');
});

/** Un riepilogo con le voci date, nella forma di `RiepilogoPerCompetenza`: una gestione ordinaria, una voce per capitolo. */
function nsfRiepilogo(array $voci, ?array $annoPrima = ['id' => 1, 'nome' => 'Esercizio 2025'], array $entrate = []): array
{
    return ['anno_prima' => $annoPrima, 'blocchi' => [['gestione' => ['tipo' => 'ordinaria'], 'spese' => $voci, 'entrate' => $entrate]]];
}

function nsfVoce(string $nome, int $consuntivo, ?int $deliberato, ?int $preventivo, ?int $annoPrima = null): array
{
    static $id = 0;

    return ['voce_id' => ++$id, 'nome' => $nome, 'consuntivo' => $consuntivo, 'deliberato' => $deliberato, 'preventivo' => $preventivo, 'anno_prima' => $annoPrima,
        'scostamento' => $deliberato === null ? null : $consuntivo - $deliberato, 'scostamento_preventivo' => $preventivo === null ? null : $consuntivo - $preventivo];
}

it('ogni caso che smentirebbe un fatto: il fatto tace o dice la cosa vera', function () {
    // G2: il deliberato non disponibile (piani di prima che non si ricostruiscono): il confronto è con il preventivo, e la frase lo dice.
    [$g2] = FattiDellaNota::g2(nsfRiepilogo([nsfVoce('Pulizie', 330000, null, 300000), nsfVoce('Ascensore', 50000, null, 50000)]));
    expect($g2['frase'])->toBe('Le voci che si sono scostate da quanto era previsto nel preventivo:')
        ->and(array_column($g2['righe'], 'testo'))->toBe(['Pulizie: '.nsfE('3.300,00').' contro '.nsfE('3.000,00').' ('.nsfE('300,00').' in più).'])
        // L'ascensore ha speso quanto previsto: non è fra le voci scostate, ed è fra «le altre voci».
        ->and($g2['coda'])->toBe('Le altre voci sono nel riepilogo finanziario.');
    // G2: né il deliberato né il preventivo: il fatto tace, la pagina dice perché.
    [$nessuno, $perche] = FattiDellaNota::g2(nsfRiepilogo([nsfVoce('Pulizie', 330000, null, null)]));
    expect($nessuno)->toBeNull()->and($perche)->toContain('Né il deliberato con le rate né il preventivo');
    // G2: sei voci scostate: le cinque più grandi in valore, il segno non conta; la sesta è fra «le altre».
    [$sei] = FattiDellaNota::g2(nsfRiepilogo(array_map(fn ($i) => nsfVoce('V'.$i, 10000 + $i * ($i % 2 ? 100 : -100), 10000, null), range(1, 6))));
    expect(array_map(fn ($r) => explode(':', $r['testo'])[0], $sei['righe']))->toBe(['V6', 'V5', 'V4', 'V3', 'V2'])->and($sei['frase'])->toContain('di più');

    // G3: il primo esercizio nel programma; una gestione nuova ogni anno, con voci nuove (lo speso dell'anno prima è zero su tutte): il fatto tace.
    expect(FattiDellaNota::g3(nsfRiepilogo([nsfVoce('Pulizie', 420000, null, null)], null))[0])->toBeNull()
        ->and(FattiDellaNota::g3(nsfRiepilogo([nsfVoce('Pulizie', 420000, null, null, 0)]))[0])->toBeNull()
        ->and(FattiDellaNota::g3(nsfRiepilogo([nsfVoce('Pulizie', 420000, null, null, 0)]))[1])->toContain('una gestione nuova ha voci nuove');
    // G3 su una voce sola delle due che c'erano: la frase dice «sulle voci che c'erano anche nell'esercizio prima».
    [$g3] = FattiDellaNota::g3(nsfRiepilogo([nsfVoce('Pulizie', 330000, null, null, 356067), nsfVoce('Nuova', 90000, null, null, 0)]));
    expect($g3['frase'])->toBe('Sulle voci che c\'erano anche nell\'esercizio prima, le spese del riepilogo finanziario sono '.nsfE('3.300,00').', '.nsfE('260,67').' in meno rispetto a quelle dell\'esercizio prima ('.nsfE('3.560,67').').');

    // G1: l'avanzo e il pari.
    expect(FattiDellaNota::g1(['costi' => 400000, 'quote_giornale' => 410000, 'risultato' => 10000])['frase'])->toEndWith('le quote superano le spese di '.nsfE('100,00').' (avanzo).')
        ->and(FattiDellaNota::g1(['costi' => 400000, 'quote_giornale' => 400000, 'risultato' => 0])['frase'])->toEndWith(': spese e quote si equivalgono (in pareggio).');

    // R1: l'opera senza la data di fine; «previsti con le rate», non «chiesti» (la cifra comprende le quote non ancora emesse).
    $r1 = FattiDellaNota::r1(['gestioni' => [7 => ['nome' => 'Facciata', 'in_corso' => ['spesi' => 3930000, 'chiesti' => 6000000, 'data_fine' => null]]]]);
    expect($r1['frase'])->toBe('Lavori «Facciata» (gestione straordinaria): in corso (la gestione non ha la data di fine); spesi finora '.nsfE('39.300,00').', previsti con le rate '.nsfE('60.000,00').'.');

    // Q1: la prima rata dell'anno dopo, emessa a dicembre e non ancora scaduta, non conta; il pregresso senza anzianità sta oltre i 90 giorni, e la
    // frase lo dice. Mai i nomi.
    $righe = [
        ['anagrafica_id' => 1, 'nome' => 'Neri', 'scaduta' => true, 'importo' => 30000, 'fascia' => 'oltre_90', 'giorni' => 120, 'origine' => null, 'versato_dopo' => 10000],
        ['anagrafica_id' => 2, 'nome' => 'Gialli', 'scaduta' => true, 'importo' => 5000, 'fascia' => 'oltre_90', 'giorni' => null, 'origine' => 'origine non dichiarata', 'versato_dopo' => 0],
        ['anagrafica_id' => 3, 'nome' => 'Rosa', 'scaduta' => false, 'importo' => 25000, 'fascia' => null, 'giorni' => null, 'origine' => null, 'versato_dopo' => 0],
    ];
    $q1 = FattiDellaNota::q1(['righe' => $righe], ['quote_piani' => 0, 'quote_non_pagate' => 0], true, '2027-03-01');
    expect($q1['frase'])->toBe('Alla fine dell\'esercizio 2 persone avevano rate scadute e non versate per '.nsfE('350,00').', tutte da oltre 90 giorni (di cui '.nsfE('50,00')
        .' di anzianità non nota). Dopo la fine dell\'esercizio, fino al 01/03/2027, ne sono stati versati '.nsfE('100,00').'.')
        ->and($q1['frase'])->not->toContain('Neri')->not->toContain('Gialli')->not->toContain('Rosa')
        // Solo la rata non scaduta: nessun fatto.
        ->and(FattiDellaNota::q1(['righe' => [$righe[2]]], [], true, '2027-03-01'))->toBeNull()
        // A esercizio aperto: «a oggi», senza il versato dopo.
        ->and(FattiDellaNota::q1(['righe' => [$righe[0]]], [], false, '2026-10-01')['frase'])->toBe('A oggi 1 persona ha rate scadute e non versate per '.nsfE('300,00').', tutte da oltre 90 giorni.');

    // G7: le righe non disponibili, che i totali non contano: la frase lo dice; l'opera in corso si conguaglia quando è finita.
    $letto = ['gestioni' => [1 => ['nome' => 'Ordinaria', 'totali' => ['a' => 0, 'personali' => 0, 'b' => 0, 'conguaglio' => 10000, 'posizione' => 0, 'fuori_dalle_rate' => 0, 'saldo' => 0,
        'non_disponibili' => 1, 'a_non_disponibili' => 7134], 'non_attribuite' => []], 2 => ['nome' => 'Facciata', 'in_corso' => ['spesi' => 1, 'chiesti' => 1, 'data_fine' => null],
        'totali' => ['conguaglio' => 0], 'non_attribuite' => []]],
        'persone' => [1 => [10 => [100 => ['conguaglio' => 10000]], 11 => [101 => ['conguaglio' => null]]]], 'da_altre_gestioni' => ['totali' => ['posizione' => 0, 'personali' => 0, 'conguaglio' => 0, 'saldo' => 0], 'persone' => []]];
    $g7 = FattiDellaNota::g7($letto, []);
    expect($g7['frase'])->toBe('La proposta di conguaglio, da approvare dall\'assemblea, è di '.nsfE('100,00').' in tutto: '.nsfE('100,00').' di spese comuni. 1 persona ha un conguaglio da versare, nessuna a credito. '
        .'Per i lavori «Facciata», in corso, il conguaglio si propone quando l\'opera è finita. Le righe non disponibili (1, spese dell\'esercizio '.nsfE('71,34').') non sono nei totali.');
});

it('i fatti che dipendono da un controllo: K3 tace se il conguaglio non divide al centesimo, il controllo da leggere si stampa solo con la spiegazione', function () {
    $s = nsfViaVerdi($this);
    $nota = app(NotaSinteticaDelRendiconto::class);
    $letture = $nota->letture($s['c'], $s['e']);
    // Il controllo del conguaglio che non torna: la frase dei centesimi non si propone.
    $letture['controlli']['controlli'] = array_map(fn ($c) => $c['id'] === 'CG' ? ['esito' => 'non_quadra'] + $c : $c, $letture['controlli']['controlli']);
    $f = app(FattiDellaNota::class)->perEsercizio($s['c'], $s['e'], $letture);
    expect(array_column($f['fatti'], 'chiave'))->not->toContain('K3')
        ->and(array_column($f['non_proposti'], 'perche', 'chiave')['K3'])->toContain('non torna');

    // Il controllo da leggere tenuto senza spiegazione non si stampa; con la spiegazione sì, con le sue parole.
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['fatti' => ['G9:R8' => ['stato' => 'tenuto'], 'G9:R6' => ['stato' => 'tenuto']],
        'spiegazioni' => ['R6' => 'Il saldo di apertura della banca non è ancora registrato a giornale']]), $this->user);
    $stampa = $nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e']));
    $frasi = array_column(collect($stampa['parti'])->firstWhere('chiave', 'gestione')['fatti'], 'testo', 'chiave');
    // La spiegazione è una frase sua, dopo quella del programma: com'è scritta, con la sua maiuscola.
    expect($frasi)->toBe(['G9:R6' => 'Il controllo «Quadratura patrimoniale» non torna. Il saldo di apertura della banca non è ancora registrato a giornale.'])
        ->and($stampa['spiegati'])->toBe(1);
});

it('le lettere: conta una lettera partita o spedita, non una solo stampata; dopo la fine dell\'esercizio, fino al giorno della nota', function () {
    $s = nsfViaVerdi($this);
    $neri = $s['persone']['Neri']->id;
    $riga = fn (string $tipo, string $stato, string $quando, array $dati = []) => DB::table('registro_invii')->insert(['condominio_id' => $s['c']->id, 'anagrafica_id' => $neri,
        'tipo' => $tipo, 'canale' => $stato === 'inviata' ? 'email' : 'stampa', 'documentabile_type' => \App\Models\Anagrafica::class, 'documentabile_id' => $neri,
        'stato' => $stato, 'emesso_il' => \Carbon\Carbon::parse($quando, 'Europe/Rome')->utc(), 'dati' => json_encode($dati), 'created_at' => now(), 'updated_at' => now()]);
    $riga('sollecito', 'inviata', '2026-06-10 09:00');
    $riga('sollecito', 'stampata', '2026-07-10 09:00');
    $riga('diffida', 'spedita', '2026-11-10 09:00', ['spedizione' => ['canale' => 'raccomandata', 'data' => '2026-11-12']]);
    $riga('promemoria', 'inviata', '2026-02-10 09:00');
    $riga('sollecito', 'inviata', '2027-01-20 09:00');
    ['fatti' => $f] = nsfFatti($s);
    expect($f['Q2']['testo'])->toBe('Nell\'esercizio sono state mandate 1 lettera di sollecito e 1 diffida. Dopo la fine dell\'esercizio, fino al 01/03/2027, è stata mandata 1 lettera di sollecito.');
});

it('le parole che il programma non scrive mai, in tutte le frasi di tutti i casi', function () {
    $tutte = [];
    $s = nsfViaVerdi($this);
    foreach (nsfFatti($s)['fatti'] as $x) {
        $tutte[] = $x['testo'];
        $tutte[] = (string) ($x['voto'] ?? '');
    }
    $roma = vrmScenario($this);
    $this->travelTo(\Carbon\Carbon::parse('2027-03-01 10:00', 'Europe/Rome'));
    foreach (nsfFatti($roma)['fatti'] as $x) {
        $tutte[] = $x['testo'];
    }
    $tutte[] = FattiDellaNota::N5_NEL_FASCICOLO;
    $tutte[] = NotaSinteticaDelRendiconto::FRASE_SENZA_NOTA;
    foreach (NotaSinteticaDelRendiconto::DICHIARAZIONE as $d) {
        $tutte[] = $d['nessuna'];
    }
    $tutte = [...$tutte, ...NotaSinteticaDelRendiconto::mancanze(NotaSinteticaDelRendiconto::normalizza([]), [['id' => 'R8', 'nome' => 'x', 'esito' => 'segnalazione']])];
    foreach ($tutte as $frase) {
        expect(preg_match(NSF_PAROLE_VIETATE, $frase))->toBe(0, $frase);
    }
    // La sonda delle parole: le vede davvero.
    expect(preg_match(NSF_PAROLE_VIETATE, 'Il rendiconto è regolare'))->toBe(1)->and(preg_match(NSF_PAROLE_VIETATE, 'Non c\'è nessuna lite'))->toBe(1);
});

it('scrivere la spiegazione di un controllo è tenerlo; togliere il controllo toglie anche la spiegazione dalla stampa', function () {
    $s = nsfViaVerdi($this);
    $nota = app(NotaSinteticaDelRendiconto::class);
    NotaSinteticaDelRendiconto::salva($s['e'], NotaSinteticaDelRendiconto::normalizza(['spiegazioni' => ['R8' => 'I vincoli si leggono a oggi', 'R6' => 'Il saldo di apertura non è a giornale'],
        'fatti' => ['G9:R6' => ['stato' => 'tolto']]]), $this->user);
    $stampa = $nota->perLaStampa($s['c'], $s['e'], $nota->letture($s['c'], $s['e']));
    expect(array_column(collect($stampa['parti'])->firstWhere('chiave', 'gestione')['fatti'], 'testo', 'chiave'))
        ->toBe(['G9:R8' => 'Il controllo «Vincoli e casse fondo» è da leggere. I vincoli si leggono a oggi.'])
        ->and(NotaSinteticaDelRendiconto::bozzaVuota(NotaSinteticaDelRendiconto::contenuto($s['e'])))->toBeFalse();
});
