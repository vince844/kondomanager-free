<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\MateriaPunto;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use Tests\TestCase;

require_once __DIR__ . '/../Verbale/VerbaleDiViaRoma12.php';

/**
 * Il Condominio Via Roma 12 del seguito delle delibere, per i test di questa cartella (non è un test): l'ordinaria del 15/12/2026 con i punti dati, tenuta
 * in sala con i gesti veri (tutti presenti: la tabella fa 1.000,00 e ogni maggioranza c'è), ogni punto confermato con l'esito dato, la sala chiusa, il
 * verbale n. 42 generato e registrato dalle rotte vere. Poi «Conferma» del riepilogo dalla sua rotta.
 */
final class SeguitoDiViaRoma12
{
    /** Tutti in sala. @return list<string> */
    public static function tutti(): array
    {
        return ['rossi', 'anna', 'marco', 'carla', 'paolo', 'rita', 'luca', 'bianchi', 'gallo', ...SalaDiViaRoma12::altri()];
    }

    /**
     * L'ordinaria tenuta e registrata. `$esiti`: numero del punto => esito diverso da quello proposto (con un motivo), o [votazione => esito] per il
     * rendiconto. `$prima`: ciò che si fa in preparazione, prima della convocazione (fermare il preventivo).
     *
     * @param list<array<string, mixed>> $punti
     * @param array<int, string|array<int, string>> $esiti
     */
    public static function tenuta(TestCase $t, Beta51Scenario $s, array $punti, array $esiti = [], ?Closure $prima = null, bool $registra = true): Assemblea
    {
        $a = SalaDiViaRoma12::ordinaria($s, $punti);
        if ($prima !== null) {
            $prima($a);
        }
        $a = SalaDiViaRoma12::convoca($a, $s);
        $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
        SalaDiViaRoma12::entrano($t, $s, $a, self::tutti());
        $minuto = 0;
        foreach ($a->punti()->orderBy('numero')->get() as $p) {
            $alle = '2026-12-15 19:' . str_pad((string) (min(59, $minuto += 3)), 2, '0', STR_PAD_LEFT);
            if ($p->maggioranza === Maggioranza::SENZA_VOTO) {
                SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('trattato', ['seduta_id' => $seduta->id, 'punto_id' => $p->id], $alle));
                continue;
            }
            foreach ($p->materia === MateriaPunto::RENDICONTO ? [1, 2] : [1] as $n) {
                $r = SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p->id, 'numero' => $n], $alle));
                $id = (int) $r['votazione_id'];
                SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $id], $alle));
                $v = VotazioneDelPunto::query()->findOrFail($id);
                $voluto = is_array($esiti[$p->numero] ?? null) ? ($esiti[$p->numero][$n] ?? null) : ($n === 1 ? ($esiti[$p->numero] ?? null) : null);
                $esito = $voluto ?? $v->esito_proposto?->value ?? 'approvata';
                VerbaleDiViaRoma12::conferma($t, $s, $a, $id, $alle, $esito, $esito !== $v->esito_proposto?->value ? ['motivo' => 'Così ha deciso l\'assemblea.'] : []);
            }
        }
        SalaDiViaRoma12::applica($t, $s, $a, SalaDiViaRoma12::gesto('chiudi', ['seduta_id' => $seduta->id], '2026-12-15 20:55'));
        if ($registra) {
            self::registra($t, $s, $a->refresh());
        }

        return $a->refresh();
    }

    /** Il verbale generato e registrato con il n. 42, dalle rotte vere. */
    public static function registra(TestCase $t, Beta51Scenario $s, Assemblea $a): void
    {
        $v = VerbaleDiViaRoma12::genera($t, $s, $a);
        VerbaleDiViaRoma12::registra($t, $s, $v)->assertSessionHasNoErrors();
    }

    /** La delibera del punto (la prima votazione, o quella data). */
    public static function delibera(Assemblea $a, int $numero, int $votazione = 1): Delibera
    {
        return Delibera::query()->where('assemblea_id', $a->id)->whereIn('punto_id', $a->punti()->where('numero', $numero)->select('id'))
            ->where('votazione', $votazione)->firstOrFail();
    }

    /** Le righe del riepilogo, per chiave. @return array<string, array<string, mixed>> */
    public static function righe(Assemblea $a): array
    {
        $righe = [];
        foreach (SeguitoDelleDelibere::riepilogo($a)['punti'] as $p) {
            foreach ($p['righe'] as $r) {
                $righe[$r['chiave']] = $r;
            }
        }

        return $righe;
    }

    /** Le chiavi spuntate di serie. @return list<string> */
    public static function spuntate(Assemblea $a): array
    {
        return array_keys(array_filter(self::righe($a), fn ($r) => $r['spuntata'] && $r['si_applica'] && ! $r['applicata']));
    }

    /** «Conferma» dalla rotta vera, con le righe spuntate di serie (o quelle date). @param array<string, mixed> $piu */
    public static function conferma(TestCase $t, Beta51Scenario $s, Assemblea $a, ?array $chiavi = null, array $piu = [])
    {
        return $t->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.conferma', [$s->c, $a]),
            ['chiavi' => $chiavi ?? self::spuntate($a)] + $piu);
    }

    /**
     * L'ordinaria del 15/12/2026 preparata nel programma e tenuta su carta (decisione 622 (4)): il riepilogo si apre appena segnati gli esiti. `$esiti`:
     * numero del punto => esito diverso da «approvata».
     *
     * @param list<array<string, mixed>> $punti
     * @param array<int, string> $esiti
     * @param array<string, mixed> $piu
     */
    public static function suCarta(Beta51Scenario $s, array $punti, string $giorno = '2026-12-15', array $esiti = [], array $piu = []): Assemblea
    {
        $a = ViaRoma12::ordinaria($s, $punti, $piu);
        $e = [];
        foreach ($a->punti()->get() as $p) {
            $e[(int) $p->id] = ['esito' => $esiti[$p->numero] ?? 'approvata'];
        }

        return \App\Services\Assemblee\StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, $e, $giorno);
    }

    /** Una versione del rendiconto 2026 presentata, fermata il giorno dato. */
    public static function versioneDelRendiconto(Beta51Scenario $s, int $numero = 2, string $calcolata = '2026-11-20 10:00:00'): \App\Models\Gestionale\RendicontoVersione
    {
        return \App\Models\Gestionale\RendicontoVersione::create(['condominio_id' => $s->c->id, 'esercizio_id' => $s->e->id, 'numero' => $numero,
            'stato' => \App\Models\Gestionale\RendicontoVersione::PRESENTATA, 'forma' => \App\Models\Gestionale\RendicontoVersione::FORMA,
            'cifre' => ['conguaglio' => ['condominio' => ['conguaglio' => 0]]], 'calcolato_il' => $calcolata, 'pdf' => [], 'creata_da' => $s->admin->id]);
    }

    /** Un compito dell'Action inbox del condominio, con il tipo e il contesto dati. @param array<string, mixed> $contesto */
    public static function compito(Beta51Scenario $s, string $tipo, array $contesto = []): \App\Models\Evento
    {
        $e = \App\Models\Evento::create(['title' => $tipo, 'start_time' => now(), 'end_time' => now()->addHour(), 'visibility' => 'hidden', 'is_approved' => true,
            'meta' => ['type' => $tipo, 'requires_action' => true] + ($contesto === [] ? [] : ['context' => $contesto])]);
        $e->condomini()->attach($s->c->id);

        return $e;
    }

    /** Il punto sul preventivo 2026 dell'esercizio dello scenario. @return array<string, mixed> */
    public static function puntoDelPreventivo(Beta51Scenario $s): array
    {
        return ['testo' => 'Approvazione del preventivo 2026 e del piano di riparto', 'materia' => 'preventivo', 'esercizio_id' => $s->e->id];
    }

    /** La chiave di una riga: la prima che comincia con la delibera e contiene il pezzo dato. */
    public static function chiave(Assemblea $a, Delibera $d, string $pezzo): string
    {
        foreach (array_keys(self::righe($a)) as $k) {
            if (str_starts_with($k, $d->id . ':') && str_contains($k, $pezzo)) {
                return $k;
            }
        }
        throw new \RuntimeException('Nessuna riga «' . $pezzo . '» per la delibera ' . $d->id . ': ' . implode(', ', array_keys(self::righe($a))));
    }
}
