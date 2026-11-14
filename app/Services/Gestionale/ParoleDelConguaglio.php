<?php

namespace App\Services\Gestionale;

/**
 * Le parole del conguaglio per chi legge il foglio della persona e le stampe del fascicolo: il condòmino, che non sa di contabilità e non usa il
 * programma. La scheda «Conguaglio» dell'amministratore ha le sue ({@see ConguaglioDelRendiconto}): il perché di una spesa non divisa con che cosa
 * fare. Qui il perché resta e l'istruzione no, perché chi legge non la può eseguire; e «il programma» diventa l'amministratore (lo studio può non esserci nelle impostazioni).
 *
 * Le frasi della scheda sono la chiave: {@see perche()} e {@see fuoriDaiCapitoli()} prendono quella dell'amministratore e danno quella per il
 * condòmino; una frase che qui non c'è (un codice nuovo) ha quella di ripiego, mai l'istruzione.
 */
final class ParoleDelConguaglio
{
    /** Che cosa è la proposta e che cosa resta da versare comunque. */
    public const PROPOSTA = 'La proposta di conguaglio va all\'assemblea: finché non è approvata non c\'è niente da pagare per il conguaglio. Le rate già chieste restano da versare come sempre.';

    /** La cifra che ancora non c'è, nelle stampe: al posto di «non disponibile». */
    public const NON_CALCOLATO = 'non ancora calcolato';

    /** Il conguaglio di un'unità che ancora non c'è, nel foglio della persona. */
    public const UNITA_NON_CALCOLATA = 'Il conguaglio di questa unità non è ancora calcolato: l\'amministratore le manderà la cifra.';

    /** Il conguaglio di una gestione che ancora non c'è, nella stampa per persona. */
    public const GESTIONE_NON_CALCOLATA = 'Il conguaglio di questa gestione non è ancora calcolato: l\'amministratore lo completerà.';

    /** Il perché di una spesa non divisa fra i condòmini, per ognuno dei motivi della scheda: la causa, senza l'istruzione. */
    public const MOTIVI = [
        'conto_senza_tabella' => 'La voce non ha ancora una tabella dei millesimi: la spesa non è ancora divisa fra le unità.',
        'tabella_senza_millesimi' => 'La tabella della voce non ha ancora i millesimi delle unità: la spesa non è ancora divisa.',
        'coefficienti_sotto_il_cento' => 'Le tabelle della voce non coprono ancora tutta la spesa: una parte non è divisa.',
        'ripartizioni_sotto_il_cento' => 'Le parti di proprietario, inquilino e usufruttuario della voce non coprono ancora tutta la spesa: una parte non è divisa.',
        'giorni_senza_titolare' => 'In alcuni giorni un\'unità non aveva un titolare registrato: quella parte non è divisa.',
        'titolari_fuori_competenza' => 'Nei giorni della spesa un\'unità non aveva un titolare registrato: la spesa non è divisa.',
        'senza_titolare' => 'Un\'unità della tabella non ha un titolare registrato: la sua parte non è divisa.',
        'ad_personam_senza_titolare' => 'La spesa di una sola unità non ha un titolare registrato quel giorno: non è divisa.',
        'pregressa_senza_periodo' => 'La spesa di un anno passato non ha ancora il suo periodo: non è divisa.',
        'voce_non_trovata' => 'La voce della spesa non è più nel piano dei conti: la spesa non è divisa.',
        'periodi_diversi' => 'I piani dell\'esercizio dividono la voce in periodi diversi, e un\'unità della voce ha cambiato titolare: la spesa non è ancora divisa.',
    ];

    /** Il perché di ripiego, per un motivo che qui non ha la sua frase. */
    public const MOTIVO_DI_RIPIEGO = 'La spesa non è ancora divisa fra le unità.';

    /** Le uscite fuori dai capitoli, per nome della scheda: il nome e il perché per il condòmino. */
    public const FUORI_DAI_CAPITOLI = [
        'commissioni_senza_voce' => ['Commissioni bancarie', 'Non sono ancora attribuite a una voce di spesa.'],
        'contestate' => ['Fatture contestate', 'Restano fuori finché la contestazione non è chiusa.'],
        'non_classificati' => ['Costi non classificati', 'Non sono ancora attribuiti a una voce di spesa.'],
    ];

