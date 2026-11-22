<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\Campo;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Services\RegistroModifiche\ImprontaDelRegistro;
use App\Services\RegistroModifiche\OscuramentoDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Services\RegistroModifiche\SigilloDelRegistro;
use App\Services\RegistroModifiche\VerificaDelRegistro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 1.12.0-beta.13 (Coda 679, §3 e §13 (4) del progetto, P13): «Verifica l'integrità» rilegge la catena. Una riga cambiata con `DB::table` fa dire «Una riga
 * non torna» indicando quella, e le altre tornano; una riga oscurata da un oscuramento no, ma i suoi valori cambiati dopo sì; il prefisso tolto da una
 * conservazione no, ma un buco sì; due transazioni che si chiudono a rovescio danno una catena intera. E la firma di nascita: una riga aggiunta con il
 * database non entra nella catena («aggiunta fuori dal programma»), una riga «conservazione» scritta a mano non conta, una catena ricalcolata con
 * l'algoritmo pubblico non torna; le chiavi di prima valgono per le righe firmate con loro.
 *
 * Che cosa non copre: l'oscuramento e la conservazione veri (hanno i loro test: qui si scrivono le loro righe nella forma che la verifica legge,
 * `lotto.righe` e `lotto.ultima_sequenza_tolta`/`ultima_impronta_tolta`); due processi veri su MySQL (si simula nascondendo al primo sigillo la riga di
 * una transazione non ancora confermata); chi ha anche la chiave del server (`APP_KEY`) e riscrive righe e firme: la catena e la firma lo rendono visibile
 * a chi non ha la chiave, non lo impediscono (la bozza: «non la impedisce»).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->travelTo(Carbon::parse('2026-12-16 09:12:00', 'Europe/Rome'));
});

function viRiga(string $nome, array $sopra = []): RegistroModifica
{
    return RegistroModifiche::scrivi(array_replace([
        'area' => 'impostazioni', 'oggetto_tipo' => 'regole_recupero', 'oggetto_id' => 3, 'oggetto_nome' => $nome, 'azione' => 'modificata',
        'campi' => [['chiave' => 'tasso_annuo', 'etichetta' => 'Tasso degli interessi di mora', 'prima' => '1,60 %', 'dopo' => '2,00 %',
            'valore_prima' => '1.60', 'valore_dopo' => '2.00', 'segreto' => false]],
        'condomini' => [12 => 'Condominio Via Roma 12'],
    ], $sopra))->fresh();
}

it('un registro intatto è integro: la frase della bozza, il conteggio, il primo giorno, e l\'esito ricordato nella catena', function () {
    viRiga('Regole del recupero dei crediti');
    viRiga('Anagrafica di Ida Romano');
    viRiga('Piano rate «Ordinario 2027»');

    $esito = VerificaDelRegistro::verifica();

    expect($esito->integro)->toBeTrue()
        ->and($esito->righe)->toBe(3)
        ->and($esito->frase())->toBe('Il registro è integro. 3 righe dal 16/12/2026; nessuna è stata toccata fuori dal programma.')
        ->and(VerificaDelRegistro::ultima())->toMatchArray(['integro' => true, 'righe' => 3, 'righe_che_non_tornano' => 0]);
});

it('una riga cambiata con DB::table fa dire «Una riga non torna» indicando quella; le altre tornano', function () {
    viRiga('Anagrafica di Ida Romano');
    $toccata = viRiga('Regole del recupero dei crediti');
    viRiga('Piano rate «Ordinario 2027»');

    DB::table('registro_modifiche')->where('id', $toccata->id)
        ->update(['campi' => json_encode([['chiave' => 'tasso_annuo', 'etichetta' => 'Tasso degli interessi di mora', 'prima' => '1,60 %', 'dopo' => '9,00 %',
            'valore_prima' => '1.60', 'valore_dopo' => '9.00', 'segreto' => false]])]);

    $esito = VerificaDelRegistro::verifica();

    expect($esito->integro)->toBeFalse()
        ->and($esito->nonTornano)->toBe([['riga_id' => $toccata->id, 'sequenza' => 2, 'motivo' => 'valori']])
        ->and($esito->frase())->toBe('Una riga non torna. Quella del 16/12/2026 alle 09:12 (regole del recupero dei crediti) è stata cambiata fuori dal '
            . 'programma, per esempio direttamente nel database. Le altre righe tornano.')
        ->and(VerificaDelRegistro::ultima())->toMatchArray(['integro' => false, 'riga_id' => $toccata->id, 'righe_che_non_tornano' => 1]);
});

