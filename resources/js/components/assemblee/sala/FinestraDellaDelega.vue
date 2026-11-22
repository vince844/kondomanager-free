<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Una delega in più» segnata in sala (scena 2 della bozza approvata della sala): a chi (un condòmino, o una persona che non è un condòmino), con il conto
 * del quinto che si aggiorna mentre la segni (art. 67 c. 1 disp. att.: con più di venti condòmini il delegato non supera un quinto dei condòmini e del
 * valore, senza la sua quota). Oltre il quinto, con l'impostazione dello studio di serie la delega non si accetta e si registra rifiutata con l'articolo;
 * con «Decide il presidente» si accetta con il suo motivo. All'amministratore non si delega (art. 67 c. 5). Il gesto è `delega`. Il menu «A chi» si apre
 * fuori dal corpo della finestra (`menuFuoriDallaFinestra`, `restaSeNelMenu`: rilievo C47, decisione 663).
 */
import { computed, ref, watch } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { millesimi } from '@/lib/assemblee/sala/conti';
import { useSala } from './contesto';
import { menuFuoriDallaFinestra, restaSeNelMenu } from '@/components/assemblee/verbale/finestra';
import { quintoDelDelegato, type Opzione } from './sala';

const props = defineProps<{ open: boolean; delegante: Opzione | null; presenti: number[] }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();
const { sala, segna } = useSala();

const ALTRA = -1;
const delegato = ref<number | null>(null);
const nome = ref('');
const motivo = ref('');
watch(() => props.open, (aperta) => {
    if (!aperta) return;
    delegato.value = null;
    nome.value = '';
    motivo.value = '';
}, { immediate: true });

/** Chi è in sala per primo, poi gli altri, per nome; in testa «Un'altra persona». */
const opzioni = computed<Opzione[]>(() => {
    const persone = Object.entries(sala.value.fotografia?.persone ?? {}).map(([id, p]) => ({ id: Number(id), nome: p.nome }))
        .filter((p) => p.id !== props.delegante?.id);
    const dentro = persone.filter((p) => props.presenti.includes(p.id)).sort((a, b) => a.nome.localeCompare(b.nome, 'it'));
    const fuori = persone.filter((p) => !props.presenti.includes(p.id)).sort((a, b) => a.nome.localeCompare(b.nome, 'it'));

    return [{ id: ALTRA, etichetta: 'Un\'altra persona (non è un condòmino)' }, ...dentro.map((p) => ({ id: p.id, etichetta: `${p.nome} · in sala` })),
        ...fuori.map((p) => ({ id: p.id, etichetta: p.nome }))];
});
const nomeDelDelegato = computed(() => (delegato.value === ALTRA ? nome.value.trim() : sala.value.fotografia?.persone[String(delegato.value)]?.nome ?? ''));
const scelto = computed(() => delegato.value !== null && (delegato.value !== ALTRA || nome.value.trim() !== ''));
const conto = computed(() => {
    if (!scelto.value || !props.delegante || !sala.value.fotografia) return null;

    return quintoDelDelegato(sala.value.fotografia, sala.value.deleghe, props.delegante.id,
        delegato.value === ALTRA ? { nome: nome.value } : { anagrafica_id: delegato.value });
});
const rifiuta = computed(() => sala.value.impostazioni.delega_oltre_il_quinto !== 'decide_il_presidente');
const diChe = computed(() => (!conto.value ? '' : conto.value.oltre_teste && conto.value.oltre_valore ? 'dei condòmini e del valore'
    : conto.value.oltre_teste ? 'dei condòmini' : 'del valore'));
const pronta = computed(() => scelto.value && (!conto.value?.oltre || rifiuta.value || motivo.value.trim() !== ''));

async function registra() {
    if (!pronta.value || !props.delegante) return;
    await segna('delega', {
        delegante_anagrafica_id: props.delegante.id,
        delegato: delegato.value === ALTRA ? { nome: nome.value.trim() } : { anagrafica_id: delegato.value! },
        ...(conto.value?.oltre && !rifiuta.value ? { motivo: motivo.value.trim() } : {}),
    });
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <!-- Il menu «A chi» si apre fuori dal corpo che scorre (rilievo C47, come la finestra del memo): scegliere una voce non chiude la finestra. -->
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" data-testid="finestra-delega" @open-auto-focus.prevent @interact-outside="restaSeNelMenu">
            <DialogHeader>
                <DialogTitle>Una delega in sala</DialogTitle>
                <DialogDescription>{{ delegante?.etichetta }} delega una persona per questa assemblea.</DialogDescription>
            </DialogHeader>
            <div class="space-y-3 text-sm">
                <div class="space-y-1.5">
                    <Label class="text-xs">A chi</Label>
                    <v-select v-model="delegato" append-to-body :calculate-position="menuFuoriDallaFinestra" :options="opzioni" label="etichetta"
                        :reduce="(o: Opzione) => o.id" :clearable="false" data-testid="delegato" />
                </div>
                <div v-if="delegato === ALTRA" class="space-y-1.5">
                    <Label for="delegato-nome" class="text-xs">Il nome e il cognome</Label>
                    <Input id="delegato-nome" v-model="nome" class="h-9 text-sm" data-testid="delegato-nome" placeholder="Per esempio: Ricci Stefano" />
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">L'amministratore non può ricevere deleghe (art. 67 c. 5 disp. att.).</p>

                <div v-if="conto && conto.si_applica" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-900" data-testid="conto-quinto">
                    <p class="text-xs font-semibold text-slate-900 dark:text-white">Il conto del quinto</p>
                    <p class="text-xs text-slate-700 dark:text-slate-300">{{ nomeDelDelegato }}: {{ conto.frase }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Il limite: un quinto dei {{ sala.fotografia?.costituzione.totale_teste }} condòmini ({{ conto.limite_teste }}) e dei millesimi
                        ({{ millesimi(conto.limite_valore) }}), perché i condòmini sono più di venti.
                    </p>
                </div>
                <div v-if="conto?.oltre" class="space-y-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900 dark:border-rose-900
                    dark:bg-rose-950/30 dark:text-rose-200" data-testid="oltre-quinto">
                    <p class="font-semibold">{{ rifiuta ? 'Non si accetta.' : 'Oltre il quinto: decide il presidente.' }}</p>
                    <p>Con {{ delegante?.etichetta }}, {{ nomeDelDelegato }} rappresenterebbe per delega {{ conto.frase }}: più di un quinto {{ diChe }} (art. 67 c. 1 disp. att.).</p>
                    <p v-if="rifiuta">Impostazione dello studio: «la delega oltre il quinto si rifiuta». Resta nell'elenco come rifiutata; una delega nuova la sostituisce.</p>
                    <template v-else>
                        <Label for="motivo-delega" class="text-xs">Il motivo del presidente per accettarla</Label>
                        <Textarea id="motivo-delega" v-model="motivo" rows="2" class="min-h-[60px] resize-none bg-white text-sm dark:bg-slate-950" data-testid="motivo-delega" />
                    </template>
                </div>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="!pronta" data-testid="registra-delega" @click="registra">
                    {{ conto?.oltre ? (rifiuta ? 'Registra rifiutata' : 'Accetta, con il motivo') : 'Registra la delega' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