    /** Le spese che il conguaglio non divide ancora, fuori dai capitoli del riepilogo, come le chiama chi legge: nell'ordine in cui si dicono. */
    public const NON_DIVISE = [
        'contestate' => 'fatture contestate',
        'commissioni_senza_voce' => 'commissioni bancarie',
        'non_classificati' => 'costi non classificati',
    ];

    /** Le spese di voci che il conguaglio non riesce a dividere, nell'elenco delle spese non ancora divise. */
    public const VOCI_NON_DIVISE = 'spese di voci non ancora divise';

    /**
     * Le spese non ancora divise fra i condòmini: quelle delle voci che non si dividono e quelle fuori dai capitoli, con che cosa sono.
     *
     * @param  array<string,int>  $fuoriPerChiave  le uscite fuori dai capitoli, per chiave di {@see NON_DIVISE}
     * @return array{importo:int, cosa:list<string>}
     */
    public static function nonDivise(int $vociNonDivise, array $fuoriPerChiave): array
    {
        $importo = $vociNonDivise;
        $cosa = $vociNonDivise !== 0 ? [self::VOCI_NON_DIVISE] : [];
        foreach (self::NON_DIVISE as $chiave => $nome) {
            $c = (int) ($fuoriPerChiave[$chiave] ?? 0);
            if ($c !== 0) {
                $importo += $c;
                $cosa[] = $nome;
            }
        }

        return ['importo' => $importo, 'cosa' => $cosa];
    }

    /** La chiave di un'uscita fuori dai capitoli, dal nome della scheda; null se il nome non c'è. */
    public static function chiaveFuoriDaiCapitoli(string $nomeDellaScheda): ?string
    {
        foreach (ConguaglioDelRendiconto::FUORI_DAI_CAPITOLI as $chiave => [$nome]) {
            if ($nome === $nomeDellaScheda) {
                return $chiave;
            }
        }

        return null;
    }

    /** Il perché per il condòmino, dalla frase della scheda: la causa senza l'istruzione. */
    public static function perche(string $motivoDellaScheda): string
    {
        $codice = array_search($motivoDellaScheda, ConguaglioDelRendiconto::MOTIVI, true);
        // La voce ferma per i periodi diversi ha i nomi dei piani al posto di «dell'esercizio»: il resto della frase è lo stesso.
        $coda = mb_substr(ConguaglioDelRendiconto::MOTIVI['periodi_diversi'] ?? '', mb_strlen('I piani dell\'esercizio '));
        if ($codice === false && $coda !== '' && str_starts_with($motivoDellaScheda, 'I piani ') && str_ends_with($motivoDellaScheda, $coda)) {
            $codice = 'periodi_diversi';
        }

        return $codice === false ? self::MOTIVO_DI_RIPIEGO : (self::MOTIVI[$codice] ?? self::MOTIVO_DI_RIPIEGO);
    }

    /**
     * Il nome e il perché di un'uscita fuori dai capitoli per il condòmino, dal nome della scheda.
     *
     * @return array{0:string, 1:string}
     */
    public static function fuoriDaiCapitoli(string $nomeDellaScheda): array
    {
        $chiave = self::chiaveFuoriDaiCapitoli($nomeDellaScheda);

        return self::FUORI_DAI_CAPITOLI[$chiave] ?? [$nomeDellaScheda, self::MOTIVO_DI_RIPIEGO];
    }

    /** Una cifra con il suo verbo, per chi non legge i segni: «da versare € 410,00», «a credito € 65,75», «nessuna cifra». */
    public static function conIlVerbo(?int $centesimi): string
    {
        if ($centesimi === null) {
            return self::NON_CALCOLATO;
        }
        $euro = '€ '.number_format(abs($centesimi) / 100, 2, ',', '.');

        return match (true) {
            $centesimi > 0 => 'da versare '.$euro,
            $centesimi < 0 => 'a credito '.$euro,
            default => 'niente da versare',
        };
    }
}
