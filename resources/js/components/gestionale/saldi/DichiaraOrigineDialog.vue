<script setup lang="ts">
/**
 * «Dichiara l'origine» del pregresso, per uno o per tanti saldi: la lista dei saldi a debito con la loro origine di oggi, spuntati quelli da
 * cui si parte, «solo quelli senza origine», «tutti»; la voce, senza una risposta già scelta. Non tocca l'importo: vale anche per un saldo fermo
 * in un piano emesso e per le righe di un passaggio.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SPIEGAZIONE_VOCI, daScelta, etichettaOrigine, opzioniOrigine, type EsercizioDellOrigine } from '@/lib/gestionale/debiti/originePregresso';

export interface SaldoDaDichiarare { id: number; etichetta: string; importo: number; origine_dichiarata?: string | null; esercizio_origine_id?: number | null }

const props = defineProps<{
    open: boolean;
    condominioId: number;
    saldi: SaldoDaDichiarare[];
    esercizi: EsercizioDellOrigine[];
    fraseOrigine: string;
    /** I saldi spuntati all'apertura (il saldo della riga da cui si è aperta). */
    partenza?: number[];
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const { generateRoute } = usePermission();
const scelti = ref<number[]>([]);
const voce = ref('');
const soloSenza = ref(false);
const errore = ref<string | null>(null);
const invio = ref(false);

watch(() => props.open, (aperta) => {
    if (aperta) {
        scelti.value = [...(props.partenza ?? [])];
        voce.value = '';
        errore.value = null;
    }
});

const visibili = computed(() => props.saldi.filter((s) => !soloSenza.value || !s.origine_dichiarata));
const tuttiVisibili = computed(() => visibili.value.length > 0 && visibili.value.every((s) => scelti.value.includes(s.id)));
const alterna = (id: number) => { scelti.value = scelti.value.includes(id) ? scelti.value.filter((x) => x !== id) : [...scelti.value, id]; };
const alternaTutti = () => {
    const ids = visibili.value.map((s) => s.id);
    scelti.value = tuttiVisibili.value ? scelti.value.filter((id) => !ids.includes(id)) : [...new Set([...scelti.value, ...ids])];
};
const euro = (c: number) => `€ ${new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2, useGrouping: 'always' } as Intl.NumberFormatOptions).format(c / 100)}`;

function conferma() {
    const { origine, esercizio_origine_id } = daScelta(voce.value);
    if (!origine || scelti.value.length === 0) {
        return;
    }
    invio.value = true;
    errore.value = null;
    router.post(route(generateRoute('gestionale.saldi.origine'), { condominio: props.condominioId }), {
        saldi: scelti.value, origine, ...(esercizio_origine_id ? { esercizio_origine_id } : {}),
    }, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: (e: Record<string, string>) => { errore.value = Object.values(e).filter(Boolean).join(' ') || 'L\'origine non è stata dichiarata. Riprova.'; },
        onFinish: () => { invio.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-[620px]">
            <DialogHeader>
                <DialogTitle>Dichiara l'origine del pregresso</DialogTitle>
                <DialogDescription>Di quando è il debito: l'elenco delle rate scadute ne conta la fascia e le date dei sei mesi. L'importo non cambia.</DialogDescription>
            </DialogHeader>

            <div class="space-y-3 text-sm">
                <label class="space-y-1 block">
                    <span class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Origine</span>
                    <select v-model="voce" data-testid="voce-origine" class="h-9 w-full rounded-md border px-2 bg-transparent">
                        <option value="" disabled>Scegli…</option>
                        <option v-for="o in opzioniOrigine(esercizi)" :key="o.value" :value="o.value">{{ o.text }}</option>
                        <option value="non_dichiarata">Togli l'origine (non dichiarata)</option>
                    </select>
                    <span class="block text-xs text-slate-500 dark:text-slate-400" data-testid="frase-origine">{{ fraseOrigine }}</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ SPIEGAZIONE_VOCI }}</span>
                </label>

                <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                    <label class="flex items-center gap-2"><Checkbox :model-value="soloSenza" @update:model-value="(v: any) => (soloSenza = !!v)" /> Solo quelli senza origine</label>
                    <Button size="sm" variant="ghost" class="h-7 text-xs" @click="alternaTutti">{{ tuttiVisibili ? 'Togli tutti' : `Scegli tutti i ${visibili.length}` }}</Button>
                </div>
                <div class="max-h-[260px] overflow-y-auto rounded-md border divide-y dark:divide-slate-800">
                    <label v-for="s in visibili" :key="s.id" class="flex items-center gap-2 px-2 py-1.5 text-xs">
                        <Checkbox :model-value="scelti.includes(s.id)" @update:model-value="alterna(s.id)" />
                        <span class="flex-1 min-w-0 truncate">{{ s.etichetta }}</span>
                        <span class="text-slate-500 dark:text-slate-400 shrink-0">{{ etichettaOrigine(s, esercizi) }}</span>
                        <span class="font-semibold shrink-0">{{ euro(s.importo) }}</span>
                    </label>
                    <p v-if="!visibili.length" class="p-3 text-xs text-slate-500">Nessun saldo a debito.</p>
                </div>
                <p v-if="errore" role="alert" class="text-xs text-rose-700 dark:text-rose-300">{{ errore }}</p>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="emit('update:open', false)">Annulla</Button>
                <Button :disabled="!voce || scelti.length === 0 || invio" data-testid="conferma-origine" @click="conferma">
                    {{ voce === 'non_dichiarata' ? 'Togli l\'origine da' : 'Dichiara per' }} {{ scelti.length === 1 ? '1 saldo' : `${scelti.length} saldi` }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
