// @vitest-environment jsdom

/**
 * Cosa resta scoperto: il server (la porta, i rifiuti e i campi che manda sono in `Beta53SaldoLegatoPortaTest.php` e `Beta53SaldoLegatoWalletTest.php`);
 * le parole di ogni voce del selettore dei passaggi; la prova a video, il tema scuro e i 375 px.
 *
 * Il modale «Aggiungi credito» / «Aggiungi debito» con «Legato a un passaggio»: la riga del Wallet legata al passaggio per cui è scritta.
 *
 * L'interruttore sta con «Soggetto specifico», spento di partenza (il caso di sempre resta com'è), e c'è solo se l'unità ha passaggi a cui
 * legare; altrimenti il modale dice che non ce ne sono. Sta fuori dal selettore delle persone: c'è anche quando tutti i soggetti hanno già un
 * saldo, che è proprio il caso per cui la riga legata nasce, e lì il riquadro dice la strada. Acceso: niente è già scelto, né il passaggio né
 * la persona, anche con un passaggio solo; le persone sono tutte quelle dell'unità, anche chi ha già un saldo (la riga legata non occupa il
 * posto). Il POST manda `passaggio_id`; il rifiuto del server si legge nel modale. Nel modo di sempre, chi ha solo righe legate si può scegliere
 * per un saldo suo. Una riga legata ha il suo segno «Passaggio», con il titolo che le parole del server riempiono.
 *
 * I campi che decide il server si scrivono a mano con la forma che `Beta53SaldoLegatoWalletTest.php` prova che il server manda:
 * `passaggi_legabili` (id, parole, persone, con_parte_a_mano) per unità, `e_legata` e `passaggio_in_parole` per riga, e `passaggio_id`.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn(), patch: vi.fn() },
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const SaldiDetailPanel = (await import('./SaldiDetailPanel.vue')).default;

const GESTIONE = { id: 7, nome: 'Ordinaria 2026', tipo: 'ordinaria', saldo_applicato: true };
const INES = 10, UGO = 11, ELSA = 12;
const VENDITA = 2, FINE = 1;
const SOGGETTI = [
    { id: INES, nome: 'Ines', cognome: '', pivot: { id: 100, tipologia: 'inquilino', data_fine: '2026-06-30' } },
    { id: UGO, nome: 'Ugo', cognome: '', pivot: { id: 101, tipologia: 'proprietario', data_fine: '2026-10-31' } },
    { id: ELSA, nome: 'Elsa', cognome: '', pivot: { id: 102, tipologia: 'proprietario', data_fine: null } },
];
/** I passaggi dell'unità nella forma di `Beta53SaldoLegatoWalletTest.php` (la vendita del 1/11 dopo la fine di Ines del 1/7), dal più recente. */
const LEGABILI = [
    { id: VENDITA, parole: 'la vendita del 1 novembre 2026', persone: ['Ugo', 'Elsa'], con_parte_a_mano: true },
    { id: FINE, parole: 'la fine della locazione di Ines del 1 luglio 2026', persone: ['Ines'], con_parte_a_mano: false },
];

/** Una riga di saldo come arriva dal payload: manuale, libera, non legata, della persona data. */
const riga = (id: number, persona: number, nome: string, centesimi: number, extra: Record<string, unknown> = {}) => ({
    id, saldo_iniziale: centesimi, is_applicato: false, e_bloccato: false, origine: 'manuale', gestione_id: GESTIONE.id, anagrafica_id: persona,
    piano_rate_id: null, piano_rate: null, gestione: GESTIONE, anagrafica: { id: persona, nome, cognome: '' }, subentro_id: null,
    passaggio_id: null, e_legata: false, passaggio_in_parole: null, ...extra,
});
/** Una riga legata alla vendita, come la manda il server. */
const legata = (id: number, persona: number, nome: string, centesimi: number) =>
    riga(id, persona, nome, centesimi, { passaggio_id: VENDITA, e_legata: true, passaggio_in_parole: 'la vendita del 1 novembre 2026' });

function monta(saldi: unknown[], legabili: unknown[] = LEGABILI, soggetti = SOGGETTI) {
    return mount(SaldiDetailPanel as never, {
        props: {
            immobile: { id: 1, nome: 'Interno 1', interno: '1', anagrafiche: soggetti, saldi, passaggi_legabili: legabili },
            gestioni: [GESTIONE], esercizio: { id: 41, nome: '2026' }, condominio: { id: 28, nome: 'Condominio Test' }, valoriInCentesimi: true,
        } as never,
        global: { stubs: { transition: false } },
    }) as VueWrapper;
}

type Modulo = { targetType: string; importo: string; anagraficaId: number | null; legato?: boolean; passaggioId?: number | null };
const modulo = (w: VueWrapper) => (w.vm as unknown as { modalForm: Modulo }).modalForm;
const testo = (w: VueWrapper) => w.text().replace(/\s+/g, ' ');

