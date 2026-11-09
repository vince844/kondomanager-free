/**
 * Le caselle dei moduli del portale (Coda 584): ogni casella ha l'id che la sua etichetta nomina, e la casella di `is_private` porta l'etichetta e
 * la spiegazione di «Privata».
 *
 * Fino alla 1.12.0-beta.6 la modifica di una comunicazione dal portale mostrava «Consenti commenti» sulla casella di `is_private`, con gli id delle
 * due caselle scambiati: togliendo la spunta un condòmino rendeva pubblica a tutto il palazzo la sua comunicazione privata, perché il controller,
 * con `is_private` falso, stacca l'autore (`ComunicazioneController::update`). Nel modulo nuovo la casella «In evidenza» aveva l'id di «Privata», e
 * la sua etichetta non la accendeva. Le pagine si leggono come testo: il difetto è nel collegamento fra la casella e la sua etichetta.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, test } from 'vitest';

type Casella = { modello: string; id: string | null; per: string | null; chiavi: string[] };

/** Le caselle di una pagina seguite dalla loro etichetta: il campo del modulo, l'id, il `for` dell'etichetta e le chiavi dei testi dentro l'etichetta. */
function caselle(pagina: string): Casella[] {
    const sorgente = readFileSync(fileURLToPath(new URL(`../../${pagina}`, import.meta.url)), 'utf8');

    return [...sorgente.matchAll(/<Checkbox\b([\s\S]*?)\/>\s*<label\b([^>]*)>([\s\S]*?)<\/label>/g)].map(([, attributi, etichetta, dentro]) => ({
        modello: /v-model="form\.(\w+)"/.exec(attributi)?.[1] ?? '',
        id: /\bid="([^"]+)"/.exec(attributi)?.[1] ?? null,
        per: /\bfor="([^"]+)"/.exec(etichetta)?.[1] ?? null,
        chiavi: [...dentro.matchAll(/trans\('([^']+)'\)/g)].map((k) => k[1]),
    }));
}

const PAGINE = ['comunicazioni/user/ComunicazioniEdit.vue', 'comunicazioni/user/ComunicazioniNew.vue', 'segnalazioni/user/SegnalazioniEdit.vue'];

describe('le caselle dei moduli del portale', () => {
    test.each(PAGINE)('%s: ogni casella ha come id il suo campo, e la sua etichetta la nomina', (pagina) => {
        const trovate = caselle(pagina);

        expect(trovate.length).toBeGreaterThan(0);
        for (const c of trovate) {
            expect({ campo: c.modello, id: c.id, per: c.per }).toEqual({ campo: c.modello, id: c.modello, per: c.modello });
        }
    });

    test.each(['comunicazioni/user/ComunicazioniEdit.vue', 'comunicazioni/user/ComunicazioniNew.vue'])(
        '%s: la casella di «is_private» dice «Privata», con la sua spiegazione; nessuna casella dice «Consenti commenti»',
        (pagina) => {
            const trovate = caselle(pagina);
            const privata = trovate.find((c) => c.modello === 'is_private');

            expect(privata?.chiavi).toEqual(['comunicazioni.label.private', 'comunicazioni.label.private', 'comunicazioni.tooltip.private']);
            expect(trovate.flatMap((c) => c.chiavi)).not.toContain('comunicazioni.label.comments');
        },
    );
});