it('anche l\'intestazione è nella catena: l\'autore cambiato fuori dal programma non torna', function () {
    viRiga('Anagrafica di Ida Romano');
    $toccata = viRiga('Regole del recupero dei crediti');
    DB::table('registro_modifiche')->where('id', $toccata->id)->update(['autore_nome' => 'Qualcun altro']);

    expect(VerificaDelRegistro::verifica()->nonTornano)->toBe([['riga_id' => $toccata->id, 'sequenza' => 2, 'motivo' => 'impronta']]);
});

it('una riga oscurata da una riga «oscuramento» che la nomina, con l\'impronta dei valori oscurati, non rompe la catena; una oscurata a mano sì', function () {
    $carlo = viRiga('Anagrafica di Carlo Fontana');
    $altra = viRiga('Anagrafica di Ida Romano');
    $oscurati = [['chiave' => 'email', 'etichetta' => 'Email', 'prima' => 'oscurato il 12/03/2027', 'dopo' => 'oscurato il 12/03/2027',
        'valore_prima' => null, 'valore_dopo' => null, 'segreto' => false]];
    $oscuramento = RegistroModifiche::scrivi(['area' => 'persone', 'oggetto_tipo' => 'anagrafica', 'oggetto_id' => 11,
        'oggetto_nome' => 'Anagrafica di Carlo Fontana', 'azione' => 'oscuramento', 'perche' => 'Richiesta scritta del 10/03/2027, protocollo 41',
        'lotto' => ['righe' => [$carlo->id], 'impronte' => [$carlo->id => ImprontaDelRegistro::deiValori(['campi' => $oscurati, 'riepilogo' => null,
            'perche' => null, 'rimando' => null, 'lotto' => null])]]]);

    DB::table('registro_modifiche')->where('id', $carlo->id)->update([
        'campi' => json_encode($oscurati),
        'oscurata_il' => '2027-03-12 10:00:00', 'oscurata_da' => $oscuramento->id,
    ]);
    expect(VerificaDelRegistro::verifica()->integro)->toBeTrue();

    DB::table('registro_modifiche')->where('id', $altra->id)->update(['campi' => null, 'oscurata_il' => '2027-03-12 10:00:00',
        'oscurata_da' => $oscuramento->id]);
    expect(VerificaDelRegistro::verifica()->nonTornano)->toBe([['riga_id' => $altra->id, 'sequenza' => 2, 'motivo' => 'oscuramento']]);
});

it('il prefisso tolto da una conservazione riparte dall\'impronta scritta nella sua riga; un prefisso tolto senza, o un buco in mezzo, no', function () {
    $prima = viRiga('Riga 1');
    $seconda = viRiga('Riga 2');
    $terza = viRiga('Riga 3');
    $quarta = viRiga('Riga 4');

    DB::table('registro_modifiche')->whereIn('id', [$prima->id, $seconda->id])->delete();
    expect(VerificaDelRegistro::verifica()->nonTornano)->toBe([['riga_id' => $terza->id, 'sequenza' => 3, 'motivo' => 'inizio']])
        ->and(VerificaDelRegistro::verifica()->frase())->toContain('Manca la riga prima di quella del 16/12/2026 alle 09:12 (riga 3)');

    RegistroModifiche::scrivi(['area' => 'impostazioni', 'oggetto_tipo' => 'registro', 'oggetto_id' => null, 'oggetto_nome' => 'Registro delle modifiche',
        'azione' => 'conservazione', 'riepilogo' => 'Tolte 2 righe fino al 16/12/2016',
        'lotto' => ['righe_tolte' => 2, 'ultima_sequenza_tolta' => 2, 'ultima_impronta_tolta' => $seconda->impronta]]);
    expect(VerificaDelRegistro::verifica()->integro)->toBeTrue();

    DB::table('registro_modifiche')->where('id', $terza->id)->delete();
    expect(VerificaDelRegistro::verifica()->nonTornano[0])->toBe(['riga_id' => $quarta->id, 'sequenza' => 4, 'motivo' => 'inizio']);
});

