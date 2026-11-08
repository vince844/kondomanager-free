// @vitest-environment jsdom

/**
 * La pagina dell'incasso da terzi: che cosa manda, e che cosa dice quando non si può registrare.
 *
 * Il corpo ha la voce di entrata, la cassa, la data, l'importo in euro (il server lo converte una volta sola, `MoneyHelper::toCents`), chi ha
 * versato e la causale, e `da` per tornare da dove si è partiti. Senza una voce di entrata la pagina dice di crearla con preventivo zero, con il
 * collegamento al piano dei conti; senza un esercizio aperto lo dice. La voce arrivata dal collegamento («Registra l'incasso») è già scelta.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import IncassoDaTerziNew from './IncassoDaTerziNew.vue';

(globalThis as any).route = (nome: string) => `/${nome}`;
const mocks = { route: (nome: string) => `/${nome}` };
const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    Head: { template: '<span />' },
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const voci = [{ id: 71, nome: 'Affitto antenna', label: 'Affitto antenna', preventivo: 24000 }, { id: 72, nome: 'Interessi attivi', label: 'Interessi attivi', preventivo: 0 }];
let pagina: VueWrapper | null = null;

function apri(sovrascrivi: Record<string, unknown> = {}) {
    pagina = mount(IncassoDaTerziNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            condomini: [],
            esercizio: { id: 41, nome: 'Esercizio 2026' },
            voci,
            casse: [{ id: 5, nome: 'Banca principale', tipo: 'banca' }],
            anagrafiche: [{ id: 9, nome: 'Torri del Nord Spa' }],
            prossimo_protocollo: 'ENT-2026-00001',
            voce_scelta: null,
            da: 'incassi',
            link_piano_dei_conti: '/piano-dei-conti',
            ...sovrascrivi,
        } as never,
        global: { stubs, mocks },
    });

    return pagina.vm as any;
}

function invia() {
    const stato = pagina!.vm as any;
    const corpi: Record<string, unknown>[] = [];
    let trasformazione: ((dati: Record<string, unknown>) => Record<string, unknown>) | null = null;
    stato.form.transform = (callback: typeof trasformazione) => { trasformazione = callback; return stato.form; };
    stato.form.post = (_url: string) => { corpi.push(trasformazione!(stato.form.data())); };
    stato.submit(false);

    return corpi;
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('l\'incasso da terzi', () => {
    test('compilato, manda voce, cassa, data, importo in euro, chi ha versato, causale e la pagina da cui si è partiti', () => {
        const stato = apri();
        Object.assign(stato.form, { conto_id: 71, anagrafica_id: 9, data_operazione: '2026-02-15', importo: 240, causale: 'Affitto antenna 2026' });

        expect(invia()).toEqual([{
            esercizio_id: 41, conto_id: 71, cassa_id: 5, anagrafica_id: 9, data_operazione: '2026-02-15', importo: 240, causale: 'Affitto antenna 2026',
            da: 'incassi', crea_altro: false,
        }]);
    });

    test('senza voce, senza importo o senza causale non parte', () => {
        const stato = apri();
        Object.assign(stato.form, { conto_id: null, importo: 240, causale: 'Affitto antenna' });
        expect(invia()).toEqual([]);
        Object.assign(stato.form, { conto_id: 71, importo: 0 });
        expect(invia()).toEqual([]);
        Object.assign(stato.form, { importo: 240, causale: '' });
        expect(invia()).toEqual([]);
    });

    test('la voce arrivata dal collegamento è già scelta', () => {
        expect(apri({ voce_scelta: 72 }).form.conto_id).toBe(72);
    });

    test('senza voci di entrata dice di crearne una con preventivo zero, con il collegamento al piano dei conti', () => {
        apri({ voci: [] });
        const blocco = pagina!.find('[data-testo="senza-voci"]');

        expect(blocco.text()).toContain('Crea la voce di entrata con preventivo zero');
        expect(blocco.find('a').attributes('href')).toBe('/piano-dei-conti');
    });

    test('senza un esercizio aperto lo dice', () => {
        apri({ esercizio: null, voci: [] });

        expect(pagina!.find('[data-testo="senza-esercizio"]').text()).toContain('non ha un esercizio aperto');
    });
});

describe('le frasi della pagina', () => {
    test('l\'incasso riduce i costi dell\'esercizio, non «i costi della voce» (una voce di entrata non ha costi)', () => {
        const stato = apri();
        stato.form.conto_id = 71;

        return Promise.resolve().then(() => {
            const testo = document.body.textContent!.replace(/ /g, ' ');
            expect(testo).toContain('L\'incasso riduce i costi dell\'esercizio, come il piano riduce già le quote.');
            expect(testo).not.toContain('riduce i costi della voce');
        });
    });
});
