// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Per chi» con l'assemblea dei condòmini di un gruppo (Coda 734, scena 7 della bozza approvata, decisione 699 (1)): dopo le scale il menu ha una voce per
 * ogni tabella segnata «di un gruppo», «I condòmini della tabella «Box»», con sotto «8 unità · 1.000,00 millesimi» e il valore `gruppo:<id>`; senza tabelle
 * segnate la voce non c'è. Scelto il gruppo, sotto il menu il riquadro azzurro dice chi riceve l'avviso e come conta la sala, con l'articolo.
 *
 * Il modulo nella forma della richiesta: `gruppo:5` diventa `ambito` gruppo con `tabella_id` 5; e dai dati della pagina torna `gruppo:5`.
 *
 * Che cosa non copre: la tabella del valore ferma, che è della pagina (`PreparaGruppo.test.ts`); il server (`AssembleaDiUnGruppoTest`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { datiDelModulo, moduloDa } from '@/lib/assemblee/pagine';

vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options', 'disabled'], template: '<div class="v-select-finto" />' } }));

const Date = (await import('./DateDellAssemblea.vue')).default;
const controllo = (conQuota: number, unita: number, millesimi: string) => ({ frase: `${conQuota} unità su ${unita} · ${millesimi} millesimi`, copre: false,
    esatta: false, con_quota: conQuota, unita });
const scelte = (segno: boolean) => ({ tipi: [], perimetri: [], modalita: [], materie: [], maggioranze: [], esiti: [], a_chi: [], unita: 34, palazzine: [],
    scale: [{ id: 2, nome: 'A', palazzina_id: null, unita: 12 }, { id: 3, nome: 'B', palazzina_id: null, unita: 14 }],
    tabelle: [
        { id: 5, nome: 'Box', unita: 8, di_un_gruppo: segno, millesimi: '1.000,00', controlli: { condominio: controllo(8, 34, '1.000,00') } },
        { id: 6, nome: 'Proprietà', unita: 26, di_un_gruppo: false, millesimi: '1.000,00', controlli: { condominio: controllo(26, 34, '1.000,00') } },
        { id: 7, nome: 'Ascensore B', unita: 6, di_un_gruppo: segno, millesimi: '1.000,00', controlli: { condominio: controllo(6, 34, '1.000,00') } },
    ],
    maggioranze_proposte: {} });
const modulo = (perimetro: string) => ({ tipo: 'straordinaria', perimetro, luogo: '', modalita: 'presenza', piattaforma: '', consenso_videoconferenza_il: '',
    consenso_videoconferenza_modo: '', sedute: [{ convocazione: 'prima', numero: 1, giorno: '2026-12-14', ora: '08:00' },
        { convocazione: 'seconda', numero: 1, giorno: '2026-12-15', ora: '18:30' }] });

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });
type Opzione = { valore: string; nome: string; sotto?: string };
const menuPerChi = () => w!.findAllComponents({ name: 'VSelect' }).find((c) => (c.props('options') as Opzione[]).some((o) => o.valore === 'condominio'))!;

describe('«Per chi»: i condòmini di una tabella segnata «di un gruppo»', () => {
    test('dopo le scale una voce per ogni tabella con il segno, con le unità e i millesimi', () => {
        w = mount(Date as never, { props: { modelValue: modulo('condominio'), scelte: scelte(true) } as never });
        const opzioni = menuPerChi().props('options') as Opzione[];
        expect(opzioni.map((o) => o.valore)).toEqual(['condominio', 'scala:2', 'scala:3', 'gruppo:5', 'gruppo:7']);
        expect(opzioni.find((o) => o.valore === 'gruppo:5')).toMatchObject({ nome: 'I condòmini della tabella «Box»', sotto: '8 unità · 1.000,00 millesimi' });
        expect(w.find('[data-testid="assemblea-del-gruppo"]').exists()).toBe(false);
    });

    test('senza tabelle segnate la voce non c\'è', () => {
        w = mount(Date as never, { props: { modelValue: modulo('condominio'), scelte: scelte(false) } as never });
        expect((menuPerChi().props('options') as Opzione[]).map((o) => o.valore)).toEqual(['condominio', 'scala:2', 'scala:3']);
    });

    test('scelto il gruppo, il riquadro azzurro dice chi riceve l\'avviso e come conta la sala', () => {
        w = mount(Date as never, { props: { modelValue: modulo('gruppo:5'), scelte: scelte(true) } as never });
        const riquadro = w.get('[data-testid="assemblea-del-gruppo"]');
        expect(riquadro.text().replace(/\s+/g, ' ')).toBe('L\'assemblea è dei soli condòmini delle 8 unità della tabella «Box» (art. 1123 c. 3 c.c.). L\'avviso va a '
            + 'loro, e la sala conta teste e millesimi fra loro. Il gruppo e la tabella non si cambiano dopo la preparazione: decidono chi è convocato.');
        expect(riquadro.classes()).toContain('bg-sky-50');
    });

    test('Fase 1-bis: con una sola unità il riquadro dice «dell\'unità», non «delle 1 unità»', () => {
        const una = scelte(true);
        una.tabelle[0].unita = 1;
        w = mount(Date as never, { props: { modelValue: modulo('gruppo:5'), scelte: una } as never });
        const testo = w.get('[data-testid="assemblea-del-gruppo"]').text().replace(/\s+/g, ' ');
        expect(testo).toContain('L\'assemblea è dei soli condòmini dell\'unità della tabella «Box» (art. 1123 c. 3 c.c.).');
        expect(testo).not.toContain('1 unità');
    });

    test('il modulo nella forma della richiesta: `gruppo:5` è l\'ambito gruppo con la tabella 5; e dai dati della pagina torna `gruppo:5`', () => {
        expect(datiDelModulo(modulo('gruppo:5'))).toMatchObject({ ambito: 'gruppo', tabella_id: 5, palazzina_id: null, scala_id: null });
        expect(datiDelModulo(modulo('scala:3'))).not.toHaveProperty('tabella_id');
        const pagina = { tipo: 'straordinaria', ambito: 'gruppo', palazzina_id: null, scala_id: null, luogo: null, modalita: 'presenza', piattaforma: null,
            consenso_videoconferenza_il: null, consenso_videoconferenza_modo: null, sedute: [], tabella: { id: 5, nome: 'Box' }, gruppo: { tabella_id: 5, nome: 'Box', unita: 8 } };
        expect(moduloDa(pagina as never).perimetro).toBe('gruppo:5');
    });
});
