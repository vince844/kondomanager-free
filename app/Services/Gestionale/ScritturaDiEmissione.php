<?php

namespace App\Services\Gestionale;

use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Support\Collection;

/**
 * La scrittura che porta a giornale le quote di una rata: la testata, le righe a nome di ogni persona su «Crediti verso condomini» (con l'unità e la
 * rata), le contropartite su «Gestione rate» e su «Passate gestioni», le righe che chiudono l'anticipazione della spesa di una sola unità.
 *
 * Le righe di ogni quota le dice {@see RataQuote::scritturaDiEmissione()}, la chiusura {@see RataQuote::righeDiChiusura()}: qui si scrivono, nello
 * stesso ordine e con le stesse note per chi emette le rate di un piano e per chi registra un addebito a una persona, così le due scritture sono la
 * stessa scrittura e chi legge il giornale (la lettura comune dei debiti, il rendiconto) non le distingue.
 */
final class ScritturaDiEmissione
{
    /**
     * Il marcatore dell'unica eccezione che la scrittura solleva da sé: una rata con un riporto in un condominio senza il conto «Passate gestioni».
     * Chi emette lo riconosce nel messaggio e dice che cosa fare.
     */
    public const ERRORE_PASSATE_GESTIONI = 'EMISSIONE_SENZA_FONDO_PASSATE_GESTIONI';

    /**
     * Scrive l'emissione delle quote date di una rata. Null quando nessuna quota ha niente da portare a giornale: la testata non resta (una scrittura
     * senza righe brucerebbe un numero di protocollo, e nessuna quota la ricorderebbe).
     *
     * Ogni quota va a giornale con le sue due componenti, a nome della persona: una quota a debito con l'importo intero in DARE; una quota che il
     * credito riportato porta a zero o sotto con la spesa in DARE e il credito riportato in AVERE, meno la parte già uscita da «Passate gestioni»
     * mentre la quota era senza scrittura. La contropartita: la spesa dell'esercizio su «Gestione rate»; il resto è il riporto da esercizi precedenti,
     * che non è un provento dell'anno, su «Passate gestioni». Il riporto si deriva per differenza dalle righe, e non si legge dal dettaglio del
     * calcolo: così DARE e AVERE tornano per costruzione anche su una quota il cui dettaglio non quadra, invece di fermarsi alla fine con il
     * protocollo già preso.
     *
     * I due totali delle contropartite possono essere negativi (un credito riportato) e si scrivono nel verso opposto; possono essere zero tutti e
     * due su una rata con righe (un debito e un credito riportati uguali). La quadratura si controlla alla fine, prima di restituire la scrittura.
     *
     * @param Collection<int, RataQuote> $daEmettere le quote della rata che vanno a giornale (senza quelle ferme)
     */
    public function scrivi(int $condominioId, int $esercizioId, ?int $gestioneId, Rata $rata, Collection $daEmettere, string $dataCompetenza, string $causale,
        ContoContabile $contoCrediti, ContoContabile $contoGestione, ?ContoContabile $contoPassate): ?ScritturaContabile
    {
        $scrittura = ScritturaContabile::create([
            'condominio_id'      => $condominioId,
            'esercizio_id'       => $esercizioId,
            'gestione_id'        => $gestioneId,
            'data_registrazione' => now(),
            'data_competenza'    => $dataCompetenza,
            'causale'            => $causale,
            'tipo_movimento'     => 'emissione_rata',
            'stato'              => 'registrata',
        ]);

        $righeScritte = 0;
        $totaleGestione = 0;
        $totalePregresso = 0;
        $perQuota = RataQuote::scritturaDiEmissione($daEmettere, $contoPassate?->id);
        foreach ($daEmettere as $quota) {
            $daScrivere = $perQuota[(int) $quota->id];
            if ($daScrivere['righe'] === []) {
                // Niente da portare: una quota a zero senza spesa né riporto, o un credito già usato per intero prima dell'emissione. La quota resta
                // senza scrittura; se uno storno poi restituisce quel credito, la scrive lo storno con una figlia di questa emissione.
                continue;
            }
            foreach ($daScrivere['righe'] as $i => [$verso, $importoRiga]) {
                $scrittura->righe()->create([
                    'conto_contabile_id' => $contoCrediti->id,
                    'anagrafica_id'      => $quota->anagrafica_id,
                    'immobile_id'        => $quota->immobile_id,
                    'rata_id'            => $rata->id,
                    'tipo_riga'          => $verso,
                    'importo'            => $importoRiga,
                    'note'               => $quota->notaDiEmissione($i, $verso, (string) $rata->descrizione),
                ]);
                $righeScritte++;
            }
            $quota->update(['scrittura_contabile_id' => $scrittura->id]);
            $totaleGestione += $daScrivere['gestione'];
            $totalePregresso += $daScrivere['pregresso'];
        }

        if ($righeScritte === 0) {
            $scrittura->forceDelete();

            return null;
        }

        if ($totalePregresso !== 0 && ! $contoPassate) {
            throw new \RuntimeException(self::ERRORE_PASSATE_GESTIONI);
        }

        foreach ([
            [$contoGestione, $totaleGestione, 'Totale emissione ' . $rata->descrizione],
            [$contoPassate, $totalePregresso, 'Riporto esercizi precedenti — ' . $rata->descrizione],
        ] as [$conto, $totale, $nota]) {
            if ($totale === 0) {
                continue;
            }
            $scrittura->righe()->create([
                'conto_contabile_id' => $conto->id,
                'tipo_riga'          => $totale > 0 ? 'avere' : 'dare',
                'importo'            => abs($totale),
                'note'               => $nota,
            ]);
        }

        // La parte di una sola unità delle quote chiude l'anticipazione che la fattura ha scritto su «Crediti verso condomini» con l'unità, una riga
        // per unità, con la rata e senza persona: non va su «Gestione rate». La riga sta nella scrittura d'emissione, e l'annullamento la porta via.
        foreach (RataQuote::righeDiChiusura($perQuota, $daEmettere, (int) $contoCrediti->id, (int) $rata->id, (string) $rata->descrizione) as $riga) {
            $scrittura->righe()->create($riga);
        }

        // La somma delle quote in DARE deve tornare con la chiusura in AVERE: un arrotondamento sbagliato si ferma qui.
        DoubleEntryValidator::validateOrFail($scrittura->id);

        return $scrittura;
    }
}
