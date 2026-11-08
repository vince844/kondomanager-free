// @vitest-environment jsdom

/**
 * La scheda «Condòmini» del rendiconto: dice i numeri che il server le dà, senza sommarne; le colonne che dipendono dalla data (versato dopo, resta
 * oggi) ci sono solo per un esercizio finito; la persona porta al suo estratto conto, la lettera al registro delle lettere della persona; l'ordine
 * del § 10 si cambia con un collegamento; le date nascono `Y-m-d` e la pagina le formatta. Nessuna delle parole che il rendiconto non dice mai di sé.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { NOTA_PREGRESSO_NELLA_RATA } from '@/lib/gestionale/debiti/rateScadute';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));

const Pagina = (await import('./Condomini.vue')).default;

const pagine = (total: number, nome: string, extra: Record<string, unknown> = {}) => ({ current_page: 1, last_page: 1, per_page: 50, total, from: total ? 1 : null, to: total || null, nome, ...extra });

const posizioni = (extra: Record<string, unknown> = {}) => ({
    alla: '2026-12-31', finito: true,
    righe: [
        { anagrafica_id: 7, nome: 'Bice', immobile_id: 3, unita: 'Int. C', ordinarie: 41000, straordinarie: 0, precedenti: 0, successivi: 0, non_emesse: 0, crediti: 0, totale: 41000, anticipi: 0, pregresso_nella_rata: false },
        { anagrafica_id: 8, nome: 'Ugo', immobile_id: null, unita: null, ordinarie: 0, straordinarie: 0, precedenti: 0, successivi: 0, non_emesse: 0, crediti: -3000, totale: -3000, anticipi: -5000, pregresso_nella_rata: false },
    ],
    pagine: pagine(2, 'pagina_posizioni'),
    totali: { ordinarie: 41000, straordinarie: 0, precedenti: 0, successivi: 0, non_emesse: 0, crediti: -3000, totale: 38000, anticipi: -5000 },
    mostra: { successivi: false, non_emesse: false, anticipi: true },
    riga_crediti: { crediti: 38000, posizioni: 38000, spese_anticipate: 0, differenza: 0 },
    riga_anticipi: { anticipi: 5000, conto: 5000, differenza: 0 },
    avvertenze: { data: 'alla data del 31/12/2026', segno: 'Gli importi dei condòmini: positivo vuol dire che devono, negativo che hanno un credito.', ordine: 'In ordine di persona.', esercizio_della_rata: 'L\'esercizio di una rata è quello del suo piano.', anticipi: 'Il totale è senza gli anticipi.', riservato: 'Non va esposto in bacheca.' },
    ...extra,
});

const riga = (extra: Record<string, unknown>) => ({
    anagrafica_id: 7, nome: 'Bice', immobile_id: 3, unita: 'Int. C', rata_id: 50, numero_rata: 12, piano: 'Preventivo 2026', gestione: 'Ordinaria', importo: 17125, scadenza: '2026-12-05',
    scaduta: true, giorni: 26, fascia: '1_30', origine: null, pregresso_nella_rata: false, annata_scadenza: '2026', annata_competenza: '2026', versato_dopo: 6125, resta_oggi: 11000, lettera: { tipo: 'sollecito', data: '2026-12-10' }, ...extra,
});

const crediti = (extra: Record<string, unknown> = {}) => ({
    alla: '2026-12-31', oggi: '2027-02-15', finito: true, ordine: 'scaduto',
    righe: [riga({}), riga({ rata_id: 51, numero_rata: 0, importo: 30000, scadenza: '2026-01-05', giorni: null, fascia: 'oltre_90', origine: 'origine non dichiarata', annata_scadenza: 'origine non dichiarata', annata_competenza: 'origine non dichiarata', versato_dopo: 0, resta_oggi: 30000, lettera: null })],
    pagine: pagine(2, 'pagina_crediti'),
    totali: { righe: 2, persone: 1, importo: 47125, scaduto: 47125, fasce: { '1_30': 17125, '31_90': 0, oltre_90: 30000 }, versato_dopo: 6125, resta_oggi: 41000 },
    avvertenze: { data: 'alla data del 31/12/2026', segno: 'Positivo vuol dire che devono.', ordine: 'In ordine di scaduto: prima chi deve di più.', annate: 'Due annate per rata.', versato_dopo: 'Versato dopo: quanto è entrato dopo il 31/12/2026.', lettera: 'L\'ultima lettera partita o spedita.', riservato: 'Non va esposto in bacheca.' },
    ...extra,
});

let w: VueWrapper | null = null;
/** La scheda con un elenco per volta, come la manda il server: le posizioni, o i crediti per anzianità. */
const monta = (p: Record<string, unknown> = {}, c: Record<string, unknown> = {}, elenco: 'posizioni' | 'crediti' = 'posizioni', troppe: string | null = null) => {
    w?.unmount();
    w = mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], elenco,
            posizioni: elenco === 'posizioni' ? posizioni(p) : null, crediti: elenco === 'crediti' && troppe === null ? crediti(c) : null, troppe } as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true } },
    });

    return w;
};
afterEach(() => { w?.unmount(); w = null; });

