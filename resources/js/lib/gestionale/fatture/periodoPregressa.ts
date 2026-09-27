/**
 * Decisione 26, punto 2 (1.11.0-beta.35) — la gemella lato client della regola di `StoreFatturaRequest`
 * (`guardiaPeriodoPregressaScoperta()`): una pregressa con una parte **non coperta** dai saldi iniziali non si
 * registra senza il periodo in cui il costo è maturato, e quel periodo si chiude **prima** dell'inizio dell'esercizio
 * della fattura. Serve a dirlo prima dell'invio, non a sostituire il server: la regola che conta è quella.
 *
 * Le date arrivano come le tiene il modulo (`AAAA-MM-GG`, o vuote): il confronto fra stringhe ISO è un confronto di
 * date.
 */
export type ErrorePeriodo = { campo: 'competenza_dal' | 'competenza_al'; messaggio: string } | null;

export const erroreDelPeriodoPregressa = (p: {
    periodoObbligatorio: boolean;
    dal?: string | null;
    al?: string | null;
    inizioEsercizio?: string | null;
}): ErrorePeriodo => {
    if (!p.periodoObbligatorio) return null;
    if (!p.dal || !p.al) {
        return {
            campo: 'competenza_dal',
            messaggio:
                'Fattura pregressa con una parte non coperta dai saldi iniziali: dichiara il periodo in cui il costo è maturato. '
                + 'Dopo non si potrà aggiungere: una pregressa si storna, non si modifica.',
        };
    }
    const inizio = p.inizioEsercizio ? p.inizioEsercizio.slice(0, 10) : null;
    if (inizio && p.al.slice(0, 10) >= inizio) {
        return {
            campo: 'competenza_al',
            messaggio: `Il periodo di una fattura pregressa si chiude prima dell'esercizio in cui la registri (inizia il ${inizio.split('-').reverse().join('/')}).`,
        };
    }

    return null;
};
