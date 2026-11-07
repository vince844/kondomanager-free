<?php

namespace App\Services\Gestionale\Debiti;

use App\Enums\TipoMovimentoContabile;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * L'accordo di rientro letto per l'elenco «Rate scadute» e per le lettere: un registro, senza calcoli di denaro sul giornale.
 *
 * - **In corso a una data** è un accordo con la data non dopo quella e non chiuso prima o quel giorno. Solo gli accordi in corso contano.
 * - **Il segno** di un accordo dice due cifre che il programma sa: i riconosciuti (la somma degli importi riconosciuti delle rate coperte) e
 *   quanto resta oggi, cioè per ogni rata coperta il minore fra il suo residuo alla data e il suo importo riconosciuto. Il programma non sa se
 *   un versamento dopo l'accordo è andato alla parte dell'accordo o all'altra parte della rata: il minore non mette mai nella lettera denaro che
 *   l'accordo può coprire: un versamento su una rata coperta in parte conta prima per la parte fuori dall'accordo. Il segno dice «residuo
 *   invariato» quando il residuo di ogni rata coperta è quello del giorno dell'accordo (un versamento stornato lo lascia uguale); con una rata
 *   coperta in parte un versamento lo cambia anche se la parte in accordo resta la stessa.
 * - **Le lettere** lasciano fuori la parte in accordo di ogni rata finché l'accordo è in corso e «fino al» non è passato; dopo quella data le
 *   rate tornano nelle lettere proposte, e il segno lo dice. Il programma non scrive mai che un accordo è decaduto.
 * - **Versati oltre il capitale riconosciuto**: gli interessi e le spese dentro le rate dell'accordo non hanno ancora un posto nel giornale, e
 *   quando la persona li versa diventano un suo credito. La cifra è la parte in più rimasta a credito, come la conta la ricevuta
 *   ({@see RicevutaIncasso}), degli incassi della persona registrati dalla data dell'accordo che hanno coperto rate dell'accordo. Il programma
 *   non la sposta: usarla, restituirla o lasciarla è dell'amministratore.
 */
final class AccordiRientro
{
    public function __construct(private readonly RicevutaIncasso $ricevuta) {}

    /**
     * Gli accordi in corso alla data, per persona, con le loro rate coperte.
     *
     * @param list<int>|null $persone
     * @return array<int, Collection<int, AccordoRientro>>
     */
    public function inCorso(int $condominioId, string $alla, ?array $persone = null): array
    {
        return AccordoRientro::with('rate')
            ->where('condominio_id', $condominioId)
            ->when($persone !== null, fn ($q) => $q->whereIn('anagrafica_id', $persone === [] ? [0] : $persone))
            ->whereDate('data_accordo', '<=', $alla)
            ->where(fn ($q) => $q->whereNull('chiuso_il')->orWhereDate('chiuso_il', '>', $alla))
            ->orderBy('data_accordo')->orderBy('id')
            ->get()
            ->groupBy('anagrafica_id')
            ->all();
    }

