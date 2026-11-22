// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina della sala (le bozze approvate della sala e delle scene della tappa 3): la barra della costituzione in alto, con la striscia della rete nel suo
 * punto d'innesto; prima dell'apertura il controllo dell'art. 1136 c. 6 con le due scelte del presidente, presidente e segretario, «Segna deserta la prima
 * convocazione»; le presenze con «Entra», «In collegamento» (con il motivo quando l'avviso non dice la videoconferenza), «Esce», la delega con il conto
 * del quinto; il voto di un punto con «Vota l'usufruttuario», «Tutti i presenti favorevoli», i tre pulsanti per testa, il conflitto, l'avviso del numero
 * legale accanto alla conferma, le note del presidente, la dichiarazione a verbale e «Conferma»; «Sospendi, prosegue il …», «Chiudi l'assemblea» con i
 * punti senza esito, «Registra dal foglio della sala» con l'ora del foglio. Ogni azione è un gesto della coda, con la versione da cui parte.
 *
 * Che cosa non copre: la coda sul disco e la striscia vera della rete (sono nei loro innesti); le regole dei gesti (i test PHP della sala).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import type { GestoDellaSala, LottoDiGesti, SalaDellAssemblea } from '@/types/assemblee';
import { delega, salaAperta, salaChiusa, salaDaAprire, votazioneDelPortone } from '@/components/assemblee/sala/salaDiProva';
import { scegliIlGiorno } from '@/components/campoGiornoDiProva';
import { IndexedDbFinto } from '@/lib/senzaRete/indexedDbFinto';
import { documento, paragrafo } from '@/lib/assemblee/verbale/struttura';
import votoSenzaRete from '../../../../../tests/Fixtures/sala/voto_senza_rete.json';
import desertaEApri from '../../../../../tests/Fixtures/sala/deserta_e_apri_senza_rete.json';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), visit: vi.fn(), url: '/admin/gestionale/3/assemblee/9/sala' }));
const rete = vi.hoisted(() => ({ lotti: [] as LottoDiGesti[], esito: 'applicato', messaggio: null as string | null, letture: [] as string[],
    /** Ciò che il server finto aggiunge alla risposta di un gesto (le versioni, i numeri). */
    piu: null as null | ((g: { tipo: string }) => Record<string, unknown>) }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: inertia.url, props: { auth: { user: { name: 'Andrea Costa', roles: ['amministratore'], permissions: [] } }, flash: {} } }),
    usePoll: () => ({ stop: () => {}, start: () => {}, polling: { value: true } }),
}));
vi.mock('axios', () => ({
    default: {
        post: vi.fn((_url: string, lotto: LottoDiGesti) => {
            rete.lotti.push(JSON.parse(JSON.stringify(lotto)));
            return Promise.resolve({ data: { ora_del_server: '2026-12-15T18:45:00Z', risposte: lotto.gesti.map((g) => ({ gesto: g.gesto, tipo: g.tipo,
                esito: rete.esito, messaggio: rete.messaggio, versione: null, conflitto: null, ...(rete.piu?.(g) ?? {}) })) } });
        }),
        // La lettura dello stato della sala (una richiesta JSON): nella prova lo stato resta quello della pagina.
        get: vi.fn((url: string) => {
            rete.letture.push(url);
            return Promise.reject(new TypeError('Lo stato resta quello della pagina'));
        }),
    },
}));
vi.mock('vue-select', async () => {
    const { defineComponent, h } = await import('vue');
    return {
        default: defineComponent({
            name: 'VSelect', props: ['modelValue', 'options', 'reduce', 'label', 'disabled'], emits: ['update:modelValue'],
            setup(p, { emit, attrs }) {
                return () => h('div', { class: 'v-select-finto', 'data-testid': attrs['data-testid'] }, (p.options ?? []).map((o: unknown, i: number) => h('button', {
                    type: 'button', 'data-opzione': i, onClick: () => emit('update:modelValue', p.reduce ? p.reduce(o) : o),
                }, typeof o === 'string' ? o : String((o as Record<string, unknown>)[p.label ?? 'label']))));
            },
        }),
    };
});
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const passa = { template: '<div><slot /></div>' };
const stubs = {
    GestionaleLayout: passa,
    PageHeaderGuide: { props: ['pageTitle', 'pageSubtitle', 'guides'], emits: ['open-text-guide'],
        template: '<header><h1>{{ pageTitle }}</h1><p>{{ pageSubtitle }}</p><ul data-testid="card-della-guida"><li v-for="g in guides" :key="g.title">{{ g.title }}: {{ g.description }}</li></ul><slot name="actions" /></header>' },
    SalaGuide: { props: ['open'], template: '<div data-finto="SalaGuide" :data-aperta="open" />' },
    DropdownMenu: passa, DropdownMenuTrigger: passa, DropdownMenuContent: passa,
    DropdownMenuItem: { emits: ['select'], template: '<button type="button" v-bind="$attrs" @click="$emit(\'select\')"><slot /></button>' },
};

const Pagina = (await import('./Sala.vue')).default;

let w: VueWrapper | null = null;
/** Il disco del browser risponde con un po' di ritardo: si aspetta che il gesto sia scritto, mostrato e (con la rete) mandato. */
const assesta = async () => {
    for (let i = 0; i < 8; i++) {
        await new Promise((fatto) => setTimeout(fatto, 3));
        await flushPromises();
    }
};
beforeEach(() => {
    rete.lotti = [];
    rete.esito = 'applicato';
    rete.messaggio = null;
    rete.letture = [];
    rete.piu = null;
    inertia.reload.mockClear();
    inertia.url = '/admin/gestionale/3/assemblee/9/sala';
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-12-15T18:45:00Z'));
    // Il deposito sul disco del browser: uno nuovo per ogni prova (la sala vista dal dispositivo è lo stato del server più i gesti in coda).
    (globalThis as Record<string, unknown>).indexedDB = new IndexedDbFinto();
});
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; vi.useRealTimers(); });

