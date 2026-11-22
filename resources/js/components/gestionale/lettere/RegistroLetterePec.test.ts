// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La lettera per PEC nella scheda «Corrispondenza» (1.12.0-beta.13): lo stato con il badge e la frase del server; la zona per la ricevuta di consegna (si
 * trascina, o «Carica la ricevuta»), che va al server con una visita Inertia; quando il file non si legge, il giorno e l'ora dell'invio già scritti, da
 * confermare o correggere, e la consegna scritta a mano con il file come prova; la ricevuta fra le prove, senza «Togli»; il termine contato dalla consegna;
 * «Manda di nuovo per PEC, con le cifre di oggi» su una PEC non partita. I menu della scheda sono `v-select`, e niente è scritto sotto i 12 pixel.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vSelect from 'vue-select';

const axiosPost = vi.fn();
const routerPost = vi.fn();
vi.mock('axios', () => ({ default: { post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn(), post: (...a: unknown[]) => routerPost(...a), delete: vi.fn() }, usePage: () => ({ props: { errors: {} } }) }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}/${p.invio ?? ''}/${p.documento ?? ''}`;

const RegistroLettere = (await import('./RegistroLettere.vue')).default;

const rigaPec = (extra: Record<string, unknown> = {}) => ({
    id: 40, canale: 'pec', formato: null, destinatario: 'neri.carla@pec.it', stato: 'inviata', quando: '2026-12-03 10:12', errore: null, spedizione: null, spedibile: false,
    rimandabile: false,
    pec: { badge: 'Partita', frase: 'Partita per PEC il 03/12 alle 10:12 a neri.carla@pec.it, dalla casella dello studio. Il gestore l\'ha accettata.', provata: false,
        ricevibile: true, ripetibile: false, invio: { giorno: '2026-12-03', ora: '10:12' } },
    ...extra,
});
const lettera = (riga = rigaPec(), extra: Record<string, unknown> = {}) => ({
    lettera: 'l-40', tipo: 'diffida', emessa_il: '2026-12-03', ora: '10:12', emesso_da: 'Andrea Costa', totale: 64000, residuo_oggi: 64000, alla: '2026-12-03', termine: 15,
    termine_dalla_consegna: null, stato: riga.stato, righe: [riga], pdf: { invio_id: 40, nome: 'diffida.pdf', impronta: 'abc', verificato: true }, prove: [], eventi: [],
    ...extra,
});

let w: VueWrapper | null = null;
const monta = (lettere: unknown[]) => {
    w = mount(RegistroLettere, { attachTo: document.body, props: { condominioId: 3, lettere: lettere as any, oggi: '2026-12-04' } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; axiosPost.mockReset(); routerPost.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();
const file = (nome: string) => new File(['contenuto'], nome, { type: nome.endsWith('.pdf') ? 'application/pdf' : 'message/rfc822' });
async function scegliIlFile(f: File) {
    const campo = w!.get('[data-testid="file-ricevuta-40"]');
    Object.defineProperty(campo.element, 'files', { value: [f], configurable: true });
    await campo.trigger('change');
}

describe('la riga di una PEC', () => {
    test('il badge e la frase del server; la zona della ricevuta, con «Carica la ricevuta» per chi non trascina', () => {
        monta([lettera()]);
        const t = pulito(w!.text());
        expect(w!.get('[data-testid="badge-pec-40"]').text()).toBe('Partita');
        expect(w!.get('[data-testid="badge-pec-40"]').classes()).toContain('rounded-md');
        expect(t).toContain('Partita per PEC il 03/12 alle 10:12 a neri.carla@pec.it, dalla casella dello studio. Il gestore l\'ha accettata.');
        expect(t).toContain('Trascina qui la ricevuta di consegna (.eml)');
        expect(t).toContain('Carica la ricevuta');
    });

    test('la ricevuta scelta va al server con una visita Inertia, con il file', async () => {
        monta([lettera()]);
        await scegliIlFile(file('ricevuta.eml'));
        const [indirizzo, dati, opzioni] = routerPost.mock.calls.at(-1) as [string, Record<string, unknown>, Record<string, unknown>];
        expect(indirizzo).toBe('/gestionale.lettere.ricevuta/40/');
        expect((dati.ricevuta as File).name).toBe('ricevuta.eml');
        expect(opzioni).toMatchObject({ forceFormData: true, preserveScroll: true });
    });

    test('il file che non si legge: il giorno e l\'ora dell\'invio già scritti, da confermare o correggere; la consegna va con il file come prova', async () => {
        monta([lettera()]);
        const pdf = file('ricevuta.pdf');
        await scegliIlFile(pdf);
        const opzioni = routerPost.mock.calls.at(-1)![2] as { onError: (e: Record<string, string>) => void };
        opzioni.onError({ ricevuta: 'Il programma non legge il giorno e l\'ora da questo file.', ricevuta_a_mano: 'proposta' });
        await flushPromises();

        const modulo = w!.get('[data-testid="consegna-a-mano-40"]');
        expect((modulo.get('[data-testid="giorno-consegna-40"]').element as HTMLInputElement).value).toBe('2026-12-03');
        expect((modulo.get('[data-testid="ora-consegna-40"]').element as HTMLInputElement).value).toBe('10:12');
        expect(pulito(modulo.text())).toContain('Il giorno e l\'ora sono quelli dell\'invio: confermali o correggili. Il file resta come prova.');
        await modulo.get('[data-testid="ora-consegna-40"]').setValue('10:14');
        await modulo.get('[data-testid="registra-consegna-40"]').trigger('click');
        const [indirizzo, dati] = routerPost.mock.calls.at(-1) as [string, Record<string, unknown>];
        expect(indirizzo).toBe('/gestionale.lettere.ricevuta/40/');
        expect(dati).toMatchObject({ giorno: '2026-12-03', ora: '10:14' });
        expect(dati.ricevuta).toBe(pdf);
    });

    test('consegnata: il badge, il termine dalla consegna, la ricevuta fra le prove senza «Togli»; gli eventi con le parole del server', () => {
        monta([lettera(rigaPec({ pec: { badge: 'Consegnata', frase: 'Consegnata il 03/12 alle 10:14 a neri.carla@pec.it, dalla ricevuta del gestore (Gestore di prova).',
            provata: true, ricevibile: true, ripetibile: false, invio: { giorno: '2026-12-03', ora: '10:12' } } }), {
            termine_dalla_consegna: { entro: '2026-12-18', giorni: 15, consegna: '2026-12-03', a_mano: false },
            prove: [{ id: 51, invio_id: 40, nome: 'ricevuta-consegna.eml', aggiunta_il: '2026-12-03', rimovibile: false, della_consegna: true }],
            eventi: [{ evento: 'ricevuta', il: '2026-12-03T10:02:00+00:00', giorno: '2026-12-03', da_nome: 'Andrea Costa', parole: 'consegnata il 03/12 alle 10:14' }],
        })]);
        const t = pulito(w!.text());
        expect(w!.get('[data-testid="badge-pec-40"]').text()).toBe('Consegnata');
        expect(t).toContain('Entro il 18/12/2026: 15 giorni dal ricevimento, contati dalla consegna del 03/12/2026.');
        expect(t).toContain('ricevuta-consegna.eml');
        expect(t).toContain('ricevuta di consegna · non si toglie');
        expect(t).toContain('ricevuta caricata il 03/12/2026, da Andrea Costa (consegnata il 03/12 alle 10:14)');
        expect(w!.findAll('button').map((b) => b.text())).not.toContain('Togli');
    });

    test('una PEC non partita: «Manda di nuovo per PEC, con le cifre di oggi», e la frase dopo', async () => {
        monta([lettera(rigaPec({ stato: 'non_partita', pec: { badge: 'Non partita', frase: 'Non partita il 03/12 alle 10:12, per x: Il server della PEC non ha accettato il messaggio.',
            provata: false, ricevibile: false, ripetibile: true, invio: { giorno: '2026-12-03', ora: '10:12' } } }))]);
        expect(w!.find('[data-testid="zona-ricevuta-40"]').exists()).toBe(false);
        axiosPost.mockResolvedValueOnce({ data: { partite: 1, non_partite: 0, frase: '1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta.', conti_cambiati: [] } });
        await w!.get('[data-testid="pec-di-nuovo-40"]').trigger('click');
        await flushPromises();
        expect(axiosPost.mock.calls.at(-1)![0]).toBe('/gestionale.lettere.pec-di-nuovo/40/');
        expect(pulito(w!.text())).toContain('1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta.');
    });
});

describe('gli standard della scheda', () => {
    test('i menu sono v-select, e il sorgente non scrive sotto i 12 pixel', () => {
        monta([lettera()]);
        expect(w!.find('select').exists()).toBe(false);
        expect(w!.findAllComponents(vSelect).length).toBeGreaterThan(0);
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/gestionale/lettere/RegistroLettere.vue'), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(9|10|11)px\]/);
        expect(sorgente).not.toContain('v-html');
    });
});