describe('la scheda «Condòmini»', () => {
    test('il § 9 dice le righe, i totali e le due righe che legano, come le dà il server', () => {
        const t = monta().find('[data-test="posizioni"]').text();
        const celle = monta().findAll('[data-test="riga-posizione"]').map((r) => r.findAll('td').map((td) => td.text().replace(/\s+/g, ' ')));
        expect(celle).toEqual([
            ['Bice', 'Int. C', '€ 410,00', '', '', '', '€ 410,00', ''],
            ['Ugo', '—', '', '', '', '€ -30,00', '€ -30,00', '€ -50,00'],
        ]);
        expect(t).toContain('Anticipi');
        expect(monta().find('[data-test="riga-crediti"]').text().replace(/\s+/g, ' ')).toContain('Crediti verso i condòmini della situazione patrimoniale € 380,00 = posizioni senza gli anticipi € 380,00 + spese anticipate per singole unità, non ancora chieste € 0,00');
        expect(monta().find('[data-test="riga-anticipi"]').text().replace(/\s+/g, ' ')).toContain('Anticipi ricevuti dai condòmini € 50,00 = conto «Anticipi ricevuti dai condòmini» € 50,00');
        expect(monta({ riga_crediti: { crediti: 38000, posizioni: 37000, spese_anticipate: 0, differenza: 1000 } }).find('[data-test="riga-crediti"]').text().replace(/\s+/g, ' ')).toContain('differenza € 10,00');
    });

    test('il § 10: le date formattate dal giorno del server, il ritardo, il pregresso con le sue parole, versato dopo e resta oggi per un esercizio finito', () => {
        const righe = monta({}, {}, 'crediti').findAll('[data-test="riga-credito"]').map((r) => r.text().replace(/\s+/g, ' '));
        expect(righe[0]).toContain('05/12/2026');
        expect(righe[0]).toContain('26 giorni, 1–30 giorni');
        expect(righe[0]).toContain('€ 61,25');
        expect(righe[0]).toContain('€ 110,00');
        expect(righe[0]).toContain('sollecito del 10/12/2026');
        // La cella del ritardo del pregresso senza origine dice l'origine, non un numero di giorni.
        expect(monta({}, {}, 'crediti').findAll('[data-test="riga-credito"]')[1].findAll('td')[5].text()).toBe('origine non dichiarata');
        expect(righe[1]).not.toMatch(/giorni, oltre/);
        expect(monta({}, {}, 'crediti').find('[data-test="crediti"]').text()).toContain('Versato dopo');

        const aperto = monta({}, { finito: false, righe: [riga({ versato_dopo: null, resta_oggi: null, scaduta: false, giorni: 0, fascia: null })], totali: { righe: 1, persone: 1, importo: 17125, scaduto: 0, fasce: { '1_30': 0, '31_90': 0, oltre_90: 0 }, versato_dopo: null, resta_oggi: null }, avvertenze: { ...crediti().avvertenze, versato_dopo: null } }, 'crediti');
        expect(aperto.find('[data-test="crediti"]').text()).not.toContain('Versato dopo');
        expect(aperto.find('[data-test="riga-credito"]').text()).toContain('non ancora scaduta');
    });

    test('la persona apre il suo estratto conto, la lettera il registro delle lettere della persona, lo scaduto «Rate scadute» alla stessa data', () => {
        const link = monta({}, {}, 'crediti').findAll('a').map((a) => [a.text().trim(), a.attributes('href')]);
        expect(link).toContainEqual(['Bice', '/gestionale/3/anagrafiche/7/estratto-conto']);
        expect(link).toContainEqual(['sollecito del 10/12/2026', '/gestionale/3/anagrafiche/7/estratto-conto?scheda=corrispondenza']);
        expect(link).toContainEqual(['«Rate scadute» al 31/12/2026', '/gestionale/3/rate-scadute?alla=2026-12-31']);
    });

    test('l\'ordine del § 10 si cambia con un collegamento che torna alla prima pagina; le pagine portano il loro numero', () => {
        expect(monta({}, {}, 'crediti').find('[data-test="ordine"]').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/condomini?elenco=crediti&ordine=persona&pagina_crediti=1');
        expect(monta({}, { ordine: 'persona' }, 'crediti').find('[data-test="ordine"]').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/condomini?elenco=crediti&ordine=scaduto&pagina_crediti=1');
        const piu = monta({}, { pagine: pagine(120, 'pagina_crediti', { last_page: 3, current_page: 2, from: 51, to: 100 }) }, 'crediti');
        const link = piu.find('[data-test="pagine-crediti"]').findAll('a').map((a) => a.attributes('href'));
        expect(link).toEqual(['/gestionale/3/esercizi/9/rendiconto/condomini?elenco=crediti&pagina_crediti=1', '/gestionale/3/esercizi/9/rendiconto/condomini?elenco=crediti&pagina_crediti=3']);
    });

    test('un elenco per volta: due schede con il loro indirizzo; oltre le righe che la pagina regge, la frase al posto dei crediti', () => {
        const posizioniSole = monta();
        expect(posizioniSole.find('[data-test="posizioni"]').exists()).toBe(true);
        expect(posizioniSole.find('[data-test="crediti"]').exists()).toBe(false);
        expect(posizioniSole.find('[data-test="elenchi"]').findAll('a').map((a) => [a.text(), a.attributes('href'), a.attributes('aria-current') ?? null])).toEqual([
            ['Posizioni', '/gestionale/3/esercizi/9/rendiconto/condomini', 'page'],
            ['Crediti per anzianità', '/gestionale/3/esercizi/9/rendiconto/condomini?elenco=crediti', null],
        ]);
        const creditiSoli = monta({}, {}, 'crediti');
        expect(creditiSoli.find('[data-test="posizioni"]').exists()).toBe(false);
        expect(creditiSoli.find('[data-test="crediti"]').exists()).toBe(true);
        const troppe = monta({}, {}, 'crediti', 'L\'elenco ha più di 20.000 rate non pagate: più di quante il server riesce a leggere per questa pagina con la memoria che ha.');
        expect(troppe.find('[data-test="troppe"]').text()).toContain('più di quante il server riesce a leggere');
        expect(troppe.find('[data-test="crediti"]').exists()).toBe(false);
    });

    test('il pregresso spalmato nelle rate: la riga del § 9 e quella del § 10 lo dicono con le parole di «Rate scadute»', () => {
        const nove = monta({ righe: [{ ...posizioni().righe[0], pregresso_nella_rata: true }, posizioni().righe[1]] });
        expect(nove.findAll('[data-test="riga-posizione"]').map((r) => r.findAll('td')[1].text().includes('contiene anche una parte di pregresso'))).toEqual([true, false]);
        const dieci = monta({}, { righe: [riga({ pregresso_nella_rata: true }), riga({ rata_id: 52, numero_rata: 11 })] }, 'crediti');
        expect(dieci.findAll('[data-test="riga-credito"]').map((r) => r.findAll('td')[2].text().includes(NOTA_PREGRESSO_NELLA_RATA))).toEqual([true, false]);
        expect(NOTA_PREGRESSO_NELLA_RATA).toBe('Questa rata contiene anche una parte di pregresso: la sua età è quella della rata.');
        const sorgente = readFileSync(resolve(__dirname, '../../../../../../app/Services/Gestionale/PosizioniDeiCondomini.php'), 'utf8');
        expect(sorgente.match(/public const NOTA_PREGRESSO_NELLA_RATA = '([^']+)';/)?.[1]).toBe(NOTA_PREGRESSO_NELLA_RATA);
    });

    test('nessuna delle parole che il rendiconto non dice mai di sé', () => {
        const testo = (monta().text() + monta({}, {}, 'crediti').text()).toLowerCase();
        for (const parola of ['pronto', 'conforme', 'corretto', 'approvabile', 'in regola', 'tutto in ordine']) {
            expect(testo).not.toContain(parola);
        }
    });
});