    /**
     * Il segno di ogni accordo di una persona e la parte in accordo di ogni sua rata, dalla lettura alla data.
     *
     * @param Collection<int, AccordoRientro> $accordi
     * @param list<array<string,mixed>> $rateLettura le righe `rate` di {@see LetturaDebiti} alla data
     * @return array{accordi: list<array<string,mixed>>, parti: array<string, array<string,mixed>>} `parti`: «rata|unità» => la parte in accordo
     */
    public function segni(Collection $accordi, array $rateLettura, string $alla, bool $conVersatiOltre = true): array
    {
        $residui = [];
        foreach ($rateLettura as $r) {
            $residui[self::chiave($r['rata_id'], $r['immobile_id'])] = (int) $r['residuo'];
        }
        $segni = [];
        $parti = [];
        foreach ($accordi as $a) {
            $riconosciuti = 0;
            $oggi = 0;
            $invariato = true;
            $finoAl = $a->fino_al->format('Y-m-d');
            foreach ($a->rate as $coperta) {
                $k = self::chiave($coperta->rata_id, $coperta->immobile_id);
                $residuo = $residui[$k] ?? 0;
                $inAccordo = min($residuo, (int) $coperta->importo_riconosciuto);
                $riconosciuti += (int) $coperta->importo_riconosciuto;
                $oggi += $inAccordo;
                // Invariato: il residuo della rata è quello del giorno dell'accordo. Senza quel residuo (una riga scritta prima che si tenesse),
                // la parte in accordo uguale al riconosciuto.
                $invariato = $invariato && ($coperta->residuo_iniziale !== null ? $residuo === (int) $coperta->residuo_iniziale
                    : $inAccordo === (int) $coperta->importo_riconosciuto);
                // Una rata in due accordi in corso (non dovrebbe succedere): conta la parte più grande, mai la somma.
                if (! isset($parti[$k]) || $parti[$k]['in_accordo'] < $inAccordo) {
                    $parti[$k] = [
                        'accordo_id'    => (int) $a->id,
                        'data_accordo'  => $a->data_accordo->format('Y-m-d'),
                        'fino_al'       => $finoAl,
                        'riconosciuto'  => (int) $coperta->importo_riconosciuto,
                        'in_accordo'    => $inAccordo,
                        // Coperta solo in parte: il riconosciuto è meno del residuo del giorno dell'accordo.
                        'in_parte'      => $coperta->residuo_iniziale !== null && (int) $coperta->importo_riconosciuto < (int) $coperta->residuo_iniziale,
                        // Le lettere lasciano fuori la parte in accordo fino a «fino al» compreso.
                        'fuori_dalle_lettere' => $alla <= $finoAl,
                    ];
                }
            }
            $segni[] = self::descrizione($a) + [
                'riconosciuti'    => $riconosciuti,
                'oggi'            => $oggi,
                'invariato'       => $invariato,
                'fino_al_passato' => $finoAl < $alla,
                'versati_oltre'   => $conVersatiOltre ? $this->versatiOltre($a, $alla) : 0,
            ];
        }

        return ['accordi' => $segni, 'parti' => $parti];
    }

    /**
     * Quanto di ogni rata di una persona le lettere lasciano fuori alla data, per gli accordi in corso fino a «fino al»: «rata|unità» => centesimi.
     * È la stessa parte che {@see \App\Services\Gestionale\Lettere\EmissioneLettere} toglie dalla lettera; la rilegge chi deve sapere se una
     * lettera di prima chiede ancora quello che chiederebbe oggi.
     *
     * @param list<array<string,mixed>> $rateLettura le righe `rate` di {@see LetturaDebiti} alla data, per la persona
     * @return array<string, int>
     */
    public function fuoriDalleLettere(int $condominioId, int $personaId, array $rateLettura, string $alla): array
    {
        $accordi = $this->inCorso($condominioId, $alla, [$personaId])[$personaId] ?? null;
        if ($accordi === null) {
            return [];
        }
        $parti = [];
        foreach ($this->segni($accordi, $rateLettura, $alla, false)['parti'] as $k => $p) {
            if ($p['fuori_dalle_lettere'] && $p['in_accordo'] > 0) {
                $parti[$k] = (int) $p['in_accordo'];
            }
        }

        return $parti;
    }

    /** I dati di un accordo come li mostrano l'elenco e la finestra, senza le cifre di oggi. */
    public static function descrizione(AccordoRientro $a): array
    {
        return [
            'id'                     => (int) $a->id,
            'data_accordo'           => $a->data_accordo->format('Y-m-d'),
            'fino_al'                => $a->fino_al->format('Y-m-d'),
            'autorizzato_assemblea'  => (bool) $a->autorizzato_assemblea,
            'data_autorizzazione'    => $a->data_autorizzazione?->format('Y-m-d'),
            'verbale_autorizzazione' => $a->verbale_autorizzazione,
            'nota'                   => $a->nota,
            'chiuso_il'              => $a->chiuso_il?->format('Y-m-d'),
            'nota_chiusura'          => $a->nota_chiusura,
        ];
    }

