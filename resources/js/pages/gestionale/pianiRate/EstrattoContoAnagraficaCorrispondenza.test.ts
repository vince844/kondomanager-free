// @vitest-environment jsdom

/**
 * Coda 533 (decisione 231): le due schede dell'estratto conto. Si apre «Movimenti contabili»; «Corrispondenza (N)» ha nel nome il numero delle
 * voci dell'elenco della scheda (lettere e documenti, contati con la stessa funzione: `numeroDellaScheda`); in testa la riga dell'ultima lettera
 * con il testo della colonna di «Rate scadute» e «poi: …»; «Vedi la corrispondenza» apre la scheda; `?scheda=corrispondenza` nell'indirizzo la
 * apre all'arrivo.
 *
 * Perché `shallowMount`: le schede sono componenti della libreria, e il test legge la scheda scelta dalla prop che la pagina passa (`modelValue`).
 * Che cosa NON copre: l'elenco della scheda (`RegistroLettereCorrispondenza.test.ts`), i dati (`CorrispondenzaDellaPersonaTest.php`).
 */
import { describe, expect, test, vi } from 'vitest';
import { nextTick } from 'vue';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { Tabs, TabsTrigger } from '@/components/ui/tabs';
import RegistroLettere from '@/components/gestionale/lettere/RegistroLettere.vue';
import LettereDialog from '@/components/gestionale/lettere/LettereDialog.vue';
import AccordiDellaPersona from '@/components/gestionale/accordi/AccordiDellaPersona.vue';
import { numeroDellaScheda, vociDellaCorrispondenza } from '@/lib/gestionale/lettere/corrispondenza';

let indirizzo = '/admin/gestionale/28/anagrafiche/7/estratto-conto';
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ url: indirizzo, props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const EstrattoContoAnagrafica = (await import('./EstrattoContoAnagrafica.vue')).default;

const lettere = [
    { lettera: 'a', tipo: 'sollecito', emessa_il: '2026-03-05', ora: '10:00', emesso_da: null, totale: 0, residuo_oggi: 0, alla: null, termine: null, stato: 'spedita',
        righe: [{ id: 20 }], pdf: null, prove: [], eventi: [] },
];
const documenti = [
    { chiave: 'ricevuta-12', id: 12, quando: '', tipo: 'ricevuta', non_da_comunicare: false, emessa_il: '2026-03-01', ora: '10:00', emesso_da: null, numero: '12',
        versamento_del: null, importo: null, annullato: false, copia_del: null, righe: [] },
    { chiave: 'ricevuta-13', id: 13, quando: '', tipo: 'ricevuta', non_da_comunicare: true, emessa_il: '2026-03-02', ora: '10:00', emesso_da: null, numero: '12',
        versamento_del: null, importo: null, annullato: false, copia_del: null, righe: [] },
];
const ultima = { tipo: 'sollecito', data: '2026-03-05', canale: 'stampa', stato: 'spedita', spedizione: { canale: 'raccomandata', data: '2026-03-06', numero: 'RA1' } };
const inCoda = { tipo: 'promemoria', data: '2026-03-07', canale: 'email', stato: 'in_coda' };

