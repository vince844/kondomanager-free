// @vitest-environment jsdom

/**
 * La pagina «Modelli delle lettere»: per ogni tipo prima il testo del programma (che non si modifica e si duplica), poi i modelli dello studio
 * con il nome, «vale per», chi l'ha cambiato e il giorno; i filtri per tipo e per condominio; il modulo con il tipo, il nome, «vale per» (nessuno
 * già scelto) e il testo, i campi e l'anteprima con i valori d'esempio; «Modifica» aperta e salvata senza toccare niente manda quello che ha
 * ricevuto; «Duplica» apre un modello nuovo con lo stesso testo; «Elimina» chiede la conferma, che dice che le lettere già scritte non cambiano;
 * chi non ha il permesso li vede e basta.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
const put = vi.fn();
const elimina = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...a: unknown[]) => post(...a), put: (...a: unknown[]) => put(...a), delete: (...a: unknown[]) => elimina(...a) },
    usePage: () => ({ props: {} }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => (p ? `/${nome}/${Object.values(p).join('/')}` : `/${nome}`);
// La scelta con la ricerca del programma (vue-select), sostituita da una tendina che fa lo stesso contratto: le opzioni, `label`, `reduce`, il valore.
vi.mock('vue-select', () => ({ default: {
    name: 'SceltaConRicerca',
    props: ['modelValue', 'options', 'label', 'reduce', 'placeholder', 'clearable', 'searchable'],
    emits: ['update:modelValue'],
    template: `<select :value="modelValue ?? ''" :data-cerca="searchable === false ? 'no' : 'si'" @change="$emit('update:modelValue', $event.target.value === '' ? null : $event.target.value)">
        <option value=""></option><option v-for="o in options" :key="reduce(o)" :value="reduce(o)">{{ o[label] }}</option></select>`,
} }));

const Pagina = (await import('./ModelliLettere.vue')).default;
const { FRASE_ELIMINA, FRASE_PAGINA, FRASE_PAGINA_SENZA_PERMESSO } = await import('@/lib/gestionale/lettere/modelli');

const props = (extra: Record<string, unknown> = {}) => ({
    tipi: [{ tipo: 'promemoria', nome: 'Promemoria' }, { tipo: 'sollecito', nome: 'Sollecito' }, { tipo: 'diffida', nome: 'Diffida' }, { tipo: 'messa_in_mora', nome: 'Messa in mora' }],
    testi_del_programma: { promemoria: 'Programma promemoria', sollecito: 'Gentile [nome], del condominio [condominio]: [totale] entro [termine].', diffida: 'Programma diffida', messa_in_mora: 'Programma mora' },
    modelli: [
        { id: 5, tipo: 'sollecito', nome: 'Sollecito cortese', testo: "Gentile [nome],\nrestano [totale].", condominio: null, cambiato_da: 'Marta Neri', cambiato_il: '2026-03-20' },
        { id: 6, tipo: 'sollecito', nome: 'Per Le Rose', testo: 'Le Rose, [nome].', condominio: { id: 2, nome: 'Le Rose' }, cambiato_da: null, cambiato_il: '2026-03-21' },
        { id: 7, tipo: 'diffida', nome: 'Diffida di Via Verdi', testo: 'Verdi, [nome].', condominio: { id: 3, nome: 'Via Verdi 3' }, cambiato_da: 'Gino Rossi', cambiato_il: '2026-03-22' },
    ],
    condomini: [{ id: 2, nome: 'Le Rose' }, { id: 3, nome: 'Via Verdi 3' }],
    campi: { '[nome]': 'il nome della persona', '[totale]': 'il totale della lettera', '[termine]': 'il termine per il versamento', '[condominio]': 'il nome del condominio' },
    esempio: { nome: 'Maria Bianchi', totale: '€ 180,00', termine: '15 giorni dal ricevimento di questa lettera', condominio: 'Le Rose' },
    puo_modificare: true,
    ...extra,
});

let w: VueWrapper | null = null;
/** L'intestazione: le guide (il riquadro), le briciole e il ritorno, come li riceve. */
const Intestazione = {
    props: ['guides', 'breadcrumbs', 'backUrl', 'pageTitle', 'backText', 'videoUrl'],
    template: `<div data-testid="intestazione" :data-ritorno="backUrl ?? ''" :data-briciole="(breadcrumbs ?? []).length"><p v-for="g in guides" :key="g.title">{{ g.description }}</p></div>`,
};
const monta = (extra: Record<string, unknown> = {}) => {
    w = mount(Pagina, { attachTo: document.body, props: props(extra) as any, global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: Intestazione } } });

    return w;
};
const el = <T extends HTMLElement>(id: string) => document.body.querySelector(`[data-testid="${id}"]`) as T | null;
const tutti = (sel: string) => [...document.body.querySelectorAll<HTMLElement>(sel)];
const testoDi = (x: Element | null) => (x?.textContent ?? '').replace(/\s+/g, ' ').trim();
const bottone = (dentro: Element, etichetta: string) => [...dentro.querySelectorAll('button')].find((b) => b.textContent?.trim() === etichetta) as HTMLButtonElement | undefined;
const imposta = async (id: string, valore: string) => {
    const c = el<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(id)!;
    c.value = valore;
    c.dispatchEvent(new Event(c instanceof HTMLSelectElement ? 'change' : 'input'));
    await flushPromises();
};

