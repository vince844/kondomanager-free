<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\OrigineAssemblea;
use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\Assemblee\RuoloOggettoPunto;
use App\Enums\Assemblee\StatoMemo;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Condominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * «Scegli la delibera» (R6 e R7 del progetto della tappa 2; decisioni 500 e 502). **Propone, non sceglie**: in testa le proposte con il perché, nell'ordine:
 * (1) il punto **nato da questa scheda**; (2) un punto che **parla della stessa cosa o di ciò che la contiene** (la gestione del piano o della fattura,
 * l'esercizio, la persona dell'accordo); (3) un punto **della stessa materia**, dalla più recente, senza un taglio di anni (§13, rilievo 33); (4) nessun legame:
 * tutte, per assemblea dalla più recente, con la ricerca nel testo. In testa stanno le proposte del livello più alto che c'è, tutte, con la conseguenza accanto
 * dove la delibera muove chi paga. Il calcolo è in PHP sulle delibere del condominio (sono decine, non migliaia).
 *
 * **R7, che cosa si sceglie**: le approvate o approvate con modifiche, del condominio, con il giorno non dopo oggi, né revocate né annullate; per il ruolo
 * `riferimento` anche quelle senza voto (la comunicazione dei lavori urgenti, legge §10). Le altre si vedono spente, con il perché. Tutte le delibere del
 * condominio si vedono, con il perimetro accanto: nessun blocco sul perimetro (decide l'amministratore).
 */
final class DelibereDelCondominio
{
    /**
     * La materia che un ruolo su una scheda cerca, per il livello 3. Le innovazioni, le innovazioni agevolate, la videosorveglianza e i progetti del
     * territorio come i lavori: la fattura dell'impresa, il piano, la gestione trovano la delibera proposta.
     */
    private const MATERIE = [
        'fattura_passiva' => [MateriaPunto::RATIFICA, MateriaPunto::LAVORI, MateriaPunto::INNOVAZIONI, MateriaPunto::INNOVAZIONI_AGEVOLATE,
            MateriaPunto::VIDEOSORVEGLIANZA, MateriaPunto::PROGETTI_TERRITORIO],
        'piano_rate' => [MateriaPunto::LAVORI, MateriaPunto::INNOVAZIONI, MateriaPunto::INNOVAZIONI_AGEVOLATE, MateriaPunto::PREVENTIVO,
            MateriaPunto::COMUNICAZIONE_URGENZA, MateriaPunto::VIDEOSORVEGLIANZA, MateriaPunto::PROGETTI_TERRITORIO],
        'dispensa_sei_mesi' => [MateriaPunto::DISPENSA],
        'accordo_rientro' => [MateriaPunto::ACCORDO],
        'regola_recupero' => [MateriaPunto::REGOLE],
        'cassa' => [MateriaPunto::FONDO],
        'rata_quote' => [MateriaPunto::REGOLE, MateriaPunto::LITI],
        'gestione' => [MateriaPunto::LAVORI, MateriaPunto::INNOVAZIONI, MateriaPunto::INNOVAZIONI_AGEVOLATE, MateriaPunto::VIDEOSORVEGLIANZA,
            MateriaPunto::PROGETTI_TERRITORIO],
    ];

    /**
     * @return array{proposte: list<array<string, mixed>>, gruppi: list<array<string, mixed>>, memo: ?array<string, mixed>, raccolta: ?array{date: int, frase: string}}
     */
    public static function scegli(Condominio $condominio, ?string $tipo, ?int $id, string $ruolo, ?string $cerca): array
    {
        $scheda = $tipo !== null && $id !== null ? SchedaDelCondominio::risolvi($condominio, $tipo, $id) : null;
        $tipoScheda = $scheda === null ? null : SchedaDelCondominio::tipoDi($scheda);
        $delibere = Delibera::query()->where('condominio_id', $condominio->id)->with(['assemblea.tabella', 'punto.oggetti', 'revocataDa.punto'])
            ->orderByDesc('deliberata_il')->orderByDesc('id')->get();
        $contenitori = $scheda === null ? [] : self::contenitori($scheda);
        $materie = array_map(fn ($m) => $m->value, self::MATERIE[$tipoScheda ?? ''] ?? []);
        if ($ruolo === RuoloCollegamento::RIFERIMENTO->value) {
            $materie = [MateriaPunto::COMUNICAZIONE_URGENZA->value, ...$materie];
        }

        $righe = [];
        foreach ($delibere as $d) {
            [$si, $perche] = self::siSceglie($d, $ruolo);
            [$livello, $motivo] = $scheda === null ? [4, null] : self::livello($d, $tipoScheda, (int) $scheda->getKey(), $contenitori, $materie);
            $righe[] = ['delibera' => $d, 'riga' => self::riga($d, $si, $perche) + ['livello' => $livello, 'perche_proposta' => $motivo,
                'conseguenza' => CollegamentiDelleDelibere::conseguenza($scheda, $d)]];
        }

        $proposte = [];
        $migliore = collect($righe)->filter(fn ($r) => $r['riga']['si_sceglie'] && $r['riga']['livello'] < 4)->min(fn ($r) => $r['riga']['livello']);
        if ($migliore !== null) {
            $proposte = collect($righe)->filter(fn ($r) => $r['riga']['si_sceglie'] && $r['riga']['livello'] === $migliore)->pluck('riga')->values()->all();
        }

        $testo = $cerca === null ? '' : mb_strtolower(trim($cerca));
        $gruppi = [];
        foreach ($righe as $r) {
            $d = $r['delibera'];
            if ($testo !== '' && ! str_contains(mb_strtolower($d->testo . ' ' . $d->punto?->testo . ' ' . $d->assemblea?->numero_verbale), $testo)) {
                continue;
            }
            $chiave = (int) $d->assemblea_id;
            $gruppi[$chiave] ??= ['assemblea' => self::assemblea($d), 'delibere' => []];
            $gruppi[$chiave]['delibere'][] = $r['riga'];
        }
        uasort($gruppi, fn ($a, $b) => [$b['assemblea']['data'], $b['assemblea']['id']] <=> [$a['assemblea']['data'], $a['assemblea']['id']]);

        return [
            'proposte' => $proposte,
            'gruppi' => array_values($gruppi),
            'memo' => $scheda === null ? null : self::memoDellaScheda($condominio, $tipoScheda, (int) $scheda->getKey()),
            'raccolta' => self::raccolta($condominio),
        ];
    }

    /**
     * R7: si sceglie per quel ruolo? Con il perché, se no («respinta», «revocata il 15/12/2026»).
     *
     * @return array{0: bool, 1: ?string}
     */
    public static function siSceglie(Delibera $delibera, string $ruolo): array
    {
        if ($delibera->eRevocata()) {
            return [false, 'revocata il ' . $delibera->revocata_il->format('d/m/Y')];
        }
        if ($delibera->eAnnullata()) {
            return [false, 'annullata il ' . $delibera->annullata_il->format('d/m/Y')];
        }
        if ($delibera->deliberata_il !== null && $delibera->deliberata_il->format('Y-m-d') > DateHelper::oggiUtente()) {
            return [false, 'è di un giorno dopo oggi'];
        }
        // La delega all'amministratore scaduta (decisione 622 (3)): si vede, spenta, con il perché; non si sceglie più.
        $delega = Seguito\DelegheDellAmministratore::delega($delibera);
        if ($delega !== null && $delega['fino_al'] !== null && $delega['fino_al'] < DateHelper::oggiUtente()) {
            return [false, 'scaduta il ' . \Illuminate\Support\Carbon::parse($delega['fino_al'])->format('d/m/Y')];
        }
        if ($delibera->eApprovata()) {
            return [true, null];
        }
        if ($delibera->esito === EsitoDelibera::SENZA_VOTO && $ruolo === RuoloCollegamento::RIFERIMENTO->value) {
            return [true, null];
        }

        return [false, mb_strtolower((string) $delibera->esito?->parola())];
    }

    /**
     * Il livello della proposta (R6) e il perché.
     *
     * @param list<array{0: string, 1: int, 2: string}> $contenitori
     * @param list<string> $materie
     * @return array{0: int, 1: ?string}
     */
    private static function livello(Delibera $d, string $tipo, int $id, array $contenitori, array $materie): array
    {
        $punto = $d->punto;
        $oggetti = $punto?->oggetti ?? collect();
        $quando = $d->assemblea?->data?->format('d/m') ?? $d->deliberata_il?->format('d/m');
        if ($oggetti->contains(fn ($o) => $o->ruolo === RuoloOggettoPunto::ORIGINE && $o->oggetto_tipo === $tipo && (int) $o->oggetto_id === $id)) {
            return [1, 'Proposta perché il punto ' . $punto->numero . ' dell\'assemblea del ' . $quando . ' è nato da ' . self::questa($tipo)];
        }
        if ($oggetti->contains(fn ($o) => $o->oggetto_tipo === $tipo && (int) $o->oggetto_id === $id)) {
            return [2, 'Proposta perché il punto ' . $punto->numero . ' dell\'assemblea del ' . $quando . ' parla di ' . self::questa($tipo)];
        }
        foreach ($contenitori as [$cTipo, $cId, $cNome]) {
            if ($oggetti->contains(fn ($o) => $o->oggetto_tipo === $cTipo && (int) $o->oggetto_id === $cId)) {
                return [2, 'Proposta perché parla ' . $cNome];
            }
        }
        if ($punto !== null && in_array($punto->materia?->value, $materie, true)) {
            return [3, 'Proposta perché è della stessa materia: ' . mb_strtolower((string) $punto->materia->parola())];
        }

        return [4, null];
    }

    /** Ciò che contiene la scheda: la gestione, l'esercizio, la persona. @return list<array{0: string, 1: int, 2: string}> */
    private static function contenitori(Model $scheda): array
    {
        $c = [];
        $gestione = $scheda->getAttribute('gestione_id');
        if ($gestione !== null) {
            $nome = DB::table('gestioni')->where('id', $gestione)->value('nome');
            $c[] = ['gestione', (int) $gestione, 'della gestione «' . $nome . '»'];
        }
        $esercizio = $scheda->getAttribute('esercizio_id');
        if ($esercizio !== null) {
            $c[] = ['esercizio', (int) $esercizio, 'dell\'esercizio ' . PropostaDelPunto::anno((int) $esercizio)];
        }
        $persona = $scheda->getAttribute('anagrafica_id');
        if ($persona !== null) {
            $c[] = ['anagrafica', (int) $persona, 'di ' . DB::table('anagrafiche')->where('id', $persona)->value('nome')];
        }
        if ($scheda instanceof \App\Models\Gestionale\DispensaSeiMesi) {
            foreach ($scheda->esercizi()->pluck('esercizi.id') as $e) {
                $c[] = ['esercizio', (int) $e, 'dell\'esercizio ' . PropostaDelPunto::anno((int) $e)];
            }
        }

        return $c;
    }

    private static function questa(string $tipo): string
    {
        return match ($tipo) {
            'fattura_passiva' => 'questa fattura',
            'piano_rate' => 'questo piano',
            'dispensa_sei_mesi' => 'questa dispensa',
            'accordo_rientro' => 'questo accordo',
            'regola_recupero' => 'questa regola',
            'cassa' => 'questo fondo',
            'gestione' => 'questa gestione',
            default => 'questa scheda',
        };
    }

    /** Una delibera nel menu. @return array<string, mixed> */
    public static function riga(Delibera $d, ?bool $siSceglie = null, ?string $perche = null): array
    {
        return [
            'id' => (int) $d->id,
            'testo' => $d->testo,
            'esito' => $d->esito?->value,
            'esito_parola' => $d->esito?->parola(),
            'deliberata_il' => $d->deliberata_il?->format('Y-m-d'),
            'punto' => $d->punto === null ? null : ['id' => (int) $d->punto->id, 'numero' => (int) $d->punto->numero, 'testo' => $d->punto->testo],
            'assemblea_id' => (int) $d->assemblea_id,
            // Il numero del verbale del giorno della delibera (decisione 615 (7)): l'assemblea sospesa e proseguita ne ha due, e ognuna dice il suo.
            'numero_verbale' => DeliberaInParole::numeroDelVerbale($d),
            'frase' => DeliberaInParole::frase($d),
            'si_sceglie' => $siSceglie ?? true,
            'perche' => $perche,
            'revocata' => $d->eRevocata() ? self::fraseDellaRevoca($d) : null,
            'impugnata' => $d->impugnata_il === null ? null : 'Impugnata il ' . $d->impugnata_il->format('d/m/Y'),
            // La delega all'amministratore con il tetto e la scadenza (decisione 622 (3)): «In vigore fino al 31/12/2027 · Fino a € 2.000,00».
            'delega' => Seguito\DelegheDellAmministratore::delega($d),
        ];
    }

    /** R4: «Revocata il 15/12/2026 dalla delibera del 15/12/2026, punto 2». */
    public static function fraseDellaRevoca(Delibera $d): string
    {
        $da = $d->revocataDa;

        return 'Revocata il ' . $d->revocata_il?->format('d/m/Y') . ($da !== null ? ' dalla ' . mb_strtolower(mb_substr(DeliberaInParole::breve($da), 0, 1))
            . mb_substr(DeliberaInParole::breve($da), 1) : '');
    }

    /** @return array<string, mixed> */
    private static function assemblea(Delibera $d): array
    {
        $a = $d->assemblea;

        return [
            'id' => (int) $a->id,
            'nome' => $a->nome(),
            'data' => $a->data?->format('Y-m-d'),
            'a_mano' => $a->origine === OrigineAssemblea::A_MANO,
            'numero_verbale' => $a->numero_verbale,
            // Anche l'assemblea tenuta fuori dal programma: è una riunione di carta registrata a mano, come dice l'archivio.
            'verbale_da_caricare' => $a->stato === \App\Enums\Assemblee\StatoAssemblea::REGISTRATA_A_MANO && $a->verbale_documento_id === null,
            // I condòmini di un gruppo con il nome della tabella (Coda 734): la delibera dei box si riconosce nel piano dei box.
            'perimetro' => $a->ambito === \App\Enums\Assemblee\AmbitoAssemblea::GRUPPO && $a->tabella !== null
                ? 'I condòmini della tabella «' . $a->tabella->nome . '»' : $a->ambito?->parola(),
        ];
    }

    /** «Già nella memoria dal 18/11». @return array<string, mixed>|null */
    private static function memoDellaScheda(Condominio $condominio, string $tipo, int $id): ?array
    {
        $memo = MemoDellAssemblea::query()->where('condominio_id', $condominio->id)->where('origine_tipo', $tipo)->where('origine_id', $id)
            ->whereIn('stato', [StatoMemo::IN_ATTESA->value, StatoMemo::NEL_PUNTO->value])->orderBy('id')->first();

        return $memo === null ? null : ['id' => (int) $memo->id, 'frase' => 'Già nella memoria dal '
            . $memo->created_at?->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m'), 'stato' => $memo->stato?->value];
    }

    /**
     * Le date scritte a mano da raccogliere (R9): il numero e la frase, o `null`. La frase la usano anche l'archivio delle assemblee e il menu di «Scegli
     * la delibera»: «In Via Roma 12 c'è 1 data scritta a mano: raccoglile», «… ci sono 6 date scritte a mano: raccoglile».
     *
     * @return array{date: int, frase: string}|null
     */
    public static function raccolta(Condominio $condominio): ?array
    {
        $date = RaccoltaDelleDate::quanteDate($condominio);

        return $date === 0 ? null : ['date' => $date, 'frase' => 'In ' . $condominio->nome . ($date === 1 ? ' c\'è 1 data scritta a mano'
            : ' ci sono ' . $date . ' date scritte a mano') . ': raccoglile'];
    }
}
