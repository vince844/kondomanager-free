// @vitest-environment jsdom

/**
 * Il campo per un file solo: tutto in italiano («Scegli il file», «Nessun file scelto», «Cambia il file»), il pulsante apre il campo del browser
 * nascosto, il file scelto torna con `update:modelValue`, la × lo toglie, e quando il valore torna vuoto da fuori il campo del browser si svuota.
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import CampoFile from './CampoFile.vue';

const conFile = (input: Element, file: File) => Object.defineProperty(input, 'files', { value: [file], configurable: true });

describe('il campo per un file', () => {
    test('vuoto dice «Scegli il file» e «Nessun file scelto», e nessuna parola inglese', () => {
        const w = mount(CampoFile, { props: { modelValue: null, accept: '.pdf' } });
        expect(w.get('[data-testid="scegli-file"]').text()).toBe('Scegli il file');
        expect(w.get('[data-testid="nome-file"]').text()).toBe('Nessun file scelto');
        expect(w.find('[data-testid="togli-file"]').exists()).toBe(false);
        expect(w.get('input[type="file"]').classes()).toContain('hidden');
        expect(w.get('input[type="file"]').attributes('accept')).toBe('.pdf');
        expect(w.text()).not.toMatch(/choose|no file|browse/i);
    });

    test('il pulsante apre il campo del browser, e il file scelto torna fuori', async () => {
        const w = mount(CampoFile, { props: { modelValue: null, testid: 'file-prova' } });
        const campo = w.get('[data-testid="file-prova"]');
        const clic = vi.spyOn(campo.element as HTMLInputElement, 'click');
        await w.get('[data-testid="scegli-file"]').trigger('click');
        expect(clic).toHaveBeenCalledTimes(1);
        const file = new File(['x'], 'accordo firmato.pdf', { type: 'application/pdf' });
        conFile(campo.element, file);
        await campo.trigger('change');
        expect(w.emitted('update:modelValue')).toEqual([[file]]);
    });

    test('con un file dice il suo nome e «Cambia il file»; la × lo toglie, e il campo del browser si svuota', async () => {
        const file = new File(['x'], 'verbale.pdf', { type: 'application/pdf' });
        const w = mount(CampoFile, { props: { modelValue: file, 'onUpdate:modelValue': (f: File | null) => w.setProps({ modelValue: f }) } });
        expect(w.get('[data-testid="nome-file"]').text()).toBe('verbale.pdf');
        expect(w.get('[data-testid="scegli-file"]').text()).toBe('Cambia il file');
        const campo = w.get('input[type="file"]').element as HTMLInputElement;
        const svuota = vi.spyOn(campo, 'value', 'set');
        await w.get('[data-testid="togli-file"]').trigger('click');
        expect(w.emitted('update:modelValue')!.at(-1)).toEqual([null]);
        expect(w.get('[data-testid="nome-file"]').text()).toBe('Nessun file scelto');
        expect(svuota).toHaveBeenCalledWith('');
    });
});
