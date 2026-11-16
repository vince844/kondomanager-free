// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le parole vietate nei testi delle pagine delle rate scadute e delle lettere, letti nel sorgente: le frasi scritte dentro le funzioni e
 * nei modelli delle pagine, non solo quelle esportate. I commenti non contano (lì le parole si nominano per dire che non si usano). Le
 * eccezioni sono quelle della prova del server: il testo dell'art. 1129 c. 9, che arriva dal server, e il nome del tipo «Messa in mora». Lo stesso vale per
 * «il fondo morosità», il nome di un tipo di fondo: è un nome, non una parola su una persona.
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
    'resources/js/pages/gestionale/movimenti/incassi/RicevuteIncassi.vue', 'resources/js/components/guides/RicevuteIncassiGuide.vue',
    // I modelli delle lettere.
    'resources/js/lib/gestionale/lettere/modelli.ts', 'resources/js/pages/impostazioni/ModelliLettere.vue', 'resources/js/pages/impostazioni/impostazioniStampe.vue',
    // La scheda «Corrispondenza» dell'estratto conto (Coda 533).
    'resources/js/lib/gestionale/lettere/corrispondenza.ts', 'resources/js/pages/gestionale/pianiRate/EstrattoContoAnagrafica.vue',
    // I sei mesi per agire: il riquadro, il compito, le finestre delle risposte, il menu dei passi.
    ...[
        'resources/js/lib/gestionale/debiti/seiMesiPerAgire.ts', 'resources/js/components/gestionale/seiMesi/SeiMesiPerAgireWidget.vue',
        'resources/js/components/gestionale/seiMesi/SeiMesiCompito.vue', 'resources/js/components/gestionale/seiMesi/SeiMesiRisposte.vue',
        'resources/js/components/gestionale/seiMesi/SeiMesiFattoDialog.vue', 'resources/js/components/gestionale/seiMesi/PassoMenu.vue',
        // La lista dello studio, nell'Action inbox: la colonna, il cassetto, le righe.
        'resources/js/lib/gestionale/debiti/seiMesiDelloStudio.ts', 'resources/js/components/gestionale/seiMesi/SeiMesiDelloStudio.vue',
        'resources/js/components/gestionale/seiMesi/SeiMesiColonna.vue', 'resources/js/components/gestionale/seiMesi/SeiMesiCassetto.vue',
        'resources/js/components/gestionale/seiMesi/RigaDelloStudio.vue', 'resources/js/composables/useSeiMesiDelloStudio.ts',
    ],
    // L'Action inbox: la pagina, le righe, le card, le frasi.
    ...['resources/js/pages/dashboard/ActionInbox.vue', 'resources/js/components/inbox/RigaDelCompito.vue', 'resources/js/components/inbox/CardDellInbox.vue',
        'resources/js/lib/inbox/actionInbox.ts'],
    // Il fascicolo per il legale: la finestra e le sue parole.
    ...['resources/js/lib/gestionale/debiti/fascicoloLegale.ts', 'resources/js/components/gestionale/fascicolo/FascicoloLegaleDialog.vue',
        'resources/js/components/gestionale/fascicolo/FascicoliDellaPersona.vue'],
    // La tesoreria a 30 giorni: le frasi, «Da dove viene», il saldo previsto, i crediti da compensare. Il riquadro della tesoreria ha «moros» nei nomi delle
    // proprietà che manda il server: le sue parole si guardano montato (`TreasuryGuardianWidgetParoleVietate.test.ts`).
    ...['resources/js/lib/gestionale/tesoreria/tesoreria.ts', 'resources/js/lib/gestionale/tesoreria/saldoPrevisto.ts',
        'resources/js/pages/gestionale/dashboard/components/DaDoveViene.vue', 'resources/js/pages/gestionale/dashboard/components/SaldoPrevistoCard.vue',
        'resources/js/pages/gestionale/dashboard/components/GraficoSaldoPrevisto.vue', 'resources/js/pages/gestionale/dashboard/components/CreditiDaCompensareWidget.vue'],
    // Le azioni consigliate della tesoreria, l'Inbox del condominio e la guida della dashboard del condominio.
    ...['resources/js/pages/gestionale/dashboard/components/AzioniConsigliate.vue', 'resources/js/lib/gestionale/tesoreria/azioniConsigliate.ts',
        'resources/js/lib/inbox/inboxDelCondominio.ts', 'resources/js/components/guides/DashboardCondominioGuide.vue'],
];

/**
 * I file nuovi dei sei mesi per agire: qui valgono anche «errore», «in ritardo» e «deve agire contro» (nelle liste di oggi farebbero rosso la tesoreria). Per
 * il fascicolo per il legale le stesse tre parole le guarda la prova della finestra sul testo a video (`FascicoloLegaleDialog.test.ts`): nel sorgente ci sono
 * i nomi delle funzioni che leggono le risposte del server.
 */
const FILE_DEI_SEI_MESI = FILE.filter((f) => f.includes('seiMesi'));

/** Il sorgente senza i commenti (`/* … *\/`, `// …`, `<!-- … -->`). */
function senzaCommenti(sorgente: string): string {
    return sorgente.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/<!--[\s\S]*?-->/g, ' ').replace(/(^|[^:'"`])\/\/[^\n]*/g, '$1 ');
}

describe('le parole vietate nei sorgenti delle pagine', () => {
    test.each(FILE)('%s', (file) => {
        const testo = senzaCommenti(readFileSync(resolve(process.cwd(), file), 'utf8')).toLowerCase().replaceAll('messa in mora', ' ').replaceAll('fondo morosità', ' ');
        expect(VIETATE.filter((p) => testo.includes(p))).toEqual([]);
    });

    test('i commenti non contano, e il nome del tipo nemmeno; una frase sì', () => {
        expect(senzaCommenti('/* mai «consegnata» */ const a = 1; // né «notificata»')).not.toContain('consegnata');
        expect(senzaCommenti('const t = `la persona è morosa`;')).toContain('morosa');
        expect(senzaCommenti('const u = "https://esempio.it/x";')).toContain('https://esempio.it/x');
    });
});

describe('le parole in più dei sei mesi per agire, solo sui file nuovi', () => {
    test.each(FILE_DEI_SEI_MESI)('%s', (file) => {
        const testo = senzaCommenti(readFileSync(resolve(process.cwd(), file), 'utf8')).toLowerCase();
        expect(['errore', 'in ritardo', 'deve agire contro'].filter((p) => testo.includes(p))).toEqual([]);
    });

    test('i file nuovi sono undici', () => {
        expect(FILE_DEI_SEI_MESI).toHaveLength(11);
    });
});

describe('il riquadro di «Impostazioni → Stampe»', () => {
    test('parla dei modelli delle lettere, non dei «testi dello studio», che non ci sono più', () => {
        const sorgente = readFileSync(resolve('resources/js/pages/impostazioni/impostazioniStampe.vue'), 'utf8').replace(/\s+/g, ' ');
        expect(sorgente).not.toContain('testi dello studio');
        expect(sorgente).toContain('I modelli delle lettere (promemoria, solleciti, diffide e messe in mora), per tutti i condomini o per uno: nella finestra della lettera li scegli, e il testo resta modificabile per ogni invio.');
    });
});
