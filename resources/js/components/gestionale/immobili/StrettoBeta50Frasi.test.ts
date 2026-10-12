// @vitest-environment jsdom

/**
 * 1.11.0-beta.50, giro stretto sulle correzioni della Fase 1-bis — G5: dopo un cambio d'inquilino «regolato fra le parti» (la casella di
 * prima della .50, o il conguaglio scritto e poi annullato), il cambio successivo con «Non scriverlo» non ha il rimborso con il prospetto
 * degli oneri accessori: la decisione 83.3 tiene la lettura delle decisioni 43 e 46 quando dopo il cambio regolato non c'è una fine
 * «Scrivi», e il prospetto ha solo l'inquilina di prima, con tutta la parte dell'inquilino nelle sue rate. La voce «Non scriverlo» del
 * pannello «Cosa cambierà» (`AnteprimaPassaggio.vue`) dice invece «chi entra glieli rimborsa con il prospetto degli oneri accessori».
 *
 * La correzione verificata passa al pannello, accanto a `rate.prospetto_esclude` (R10), i piani su cui il passaggio che ha dato i giorni
 * a chi esce è regolato fra le parti: `rate.prospetto_regolato`, il nome che la verifica propone. Il test PHP gemello è in
 * `StrettoBeta50FrasiTest` («G5, la chiave per il modulo»), con lo scenario del server.
 *
 * Il caso: Ines Moretti → Luca Ferri il 1/7, regolato fra le parti; Luca Ferri → Anna Neri il 1/9. La coppia di Anna: 36000 × 122 / 365 =
 * 12032,88 → € 120,33. Il JSON è quello del cambio d'inquilino di `StrettoBeta50Pannello.test.ts`, con le persone e la cifra di questo caso.
 *
 * Il test sulla chiave `rate.prospetto_regolato` nacque rosso, perché il codice della .50 non la leggeva, e fu corretto prima del
 * commit; accanto, il controllo.
 *
 * Che cosa NON copre: quali piani il server mette nella chiave (il test PHP); la frase del server in `rate.frasi`, che arriva così com'è
 * (il test PHP di G5); il modulo `PassaggioNew.vue`; il modo scuro e le larghezze.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import AnteprimaPassaggio from './AnteprimaPassaggio.vue';

const emesse = [1, 2, 3, 4, 5, 6].map((rata) => ({
    rata, scadenza: `2026-0${rata}-05`, importo: 3000, importo_formattato: '€ 30,00', intestatario: 'Ines Moretti', piano: 'Preventivo 2026', natura: 'ordinaria',
}));

/** Il cambio di Luca Ferri ad Anna Neri il 1/9, come lo manda il server; `regolato`: i piani di `rate.prospetto_regolato`, se si dice. */
function cambio(regolato?: string[]): any {
    return {
        nudi: null,
        obbligati: { frasi: ['Le rate già emesse a Luca Ferri restano sue.'], copia_autentica_mancante: false },
        invarianti: { frasi: ['Millesimi: invariati.'] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
        riferimento: { uscente_fino_al: '2026-08-31', entrante_dal: '2026-09-01', frase: 'Luca Ferri risulterà inquilino fino al 31 agosto 2026 compreso.' },
        anagrafica: { frasi: ['Luca Ferri risulterà inquilino fino al 31 agosto 2026.', 'Anna Neri inquilino dal 1 settembre 2026, al 100 %.'], pertinenze: [] },
        rate: {
            stato: 'calcolato', emesse, piani_distinti: 1, totale_emesso: 18000, totale_emesso_formattato: '€ 180,00',
            altre: { quote: 6, intestatari: ['Ugo Rinaldi'] }, morosita: null,
            conguaglio: {
                stato: 'calcolato', anagrafica_uscente_id: 3, anagrafica_entrante_id: 4, quote: [], per_gestione: [],
                coppie: [{ gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, importo: 12033, importo_formattato: '€ 120,33', per_nudo: null, anagrafica_entrante_id: 4, entrante_nome: 'Anna Neri' }],
                totale_entrante: 12033, totale_entrante_formattato: '€ 120,33', totale_entrante_assoluto_formattato: '€ 120,33',
                riassegnazione: [], pregressi: 0, non_risolte: [], frasi: [],
            },
            frasi: ['Le rate già emesse non si toccano.'],
            frasi_del_conguaglio: [],
            scelta_del_conguaglio: true,
            fermata_coinquilino: [],
            prospetto_esclude: [],
            ...(regolato ? { prospetto_regolato: regolato } : {}),
        },
        cancello: { richiesto: true, motivi: [], informazioni: [], avvisi: [] },
    };
}

function montaIlPannello(dati: any) {
    return mount(AnteprimaPassaggio, { props: { dati, inCorso: false, errore: false, mancante: [], tipo: 'fine_locazione', senzaSuccessore: false, sceltaConguaglio: null } });
}

/** Il testo della voce «Non scriverlo». */
function voceNonScriverlo(w: ReturnType<typeof montaIlPannello>): string {
    const voce = w.findAll('label').find((l) => l.find('input[type="radio"][value="non_scrivere"]').exists());
    if (!voce) throw new Error('La voce «Non scriverlo» non c\'è.');

    return voce.text();
}

describe('AnteprimaPassaggio — G5, il cambio con «Non scriverlo» dopo un cambio regolato fra le parti', () => {
    test('G5: con il piano in `rate.prospetto_regolato` la voce «Non scriverlo» non promette il rimborso con il prospetto degli oneri accessori, e dice che si regola fra le parti', () => {
        const w = montaIlPannello(cambio(['Preventivo 2026']));
        const testo = voceNonScriverlo(w);

        // Controllo: è la scelta del cambio d'inquilino, con le due voci e la coppia di Anna.
        expect(w.find('input[type="radio"][value="scrivi"]').exists()).toBe(true);
        expect(w.text()).toContain('€ 120,33');

        expect(testo).not.toContain('chi entra glieli rimborsa con il prospetto degli oneri accessori');
        expect(testo).toContain('fra le parti');
    });

    test('la guardia — senza la chiave la voce tiene il rimborso con il prospetto, come dopo un cambio scritto', () => {
        for (const dati of [cambio(), cambio([])]) {
            expect(voceNonScriverlo(montaIlPannello(dati))).toContain('Nessuna riga in saldi: i giorni dopo l\'uscita restano nelle rate di chi esce, e chi entra glieli rimborsa con il prospetto degli oneri accessori.');
        }
    });
});