function monta(altro: Record<string, unknown> = {}) {
    return shallowMount(EstrattoContoAnagrafica, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never,
            esercizio: { id: 5, nome: 'Esercizio 2026' } as never,
            anagrafica: { id: 7, nome: 'Giuseppe Rossi', codice_fiscale: null, immobili: [] } as never,
            timeline: [],
            stats: {
                totale_addebiti: '€ 0,00', totale_versamenti: '€ 0,00', saldo_finale: '€ 0,00', saldo_raw: 0, saldo_iniziale: '€ 0,00', saldo_iniziale_raw: 0,
                credito_disponibile: '€ 0,00', credito_disponibile_raw: 0, credito_per_gestione: [], compensabile: '€ 0,00', compensabile_raw: 0, compensabile_frase: '',
                compensabile_rata_id: null,
            },
            rimborso: { quote: [], casse: [], credito_in_saldi: 0 },
            lettere: lettere as never, documenti: documenti as never, ultima_lettera: { ultima, in_sospeso: inCoda } as never,
            ...altro,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}

describe('le schede dell\'estratto conto', () => {
    test('si apre «Movimenti contabili»; «Corrispondenza (N)» ha nel nome il numero delle voci dell\'elenco', () => {
        const w = monta();
        expect(w.findComponent(Tabs).props('modelValue')).toBe('movimenti');
        const nomi = w.findAllComponents(TabsTrigger).map((t) => t.text());
        expect(nomi).toEqual(['Movimenti contabili', 'Corrispondenza (3)']);
        expect(numeroDellaScheda(lettere, documenti as never)).toBe(vociDellaCorrispondenza(lettere, documenti as never).length);
    });

    test('in testa l\'ultima lettera con il testo della colonna di «Rate scadute» e «poi: …»; «Vedi la corrispondenza» apre la scheda', async () => {
        const w = monta();
        const riga = w.get('[data-testid="ultima-lettera-in-testa"]');
        expect(riga.findAll('span').map((x) => x.text())).toEqual(['Ultima lettera:', 'Sollecito spedito il 06/03/2026 con raccomandata A/R n. RA1',
            'poi: Promemoria in coda dal 07/03/2026']);
        expect(riga.text()).toContain('poi: Promemoria in coda dal 07/03/2026');
        const scorri = vi.fn();
        // jsdom non ha `scrollIntoView`: la pagina porta le schede in vista dopo il clic, e il test lo guarda.
        (Element.prototype as unknown as { scrollIntoView: unknown }).scrollIntoView = scorri;
        await w.get('[data-testid="vedi-la-corrispondenza"]').trigger('click');
        await nextTick();
        expect(w.findComponent(Tabs).props('modelValue')).toBe('corrispondenza');
        expect(scorri).toHaveBeenCalledTimes(1);
    });

    test('con la sola lettera in coda o non partita la riga dice «Nessuna lettera» come la colonna di «Rate scadute», poi «poi: …»', () => {
        const w = monta({ ultima_lettera: { ultima: null, in_sospeso: inCoda } });
        expect(w.get('[data-testid="ultima-lettera-in-testa"]').findAll('span').map((x) => x.text()))
            .toEqual(['Ultima lettera:', 'Nessuna lettera', 'poi: Promemoria in coda dal 07/03/2026']);
    });

    test('il registro riceve la persona (per il collegamento alle sue ricevute) e le lettere e i documenti', () => {
        const registro = monta().findComponent(RegistroLettere);
        expect(registro.props('personaId')).toBe(7);
        expect(registro.props('documenti')).toHaveLength(2);
        expect(registro.props('lettere')).toHaveLength(1);
    });

    test('dopo una lettera dalla finestra, o un accordo, si ricaricano anche l\'ultima lettera e le lettere', () => {
        const ricarica = vi.spyOn(router, 'reload').mockImplementation(() => undefined as never);
        const w = monta();
        w.findComponent(LettereDialog).vm.$emit('fatto');
        expect(ricarica).toHaveBeenLastCalledWith({ only: ['lettere', 'ultima_lettera'] });
        w.findComponent(AccordiDellaPersona).vm.$emit('fatto');
        expect(ricarica).toHaveBeenLastCalledWith({ only: ['accordi_rientro', 'lettere', 'ultima_lettera'] });
        ricarica.mockRestore();
    });

    test('senza lettere la riga lo dice; senza nessun documento «Vedi la corrispondenza» non c\'è', () => {
        const senzaLettere = monta({ lettere: [], ultima_lettera: { ultima: null, in_sospeso: null } });
        expect(senzaLettere.get('[data-testid="ultima-lettera-in-testa"]').text()).toContain('Nessuna lettera a questa persona in questo condominio.');
        expect(senzaLettere.find('[data-testid="vedi-la-corrispondenza"]').exists()).toBe(true);
        const niente = monta({ lettere: [], documenti: [], ultima_lettera: { ultima: null, in_sospeso: null } });
        expect(niente.find('[data-testid="vedi-la-corrispondenza"]').exists()).toBe(false);
        expect(niente.findAllComponents(TabsTrigger).map((t) => t.text())).toContain('Corrispondenza (0)');
    });

    test('`?scheda=corrispondenza` nell\'indirizzo apre la scheda della corrispondenza', () => {
        indirizzo = '/admin/gestionale/28/anagrafiche/7/estratto-conto?scheda=corrispondenza';
        try {
            expect(monta().findComponent(Tabs).props('modelValue')).toBe('corrispondenza');
        } finally {
            indirizzo = '/admin/gestionale/28/anagrafiche/7/estratto-conto';
        }
    });
});
