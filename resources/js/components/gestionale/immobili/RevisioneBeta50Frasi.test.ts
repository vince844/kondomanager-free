// @vitest-environment jsdom

/**
 * 1.11.0-beta.50, Fase 1-bis — le frasi delle pagine che la revisione ha trovato sbagliate (verbale `piano_esecutivo_beta50.md`,
 * «Fase 1-bis», R11, e i rilievi della ripresa in fondo a «La lista della ripresa»). I test del server sono in
 * `tests/Feature/Gestionale/RevisioneBeta50FrasiTest.php`.
 *
 * - Lo storico dell'unità (`TitolaritaSheet.vue`): dopo una fine di locazione senza un nuovo inquilino, con il conguaglio scritto,
 *   nessuno «entra». La coppia è di chi paga al posto dell'inquilino: la scheda lo dice con il nome che il server le dà, e senza nome
 *   con «chi paga al posto dell'inquilino», mai «a chi entra». Lo sa da sola: la voce ha `tipo_passaggio` e `entrante` nullo.
 * - Il modulo della fine locazione (`PassaggioNew.vue`): chi entra il giorno stesso dell'uscita (l'inizio registrato prima) non «resta»
 *   inquilino. La condizione dello «sfitta» resta quella larga (chiunque sia inquilino quel giorno), voluta; la parola no.
 *
 * Nato come test rosso, corretto prima del commit; accanto, i controlli di ciò che era già giusto.
 *
 * Che cosa NON copre: il riquadro verde dopo la registrazione nella pagina dei titolari («a debito di chi entra»): è in
 * `StrettoBeta50Modulo.test.ts` (R11), e il server è fissato nel test PHP (il nome e l'estratto conto di Ugo). L'avviso di «Annulla il
 * conguaglio» dopo una fermata, prima di confermare (decisione 83.2): è in `StrettoBeta50Pannello.test.ts`, e il server è fissato nel
 * test PHP. Il pannello «Cosa cambierà», che mostra le frasi del server così come sono.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount, shallowMount } from '@vue/test-utils';
import { nextTick } from 'vue';
import VueDatePicker from '@vuepic/vue-datepicker';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

vi.mock('axios', () => ({ default: { post: vi.fn(() => new Promise(() => {})) } }));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const TitolaritaSheet = (await import('./TitolaritaSheet.vue')).default;
const PassaggioNew = (await import('@/pages/gestionale/immobili/anagrafiche/PassaggioNew.vue')).default;

// --- Lo storico: la fine di Ines il 1/7 con «Scrivi», senza un nuovo inquilino ----------------------------------------------------

/** La voce dello storico come la dà il server (`StoricoTitolarita`): la coppia € 181,48 (36000 × 184/365 = 18147,95 → 18148). */
function fine(extra: Record<string, unknown> = {}): any {
    return {
        id: 9, tipo_passaggio: 'fine_locazione', sottotipo: null, decorrenza: '2026-07-01', decorrenza_a_parole: '1 luglio 2026', registrato_il: '12 ottobre 2026',
        uscente: 'Ines', entrante: null, estremi_titolo: null, copia_autentica_il: null, copia_autentica_a_parole: null,
        copia_autentica_attesa: false, documento_url: null, pertinenze: [],
        conguaglio: { stato: 'proposto', importo: 18148, importo_formattato: '€ 181,48', per_entrante: [], applicato: false, nota: null, nota_annullamento: null, annullato_il: null, annullabile: true },
        obbligati: ['Dal 1 luglio 2026 le voci a carico dell\'inquilino tornano a Ugo, nei piani generati o ricalcolati dopo il passaggio. Le rate già emesse a Ines restano sue.'],
        fermate: [], ordinaria: null, nota: null,
        annullato: false, annullato_il: null, annullato_da: null, nota_annullamento: null,
        annullabile: { si: true, motivo: null, avvisi: [], effetti: [] },
        ...extra,
    };
}