it('una riga tolta in mezzo è un buco nelle sequenze; l\'ultima tolta non è più quella della catena', function () {
    viRiga('Riga 1');
    $seconda = viRiga('Riga 2');
    $terza = viRiga('Riga 3');

    DB::table('registro_modifiche')->where('id', $seconda->id)->delete();
    expect(VerificaDelRegistro::verifica()->nonTornano)->toBe([['riga_id' => $terza->id, 'sequenza' => 3, 'motivo' => 'mancante']]);

    $quarta = viRiga('Riga 4');
    DB::table('registro_modifiche')->where('id', $quarta->id)->delete();
    $esito = VerificaDelRegistro::verifica();
    expect(collect($esito->nonTornano)->pluck('motivo')->all())->toBe(['mancante', 'fine']);
});

it('due transazioni che si chiudono a rovescio danno una catena intera (simulato: il primo sigillo non vede la riga non confermata)', function () {
    $prima = viRiga('Riga della transazione che si chiude per seconda');
    $seconda = viRiga('Riga della transazione che si chiude per prima');
    // Si rifà il sigillo come se la prima riga non fosse ancora confermata quando la seconda si chiude.
    DB::table('registro_modifiche')->update(['sequenza' => null, 'impronta_precedente' => null, 'impronta' => null]);
    DB::table('registro_modifiche_catena')->update(['ultima_sequenza' => 0, 'ultima_impronta' => null]);
    DB::table('registro_modifiche')->where('id', $prima->id)->update(['sequenza' => 999999]);
    SigilloDelRegistro::sigilla();
    DB::table('registro_modifiche')->where('id', $prima->id)->update(['sequenza' => null]);
    SigilloDelRegistro::sigilla();

    expect(DB::table('registro_modifiche')->orderBy('sequenza')->pluck('id')->all())->toBe([$seconda->id, $prima->id])
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();
});

it('la verifica sigilla prima di leggere: una riga rimasta da sigillare entra nella catena', function () {
    viRiga('Riga 1');
    $sospesa = viRiga('Riga 2');
    DB::table('registro_modifiche')->where('id', $sospesa->id)->update(['sequenza' => null, 'impronta_precedente' => null, 'impronta' => null]);
    DB::table('registro_modifiche_catena')->update(['ultima_sequenza' => 1,
        'ultima_impronta' => DB::table('registro_modifiche')->where('sequenza', 1)->value('impronta')]);

    $esito = VerificaDelRegistro::verifica();

    expect($esito->integro)->toBeTrue()
        ->and($esito->righe)->toBe(2)
        ->and($esito->daSigillare)->toBe(0);
});

it('una riga oscurata cambiata dopo l\'oscuramento non torna: i suoi valori oscurati hanno l\'impronta nella riga «oscuramento»', function () {
    $ida = Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12', 'email' => 'ida.romano@libero.it']);
    $versato = RegistroModifiche::scrivi(['area' => 'piani_rate', 'oggetto_tipo' => 'voce', 'oggetto_id' => 5, 'oggetto_nome' => 'Voce «Pulizie»',
        'azione' => 'gesto', 'riepilogo' => 'Già versato della voce Pulizie: Ida Romano € 0,00 → € 120,00',
        'campi' => [Campo::nuovo('versato', 'Int. 7, Ida Romano', '€ 0,00', '€ 120,00', 0, 12000)], 'soggetti' => [['anagrafica', $ida->id]]]);
    $this->travelTo(Carbon::parse('2027-03-12 10:00:00', 'Europe/Rome'));
    OscuramentoDelRegistro::oscura('anagrafica', $ida->id, 'Richiesta scritta');
    $oscurata = DB::table('registro_modifiche')->where('id', $versato->id)->first();
    expect($oscurata->riepilogo)->toBe('Già versato della voce Pulizie: oscurato il 12/03/2027 € 0,00 → € 120,00')
        ->and(VerificaDelRegistro::verifica()->integro)->toBeTrue();

    // Fuori dal programma: 120 diventa 1.200, nel riepilogo e nei campi.
    DB::table('registro_modifiche')->where('id', $versato->id)->update([
        'riepilogo' => str_replace('€ 120,00', '€ 1.200,00', (string) $oscurata->riepilogo),
        'campi' => str_replace(['€ 120,00', '12000'], ['€ 1.200,00', '120000'], (string) $oscurata->campi),
    ]);
    $esito = VerificaDelRegistro::verifica();

    expect($esito->integro)->toBeFalse()
        ->and($esito->nonTornano)->toBe([['riga_id' => $versato->id, 'sequenza' => (int) $oscurata->sequenza, 'motivo' => 'valori']]);
});

