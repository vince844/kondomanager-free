<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

// Fase 1-bis della beta.14: il memo di un gruppo (il punto rinviato in un'assemblea dei condòmini di un gruppo) che una seconda assemblea dello stesso gruppo ha
// preso, e che poi si elimina, resta del suo gruppo: l'ordinaria di tutto il condominio non lo propone spuntato.

use App\Enums\Permission as Permesso;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Scala;
use App\Models\Tabella;
use App\Services\Assemblee\Convocazione\AvvisoInBacheca;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\Assemblee\PreparazioneDellAssemblea;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\Sala\ControlloDellApertura;
use App\Services\Assemblee\Sala\FotografiaDellaSeduta;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\Assemblee\Verbale\ComposizioneDelVerbale;
use App\Services\Assemblee\Verbale\DestinatariDelVerbale;
use App\Services\Assemblee\Verbale\ModelliDelVerbale;
use App\Services\Assemblee\Verbale\RegistroDeiVerbali;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

require_once __DIR__ . '/Sala/SalaDiViaRoma12.php';
require_once __DIR__ . '/Verbale/VerbaleDiViaRoma12.php';

beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Via Roma 12 della sala con gli otto box e la tabella «Box», con il segno o senza. */
function memoGruppoElimViaRoma(TestCase $t, bool $segno = true): Beta51Scenario
{
    $s = SalaDiViaRoma12::nuovo($t);
    $s->pertinenza('22', '9')->pertinenza('23', '12');
    foreach (range(24, 28) as $i) {
        $s->pertinenza((string) $i, (string) ($i + 6));
    }
    $s->titolare('22', 'carla', 'usufruttuario')->titolare('22', 'paolo', 'nuda_proprietario')->titolare('23', 'rita')->titolare('23', 'luca', 'inquilino');
    foreach (range(24, 28) as $i) {
        $s->titolare((string) $i, 'c' . ($i + 6));
    }
    $s->tabella('Box', array_fill_keys(['21', '22', '23', '24', '25', '26', '27', '28'], 125));
    if ($segno) {
        $s->tabelle['Box']->forceFill(['di_un_gruppo' => true])->saveQuietly();
    }

    return $s;
}

/** I tre punti del caso. @return list<array<string, mixed>> */
function memoGruppoElimPunti(): array
{
    return [
        ['testo' => 'Sostituzione della serranda dei box', 'testo_pubblico' => null, 'materia' => 'lavori', 'maggioranza' => 'meta_del_valore', 'conduttore' => null,
            'ambito' => 'condominio', 'palazzina_id' => null, 'scala_id' => null, 'tabella_id' => null, 'importo' => null],
        ['testo' => 'Pulizia dei box: turni e costo', 'testo_pubblico' => null, 'materia' => 'altro', 'maggioranza' => 'ordinaria', 'conduttore' => 'interviene',
            'ambito' => 'condominio', 'palazzina_id' => null, 'scala_id' => null, 'tabella_id' => null, 'importo' => null],
    ];
}

/** «Prepara l'assemblea» dalla rotta, per i condòmini della tabella data. */
function memoGruppoElimPrepara(TestCase $t, Beta51Scenario $s, ?int $tabella = null, array $piu = [])
{
    return $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), $piu + [
        'tipo' => 'straordinaria', 'ambito' => 'gruppo', 'tabella_id' => $tabella ?? $s->tabelle['Box']->id, 'luogo' => 'Sala condominiale, Via Roma 12',
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00'],
            ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '18:30']],
        'proposte' => ['varie'], 'punti' => memoGruppoElimPunti(),
    ]);
}

/** L'assemblea dei box preparata dalla rotta, con i documenti guardati. */
function memoGruppoElimAssemblea(TestCase $t, Beta51Scenario $s): Assemblea
{
    memoGruppoElimPrepara($t, $s)->assertSessionHasNoErrors();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->latest('id')->firstOrFail();
    $a->forceFill(['documenti_visti_il' => now()])->saveQuietly();

    return $a->refresh();
}

/** La prima convocazione aperta lunedì 14/12 alle 8:05, presidente Rossi; poi l'orologio va a mezzogiorno. */
function memoGruppoElimApriLaPrima(TestCase $t, Beta51Scenario $s, Assemblea $a): SedutaDellAssemblea
{
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:05');
    SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => SalaDiViaRoma12::seduta($a, 'prima')->id,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], '2026-12-14 08:05'));
    ConvocazioneDiViaRoma12::oggi('2026-12-14 12:00');

    return SalaDiViaRoma12::seduta($a, 'prima')->refresh();
}

/** Le persone date entrano nella seduta data. @param list<string> $chi */
function memoGruppoElimEntrano(TestCase $t, Beta51Scenario $s, Assemblea $a, SedutaDellAssemblea $seduta, array $chi, string $alle): void
{
    foreach ($chi as $x) {
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone[$x]->id],
            'come' => 'in_sala'], $alle));
    }
}