async function monta(sala: SalaDellAssemblea = salaAperta()) {
    w = mount(Pagina as never, { attachTo: document.body, props: { condominio: { id: 3, nome: 'Via Roma 12' }, condomini: [], sala } as never, global: { stubs } });
    await assesta();

    return w;
}
const testo = (sel: string) => (document.body.querySelector(sel)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const clic = async (sel: string) => {
    const el = document.body.querySelector(sel) as HTMLElement | null;
    if (!el) throw new Error(`Non trovo ${sel}`);
    el.click();
    await assesta();
};
const scrivi = async (sel: string, valore: string) => {
    const el = document.body.querySelector(sel) as HTMLInputElement | HTMLTextAreaElement | null;
    if (!el) throw new Error(`Non trovo ${sel}`);
    el.value = valore;
    el.dispatchEvent(new Event('input'));
    await flushPromises();
};
/** Sceglie un giorno nel calendario del campo (`CampoGiorno`), come a video: il campo del giorno non è più quello del browser, su cui si scriveva. */
const scegliGiorno = async (sel: string, iso: string) => {
    const el = document.body.querySelector(sel);
    if (!el) throw new Error(`Non trovo ${sel}`);
    await scegliIlGiorno(el, iso);
    await assesta();
};
/** Sceglie nel menu (`v-select`) la voce che comincia con l'etichetta. */
const scegli = async (testid: string, etichetta: string) => {
    const voce = [...document.body.querySelectorAll(`[data-testid="${testid}"] [data-opzione]`)].find((b) => (b.textContent ?? '').trim().startsWith(etichetta));
    if (!voce) throw new Error(`Non trovo «${etichetta}» in ${testid}`);
    (voce as HTMLElement).click();
    await flushPromises();
};
const gesti = (): GestoDellaSala[] => rete.lotti.flatMap((l) => l.gesti);
const ultimo = () => gesti().at(-1)!;

describe('la barra della costituzione e la rete', () => {
    test('in alto, sempre in vista: teste e valore contati sul posto, la convocazione, il punto d\'innesto della rete', async () => {
        await monta();
        expect(testo('[data-testid="barra-teste"]')).toBe('11 su 26 condòmini in sala (3 per delega)');
        expect(testo('[data-testid="barra-valore"]')).toBe('512,57 millesimi su 1.000');
        expect(testo('[data-testid="barra-stato"]')).toBe('Costituita in seconda convocazione');
        const testa = document.body.querySelector('[data-testid="testa-della-sala"]')!;
        expect(testa.className).toContain('sticky');
        // Lo sfondo della testa fissa è quello della pagina: un grigio suo si vedeva come un riquadro dietro la striscia e la barra.
        expect(testa.className.split(' ')).toContain('bg-background/95');
        expect(testa.querySelector('[data-innesto="rete"]')).not.toBeNull();
        // A sala aperta il menu della sala ha anche i gesti (deserta, sospendi, rinvia, chiudi): si chiama «Azioni», come nella pagina dell'assemblea.
        expect(testo('[data-testid="menu-sala"]')).toBe('Azioni');
        expect(testo('[data-innesto="rete"]')).toContain('In linea');
    });

    test('il punto della sola scala aggiunge la sua riga, gialla quando non basta', async () => {
        await monta();
        await clic('[data-testid="punto-7"]');
        expect(testo('[data-testid="barra-scala"]')).toContain('Punto 7, solo la scala B: 3 su 10 · 290,00 su 1.000,00 · servono 4 e 333,34');
        expect(testo('[data-testid="barra-scala"]')).toContain('Non bastano · decide il presidente');
        await clic('[data-testid="punto-4"]');
        expect(document.body.querySelector('[data-testid="barra-scala"]')).toBeNull();
    });
});

describe('prima dell\'apertura', () => {
    test('il controllo dell\'art. 1136 c. 6: «Apri, e il verbale lo scrive» chiede il motivo, e il gesto porta la scelta', async () => {
        await monta(salaDaAprire());
        expect(testo('[data-testid="controllo-apertura"]')).toContain('Bianchi Anna (Int. 14) non risulta fra i convocati');
        expect(testo('[data-testid="controllo-apertura"]')).toContain('art. 1136 c. 6');
        await scegli('presidente', 'Romano Ida');
        await scegli('segretario', 'L\'amministratore');
        const apri = () => document.body.querySelector('[data-testid="apri-assemblea"]') as HTMLButtonElement;
        expect(apri().textContent).toContain('Apri l\'assemblea alle 19:45');
        expect(apri().disabled).toBe(true);
        await clic('[data-testid="scelta-apri-lo-stesso"]');
        await scrivi('[data-testid="motivo-apertura"]', 'Bianchi Anna è presente e non si oppone.');
        expect(apri().disabled).toBe(false);
        await clic('[data-testid="apri-assemblea"]');
        expect(ultimo()).toMatchObject({ tipo: 'apri', dati: { seduta_id: 2, presidente: { anagrafica_id: 13 }, segretario: { nome: 'L\'amministratore' }, scelta: 'apri',
            motivo: 'Bianchi Anna è presente e non si oppone.' } });
    });

    test('prima dell\'ora della convocazione il pulsante dice l\'ora e al clic chiede conferma: «Apri lo stesso» apre, «Aspetta» no (M4)', async () => {
        vi.setSystemTime(new Date('2026-12-15T16:36:00Z'));
        await monta(salaDaAprire({ controllo: null }));
        await scegli('presidente', 'Romano Ida');
        const apri = () => document.body.querySelector('[data-testid="apri-assemblea"]') as HTMLButtonElement;
        expect(apri().textContent).toContain('la convocazione dice alle 18:30');
        expect(apri().disabled).toBe(false);
        const prima = gesti().length;
        await clic('[data-testid="apri-assemblea"]');
        expect(testo('[data-testid="conferma-apertura-presto"]')).toContain('Mancano 54 minuti all\'ora della convocazione (18:30): chi arriva all\'ora giusta potrebbe impugnare. Apri lo stesso?');
        expect(gesti().length).toBe(prima);
        await clic('[data-testid="aspetta"]');
        expect(document.body.querySelector('[data-testid="conferma-apertura-presto"]')).toBeNull();
        expect(gesti().length).toBe(prima);
        await clic('[data-testid="apri-assemblea"]');
        await clic('[data-testid="apri-lo-stesso"]');
        expect(ultimo()).toMatchObject({ tipo: 'apri', dati: { seduta_id: 2 } });
    });

    test('dopo l\'ora della convocazione il pulsante è quello di sempre e apre subito', async () => {
        await monta(salaDaAprire({ controllo: null }));
        await scegli('presidente', 'Romano Ida');
        expect((document.body.querySelector('[data-testid="apri-assemblea"]') as HTMLButtonElement).textContent).not.toContain('la convocazione dice');
        await clic('[data-testid="apri-assemblea"]');
        expect(document.body.querySelector('[data-testid="conferma-apertura-presto"]')).toBeNull();
        expect(ultimo()).toMatchObject({ tipo: 'apri' });
    });

    test('«Rinvia l\'assemblea» apre la finestra del rinvio, con il motivo e le date nuove', async () => {
        await monta(salaDaAprire());
        await clic('[data-testid="scelta-rinvia"]');
        expect(document.body.querySelector('[data-testid="finestra-rinvia"]')).not.toBeNull();
        await scrivi('[data-testid="motivo-rinvia"]', 'Bianchi Anna non è stata convocata.');
        await scegliGiorno('[data-testid="rinvia-prima-giorno"]', '2027-01-11');
        await scrivi('[data-testid="rinvia-prima-ora"]', '08:00');
        await scegliGiorno('[data-testid="rinvia-seconda-giorno"]', '2027-01-12');
        await scrivi('[data-testid="rinvia-seconda-ora"]', '18:30');
        await clic('[data-testid="conferma-rinvia"]');
        expect(ultimo()).toMatchObject({ tipo: 'rinvia', dati: { motivo: 'Bianchi Anna non è stata convocata.', sedute: [
            { convocazione: 'prima', numero: 1, fissata_il: '2027-01-11 08:00' }, { convocazione: 'seconda', numero: 1, fissata_il: '2027-01-12 18:30' }] } });
    });

    test('«Segna deserta la prima convocazione» dall\'ora della prima, con la sua finestra', async () => {
        vi.setSystemTime(new Date('2026-12-14T07:40:00Z'));
        const sala = salaDaAprire({ ora_del_server: '2026-12-14T07:40:00Z', sedute: salaDaAprire().sedute.map((s) => ({ ...s, esito: null })),
            da_aprire: { seduta_id: 1, convocazione: 'prima', numero: 1, si_apre: true, perche: null }, fotografia: { ...salaDaAprire().fotografia!, convocazione: 'prima' } });
        await monta(sala);
        expect(testo('[data-testid="barra-servono"]')).toContain('In prima convocazione servono 14 condòmini e 666,67 millesimi');
        await clic('[data-testid="segna-deserta"]');
        expect(testo('[data-testid="finestra-deserta"]')).toContain('Si prosegue in seconda: martedì 15/12/2026 alle 18:30');
        await clic('[data-testid="conferma-deserta"]');
        expect(ultimo()).toMatchObject({ tipo: 'deserta', dati: { seduta_id: 1 }, segnato_il: '2026-12-14T07:40:00.000Z' });
    });

    test('prima del giorno la sala lo dice e non si apre', async () => {
        vi.setSystemTime(new Date('2026-12-14T18:45:00Z'));
        await monta(salaDaAprire({ da_aprire: { seduta_id: 2, convocazione: 'seconda', numero: 1, si_apre: false, perche: 'La sala si apre il giorno della seduta, il 15/12/2026.' },
            controllo: null }));
        expect(testo('[data-testid="apertura"]')).toContain('La sala si apre il giorno della seduta, il 15/12/2026.');
        expect(document.body.querySelector('[data-testid="apri-assemblea"]')).toBeNull();
    });
});

describe('le presenze', () => {
    test('«Entra» è un gesto con la versione della presenza; la riga dice «Su questo computer» finché il server non risponde', async () => {
        await monta();
        await clic('[data-testid="mostra-tutti"]');
        await clic('[data-testa="p-15"] [data-testid="entra"]');
        expect(ultimo()).toMatchObject({ tipo: 'entra', dati: { seduta_id: 2, persona: { anagrafica_id: 15 }, come: 'in_sala' }, base: 0 });
        await clic('[data-testa="p-1"] [data-testid="esce"]');
        expect(ultimo()).toMatchObject({ tipo: 'esce', dati: { seduta_id: 2, persona: { anagrafica_id: 1 } }, base: 1 });
        // Il server ha risposto: lo stato si rilegge con una richiesta JSON, mai con una visita (che con la sessione scaduta aprirebbe l'accesso).
        expect(rete.letture).toContain('/sala/stato');
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    test('chi è entrato dopo un voto lo dice la sua riga: per quel punto non conta', async () => {
        const sala = salaAperta();
        const v = sala.punti[0].votazioni[0];
        sala.punti[0] = { ...sala.punti[0], votazioni: [{ ...v, intervenuti: { ...v.intervenuti, teste: v.intervenuti.teste.filter((t) => t.chiave !== 'p-14') } }] };
        await monta(sala);
        expect(testo('[data-testa="p-14"]')).toContain('entrato alle 19:20, dopo il voto del punto 1 (19:05): per il punto 1 non conta');
        expect(testo('[data-testa="p-1"]')).not.toContain('dopo il voto');
    });

    test('«In collegamento» senza la videoconferenza nell\'avviso chiede il motivo, senza dati sulla salute', async () => {
        await monta();
        await clic('[data-testid="mostra-tutti"]');
        await clic('[data-testa="p-15"] [data-testid="in-collegamento"]');
        expect(testo('[data-testid="finestra-entrata"]')).toContain('Nel motivo niente dati sulla salute');
        const conferma = () => document.body.querySelector('[data-testid="conferma-entrata"]') as HTMLButtonElement;
        expect(conferma().disabled).toBe(true);
        await scrivi('[data-testid="motivo-collegamento"]', 'Fuori città per lavoro; nessuno si oppone.');
        await clic('[data-testid="conferma-entrata"]');
        expect(ultimo()).toMatchObject({ tipo: 'entra', dati: { persona: { anagrafica_id: 15 }, come: 'in_collegamento', motivo: 'Fuori città per lavoro; nessuno si oppone.' } });
    });

    test('in videoconferenza «In collegamento» è un gesto solo; il collegamento caduto è un\'uscita', async () => {
        const sala = salaAperta();
        await monta({ ...sala, assemblea: { ...sala.assemblea, modalita: 'mista', piattaforma: 'Meet' } });
        await clic('[data-testid="mostra-tutti"]');
        await clic('[data-testa="p-15"] [data-testid="in-collegamento"]');
        expect(ultimo()).toMatchObject({ tipo: 'entra', dati: { come: 'in_collegamento' } });
        expect(document.body.querySelector('[data-testid="finestra-entrata"]')).toBeNull();
    });

    test('la delega in sala: il conto del quinto mentre la segni; oltre il quinto, con l\'impostazione di serie, «Non si accetta»', async () => {
        await monta();
        await clic('[data-testid="mostra-tutti"]');
        await clic('[data-testa="p-15"] [data-testid="delega"]');
        const finestra = '[data-testid="finestra-delega"]';
        expect(testo(finestra)).toContain('Greco Silvio');
        await scegli('delegato', 'Bianchi Giorgio');
        expect(testo('[data-testid="conto-quinto"]')).toContain('4 condòmini su 5 · 155,70 su 200,00');
        expect(document.body.querySelector('[data-testid="oltre-quinto"]')).toBeNull();
        await clic('[data-testid="registra-delega"]');
        expect(ultimo()).toMatchObject({ tipo: 'delega', dati: { delegante_anagrafica_id: 15, delegato: { anagrafica_id: 2 } } });
    });

    test('la delega a chi non è un condòmino si scrive con il nome; mai all\'amministratore', async () => {
        await monta();
        await clic('[data-testid="mostra-tutti"]');
        await clic('[data-testa="p-15"] [data-testid="delega"]');
        await scegli('delegato', 'Un\'altra persona');
        await scrivi('[data-testid="delegato-nome"]', 'Ricci Stefano');
        expect(testo('[data-testid="finestra-delega"]')).toContain('L\'amministratore non può ricevere deleghe');
        await clic('[data-testid="registra-delega"]');
        expect(ultimo()).toMatchObject({ tipo: 'delega', dati: { delegante_anagrafica_id: 15, delegato: { nome: 'Ricci Stefano' } } });
    });
});

describe('il voto di un punto', () => {
    test('prima di «Si vota» il presidente può far votare l\'usufruttuario sul punto straordinario, con il perché', async () => {
        await monta();
        await clic('[data-testid="punto-4"]');
        expect(testo('[data-testid="voto-del-punto"]')).toContain('Neri Paolo');
        await clic('[data-testid="vota-usufruttuario"]');
        expect(testo('[data-testid="finestra-usufruttuario"]')).toContain('Di serie, sui punti straordinari vota il nudo proprietario (art. 67 c. 7 disp. att.)');
        await scegli('perche-usufruttuario', 'Si avvale dell\'art. 1006 c.c.');
        await clic('[data-testid="conferma-usufruttuario"]');
        expect(testo('[data-testid="voto-del-punto"]')).toContain('Scelta del presidente');
        await clic('[data-testid="si-vota"]');
        expect(ultimo()).toMatchObject({ tipo: 'si_vota', dati: { seduta_id: 2, punto_id: 44, usufruttuari: [{ immobile_id: 109, perche: 'art_1006' }] } });
    });

    test('«Tutti i presenti favorevoli», poi i contrari: ogni voto con la sua versione; la conferma con la versione della votazione', async () => {
        const sala = salaAperta();
        sala.punti[1] = { ...sala.punti[1], votazioni: [votazioneDelPortone()] };
        // Il server risponde con le versioni: la votazione passa alla 4 con «Tutti i presenti favorevoli», alla 5 con il voto cambiato.
        rete.piu = (g) => (g.tipo === 'tutti_favorevoli' ? { versione: 4, votazione_id: 404 } : g.tipo === 'voto' ? { versione: 2, versione_votazione: 5, votazione_id: 404 } : {});
        await monta(sala);
        await clic('[data-testid="punto-4"]');
        await clic('[data-testid="tutti-favorevoli"]');
        expect(ultimo()).toMatchObject({ tipo: 'tutti_favorevoli', dati: { votazione_id: 404 } });
        await clic('[data-voto-testa="p-13"] [data-testid="contrario"]');
        // Il voto di p-13 l'ha già scritto «Tutti i presenti favorevoli» (versione 1, come sul server): arrivato quel gesto, la sala lo tiene a video
        // finché la rilettura lo porta, e il voto cambiato parte da lì (con la base 0 il server direbbe «due versioni»).
        expect(ultimo()).toMatchObject({ tipo: 'voto', dati: { votazione_id: 404, testa: 'p-13', voto: 'contrario' }, base: 1 });
        // Undici favorevoli su dodici: approvata. La conferma parte dalla versione che il server ha risposto (5), non da quella della pagina (3).
        expect(testo('[data-testid="conferma-voto"]')).toBe('Conferma: approvata');
        await clic('[data-testid="conferma-voto"]');
        expect(ultimo()).toMatchObject({ tipo: 'conferma', dati: { votazione_id: 404, esito: 'approvata' }, base: 5 });
    });

    test('un esito diverso da quello proposto chiede il motivo', async () => {
        const sala = salaAperta();
        sala.punti[1] = { ...sala.punti[1], votazioni: [votazioneDelPortone()] };
        await monta(sala);
        await clic('[data-testid="punto-4"]');
        await clic('[data-testid="altro-esito"]');
        expect(document.body.querySelector('[data-testid="finestra-conferma"]')).not.toBeNull();
        await scegli('esito-conferma', 'Approvata');
        const conferma = () => document.body.querySelector('[data-testid="conferma-esito"]') as HTMLButtonElement;
        expect(conferma().disabled).toBe(true);
        await scrivi('[data-testid="motivo-conferma"]', 'Il presidente legge i voti in modo diverso.');
        await clic('[data-testid="conferma-esito"]');
        expect(ultimo()).toMatchObject({ tipo: 'conferma', dati: { votazione_id: 404, esito: 'approvata', motivo: 'Il presidente legge i voti in modo diverso.' } });
    });

    test('il conflitto d\'interessi si segna con la nota; senza numero legale l\'avviso compare accanto alla conferma, e il presidente scrive la sua nota', async () => {
        const sala = salaAperta({ presenze: salaAperta().presenze.slice(0, 2), deleghe: [] });
        sala.punti[1] = { ...sala.punti[1], votazioni: [votazioneDelPortone()] };
        await monta(sala);
        await clic('[data-testid="punto-4"]');
        expect(testo('[data-testid="avviso-numero-legale"]')).toContain('L\'assemblea non risulta costituita');
        expect(testo('[data-testid="avviso-numero-legale"]')).toContain('decide il presidente');
        await clic('[data-voto-testa="p-1"] [data-testid="in-conflitto"]');
        await scrivi('[data-testid="nota-conflitto"]', 'Titolare di uno dei preventivi');
        await clic('[data-testid="conferma-conflitto"]');
        expect(ultimo()).toMatchObject({ tipo: 'conflitto', dati: { votazione_id: 404, testa: 'p-1', in_conflitto: true, nota: 'Titolare di uno dei preventivi' } });
        await clic('[data-testid="nota-voto"]');
        await scrivi('[data-testid="testo-nota"]', 'Si vota con i presenti: lo decide il presidente.');
        await clic('[data-testid="salva-nota"]');
        expect(ultimo()).toMatchObject({ tipo: 'nota', dati: { votazione_id: 404, su: 'voto', testo: 'Si vota con i presenti: lo decide il presidente.' } });
        await clic('[data-testid="nota-non-votato"]');
        await scrivi('[data-testid="testo-nota"]', 'Due persone sono uscite un momento.');
        await clic('[data-testid="salva-nota"]');
        expect(ultimo()).toMatchObject({ tipo: 'nota', dati: { votazione_id: 404, su: 'non_votato', testo: 'Due persone sono uscite un momento.' } });
    });

    test('la dichiarazione a verbale', async () => {
        const sala = salaAperta();
        sala.punti[1] = { ...sala.punti[1], votazioni: [votazioneDelPortone()] };
        await monta(sala);
        await clic('[data-testid="punto-4"]');
        await clic('[data-testid="dichiarazione"]');
        await scegli('chi-dichiara', 'Romano Ida');
        await scrivi('[data-testid="testo-dichiarazione"]', 'Contraria perché i preventivi sono arrivati tardi.');
        await clic('[data-testid="salva-dichiarazione"]');
        expect(ultimo()).toMatchObject({ tipo: 'dichiarazione', dati: { seduta_id: 2, punto_id: 44, anagrafica_id: 13,
            testo: 'Contraria perché i preventivi sono arrivati tardi.' } });
    });

    test('senza rete il punto si vota tutto: «Si vota», i voti, la nota e la conferma restano su questo computer e partono quando la rete torna', async () => {
        // I numeri dei gesti e del dispositivo fissi, per il file in comune con il server (`CateneDellaSalaInteraTest`, catena 1).
        let n = 0;
        vi.spyOn(globalThis.crypto, 'randomUUID').mockImplementation(() => `5e7a0000-0000-4000-8000-${String(++n).padStart(12, '0')}` as `${string}-${string}-${string}-${string}-${string}`);
        const axios = (await import('axios')).default as unknown as { post: ReturnType<typeof vi.fn> };
        const conLaRete = axios.post.getMockImplementation()!;
        axios.post.mockImplementation(() => Promise.reject(new Error('Network Error')));
        await monta();
        await clic('[data-testid="punto-4"]');
        await clic('[data-testid="si-vota"]');
        expect(testo('[data-testid="votato-senza-rete"]')).toContain('Votato senza rete');
        expect(testo('[data-testid="voto-del-punto"]')).toContain('Su questo computer');
        await clic('[data-testid="tutti-favorevoli"]');
        await clic('[data-voto-testa="p-13"] [data-testid="contrario"]');
        await clic('[data-testid="nota-voto"]');
        await scrivi('[data-testid="testo-nota"]', 'Votato senza rete, il Wi-Fi è caduto alle 19:38.');
        await clic('[data-testid="salva-nota"]');
        await clic('[data-testid="altro-esito"]');
        await scegli('esito-conferma', 'Approvata con modifiche');
        await clic('[data-testid="conferma-esito"]');
        expect(testo('[data-testid="striscia-della-rete"]')).toContain('Senza rete: 5 gesti salvati');
        expect(gesti()).toEqual([]);

        rete.lotti = [];
        axios.post.mockImplementation(conLaRete);
        window.dispatchEvent(new Event('online'));
        await assesta();

        const lotto = rete.lotti.flatMap((l) => l.gesti);
        expect(lotto.map((g) => g.tipo)).toEqual(['si_vota', 'tutti_favorevoli', 'voto', 'nota', 'conferma']);
        const siVota = lotto[0].gesto;
        for (const g of lotto.slice(1)) {
            expect(g.dati).toMatchObject({ seduta_id: 2, punto_id: 44, numero: 1, aperta_dal_gesto: siVota });
            expect(g.dati).not.toHaveProperty('votazione_id');
        }
        expect(lotto.find((g) => g.tipo === 'conferma')?.base).toBeNull();
        // Il lotto è quello che il server riceve nella catena 1 (i numeri della sala al posto dei segnaposti).
        const segnaposti = JSON.parse(JSON.stringify(rete.lotti[0]).replaceAll('"seduta_id":2', '"seduta_id":"{seduta}"').replaceAll('"punto_id":44', '"punto_id":"{punto}"')
            .replaceAll('"p-13"', '"{testa_contrario}"'));
        expect(segnaposti).toEqual(votoSenzaRete.lotto);
    });

    test('il punto senza voto ha solo «Trattato»', async () => {
        await monta();
        await clic('[data-testid="punto-8"]');
        expect(document.body.querySelector('[data-testid="si-vota"]')).toBeNull();
        await clic('[data-testid="trattato"]');
        expect(ultimo()).toMatchObject({ tipo: 'trattato', dati: { seduta_id: 2, punto_id: 48 } });
    });

    test('il messaggio della costituzione dice il punto, e nella sala ce n\'è uno solo: il nuovo prende il posto del vecchio; due testi uguali mai (C52)', async () => {
        const nonCostituita = (n: number) => `Punto ${n}: l'assemblea non risulta costituita (2 su 26 · 140,00 su 1.000,00; servono 9 e 333,34). Decide il presidente.`;
        rete.piu = (g) => (g.tipo === 'si_vota' ? { messaggio: nonCostituita(Number((g as { dati?: { punto_id?: number } }).dati?.punto_id) - 40) } : {});
        await monta();
        await clic('[data-testid="punto-4"]');
        await clic('[data-testid="si-vota"]');
        await clic('[data-testid="punto-7"]');
        await clic('[data-testid="si-vota"]');
        const righe = () => Array.from(document.body.querySelectorAll('[data-testid="messaggi-della-sala"] li')).map((li) => li.textContent ?? '');
        expect(righe().filter((r) => r.includes('non risulta costituita'))).toHaveLength(1);
        expect(righe()[0]).toContain(nonCostituita(7));

        // Lo stesso testo due volte (due gesti rifiutati per la stessa ragione): un messaggio solo.
        rete.piu = null;
        rete.esito = 'rifiutato';
        rete.messaggio = 'Nessuna seduta è aperta: prima apri l\'assemblea.';
        await clic('[data-testid="mostra-tutti"]');
        await clic('[data-testa="p-15"] [data-testid="entra"]');
        await clic('[data-testa="p-15"] [data-testid="entra"]');
        expect(righe().filter((r) => r.includes('Nessuna seduta è aperta'))).toHaveLength(1);
    });

    test('il messaggio del server su un gesto rifiutato si legge nella sala', async () => {
        await monta();
        rete.esito = 'rifiutato';
        rete.messaggio = 'Il punto 8 è senza voto: segnalo «Trattato».';
        await clic('[data-testid="punto-8"]');
        await clic('[data-testid="trattato"]');
        expect(testo('[data-testid="messaggi-della-sala"]')).toContain('Il punto 8 è senza voto');
    });
});

describe('sospendere, chiudere, rinviare', () => {
    test('«Sospendi» propone solo le date di prosecuzione dell\'avviso; senza, rimanda a «Rinvia l\'assemblea»', async () => {
        const sala = salaAperta();
        await monta({ ...sala, sedute: [...sala.sedute, { id: 3, convocazione: 'seconda', numero: 2, giorno: '2026-12-17', ora: '18:30', aperta_il: null, chiusa_il: null,
            esito: null, presidente: null, segretario: null, dal_foglio: false }] });
        await clic('[data-testid="voce-sospendi"]');
        expect(testo('[data-testid="finestra-sospendi"]')).toContain('giovedì 17/12/2026 alle 18:30');
        await clic('[data-testid="conferma-sospendi"]');
        expect(ultimo()).toMatchObject({ tipo: 'sospendi', dati: { seduta_id: 2, prosegue_seduta_id: 3 } });
        w!.unmount();
        document.body.innerHTML = '';
        await monta();
        await clic('[data-testid="voce-sospendi"]');
        expect(testo('[data-testid="senza-prosecuzione"]')).toContain('L\'avviso non indica altre date');
        expect(document.body.querySelector('[data-testid="conferma-sospendi"]')).toBeNull();
    });

    test('«Chiudi l\'assemblea» elenca i punti senza esito; con un voto da confermare non si chiude', async () => {
        const sala = salaAperta();
        sala.punti[1] = { ...sala.punti[1], votazioni: [votazioneDelPortone()] };
        await monta(sala);
        await clic('[data-testid="voce-chiudi"]');
        // I punti da decidere sono quelli con un gesto (conferma, trattato); il non trattato si rinvia da sé con la chiusura, e la frase lo dice (rilievo C56).
        expect(testo('[data-testid="finestra-chiudi"]')).toContain('ne mancano 2');
        expect(testo('[data-testid="finestra-chiudi"]')).toContain('Il punto 7, non trattato, si rinvia con la chiusura.');
        expect(testo('[data-senza-esito="4"]')).toContain('Conferma');
        expect(testo('[data-senza-esito="7"]')).toContain('Rinviato');
        expect(testo('[data-senza-esito="8"]')).toContain('Trattato');
        expect((document.body.querySelector('[data-testid="conferma-chiudi"]') as HTMLButtonElement).disabled).toBe(true);
    });

    test('senza voti da confermare «Chiudi alle …» manda i non trattati «Rinviata»', async () => {
        await monta();
        await clic('[data-testid="voce-chiudi"]');
        await clic('[data-testid="conferma-chiudi"]');
        expect(ultimo()).toMatchObject({ tipo: 'chiudi', dati: { seduta_id: 2, non_trattati: 'rinviata' } });
    });
});

describe('dal foglio della sala, chi può, il telefono', () => {
    test('«Registra dal foglio della sala»: la scritta in testa, e ogni gesto porta l\'ora del foglio', async () => {
        inertia.url = '/admin/gestionale/3/assemblee/9/sala?foglio=1';
        vi.setSystemTime(new Date('2026-12-16T09:12:00Z'));
        await monta();
        expect(testo('[data-testid="foglio-banner"]')).toContain('Registri dal foglio della sala di martedì 15/12/2026');
        await scrivi('[data-testid="ora-del-foglio"]', '19:20');
        await clic('[data-testid="mostra-tutti"]');
        await clic('[data-testa="p-15"] [data-testid="entra"]');
        expect(ultimo()).toMatchObject({ tipo: 'entra', segnato_il: '2026-12-15T18:20:00.000Z' });
    });

    test('chi vede soltanto non ha i pulsanti dei gesti', async () => {
        await monta(salaAperta({ puo_gestire: false }));
        expect(document.body.querySelector('[data-testid="entra"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="esce"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="voce-chiudi"]')).toBeNull();
    });

    test('al telefono due schede, «Presenze» e «Punto N di M», e le presenze in card', async () => {
        await monta();
        expect(testo('[data-testid="scheda-presenze"]')).toBe('Presenze');
        expect(testo('[data-testid="scheda-punto"]')).toMatch(/^Punto \d di 4$/);
        expect(document.body.querySelector('[data-testid="presenze-card"]')?.className).toContain('sm:hidden');
        expect(document.body.querySelector('[data-testid="presenze-tabella"]')?.className.split(' ')).toEqual(expect.arrayContaining(['hidden', 'sm:block']));
    });
});

describe('la testa della sala: «Vai a» e le tre card della guida', () => {
    test('«L\'assemblea», «Il verbale» e «Il registro dei verbali» stanno in un menu «Vai a», con il nome e la riga grigia; il verbale porta da=sala', async () => {
        await monta(salaChiusa());
        expect(testo('[data-testid="vai-a"]')).toContain('Vai a');
        const voci = [...document.body.querySelectorAll('[data-testid="voce-vai-a"]')].map((x) => [...x.children].map((c) => c.textContent?.trim()).join(' | '));
        expect(voci).toEqual(["L'assemblea | I sei passi", 'Il verbale | Il testo e la registrazione', 'Il registro dei verbali | I verbali di Via Roma 12']);
        expect(document.body.querySelector('[data-testid="vai-al-verbale"]')?.getAttribute('href')).toBe('/verbale?da=sala');
        expect(document.body.querySelector('[data-testid="vai-al-registro"]')?.getAttribute('href')).toBe('/registro?da_assemblea=9');
        expect(document.body.querySelector('[data-testid="torna-all-assemblea"]')?.getAttribute('href')).toBe('/assemblea');
    });

    test('le tre card corte: «Le presenze», «I voti», «Senza rete», con due frasi ciascuna', async () => {
        await monta();
        const card = [...document.body.querySelectorAll('[data-testid="card-della-guida"] li')].map((x) => (x.textContent ?? '').trim());
        expect(card).toEqual([
            'Le presenze: Chi entra, esce, delega o si collega, con l\'ora. La sala conta da sola.',
            'I voti: Ogni punto con la sua maggioranza: la sala dice se basta. L\'esito lo conferma il presidente.',
            'Senza rete: Questo computer tiene la sala finché la rete torna. Poi si manda da sola.',
        ]);
    });
});

describe('la sala chiusa si legge com\'era (decisione 660)', () => {
    test('la barra dice la costituzione vera, non zero: 11 su 26, 512,57 millesimi, costituita', async () => {
        await monta(salaChiusa());
        expect(testo('[data-testid="barra-stato"]')).toBe('Chiusa alle 18:42 · costituita in seconda convocazione');
        expect(testo('[data-testid="barra-teste"]')).toBe('11 su 26 condòmini in sala');
        expect(testo('[data-testid="barra-valore"]')).toBe('512,57 millesimi su 1.000');
        expect(testo('[data-testid="barra-servono"]')).toBe('Chiusa alle 18:42 · costituita in seconda convocazione con 11 su 26 e 512,57 millesimi.');
    });

    test('la frase resta sopra, piccola, e sotto ci sono le presenze con le ore di entrata e di uscita e come si partecipa', async () => {
        await monta(salaChiusa());
        expect(testo('[data-testid="motivo-sala-chiusa"]')).toBe('L\'assemblea è chiusa: la sala resta in sola lettura.');
        const righe = [...document.body.querySelectorAll('[data-testid="lettura-riga"]')].map((r) => (r.textContent ?? '').replace(/\s+/g, ' ').trim());
        expect(righe).toHaveLength(8);
        expect(righe[0]).toContain('Bianchi Giorgio');
        expect(righe[0]).toContain('In sala');
        expect(righe[0]).toContain('per delega di Costa Teresa, Fontana Laura, De Luca Gianni');
        expect(righe[0]).toContain('18:31');
        expect(righe[0]).toContain('18:42');
        expect(testo('[data-testid="lettura-non-presenti"]')).toContain('Non c\'erano:');
    });

    test('un collegamento si dice «In collegamento»', async () => {
        const s = salaChiusa();
        s.ultima_seduta!.presenze[0] = { ...s.ultima_seduta!.presenze[0], come: 'in_collegamento' };
        await monta(s);
        expect(testo('[data-testid="lettura-riga"]')).toContain('In collegamento');
    });

    test('i punti hanno l\'esito e i conti; il punto senza voto è «Trattato»', async () => {
        await monta(salaChiusa());
        expect(testo('[data-testid="lettura-punto-1"] [data-testid="lettura-esito"]')).toBe('Approvata');
        expect(testo('[data-testid="lettura-punto-1"] [data-testid="lettura-conti"]')).toContain('1 testa · 98,30 millesimi');
        expect(testo('[data-testid="lettura-punto-1"] [data-testid="lettura-conti"]')).toContain('0 teste · 0,00 millesimi');
        expect(testo('[data-testid="lettura-punto-8"] [data-testid="lettura-esito"]')).toBe('Trattato');
        expect(testo('[data-testid="lettura-punto-8"]')).toContain('Trattato, senza voto.');
    });

    // La prova a video del 22/11/2026, notte (decisione 660): la sala chiusa non mostrava la discussione, le note e le dichiarazioni, e chi la guardava
    // credeva che fossero perse. Il verbale le ha tutte: la sala chiusa le dice sotto l'esito e i conti di ogni punto, in sola lettura.
    test('ogni punto dice anche i testi: la discussione scritta in sala, le note del presidente, il motivo dell\'esito, le dichiarazioni a verbale con chi', async () => {
        const s = salaChiusa();
        const v = s.punti[0].votazioni[0];
        s.punti[0] = { ...s.punti[0], votazioni: [{ ...v, nota: 'Si vota con i presenti, lo decide il presidente.', nota_non_votato: 'Due persone erano uscite un momento.',
            motivo: 'Approvato con la correzione della voce delle pulizie detta in sala.' }] };
        s.ultima_seduta = { ...s.ultima_seduta!, discussioni: { 41: { parte: 'punto:41', documento: documento([paragrafo('L\'amministratore illustra il rendiconto, voce per voce.')]),
            revisione: 3, impronta: 'x' } } };
        s.dichiarazioni = [{ id: 31, seduta_id: 2, punto_id: 41, anagrafica_id: 13, nome: 'Romano Ida', testo: 'Chiedo il dettaglio delle spese di pulizia.' }];
        await monta(s);
        const uno = '[data-testid="lettura-punto-1"]';
        expect(testo(`${uno} [data-testid="lettura-discussione"] p`)).toBe('La discussione');
        expect(testo(`${uno} [data-testid="lettura-discussione"] [data-testid="testo-del-verbale"]`)).toBe('L\'amministratore illustra il rendiconto, voce per voce.');
        expect(testo(`${uno} [data-testid="lettura-nota"]`)).toBe('Nota del presidente sul voto: Si vota con i presenti, lo decide il presidente.');
        expect(testo(`${uno} [data-testid="lettura-nota-non-votato"]`)).toBe('Su chi non ha votato: Due persone erano uscite un momento.');
        expect(testo(`${uno} [data-testid="lettura-motivo"]`)).toBe('Il motivo dell\'esito: Approvato con la correzione della voce delle pulizie detta in sala.');
        expect(testo(`${uno} [data-testid="lettura-dichiarazione"]`)).toBe('Dichiarazione a verbale · Romano Ida: «Chiedo il dettaglio delle spese di pulizia.»');
        // Solo lettura: nessun pulsante, nessun campo da scrivere.
        expect(document.body.querySelectorAll('[data-testid="sala-in-sola-lettura"] button, [data-testid="sala-in-sola-lettura"] [contenteditable]')).toHaveLength(0);
        // Il punto senza testi non ha righe vuote.
        expect(document.body.querySelector('[data-testid="lettura-punto-8"] [data-testid="lettura-discussione"]')).toBeNull();
    });

    test('chiusa su questo computer, prima che il server la rilegga: la discussione è quella che la sala aveva', async () => {
        const s = salaChiusa();
        s.ultima_seduta = null;
        s.presenze = salaAperta().presenze.map((p) => ({ ...p, uscita_il: '2026-12-15T17:42:50Z' }));
        s.verbale = { seduta_id: 2, id: 7, stato: 'in_sala', discussioni: { 41: { parte: 'punto:41', documento: documento([paragrafo('Si discute del rendiconto.')]), revisione: 1,
            impronta: 'x' } } };
        await monta(s);
        expect(testo('[data-testid="lettura-punto-1"] [data-testid="lettura-discussione"] [data-testid="testo-del-verbale"]')).toBe('Si discute del rendiconto.');
    });

    test('senza nessun gesto: né «Entra», «Esce», «Si vota» o «Conferma», né le linguette; il menu «Stampe» ha solo la stampa del prospetto', async () => {
        await monta(salaChiusa());
        for (const sel of ['entra', 'in-collegamento', 'esce', 'delega', 'si-vota', 'trattato', 'conferma', 'scheda-presenze', 'punti', 'voce-foglio', 'voce-sospendi', 'voce-chiudi',
            'voce-rinvia', 'voce-deserta', 'voce-foglio-vuoto', 'voce-foglio-compilato']) {
            expect(document.body.querySelector(`[data-testid="${sel}"]`), sel).toBeNull();
        }
        expect(document.body.querySelectorAll('[data-testid="sala-in-sola-lettura"] button')).toHaveLength(0);
        // A sala chiusa il menu ha solo la stampa: si chiama «Stampe», non tre puntini.
        expect(testo('[data-testid="menu-sala"]')).toBe('Stampe');
        expect(testo('[data-testid="voce-prospetto"]')).toBe('Stampa il prospetto');
    });

    test('«Stampa il prospetto» apre il PDF «Le presenze e i voti» in una scheda', async () => {
        const apri = vi.fn();
        vi.stubGlobal('open', apri);
        await monta(salaChiusa());
        await clic('[data-testid="voce-prospetto"]');
        expect(apri).toHaveBeenCalledWith('/sala/prospetto', '_blank', 'noopener');
        vi.unstubAllGlobals();
    });

    test('a sala aperta la lettura non c\'è e il prospetto sta nel menu insieme agli altri gesti', async () => {
        await monta(salaAperta({ rotte: { ...salaAperta().rotte, prospetto: '/sala/prospetto' } }));
        expect(document.body.querySelector('[data-testid="sala-in-sola-lettura"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="voce-chiudi"]')).not.toBeNull();
        expect(document.body.querySelector('[data-testid="voce-prospetto"]')).not.toBeNull();
    });

    test('al telefono le presenze sono schede, dal tablet in su una tabella', async () => {
        await monta(salaChiusa());
        expect(document.body.querySelector('[data-testid="lettura-presenze-tabella"]')?.className.split(' ')).toEqual(expect.arrayContaining(['hidden', 'sm:block']));
        expect(document.body.querySelector('[data-testid="lettura-presenze-schede"]')?.className.split(' ')).toContain('sm:hidden');
        expect(document.body.querySelectorAll('[data-testid="lettura-scheda"]')).toHaveLength(8);
    });
});

describe('le regole delle pagine', () => {
    const cartelle = ['resources/js/components/assemblee/sala', 'resources/js/pages/gestionale/assemblee'];
    const sorgenti = cartelle.flatMap((c) => readdirSync(resolve(process.cwd(), c)).filter((f) => f.endsWith('.vue') && (c.endsWith('sala') || f === 'Sala.vue'))
        .map((f) => [join(c, f), readFileSync(resolve(process.cwd(), c, f), 'utf8')] as const));

    test('ci sono i pezzi della sala', () => {
        expect(sorgenti.length).toBeGreaterThan(8);
    });

    test('niente v-html, niente scritte sotto i 12 pixel, badge rounded-md, niente pillole', () => {
        for (const [file, s] of sorgenti) {
            expect(s, file).not.toMatch(/v-html/);
            expect(s, file).not.toMatch(/text-\[(?:[0-9]|1[01])px\]/);
            expect(s, file).not.toMatch(/rounded-full[^"]*px-2/);
        }
    });
});

describe('la tenuta senza rete nella sala', () => {
    /** La prima convocazione aperta lunedì 14/12 alle 8:05, con tre presenti, senza voti. */
    function primaAperta(): SalaDellAssemblea {
        const s = salaAperta();
        return {
            ...s,
            sedute: [{ ...s.sedute[0], esito: null, aperta_il: '2026-12-14T07:05:00Z', presidente: { anagrafica_id: 13, nome: 'Romano Ida' },
                segretario: { anagrafica_id: null, nome: 'L\'amministratore' } }, { ...s.sedute[1], aperta_il: null, presidente: null, segretario: null }],
            seduta_id: 1,
            presenze: s.presenze.slice(0, 3),
            punti: s.punti.map((p) => ({ ...p, votazioni: [], esito: null })),
        };
    }

    test('aperta la prima e contati i presenti, il menu ha «Segna deserta la prima convocazione»: le presenze escono all\'ora, e la sala torna da aprire', async () => {
        await monta(primaAperta());
        await clic('[data-testid="voce-deserta"]');
        expect(testo('[data-testid="finestra-deserta"]')).toContain('Chi è in sala esce alle');
        await scrivi('[data-testid="ora-deserta"]', '08:40');
        await clic('[data-testid="conferma-deserta"]');
        expect(testo('[data-testid="apertura"]')).not.toContain('Segna deserta la prima convocazione');
        expect(testo('[data-testid="striscia-della-rete"]')).toContain('In linea');
        expect(ultimo()).toMatchObject({ tipo: 'deserta', dati: { seduta_id: 1 }, segnato_il: '2026-12-14T07:40:00.000Z' });
    });

    test('dopo il primo voto della prima, o della seconda, «Segna deserta» non c\'è nel menu', async () => {
        const s = primaAperta();
        s.punti[1] = { ...s.punti[1], votazioni: [votazioneDelPortone({ seduta_id: 1 })] };
        await monta(s);
        expect(document.body.querySelector('[data-testid="voce-deserta"]')).toBeNull();
        w?.unmount();
        await monta();
        expect(document.body.querySelector('[data-testid="voce-deserta"]')).toBeNull();
    });

    test('due versioni: la striscia dice «Scegli», la finestra dice da quale dispositivo e a che ora, «Tieni questa» è un gesto', async () => {
        await monta(salaAperta({ conflitti: [{ gesto: 'c0ffee00-0000-4000-8000-000000000001', tipo: 'voto', dispositivo: 'computer di Andrea Costa#a1b2c3d4',
            questa: 'Colombo Rita: Contrario', quella: 'Colombo Rita: Astenuto', su: 'votazione:401', segnato_il: '2026-12-15T18:42:00Z',
            quella_dispositivo: 'tablet di Marco Galli#00aa11bb', quella_segnato_il: '2026-12-15T18:44:00Z' }] }));
        expect(testo('[data-innesto="rete"]')).toContain('Due versioni');
        await clic('[data-testid="striscia-scegli"]');
        expect(testo('[data-testid="versione-quella"]')).toContain('Dal dispositivo «tablet di Marco Galli»');
        expect(testo('[data-testid="versione-quella"]')).toContain('segnato alle 19:44');
        await clic('[data-testid="tieni-quella"]');
        expect(ultimo()).toMatchObject({ tipo: 'scegli', dati: { gesto: 'c0ffee00-0000-4000-8000-000000000001', scelta: 'quella' } });
    });

    test('un browser che non tiene il lavoro (sembra una finestra privata): l\'avviso prima di aprire, sopra «Apri l\'assemblea», e la striscia lo ricorda', async () => {
        delete (globalThis as Record<string, unknown>).indexedDB;
        await monta(salaDaAprire());
        const innesto = document.body.querySelector('[data-innesto="finestra-privata"]');
        expect(innesto).not.toBeNull();
        expect(testo('[data-testid="avviso-finestra-privata"]')).toContain('Sembra una finestra privata');
        await clic('[data-testid="apri-lo-stesso"]');
        expect(document.body.querySelector('[data-testid="avviso-finestra-privata"]')).toBeNull();
        expect(testo('[data-testid="striscia-della-rete"]')).toContain('non tiene');
    });

    test('su un indirizzo senza https la sala lo dice prima di aprire: non si riapre senza rete, il lavoro resta sul computer, il foglio è il ripiego', async () => {
        vi.stubGlobal('isSecureContext', false);
        try {
            await monta(salaDaAprire());
            expect(testo('[data-innesto="finestra-privata"] [data-testid="nota-indirizzo"]')).toContain('la sala non si riapre senza rete');
            expect(testo('[data-testid="nota-indirizzo"]')).toContain('resta su questo computer');
            expect(testo('[data-testid="nota-indirizzo"]')).toContain('foglio della sala');
        } finally {
            vi.unstubAllGlobals();
        }
    });
});

describe('il foglio della sala con quello che c\'è', () => {
    test('a sala aperta il menu ⋯ ha «Stampa il foglio» (vuoto) e «Stampa il foglio con quello che c\'è», ognuno nella sua scheda', async () => {
        const apri = vi.fn();
        vi.stubGlobal('open', apri);
        try {
            const s = salaAperta();
            await monta({ ...s, rotte: { ...s.rotte, foglio_compilato: '/sala/foglio?compilato=1' } });
            await clic('[data-testid="voce-foglio-vuoto"]');
            await clic('[data-testid="voce-foglio-compilato"]');
            expect(apri.mock.calls).toEqual([['/sala/foglio', '_blank', 'noopener'], ['/sala/foglio?compilato=1', '_blank', 'noopener']]);
        } finally {
            vi.unstubAllGlobals();
        }
    });

    test('«Registra dal foglio della sala» dopo un foglio stampato a sala aperta: si registra solo ciò che è scritto a mano', async () => {
        inertia.url = '/admin/gestionale/3/assemblee/9/sala?foglio=1';
        await monta(salaAperta({ foglio: { stampato_il: '2026-12-15T19:10:00Z', da_ristampare: false } }));
        expect(testo('[data-testid="foglio-banner"]')).toContain('Il foglio stampato alle 20:10 aveva già i segni fino a quell\'ora: registra solo ciò che è scritto a mano.');
    });
});

describe('niente del lavoro senza rete si perde, e i conti giusti', () => {
    /** La sala alle 17:00 del 15/12, con la rete: la prima di lunedì non ancora segnata deserta, il controllo pulito. */
    function primaDaSegnare(): SalaDellAssemblea {
        const s = salaDaAprire();
        return { ...s, ora_del_server: '2026-12-15T16:00:00Z', fotografia: { ...s.fotografia!, convocazione: 'prima' },
            sedute: s.sedute.map((x) => (x.id === 1 ? { ...x, esito: null } : x)),
            da_aprire: { seduta_id: 1, convocazione: 'prima', numero: 1, si_apre: true, perche: null },
            controllo: { problemi: [], da_guardare: [], serve_la_scelta: false, frase: null } };
    }

    test('catena: senza rete «Segna deserta la prima» e «Apri» aprono la seconda; al ritorno della rete il lotto è quello del file in comune con il server', async () => {
        // I numeri dei gesti e del dispositivo fissi, per il file in comune con il server (`CateneNienteSiPerdeTest`, catena 1).
        let n = 0;
        vi.spyOn(globalThis.crypto, 'randomUUID').mockImplementation(() => `de5e0000-0000-4000-8000-${String(++n).padStart(12, '0')}` as `${string}-${string}-${string}-${string}-${string}`);
        vi.setSystemTime(new Date('2026-12-15T17:35:00Z'));
        const axios = (await import('axios')).default as unknown as { post: ReturnType<typeof vi.fn> };
        const conLaRete = axios.post.getMockImplementation()!;
        axios.post.mockImplementation(() => Promise.reject(new Error('Network Error')));
        await monta(primaDaSegnare());

        await clic('[data-testid="segna-deserta"]');
        await clic('[data-testid="conferma-deserta"]');
        // La sala vista da questo computer: da aprire adesso è la seconda, e il controllo lo rifà il server.
        expect(testo('[data-testid="apertura"]')).toContain('La seconda convocazione');
        expect(testo('[data-testid="controllo-da-rifare"]')).toContain('lo rifà il server quando torna la rete');
        vi.setSystemTime(new Date('2026-12-15T17:42:00Z'));
        await scegli('presidente', 'Romano Ida');
        await clic('[data-testid="apri-assemblea"]');
        await clic('[data-testid="mostra-tutti"]');
        vi.setSystemTime(new Date('2026-12-15T17:45:00Z'));
        await clic('[data-testa="p-1"] [data-testid="entra"]');
        vi.setSystemTime(new Date('2026-12-15T18:00:00Z'));
        await clic('[data-testid="punto-4"]');
        await clic('[data-testid="si-vota"]');
        await clic('[data-voto-testa="p-1"] [data-testid="contrario"]');
        expect(gesti()).toEqual([]);

        rete.lotti = [];
        axios.post.mockImplementation(conLaRete);
        window.dispatchEvent(new Event('online'));
        await assesta();

        const lotto = rete.lotti.flatMap((l) => l.gesti);
        expect(lotto.map((x) => x.tipo)).toEqual(['deserta', 'apri', 'entra', 'si_vota', 'voto']);
        expect(lotto[1].dati).toMatchObject({ seduta_id: 2, controllo_visto: null });
        const segnaposti = JSON.parse(JSON.stringify({ dispositivo: rete.lotti[0].dispositivo, gesti: lotto }).replaceAll('"seduta_id":1', '"seduta_id":"{prima}"')
            .replaceAll('"seduta_id":2', '"seduta_id":"{seconda}"').replaceAll('"anagrafica_id":13', '"anagrafica_id":"{presidente}"')
            .replaceAll('"anagrafica_id":1}', '"anagrafica_id":"{rossi}"}').replaceAll('"punto_id":44', '"punto_id":"{punto}"').replaceAll('"p-1"', '"p-{rossi}"'));
        expect(segnaposti).toEqual(desertaEApri.lotto);
    });

    test('un gesto che il server non accetta resta su questo computer «da rivedere»; «Rimanda» lo rimette in coda con un numero nuovo', async () => {
        await monta();
        rete.esito = 'rifiutato';
        rete.messaggio = 'Nessuna seduta è aperta: prima apri l\'assemblea.';
        await clic('[data-testid="mostra-tutti"]');
        await clic('[data-testa="p-15"] [data-testid="entra"]');
        const primo = ultimo();
        expect(testo('[data-testid="da-rivedere"]')).toContain('1 gesto non accettato resta su questo computer');
        expect(testo('[data-testid="da-rivedere"]')).toContain('Nessuna seduta è aperta');
        rete.esito = 'applicato';
        rete.messaggio = null;
        await clic('[data-testid="rimanda"]');
        expect(ultimo()).toMatchObject({ tipo: 'entra', dati: primo.dati, segnato_il: primo.segnato_il });
        expect(ultimo().gesto).not.toBe(primo.gesto);
        expect(document.body.querySelector('[data-testid="da-rivedere"]')).toBeNull();
    });

    test('la delega oltre il quinto «da decidere» non conta nella barra; la sala la fa decidere al presidente, con il motivo', async () => {
        const s = salaAperta();
        await monta({ ...s, deleghe: [...s.deleghe, delega(4, 15, 2, 'Bianchi Giorgio', { da_decidere: true, quinto: '4 condòmini su 5 · 155,70 su 200,00' })] });
        expect(testo('[data-testid="barra-teste"]')).toBe('11 su 26 condòmini in sala (3 per delega)');
        expect(testo('[data-testid="deleghe-da-decidere"]')).toContain('Greco Silvio a Bianchi Giorgio');
        expect(testo('[data-testid="deleghe-da-decidere"]')).toContain('decide il presidente');
        await clic('[data-testid="delega-accetta"]');
        await scrivi('[data-testid="motivo-delega-decidi"]', 'Greco Silvio lo ha chiesto per iscritto.');
        await clic('[data-testid="conferma-delega-decidi"]');
        expect(ultimo()).toMatchObject({ tipo: 'delega_decidi', dati: { delega_id: 4, accetta: true, motivo: 'Greco Silvio lo ha chiesto per iscritto.' } });
    });

    test('la delega di un proprietario per alcuni punti lo porta presente nella barra', async () => {
        const s = salaAperta();
        await monta({ ...s, deleghe: [...s.deleghe, delega(5, 15, 2, 'Bianchi Giorgio', { punti: [44] })] });
        expect(testo('[data-testid="barra-teste"]')).toBe('12 su 26 condòmini in sala (4 per delega)');
    });

    test('la seduta aperta senza la scelta del presidente (il controllo cambiato senza rete): la sala lo dice, e il presidente decide con il motivo', async () => {
        const s = salaAperta();
        await monta({ ...s, sedute: s.sedute.map((x) => (x.id === 2 ? { ...x, apertura_da_decidere: ['Gallo Enzo (Int. 7) non risulta fra i convocati.'] } : x)) });
        expect(testo('[data-testid="apertura-da-decidere"]')).toContain('Gallo Enzo (Int. 7) non risulta fra i convocati.');
        expect(testo('[data-testid="apertura-da-decidere"]')).toContain('art. 1136 c. 6');
        await clic('[data-testid="apertura-decidi"]');
        await scrivi('[data-testid="motivo-apertura-decidi"]', 'Gallo Enzo è presente e non si oppone.');
        await clic('[data-testid="conferma-apertura-decidi"]');
        expect(ultimo()).toMatchObject({ tipo: 'apertura_decidi', dati: { seduta_id: 2, motivo: 'Gallo Enzo è presente e non si oppone.' } });
    });

    test('aperta la seconda e fatto l\'appello, finché non si vota il menu ha «Segna deserta la seconda convocazione»: l\'assemblea è deserta', async () => {
        const axios = (await import('axios')).default as unknown as { post: ReturnType<typeof vi.fn> };
        const conLaRete = axios.post.getMockImplementation()!;
        // Senza rete: la sala vista da questo computer mostra il gesto finché non arriva.
        axios.post.mockImplementation(() => Promise.reject(new Error('Network Error')));
        try {
            const s = salaAperta();
            await monta({ ...s, punti: s.punti.map((p) => ({ ...p, votazioni: [], esito: null })) });
            await clic('[data-testid="voce-deserta"]');
            expect(testo('[data-testid="finestra-deserta"]')).toContain('Segnare deserta la seconda convocazione?');
            expect(testo('[data-testid="finestra-deserta"]')).toContain('Chi è in sala esce alle');
            expect(testo('[data-testid="finestra-deserta"]')).toContain('l\'assemblea è deserta: i punti tornano fra quelli per la prossima');
            await clic('[data-testid="conferma-deserta"]');
            expect(testo('[data-testid="apertura"]')).toContain('L\'assemblea è deserta');
            rete.lotti = [];
            axios.post.mockImplementation(conLaRete);
            window.dispatchEvent(new Event('online'));
            await assesta();
            expect(ultimo()).toMatchObject({ tipo: 'deserta', dati: { seduta_id: 2 } });
        } finally {
            axios.post.mockImplementation(conLaRete);
        }
    });

    test('«Approvata con modifiche» contro una proposta «Respinta» chiede il motivo; con un conflitto determinante anche contro «Approvata»', async () => {
        const s = salaAperta();
        s.punti[1] = { ...s.punti[1], votazioni: [votazioneDelPortone()] };
        await monta(s);
        await clic('[data-testid="punto-4"]');
        await clic('[data-testid="altro-esito"]');
        await scegli('esito-conferma', 'Approvata con modifiche');
        expect((document.body.querySelector('[data-testid="conferma-esito"]') as HTMLButtonElement).disabled).toBe(true);
        expect(document.body.querySelector('[data-testid="motivo-conferma"]')).not.toBeNull();
    });

    test('«Chiudi l\'assemblea» con il rendiconto votato e il riparto no lo dice, senza fermare la chiusura', async () => {
        const s = salaAperta();
        s.punti[0] = { ...s.punti[0], votazioni: s.punti[0].votazioni.map((v) => ({ ...v, oggetto: 'rendiconto' })) };
        await monta(s);
        await clic('[data-testid="voce-chiudi"]');
        expect(testo('[data-testid="senza-riparto"]')).toContain('Il riparto del punto 1 non è votato');
    });

    test('«Stampa il foglio con quello che c\'è» resta spento finché i gesti stanno partendo, e dice perché; dopo un «Trattato» «Rinvia» non c\'è', async () => {
        const axios = (await import('axios')).default as unknown as { post: ReturnType<typeof vi.fn> };
        const conLaRete = axios.post.getMockImplementation()!;
        axios.post.mockImplementation(() => Promise.reject(new Error('Network Error')));
        try {
            const s = salaAperta();
            await monta({ ...s, rotte: { ...s.rotte, foglio_compilato: '/sala/foglio?compilato=1' }, punti: s.punti.map((p) => (p.id === 48 ? { ...p, esito: 'senza_voto',
                votazioni: [votazioneDelPortone({ id: 480, punto_id: 48, maggioranza: 'senza_voto', esito: 'senza_voto', esito_proposto: 'senza_voto',
                    confermata_il: '2026-12-15T18:10:00Z' })] } : { ...p, votazioni: [], esito: null })) });
            expect(document.body.querySelector('[data-testid="voce-rinvia"]')).toBeNull();
            await clic('[data-testid="mostra-tutti"]');
            await clic('[data-testa="p-15"] [data-testid="entra"]');
            const voce = document.body.querySelector('[data-testid="voce-foglio-compilato"]') as HTMLButtonElement;
            expect(voce.disabled).toBe(true);
            expect(voce.textContent).toContain('1 gesto sta ancora partendo');
        } finally {
            axios.post.mockImplementation(conLaRete);
        }
    });
});
