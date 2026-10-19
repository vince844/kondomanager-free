// @vitest-environment jsdom

/**
 * Cosa resta scoperto: le frasi del server (in `Beta53FrasiCorrezioneLegataTest.php`); il lucchetto di un saldo ordinario e quello senza titolare,
 * che non dicono un saldo di segno opposto; la prova a video.
 *
 * Il riquadro del lucchetto del pannello dei saldi, per le righe di un passaggio: dove la correzione resta un saldo manuale di segno opposto
 * sulla stessa gestione, la frase dice di legarlo a quel passaggio, dal modale «Aggiungi credito» o «Aggiungi debito», e che non occupa il posto
 * di un riporto. Lo stesso per l'accordo diverso fra le parti, quando una successione dopo ha letto la riga.
 *
 * I campi che decide il server si scrivono a mano con la forma che i test PHP provano che il server manda: `e_conguaglio` e `subentro_id`
 * (`Beta52FrasiRegolaWalletSaldoManualeTest.php`), `e_arretrato` e `e_conguaglio_con_arretrato` (`SuccessioneTest.php`, la pagina dei saldi con
 * l'arretrato agli eredi), `e_fonte_arretrato` (`SuccessioneTest.php`, le fonti dell'arretrato nella pagina dei saldi).
 */

import { describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn(), patch: vi.fn() },
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const SaldiDetailPanel = (await import('./SaldiDetailPanel.vue')).default;

const GESTIONE = { id: 7, nome: 'Ordinaria 2026', tipo: 'ordinaria', saldo_applicato: true };
const UGO = 11;
const SOGGETTI = [{ id: UGO, nome: 'Ugo', cognome: '', pivot: { id: 101, tipologia: 'proprietario', data_fine: null } }];
const CORREZIONE = 'la correzione passa da un saldo manuale di segno opposto sulla stessa gestione, legato a quel passaggio con «Aggiungi credito» o «Aggiungi debito»: non occupa il posto di un riporto.';

/** Una riga di un passaggio, assorbita da un piano: il lucchetto c'è, con le chiavi date. */
const dalPassaggio = (extra: Record<string, unknown>) => ({
    id: 1, saldo_iniziale: 18148, is_applicato: true, e_bloccato: true, origine: 'automatico', gestione_id: GESTIONE.id, anagrafica_id: UGO,
    piano_rate_id: 3, piano_rate: { id: 3, nome: 'Conguaglio 2026' }, gestione: GESTIONE, anagrafica: { id: UGO, nome: 'Ugo', cognome: '' },
    subentro_id: 4, subentro: { id: 4, decorrenza: '2026-07-01', tipo_passaggio: 'fine_locazione' }, e_conguaglio: true, e_arretrato: false,
    e_conguaglio_con_arretrato: false, e_fonte_arretrato: false, passaggio_id: null, e_legata: false, passaggio_in_parole: null, ...extra,
});

/** Monta il pannello, apre la gestione e clicca il lucchetto della riga; restituisce il testo del riquadro, con gli spazi normalizzati. */
async function riquadro(riga: unknown): Promise<string> {
    const w = mount(SaldiDetailPanel as never, {
        props: {
            immobile: { id: 1, nome: 'Interno 1', interno: '1', anagrafiche: SOGGETTI, saldi: [riga], passaggi_legabili: [] },
            gestioni: [GESTIONE], esercizio: { id: 41, nome: '2026' }, condominio: { id: 28, nome: 'Condominio Test' }, valoriInCentesimi: true,
        } as never,
        global: { stubs: { transition: false } },
    }) as VueWrapper;
    await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
    await w.vm.$nextTick();
    const lucchetto = w.findAll('button[title]').find((b) => (b.attributes('title') ?? '').length > 0 && b.find('svg').exists());
    expect(lucchetto, 'Il lucchetto della riga non c\'è').toBeDefined();
    await lucchetto!.trigger('click');
    await w.vm.$nextTick();

    return w.text().replace(/\s+/g, ' ');
}

describe('il riquadro del lucchetto dice di legare al passaggio il saldo di segno opposto', () => {
    test('una gamba della coppia di un passaggio, in un piano che non si riscrive più', async () => {
        const t = await riquadro(dalPassaggio({}));

        expect(t).toContain('saldo manuale di segno opposto sulla stessa gestione');
        expect(t).toContain(CORREZIONE);
    });

    test('una riga dell\'arretrato di una successione', async () => {
        const t = await riquadro(dalPassaggio({ e_arretrato: true, subentro: { id: 4, decorrenza: '2026-05-01', tipo_passaggio: 'successione' } }));

        expect(t).toContain('saldo manuale di segno opposto sulla stessa gestione');
        expect(t).toContain(CORREZIONE);
    });

    test('una gamba del conguaglio di una successione con l\'arretrato agli eredi', async () => {
        const t = await riquadro(dalPassaggio({ e_conguaglio_con_arretrato: true, subentro: { id: 4, decorrenza: '2026-05-01', tipo_passaggio: 'successione' } }));

        expect(t).toContain('saldo manuale di segno opposto sulla stessa gestione');
        expect(t).toContain(CORREZIONE);
    });

    test('una riga fra le fonti dell\'arretrato di una successione: l\'accordo diverso si scrive con un saldo manuale legato al passaggio del conguaglio, non alla successione', async () => {
        const t = await riquadro(dalPassaggio({ e_fonte_arretrato: true }));

        expect(t).toContain('un accordo diverso fra le parti');
        expect(t).toContain('il conguaglio resta, e un accordo diverso fra le parti si scrive con un saldo manuale sulla stessa gestione, legato al passaggio di questo conguaglio con «Aggiungi credito» o «Aggiungi debito»: non occupa il posto di un riporto.');
    });
});