beforeEach(() => { post.mockReset(); put.mockReset(); elimina.mockReset(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

describe('l\'elenco', () => {
    test('per ogni tipo prima il testo del programma, che non si modifica e si duplica; poi i modelli, con «vale per», chi e quando', async () => {
        monta();
        const sollecito = el('tipo-sollecito')!;
        const voci = [...sollecito.querySelectorAll('[data-testid^="programma-"], [data-testid^="modello-"]')].map((v) => v.getAttribute('data-testid'));
        expect(voci).toEqual(['programma-sollecito', 'modello-5', 'modello-6']);
        const programma = el('programma-sollecito')!;
        expect(testoDi(programma)).toContain('Testo del programma');
        expect(testoDi(programma)).toContain('Non si modifica');
        expect(bottone(programma, 'Duplica')).toBeDefined();
        expect(bottone(programma, 'Modifica')).toBeUndefined();
        expect(bottone(programma, 'Elimina')).toBeUndefined();

        expect(testoDi(el('modello-5'))).toContain('Vale per tutti i condomini');
        expect(testoDi(el('modello-5'))).toContain('Cambiato da Marta Neri il 20/03/2026');
        expect(testoDi(el('modello-6'))).toContain('Vale per Le Rose');
        expect(testoDi(el('modello-6'))).toContain('Cambiato il 21/03/2026');
        expect(bottone(el('modello-6')!, 'Modifica')).toBeDefined();
        // Il promemoria senza modelli ha solo il testo del programma.
        expect(tutti('[data-testid="tipo-promemoria"] [data-testid^="modello-"]')).toHaveLength(0);
    });

    test('i filtri: per tipo; per condominio i suoi modelli e quelli per tutti; solo quelli per tutti', async () => {
        monta();
        await imposta('filtro-tipo', 'diffida');
        expect(el('tipo-sollecito')).toBeNull();
        expect(el('modello-7')).not.toBeNull();
        await imposta('filtro-tipo', '');
        await imposta('filtro-condominio', '2');
        expect(tutti('[data-testid^="modello-"]').map((v) => v.getAttribute('data-testid'))).toEqual(['modello-5', 'modello-6']);
        await imposta('filtro-condominio', 'tutti');
        expect(tutti('[data-testid^="modello-"]').map((v) => v.getAttribute('data-testid'))).toEqual(['modello-5']);
        // I testi del programma restano, per ogni tipo.
        expect(el('programma-diffida')).not.toBeNull();
    });
});

describe('l\'intestazione e la scelta del condominio', () => {
    test('la frase della pagina c\'è una volta sola, nel riquadro dell\'intestazione', () => {
        monta();
        const volte = (document.body.textContent ?? '').split(FRASE_PAGINA).length - 1;
        expect(volte).toBe(1);
    });

    test('il condominio si sceglie con la ricerca: «vale per» e il filtro portano tutti i condomini, e «vale per» anche «Tutti i condomini»', async () => {
        monta({ condomini: Array.from({ length: 100 }, (_, i) => ({ id: i + 1, nome: `Condominio ${i + 1}` })) });
        expect(el('filtro-condominio')!.getAttribute('data-cerca')).toBe('si');
        expect(el('filtro-condominio')!.querySelectorAll('option').length).toBe(102);
        el<HTMLButtonElement>('nuovo-modello')!.click();
        await flushPromises();
        const valePer = el('form-vale-per')!;
        expect(valePer.getAttribute('data-cerca')).toBe('si');
        expect([...valePer.querySelectorAll('option')].map((o) => o.textContent?.trim()).slice(0, 3)).toEqual(['', 'Tutti i condomini', 'Condominio 1']);
        expect(valePer.querySelectorAll('option').length).toBe(102);
    });
});

describe('il modulo', () => {
    test('«Nuovo modello»: tipo, nome, «vale per» (nessuno già scelto) e testo, con i campi e l\'anteprima con i valori d\'esempio; manda quello che c\'è scritto', async () => {
        monta();
        el<HTMLButtonElement>('nuovo-modello')!.click();
        await flushPromises();
        expect(el<HTMLSelectElement>('form-vale-per')!.value).toBe('');
        expect(el<HTMLButtonElement>('form-salva')!.disabled).toBe(true);
        expect(testoDi(el('form-campi'))).toContain('[nome]: il nome della persona');

        await imposta('form-tipo', 'sollecito');
        await imposta('form-nome', 'Sollecito breve');
        await imposta('form-vale-per', '3');
        await imposta('form-testo', 'Gentile [nome], del condominio [condominio]: [totale] entro [termine].');
        expect(testoDi(el('form-anteprima'))).toBe('Gentile Maria Bianchi, del condominio Le Rose: € 180,00 entro 15 giorni dal ricevimento di questa lettera.');
        expect(el<HTMLButtonElement>('form-salva')!.disabled).toBe(false);
        el<HTMLButtonElement>('form-salva')!.click();
        await flushPromises();
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/impostazioni.stampe.modelli-lettere.store');
        expect(post.mock.calls[0][1]).toEqual({ tipo: 'sollecito', nome: 'Sollecito breve', testo: 'Gentile [nome], del condominio [condominio]: [totale] entro [termine].', condominio_id: 3 });

        // «Vale per tutti i condomini»: condominio_id nullo.
        await imposta('form-vale-per', 'tutti');
        el<HTMLButtonElement>('form-salva')!.click();
        await flushPromises();
        expect(post.mock.calls[1][1]).toMatchObject({ condominio_id: null });
    });

    test('l\'email del foglio del conguaglio: il suo testo del programma, i suoi campi e l\'anteprima con l\'esercizio; «Duplica» ha il suo nome', async () => {
        monta({
            tipi: [...props().tipi, { tipo: 'foglio_conguaglio', nome: 'Email del foglio del conguaglio' }],
            testi_del_programma: { ...props().testi_del_programma, foglio_conguaglio: 'In allegato il suo foglio del consuntivo, [esercizio], del condominio [condominio].' },
            campi_per_tipo: { foglio_conguaglio: { '[nome]': 'il nome della persona', '[condominio]': 'il nome del condominio', '[esercizio]': 'il nome dell\'esercizio' } },
            esempio: { ...props().esempio, esercizio: 'Esercizio 2026' },
        });
        const sezione = el('tipo-foglio_conguaglio')!;
        expect(testoDi(sezione)).toContain('Email del foglio del conguaglio');
        bottone(el('programma-foglio_conguaglio')!, 'Duplica')!.click();
        await flushPromises();
        expect(el<HTMLInputElement>('form-nome')!.value).toBe('Email del foglio del conguaglio (copia)');
        expect(testoDi(el('form-campi'))).toContain('[esercizio]: il nome dell\'esercizio');
        expect(testoDi(el('form-campi'))).not.toContain('[totale]');
        expect(testoDi(el('form-anteprima'))).toBe('In allegato il suo foglio del consuntivo, Esercizio 2026, del condominio Le Rose.');
        // I campi che il tipo non ha restano come sono scritti: il server li rifiuta (i fogli) o li lascia (le lettere).
        await imposta('form-testo', 'Il foglio, [totale] entro [termine], [esercizio].');
        expect(testoDi(el('form-anteprima'))).toBe('Il foglio, [totale] entro [termine], Esercizio 2026.');
    });

    test('l\'anteprima di una lettera lascia [esercizio] com\'è scritto; la sezione dell\'email dei fogli dice la frase fissa', async () => {
        monta({
            tipi: [...props().tipi, { tipo: 'foglio_conguaglio', nome: 'Email del foglio del conguaglio' }],
            testi_del_programma: { ...props().testi_del_programma, foglio_conguaglio: 'In allegato il suo foglio.' },
            campi_per_tipo: { foglio_conguaglio: { '[nome]': 'il nome della persona', '[condominio]': 'il nome del condominio', '[esercizio]': 'il nome dell\'esercizio' } },
            frase_proposta_per_tipo: { foglio_conguaglio: 'È una proposta di conguaglio, da approvare dall\'assemblea: non è una richiesta di pagamento.' },
            esempio: { ...props().esempio, esercizio: 'Esercizio 2026' },
        });
        expect(testoDi(el('frase-fissa-foglio_conguaglio'))).toBe('In fondo a ogni email, sempre: «È una proposta di conguaglio, da approvare dall\'assemblea: non è una richiesta di pagamento.» e la data e l\'ora del calcolo delle cifre.');
        expect(el('frase-fissa-sollecito')).toBeNull();
        el<HTMLButtonElement>('nuovo-modello')!.click();
        await flushPromises();
        await imposta('form-tipo', 'sollecito');
        await imposta('form-testo', 'Gentile [nome], [totale], [esercizio].');
        expect(testoDi(el('form-anteprima'))).toBe('Gentile Maria Bianchi, € 180,00, [esercizio].');
        await imposta('form-tipo', 'foglio_conguaglio');
        expect(testoDi(el('form-frase-fissa'))).toContain('In fondo a ogni email, sempre:');
    });

    test('«Modifica» aperta e salvata senza toccare niente manda quello che ha ricevuto, e il tipo non si cambia', async () => {
        monta();
        bottone(el('modello-6')!, 'Modifica')!.click();
        await flushPromises();
        expect(el<HTMLSelectElement>('form-tipo')!.disabled).toBe(true);
        el<HTMLButtonElement>('form-salva')!.click();
        await flushPromises();
        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][0]).toBe('/impostazioni.stampe.modelli-lettere.update/6');
        expect(put.mock.calls[0][1]).toEqual({ nome: 'Per Le Rose', testo: 'Le Rose, [nome].', condominio_id: 2 });

        bottone(el('modello-5')!, 'Modifica')!.click();
        await flushPromises();
        el<HTMLButtonElement>('form-salva')!.click();
        await flushPromises();
        expect(put.mock.calls[1][1]).toEqual({ nome: 'Sollecito cortese', testo: "Gentile [nome],\nrestano [totale].", condominio_id: null });
    });

    test('«Duplica» dal testo del programma e da un modello apre un modello nuovo con lo stesso testo', async () => {
        monta();
        bottone(el('programma-sollecito')!, 'Duplica')!.click();
        await flushPromises();
        expect(el<HTMLSelectElement>('form-tipo')!.value).toBe('sollecito');
        expect(el<HTMLInputElement>('form-nome')!.value).toBe('Sollecito (copia)');
        expect(el<HTMLTextAreaElement>('form-testo')!.value).toBe('Gentile [nome], del condominio [condominio]: [totale] entro [termine].');
        expect(el<HTMLSelectElement>('form-vale-per')!.value).toBe('');
        await imposta('form-vale-per', 'tutti');
        el<HTMLButtonElement>('form-salva')!.click();
        await flushPromises();
        expect(post.mock.calls[0][1]).toEqual({ tipo: 'sollecito', nome: 'Sollecito (copia)', testo: 'Gentile [nome], del condominio [condominio]: [totale] entro [termine].', condominio_id: null });

        bottone(el('modello-7')!, 'Duplica')!.click();
        await flushPromises();
        expect(el<HTMLInputElement>('form-nome')!.value).toBe('Diffida di Via Verdi (copia)');
        expect(el<HTMLSelectElement>('form-vale-per')!.value).toBe('3');
        el<HTMLButtonElement>('form-salva')!.click();
        await flushPromises();
        expect(post.mock.calls[1][1]).toEqual({ tipo: 'diffida', nome: 'Diffida di Via Verdi (copia)', testo: 'Verdi, [nome].', condominio_id: 3 });
        expect(put).not.toHaveBeenCalled();
    });

    test('«Elimina» chiede la conferma, che dice che le lettere già scritte non cambiano', async () => {
        monta();
        bottone(el('modello-5')!, 'Elimina')!.click();
        await flushPromises();
        expect(elimina).not.toHaveBeenCalled();
        expect(testoDi(el('conferma-elimina'))).toContain(FRASE_ELIMINA);
        expect(FRASE_ELIMINA).toBe('Le lettere già scritte con questo modello non cambiano: il testo di ognuna è nella lettera.');
        el<HTMLButtonElement>('elimina-conferma')!.click();
        await flushPromises();
        expect(elimina).toHaveBeenCalledTimes(1);
        expect(elimina.mock.calls[0][0]).toBe('/impostazioni.stampe.modelli-lettere.destroy/5');
    });

    test('«Elimina il modello» premuto due volte manda una richiesta sola', async () => {
        monta();
        bottone(el('modello-5')!, 'Elimina')!.click();
        await flushPromises();
        const conferma = el<HTMLButtonElement>('elimina-conferma')!;
        conferma.click();
        conferma.click();
        await flushPromises();
        expect(elimina).toHaveBeenCalledTimes(1);
        expect(conferma.disabled).toBe(true);
    });
});

describe('senza il permesso', () => {
    test('i modelli si vedono; creare, cambiare, duplicare ed eliminare no, e la pagina dice chi lo fa', async () => {
        monta({ puo_modificare: false });
        expect(el('nuovo-modello')).toBeNull();
        expect(bottone(el('modello-5')!, 'Modifica')).toBeUndefined();
        expect(bottone(el('modello-5')!, 'Elimina')).toBeUndefined();
        expect(bottone(el('programma-sollecito')!, 'Duplica')).toBeUndefined();
        expect(testoDi(document.body)).toContain(FRASE_PAGINA_SENZA_PERMESSO);
        // Le impostazioni e le stampe gli risponderebbero di no: niente briciole né ritorno verso di loro.
        expect(el('intestazione')!.getAttribute('data-briciole')).toBe('0');
        expect(el('intestazione')!.getAttribute('data-ritorno')).toBe('');
    });

    test('con il permesso le briciole e il ritorno portano alle stampe', () => {
        monta();
        expect(el('intestazione')!.getAttribute('data-briciole')).toBe('3');
        expect(el('intestazione')!.getAttribute('data-ritorno')).toBe('/impostazioni/stampe');
    });
});