function montaLoStorico(p: any) {
    return mount(TitolaritaSheet, {
        props: {
            open: true, unita: 'Interno 1', condominioId: 18, immobileId: 24,
            storico: { passaggi: 1, periodi_chiusi: 0, righe: [], gruppi: [{ diritto: 'Proprietà', righe: [] }], subentri: [p] },
        },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
                Accordion: { template: '<div><slot /></div>' }, AccordionItem: { template: '<div><slot /></div>' },
                AccordionTrigger: { template: '<div><slot /></div>' }, AccordionContent: { template: '<div><slot /></div>' },
                BadgeRuolo: true,
                Input: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
}

describe('TitolaritaSheet — la coppia della fine senza un nuovo inquilino (R11)', () => {
    test('R11: la fine di Ines con «Scrivi», senza un nuovo inquilino: lo storico non dice «€ 181,48 a chi entra», perché nessuno entra', () => {
        const testo = montaLoStorico(fine()).text();

        // Controllo: lo storico dice la coppia, con la sua cifra.
        expect(testo).toContain('€ 181,48');

        expect(testo).not.toContain('a chi entra');
    });

    test('la guardia — nel cambio d\'inquilino (Luca entra) lo storico dice ancora «€ 181,48 a chi entra»', () => {
        expect(montaLoStorico(fine({ entrante: 'Luca' })).text()).toContain('€ 181,48 a chi entra, credito uguale a chi esce');
    });
});

// --- Il modulo: chi entra lo stesso giorno non «resta» inquilino ---------------------------------------------------------------------

const UGO = { id: 10, anagrafica: { id: 1, nome: 'Ugo', codice_fiscale: null }, tipologia: 'proprietario', quota: 100, data_inizio: '2019-01-01', data_fine: null };

/** La pagina della fine locazione di Ines (riga 11, già scelta) con le righe d'inquilino date, e la data del passaggio scritta. */
async function montaIlModulo(inquilini: { id: number; nome: string; quota: number; dal: string }[]) {
    const w = shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Interno 1' } as never,
            tipo: 'fine_locazione',
            rigaPreselezionata: 11,
            titolari: [UGO, ...inquilini.map((i, n) => ({ id: i.id, anagrafica: { id: 2 + n, nome: i.nome, codice_fiscale: null }, tipologia: 'inquilino', quota: i.quota, data_inizio: i.dal, data_fine: null }))],
            anagrafiche: [],
            pertinenze: [],
            rateEmesseCount: 6,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
    w.findAllComponents(VueDatePicker)[0].vm.$emit('update:modelValue', '2026-07-01');
    await nextTick();

    return w;
}

describe('PassaggioNew — chi entra il giorno dell\'uscita (rilievo della ripresa, punto 5)', () => {
    test('Luca entra il 1/7 (registrato prima) e Ines esce lo stesso giorno: il modulo non dice «resta inquilino Luca», né «l\'unità resta sfitta»', async () => {
        const w = await montaIlModulo([{ id: 11, nome: 'Ines', quota: 50, dal: '2019-01-01' }, { id: 12, nome: 'Luca', quota: 50, dal: '2026-07-01' }]);

        // Controlli: la casella di chi non entra c'è, e l'unità non resta sfitta (la condizione larga, voluta).
        expect(w.text()).toContain('Nessuno —');
        expect(w.text()).not.toContain('resta sfitta');

        expect(w.text()).not.toContain('resta inquilino Luca');
    });

    test('la guardia — Dora, inquilina con Ines dal 2019, resta: il modulo dice ancora «resta inquilino Dora»', async () => {
        const w = await montaIlModulo([{ id: 11, nome: 'Ines', quota: 50, dal: '2019-01-01' }, { id: 12, nome: 'Dora', quota: 50, dal: '2019-01-01' }]);

        expect(w.text()).toContain('Nessuno — resta inquilino Dora');
    });
});
