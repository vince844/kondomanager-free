// @vitest-environment jsdom

/**
 * «Manca un condominio?» nei moduli «Nuova» e «Modifica» anagrafica del pannello manda a registrare il condominio solo chi
 * lo può fare (Coda 403).
 *
 * Il collegamento «gestione condomini» porta all'elenco dei condomìni, che chiede «Visualizza condomini»; da lì la
 * registrazione chiede «Crea condomini» (`CondominioPolicy`). I moduli delle anagrafiche si aprono con «Crea» o «Modifica
 * anagrafiche» (`AnagraficaPolicy`): il collegamento e «per registrarlo» compaiono solo con tutti e due i permessi dei
 * condomìni; senza, la frase dice soltanto quali condomìni compaiono nella tendina, che è vero in ogni caso. I ruoli di serie
 * li hanno tutti e due. È la forma del collegamento delle anagrafiche nel modulo dell'unità
 * (`gestionale/immobili/anagrafiche/AnagraficheNew.test.ts`).
 *
 * In fondo, il modulo di modifica aperto e inviato senza toccare niente: manda i dati che ha ricevuto.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

const chiGuarda = vi.hoisted(() => ({ ruoli: [] as string[], permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    usePage: () => ({
        url: '/admin/anagrafiche/create',
        props: { flash: {}, auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } } },
    }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', render: () => null } }));

const route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}${parametri?.id ? `/${parametri.id}` : ''}`;
(globalThis as Record<string, unknown>).route = route;

const AnagraficheNew = (await import('./AnagraficheNew.vue')).default;
const AnagraficheEdit = (await import('./AnagraficheEdit.vue')).default;

const passa = { template: '<div><slot /></div>' };

function monta(pagina: unknown, props: Record<string, unknown>) {
    return mount(pagina as never, {
        props: props as never,
        global: {
            mocks: { route },
            stubs: {
                AppLayout: passa,
                Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
                VueDatePicker: true,
                HoverCard: passa, HoverCardTrigger: passa, HoverCardContent: passa,
            },
        },
    });
}

const MODULI = {
    'Nuova anagrafica': () => monta(AnagraficheNew, { condomini: [] }),
    'Modifica anagrafica': () => monta(AnagraficheEdit, { anagrafica: { id: 7, nome: 'Mario Rossi', indirizzo: 'Via Roma 1', condomini: [] }, condomini: [] }),
};

/** Il riquadro «Manca un condominio?»: il testo e i collegamenti che contiene. */
function mancaUnCondominio(modulo: keyof typeof MODULI) {
    const riquadro = MODULI[modulo]().findAll('div.text-xs').find((d) => d.text().includes('Manca un condominio?'));
    expect(riquadro, 'il riquadro «Manca un condominio?» c\'è').toBeDefined();

    return {
        testo: riquadro!.text().replace(/\s+/g, ' ').trim(),
        collegamenti: riquadro!.findAll('a').map((a) => a.attributes('href')),
    };
}

beforeEach(() => {
    chiGuarda.ruoli = ['collaboratore'];
    chiGuarda.permessi = [
        'Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche',
        'Visualizza condomini', 'Crea condomini', 'Modifica condomini',
    ];
});

describe.each(Object.keys(MODULI) as (keyof typeof MODULI)[])('%s — «Manca un condominio?» porta a registrarlo solo chi può (Coda 403)', (modulo) => {
    test('con «Visualizza» e «Crea condomini», come i ruoli di serie, il collegamento porta alla gestione condomini per registrarlo', () => {
        const riquadro = mancaUnCondominio(modulo);

        expect(riquadro.collegamenti).toEqual(['/condomini.index']);
        expect(riquadro.testo).toContain('gestione condomini per registrarlo a sistema');
        expect(riquadro.testo).not.toContain('anagrafiche.tooltip.buildings_missing_registered_only');
    });

    test.each([
        ['i soli permessi delle anagrafiche', ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche']],
        ['«Visualizza condomini» senza «Crea condomini»', ['Accesso pannello amministratore', 'Crea anagrafiche', 'Modifica anagrafiche', 'Visualizza condomini']],
        ['«Crea condomini» senza «Visualizza condomini»', ['Accesso pannello amministratore', 'Crea anagrafiche', 'Modifica anagrafiche', 'Crea condomini']],
    ])('con %s nessun collegamento e nessun «per registrarlo»: la frase dice solo quali condomìni compaiono', (_caso, permessi) => {
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = permessi as string[];
        const riquadro = mancaUnCondominio(modulo);

        expect(riquadro.collegamenti).toEqual([]);
        expect(riquadro.testo).not.toContain('per registrarlo');
        expect(riquadro.testo).toContain('anagrafiche.tooltip.buildings_missing_registered_only');
    });
});

describe('AnagraficheEdit — aperta e inviata senza toccare niente', () => {
    test('manda i dati che ha ricevuto, anche con un documento scaduto e testi scomodi', () => {
        const spia = vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
        const anagrafica = {
            id: 7, nome: "Maria D'Àngelo «la portinaia»", indirizzo: 'Via dell\'Orto 3/B', codice_fiscale: 'DNGMRA80A41H501X',
            luogo_nascita: "Sant'Anna d'Alfaedo", data_nascita: '1980-01-01', numero_documento: 'CA 12345 XY',
            tipologia_documento: 'id_card', scadenza_documento: '2024-03-31', email: 'maria@example.com', email_secondaria: null,
            pec: null, telefono: '06 1234 5678', cellulare: '+39 333 123 4567', note: 'Riga uno\nriga «due» & <tre>',
            condomini: [{ id: 3, nome: 'Condominio Aurora' }, { id: 5, nome: "Residenza l'Ulivo" }],
        };
        const pagina = monta(AnagraficheEdit, { anagrafica, condomini: anagrafica.condomini });

        (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

        const [url, corpo] = spia.mock.calls.at(-1)! as [string, Record<string, unknown>];
        spia.mockRestore();
        expect(url).toContain('anagrafiche.update');
        expect(corpo).toEqual({
            nome: anagrafica.nome, indirizzo: anagrafica.indirizzo, codice_fiscale: anagrafica.codice_fiscale,
            luogo_nascita: anagrafica.luogo_nascita, data_nascita: anagrafica.data_nascita,
            numero_documento: anagrafica.numero_documento, tipologia_documento: anagrafica.tipologia_documento,
            scadenza_documento: '2024-03-31', email: anagrafica.email, email_secondaria: null, pec: null,
            telefono: anagrafica.telefono, cellulare: anagrafica.cellulare, note: anagrafica.note, condomini: [3, 5],
        });
    });
});
