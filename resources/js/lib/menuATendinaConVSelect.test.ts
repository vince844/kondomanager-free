// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, test } from 'vitest';

/**
 * I menu a tendina del programma si fanno con `v-select` (`vue-select`), mai con il `<select>` del browser: quello ha l'aspetto del sistema, diverso in
 * ogni browser e nel tema scuro, e dentro una finestra non si allinea ai campi accanto.
 *
 * La guardia congela il perimetro: i file `.vue` che oggi hanno ancora un `<select>` sono scritti qui sotto, e si convertono a mano a mano. Fallisce in
 * due direzioni, ed è voluto:
 *
 * - un file **nuovo**, o un file che non è nell'elenco, con un `<select>` → rosso: il menu si fa con `v-select`;
 * - un file dell'elenco che **non ha più** un `<select>` (convertito, o sparito) → rosso: va tolto dall'elenco, così l'elenco si accorcia.
 *
 * I commenti non contano (dentro un commento `<select>` è il nome della cosa, non la cosa). I `<Select>` dei componenti dell'interfaccia (la maiuscola)
 * e `v-select` non sono il menu del browser e non entrano. L'autocontrollo in fondo chiama la stessa funzione del test vero.
 */

const RADICE = join(__dirname, '..');

/** I file che hanno ancora un `<select>` del browser: si convertono a `v-select` a mano a mano, e l'elenco si accorcia. */
// L'elenco contiene solo file già usciti in una versione: un file nuovo nasce con `v-select`.
const ANCORA_CON_SELECT = [
    'components/gestionale/lettere/LettereDialog.vue',
    'components/gestionale/lettere/RegistroLettere.vue',
    'components/gestionale/movimenti/fatture/DataTableRowActions.vue',
    'components/gestionale/movimenti/incassi/CompensazioniSenzaAvviso.vue',
    'components/gestionale/pianiDeiConti/conti/ModalModificaConto.vue',
    'components/gestionale/pianiDeiConti/conti/ModalNuovoConto.vue',
    'components/gestionale/rendiconto/InvioDeiFogli.vue',
    'components/gestionale/saldi/SaldiDetailPanel.vue',
    'pages/gestionale/contributi/ContributiEdit.vue',
    'pages/gestionale/movimenti/rateScadute/RateScadute.vue',
    'pages/gestionale/movimenti/rendiconto/Controlli.vue',
    'pages/gestionale/pianiRate/PianiRateNew.vue',
    'pages/impostazioni/ModelliLettere.vue',
    'pages/impostazioni/impostazioniMail.vue',
];

/** Il sorgente senza i commenti: quelli HTML, i blocchi `/* … *\/` e le righe `//` (non `https://`). */
function senzaCommenti(sorgente: string): string {
    return sorgente
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|\s)\/\/.*$/gm, '$1');
}

/** Il sorgente ha un `<select>` del browser, fuori dai commenti? */
function haSelectDelBrowser(sorgente: string): boolean {
    return /<select[\s>/]/.test(senzaCommenti(sorgente));
}

function fileVue(cartella: string): string[] {
    return readdirSync(cartella).flatMap((nome) => {
        const percorso = join(cartella, nome);
        if (statSync(percorso).isDirectory()) return fileVue(percorso);
        return nome.endsWith('.vue') ? [percorso] : [];
    });
}

const conSelect = () => fileVue(RADICE)
    .filter((f) => haSelectDelBrowser(readFileSync(f, 'utf8')))
    .map((f) => relative(RADICE, f).split('\\').join('/'))
    .sort();

describe('i menu a tendina si fanno con v-select', () => {
    test('nessun file nuovo, o fuori dall\'elenco, usa il <select> del browser', () => {
        const nuovi = conSelect().filter((f) => !ANCORA_CON_SELECT.includes(f));
        expect(nuovi, 'Questi file usano il <select> del browser: il menu si fa con v-select (vue-select), come nelle ricevute e nei filtri di «Rate scadute».')
            .toEqual([]);
    });

    test('ogni file dell\'elenco ha ancora un <select>: chi è convertito esce dall\'elenco', () => {
        const trovati = conSelect();
        const daTogliere = ANCORA_CON_SELECT.filter((f) => !trovati.includes(f));
        expect(daTogliere, 'Questi file non hanno più un <select> del browser (o non ci sono più): toglili da ANCORA_CON_SELECT.').toEqual([]);
    });

    test('l\'elenco non ha doppioni ed è in ordine, e la guardia legge davvero i file', () => {
        expect(new Set(ANCORA_CON_SELECT).size).toBe(ANCORA_CON_SELECT.length);
        expect([...ANCORA_CON_SELECT].sort()).toEqual(ANCORA_CON_SELECT);
        expect(fileVue(RADICE).length).toBeGreaterThan(200);
    });

    test('la guardia morde: il <select> del browser sì; nei commenti, <Select> e v-select no', () => {
        expect(haSelectDelBrowser('<template><select v-model="x"><option>1</option></select></template>')).toBe(true);
        expect(haSelectDelBrowser('<select\n  v-model="x">')).toBe(true);
        expect(haSelectDelBrowser('<select>')).toBe(true);
        expect(haSelectDelBrowser('<!-- `v-select` e non un `<select>` nativo -->\n<v-select :options="o" />')).toBe(false);
        expect(haSelectDelBrowser('<Select v-model="x"><SelectTrigger /></Select>')).toBe(false);
        expect(haSelectDelBrowser('<vSelect v-model="x" />')).toBe(false);
        expect(haSelectDelBrowser('// un <select> qui sarebbe sbagliato\n/* <select> */')).toBe(false);
        expect(haSelectDelBrowser('<selection-box />')).toBe(false);
    });
});
