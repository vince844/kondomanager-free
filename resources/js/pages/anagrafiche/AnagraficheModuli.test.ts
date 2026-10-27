// @vitest-environment jsdom

/**
 * I moduli delle anagrafiche del pannello (nuova e modifica) portano solo dove si può entrare (decisione 137).
 *
 * L'elenco chiede «Visualizza anagrafiche» (`AnagraficaPolicy::viewAny`), i moduli chiedono «Crea» o «Modifica anagrafiche»:
 * un ruolo su misura può avere il modulo senza l'elenco. «Indietro», «Annulla» e la briciola portano all'elenco solo a chi lo
 * può consultare; agli altri «Indietro» e «Annulla» portano alla dashboard del pannello, che si apre con il solo accesso, e la
 * briciola dell'elenco non c'è. Dopo «Salva» il server riporta chi non consulta l'elenco al modulo, con il messaggio di
 * riuscita (`AnagrafichePermessiDelPannelloTest.php`, «Dopo «Salva»…»): il modulo lo mostra, anche al secondo salvataggio di
 * fila, quando il modulo resta montato (`useGiroFlash`). I ruoli di serie hanno la consultazione, e per loro non cambia niente.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

const chiGuarda = vi.hoisted(() => ({
    ruoli: [] as string[],
    permessi: [] as string[],
    flash: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    usePage: () => ({
        url: '/admin/anagrafiche/create',
        props: {
            get flash() { return chiGuarda.flash; },
            auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } },
        },
    }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', render: () => null } }));

const route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}${parametri?.id ? `/${parametri.id}` : ''}`;
(globalThis as Record<string, unknown>).route = route;

const AnagraficheNew = (await import('./AnagraficheNew.vue')).default;
const AnagraficheEdit = (await import('./AnagraficheEdit.vue')).default;

const ELENCO = '/admin.anagrafiche.index';
const DASHBOARD = '/admin.dashboard';

/** La briciola del layout come la disegna `Breadcrumbs.vue`: le voci prima dell'ultima sono collegamenti. */
const AppLayout = {
    props: { breadcrumbs: { type: Array, default: () => [] } },
    template: `<div>
        <nav class="briciole-del-layout">
            <template v-for="(b, i) in breadcrumbs" :key="i">
                <a v-if="i < breadcrumbs.length - 1" :href="b.href ?? '#'">{{ b.title }}</a>
                <span v-else>{{ b.title }}</span>
            </template>
        </nav>
        <slot />
    </div>`,
};

const passa = { template: '<div><slot /></div>' };

function monta(pagina: unknown, props: Record<string, unknown>) {
    return mount(pagina as never, {
        props: props as never,
        global: {
            mocks: { route },
            stubs: {
                AppLayout,
                Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
                VueDatePicker: true,
                HoverCard: passa, HoverCardTrigger: passa, HoverCardContent: passa,
            },
        },
    });
}

const nuova = () => monta(AnagraficheNew, { condomini: [] });
const modifica = () => monta(AnagraficheEdit, {
    anagrafica: { id: 7, nome: 'Mario Rossi', indirizzo: 'Via Roma 1', condomini: [] },
    condomini: [],
});

/** I collegamenti della pagina: testo e destinazione. */
function collegamenti(pagina: ReturnType<typeof monta>): Array<{ testo: string; href: string | undefined }> {
    return pagina.findAll('a').map((a) => ({ testo: a.text().replace(/\s+/g, ' ').trim(), href: a.attributes('href') }));
}

function dove(pagina: ReturnType<typeof monta>, testo: string): Array<string | undefined> {
    return collegamenti(pagina).filter((c) => c.testo === testo).map((c) => c.href);
}

/** Il router di Inertia 3 emette `inertia:success` sul documento a ogni visita riuscita, anche quando la pagina resta montata. */
async function visitaRiuscita() {
    document.dispatchEvent(new CustomEvent('inertia:success', { detail: { page: {} } }));
    await nextTick();
}

/** Due salvataggi di fila nello stesso modulo: il primo messaggio si chiude da solo, il secondo deve comparire. */
async function dueSalvataggiDiFila(pagina: ReturnType<typeof monta>, messaggio: string) {
    await nextTick();
    expect(pagina.text()).toContain(messaggio);
    vi.advanceTimersByTime(6001);
    await nextTick();
    expect(pagina.text()).not.toContain(messaggio);

    await visitaRiuscita();
    expect(pagina.text()).toContain(messaggio);
}

afterEach(() => vi.useRealTimers());

