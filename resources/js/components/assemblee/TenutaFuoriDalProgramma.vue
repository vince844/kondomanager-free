<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Tenuta su carta» (R16; §13 rilievo 8; nel codice `TenutaFuori…`): l'assemblea preparata nel programma e tenuta su carta, con il verbale di carta e senza la
 * sala. Dal menu «Azioni» della pagina, la voce «Tenuta su carta» con «Registra gli esiti dal verbale di carta». Il giorno della seduta tenuta (di serie il
 * giorno della riunione, non dopo oggi) e, per ogni punto, l'esito con che cosa ha deciso; un punto senza esito è «non trattato» e il suo memo torna in
 * attesa per la prossima. Le delibere che mancano si registrano dopo sul loro punto. Non perde il lavoro né i memo.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Button } from '@/components/ui/button';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePermission } from '@/composables/permissions';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import type { AssembleaDellaPagina, PuntoDellaPagina, Scelta } from '@/lib/assemblee/pagine';

const props = defineProps<{ open: boolean; condominioId: number; assemblea: AssembleaDellaPagina; ordine: PuntoDellaPagina[]; esiti: Scelta[] }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const { generateRoute } = usePermission();
const oggi = giornoDiRoma();
const giorno = ref('');
/** Il numero del verbale di carta nel registro dei verbali: quello proposto, correggibile; vuoto se non si vuole dare. */
const numero = ref('');
const esiti = ref<Record<number, { esito: string; testo: string }>>({});
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);

const opzioni = computed<{ valore: string; nome: string }[]>(() => [{ valore: '', nome: 'Non trattato' }, ...props.esiti]);
const dopoOggi = computed(() => giorno.value !== '' && giorno.value > oggi);
const trattati = computed(() => Object.values(esiti.value).filter((e) => e.esito !== '').length);

watch(() => props.open, (a) => {
    if (!a) return;
    giorno.value = props.assemblea.data && props.assemblea.data <= oggi ? props.assemblea.data : '';
    numero.value = props.assemblea.numero_verbale_proposto ? String(props.assemblea.numero_verbale_proposto) : '';
    esiti.value = Object.fromEntries(props.ordine.map((p) => [p.id, { esito: '', testo: '' }]));
    errori.value = {};
}, { immediate: true });

function conferma() {
    inCorso.value = true;
    router.post(route(generateRoute('gestionale.assemblee.tenuta-fuori'), { condominio: props.condominioId, assemblea: props.assemblea.id }), {
        giorno: giorno.value,
        ...(numero.value.trim() !== '' ? { numero_verbale: Number(numero.value) } : {}),
        esiti: Object.fromEntries(Object.entries(esiti.value).filter(([, e]) => e.esito !== '').map(([id, e]) => [id, { esito: e.esito, testo: e.testo || null }])),
    }, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: (e: Record<string, string>) => { errori.value = e; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-h-[100dvh] overflow-y-auto sm:max-w-[640px]" data-testid="finestra-tenuta-fuori" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>L'assemblea si è tenuta su carta</DialogTitle>
                <DialogDescription>Registri il giorno e gli esiti dei punti dal verbale di carta, e la pagina diventa la sua riunione registrata a mano.</DialogDescription>
            </DialogHeader>
            <div class="space-y-3.5">
                <label class="block max-w-[220px]"><span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Il giorno in cui si è tenuta</span>
                    <CampoGiorno v-model="giorno" :max="oggi" data-testid="giorno-tenuta" />
                </label>
                <p v-if="dopoOggi || errori.giorno" class="text-xs text-rose-700 dark:text-rose-400">{{ errori.giorno ?? 'Il giorno della seduta tenuta non può essere dopo oggi.' }}</p>
                <label class="block max-w-[220px]"><span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Il numero del verbale nel registro</span>
                    <input v-model="numero" type="text" inputmode="numeric" :placeholder="assemblea.numero_verbale_proposto ? String(assemblea.numero_verbale_proposto) : ''"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm dark:bg-slate-950" data-testid="numero-verbale-tenuta" />
                </label>
                <p class="text-xs text-slate-500 dark:text-slate-400">Il verbale di carta entra nel registro dei verbali con il numero dopo l'ultimo: si corregge, mai uno già usato.</p>
                <p v-if="errori.numero_verbale" class="text-xs text-rose-700 dark:text-rose-400">{{ errori.numero_verbale }}</p>
                <ol class="space-y-2.5">
                    <li v-for="p in ordine" :key="p.id" class="rounded-md border border-slate-200 p-2.5 dark:border-slate-700" data-testid="esito-punto">
                        <p class="break-words text-sm text-slate-900 dark:text-slate-100"><span class="font-semibold tabular-nums">{{ p.numero }}.</span> {{ p.testo }}</p>
                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-[200px_minmax(0,1fr)]">
                            <div class="menu-campo">
                                <v-select v-model="esiti[p.id].esito" :options="opzioni" label="nome" :reduce="(o: { valore: string }) => o.valore" :clearable="false" :searchable="false" />
                            </div>
                            <input v-if="esiti[p.id].esito !== ''" v-model="esiti[p.id].testo" type="text" placeholder="Che cosa ha deciso (di serie il testo del punto)"
                                class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm placeholder:text-slate-400 dark:bg-slate-950" />
                        </div>
                    </li>
                </ol>
                <p class="text-xs text-slate-600 dark:text-slate-300" data-testid="adesso-tenuta">
                    Adesso: {{ trattati === 1 ? '1 delibera' : `${trattati} delibere` }} da registrare, {{ ordine.length - trattati === 1 ? '1 punto non trattato' : `${ordine.length - trattati} punti non trattati` }}.
                    I memo dei punti non trattati tornano nella memoria del condominio.
                </p>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end dark:border-slate-800">
                <Button variant="outline" @click="$emit('update:open', false)">Annulla</Button>
                <Button :disabled="giorno === '' || dopoOggi || inCorso" data-testid="conferma-tenuta" @click="conferma">{{ inCorso ? 'Un momento…' : 'Registra gli esiti' }}</Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
