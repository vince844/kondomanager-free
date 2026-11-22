<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\FontePunto;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoAssemblea;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Condominio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo stato di esecuzione delle delibere (R10; decisioni 522 (6), 523 e 622 (7)).
 *
 * - **Il seguito di una delibera** ({@see seguito()}): «Da confermare» finché il riepilogo non è confermato; poi «Da eseguire» finché una cosa da fare è
 *   aperta, «Eseguita» quando ogni cosa è fatta o «Non serve più» (il giorno è l'ultimo). Le righe tolte dal riepilogo non la tengono aperta: restano nel
 *   passo 6 con «Applica». Una delibera respinta con la risposta data è «Respinta · risposta data il 17/12/2026», mai «Eseguita» ({@see RESPINTA_MAI_ESEGUITA}).
 * - **Il punto «Stato di esecuzione delle delibere precedenti»** ({@see proposta()}): proposto di serie, senza voto, con le delibere ancora aperte di sempre e
 *   quelle eseguite dall'ultima ordinaria (per il giorno in cui sono state eseguite, non per quello della delibera); l'elenco si ferma il giorno in cui si
 *   prepara, e va nei dati del punto, per l'avviso e il verbale: porta la frase pubblica del punto (o la riga neutra della materia) e la parola del tipo delle
 *   cose, mai un testo scritto a mano (Cass. 29323/2022).
 * - **«rinviato dal 15/12/2026»** (R8): il sospeso che torna proposto dopo un rinvio lo dice ({@see rinvii()}).
 */
final class StatoDiEsecuzione
{
    public const CHIAVE = 'stato_esecuzione';

    public const TESTO = 'Stato di esecuzione delle delibere precedenti';

    /** Una delibera respinta con la risposta data non è «Eseguita» (fa pensare che la ratifica sia avvenuta). È la risposta proposta: con `false`, «Eseguita». */
    public const RESPINTA_MAI_ESEGUITA = true;

    /**
     * @return array{stato: string, parola: string, eseguita_il: ?string, fatte: int, quante: int}
     */
    public static function seguito(Delibera $delibera): array
    {
        $cose = CosaDaFareDellaDelibera::query()->where('delibera_id', $delibera->id)->get(['stato', 'fatta_il']);
        $quante = $cose->count();
        $fatte = $cose->filter(fn ($c) => $c->stato !== StatoCosaDaFare::DA_FARE)->count();
        if ($delibera->seguito_confermato_il === null) {
            return ['stato' => 'da_confermare', 'parola' => 'Da confermare', 'eseguita_il' => null, 'fatte' => $fatte, 'quante' => $quante];
        }
        if ($fatte < $quante) {
            return ['stato' => 'da_eseguire', 'parola' => 'Da eseguire' . ($quante > 1 ? ' · ' . $fatte . ' di ' . $quante : ''), 'eseguita_il' => null,
                'fatte' => $fatte, 'quante' => $quante];
        }
        if (! $delibera->eApprovata() && $quante === 0) {
            return ['stato' => 'senza_seguito', 'parola' => (string) $delibera->esito?->parola(), 'eseguita_il' => null, 'fatte' => 0, 'quante' => 0];
        }
        $confermata = $delibera->seguito_confermato_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d');
        $ultima = $cose->map(fn ($c) => $c->fatta_il?->format('Y-m-d'))->filter()->push($confermata)->max();
        if (self::RESPINTA_MAI_ESEGUITA && ! $delibera->eApprovata()) {
            return ['stato' => 'risposta_data', 'parola' => 'Respinta · risposta data il ' . Carbon::parse($ultima)->format('d/m/Y'), 'eseguita_il' => $ultima,
                'fatte' => $fatte, 'quante' => $quante];
        }

        return ['stato' => 'eseguita', 'parola' => 'Eseguita', 'eseguita_il' => $ultima, 'fatte' => $fatte, 'quante' => $quante];
    }

    /**
     * Il punto «Stato di esecuzione delle delibere precedenti» per l'assemblea che si prepara, o null se non c'è niente da dire. Senza voto, di serie.
     *
     * @return ?array<string, mixed>
     */
    public static function proposta(Condominio $condominio, ?Assemblea $assemblea): ?array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('delibere_cose_da_fare')) {
            return null;
        }
        $elenco = self::elenco($condominio, $assemblea);
        if ($elenco['aperte'] === [] && $elenco['eseguite'] === []) {
            return null;
        }
        $quante = count($elenco['aperte']) + count($elenco['eseguite']);

        return ['chiave_fonte' => self::CHIAVE, 'testo' => self::TESTO, 'materia' => MateriaPunto::COMUNICAZIONE->value, 'fonte' => FontePunto::DI_SERIE->value,
            'dati' => [self::CHIAVE => $elenco],
            'frase' => 'Una comunicazione dell\'amministratore: non si vota, e il verbale la riporta. ' . ($quante === 1 ? '1 delibera' : $quante . ' delibere')
                . ', l\'elenco fermo al ' . Carbon::parse($elenco['al'])->format('d/m/Y') . '.'];
    }

    /**
     * Le delibere del punto: le aperte di sempre (una cosa da fare aperta), e le eseguite dall'ultima ordinaria tenuta: quelle eseguite da quel giorno in poi
     * (la facciata del 22/10/2026 eseguita il 12/01/2027 è fra le eseguite del punto di maggio, se l'ordinaria è del 15/12), più le delibere di quell'ordinaria.
     * Il giorno dell'elenco è oggi.
     *
     * @return array{al: string, ultima_ordinaria: ?string, aperte: list<array<string, mixed>>, eseguite: list<array<string, mixed>>}
     */
    public static function elenco(Condominio $condominio, ?Assemblea $assemblea): array
    {
        $oggi = DateHelper::oggiUtente();
        $ultima = Assemblea::query()->where('condominio_id', $condominio->id)->where('tipo', TipoAssemblea::ORDINARIA->value)
            ->whereIn('stato', [StatoAssemblea::CHIUSA->value, StatoAssemblea::REGISTRATA_A_MANO->value])
            ->when($assemblea !== null, fn ($q) => $q->whereKeyNot($assemblea->id))->where('data', '<=', $oggi)->orderByDesc('data')->value('data');
        $ultima = $ultima === null ? null : Carbon::parse($ultima)->format('Y-m-d');
        $aperte = CosaDaFareDellaDelibera::query()->where('condominio_id', $condominio->id)->where('stato', StatoCosaDaFare::DA_FARE->value)
            ->pluck('delibera_id')->unique();
        // Eseguite dall'ultima ordinaria in poi: una cosa fatta da quel giorno, o la conferma da quel giorno (le delibere senza cose).
        $fatteDopo = $ultima === null ? collect() : CosaDaFareDellaDelibera::query()->where('condominio_id', $condominio->id)
            ->where('fatta_il', '>=', $ultima)->pluck('delibera_id')->unique();
        $delibere = Delibera::query()->where('condominio_id', $condominio->id)->whereNotNull('seguito_confermato_il')
            ->when($assemblea !== null, fn ($q) => $q->where('assemblea_id', '!=', $assemblea->id))
            ->where(fn ($q) => $q->whereIn('id', $aperte)->when($ultima !== null, fn ($w) => $w->orWhere('deliberata_il', '>=', $ultima)
                ->orWhereIn('id', $fatteDopo)->orWhere('seguito_confermato_il', '>=', $ultima)))
            ->whereIn('esito', [EsitoDelibera::APPROVATA->value, EsitoDelibera::APPROVATA_CON_MODIFICHE->value])
            ->with(['assemblea', 'punto'])->orderBy('deliberata_il')->orderBy('id')->get();
        $righe = ['aperte' => [], 'eseguite' => []];
        foreach ($delibere as $d) {
            $s = self::seguito($d);
            $dellUltima = $ultima !== null && $d->deliberata_il !== null && $d->deliberata_il->format('Y-m-d') >= $ultima;
            if ($s['stato'] === 'eseguita' && ! $dellUltima && ($ultima === null || (string) $s['eseguita_il'] < $ultima)) {
                continue;
            }
            if ($s['stato'] !== 'da_eseguire' && $s['stato'] !== 'eseguita') {
                continue;
            }
            $cose = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->orderBy('id')->get();
            $riga = ['delibera_id' => (int) $d->id, 'assemblea_id' => (int) $d->assemblea_id, 'assemblea' => $d->assemblea?->nome(),
                'deliberata_il' => $d->deliberata_il?->format('Y-m-d'), 'punto' => $d->punto?->numero, 'testo' => self::frasePubblica($d),
                'eseguita_il' => $s['eseguita_il'],
                'cose' => $cose->map(fn (CosaDaFareDellaDelibera $c) => ['testo' => (string) $c->tipo?->parolaNelloStato(), 'stato' => $c->stato?->value,
                    'fatta_il' => $c->fatta_il?->format('Y-m-d'), 'entro_il' => $c->entro_il?->format('Y-m-d')])->values()->all()];
            $righe[$s['stato'] === 'da_eseguire' ? 'aperte' : 'eseguite'][] = $riga;
        }

        return ['al' => $oggi, 'ultima_ordinaria' => $ultima] + $righe;
    }

    /** La frase pubblica del punto, o la riga neutra della sua materia: il testo del punto può portare un nome, e questo elenco va nell'avviso e nel verbale. */
    private static function frasePubblica(Delibera $d): string
    {
        $pubblica = trim((string) ($d->punto?->testo_pubblico ?? ''));

        return $pubblica !== '' ? $pubblica : ($d->punto !== null ? \App\Services\Assemblee\Convocazione\AvvisoInBacheca::rigaNeutra($d->punto)
            : 'Una delibera dell\'assemblea');
    }

    /**
     * R8: il giorno dell'ultimo rinvio di ogni chiave di sospeso del condominio («rinviato dal 15/12/2026»): il punto rinviato libera la chiave alla chiusura,
     * e la proposta lo dice leggendo la delibera rinviata con la stessa chiave.
     *
     * @return array<string, string> chiave => Y-m-d
     */
    public static function rinvii(Condominio $condominio): array
    {
        return DB::table('delibere')->join('assemblee_punti', 'assemblee_punti.id', '=', 'delibere.punto_id')
            ->where('delibere.condominio_id', $condominio->id)->where('delibere.esito', EsitoDelibera::RINVIATA->value)
            ->whereNotNull('assemblee_punti.chiave_fonte')->groupBy('assemblee_punti.chiave_fonte')
            ->selectRaw('assemblee_punti.chiave_fonte as chiave, max(delibere.deliberata_il) as giorno')->pluck('giorno', 'chiave')
            ->map(fn ($g) => Carbon::parse($g)->format('Y-m-d'))->all();
    }
}