    /**
     * La parte in più rimasta a credito degli incassi della persona registrati dalla data dell'accordo fino alla data (per un accordo chiuso, fino
     * al giorno prima della chiusura), che hanno coperto rate dell'accordo: la somma, per ogni incasso, di quello che la ricevuta chiama «parte in
     * più» lasciata a credito.
     */
    public function versatiOltre(AccordoRientro $a, string $alla): int
    {
        $rate = $a->rate->pluck('rata_id')->unique()->values()->all();
        if ($rate === []) {
            return 0;
        }
        $dopo = CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');
        // Un accordo chiuso non è in corso dal giorno della chiusura: i versamenti di quel giorno e dopo non sono suoi (un accordo nuovo sulla
        // stessa rata li conta, una volta).
        if ($a->chiuso_il !== null && $a->chiuso_il->format('Y-m-d') < $dopo) {
            $dopo = $a->chiuso_il->format('Y-m-d');
        }
        $incassi = DB::table('scritture_contabili as sc')
            ->join('righe_scritture as rs', 'rs.scrittura_id', '=', 'sc.id')
            ->where('sc.condominio_id', $a->condominio_id)
            ->where('sc.tipo_movimento', TipoMovimentoContabile::INCASSO_RATA->value)
            ->where('sc.stato', '!=', 'annullata')
            ->whereNull('sc.deleted_at')
            ->where('sc.data_competenza', '>=', $a->data_accordo->format('Y-m-d'))
            ->where('sc.data_competenza', '<', $dopo)
            ->where('rs.anagrafica_id', $a->anagrafica_id)
            ->whereIn('rs.rata_id', $rate)
            ->where('rs.tipo_riga', 'avere')
            ->distinct()
            ->pluck('sc.id');

        $oltre = 0;
        foreach (ScritturaContabile::whereIn('id', $incassi->all() ?: [0])->get() as $incasso) {
            $r = $this->ricevuta->per($incasso);
            if ((int) ($r['posizione']['id'] ?? 0) !== (int) $a->anagrafica_id) {
                continue;
            }
            foreach ($r['eccedenze'] as $e) {
                if (! $e['di_chi_ha_versato']) {
                    $oltre += (int) $e['a_credito'];
                }
            }
        }

        return $oltre;
    }

    /**
     * Il segno di un accordo nella stampa dell'elenco, in una riga: la data, e le due cifre o «residuo invariato». La pagina lo scrive con le
     * stesse parole (`accordiRientro.ts`), dalle date `Y-m-d` del server.
     */
    public static function fraseBreve(array $segno): string
    {
        $giorno = fn (string $d) => CarbonImmutable::parse($d)->format('d/m/Y');
        $euro = fn (int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
        $cifre = $segno['invariato']
            ? 'riconosciuti ' . $euro($segno['riconosciuti']) . ', residuo invariato dal ' . $giorno($segno['data_accordo'])
            : 'riconosciuti ' . $euro($segno['riconosciuti']) . ', oggi ' . $euro($segno['oggi']);

        return 'accordo di rientro del ' . $giorno($segno['data_accordo']) . ', fino al ' . $giorno($segno['fino_al']) . ($segno['fino_al_passato'] ? ' (data passata)' : '') . ': ' . $cifre;
    }

    /** La chiave di una rata di una persona: rata e unità, come la lettura. */
    public static function chiave(mixed $rataId, mixed $immobileId): string
    {
        return ((int) $rataId) . '|' . ($immobileId === null ? '' : (int) $immobileId);
    }

    /**
     * Le rate che un accordo può coprire a una data: le rate scadute della persona con un residuo, con il residuo di quel giorno come importo
     * proposto. La lettura è quella comune, a quella data.
     *
     * @return list<array<string,mixed>>
     */
    public static function coperturePossibili(array $lettura): array
    {
        return array_values(array_map(fn ($r) => [
            'rata_id' => (int) $r['rata_id'], 'immobile_id' => $r['immobile_id'] === null ? null : (int) $r['immobile_id'], 'numero_rata' => (int) $r['numero_rata'],
            'piano_rate_id' => (int) $r['piano_rate_id'], 'scadenza' => $r['scadenza'], 'residuo' => (int) $r['residuo'],
        ], array_filter($lettura['rate'], fn ($r) => $r['scaduta'] && $r['residuo'] > 0)));
    }

}