/** Apre la gestione, «Aggiungi credito» (o debito) e sceglie «Soggetto specifico». */
async function apri(saldi: unknown[], legabili: unknown[] = LEGABILI, pulsante = 'Aggiungi credito'): Promise<VueWrapper> {
    const w = monta(saldi, legabili);
    await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
    await w.vm.$nextTick();
    await w.findAll('button').find((b) => b.text().includes(pulsante))!.trigger('click');
    await w.vm.$nextTick();
    await w.findAll('input[type="radio"]').find((r) => r.attributes('value') === 'personale')!.setValue(true);
    await w.vm.$nextTick();

    return w;
}

/** L'interruttore «Legato a un passaggio»: l'elemento più interno che ne porta il nome (una label, un pulsante o un interruttore). */
function interruttore(w: VueWrapper): DOMWrapper<Element> | undefined {
    const candidati = w.findAll('label, button, [role="switch"]').filter((e) => e.text().replace(/\s+/g, ' ').includes('Legato a un passaggio'));

    return candidati[candidati.length - 1];
}

/** Accende l'interruttore: la casella dentro la label, o il clic sul pulsante. */
async function accendi(w: VueWrapper): Promise<void> {
    const i = interruttore(w);
    expect(i, 'Nessun interruttore «Legato a un passaggio» nel modale').toBeDefined();
    const casella = i!.find('input[type="checkbox"]');
    if (casella.exists()) {
        await casella.setValue(true);
    } else {
        await i!.trigger('click');
    }
    await w.vm.$nextTick();
}

/** I passaggi che il selettore offre (gli id): da un `<select>` nativo o da un selettore con le voci dei passaggi. */
function passaggiOfferti(w: VueWrapper): number[] {
    const nativo = w.findAll('select').find((s) => s.findAll('option').some((o) => o.text().includes('la vendita del 1 novembre 2026')));
    if (nativo) {
        return nativo.findAll('option').map((o) => Number(o.attributes('value'))).filter((n) => Number.isFinite(n) && n > 0);
    }
    const sel = w.findAllComponents(vSelect).find((c) => ((c.props('options') as Array<Record<string, unknown>>) ?? []).some((o) => 'parole' in o));

    return sel ? (sel.props('options') as Array<{ id: number }>).map((o) => o.id) : [];
}

/** Le persone che il selettore delle persone offre, per nome. */
function personeOfferte(w: VueWrapper): string[] {
    const sel = w.findAllComponents(vSelect).find((c) => ((c.props('options') as Array<Record<string, unknown>>) ?? []).some((o) => 'nome' in o && !('parole' in o)));

    return sel ? (sel.props('options') as Array<{ nome: string }>).map((a) => a.nome) : [];
}

/** Sceglie il passaggio e la persona dai selettori del modale. */
async function scegli(w: VueWrapper, passaggio: number, persona: number): Promise<void> {
    const nativo = w.findAll('select').find((s) => s.findAll('option').some((o) => o.text().includes('la vendita del 1 novembre 2026')));
    if (nativo) {
        await nativo.setValue(String(passaggio));
    } else {
        w.findAllComponents(vSelect).find((c) => ((c.props('options') as Array<Record<string, unknown>>) ?? []).some((o) => 'parole' in o))!.vm.$emit('update:modelValue', passaggio);
    }
    w.findAllComponents(vSelect).find((c) => ((c.props('options') as Array<Record<string, unknown>>) ?? []).some((o) => 'nome' in o && !('parole' in o)))!.vm.$emit('update:modelValue', persona);
    await w.vm.$nextTick();
}

