// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 448: nella successione con l'arretrato agli eredi, un interesse o una spesa del defunto con un pagamento segnalato dal portale e non
 * verificato resta al defunto; l'amministratore può passarlo comunque agli eredi con la casella della sua riga. La riga dice che cosa succede con la
 * casella e senza; la casella manda l'id della quota.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import AccessoriSegnalatiDelDefunto from './AccessoriSegnalatiDelDefunto.vue';

const sollecito = { quota_id: 25, causale: 'Spese di sollecito', aperto: 1601, aperto_formattato: '€ 16,01', passa: false };
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('gli accessori del defunto con un pagamento segnalato', () => {
    test('senza la spunta: resta al defunto; con la spunta passa agli eredi e la segnalazione resta da verificare', async () => {
        const w = mount(AccessoriSegnalatiDelDefunto, { props: { accessori: [sollecito], defunto: 'Neri', modelValue: [] } });
        const riga = w.find('[data-testid="accessorio-segnalato"]');
        expect(pulito(riga.text())).toContain('Passa comunque agli eredi «Spese di sollecito» (€ 16,01)');
        expect(pulito(riga.text())).toContain('Ha un pagamento segnalato dal portale e non ancora verificato: senza la spunta resta a nome di Neri.');
        const casella = riga.find('input[type="checkbox"]');
        expect((casella.element as HTMLInputElement).checked).toBe(false);

        await casella.setValue(true);
        expect(w.emitted('update:modelValue')?.[0]).toEqual([[25]]);

        await w.setProps({ modelValue: [25], accessori: [{ ...sollecito, passa: true }] });
        expect((w.find('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(true);
        expect(pulito(w.find('[data-testid="accessorio-segnalato"]').text())).toContain('Passa agli eredi per quota, come gli altri; la segnalazione resta da verificare.');
        // Il pagamento vero: dal compito della segnalazione, sulla quota di ogni erede con «Versato da» il defunto.
        expect(pulito(w.find('[data-testid="accessorio-segnalato"]').text())).toContain('Se il pagamento risulta arrivato, registralo con «Registra l\'incasso» dal compito '
            + 'del bonifico segnalato, sulla quota di ogni erede con «Versato da» Neri: il primo incasso chiude il compito.');
        await w.find('input[type="checkbox"]').setValue(false);
        expect(w.emitted('update:modelValue')?.[1]).toEqual([[]]);
    });

    test('senza accessori segnalati non mostra niente', () => {
        const w = mount(AccessoriSegnalatiDelDefunto, { props: { accessori: [], defunto: 'Neri', modelValue: [] } });
        expect(w.find('[data-testid="accessori-segnalati"]').exists()).toBe(false);
    });
});
