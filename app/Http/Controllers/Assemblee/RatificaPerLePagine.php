<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\Assemblee\StatoAssemblea;
use App\Helpers\MoneyHelper;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\PropostaDelPunto;
use App\Services\Assemblee\RatificaDelloSforo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La ratifica di una spesa oltre il preventivo come la leggono le pagine della fattura (§3 P11, R12; decisione 561; scene 1b e 1d della bozza delle scene
 * della tappa 2): «Da ratificare» per la fattura in sforo e per quella già sbloccata per l'urgenza con il motivo (si paga subito e si riferisce alla prima
 * assemblea, art. 1135 c. 2 c.c.); «Ratificata con la delibera del 15/12/2026, punto 3» quando la delibera è collegata. La delibera sta solo nel
 * collegamento (§13, rilievo 22): la data che le pagine mostrano è quella della riunione, `deliberata_il`, e per le ratifiche di prima, senza la delibera,
 * resta `approvato_il` come prima. Le ratifiche di prima (la nota senza la chiave `urgenza`) sono «Ratificata il … (registrata)» e
 * offrono «Collega la delibera»: non aspettano niente.
 */
final class RatificaPerLePagine
{
    /** Gli id delle fatture del condominio con la delibera della ratifica: una lettura sola per tutto l'elenco. @return array<int, true> */
    public static function ratificate(int $condominioId): array
    {
        return DB::table('delibere_collegamenti')->where('condominio_id', $condominioId)->where('oggetto_tipo', 'fattura_passiva')
            ->where('ruolo', RuoloCollegamento::RATIFICA->value)->pluck('oggetto_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * I campi della riga per il menu e per l'elenco: aspetta la ratifica (in sforo, o sbloccata con il motivo e senza la delibera), lo stato in parole, di
     * quanto supera il preventivo e di quale voce.
     *
     * @param array<int, true> $ratificate
     * @return array{aspetta_la_ratifica: bool, ratifica: ?string, sforo_oltre: ?int, voce_sforo: ?string, motivazione_sforo: ?string}
     */
    public static function perLaRiga(FatturaPassiva $fattura, array $ratificate): array
    {
        $conLaNota = isset(((array) ($fattura->dati_extra ?? []))['ratifica_assembleare']);
        $collegata = isset($ratificate[(int) $fattura->id]);
        $perLUrgenza = RatificaDelloSforo::sbloccataPerLUrgenza($fattura);
        $aspetta = $fattura->stato_approvazione === 'sforo_motivato' || ($perLUrgenza && ! $collegata);
        $sforo = self::sforo($fattura);

        return [
            'aspetta_la_ratifica' => $aspetta,
            // Una ratifica fatta prima della 1.12 con la nota, senza la chiave `urgenza`, è ratificata.
            'ratifica' => $aspetta ? 'da_ratificare' : ($collegata || ($conLaNota && $fattura->stato_approvazione === 'approvata') ? 'ratificata' : null),
            'sforo_oltre' => $sforo['oltre'],
            'voce_sforo' => $sforo['voce'],
            'motivazione_sforo' => $sforo['motivazione'],
        ];
    }

    /**
     * Di quanto la spesa supera il preventivo (centesimi, dalla giustificazione dello sforo), la voce quando le righe ne hanno una sola, e la motivazione
     * scritta alla registrazione: «Paga per l'urgenza» parte già scritta con quella.
     *
     * @return array{oltre: ?int, voce: ?string, motivazione: ?string}
     */
    public static function sforo(FatturaPassiva $fattura): array
    {
        $extra = (array) ($fattura->dati_extra ?? []);
        $oltre = isset($extra['override_budget']['importo_sforo']) ? (int) $extra['override_budget']['importo_sforo'] : null;
        $motivazione = trim((string) ($extra['override_budget']['motivazione'] ?? ''));
        $motivazione = $motivazione === '' ? null : $motivazione;
        if ($oltre === null || $oltre <= 0) {
            return ['oltre' => null, 'voce' => null, 'motivazione' => $motivazione];
        }
        $conti = $fattura->relationLoaded('righe') ? $fattura->righe->pluck('conto_id')->filter()->unique()->values()
            : DB::table('righe_fattura')->where('fattura_passiva_id', $fattura->id)->whereNotNull('conto_id')->distinct()->pluck('conto_id');
        $voce = $conti->count() === 1 ? DB::table('conti')->where('id', $conti->first())->value('nome') : null;

        return ['oltre' => $oltre, 'voce' => $voce === null ? null : (string) $voce, 'motivazione' => $motivazione];
    }

    /**
     * Il riquadro «Ratifica dell'assemblea» della pagina della fattura, o `null` per una fattura che non ha mai chiesto una ratifica.
     *
     * - `stato`: `da_ratificare` o `ratificata`; `in_sforo`: ancora ferma, né sbloccata né ratificata.
     * - `sbloccata`: lo sblocco per l'urgenza con il motivo (`approva-sforo`, il pagamento di oggi): il giorno, chi, il motivo, se è già pagata e la parola
     *   («Sbloccata», «Pagata in parte», «Pagata»).
     * - `delibera`: la frase breve, la riunione, il verbale, chi l'ha registrata e quando, l'assemblea e il punto per «Apri l'assemblea».
     * - `in_assemblea`: il punto proposto per la prossima, o quello già nell'ordine del giorno di un'assemblea in preparazione o convocata.
     *
     * @return array<string, mixed>|null
     */
    public static function perLaPagina(FatturaPassiva $fattura): ?array
    {
        $extra = (array) ($fattura->dati_extra ?? []);
        $nota = (array) ($extra['ratifica_assembleare'] ?? []);
        $collegamento = CollegamentoAllaDelibera::query()->where('oggetto_tipo', 'fattura_passiva')->where('oggetto_id', $fattura->id)
            ->where('ruolo', RuoloCollegamento::RATIFICA->value)->with(['delibera.assemblea', 'delibera.punto'])->first();
        $inSforo = $fattura->stato_approvazione === 'sforo_motivato';
        if (! $inSforo && $nota === [] && $collegamento === null) {
            return null;
        }
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $chi = fn ($id) => $id === null ? null : DB::table('users')->where('id', $id)->value('name');
        $giorno = function ($istante) use ($fuso): ?string {
            if ($istante === null || $istante === '') {
                return null;
            }
            try {
                return Carbon::parse($istante)->setTimezone($fuso)->format('d/m/Y');
            } catch (\Throwable) {
                return null;
            }
        };

        // Lo sblocco per l'urgenza: la nota di `approva-sforo`, con la chiave `urgenza`. Con la delibera, c'è stato uno sblocco prima solo se la nota è più
        // vecchia del collegamento (la ratifica fatta direttamente dalla fattura in sforo scrive la nota e il collegamento nello stesso momento). La stessa
        // nota senza la chiave è una ratifica fatta prima della 1.12: «Ratificata il … (registrata)», con la nota.
        $sbloccata = null;
        $diPrima = null;
        $perLUrgenza = ($nota['urgenza'] ?? false) === true;
        if ($nota !== []) {
            $quando = isset($nota['approvato_il']) ? Carbon::parse($nota['approvato_il']) : null;
            $prima = $collegamento === null || ($quando !== null && $collegamento->collegata_il !== null
                && $quando->lt($collegamento->collegata_il->copy()->subMinute()));
            if ($prima && ! $perLUrgenza && ! $inSforo) {
                $diPrima = ['il' => $giorno($nota['approvato_il'] ?? null), 'nota' => isset($nota['note']) && trim((string) $nota['note']) !== '' ? trim((string) $nota['note']) : null];
            }
            if ($prima && $perLUrgenza) {
                $sbloccata = [
                    'il' => $giorno($nota['approvato_il'] ?? null),
                    'da' => $chi($nota['approvato_da'] ?? null),
                    'motivo' => isset($nota['note']) && trim((string) $nota['note']) !== '' ? trim((string) $nota['note']) : null,
                    // «Sbloccata per l'urgenza» finché non è pagata, «Pagata in parte per l'urgenza» fino al saldo, «Pagata per l'urgenza» dopo: la regola di
                    // `RatificaDelloSforo::parolaDelPagamento()`.
                    'pagata' => RatificaDelloSforo::pagata($fattura),
                    'parola' => RatificaDelloSforo::parolaDelPagamento($fattura),
                ];
            }
        }

        $delibera = null;
        if ($collegamento !== null && $collegamento->delibera !== null) {
            $d = $collegamento->delibera;
            $assemblea = $d->assemblea;
            $delibera = [
                'frase' => 'Ratificata con la ' . lcfirst(self::senzaVerbale(DeliberaInParole::breve($d))),
                'deliberata_il' => $d->deliberata_il?->format('Y-m-d'),
                'assemblea_id' => (int) $d->assemblea_id,
                'punto_id' => $d->punto_id === null ? null : (int) $d->punto_id,
                'assemblea' => $assemblea === null ? null : ($assemblea->eAMano() ? $assemblea->nome()
                    : 'Assemblea ' . mb_strtolower((string) $assemblea->tipo?->parola()) . ' di ' . $assemblea->condominio?->nome),
                'numero_verbale' => DeliberaInParole::numeroDelVerbale($d),
                'registrata_il' => $giorno($collegamento->collegata_il),
                'registrata_da' => $chi($collegamento->collegata_da),
                'nota' => $collegamento->nota,
            ];
        }

        return [
            'stato' => RatificaDelloSforo::aspettaLaRatifica($fattura) ? 'da_ratificare' : 'ratificata',
            'in_sforo' => $inSforo,
            'sforo' => self::sforo($fattura),
            'sbloccata' => $sbloccata,
            'delibera' => $delibera,
            'in_assemblea' => $delibera === null && $diPrima === null ? self::inAssemblea($fattura) : null,
            // Le ratifiche di prima: il giorno del clic e la nota, come prima; con la delibera collegata dopo, restano scritte accanto.
            'di_prima' => $diPrima,
        ];
    }

    /**
     * La riga della ratifica nella pagina di una scrittura: per ogni fattura collegata con la delibera, la frase e l'assemblea; senza la delibera, la data
     * della ratifica come prima (`deliberata_il` se c'è, altrimenti `approvato_il`).
     *
     * @param iterable<FatturaPassiva> $fatture
     * @return array<int, array{frase: ?string, assemblea_id: ?int, punto_id: ?int, giorno: ?string}>
     */
    public static function perLeScritture(iterable $fatture): array
    {
        $esito = [];
        foreach ($fatture as $f) {
            $pagina = self::perLaPagina($f);
            if ($pagina === null) {
                continue;
            }
            $nota = (array) (((array) ($f->dati_extra ?? []))['ratifica_assembleare'] ?? []);
            $esito[(int) $f->id] = [
                'frase' => $pagina['delibera']['frase'] ?? null,
                'assemblea_id' => $pagina['delibera']['assemblea_id'] ?? null,
                'punto_id' => $pagina['delibera']['punto_id'] ?? null,
                'giorno' => $nota['deliberata_il'] ?? $nota['approvato_il'] ?? null,
            ];
        }

        return $esito;
    }

    /**
     * Il punto della fattura: nell'ordine del giorno di un'assemblea in preparazione o convocata, o proposto per la prossima. `frase` resta la frase intera;
     * `testo` è il testo del punto per i condòmini **senza** la riga del motivo (che la pagina mostra a parte, con la sua etichetta); `proposta`, `numero` e `il`
     * dicono dove sta.
     *
     * @return array{frase: string, testo: string, proposta: bool, numero: ?int, il: ?string, assemblea_id: ?int}|null
     */
    private static function inAssemblea(FatturaPassiva $fattura): ?array
    {
        $chiave = PropostaDelPunto::chiave('fattura_passiva', (int) $fattura->id);
        $punto = DB::table('assemblee_punti')->join('assemblee', 'assemblee.id', '=', 'assemblee_punti.assemblea_id')
            ->where('assemblee.condominio_id', $fattura->condominio_id)->where('assemblee_punti.chiave_fonte', $chiave)
            ->whereIn('assemblee.stato', [StatoAssemblea::IN_PREPARAZIONE->value, StatoAssemblea::CONVOCATA->value])
            ->orderBy('assemblee.data')->first(['assemblee.id as assemblea_id', 'assemblee.data', 'assemblee_punti.numero', 'assemblee_punti.testo',
                'assemblee_punti.testo_pubblico']);
        if ($punto !== null) {
            $il = $punto->data !== null ? Carbon::parse($punto->data)->format('d/m/Y') : null;

            return ['frase' => 'È il punto ' . $punto->numero . ' dell\'ordine del giorno' . ($il !== null ? ' del ' . $il : '') . ': «' . $punto->testo . '»',
                'testo' => self::senzaLaMotivazione((string) (trim((string) $punto->testo_pubblico) !== '' ? $punto->testo_pubblico : $punto->testo), $fattura),
                'proposta' => false, 'numero' => (int) $punto->numero, 'il' => $il, 'assemblea_id' => (int) $punto->assemblea_id];
        }
        try {
            $proposta = PropostaDelPunto::perLaScheda($fattura);
        } catch (\Throwable) {
            return null;
        }
        $pubblico = (string) ($proposta['testo_pubblico'] ?? $proposta['testo']);

        return ['frase' => 'Proposta fra i punti della prossima assemblea: «' . $pubblico . '»', 'testo' => self::senzaLaMotivazione($pubblico, $fattura),
            'proposta' => true, 'numero' => null, 'il' => null, 'assemblea_id' => null];
    }

    /** Il testo del punto senza la riga «Perché era urgente: …» o «Motivazione: …» che `PropostaDelPunto` gli aggiunge sotto: ha il suo dato. */
    private static function senzaLaMotivazione(string $testo, FatturaPassiva $fattura): string
    {
        $motivazione = PropostaDelPunto::motivazioneDellaSpesa($fattura);
        if ($motivazione !== null) {
            $riga = "\n" . PropostaDelPunto::fraseDellaMotivazione($motivazione['motivazione'], $motivazione['di']);
            if (str_ends_with($testo, $riga)) {
                return trim(substr($testo, 0, -strlen($riga)));
            }
        }

        return trim($testo);
    }

    /** «Delibera del 15/12/2026, punto 3 (verbale n. 42)» → «Delibera del 15/12/2026, punto 3»: il verbale la pagina lo scrive a parte. */
    private static function senzaVerbale(string $frase): string
    {
        return (string) preg_replace('/\s*\(verbale n\. [^)]*\)$/u', '', $frase);
    }

    /** Un importo in centesimi come si legge. */
    public static function euro(?int $centesimi): ?string
    {
        return $centesimi === null ? null : MoneyHelper::format($centesimi);
    }
}
