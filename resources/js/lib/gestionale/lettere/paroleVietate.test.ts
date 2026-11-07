/**
 * Le parole vietate nei testi delle pagine delle rate scadute e delle lettere, letti nel sorgente: le frasi scritte dentro le funzioni e
 * nei modelli delle pagine, non solo quelle esportate. I commenti non contano (lì le parole si nominano per dire che non si usano). Le
 * eccezioni sono quelle della prova del server: il testo dell'art. 1129 c. 9, che arriva dal server, e il nome del tipo «Messa in mora».
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const VIETATE = ['moros', 'esigibile', 'diffidat', 'è in mora', 'costituito in mora', 'in mora dal', 'ingiungibil', 'ingiunzion', 'decadut',
    'termine scaduto', 'grave irregolarità', 'consegnata', 'recapitata', 'notificata', 'valore legale', 'novazione', 'transazione'];

const FILE = [
    'resources/js/lib/gestionale/lettere/lettere.ts', 'resources/js/lib/gestionale/debiti/rateScadute.ts', 'resources/js/lib/gestionale/debiti/originePregresso.ts',
    'resources/js/components/gestionale/lettere/LettereDialog.vue', 'resources/js/components/gestionale/lettere/RegistroLettere.vue',
    'resources/js/pages/gestionale/movimenti/rateScadute/RateScadute.vue', 'resources/js/pages/gestionale/esercizi/DispenseSeiMesi.vue',
    'resources/js/components/gestionale/saldi/DichiaraOrigineDialog.vue',
    // L'accordo di rientro e l'avviso di compensazione.
    'resources/js/lib/gestionale/debiti/accordiRientro.ts', 'resources/js/components/gestionale/accordi/AccordoRientroDialog.vue',
    'resources/js/lib/gestionale/incassi/compensazioni.ts', 'resources/js/components/gestionale/movimenti/incassi/CompensazioniSenzaAvviso.vue',
    // Gli accordi nell'estratto conto, i saldi da dichiarare, gli annullamenti da comunicare.
    'resources/js/components/gestionale/accordi/AccordiDellaPersona.vue', 'resources/js/lib/gestionale/saldi/origineDaDichiarare.ts',
    'resources/js/pages/gestionale/movimenti/incassi/RicevuteIncassi.vue',
    // I modelli delle lettere.
    'resources/js/lib/gestionale/lettere/modelli.ts', 'resources/js/pages/impostazioni/ModelliLettere.vue', 'resources/js/pages/impostazioni/impostazioniStampe.vue',
];

/** Il sorgente senza i commenti (`/* … *\/`, `// …`, `<!-- … -->`). */
function senzaCommenti(sorgente: string): string {
    return sorgente.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/<!--[\s\S]*?-->/g, ' ').replace(/(^|[^:'"`])\/\/[^\n]*/g, '$1 ');
}

describe('le parole vietate nei sorgenti delle pagine', () => {
    test.each(FILE)('%s', (file) => {
        const testo = senzaCommenti(readFileSync(resolve(process.cwd(), file), 'utf8')).toLowerCase().replaceAll('messa in mora', ' ');
        expect(VIETATE.filter((p) => testo.includes(p))).toEqual([]);
    });

    test('i commenti non contano, e il nome del tipo nemmeno; una frase sì', () => {
        expect(senzaCommenti('/* mai «consegnata» */ const a = 1; // né «notificata»')).not.toContain('consegnata');
        expect(senzaCommenti('const t = `la persona è morosa`;')).toContain('morosa');
        expect(senzaCommenti('const u = "https://esempio.it/x";')).toContain('https://esempio.it/x');
    });
});

describe('il riquadro di «Impostazioni → Stampe»', () => {
    test('parla dei modelli delle lettere, non dei «testi dello studio», che non ci sono più', () => {
        const sorgente = readFileSync(resolve('resources/js/pages/impostazioni/impostazioniStampe.vue'), 'utf8').replace(/\s+/g, ' ');
        expect(sorgente).not.toContain('testi dello studio');
        expect(sorgente).toContain('I modelli delle lettere (promemoria, solleciti, diffide e messe in mora), per tutti i condomini o per uno: nella finestra della lettera li scegli, e il testo resta modificabile per ogni invio.');
    });
});
