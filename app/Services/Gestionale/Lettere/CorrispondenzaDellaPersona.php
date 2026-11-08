<?php

namespace App\Services\Gestionale\Lettere;

use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use Illuminate\Support\Collection;

/**
 * La scheda «Corrispondenza» dell'estratto conto di una persona (Coda 533, decisione 231): ciò che il programma ha emesso per lei in quel
 * condominio, dal registro degli invii. Le lettere restano di {@see RegistroLettere::perPersona()}, con le loro azioni; qui il resto:
 *
 * - **le ricevute e gli avvisi di compensazione**: una voce per tipo, incasso, istante di emissione e canale. Una ricevuta mandata alla
 *   primaria e alla secondaria è una voce con due righe; la ristampa del giorno dopo è un'altra voce (una copia). Le cifre vengono dai dati
 *   salvati nella riga (la ricevuta emessa, l'istantanea dell'avviso), non dall'incasso di oggi, che può essere cambiato o non esserci più;
 * - **«non serve comunicarla»** (decisione 205): la scelta dell'amministratore di non comunicare l'annullamento, una voce con chi e quando.
 *
 * Ogni voce porta l'incasso (`incasso_id`, nullo se l'incasso non c'è più), perché la ristampa, «Invia di nuovo» e la comunicazione di un annullamento
 * stanno nella pagina dell'incasso: l'elenco delle ricevute non ha gli incassi annullati né gli avvisi. Una ricevuta mandata a chi ha versato per un
 * altro dice per conto di chi (`per_conto_di`, dalla ricevuta emessa).
 *
 * Dalla più recente, per istante di emissione e poi per id. Il giorno e l'ora sono quelli dell'amministratore (`app.user_timezone`). I filtri,
 * le pagine e il numero della scheda stanno nel browser (`resources/js/lib/gestionale/lettere/corrispondenza.ts`): la pagina riceve tutto, e
 * il numero e l'elenco non possono divergere. Il limite: con migliaia di documenti per una persona la pagina pesa; per una persona sola non è un
 * caso realistico (una ricevuta per incasso, più le copie).
 */
final class CorrispondenzaDellaPersona
{
    /** I tipi del registro che questa lettura porta: le lettere li porta {@see RegistroLettere::perPersona()}. */
    public const TIPI = [RegistroInvio::TIPO_RICEVUTA, RegistroInvio::TIPO_AVVISO_COMPENSAZIONE];

    public function __construct(private readonly RegistroLettere $lettere) {}

    /**
     * Le ricevute, gli avvisi e le scelte «non serve comunicarla» della persona in questo condominio, dalla più recente.
     *
     * @return list<array<string, mixed>>
     */
    public function documenti(Condominio $condominio, Anagrafica $persona): array
    {
        $righe = RegistroInvio::with('emessoDa:id,name')
            ->where('condominio_id', $condominio->id)
            ->where('anagrafica_id', $persona->id)
            ->whereIn('tipo', self::TIPI)
            ->orderBy('id')
            ->get();

        $fuso = self::fuso();
        // Gli incassi che ci sono ancora: di uno cancellato dopo l'emissione la voce resta, senza il collegamento.
        $incassi = ScritturaContabile::whereIn('id', $righe->where('documentabile_type', ScritturaContabile::class)->pluck('documentabile_id')->unique()->values())
            ->pluck('id')->flip();
        $voci = $righe->groupBy(fn (RegistroInvio $r) => implode('|', [$r->tipo, $r->documentabile_type, $r->documentabile_id, $r->emesso_il->format('Y-m-d H:i:s'), $r->canale]))
            ->map(function (Collection $sue) use ($fuso, $incassi, $persona) {
                /** @var RegistroInvio $prima */
                $prima = $sue->first();
                $dati = (array) $prima->dati;
                $contenuto = (array) ($dati['contenuto'] ?? []);
                $incasso = (array) ($dati['incasso'] ?? []);
                $emessa = $prima->emesso_il->copy()->setTimezone($fuso);
                $posizione = (array) ($contenuto['posizione'] ?? []);

                return [
                    'chiave'            => $prima->tipo . '-' . $prima->id,
                    'id'                => (int) $sue->max('id'),
                    'quando'            => $prima->emesso_il->toIso8601String(),
                    'tipo'              => $prima->tipo,
                    'non_da_comunicare' => $prima->stato === RegistroInvio::STATO_NON_DA_COMUNICARE,
                    'emessa_il'         => $emessa->format('Y-m-d'),
                    'ora'               => $emessa->format('H:i'),
                    'emesso_da'         => $prima->emessoDa?->name,
                    // Il numero dell'incasso: nella colonna per la ricevuta, nell'istantanea per l'avviso.
                    'numero'            => $prima->numero ?? $incasso['numero'] ?? $contenuto['numero'] ?? null,
                    'versamento_del'    => $contenuto['data_versamento'] ?? $incasso['data'] ?? null,
                    // Della ricevuta il versato, dell'avviso la parte coperta dal credito; null se la riga non li porta («non serve comunicarla»).
                    'importo'           => isset($contenuto['importo_versato']) ? (int) $contenuto['importo_versato'] : (isset($dati['importo']) ? (int) $dati['importo'] : null),
                    'annullato'         => ! empty($contenuto['annullato']),
                    'copia_del'         => $dati['copia_del'] ?? null,
                    'incasso_id'        => $prima->documentabile_type === ScritturaContabile::class && $incassi->has($prima->documentabile_id) ? (int) $prima->documentabile_id : null,
                    // La ricevuta di chi ha versato per un altro: la posizione della ricevuta emessa non è la persona di questa scheda.
                    'per_conto_di'      => isset($posizione['id']) && (int) $posizione['id'] !== (int) $persona->id ? ($posizione['nome'] ?? null) : null,
                    'righe'             => $sue->map(fn (RegistroInvio $r) => [
                        'id'           => $r->id,
                        'canale'       => $r->canale,
                        'destinatario' => $r->destinatario,
                        'stato'        => $r->stato,
                        'quando'       => $r->emesso_il->copy()->setTimezone($fuso)->format('Y-m-d H:i'),
                        'errore'       => ErroreDiPosta::frase($r->errore),
                    ])->values()->all(),
                ];
            })
            ->sortBy([['quando', 'desc'], ['id', 'desc']])
            ->values()
            ->all();

        return $voci;
    }

    /**
     * L'ultima lettera della persona oggi, e quella in coda o non partita dopo: la stessa lettura e la stessa data della colonna «Ultima
     * lettera» di «Rate scadute» ({@see RegistroLettere::ultimePerPersone()}, letta da `ElencoRateScadute`), così le due pagine dicono lo stesso.
     *
     * @return array{ultima: ?array<string, mixed>, in_sospeso: ?array<string, mixed>}
     */
    public function ultimaLettera(Condominio $condominio, Anagrafica $persona): array
    {
        $sua = $this->lettere->ultimePerPersone((int) $condominio->id, [(int) $persona->id], DateHelper::oggiUtente())[(int) $persona->id] ?? [];

        return ['ultima' => $sua['ultima'] ?? null, 'in_sospeso' => $sua['in_sospeso'] ?? null];
    }

    private static function fuso(): string
    {
        return (string) config('app.user_timezone', 'Europe/Rome');
    }
}
