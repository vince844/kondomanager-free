<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Enums\Role as Ruolo;
use App\Models\Assemblee\RigaDiNomina;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';

/**
 * I permessi del verbale: la pagina, lo stato, le versioni, la copia, la pagina del libro, la prova della trasmissione e il registro si leggono con «Vede
 * le assemblee»; scrivere, generare, stampare la bozza e trasmettere con «Gestisce le assemblee»; registrare, sostituire la copia, annullare, il libro di
 * carta e il registro di nomina e revoca con «Registra il verbale sottoscritto». I ruoli `utente` e `fornitore` mai. Ogni id nell'indirizzo è del suo
 * condominio e della sua assemblea, altrimenti 404; un gesto con il verbale di un'altra assemblea si rifiuta.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Le rotte del verbale e del registro lette dal router, con il metodo e i parametri del fixture. @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
function pdvRotte(array $p): array
{
    $rotte = [];
    foreach (Route::getRoutes() as $r) {
        if (! str_contains((string) $r->getActionName(), '\\Assemblee\\Verbale\\')) {
            continue;
        }
        $rotte[] = [collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first(), (string) $r->getName(),
            array_intersect_key($p, array_flip($r->parameterNames()))];
    }

    return $rotte;
}

/** La sala chiusa, il verbale generato, una versione, la delibera del punto 1, la prima convocazione deserta, una riga di nomina. */
function pdvFixture($t): array
{
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($t);
    $verbale = VerbaleDiViaRoma12::genera($t, $s, $a);
    $riga = RigaDiNomina::query()->create(['condominio_id' => $s->c->id, 'evento' => 'nomina', 'amministratore' => 'Andrea Verdi', 'dal' => '2025-03-01',
        'da_dove' => 'carta', 'stato' => 'confermata', 'created_by' => $s->admin->id]);

    return ['s' => $s, 'a' => $a, 'v' => $verbale, 'condominio' => $s->c->id, 'assemblea' => $a->id, 'verbaleDellAssemblea' => $verbale->id,
        'versione' => (int) $verbale->versioni()->value('id'), 'delibera' => (int) \App\Models\Assemblee\Delibera::query()->where('assemblea_id', $a->id)->value('id'),
        'seduta' => SalaDiViaRoma12::seduta($a, 'prima')->id, 'rigaDiNomina' => $riga->id];
}

it('ogni rotta del verbale e del registro risponde 403 ai condòmini, ai fornitori e a chi nello studio non ha i permessi delle assemblee', function () {
    $p = pdvFixture($this);
    $tutti = [Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE, Permesso::REGISTER_VERBALE_SOTTOSCRITTO];
    $sbagliate = [];
    $rotte = pdvRotte($p);
    foreach (['condòmino' => apUtente(Ruolo::UTENTE->value, $tutti), 'fornitore' => apUtente(Ruolo::FORNITORE->value, $tutti),
        'studio senza permessi' => apUtente(null, [])] as $nome => $u) {
        foreach ($rotte as [$metodo, $rotta, $parametri]) {
            $stato = $this->actingAs($u)->json($metodo, route($rotta, $parametri))->status();
            if ($stato !== 403) {
                $sbagliate[] = "{$nome}: {$metodo} {$rotta} → {$stato}";
            }
        }
    }
    expect(count($rotte))->toBe(34)->and($sbagliate)->toBe([]);
});

