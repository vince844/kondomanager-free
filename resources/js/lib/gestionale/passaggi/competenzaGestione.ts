/**
 * Che cosa dice l'anteprima del passaggio, nella colonna della competenza, per una gestione del conguaglio quando la
 * competenza viene dalle voci (lo straordinario, e dalla decisione 26 il piano da fatture anche sull'ordinaria).
 *
 * R5 della Fase 1-bis (1.11.0-beta.35): sull'ordinaria il gradino della gestione vale «dichiarata» appena una voce lo è,
 * e il periodo è l'unione di quelli di tutte le voci. La colonna diceva «competenza dichiarata · dal primo al dell'ultima
 * voce in ordine», un intervallo che non era di nessuna voce. Con voci miste dice «voce per voce» (il dettaglio sta sotto),
 * e l'intervallo, quando c'è, va dal primo inizio all'ultima fine.
 */
export interface GestioneConguaglio {
    natura: 'ordinaria' | 'straordinaria';
    gradino: string[];
    periodo: { dal: string; al: string }[] | null;
    voce_per_voce?: boolean;
}

export interface CompetenzaGestione {
    etichetta: string;
    /** «01/01/2025–31/08/2026», quando la competenza è dichiarata. */
    intervallo: string | null;
    /** Il giorno della delibera. */
    giorno: string | null;
}

const dataBreve = (iso: string) => iso.split('-').reverse().join('/');

export function competenzaDellaGestione(g: GestioneConguaglio, gradini: Record<string, string>): CompetenzaGestione {
    if (g.natura !== 'straordinaria' && g.voce_per_voce) {
        return { etichetta: 'competenza voce per voce', intervallo: null, giorno: null };
    }

    const etichetta = gradini[g.gradino[0]] ?? gradini.delibera;
    const periodo = g.periodo ?? [];
    if (g.gradino[0] === 'dichiarata' && periodo.length) {
        const dal = periodo.reduce((m, t) => (t.dal < m ? t.dal : m), periodo[0].dal);
        const al = periodo.reduce((m, t) => (t.al > m ? t.al : m), periodo[0].al);

        return { etichetta, intervallo: `${dataBreve(dal)}–${dataBreve(al)}`, giorno: null };
    }

    return { etichetta, intervallo: null, giorno: periodo[0] ? dataBreve(periodo[0].dal) : null };
}

/**
 * La colonna di una gestione che il conguaglio lascia fuori per legge (V1 della verifica a video, 1.11.0-beta.38). Diceva
 * sempre «straordinaria: resta al nudo proprietario», la frase dell'usufrutto; nella vendita con riserva d'usufrutto, e nella
 * vendita della sola nuda proprietà, a restare fuori è l'ordinaria, che resta all'usufruttuario (art. 1004 c.c.). Con «come
 * dice ogni voce» (decisione 31.5, 1.11.0-beta.41) l'ordinaria non resta fuori: si divide voce per voce, e l'etichetta non serve.
 */
export function etichettaEsclusa(g: Pick<GestioneConguaglio, 'natura'>): string {
    return g.natura === 'straordinaria' ? 'straordinaria: resta al nudo proprietario' : 'ordinaria: resta all\'usufruttuario';
}