it('una riga aggiunta con DB::table, senza sequenza e con l\'impronta dei valori giusta, non entra nella catena: «aggiunta fuori dal programma»', function () {
    viRiga('Anagrafica di Ida Romano');
    // Fuori dal programma, con l'algoritmo pubblico: una riga retrodatata, firmata da una persona.
    $valori = ['campi' => [['chiave' => 'iban', 'etichetta' => 'IBAN', 'prima' => 'IT00A', 'dopo' => 'IT99Z', 'valore_prima' => 'IT00A',
        'valore_dopo' => 'IT99Z', 'segreto' => false]], 'riepilogo' => null, 'perche' => null, 'rimando' => null, 'lotto' => null];
    $id = DB::table('registro_modifiche')->insertGetId(['avvenuta_il' => '2026-10-01 06:00:00', 'registrata_il' => '2026-10-01 06:00:00',
        'autore_tipo' => 'persona', 'autore_id' => 3, 'autore_nome' => 'Marco Galli', 'autore_ruolo' => 'collaboratore', 'origine' => 'pagina',
        'area' => 'persone', 'oggetto_tipo' => 'fornitore', 'oggetto_id' => 9, 'oggetto_nome' => 'Fornitore Serramenti Marino', 'azione' => 'modificata',
        'campi' => json_encode($valori['campi']), 'impronta_valori' => ImprontaDelRegistro::deiValori($valori),
        'firma' => hash('sha256', 'una firma inventata')]);

    $esito = VerificaDelRegistro::verifica();

    expect(DB::table('registro_modifiche')->where('id', $id)->value('sequenza'))->toBeNull()
        ->and($esito->integro)->toBeFalse()
        ->and($esito->righe)->toBe(1)
        ->and($esito->nonTornano)->toBe([['riga_id' => $id, 'sequenza' => null, 'motivo' => 'aggiunta']])
        ->and($esito->frase())->toBe('Una riga non torna. Quella del 01/10/2026 alle 08:00 (fornitore Serramenti Marino) è stata aggiunta fuori dal '
            . 'programma, per esempio direttamente nel database. Le altre righe tornano.');
    // Il sigillo la lascia fuori anche le volte dopo, e le righe nate dopo di lei entrano in catena.
    $dopo = viRiga('Regole del recupero dei crediti');
    expect($dopo->sequenza)->toBe(2)
        ->and(DB::table('registro_modifiche')->where('id', $id)->value('sequenza'))->toBeNull();
});