it('«Vede» legge e basta; «Gestisce» scrive e trasmette ma non registra; «Registra il verbale sottoscritto» registra, annulla, il libro e le nomine', function () {
    $p = pdvFixture($this);
    $vede = apUtente(null, [Permesso::VIEW_ASSEMBLEE]);
    $gestisce = apUtente(null, [Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE]);
    $registra = apUtente(null, [Permesso::VIEW_ASSEMBLEE, Permesso::REGISTER_VERBALE_SOTTOSCRITTO]);
    $daRegistrare = ['registra', 'copia', 'annulla', 'libro', 'registro.partenza', 'registro.libro', 'registro.nomine.store', 'registro.nomine.update',
        'registro.nomine.conferma', 'registro.nomine.annulla'];
    $sbagliate = [];
    foreach (pdvRotte($p) as [$metodo, $rotta, $parametri]) {
        $breve = preg_replace('/^admin\.gestionale\.assemblee\.(verbale\.)?/', '', $rotta);
        $legge = $metodo === 'GET';
        $attese = [
            'vede' => $legge,
            'gestisce' => $legge || ! in_array($breve, $daRegistrare, true),
            'registra' => $legge || in_array($breve, $daRegistrare, true),
        ];
        foreach (['vede' => $vede, 'gestisce' => $gestisce, 'registra' => $registra] as $chi => $u) {
            $stato = $this->actingAs($u)->json($metodo, route($rotta, $parametri))->status();
            if (($stato !== 403) !== $attese[$chi]) {
                $sbagliate[] = "{$chi}: {$metodo} {$breve} → {$stato}";
            }
        }
    }
    expect($sbagliate)->toBe([]);
});

it('il verbale, la versione, la delibera, la seduta e la riga di nomina di un\'altra assemblea o di un altro condominio sono un 404', function () {
    $p = pdvFixture($this);
    [$s, $a, $v] = [$p['s'], $p['a'], $p['v']];
    $b = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $sua = ViaRoma12::ordinaria($altro);
    $rigaAltro = RigaDiNomina::query()->create(['condominio_id' => $altro->c->id, 'evento' => 'nomina', 'amministratore' => 'Altro', 'dal' => '2025-03-01',
        'da_dove' => 'carta', 'stato' => 'confermata', 'created_by' => $s->admin->id]);
    $this->actingAs($s->admin);

    $this->get(route('admin.gestionale.assemblee.verbale.pagina', [$s->c, $sua]))->assertNotFound();
    $this->getJson(route('admin.gestionale.assemblee.verbale.stato', [$s->c, $sua]))->assertNotFound();
    $this->postJson(route('admin.gestionale.assemblee.verbale.aggiorna_sala', [$s->c, $b, $v]))->assertNotFound();
    $this->getJson(route('admin.gestionale.assemblee.verbale.copia.show', [$s->c, $b, $v]))->assertNotFound();
    $this->getJson(route('admin.gestionale.assemblee.verbale.versione', [$s->c, $a, $v, 999999]))->assertNotFound();
    $this->postJson(route('admin.gestionale.assemblee.delibere.impugnata', [$s->c, $b, $p['delibera']]))->assertNotFound();
    $this->getJson(route('admin.gestionale.assemblee.registro.annotazione', [$s->c, $sua->sedute()->value("id")]))->assertNotFound();
    $this->patchJson(route('admin.gestionale.assemblee.registro.nomine.update', [$s->c, $rigaAltro]), ['amministratore' => 'X'])->assertNotFound();
    $this->postJson(route('admin.gestionale.assemblee.registro.nomine.annulla', [$s->c, $rigaAltro]), ['motivo' => 'x'])->assertNotFound();
    expect($rigaAltro->refresh()->amministratore)->toBe('Altro');
});

it('un testo con il verbale di un\'altra assemblea si rifiuta e non tocca niente', function () {
    $p = pdvFixture($this);
    [$s, $a, $v] = [$p['s'], $p['a'], $p['v']];
    $b = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $doc = StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo('Da un\'altra assemblea.')]);
    $prima = $v->parti()->get(['parte', 'revisione'])->toArray();
    $r = VerbaleDiViaRoma12::manda($this, $s, $b, [VerbaleDiViaRoma12::testo($v->id, 'intero', $doc, '2026-12-15 21:00', 1)])->assertOk()->json('risposte.0');
    expect($r['esito'])->toBe('rifiutato')->and($v->parti()->get(['parte', 'revisione'])->toArray())->toBe($prima);
});
