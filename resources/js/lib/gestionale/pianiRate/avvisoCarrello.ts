/**
 * Che cosa dice il carrello del piano da fatture sotto ogni fattura: la competenza dichiarata, o che cosa ne farà il
 * riparto senza. Stava tutto in una catena di `v-if` dentro `PianiRateNew.vue`, e la decisione 26 (1.11.0-beta.35) ci aveva
 * messo la riga della pregressa senza periodo **prima** di quella dell'«Urgenza» — R3 della Fase 1-bis:
 *
 * - sulla gestione straordinaria la riga diceva «si ripartirà sui giorni di quest'anno», e invece decide la delibera
 *   (tutto a chi è titolare quel giorno, decisione 12), come dice già il cancello (`DestinatariWarning.vue`);
 * - con «Urgenza» nascondeva l'avviso che il riparto si ferma su quella fattura, che prima della beta c'era.
 *
 * Qui le varianti stanno in una funzione sola, provata una per una (`avvisoCarrello.test.ts`).
 *
 * R2 della Fase 1-bis: una pregressa registrata prima della beta.35 può avere come periodo la data dell'assemblea di
 * quest'anno, che la finestra della motivazione precompilava. Il server la segnala (`periodo_nell_esercizio`), e il
 * carrello lo dice accanto alla competenza.
 */
import { euro } from '@/lib/gestionale/fatture/fatturaRettificata';

export interface VoceCarrello {
    ha_competenza: boolean;
    competenza?: string | null;
    senza_periodo?: boolean;
    periodo_nell_esercizio?: boolean;
    selezionata?: boolean;
}

export interface ContestoCarrello {
    gestioneStraordinaria: boolean;
    urgenza: boolean;
}

export interface AvvisoCarrello {
    /** `nota` è grigia, `avviso` ambra con il triangolo. */
    tono: 'nota' | 'avviso';
    testo: string;
}

const STORNA = 'Una pregressa non si modifica: si storna e si registra di nuovo con il periodo.';

export function avvisoFatturaNelCarrello(f: VoceCarrello, c: ContestoCarrello): AvvisoCarrello | null {
    if (f.ha_competenza) {
        if (f.periodo_nell_esercizio) {
            return {
                tono: 'avviso',
                testo: `Competenza dichiarata: ${f.competenza}. Il periodo non si chiude prima di quest'esercizio: la paga chi è titolare in quel periodo, non chi lo era quando il costo è maturato. ${STORNA}`,
            };
        }

        return { tono: 'nota', testo: `Competenza dichiarata: ${f.competenza}` };
    }

    if (f.senza_periodo) {
        if (c.gestioneStraordinaria && c.urgenza) {
            return { tono: 'avviso', testo: `Pregressa senza periodo dichiarato: con «Urgenza» il riparto si fermerà su questa fattura. ${STORNA}` };
        }
        if (c.gestioneStraordinaria) {
            return { tono: 'avviso', testo: 'Pregressa senza periodo dichiarato: la paga per intero chi è titolare alla data della delibera, non chi lo era quando il costo è maturato.' };
        }

        return { tono: 'avviso', testo: 'Pregressa senza periodo dichiarato: si ripartirà sui giorni di quest\'anno, non su quelli in cui il costo è maturato.' };
    }

    if (c.gestioneStraordinaria && c.urgenza && f.selezionata) {
        return { tono: 'avviso', testo: 'Senza competenza dichiarata: il riparto si fermerà su questa fattura.' };
    }

    return null;
}

/**
 * Le fatture scelte senza competenza, che con «Urgenza» fermano il riparto: le correnti si dichiarano sulla fattura, le
 * pregresse no — si stornano e si registrano di nuovo. Il riquadro della delibera le contava insieme e chiedeva a tutte
 * di «dichiararla» (R20 della Fase 1-bis).
 */
export function senzaCompetenzaScelte(fatture: VoceCarrello[]): { correnti: number; pregresse: number } {
    const scelte = fatture.filter(f => f.selezionata && !f.ha_competenza);

    return {
        correnti: scelte.filter(f => !f.senza_periodo).length,
        pregresse: scelte.filter(f => f.senza_periodo).length,
    };
}

/**
 * Coda 165 (1.11.0-beta.36): la riga che spiega un «da finanziare» più basso della fattura. Il carrello offre la fattura
 * rettificata da una nota del fornitore **al netto** (`NettoNoteCollegate`), e senza questa riga il numero più basso resta
 * senza spiegazione. `null` se la fattura non ha note collegate. Gli importi arrivano in euro, come il resto del carrello.
 */
export interface VoceCarrelloConNote {
    totale_straordinario: number;
    totale_netto?: number;
    note_collegate?: Array<{ numero: string; data?: string | null; importo: number }>;
}

export function notaCollegataNelCarrello(f: VoceCarrelloConNote): string | null {
    const note = f.note_collegate ?? [];
    if (note.length === 0) return null;

    const nomi = note.map(n => `n. ${n.numero}`);
    const quali = note.length === 1
        ? `della nota di credito ${nomi[0]}`
        : `delle note di credito ${nomi.slice(0, -1).join(', ')} e ${nomi[nomi.length - 1]}`;
    const totale = Math.round(f.totale_straordinario * 100);
    const netto = Math.round((f.totale_netto ?? f.totale_straordinario) * 100);

    if (netto < totale) {
        return `Al netto ${quali}: la fattura vale ${euro(netto)} per il piano, invece di ${euro(totale)}.`;
    }

    // Non si dice dove sta la nota: può essere sulla parte a preventivo, o su una voce o un'unità che la fattura non ha
    // (testuale della Fase 1-bis — «parte a preventivo» era falso nel secondo caso, e per una pregressa).
    return note.length === 1
        ? `La nota di credito ${nomi[0]} non riduce la parte che il piano finanzia, che resta ${euro(totale)}.`
        : `Le note di credito ${nomi.join(', ')} non riducono la parte che il piano finanzia, che resta ${euro(totale)}.`;
}
