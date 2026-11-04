/**
 * Coda 448 — nessuna schermata promette la Certificazione Unica.
 *
 * Il programma prepara la delega F24 per versare le ritenute (`GeneraDelegheF24Action`, `DelegaF24PrintController`) e non produce né la
 * Certificazione Unica né il Modello 770: il sito lo dice, e le schermate dicevano il contrario in tre punti — il riquadro dei parametri di
 * ritenuta del fornitore (nuovo e modifica) e la guida «Dati fiscali» della struttura. Ora dicono che cosa il programma fa.
 *
 * Si leggono i sorgenti senza commenti, come negli altri test dei testi: nei commenti la CU resta, come riferimento normativo.
 * Due guardie: i tre file, e poi **tutto il frontend** più le lingue e le viste, perché una promessa uguale in un altro file è lo stesso difetto.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const NUOVO = 'resources/js/pages/fornitori/FornitoriNew.vue';
const MODIFICA = 'resources/js/pages/fornitori/FornitoriEdit.vue';
const STRUTTURA = 'resources/js/pages/gestionale/struttura/Struttura.vue';

/** Toglie i commenti HTML, i blocchi e le righe che cominciano con `//` o hanno ` //` dopo uno spazio (non `https://`). */
function senzaCommenti(sorgente: string): string {
    return sorgente
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|\s)\/\/.*$/gm, '$1');
}

const leggi = (file: string) => senzaCommenti(fs.readFileSync(file, 'utf8')).replace(/\s+/g, ' ');

/** Le frasi con cui un testo promette la CU o il 770: il nome per esteso, la sigla fra parentesi o dopo «F24 e», il modello. */
const PROMESSA = /certificazion[ei] unic|\(CU\)|F24 e CU|\bCU\)|modello 770|\b770\b/i;

/** Tutti i file con un testo visibile sotto `cartella`, test esclusi. */
function tuttiITesti(cartella: string): string[] {
    return fs.readdirSync(cartella, { withFileTypes: true }).flatMap((voce) => {
        const percorso = path.join(cartella, voce.name);
        if (voce.isDirectory()) return tuttiITesti(percorso);
        return /\.(vue|ts|php)$/.test(voce.name) && !/\.(test|spec)\.ts$/.test(voce.name) && !voce.name.endsWith('.d.ts') ? [percorso] : [];
    });
}

describe('la ritenuta del fornitore e il codice fiscale del condominio dicono che cosa fa il programma (Coda 448)', () => {
    test.each([NUOVO, MODIFICA, STRUTTURA])('%s non promette la Certificazione Unica né il 770', (file) => {
        expect(leggi(file).match(PROMESSA), `${file} promette la CU o il 770`).toBeNull();
    });

    test.each([NUOVO, MODIFICA])('%s: il titolo del riquadro dice «Ritenuta e delega F24», senza la maiuscola di «Fiscali»', (file) => {
        const sorgente = leggi(file);
        expect(sorgente).toContain('>Ritenuta e delega F24</h4>');
        expect(sorgente).not.toContain('Automazioni Fiscali');
    });

    test.each([NUOVO, MODIFICA])('%s: il riquadro dice il netto da pagare e la delega F24 per versare la ritenuta', (file) => {
        const sorgente = leggi(file);
        expect(sorgente).toContain('<strong>calcolare automaticamente la trattenuta</strong> in fase di registrazione fattura');
        expect(sorgente).toContain('scorporerà in automatico il <em>netto da pagare</em> per il fornitore');
        expect(sorgente).toContain('alimenterà lo scadenzario delle ritenute, da cui prepara la <strong>delega F24</strong> per versarle');
    });

    test('la guida «Dati fiscali» della struttura dice a che cosa serve il codice fiscale: è il contribuente delle deleghe F24 e lo confronta l\'importazione di una fattura elettronica', () => {
        const sorgente = leggi(STRUTTURA);
        expect(sorgente).toContain("Mantieni sempre aggiornato il Codice Fiscale. È il codice del contribuente nelle deleghe F24 per versare le ritenute, e l\\'importazione di una fattura elettronica lo confronta con quello del destinatario.");
        // Le altre due schede della guida non cambiano.
        expect(sorgente).toContain("I dati catastali (Foglio, Particella, Sezione) sono necessari per le pratiche di detrazione fiscale sui lavori straordinari.");
        expect(sorgente).toContain('Usa le note per appuntare informazioni cruciali per i fornitori');
    });

    test('nessun altro testo del frontend, delle lingue e delle viste promette la Certificazione Unica o il 770', () => {
        const file = [...tuttiITesti('resources/js'), ...tuttiITesti('lang'), ...tuttiITesti('resources/views')];
        expect(file.length).toBeGreaterThan(200);
        const trovati = file.flatMap((f) => {
            const riga = leggi(f).match(PROMESSA);
            return riga ? [`${f}: ${riga[0]}`] : [];
        });
        expect(trovati, 'testi che promettono la CU o il 770').toEqual([]);
    });

    test('la guardia morde: la promessa nel testo viene trovata, il riferimento normativo in un commento no', () => {
        expect(senzaCommenti('<p>per la generazione automatica del Modello F24 e della Certificazione Unica</p>').match(PROMESSA)).not.toBeNull();
        expect(senzaCommenti('<h4>Automazioni Fiscali (F24 e CU)</h4>').match(PROMESSA)).not.toBeNull();
        expect(senzaCommenti("description: 'per la generazione delle Certificazioni Uniche (CU) e dei modelli F24'").match(PROMESSA)).not.toBeNull();
        expect(senzaCommenti('<p>il Modello 770 non è prodotto</p>').match(PROMESSA)).not.toBeNull();
        expect(senzaCommenti('<!-- Certificazione Unica (CU) -->\n// modello 770\n/* (CU) */\n<p>la delega F24</p>').match(PROMESSA)).toBeNull();
    });
});
