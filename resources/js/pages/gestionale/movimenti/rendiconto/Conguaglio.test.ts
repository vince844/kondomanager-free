// @vitest-environment jsdom

/**
 * La scheda «Conguaglio»: dice le righe che il server le dà, senza sommarne; il più sul conguaglio e sul saldo a debito; i giorni; le note per
 * l'amministratore sotto la riga; il foglio e l'estratto conto di ogni persona; le righe dopo, a pagine; i fogli in un archivio con il numero delle
 * persone scelte e il tetto. Nessuna delle parole che una proposta non dice.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { flash: undefined } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));
(globalThis as any).route = (nome: string, p: Record<string, number>) => `/${nome}/${p.condominio}/${p.esercizio}`;

const Pagina = (await import('./Conguaglio.vue')).default;

const riga = (extra: Record<string, unknown>) => ({
    anagrafica_id: 1, nome: 'Neri', immobile_id: 11, unita: 'Int. U1', giorni: null, a: 210000, personali: 0, b: 200000, b_non_emesse: 0, conguaglio: 10000,
    posizione: 30000, fuori: 0, saldo: 40000, non_disponibile: null, note: [], subtotale: false, gestione_id: 7, ...extra,
});
const totali = { a: 420000, personali: 8000, b: 400000, conguaglio: 28000, posizione: 30000, fuori_dalle_rate: 0, saldo: 58000, non_disponibili: 0 };
const props = (extra: Record<string, unknown> = {}) => ({
    condominio: { id: 3, nome: 'Via Verdi' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [],
    conguaglio: {
        gestioni: [{ id: 7, nome: 'Ordinaria 2026', tipo: 'ordinaria', non_calcolabile: null, totali,
            gia_versato_senza_titolare: 'Già versato registrato e non usato di unità senza un titolare di diritto reale alla data (Int. U3): € 100,01. Resta della gestione: non è nel conguaglio di nessuno.',
            non_attribuite: [{ conto_id: 5, voce: 'Varie', piano_conto_id: 4, importo: 12345, motivo: 'La voce non ha una tabella millesimale. Collega una tabella con i millesimi alla voce.' }] }],
        da_altre_gestioni: { nome: 'Da altre gestioni e da esercizi prima', totali: null },
        fuori_dai_capitoli: [],
        condominio: { ...totali, non_attribuite: 12345, non_emesse: 50000 },
        non_disponibili: 'Le righe non disponibili (1, spese dell\'esercizio € 840,00) non sono nei totali.',
        data: { alla: '2026-12-31', finito: true, calcolato_il: '10/01/2027 alle 10:00', frase: 'Alla fine dell\'esercizio, il 31/12/2026; calcolato il 10/01/2027 alle 10:00.' },
        secondi: 1.5,
        righe: [
            riga({ anagrafica_id: 2, nome: 'Bianchi', immobile_id: 12, unita: 'Int. U2', giorni: 181, a: 62482, b: 59507, conguaglio: 2975, posizione: 0, fuori: -60493, saldo: -57518, note: ['Nelle quote € -604,93 della coppia del passaggio.'] }),
            riga({ non_emesse: 50000 }),
            riga({ anagrafica_id: 4, nome: 'Verdi', immobile_id: 13, unita: 'Int. U3', non_disponibile: 'Il passaggio non ha la cifra nel registro.', b: null, conguaglio: null, saldo: null }),
        ],
        pagine: { corrente: 1, ultima: 2, per_pagina: 200, righe: 450, dopo: 250 },
    },
    frasi: { proposta: 'Proposta di conguaglio da approvare dall\'assemblea: diventa una richiesta solo con l\'approvazione.', segno: 'Positivo vuol dire da versare, negativo a credito.',
        competenza: 'Per competenza.', giorni: 'I giorni.', centesimi: 'Al centesimo.', colonne: 'Conguaglio = A + personali − B.' },
    fogli: { tutto: { tutte: 230, a_debito: 120, con_email: 90 }, pagina: { tutte: 200, a_debito: 100, con_email: 80 }, tetto: 200, ricordata: 'tutte' },
    ...extra,
});

let w: VueWrapper | null = null;
const monta = (extra: Record<string, unknown> = {}) => {
    w?.unmount();
    const slot = { template: '<div><slot /></div>' };
    w = mount(Pagina, { props: props(extra) as any, global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: slot, Alert: slot } } });

    return w;
};
const testo = (s: string) => s.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
afterEach(() => { w?.unmount(); w = null; });

describe('la scheda «Conguaglio»', () => {
    test('le righe del server con il segno, i giorni, la nota sotto la riga, «non disponibile», il foglio e l\'estratto conto, la riga del condominio', () => {
        const p = monta();
        const righe = p.findAll('[data-test="riga"]');
        expect(righe).toHaveLength(3);
        expect(testo(righe[0].html())).toContain('Bianchi');
        expect(testo(righe[0].html())).toContain('181 giorni');
        expect(testo(righe[0].html())).toContain('€ +29,75');
        expect(testo(righe[0].html())).toContain('€ -575,18');
        expect(testo(p.findAll('[data-test="note"]')[0].html())).toBe('Nelle quote € -604,93 della coppia del passaggio.');
        expect(testo(righe[1].html())).toContain('€ +100,00');
        // Il saldo con le rate non ancora emesse lo dice sotto la cifra; senza, niente.
        expect(testo(righe[1].find('[data-test="non-emesse"]').html())).toBe('più le rate non ancora emesse: € 500,00');
        expect(righe[0].find('[data-test="non-emesse"]').exists()).toBe(false);
        expect(testo(righe[2].html())).toContain('non disponibile');
        expect(testo(p.findAll('[data-test="note"]')[1].html())).toBe('Non disponibile: Il passaggio non ha la cifra nel registro.');
        expect(righe[1].findAll('a').map((a) => a.attributes('href'))).toEqual(['/gestionale/3/esercizi/9/rendiconto/conguaglio/fogli/1', '/gestionale/3/anagrafiche/1/estratto-conto']);
        expect(testo(p.find('[data-test="condominio"]').html())).toBe('Il condominio € 4.200,00 € 80,00 € 4.000,00 € +280,00 € 300,00 € 0,00 € +580,00 più le rate non ancora emesse: € 500,00');
        expect(testo(p.find('[data-test="non-emesse-condominio"]').html())).toBe('più le rate non ancora emesse: € 500,00');
        // Il già versato non usato di un'unità senza titolare: una riga della gestione, sotto le righe.
        expect(testo(p.find('[data-test="senza-titolare"]').html())).toBe('Ordinaria 2026: Già versato registrato e non usato di unità senza un titolare di diritto reale alla data (Int. U3): € 100,01. Resta della gestione: non è nel conguaglio di nessuno.');
        expect(testo(p.find('[data-test="non-attribuita"]').html())).toBe('Varie (Ordinaria 2026): € 123,45 non attribuiti. La voce non ha una tabella millesimale. Collega una tabella con i millesimi alla voce. Apri il riepilogo');
        expect(p.find('[data-test="apri-riepilogo"]').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/riepilogo-finanziario');
        expect(testo(p.find('[data-test="non-disponibili"]').html())).toBe('Le righe non disponibili (1, spese dell\'esercizio € 840,00) non sono nei totali.');
        expect(p.find('[data-test="non-attribuita"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/piani-conti/4');
        expect(testo(p.find('[data-test="data"]').html())).toContain('calcolato il 10/01/2027 alle 10:00');
        expect(testo(p.find('[data-test="pagine"]').html())).toContain('altre 250 righe');
        expect(p.find('[data-test="altre"]').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/conguaglio?pagina=2');
        expect(p.find('[data-scheda="conguaglio"]').attributes('aria-current')).toBe('page');
    });

    test('il sottotitolo: l\'esercizio e la frase della proposta, con un punto e i due punti una volta sola', () => {
        const slot = { template: '<div><slot /></div>' };
        w = mount(Pagina, { props: props() as any, global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, Alert: slot,
            PageHeaderGuide: { props: ['pageSubtitle'], template: '<div data-test="sottotitolo">{{ pageSubtitle }}</div>' } } } });
        const sotto = w.find('[data-test="sottotitolo"]').text();
        expect(sotto).toBe('Esercizio 2026. Proposta di conguaglio da approvare dall\'assemblea: diventa una richiesta solo con l\'approvazione.');
        expect(sotto.split(':')).toHaveLength(2);
    });

    test('i fogli in un archivio: il numero delle persone scelte; oltre il tetto la frase dice di scegliere una pagina e il pulsante non c\'è', async () => {
        const p = monta();
        expect(testo(p.find('[data-test="quante"]').html())).toBe('Persone scelte: 230. In un archivio ne stanno al massimo 200: scegli le persone di una pagina dell\'elenco.');
        expect(p.find('[data-test="scarica-fogli"]').exists()).toBe(false);
        await p.find('[data-test="solo-pagina"]').setValue(true);
        await p.find('[data-scelta="a_debito"]').setValue(true);
        expect(testo(p.find('[data-test="quante"]').html())).toBe('Persone scelte: 100.');
        expect(p.find('[data-test="scarica-fogli"]').attributes('href')).toBe('/gestionale.esercizi.rendiconto.conguaglio.fogli/3/9?chi=a_debito&pagina=1');
    });

    test('«con il conguaglio a debito» dice quante persone lascia fuori perché il loro conguaglio non è disponibile; le altre scelte no', async () => {
        const base = props().fogli as any;
        const p = monta({ fogli: { ...base, tutto: { ...base.tutto, non_disponibili: 2 }, pagina: { ...base.pagina, non_disponibili: 1 } } });
        expect(testo(p.find('[data-test="quante"]').html())).not.toContain('non disponibile');
        await p.find('[data-scelta="a_debito"]').setValue(true);
        expect(testo(p.find('[data-test="quante"]').html())).toBe('Persone scelte: 120. 2 persone hanno il conguaglio non disponibile e non sono fra queste.');
        await p.find('[data-test="solo-pagina"]').setValue(true);
        expect(testo(p.find('[data-test="quante"]').html())).toBe('Persone scelte: 100. 1 persona ha il conguaglio non disponibile e non è fra queste.');
        await p.find('[data-scelta="con_email"]').setValue(true);
        expect(testo(p.find('[data-test="quante"]').html())).toBe('Persone scelte: 80.');
    });

    test('gli avvisi della gestione per l\'amministratore: una riga per frase sotto il nome della gestione, come le coperture; senza avvisi nessuna riga', () => {
        const base = props().conguaglio as any;
        const avvisi = [
            'Spese per singole unità di giorni in cui l\'unità non aveva un titolare (Int. U3): € 50,00. Non sono nel conguaglio di nessuno: controlla le date dei titolari, o scrivi la competenza sulla fattura.',
            'Il piano «Piano luglio» non ha l\'esercizio scritto: il programma lo legge come piano di «Esercizio 2026» dalla sua data. Se non è il suo, scrivi l\'esercizio nel piano.',
        ];
        const p = monta({ conguaglio: { ...base, gestioni: [{ ...base.gestioni[0], avvisi }] } });
        const righe = p.findAll('[data-test="avviso"]');
        expect(righe).toHaveLength(2);
        expect(testo(righe[0].html())).toBe(avvisi[0]);
        expect(testo(righe[1].html())).toBe(avvisi[1]);
        // Sotto il nome della gestione, nel suo gruppo di righe.
        expect(p.find('[data-gestione="7"]').find('[data-test="avviso"]').exists()).toBe(true);
        expect(monta().find('[data-test="avviso"]').exists()).toBe(false);
    });

    test('l\'opera in corso: la frase sotto il nome della gestione e, senza la data di fine, la porta alla gestione; l\'opera finita; la copertura dal fondo', () => {
        const base = props().conguaglio as any;
        const p = monta({ conguaglio: { ...base, gestioni: [{ ...base.gestioni[0],
            in_corso: { frase: 'Opera in corso: spesi € 65.000,00, chiesti € 60.000,00. Il conguaglio si propone quando l\'opera è finita: scrivi la data di fine della gestione quando l\'opera è finita.',
                gestione_id: 7, senza_data_fine: true, spesi: 6500000, chiesti: 6000000 },
            opera_finita: null,
            coperture: ['Pulizie: la fattura n. F-1 indica la copertura dello sforo con il fondo «Fondo di riserva», € 300,00. Il conguaglio è lordo: se usare il fondo lo decide l\'assemblea quando approva.'] }] } });
        expect(testo(p.find('[data-test="in-corso"]').html())).toContain('Opera in corso: spesi € 65.000,00, chiesti € 60.000,00.');
        expect(p.find('[data-test="scrivi-data-fine"]').attributes('href')).toBe('/gestionale/3/esercizi/9/gestioni/7/edit');
        expect(testo(p.find('[data-test="copertura"]').html())).toContain('copertura dello sforo con il fondo «Fondo di riserva»');
        expect(p.find('[data-test="opera-finita"]').exists()).toBe(false);

        const conData = monta({ conguaglio: { ...base, gestioni: [{ ...base.gestioni[0],
            in_corso: { frase: 'Opera in corso fino al 30/06/2027: spesi € 40.000,00, chiesti € 60.000,00. Il conguaglio si propone quando l\'opera è finita.', gestione_id: 7, senza_data_fine: false, spesi: 4000000, chiesti: 6000000 } }] } });
        expect(conData.find('[data-test="scrivi-data-fine"]').exists()).toBe(false);
        const finita = monta({ conguaglio: { ...base, gestioni: [{ ...base.gestioni[0], opera_finita: 'Opera finita il 30/06/2027: il conguaglio è sull\'intera gestione.' }] } });
        expect(testo(finita.find('[data-test="opera-finita"]').html())).toBe('Opera finita il 30/06/2027: il conguaglio è sull\'intera gestione.');
    });

    test('l\'opera finita in un esercizio prima con spese scritte dopo la fine: la frase e la porta per spostare la data di fine', () => {
        const base = props().conguaglio as any;
        const frase = 'Opera finita il 30/11/2026: il conguaglio dell\'opera è nel rendiconto di «Esercizio 2026»; qui le spese scritte dopo la fine, € 25.000,00. Se l\'opera non era finita, sposta la data di fine della gestione.';
        const p = monta({ conguaglio: { ...base, gestioni: [{ ...base.gestioni[0],
            in_corso: { frase, gestione_id: 7, senza_data_fine: false, sposta_data_fine: true, spesi: null, chiesti: null } }] } });
        expect(testo(p.find('[data-test="in-corso"]').html())).toContain(frase);
        expect(p.find('[data-test="sposta-data-fine"]').attributes('href')).toBe('/gestionale/3/esercizi/9/gestioni/7/edit');
        expect(p.find('[data-test="scrivi-data-fine"]').exists()).toBe(false);
        const senza = monta({ conguaglio: { ...base, gestioni: [{ ...base.gestioni[0],
            in_corso: { frase: 'Opera finita il 30/11/2026: il conguaglio è nel rendiconto di «Esercizio 2026».', gestione_id: 7, senza_data_fine: false, sposta_data_fine: false, spesi: null, chiesti: null } }] } });
        expect(senza.find('[data-test="sposta-data-fine"]').exists()).toBe(false);
    });

    test('il secondo momento: la frase del server sotto la tabella; senza, niente', () => {
        const base = props().conguaglio as any;
        const frase = 'Dopo l\'approvazione e la scrittura del conguaglio: crediti verso i condòmini € 580,00 (oggi € 380,00, meno le spese personali che il conguaglio chiede € 80,00, più i conguagli a debito € 280,00); debiti verso i condòmini per i conguagli a credito € 0,00.';
        const p = monta({ conguaglio: { ...base, secondo_momento: { crediti_oggi: 38000, personali: 8000, a_debito: 28000, crediti_dopo: 58000, a_credito: 0, frase } } });
        expect(testo(p.find('[data-test="secondo-momento"]').html())).toBe(frase);
        expect(monta().find('[data-test="secondo-momento"]').exists()).toBe(false);
    });

    test('nessuna delle parole che una proposta di conguaglio non dice', () => {
        const t = monta().text().toLowerCase();
        for (const parola of ['dovuto', 'esigibile', 'pronto', 'conforme', 'corretto', 'in regola']) {
            expect(t).not.toMatch(new RegExp(`(^|[^\\p{L}])${parola}($|[^\\p{L}])`, 'u'));
        }
    });
});