it('una cancellazione fatta passare per conservazione: tolte le prime righe e scritta a mano una riga «conservazione», la verifica non la prende', function () {
    $righe = [viRiga('Riga 1'), viRiga('Riga 2'), viRiga('Riga 3'), viRiga('Riga 4')];
    DB::table('registro_modifiche')->whereIn('id', [$righe[0]->id, $righe[1]->id])->delete();
    $lotto = ['righe_tolte' => 2, 'fino_al' => '2026-12-16 08:12:00', 'ultima_sequenza_tolta' => 2, 'ultima_impronta_tolta' => $righe[1]->impronta,
        'anni' => 10];
    $valori = ['campi' => null, 'riepilogo' => 'Tolte 2 righe, fino a quella del 16/12/2026: più vecchie di 10 anni.', 'perche' => null, 'rimando' => null,
        'lotto' => $lotto];
    $id = DB::table('registro_modifiche')->insertGetId(['avvenuta_il' => '2026-12-16 08:12:00', 'registrata_il' => '2026-12-16 08:12:00',
        'autore_tipo' => 'programma', 'autore_nome' => 'Il programma', 'origine' => 'pianificato', 'area' => 'impostazioni',
        'oggetto_tipo' => 'registro_modifiche', 'oggetto_nome' => 'Registro delle modifiche', 'scheda_tipo' => 'impostazioni_registro_modifiche',
        'azione' => 'conservazione', 'riepilogo' => $valori['riepilogo'], 'lotto' => json_encode($lotto), 'impronta_valori' => ImprontaDelRegistro::deiValori($valori)]);

    $esito = VerificaDelRegistro::verifica();

    expect($esito->integro)->toBeFalse()
        ->and(collect($esito->nonTornano)->pluck('motivo', 'riga_id')->all())->toBe([$righe[2]->id => 'inizio', $id => 'aggiunta']);
});

it('una riga cambiata con le impronte e la catena ricalcolate (l\'algoritmo è pubblico) non torna: la firma nasce con una chiave che il database non ha', function () {
    viRiga('Anagrafica di Ida Romano');
    $toccata = viRiga('Regole del recupero dei crediti');
    viRiga('Piano rate «Ordinario 2027»');

    // Fuori dal programma: il valore cambia, e chi lo cambia ricalcola l'impronta dei valori e la catena da quella riga in poi.
    $campi = [['chiave' => 'tasso_annuo', 'etichetta' => 'Tasso degli interessi di mora', 'prima' => '1,60 %', 'dopo' => '9,00 %',
        'valore_prima' => '1.60', 'valore_dopo' => '9.00', 'segreto' => false]];
    DB::table('registro_modifiche')->where('id', $toccata->id)->update(['campi' => json_encode($campi),
        'impronta_valori' => ImprontaDelRegistro::deiValori(['campi' => $campi, 'riepilogo' => null, 'perche' => null, 'rimando' => null, 'lotto' => null])]);
    $precedente = DB::table('registro_modifiche')->where('sequenza', 1)->value('impronta');
    foreach (DB::table('registro_modifiche')->where('sequenza', '>=', 2)->orderBy('sequenza')->get() as $riga) {
        $testa = ImprontaDelRegistro::intestazione($riga, (int) $riga->sequenza, SigilloDelRegistro::condominiDelle([(int) $riga->id])[(int) $riga->id] ?? [], []);
        $impronta = ImprontaDelRegistro::dellaRiga($precedente, $testa, (string) $riga->impronta_valori);
        DB::table('registro_modifiche')->where('id', $riga->id)->update(['impronta_precedente' => $precedente, 'impronta' => $impronta]);
        $precedente = $impronta;
    }
    DB::table('registro_modifiche_catena')->where('id', 1)->update(['ultima_impronta' => $precedente]);

    expect(VerificaDelRegistro::verifica()->nonTornano)->toBe([['riga_id' => $toccata->id, 'sequenza' => 2, 'motivo' => 'firma']]);
});

it('le righe firmate con una chiave di prima tornano se la chiave è fra quelle di prima (app.previous_keys); senza, non tornano', function () {
    $vecchia = config('app.key');
    $prima = viRiga('Anagrafica di Ida Romano');
    $nuova = 'base64:' . base64_encode(random_bytes(32));
    config(['app.key' => $nuova, 'app.previous_keys' => [$vecchia]]);
    $dopo = viRiga('Regole del recupero dei crediti');

    expect(VerificaDelRegistro::verifica()->integro)->toBeTrue()
        ->and($dopo->sequenza)->toBe(2);

    config(['app.previous_keys' => []]);
    expect(VerificaDelRegistro::verifica()->nonTornano)->toBe([['riga_id' => $prima->id, 'sequenza' => 1, 'motivo' => 'firma']]);
});
