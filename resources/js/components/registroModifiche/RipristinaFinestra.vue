<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La finestra di «Ripristina» (scena 1 della bozza, §8 e P3 del progetto della tappa 1): «Rimettere l'email di prima?», chi l'ha cambiata e quando, «Adesso»
 * e «Torna a», per un indirizzo di posta la frase sul registro degli invii («il ripristino non la cambia e non la rimanda»), e la regola: il ripristino
 * passa dagli stessi controlli della scheda e diventa una riga del registro con il tuo nome. L'anteprima e il ripristino li dà il server: quando la scheda
 * non passa le regole della sua pagina, la finestra dice il messaggio della pagina.
 */
import { ref, watch } from 'vue';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Info } from 'lucide-vue-next';
import { messaggioDellErrore, valore, type AnteprimaDelRipristino } from '@/lib/registroModifiche/registro';

const props = defineProps<{ open: boolean; rigaId: number | null; campo: string | null }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto', messaggio: string): void }>();

const { generateRoute } = usePermission();
const anteprima = ref<AnteprimaDelRipristino | null>(null);
const errore = ref<string | null>(null);
const inCorso = ref(false);
let lettura = 0;

watch(() => [props.open, props.rigaId, props.campo] as const, async ([aperta, riga, campo]) => {
    // Alla chiusura il contenuto resta com'è finché la finestra sparisce: azzerarlo qui farebbe lampeggiare «Un momento…» durante l'uscita.
    if (!aperta || riga === null || campo === null) {
        return;
    }
    anteprima.value = null;
    errore.value = null;
    const numero = ++lettura;
    try {
        const r = await axios.get(route(generateRoute('registro-modifiche.ripristina.anteprima'), { riga }), { params: { campo } });
        if (numero === lettura) {
            anteprima.value = r.data as AnteprimaDelRipristino;
        }
    } catch (e) {
        if (numero === lettura) {
            errore.value = messaggioDellErrore(e, 'L\'anteprima non si è caricata. Riprova.');
        }
    }
}, { immediate: true });

async function rimetti() {
    if (!anteprima.value || props.rigaId === null || props.campo === null) {
        return;
    }
    inCorso.value = true;
    errore.value = null;
    try {
        const r = await axios.post(route(generateRoute('registro-modifiche.ripristina'), { riga: props.rigaId }), { campo: props.campo });
        emit('fatto', (r.data?.message as string) ?? 'Rimesso.');
        emit('update:open', false);
    } catch (e) {
        errore.value = messaggioDellErrore(e, 'Il dato non è stato rimesso. Riprova.');
    } finally {
        inCorso.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-w-[520px]" data-testid="finestra-ripristina">
            <DialogHeader>
                <DialogTitle class="text-lg font-semibold">{{ anteprima?.titolo ?? 'Rimettere il dato di prima?' }}</DialogTitle>
                <DialogDescription class="text-sm text-slate-500 dark:text-slate-400">{{ anteprima?.descrizione ?? 'Un momento…' }}</DialogDescription>
            </DialogHeader>

            <div v-if="anteprima" class="space-y-3.5 text-sm">
                <div class="grid grid-cols-[90px_minmax(0,1fr)] gap-x-2 gap-y-1.5" data-testid="adesso-torna-a">
                    <span class="text-xs text-slate-500 dark:text-slate-400">Adesso</span>
                    <span class="break-words text-slate-900 dark:text-slate-100" data-testid="adesso">{{ valore(anteprima.adesso) }}</span>
                    <span class="text-xs text-slate-500 dark:text-slate-400">Torna a</span>
                    <strong class="break-words text-slate-900 dark:text-white" data-testid="torna-a">{{ valore(anteprima.torna_a) }}</strong>
                </div>
                <div v-if="anteprima.invii" class="flex items-start gap-2.5 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2.5 text-sky-800 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300" data-testid="invii">
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    <p class="text-xs">{{ anteprima.invii }}</p>
                </div>
                <p v-if="anteprima.gia_cosi" class="text-xs text-amber-800 dark:text-amber-300" data-testid="gia-cosi">È già così: non c'è niente da rimettere.</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Il ripristino è una modifica come le altre: passa dagli stessi controlli della scheda e diventa una riga del registro, con il tuo nome.
                </p>
            </div>
            <p v-if="errore" class="text-xs text-rose-700 dark:text-rose-400" data-testid="errore-ripristino">{{ errore }}</p>

            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                <Button variant="outline" size="sm" @click="$emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="!anteprima || anteprima.gia_cosi || inCorso" data-testid="rimetti" @click="rimetti">
                    {{ inCorso ? 'Un momento…' : (anteprima?.pulsante ?? 'Rimetti il dato di prima') }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