beforeEach(() => {
    chiGuarda.ruoli = ['collaboratore'];
    chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche'];
    chiGuarda.flash = {};
});

describe('AnagraficheNew — il modulo nuovo porta solo dove si può entrare (decisione 137)', () => {
    test('con «Visualizza anagrafiche», come il collaboratore di serie, «Indietro», «Annulla» e la briciola portano all\'elenco, come prima', () => {
        const pagina = nuova();

        expect(dove(pagina, 'Indietro all\'elenco')).toEqual([ELENCO]);
        expect(dove(pagina, 'Annulla')).toEqual([ELENCO]);
        expect(dove(pagina, 'anagrafiche.header.list_residents_title')).toEqual([ELENCO]);
    });

    test('decisione 137 — con «Crea anagrafiche» senza «Visualizza anagrafiche» nessun collegamento porta all\'elenco: «Indietro» e «Annulla» portano alla dashboard', () => {
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Crea anagrafiche', 'Modifica anagrafiche'];
        const pagina = nuova();

        expect(collegamenti(pagina).map((c) => c.href)).not.toContain(ELENCO);
        expect(dove(pagina, 'anagrafiche.actions.back_to_dashboard')).toEqual([DASHBOARD]);
        expect(dove(pagina, 'Annulla')).toEqual([DASHBOARD]);
        expect(pagina.text()).not.toContain('Indietro all\'elenco');
    });

    test('decisione 137 — dopo «Salva», chi torna al modulo nuovo vede il messaggio di riuscita', async () => {
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Crea anagrafiche'];
        chiGuarda.flash = { message: { type: 'success', message: 'La nuova anagrafica è stata creata con successo.' } };
        const pagina = nuova();
        await nextTick();

        expect(pagina.text()).toContain('La nuova anagrafica è stata creata con successo.');
    });

    test('decisione 137 — due «Salva» di fila nel modulo nuovo: il secondo messaggio compare anche dopo che il primo si è chiuso', async () => {
        vi.useFakeTimers();
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Crea anagrafiche'];
        chiGuarda.flash = { message: { type: 'success', message: 'La nuova anagrafica è stata creata con successo.' } };

        await dueSalvataggiDiFila(nuova(), 'La nuova anagrafica è stata creata con successo.');
    });
});

describe('AnagraficheEdit — il modulo di modifica porta solo dove si può entrare (decisione 137)', () => {
    test('con «Visualizza anagrafiche», come il collaboratore di serie, «Indietro», «Annulla» e le briciole portano all\'elenco, come prima', () => {
        const pagina = modifica();

        expect(dove(pagina, 'Indietro all\'elenco')).toEqual([ELENCO]);
        expect(dove(pagina, 'Annulla')).toEqual([ELENCO]);
        expect(dove(pagina, 'anagrafiche.header.list_residents_title')).toEqual([ELENCO, ELENCO]);
    });

    test('decisione 137 — con «Modifica anagrafiche» senza «Visualizza anagrafiche» nessun collegamento porta all\'elenco: «Indietro» e «Annulla» portano alla dashboard', () => {
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Modifica anagrafiche'];
        const pagina = modifica();

        expect(collegamenti(pagina).map((c) => c.href)).not.toContain(ELENCO);
        expect(dove(pagina, 'anagrafiche.actions.back_to_dashboard')).toEqual([DASHBOARD]);
        expect(dove(pagina, 'Annulla')).toEqual([DASHBOARD]);
        expect(pagina.text()).not.toContain('Indietro all\'elenco');
    });

    test('decisione 137 — dopo «Salva», chi torna al modulo di modifica vede il messaggio di riuscita', async () => {
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Modifica anagrafiche'];
        chiGuarda.flash = { message: { type: 'success', message: 'L\'anagrafica è stata aggiornata con successo.' } };
        const pagina = modifica();
        await nextTick();

        expect(pagina.text()).toContain('L\'anagrafica è stata aggiornata con successo.');
    });

    test('decisione 137 — due «Salva» di fila nel modulo di modifica: il secondo messaggio compare anche dopo che il primo si è chiuso', async () => {
        vi.useFakeTimers();
        chiGuarda.ruoli = ['Su misura'];
        chiGuarda.permessi = ['Accesso pannello amministratore', 'Modifica anagrafiche'];
        chiGuarda.flash = { message: { type: 'success', message: 'L\'anagrafica è stata aggiornata con successo.' } };

        await dueSalvataggiDiFila(modifica(), 'L\'anagrafica è stata aggiornata con successo.');
    });
});
