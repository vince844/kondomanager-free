<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Enums\Role as Ruolo;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Assemblee\VotoInSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SalaDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';

/**
 * I permessi della sala: la sala e il suo stato si leggono con «Vede le assemblee»; i gesti e le deleghe con «Gestisce le assemblee»; i ruoli `utente` e
 * `fornitore` mai, qualunque permesso abbiano. Ogni gesto si controlla come una richiesta: **un id di un'altra assemblea o di un altro condominio si
 * rifiuta**, gesto per gesto, e non tocca niente; l'assemblea di un altro condominio nell'indirizzo è un 404.
 *
 * Che cosa non copre: le impostazioni (`ImpostazioniDellaSalaTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Le rotte della sala, con il metodo e i parametri. @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
function pdsRotte($s, $a, $delega): array
{
    $p = ['condominio' => $s->c->id, 'assemblea' => $a->id];

    return [
        ['GET', 'admin.gestionale.assemblee.sala', $p],
        ['GET', 'admin.gestionale.assemblee.sala.stato', $p],
        ['POST', 'admin.gestionale.assemblee.sala.gesti', $p],
        ['POST', 'admin.gestionale.assemblee.deleghe.store', $p],
        ['POST', 'admin.gestionale.assemblee.deleghe.revoca', $p + ['delegaRicevuta' => $delega->id]],
        ['GET', 'admin.gestionale.assemblee.deleghe.scansione', $p + ['delegaRicevuta' => $delega->id]],
        ['GET', 'admin.gestionale.assemblee.sala.foglio', $p],
        ['GET', 'admin.gestionale.assemblee.sala.prospetto', $p],
        ['GET', 'admin.gestionale.assemblee.sala.senza_rete', $p],
    ];
}

function pdsSala($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['rossi']->id,
        'delegato' => ['nome' => 'Bianchi Giorgio'], 'ricevuta_il' => '2026-11-26'])->assertSessionHasNoErrors();

    return [$s, $a, DelegaDellAssemblea::query()->firstOrFail()];
}

it('ogni rotta della sala risponde 403 ai condòmini, ai fornitori e a chi nello studio non ha i permessi delle assemblee', function () {
    [$s, $a, $delega] = pdsSala($this);
    $tutti = [Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE];
    $sbagliate = [];
    foreach (['condòmino' => apUtente(Ruolo::UTENTE->value, $tutti), 'fornitore' => apUtente(Ruolo::FORNITORE->value, $tutti),
        'studio senza permessi' => apUtente(null, [])] as $nome => $u) {
        foreach (pdsRotte($s, $a, $delega) as [$metodo, $rotta, $parametri]) {
            $stato = $this->actingAs($u)->json($metodo, route($rotta, $parametri))->status();
            if ($stato !== 403) {
                $sbagliate[] = "{$nome}: {$metodo} {$rotta} → {$stato}";
            }
        }
    }
    expect($sbagliate)->toBe([]);
});

it('con «Vede le assemblee» la sala si legge (anche la scansione, il prospetto e l\'operaio senza rete), e non si mandano gesti né deleghe né il foglio', function () {
    [$s, $a, $delega] = pdsSala($this);
    $vede = apUtente(null, [Permesso::VIEW_ASSEMBLEE]);
    $stati = [];
    foreach (pdsRotte($s, $a, $delega) as [$metodo, $rotta, $parametri]) {
        $stati[$rotta] = $this->actingAs($vede)->json($metodo, route($rotta, $parametri))->status();
    }
    // La scansione e il prospetto si leggono con «Vede»: qui non ci sono ancora (la delega senza scansione, la sala non tenuta), quindi 404 e non 403.
    expect($stati)->toBe(['admin.gestionale.assemblee.sala' => 200, 'admin.gestionale.assemblee.sala.stato' => 200, 'admin.gestionale.assemblee.sala.gesti' => 403,
        'admin.gestionale.assemblee.deleghe.store' => 403, 'admin.gestionale.assemblee.deleghe.revoca' => 403, 'admin.gestionale.assemblee.deleghe.scansione' => 404,
        'admin.gestionale.assemblee.sala.foglio' => 403, 'admin.gestionale.assemblee.sala.prospetto' => 404, 'admin.gestionale.assemblee.sala.senza_rete' => 200])
        ->and($this->actingAs($vede)->getJson(route('admin.gestionale.assemblee.sala.stato', [$s->c, $a]))->json('puo_gestire'))->toBeFalse();
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala', [$s->c, $a]))->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->component('gestionale/assemblee/Sala', false)->where('sala.assemblea.id', $a->id)->where('sala.puo_gestire', true));
});

it('l\'assemblea di un altro condominio nell\'indirizzo è un 404', function () {
    [$s, $a] = pdsSala($this);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $sua = ViaRoma12::ordinaria($altro);

    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.sala.gesti', [$s->c, $sua]), ['dispositivo' => 'x',
        'gesti' => [SalaDiViaRoma12::gesto('entra', [])]])->assertNotFound();
    $this->getJson(route('admin.gestionale.assemblee.sala.stato', [$s->c, $sua]))->assertNotFound();
});

it('ogni gesto con l\'id di un\'altra assemblea o di un altro condominio si rifiuta, e non tocca niente', function () {
    [$s, $a, $delega] = pdsSala($this);
    // Un'altra assemblea dello stesso condominio, aperta, con una presenza, un voto e una delega.
    $b = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $sedutaB = SalaDiViaRoma12::apriLaSeconda($this, $s, $b);
    SalaDiViaRoma12::entrano($this, $s, $b, ['rossi', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $vb = SalaDiViaRoma12::applica($this, $s, $b, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $sedutaB->id, 'punto_id' => $b->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $b, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $vb['votazione_id']], '2026-12-15 19:01'));
    $delegaB = DelegaDellAssemblea::query()->create(['assemblea_id' => $b->id, 'delegante_anagrafica_id' => $s->persone['gallo']->id, 'delegante_nome' => 'Gallo Enzo',
        'unita' => [], 'delegato_nome' => 'Greco Paola', 'arrivata_come' => 'carta_prima', 'ricevuta_il' => '2026-12-10', 'stato' => 'valida']);
    $conflittoB = GestoDellaSala::query()->create(['assemblea_id' => $b->id, 'gesto' => (string) \Illuminate\Support\Str::uuid(), 'dispositivo' => 'x', 'tipo' => 'voto',
        'dati' => [], 'segnato_il' => now(), 'arrivato_il' => now(), 'esito' => 'in_conflitto', 'conflitto' => ['questa' => 'a', 'quella' => 'b']]);
    // E un altro condominio, con le sue persone e la sua assemblea.
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3')->tabella('Proprietà', ['1' => 1000])->titolare('1', 'estraneo');
    $sua = ViaRoma12::ordinaria($altro, [['testo' => 'Rendiconto', 'materia' => 'rendiconto']]);

    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi']);
    $votoB = VotoInSala::query()->where('votazione_id', $vb['votazione_id'])->firstOrFail();
    $estraneo = $altro->persone['estraneo']->id;
    $puntoB = $b->punti()->where('numero', 2)->value('id');
    $puntoAltro = $sua->punti()->value('id');
    $sedutaAltro = $sua->sedute()->value('id');
    $prima = [PresenzaInSala::query()->count(), VotazioneDelPunto::query()->count(), VotoInSala::query()->count(), DelegaDellAssemblea::query()->count(),
        VotoInSala::query()->whereKey($votoB->id)->value('voto')];

    $gesti = [
        ['deserta', ['seduta_id' => $sedutaAltro]],
        ['apri', ['seduta_id' => SalaDiViaRoma12::seduta($b, 'prima')->id, 'presidente' => ['nome' => 'X'], 'segretario' => ['nome' => 'Y']]],
        ['sospendi', ['seduta_id' => $seduta->id, 'prosegue_seduta_id' => $sedutaAltro]],
        ['chiudi', ['seduta_id' => $sedutaB->id]],
        ['entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $estraneo], 'come' => 'in_sala']],
        ['entra', ['seduta_id' => $seduta->id, 'persona' => ['delega_id' => $delegaB->id], 'come' => 'in_sala']],
        ['entra', ['seduta_id' => $sedutaB->id, 'persona' => ['anagrafica_id' => $s->persone['gallo']->id], 'come' => 'in_sala']],
        ['esce', ['seduta_id' => $sedutaB->id, 'persona' => ['anagrafica_id' => $s->persone['rossi']->id]]],
        ['rappresenta', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $estraneo], 'unita' => [1]]],
        ['delega', ['delegante_anagrafica_id' => $estraneo, 'delegato' => ['nome' => 'Greco Paola']]],
        ['delega_revoca', ['delega_id' => $delegaB->id, 'motivo' => 'x']],
        ['delega_decidi', ['delega_id' => $delegaB->id, 'accetta' => false, 'motivo' => 'x']],
        ['si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $puntoB]],
        ['si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $puntoAltro]],
        ['tutti_favorevoli', ['votazione_id' => $vb['votazione_id']]],
        ['voto', ['votazione_id' => $vb['votazione_id'], 'testa' => $votoB->testa, 'voto' => 'contrario']],
        ['conflitto', ['votazione_id' => $vb['votazione_id'], 'testa' => $votoB->testa, 'in_conflitto' => true]],
        ['conferma', ['votazione_id' => $vb['votazione_id'], 'esito' => 'approvata']],
        ['trattato', ['seduta_id' => $seduta->id, 'punto_id' => $puntoAltro]],
        ['dichiarazione', ['seduta_id' => $seduta->id, 'punto_id' => $puntoB, 'nome' => 'X', 'testo' => 'Y']],
        ['dichiarazione', ['seduta_id' => $seduta->id, 'anagrafica_id' => $estraneo, 'testo' => 'Y']],
        ['scegli', ['gesto' => $conflittoB->gesto, 'scelta' => 'questa']],
    ];
    $risposte = SalaDiViaRoma12::manda($this, $s, $a, array_map(fn ($g) => SalaDiViaRoma12::gesto($g[0], $g[1], '2026-12-15 19:30'), $gesti))->assertOk()->json('risposte');

    $applicati = array_values(array_filter(array_map(fn ($r, $g) => $r['esito'] === 'rifiutato' ? null : $g[0] . ' → ' . $r['esito'], $risposte, $gesti)));
    expect($applicati)->toBe([])
        ->and([PresenzaInSala::query()->count(), VotazioneDelPunto::query()->count(), VotoInSala::query()->count(), DelegaDellAssemblea::query()->count(),
            VotoInSala::query()->whereKey($votoB->id)->value('voto')])->toBe($prima)
        ->and($delegaB->refresh()->stato->value)->toBe('valida');
});
