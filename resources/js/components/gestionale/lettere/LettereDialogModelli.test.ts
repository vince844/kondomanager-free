// @vitest-environment jsdom

/**
 * La finestra delle lettere con i modelli: il menu «Modello» porta prima il testo del programma, poi i modelli di quel tipo, quelli del
 * condominio prima di quelli per tutti; scegliere riempie il testo, che resta modificabile, e la richiesta porta il modello; la finestra parte
 * dall'ultimo modello di quel tipo nel condominio, e da un modello che non c'è più torna al testo del programma dicendolo; «Salva come modello…»
 * chiede il nome e «vale per» (nessuno già scelto), e da un modello offre «Aggiorna «nome»»; un altro tipo con il testo cambiato non porta il
 * modello del tipo di prima.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
const axiosPut = vi.fn();
vi.mock('axios', () => ({ default: {
    get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a), put: (...a: unknown[]) => axiosPut(...a),
} }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const LettereDialog = (await import('./LettereDialog.vue')).default;
const { testoModelloMancante, FRASE_MODELLI_SENZA_PERMESSO } = await import('@/lib/gestionale/lettere/modelli');

const TESTI = { promemoria: 'Programma promemoria', sollecito: 'Programma sollecito, [termine]', diffida: 'Programma diffida, [termine]', messa_in_mora: 'Programma mora, [termine]' };
const modelli = [
    // Arrivano per tutti i tipi; la finestra mostra quelli del tipo scelto, prima quelli del condominio.
    { id: 11, tipo: 'sollecito', nome: 'A per tutti', testo: 'Testo A, [termine]', per_tutti: true },
    { id: 12, tipo: 'sollecito', nome: 'Z del condominio', testo: 'Testo Z, [termine]', per_tutti: false },
    { id: 13, tipo: 'sollecito', nome: 'B per tutti', testo: 'Testo B, [termine]', per_tutti: true },
    { id: 21, tipo: 'diffida', nome: 'Diffida ferma', testo: 'Testo della diffida, [termine]', per_tutti: true },
];
const opzioni = (extra: Record<string, unknown> = {}) => ({
    tipi: [
        { tipo: 'promemoria', nome: 'Promemoria', solo_carta: false, conservato: false }, { tipo: 'sollecito', nome: 'Sollecito', solo_carta: false, conservato: true },
        { tipo: 'diffida', nome: 'Diffida', solo_carta: true, conservato: true }, { tipo: 'messa_in_mora', nome: 'Messa in mora', solo_carta: true, conservato: true },
    ],
    testi: TESTI,
    modelli,
    modelli_ricordati: { promemoria: null, sollecito: null, diffida: null, messa_in_mora: null },
    conti: [{ id: 4, nome: 'Banca', iban: 'IT60X0542811101000000123456', intestatario: 'Condominio' }],
    scelta_ricordata: { termini: { sollecito: { forma: 'giorni', giorni: 15 } }, cassa_id: 4, indirizzi: null },
    studio_compilato: true, senza_server_posta: false, puo_salvare_il_testo: true,
    ...extra,
});

let finestra: VueWrapper | null = null;
async function apri(o = opzioni(), props: Record<string, unknown> = {}) {
    axiosGet.mockResolvedValue({ data: o });
    finestra = mount(LettereDialog, { attachTo: document.body, props: { open: false, condominioId: 3, oggi: '2026-03-20', selezione: { tutte: true, ids: [] }, filtri: {}, ...props } });
    await finestra.setProps({ open: true });
    await flushPromises();
}
const el = <T extends HTMLElement>(id: string) => document.body.querySelector(`[data-testid="${id}"]`) as T | null;
const menuModello = () => el<HTMLSelectElement>('modello')!;
const voci = () => [...menuModello().querySelectorAll('option')].map((o) => o.textContent?.trim());
const gruppi = () => [...menuModello().querySelectorAll('optgroup')].map((g) => g.getAttribute('label'));
const area = () => el<HTMLTextAreaElement>('testo')!;
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const scegli = async (valore: string) => { menuModello().value = valore; menuModello().dispatchEvent(new Event('change')); await flushPromises(); };
const scegliTipo = async (t: string) => { el<HTMLElement>(`tipo-${t}`)!.querySelector('input')!.click(); await flushPromises(); };
const scrivi = async (t: string) => { area().value = t; area().dispatchEvent(new Event('input')); await flushPromises(); };
async function corpoDellAnteprima() {
    axiosPost.mockResolvedValueOnce({ data: { tipo: 'x', persone: [] } });
    el<HTMLButtonElement>('anteprima')!.click();
    await flushPromises();

    return axiosPost.mock.calls.at(-1)![1] as Record<string, unknown>;
}

beforeEach(() => { axiosGet.mockReset(); axiosPost.mockReset(); axiosPut.mockReset(); });
afterEach(() => { finestra?.unmount(); finestra = null; document.body.innerHTML = ''; });

describe('il menu del modello', () => {
    test('prima il testo del programma, poi i modelli del tipo: quelli del condominio, poi quelli per tutti; scegliere riempie il testo, che resta modificabile, e la richiesta porta il modello', async () => {
        await apri();
        expect(voci()).toEqual(['Testo del programma', 'Z del condominio', 'A per tutti', 'B per tutti']);
        expect(gruppi()).toEqual(['Per questo condominio', 'Per tutti i condomini']);
        expect(menuModello().value).toBe('');
        expect(area().value).toBe(TESTI.sollecito);
        expect((await corpoDellAnteprima()).modello_id).toBeNull();

        await scegli('11');
        expect(area().value).toBe('Testo A, [termine]');
        await scrivi('Testo A cambiato per questa volta, [termine]');
        const corpo = await corpoDellAnteprima();
        expect(corpo.modello_id).toBe(11);
        expect(corpo.testo).toBe('Testo A cambiato per questa volta, [termine]');

        // Di nuovo il testo del programma.
        await scegli('');
        expect(area().value).toBe(TESTI.sollecito);
        expect((await corpoDellAnteprima()).modello_id).toBeNull();

        // La diffida ha i suoi.
        await scegliTipo('diffida');
        expect(voci()).toEqual(['Testo del programma', 'Diffida ferma']);
        expect(gruppi()).toEqual(['Per tutti i condomini']);
    });

    test('la finestra parte dall\'ultimo modello di quel tipo nel condominio; da un modello che non c\'è più torna al testo del programma e lo dice', async () => {
        await apri(opzioni({ modelli_ricordati: { promemoria: null, sollecito: { id: 13, nome: 'B per tutti', mancante: false },
            diffida: { id: null, nome: 'Diffida vecchia', mancante: true }, messa_in_mora: null } }));
        expect(menuModello().value).toBe('13');
        expect(area().value).toBe('Testo B, [termine]');
        expect(el('modello-mancante')).toBeNull();

        await scegliTipo('diffida');
        expect(menuModello().value).toBe('');
        expect(area().value).toBe(TESTI.diffida);
        expect(el('modello-mancante')!.textContent).toContain(testoModelloMancante('Diffida vecchia'));
        expect(testoModelloMancante('Diffida vecchia')).toBe('Il modello «Diffida vecchia», scelto l\'ultima volta per questo tipo di lettera, non c\'è più o non vale più per questo condominio: la finestra parte dal testo del programma.');

        await scegliTipo('promemoria');
        expect(area().value).toBe(TESTI.promemoria);
        expect(el('modello-mancante')).toBeNull();

        // Tornando al sollecito, di nuovo il modello ricordato.
        await scegliTipo('sollecito');
        expect(menuModello().value).toBe('13');
        expect(area().value).toBe('Testo B, [termine]');
    });

    test('con il testo cambiato un altro tipo tiene il testo, ma non porta il modello del tipo di prima', async () => {
        await apri();
        await scegli('11');
        await scrivi('Il mio testo, [termine]');
        await scegliTipo('diffida');
        expect(area().value).toBe('Il mio testo, [termine]');
        expect(menuModello().value).toBe('');
        expect((await corpoDellAnteprima()).modello_id).toBeNull();
    });

    test('anche nella lettera di una persona il menu c\'è, e «Salva come modello…»; la richiesta dice che è la lettera di una persona, che non cambia il modello ricordato', async () => {
        await apri(opzioni(), { persona: { id: 1, nome: 'Ugo' }, selezione: null });
        expect(voci()).toEqual(['Testo del programma', 'Z del condominio', 'A per tutti', 'B per tutti']);
        expect(el('salva-modello')).not.toBeNull();
        await scegli('12');
        await scrivi('Caro Ugo, come detto al telefono, [termine]');
        // «Aggiorna» no: il testo scritto per una persona non diventa il modello di tutte le lettere.
        expect(el('aggiorna-modello')).toBeNull();
        expect(await corpoDellAnteprima()).toMatchObject({ singola: 1, modello_id: 12 });
        finestra?.unmount();
        document.body.innerHTML = '';
        // Il blocco non lo dice.
        await apri();
        expect(await corpoDellAnteprima()).not.toHaveProperty('singola');
    });

    test('scegliere un modello con il testo già cambiato non lo perde: «Torna al testo di prima» lo rimette, con il modello da cui era partito', async () => {
        await apri();
        // Con il testo non cambiato non c'è niente da rimettere.
        await scegli('11');
        expect(el('torna-al-testo')).toBeNull();
        await scrivi('Il mio testo lungo, scritto a mano, [termine]');
        await scegli('12');
        expect(area().value).toBe('Testo Z, [termine]');
        expect(el('torna-al-testo')).not.toBeNull();
        el<HTMLButtonElement>('torna-al-testo')!.click();
        await flushPromises();
        expect(area().value).toBe('Il mio testo lungo, scritto a mano, [termine]');
        expect(menuModello().value).toBe('11');
        expect(el('torna-al-testo')).toBeNull();
        // È ancora un testo cambiato: «Aggiorna» lo offre, e la richiesta porta il modello di partenza.
        expect(el('aggiorna-modello')).not.toBeNull();
        expect((await corpoDellAnteprima()).modello_id).toBe(11);
        // Scritto ancora dopo una scelta, non c'è più niente da rimettere.
        await scegli('13');
        expect(el('torna-al-testo')).not.toBeNull();
        await scrivi('Testo B cambiato, [termine]');
        expect(el('torna-al-testo')).toBeNull();
    });

    test('il collegamento ai modelli delle lettere, per tutti quelli che preparano le lettere', async () => {
        await apri(opzioni({ puo_salvare_il_testo: false }));
        const a = el<HTMLAnchorElement>('vai-ai-modelli')!;
        expect(a.getAttribute('href')).toBe('/impostazioni.stampe.modelli-lettere');
        expect(a.getAttribute('target')).toBe('_blank');
    });

    test('la riga dell\'IBAN sta sotto il termine, in una riga sua', async () => {
        await apri();
        const iban = el('iban')!;
        const termine = el('termine-forma')!;
        expect(iban.textContent).toContain('IBAN IT60X0542811101000000123456');
        expect(iban.parentElement).not.toBe(termine.parentElement);
        expect(termine.compareDocumentPosition(iban) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
});

describe('«Salva come modello…» e «Aggiorna»', () => {
    test('chiede il nome e «vale per», nessuno già scelto; salvato, il menu è sul modello nuovo', async () => {
        await apri();
        await scrivi('Un testo nuovo, [termine]');
        el<HTMLButtonElement>('salva-modello')!.click();
        await flushPromises();
        const conferma = el<HTMLButtonElement>('conferma-modello')!;
        expect(conferma.disabled).toBe(true);
        expect([...document.body.querySelectorAll<HTMLInputElement>('input[name="vale-per"]')].map((r) => r.checked)).toEqual([false, false]);

        const nome = el<HTMLInputElement>('nome-modello')!;
        nome.value = 'Sollecito gentile';
        nome.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(conferma.disabled).toBe(true);
        document.body.querySelector<HTMLInputElement>('input[name="vale-per"][value="condominio"]')!.click();
        await flushPromises();
        expect(conferma.disabled).toBe(false);

        const nuovo = { id: 31, tipo: 'sollecito', nome: 'Sollecito gentile', testo: 'Un testo nuovo, [termine]', per_tutti: false };
        axiosPost.mockResolvedValueOnce({ status: 201, data: { modello: nuovo, modelli: [...modelli, nuovo] } });
        conferma.click();
        await flushPromises();
        expect(axiosPost).toHaveBeenCalledWith('/gestionale.lettere.modelli.store', { tipo: 'sollecito', nome: 'Sollecito gentile', testo: 'Un testo nuovo, [termine]', per_tutti: false });
        expect(menuModello().value).toBe('31');
        expect(voci()).toContain('Sollecito gentile');
        expect(testo()).toContain('Salvato il modello «Sollecito gentile», per questo condominio.');
        expect((await corpoDellAnteprima()).modello_id).toBe(31);
    });

    test('scritto ancora mentre il modello si salva: il testo nuovo resta, ed è un testo cambiato rispetto al modello', async () => {
        await apri();
        await scrivi('Primo testo, [termine]');
        el<HTMLButtonElement>('salva-modello')!.click();
        await flushPromises();
        const nome = el<HTMLInputElement>('nome-modello')!;
        nome.value = 'Veloce';
        nome.dispatchEvent(new Event('input'));
        document.body.querySelector<HTMLInputElement>('input[name="vale-per"][value="tutti"]')!.click();
        await flushPromises();
        let rispondi: (v: unknown) => void = () => {};
        axiosPost.mockReturnValueOnce(new Promise((r) => { rispondi = r; }));
        el<HTMLButtonElement>('conferma-modello')!.click();
        await flushPromises();
        await scrivi('Primo testo, e altro ancora, [termine]');
        const salvato = { id: 41, tipo: 'sollecito', nome: 'Veloce', testo: 'Primo testo, [termine]', per_tutti: true };
        rispondi({ status: 201, data: { modello: salvato, modelli: [...modelli, salvato] } });
        await flushPromises();
        expect(area().value).toBe('Primo testo, e altro ancora, [termine]');
        expect(menuModello().value).toBe('41');
        expect(el('aggiorna-modello')!.textContent).toContain('Aggiorna «Veloce»');
    });

    test('partito da un modello e cambiato il testo, offre «Aggiorna «nome»», che cambia solo il testo del modello', async () => {
        await apri();
        expect(el('aggiorna-modello')).toBeNull();
        await scegli('12');
        expect(el('aggiorna-modello')).toBeNull();
        await scrivi('Testo Z rivisto, [termine]');
        expect(el('aggiorna-modello')!.textContent).toContain('Aggiorna «Z del condominio»');
        axiosPut.mockResolvedValueOnce({ data: { modello: { ...modelli[1], testo: 'Testo Z rivisto, [termine]' }, modelli: modelli.map((m) => (m.id === 12 ? { ...m, testo: 'Testo Z rivisto, [termine]' } : m)) } });
        el<HTMLButtonElement>('aggiorna-modello')!.click();
        await flushPromises();
        expect(axiosPut).toHaveBeenCalledWith('/gestionale.lettere.modelli.update', { testo: 'Testo Z rivisto, [termine]' });
        expect(testo()).toContain('Aggiornato il modello «Z del condominio»: le lettere già scritte non cambiano.');
        expect(el('aggiorna-modello')).toBeNull();
        expect(menuModello().value).toBe('12');
    });

    test('«Aggiorna» di un modello che vale per tutti i condomini lo dice prima, nel pulsante, e dopo', async () => {
        await apri();
        await scegli('11');
        await scrivi('Testo A rivisto per Le Rose, [termine]');
        expect(el('aggiorna-modello')!.textContent!.replace(/\s+/g, ' ').trim()).toBe('Aggiorna «A per tutti» per tutti i condomini');
        axiosPut.mockResolvedValueOnce({ data: { modello: { ...modelli[0], testo: 'Testo A rivisto per Le Rose, [termine]' }, modelli } });
        el<HTMLButtonElement>('aggiorna-modello')!.click();
        await flushPromises();
        expect(testo()).toContain('Aggiornato il modello «A per tutti», che vale per tutti i condomini: le lettere già scritte non cambiano.');
    });

    test('a chi non può salvare i modelli la finestra dice chi li salva', async () => {
        await apri(opzioni({ puo_salvare_il_testo: false }));
        expect(el('salva-modello')).toBeNull();
        await scegli('12');
        await scrivi('Cambiato, [termine]');
        expect(el('aggiorna-modello')).toBeNull();
        expect(testo()).toContain(FRASE_MODELLI_SENZA_PERMESSO);
    });
});
