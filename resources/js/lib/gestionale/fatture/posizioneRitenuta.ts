/**
 * Che cosa proporre quando nessuno si è mai pronunciato sulla ritenuta di un fornitore.
 *
 * ## Il buco che questo modulo NON chiude, e va detto per primo
 *
 * ⚠️ **Nessun campo della fattura elettronica risponde alla domanda.** L'obbligo della ritenuta
 * è del condominio come sostituto d'imposta, non del fornitore che la dichiara in fattura: un
 * documento senza blocco `<DatiRitenuta>` non significa «nessuna ritenuta», significa soltanto
 * che il fornitore non l'ha scritta. Misurato sui file veri: **sei degli undici** non hanno quel
 * blocco, e uno dei sei è un geometra — cassa previdenziale TC03, cedente persona fisica — su
 * cui la ritenuta del 20% è dovuta.
 *
 * È l'unico buco dell'importazione XML che **non si chiude leggendo meglio il file**. Quindi qui
 * non si decide: si **propone**, e la risposta la dà l'amministratore. La proposta serve solo a
 * fare in modo che nel caso comune sia una conferma invece di una decisione da zero.
 *
 * ## Perché la proposta e non il silenzio
 *
 * Chiedere senza proporre significa mettere davanti una domanda fiscale a chi non è un
 * commercialista, ogni volta, senza aiutarlo. Il file qualche indizio ce l'ha, e usarlo è
 * l'opposto di indovinare: si mostra da dove viene la proposta, e resta modificabile.
 */

/** I segnali che `ImportaFatturaXmlController` espone in `fornitore.letto_da_xml`. */
export type SegnaliFornitoreXml = {
    regime_forfetario?: boolean;
    e_persona_fisica?: boolean;
    ha_cassa_previdenziale?: boolean;
};

export type FondamentoProposta =
    /** Il documento lo dichiara: è un fatto scritto nel file, non una deduzione. */
    | 'dichiarato_dal_file'
    /** Il documento lo suggerisce. Va guardato, non applicato a occhi chiusi. */
    | 'indizio'
    /** Il file non dice niente in nessuna direzione. */
    | 'nessun_segnale';

export type PropostaPosizione = {
    soggetto: boolean;
    /** `null` quando la proposta è «non soggetto»: senza ritenuta non c'è un regime. */
    tipo: 'appalto_4' | 'lavoro_autonomo_20' | null;
    fondamento: FondamentoProposta;
    /** Da mostrare accanto alla proposta: dice **perché**, che è ciò che la rende verificabile. */
    spiegazione: string;
};

export function proponiPosizioneRitenuta(segnali: SegnaliFornitoreXml): PropostaPosizione {
    // ⚠️ **Il forfetario viene prima di tutto, anche di una persona fisica con cassa.** Su un
    // fornitore in regime forfetario la ritenuta non si applica, e `RitenutaService` esce con
    // `MotivoEsclusioneRitenuta::FORFETARIO` prima ancora di guardare `soggetto_ritenuta`:
    // proporre «soggetto» qui vorrebbe dire proporre uno stato che il calcolo poi ignora.
    // È anche l'unico dei tre casi in cui il file **dichiara** invece di suggerire — RF19 è un
    // campo, non un indizio.
    if (segnali.regime_forfetario) {
        return {
            soggetto: false,
            tipo: null,
            fondamento: 'dichiarato_dal_file',
            spiegazione:
                'Il documento dichiara il regime forfetario (RF19), e su un forfetario la ritenuta non si applica.',
        };
    }

    // Un cedente persona fisica che fattura a un condominio è quasi sempre un professionista;
    // una cassa previdenziale dichiarata lo conferma. Nessuno dei due è una prova — un privato
    // può vendere qualcosa a un condominio senza essere un professionista — quindi la proposta
    // dice da dove viene e resta modificabile.
    if (segnali.ha_cassa_previdenziale || segnali.e_persona_fisica) {
        const perche = segnali.ha_cassa_previdenziale
            ? 'Il documento dichiara un contributo di cassa previdenziale'
            : 'Il fornitore fattura come persona fisica e non come società';

        return {
            soggetto: true,
            tipo: 'lavoro_autonomo_20',
            fondamento: 'indizio',
            spiegazione:
                `${perche}: sembra una prestazione professionale, su cui la ritenuta del 20% è dovuta ` +
                'anche quando la fattura non la indica. Controlla prima di salvare.',
        };
    }

    return {
        soggetto: false,
        tipo: null,
        fondamento: 'nessun_segnale',
        // ⚠️ **Non ripetere ciò che il pannello dice già sopra.** La prima stesura chiudeva con
        // «la fattura non lo dichiara mai», che è la frase con cui il riquadro si apre tre righe
        // più su: a video erano due volte la stessa cosa, ed è il difetto che questa beta ha già
        // corretto una volta sull'F24.
        spiegazione:
            'Il documento non dice niente sulla natura del fornitore: se è un professionista o un ' +
            'appaltatore la ritenuta è dovuta lo stesso.',
    };
}
