<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Un testo dell'amministratore nella nota sintetica: il campo, il conteggio dei caratteri, i testi dello studio dello stesso campo da mettere nel testo
 * («Usa un testo dello studio»: si aggiunge in fondo, e si può cambiare), e «Salva come testo dello studio» con un nome, per tutti i condomini o solo per
 * questo. Il testo usato si copia nella nota: cambiarlo o cancellarlo dopo non cambia la nota. Chi può salvare i testi li può anche rinominare ed eliminare.
 */
import { computed, ref } from 'vue';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import vSelect from 'vue-select';

export interface TestoStudio { id: number; tipo: string; nome: string; testo: string; per_tutti: boolean }

const props = defineProps<{
    modelValue: string; tipo: string; testi: TestoStudio[]; puoSalvare: boolean; condominioId: number; lunghezza: number;
    segnaposto?: string; righe?: number; etichetta?: string;
}>();
const emit = defineEmits<{ (e: 'update:modelValue', v: string): void; (e: 'testi', v: TestoStudio[]): void }>();

const { generateRoute } = usePermission();
const delCampo = computed(() => props.testi.filter((t) => t.tipo === props.tipo));
// Le voci del menu «Usa un testo dello studio…»: il nome, e «(solo questo condominio)» per un testo di un condominio solo. Scelto, il testo si usa e il
// menu torna vuoto.
const vociDelCampo = computed(() => delCampo.value.map((t) => ({ id: String(t.id), etichetta: `${t.nome}${t.per_tutti ? '' : ' (solo questo condominio)'}` })));
const usa = (id: string) => {
    const t = delCampo.value.find((x) => String(x.id) === id);
    if (!t) return;
    emit('update:modelValue', props.modelValue.trim() === '' ? t.testo : `${props.modelValue.trimEnd()}\n${t.testo}`);
};

const salvando = ref(false);
const nome = ref('');
const perTutti = ref<'' | 'si' | 'no'>('');
const errore = ref<string | null>(null);
const inCorso = ref(false);
async function salva() {
    errore.value = null;
    if (!nome.value.trim() || perTutti.value === '') {
        errore.value = 'Scrivi il nome e scegli per quali condomini vale.';
        return;
    }
    inCorso.value = true;
    try {
        const r = await axios.post(route(generateRoute('gestionale.nota-sintetica.testi.store'), { condominio: props.condominioId }),
            { tipo: props.tipo, nome: nome.value.trim(), testo: props.modelValue, per_tutti: perTutti.value === 'si' });
        emit('testi', r.data.testi);
        salvando.value = false;
        nome.value = '';
        perTutti.value = '';
    } catch (e: any) {
        const errori = e?.response?.data?.errors ?? {};
        errore.value = (Object.values(errori)[0] as string[] | undefined)?.[0] ?? 'Il testo non è stato salvato.';
    } finally {
        inCorso.value = false;
    }
}

/* ─── I testi dello studio di questo campo: rinomina, elimina ─── */
const gestione = ref(false);
const nomi = ref<Record<number, string>>({});
const erroreTesti = ref<string | null>(null);
const apriGestione = () => { gestione.value = !gestione.value; nomi.value = Object.fromEntries(delCampo.value.map((t) => [t.id, t.nome])); erroreTesti.value = null; };
const indirizzo = (t: TestoStudio, azione: 'update' | 'destroy') => route(generateRoute(`gestionale.nota-sintetica.testi.${azione}`), { condominio: props.condominioId, testo: t.id });
async function rinomina(t: TestoStudio) {
    erroreTesti.value = null;
    try {
        const r = await axios.put(indirizzo(t, 'update'), { nome: (nomi.value[t.id] ?? '').trim() });
        emit('testi', r.data.testi);
    } catch (e: any) {
        const errori = e?.response?.data?.errors ?? {};
        erroreTesti.value = (Object.values(errori)[0] as string[] | undefined)?.[0] ?? 'Il nome non è stato cambiato.';
    }
}
async function elimina(t: TestoStudio) {
    erroreTesti.value = null;
    if (!window.confirm(`Eliminare il testo «${t.nome}»? Le note che l'hanno usato non cambiano.`)) return;
    try {
        const r = await axios.delete(indirizzo(t, 'destroy'));
        emit('testi', r.data.testi);
    } catch {
        erroreTesti.value = 'Il testo non è stato eliminato.';
    }
}
</script>

<template>
    <div class="space-y-1" :data-testo="tipo">
        <label v-if="etichetta" class="text-[12px] font-medium text-slate-700 dark:text-slate-300">{{ etichetta }}</label>
        <textarea
            :value="modelValue"
            :rows="righe ?? 3"
            :maxlength="lunghezza"
            :placeholder="segnaposto"
            class="w-full rounded-md border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-[13px] text-slate-800 dark:text-slate-200"
            data-test="testo"
            @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
        />
        <div class="flex flex-wrap items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
            <span data-test="caratteri">{{ modelValue.length }} / {{ lunghezza }}</span>
            <div v-if="delCampo.length" class="menu-campo menu-campo-piccolo w-[260px] max-w-full" data-test="usa-testo">
                <v-select :model-value="null" :options="vociDelCampo" label="etichetta" :reduce="(t: { id: string }) => t.id" :searchable="false" :clearable="false"
                    placeholder="Usa un testo dello studio…" class="w-full" @update:model-value="(v: string | null) => v && usa(v)" />
            </div>
            <button v-if="puoSalvare && modelValue.trim() !== '' && !salvando" type="button" class="underline" data-test="salva-come" @click="salvando = true">Salva come testo dello studio</button>
            <button v-if="puoSalvare && delCampo.length" type="button" class="underline" data-test="gestisci-testi" @click="apriGestione">Rinomina o elimina i testi dello studio</button>
        </div>
        <div v-if="gestione" class="space-y-1 rounded border border-slate-200 dark:border-slate-700 px-2 py-1.5 text-[12px]" data-test="gestione-testi">
            <div v-for="t in delCampo" :key="t.id" class="flex flex-wrap items-center gap-2" :data-testo-studio="t.id">
                <input v-model="nomi[t.id]" class="h-7 flex-1 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" />
                <span class="text-[11px] text-slate-500">{{ t.per_tutti ? 'tutti i condomini' : 'solo questo condominio' }}</span>
                <Button size="sm" variant="outline" class="h-7" data-test="rinomina-testo" @click="rinomina(t)">Rinomina</Button>
                <Button size="sm" variant="ghost" class="h-7" data-test="elimina-testo" @click="elimina(t)">Elimina</Button>
            </div>
            <p v-if="erroreTesti" class="text-rose-600 dark:text-rose-400" data-test="errore-testi">{{ erroreTesti }}</p>
        </div>
        <div v-if="salvando" class="flex flex-wrap items-center gap-2 text-[12px]" data-test="salva-come-form">
            <input v-model="nome" placeholder="Il nome del testo" class="h-7 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" />
            <label class="flex items-center gap-1"><input v-model="perTutti" type="radio" value="no" /> Solo questo condominio</label>
            <label class="flex items-center gap-1"><input v-model="perTutti" type="radio" value="si" /> Tutti i condomini</label>
            <Button size="sm" class="h-7" :disabled="inCorso" @click="salva">Salva</Button>
            <Button size="sm" variant="ghost" class="h-7" @click="salvando = false">Annulla</Button>
            <span v-if="errore" class="text-rose-600 dark:text-rose-400" data-test="errore">{{ errore }}</span>
        </div>
    </div>
</template>