/** I sette proprietari di box in sala: tutti tranne i Neri del box 22. @return list<string> */
function memoGruppoElimISette(): array
{
    return ['rossi', 'rita', 'c30', 'c31', 'c32', 'c33', 'c34'];
}

/** «Si vota» sul punto 1 e i voti, testa per testa. @param array<string, string> $voti @return array{0: array<string, mixed>, 1: VotazioneDelPunto} */
function memoGruppoElimVota(TestCase $t, Beta51Scenario $s, Assemblea $a, SedutaDellAssemblea $seduta, string $alle, array $voti, int $numero = 1): array
{
    $punto = (int) $a->punti()->where('numero', $numero)->value('id');
    $r = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $punto], $alle));
    $v = VotazioneDelPunto::query()->findOrFail((int) $r['votazione_id']);
    foreach ($voti as $chi => $voto) {
        $testa = collect((array) $v->intervenuti['teste'])->first(fn ($x) => in_array((int) $s->persone[$chi]->id, array_map('intval', (array) $x['persone']), true));
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v->id, 'testa' => $testa['chiave'], 'voto' => $voto], $alle));
    }

    return [$r, $v->refresh()];
}


it('il memo del gruppo preso da una seconda assemblea dei box che poi si elimina resta dei box: l\'ordinaria di tutto il condominio non lo propone spuntato', function () {
    $s = memoGruppoElimViaRoma($this);
    $a = SalaDiViaRoma12::convoca(memoGruppoElimAssemblea($this, $s), $s);
    $seduta = memoGruppoElimApriLaPrima($this, $s, $a);
    memoGruppoElimEntrano($this, $s, $a, $seduta, memoGruppoElimISette(), '2026-12-14 08:10');
    [, $v] = memoGruppoElimVota($this, $s, $a, $seduta, '2026-12-14 08:40', array_fill_keys(['rossi', 'rita', 'c30', 'c31', 'c32', 'c33'], 'favorevole') + ['c34' => 'contrario']);
    VerbaleDiViaRoma12::conferma($this, $s, $a, (int) $v->id, '2026-12-14 08:41');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('trattato', ['seduta_id' => $seduta->id, 'punto_id' => VerbaleDiViaRoma12::punto($a, 3)], '2026-12-14 08:55'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id, 'non_trattati' => 'rinviata'], '2026-12-14 09:00'));
    $memo = \App\Models\Assemblee\MemoDellAssemblea::query()->where('punto_id', VerbaleDiViaRoma12::punto($a, 2))->firstOrFail();
    $chiave = 'memo:' . $memo->id;
    ConvocazioneDiViaRoma12::oggi('2026-12-20 10:00');
    memoGruppoElimPrepara($this, $s, null, ['sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2027-01-18', 'ora' => '08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2027-01-19', 'ora' => '18:30']], 'punti' => [memoGruppoElimPunti()[0]]])->assertSessionHasNoErrors();
    $prossima = Assemblea::query()->where('condominio_id', $s->c->id)->where('ambito', 'gruppo')->latest('id')->firstOrFail();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.punti.dalle-proposte', [$s->c, $prossima]), ['proposte' => [$chiave]])->assertSessionHasNoErrors();
    expect($memo->refresh()->stato?->value)->toBe('nel_punto');

    // L'assemblea dei box in preparazione si elimina (non la si tiene più).
    $this->actingAs($s->admin)->delete(route('admin.gestionale.assemblee.destroy', [$s->c, $prossima]))->assertSessionHasNoErrors();
    $memo->refresh();
    expect($memo->stato?->value)->toBe('in_attesa')->and(\App\Services\Assemblee\MemoriaDelCondominio::gruppoDelMemo($memo))
        ->toBe(['tabella_id' => (int) $s->tabelle['Box']->id, 'nome' => 'Box']);

    // L'ordinaria di tutto il condominio preparata dopo: il memo dei box è fra le sue proposte?
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'tabella_id' => $s->tabelle['Proprietà']->id, 'luogo' => 'Sala condominiale, Via Roma 12',
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2027-01-25', 'ora' => '08:00'],
            ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2027-01-26', 'ora' => '18:30']],
        'proposte' => ['varie'], 'punti' => [],
    ])->assertSessionHasNoErrors();
    $tutti = Assemblea::query()->where('condominio_id', $s->c->id)->where('ambito', 'condominio')->latest('id')->firstOrFail();
    $proposta = collect(ProposteDellOrdineDelGiorno::per($s->c, $tutti))->flatMap(fn ($g) => $g['punti'])->firstWhere('chiave_fonte', $chiave);
    expect($proposta)->toBeNull();
});
