// @vitest-environment jsdom

/**
 * La pagina di modifica di una segnalazione (pannello): la riga sotto la tendina «Pubblicata».
 *
 * La modifica non approva (decisione 127.2: l'approvazione cambia solo dall'interruttore, che avvisa il
 * creatore). Su una segnalazione in moderazione — come nasce quella aperta dal portale da un condòmino
 * di serie — scegliere «Pubblicata» e salvare non la mostra a nessuno e non fa partire avvisi: la riga
 * sotto la tendina lo dice, e dice dove si approva. Su una approvata la riga non c'è.
 *
 * Aperta e mandata senza toccare niente, la pagina manda i dati ricevuti (una privata senza residenti
 * compresa: il server la salva com'è, `tests/Feature/Portale/ResidentiDiUnaPrivataTest.php`).
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
// I permessi di chi apre la pagina: `null` = tutti (l'amministratore), altrimenti l'elenco dei concessi.
const permessi = vi.hoisted(() => ({ concessi: null as string[] | null }));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true,
        hasPermission: (chiesti: string[]) => permessi.concessi === null || chiesti.some((p) => permessi.concessi!.includes(p)),
    }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const SegnalazioniEdit = (await import('./SegnalazioniEdit.vue')).default;

function segnalazione(campi: Record<string, unknown> = {}) {
    return {
        id: 9, subject: "Muffa nell'angolo della camera «nord»", description: "Prima riga\nseconda riga",
        priority: 'urgente', stato: 'in lavorazione', is_private: true, is_published: false, is_approved: false,
        is_featured: true, can_comment: false,
        condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
        anagrafiche: [],
        ...campi,
    };
}

function monta(campi: Record<string, unknown> = {}) {
    return shallowMount(SegnalazioniEdit, {
        props: { condomini: [], anagrafiche: [], segnalazione: segnalazione(campi) },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

beforeEach(() => {
    permessi.concessi = null;
    vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
    vi.mocked(router.put).mockClear();
});

describe('SegnalazioniEdit — la riga sotto la tendina «Pubblicata» (decisione 127.2)', () => {
    test('su una segnalazione in moderazione la riga dice che serve l\'approvazione', () => {
        expect(monta({ is_approved: false, is_published: false }).text()).toContain('segnalazioni.publication.needs_approval');
        expect(monta({ is_approved: 0, is_published: 1 }).text()).toContain('segnalazioni.publication.needs_approval');
    });

    test('su una segnalazione approvata la riga non c\'è, in bozza o pubblicata', () => {
        expect(monta({ is_approved: true, is_published: true }).text()).not.toContain('segnalazioni.publication.needs_approval');
        expect(monta({ is_approved: 1, is_published: 0 }).text()).not.toContain('segnalazioni.publication.needs_approval');
    });

    test('aperta e mandata senza toccare niente, una privata senza residenti manda i dati ricevuti', () => {
        const pagina = monta();

        (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

        const chiamata = vi.mocked(router.put).mock.calls.at(-1)!;
        expect(chiamata[0]).toBe('/segnalazioni.update');
        expect(chiamata[1]).toEqual({
            subject: "Muffa nell'angolo della camera «nord»", description: "Prima riga\nseconda riga",
            priority: 'urgente', stato: 'in lavorazione', condominio_id: 3, can_comment: false, is_featured: true,
            is_published: false, anagrafiche: [], avvisa_destinatari: false,
        });
    });
});

describe('SegnalazioniEdit — la riga sotto la tendina dice dove si approva solo a chi può approvare', () => {
    // Lo scudo dell'elenco lo vede solo chi ha «Approva segnalazioni» (`components/segnalazioni/columns.ts`): a chi
    // non ce l'ha — il collaboratore di serie, per segnalazioni e comunicazioni — la riga dice chi
    // approva, come la frase dei residenti di una privata.
    test('chi approva legge lo scudo', () => {
        permessi.concessi = ['Approva segnalazioni'];
        const testo = monta({ is_approved: false, is_published: false }).text();
        expect(testo).toContain('segnalazioni.publication.needs_approval');
        expect(testo).not.toContain('segnalazioni.publication.waiting_approver');
    });

    test('chi non approva legge chi approva, non lo scudo', () => {
        permessi.concessi = [];
        for (const pagina of [monta({ is_approved: false, is_published: false }), monta({ is_approved: false, is_published: true })]) {
            const testo = pagina.text();
            expect(testo).toContain('segnalazioni.publication.waiting_approver');
            expect(testo).not.toContain('segnalazioni.publication.needs_approval');
        }
    });

    test('il permesso di approvare un\'altra entità non conta', () => {
        permessi.concessi = ['Approva comunicazioni'];
        expect(monta({ is_approved: false, is_published: false }).text()).toContain('segnalazioni.publication.waiting_approver');
    });

    test('su un oggetto approvato la riga non c\'è, con o senza il permesso', () => {
        for (const concessi of [[], ['Approva segnalazioni']]) {
            permessi.concessi = concessi;
            const testo = monta({ is_approved: true, is_published: true }).text();
            expect(testo).not.toContain('segnalazioni.publication.waiting_approver');
            expect(testo).not.toContain('segnalazioni.publication.needs_approval');
        }
    });
});

describe('SegnalazioniEdit — un destinatario che non è più nel palazzo', () => {
    // La stessa forma di `ComunicazioniEdit`: aperta e inviata senza toccare niente, la segnalazione resta
    // indirizzata a chi lo era, anche a chi nel frattempo è stato staccato dal palazzo. Qui l'elenco è
    // quello di tutte le anagrafiche e nessun filtro guarda il palazzo all'apertura: è la guardia.
    const ANNA = { id: 41, nome: 'Anna Neri' };
    const BRUNO = { id: 42, nome: 'Bruno Galli' };
    const dati = { is_private: false, is_published: true, is_approved: true, anagrafiche: [ANNA, BRUNO] };

    test('aperta e inviata senza toccare niente, e dopo che il server la rimanda, il corpo porta tutti i destinatari ricevuti', async () => {
        const pagina = shallowMount(SegnalazioniEdit, {
            props: { condomini: [], anagrafiche: [ANNA] as never, segnalazione: segnalazione(dati) },
            global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
        });
        await flushPromises();
        const submit = () => (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

        submit();
        expect(vi.mocked(router.put).mock.calls.at(-1)![1]).toEqual({
            subject: "Muffa nell'angolo della camera «nord»", description: "Prima riga\nseconda riga",
            priority: 'urgente', stato: 'in lavorazione', condominio_id: 3, can_comment: false, is_featured: true,
            is_published: true, anagrafiche: [41, 42], avvisa_destinatari: false,
        });

        await pagina.setProps({ segnalazione: segnalazione(dati) });
        await flushPromises();
        submit();
        expect((vi.mocked(router.put).mock.calls.at(-1)![1] as { anagrafiche: number[] }).anagrafiche).toEqual([41, 42]);
    });

    test('dopo un salvataggio rifiutato a palazzo cambiato, il server rimanda la pagina: il corpo è uguale ai dati ricevuti', async () => {
        // La stessa forma di `ComunicazioniEdit`: qui il cambio del palazzo non filtra i destinatari, e il
        // ritorno della pagina riporta palazzo e destinatari salvati, tutti. È la guardia.
        const pagina = shallowMount(SegnalazioniEdit, {
            props: { condomini: [], anagrafiche: [ANNA] as never, segnalazione: segnalazione(dati) },
            global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
        });
        await flushPromises();
        const s = (pagina.vm as unknown as { $: { setupState: { submit: () => void; form: Record<string, unknown> } } }).$.setupState;

        s.form.condominio_id = 5;
        await flushPromises();
        expect(s.form.anagrafiche).toEqual([41, 42]);

        await pagina.setProps({ segnalazione: segnalazione(dati) });
        await flushPromises();
        s.submit();

        expect(vi.mocked(router.put).mock.calls.at(-1)![1]).toEqual({
            subject: "Muffa nell'angolo della camera «nord»", description: "Prima riga\nseconda riga",
            priority: 'urgente', stato: 'in lavorazione', condominio_id: 3, can_comment: false, is_featured: true,
            is_published: true, anagrafiche: [41, 42], avvisa_destinatari: false,
        });
    });
});
