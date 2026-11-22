<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\FontePunto;
use App\Enums\Assemblee\MateriaPunto;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * I sei mesi e la dispensa entrano in assemblea (Coda 736, decisione 650 (1)). L'art. 1129 c. 9 c.c. chiede all'amministratore di agire per i crediti entro
 * sei mesi dalla chiusura dell'esercizio, «salvo che sia stato espressamente dispensato dall'assemblea»: qui il punto che porta la dispensa all'assemblea.
 *
 * **La fonte «Dal programma»** ({@see ProposteDellOrdineDelGiorno}): per ogni riga del riquadro dei sei mesi con il compito aperto (almeno una persona da
 * seguire, la data entro 60 giorni o passata, nessuna risposta che lo tenga chiuso: {@see SeiMesiPerAgire::riassunto()}) il punto «Dispensa dall'agire entro
 * sei mesi per i crediti del 2026», materia «Dispensa dei sei mesi», con l'esercizio del punto e la chiave `sei_mesi:<esercizio>`.
 *
 * **Senza nomi** (decisione 492 (5)): il testo e quello per la bacheca dicono quante posizioni e la cifra; i nomi restano nel riquadro dei sei mesi.
 *
 * **Si chiude con una delibera sul punto**, approvata o respinta, non revocata né annullata. Approvata, il seguito propone «Registra la dispensa» con la
 * delibera e l'esercizio del punto ({@see Seguito\Effetti\SullaDispensa}); registrata la dispensa, le persone escono dalla riga e il compito si chiude.
 * Respinta, l'assemblea ha deciso e l'amministratore agisce: il punto non torna da solo (un memo lo riporta, se l'amministratore vuole). Un rinvio lo
 * rimette fra le proposte, con «rinviato dal», come gli altri sospesi.
 *
 * **Il memo** «Da portare in assemblea» del riquadro e delle rate scadute nasce dalla scheda `sei_mesi` (l'esercizio letto come
 * {@see \App\Models\Gestionale\SeiMesiDellEsercizio}): ha la stessa chiave, e il memo e il punto del programma sono un punto solo (decisione 499 (3)).
 */
final class DispensaInAssemblea
{
    /** Il tipo della scheda, e il prefisso della chiave del punto. */
    public const TIPO = 'sei_mesi';

    /** Gli esiti con cui l'assemblea ha deciso sul punto: la proposta si chiude. Un rinvio o un punto senza voto no. */
    private const ESITI_CHE_CHIUDONO = [EsitoDelibera::APPROVATA, EsitoDelibera::APPROVATA_CON_MODIFICHE, EsitoDelibera::RESPINTA];

    /** Le posizioni scritte in lettere fino a dieci, come nel testo dell'avviso: «tre posizioni scadute». */
    private const IN_LETTERE = [1 => 'una', 2 => 'due', 3 => 'tre', 4 => 'quattro', 5 => 'cinque', 6 => 'sei', 7 => 'sette', 8 => 'otto', 9 => 'nove', 10 => 'dieci'];

    /** «sei_mesi:12». */
    public static function chiave(int $esercizioId): string
    {
        return self::TIPO . ':' . $esercizioId;
    }

    /**
     * I punti «Dal programma»: uno per ogni riga con il compito aperto, senza una delibera che abbia deciso sul punto.
     *
     * @return list<array<string, mixed>>
     */
    public static function proposte(Condominio $condominio): array
    {
        $chiuse = self::chiaviChiuse($condominio);
        $punti = [];
        foreach (self::righe($condominio) as $riga) {
            $esercizio = (int) $riga['esercizio']['id'];
            if (! $riga['compito'] || in_array(self::chiave($esercizio), $chiuse, true)) {
                continue;
            }
            $punti[] = self::punto($esercizio, $riga) + ['fonte' => FontePunto::PROGRAMMA->value, 'frase' => self::frase($riga)];
        }

        return $punti;
    }

    /**
     * Il punto della dispensa per i crediti di un esercizio: il testo con le posizioni e la cifra della riga (senza la riga, il titolo soltanto), quello per
     * la bacheca con la data dei sei mesi, la materia, la chiave, l'esercizio.
     *
     * @param array<string, mixed>|null $riga la riga del riquadro ({@see riga()})
     * @return array{testo: string, testo_pubblico: string, materia: string, chiave_fonte: string, oggetti: list<array{0: string, 1: int, 2: string}>, esercizio_id: int}
     */
    public static function punto(int $esercizioId, ?array $riga): array
    {
        $anno = PropostaDelPunto::anno($esercizioId);
        $titolo = 'Dispensa dall\'agire entro sei mesi' . ($anno !== '' ? ' per i crediti del ' . $anno : '') . ' (art. 1129 c. 9 c.c.)';
        $posizioni = $riga !== null && (int) $riga['quante'] > 0 ? self::posizioni((int) $riga['quante']) . ', ' . MoneyHelper::format((int) $riga['cifra']) : null;

        return [
            'testo' => $titolo . ($posizioni !== null ? ': ' . $posizioni : ''),
            'testo_pubblico' => $titolo . ($posizioni !== null ? "\n" . mb_strtoupper(mb_substr($posizioni, 0, 1)) . mb_substr($posizioni, 1)
                . ': dispensa dall\'agire entro il ' . self::giorno((string) $riga['data']) . '.' : ''),
            'materia' => MateriaPunto::DISPENSA->value,
            'chiave_fonte' => self::chiave($esercizioId),
            'oggetti' => [['esercizio', $esercizioId, 'parla_di']],
            'esercizio_id' => $esercizioId,
        ];
    }

    /** La riga del riquadro dei sei mesi per i crediti di quell'esercizio, o null. @return array<string, mixed>|null */
    public static function riga(Condominio $condominio, int $esercizioId): ?array
    {
        foreach (self::righe($condominio) as $riga) {
            if ((int) $riga['esercizio']['id'] === $esercizioId) {
                return $riga;
            }
        }

        return null;
    }

    /** Il programma propone il punto adesso: il compito è aperto, e nessuna delibera ha deciso sul punto. La finestra del memo lo dice. */
    public static function eAperta(Condominio $condominio, int $esercizioId): bool
    {
        if (in_array(self::chiave($esercizioId), self::chiaviChiuse($condominio), true)) {
            return false;
        }

        return (bool) (self::riga($condominio, $esercizioId)['compito'] ?? false);
    }

    /**
     * Le chiavi con una delibera sul punto, approvata o respinta, non revocata né annullata, in un'assemblea di qualunque stato.
     *
     * @return list<string>
     */
    public static function chiaviChiuse(Condominio $condominio): array
    {
        return DB::table('delibere')->join('assemblee_punti', 'assemblee_punti.id', '=', 'delibere.punto_id')
            ->where('delibere.condominio_id', $condominio->id)
            ->whereIn('delibere.esito', array_map(fn (EsitoDelibera $e) => $e->value, self::ESITI_CHE_CHIUDONO))
            ->whereNull('delibere.revocata_il')->whereNull('delibere.annullata_il')
            ->where('assemblee_punti.chiave_fonte', 'like', self::TIPO . ':%')
            ->distinct()->pluck('assemblee_punti.chiave_fonte')->map(fn ($c) => (string) $c)->values()->all();
    }

    /** «Apri i sei mesi per agire»: la riga dell'esercizio nel riquadro della dashboard del condominio. @return array{voce: string, url: string}|null */
    public static function apri(int $condominioId, int $esercizioId): ?array
    {
        if (! Route::has('admin.gestionale.index') || $condominioId === 0 || $esercizioId === 0) {
            return null;
        }

        return ['voce' => 'Apri i sei mesi per agire', 'url' => route('admin.gestionale.index', ['condominio' => $condominioId], false) . '#sei-mesi-' . $esercizioId];
    }

    /** Le righe del riassunto dei sei mesi; se la lettura non riesce, nessuna (la proposta dell'ordine del giorno va avanti). @return list<array<string, mixed>> */
    private static function righe(Condominio $condominio): array
    {
        try {
            return app(SeiMesiPerAgire::class)->riassunto($condominio)['righe'];
        } catch (Throwable $e) {
            Log::warning('Ordine del giorno proposto: i sei mesi non si sono letti', ['condominio' => $condominio->id, 'errore' => $e->getMessage()]);

            return [];
        }
    }

    /** La riga sotto il punto, per lo studio: la data, e dove sono i nomi. */
    private static function frase(array $riga): string
    {
        $giorno = self::giorno((string) $riga['data']);

        return 'Il compito dei sei mesi è aperto: ' . ((int) $riga['giorni'] < 0 ? 'la data per agire, il ' . $giorno . ', è passata.'
            : 'senza la dispensa dell\'assemblea si agisce entro il ' . $giorno . '.') . ' I nomi sono nel riquadro dei sei mesi.';
    }

    /** «una posizione scaduta», «quattro posizioni scadute», «12 posizioni scadute». */
    private static function posizioni(int $quante): string
    {
        $numero = self::IN_LETTERE[$quante] ?? (string) $quante;

        return $quante === 1 ? $numero . ' posizione scaduta' : $numero . ' posizioni scadute';
    }

    private static function giorno(string $data): string
    {
        return CarbonImmutable::parse(substr($data, 0, 10))->format('d/m/Y');
    }
}