describe('«Legato a un passaggio» nel modale del Wallet', () => {
    test('l\'interruttore compare con «Soggetto specifico» e i passaggi dell\'unità, spento, con il suo aiuto', async () => {
        const w = await apri([]);

        expect(interruttore(w)).toBeDefined();
        expect(modulo(w).legato).toBe(false);
        expect(testo(w)).toContain('Per la parte di un passaggio che il programma non sposta: il saldo non occupa il posto di un riporto, e si toglie se annulli il passaggio.');
    });

    test('con «Intero immobile» l\'interruttore non c\'è (una riga legata è di una persona); senza passaggi il modale dice che non ce ne sono', async () => {
        const w = monta([]);
        await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
        await w.vm.$nextTick();
        await w.findAll('button').find((b) => b.text().includes('Aggiungi credito'))!.trigger('click');
        await w.vm.$nextTick();
        expect(modulo(w).targetType).toBe('solidale');
        expect(interruttore(w)).toBeUndefined();

        const senza = await apri([], []);
        expect(interruttore(senza)).toBeUndefined();
        expect(testo(senza)).toContain('Su questa unità non ci sono passaggi a cui legare un saldo.');
    });

    test('acceso: nessun passaggio e nessuna persona già scelti, anche con un passaggio solo; ci sono anche le persone con un saldo', async () => {
        // Ugo ha un riporto: nel modo di sempre non si offre; legato sì.
        const w = await apri([riga(5, UGO, 'Ugo', 12345)], [LEGABILI[0]]);
        await accendi(w);

        expect(modulo(w).legato).toBe(true);
        expect(modulo(w).passaggioId ?? null).toBeNull();
        expect(modulo(w).anagraficaId).toBeNull();
        expect(passaggiOfferti(w)).toEqual([VENDITA]);
        expect(personeOfferte(w)).toEqual(['Ines', 'Ugo', 'Elsa']);
    });

    test('acceso, i passaggi offerti sono quelli del server, nel suo ordine', async () => {
        const w = await apri([]);
        await accendi(w);

        expect(passaggiOfferti(w)).toEqual([VENDITA, FINE]);
    });

    test('il POST manda passaggio_id, e il rifiuto del server si legge nel modale, che resta aperto', async () => {
        const w = await apri([riga(5, UGO, 'Ugo', 12345)]);
        await accendi(w);
        await scegli(w, VENDITA, UGO);
        vi.mocked(router.post).mockClear();
        modulo(w).importo = '60,16';
        await w.vm.$nextTick();
        await w.findAll('button').find((b) => b.text().includes('Salva Credito'))!.trigger('click');
        await w.vm.$nextTick();

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ anagrafica_id: UGO, immobile_id: 1, gestione_id: 7, saldo_iniziale: -6016, passaggio_id: VENDITA });
        const opzioni = vi.mocked(router.post).mock.calls[0][2] as { onError: (e: Record<string, string>) => void };
        opzioni.onError({ passaggio_id: 'Il passaggio scelto non è di questo condominio, o è stato annullato.' });
        await w.vm.$nextTick();

        expect(testo(w)).toContain('Il passaggio scelto non è di questo condominio, o è stato annullato.');
        expect(testo(w)).toContain('Salva Credito');
    });

    test('acceso, «Salva» resta spento finché passaggio e persona non sono scelti', async () => {
        const w = await apri([]);
        await accendi(w);
        modulo(w).importo = '60,16';
        await w.vm.$nextTick();

        expect(w.findAll('button').find((b) => b.text().includes('Salva Credito'))!.attributes('disabled')).toBeDefined();
    });

    test('nel modo di sempre chi ha solo una riga legata si può scegliere per un saldo suo; chi ha un riporto no', async () => {
        const w = await apri([legata(5, UGO, 'Ugo', -6016), riga(6, ELSA, 'Elsa', 7000)]);

        expect(modulo(w).legato ?? false).toBe(false);
        expect(personeOfferte(w)).toEqual(['Ines', 'Ugo']);
    });

    test('tutti i soggetti hanno già un saldo: l\'interruttore c\'è lo stesso, e il riquadro dice la strada legata', async () => {
        const w = await apri([riga(5, INES, 'Ines', 100), riga(6, UGO, 'Ugo', 12345), riga(7, ELSA, 'Elsa', 7000)]);

        expect(testo(w)).toContain('Tutti i soggetti associati hanno già un saldo per questa gestione.');
        expect(testo(w)).toContain('Per la parte di un passaggio, attiva «Legato a un passaggio»: un saldo legato non occupa il posto e si toglie se annulli il passaggio.');
        expect(interruttore(w)).toBeDefined();
        await accendi(w);
        expect(personeOfferte(w)).toEqual(['Ines', 'Ugo', 'Elsa']);
    });
});

describe('la riga legata nel Wallet', () => {
    test('ha il segno «Passaggio», con il titolo dalle parole del server', async () => {
        const w = monta([legata(5, UGO, 'Ugo', -6016)]);
        await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
        await w.vm.$nextTick();

        const segno = w.findAll('[title]').find((e) => (e.attributes('title') ?? '').startsWith('Legato al passaggio:'));
        expect(segno, 'Nessun segno della riga legata').toBeDefined();
        expect(segno!.attributes('title')).toBe('Legato al passaggio: la vendita del 1 novembre 2026. Si toglie se annulli il passaggio; se un piano lo ha già assorbito, il passaggio non si annulla finché quel piano non è eliminato (il lucchetto si riapre).');
        expect(segno!.text()).toContain('Passaggio');
    });

    test('controprova — aperta e salvata senza toccare niente, la modifica manda la stessa cifra con il segno della colonna e la stessa gestione (verde anche prima)', async () => {
        const w = monta([legata(5, UGO, 'Ugo', -123457), legata(6, ELSA, 'Elsa', 29)]);
        await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
        await w.vm.$nextTick();
        const vm = w.vm as unknown as { startEdit: (s: unknown) => void; saveEdit: (s: unknown, t: 'credito' | 'debito') => void };

        vi.mocked(router.patch).mockClear();
        const [ugo, elsa] = [legata(5, UGO, 'Ugo', -123457), legata(6, ELSA, 'Elsa', 29)];
        vm.startEdit(ugo);
        vm.saveEdit(ugo, 'credito');
        vm.startEdit(elsa);
        vm.saveEdit(elsa, 'debito');

        expect(vi.mocked(router.patch).mock.calls.map((c) => c[1])).toEqual([
            { saldo_iniziale: -123457, gestione_id: 7 },
            { saldo_iniziale: 29, gestione_id: 7 },
        ]);
    });
});
