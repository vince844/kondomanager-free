<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Segna una delega ricevuta» (scena 1 della bozza delle scene della tappa 3): la delega arrivata in studio prima dell'assemblea, che vale per tutte le sue
 * date. Chi delega, a chi (anche una persona che non è un condòmino), il giorno in cui l'hai ricevuta, i punti (la delega del conduttore), la scansione
 * facoltativa; il quinto si conta mentre la segni, sui titolari di oggi. Oltre il quinto, con l'impostazione di serie, si registra rifiutata con l'articolo;
 * con «Decide il presidente» si segna, e la decide il presidente all'apertura (o la accetta subito con il suo motivo). È una visita, non un gesto della sala.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Button } from '@/components/ui/button';
import CampoFile from '@/components/CampoFile.vue';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { millesimi } from '@/lib/assemblee/sala/conti';
import type { SalaDellAssemblea } from '@/types/assemblee';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma, quintoDelDelegato, type Opzione } from './sala';

const props = defineProps<{ open: boolean; sala: SalaDellAssemblea }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const ALTRA = -1;
const oggi = giornoDiRoma();
const delegante = ref<number | null>(null);
const delegato = ref<number | null>(null);
const nome = ref('');
const ricevuta = ref(oggi);
const punti = ref<number[]>([]);
const scansione = ref<File | null>(null);
const motivo = ref('');
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
watch(() => props.open, (aperta) => {
    if (!aperta) return;
    delegante.value = null;
    delegato.value = null;
    nome.value = '';
    ricevuta.value = oggi;
    punti.value = [];
    scansione.value = null;
    motivo.value = '';
    errori.value = {};
}, { immediate: true });

const persone = computed<Opzione[]>(() => Object.entries(props.sala.fotografia?.persone ?? {}).map(([id, p]) => ({ id: Number(id), etichetta: p.nome }))
    .sort((a, b) => a.etichetta.localeCompare(b.etichetta, 'it')));
const aChi = computed<Opzione[]>(() => [{ id: ALTRA, etichetta: 'Un\'altra persona (non è un condòmino)' }, ...persone.value.filter((p) => p.id !== delegante.value)]);
const opzioniDeiPunti = computed(() => props.sala.punti.map((p) => ({ id: p.id, etichetta: `${p.numero}. ${p.testo}` })));
const nomeDi = (id: number | null) => persone.value.find((p) => p.id === id)?.etichetta ?? '';
const nomeDelDelegato = computed(() => (delegato.value === ALTRA ? nome.value.trim() : nomeDi(delegato.value)));
const scelto = computed(() => delegante.value !== null && delegato.value !== null && (delegato.value !== ALTRA || nome.value.trim() !== ''));

const conto = computed(() => {
    if (!scelto.value || !props.sala.fotografia) return null;

    return quintoDelDelegato(props.sala.fotografia, props.sala.deleghe, delegante.value!, delegato.value === ALTRA ? { nome: nome.value } : { anagrafica_id: delegato.value });
});
const rifiuta = computed(() => props.sala.impostazioni.delega_oltre_il_quinto !== 'decide_il_presidente');
const diChe = computed(() => (!conto.value ? '' : conto.value.oltre_teste && conto.value.oltre_valore ? 'dei condòmini e del valore'
    : conto.value.oltre_teste ? 'dei condòmini' : 'del valore'));

function registra() {
    if (!scelto.value || inCorso.value) return;
    inCorso.value = true;
    const corpo: Record<string, unknown> = {
        delegante_anagrafica_id: delegante.value,
        delegato: delegato.value === ALTRA ? { nome: nome.value.trim() } : { anagrafica_id: delegato.value },
        ricevuta_il: ricevuta.value,
    };
    if (punti.value.length) corpo.punti = punti.value;
    if (scansione.value) corpo.scansione = scansione.value;
    if (conto.value?.oltre && !rifiuta.value && motivo.value.trim()) corpo.motivo = motivo.value.trim();
    router.post(props.sala.rotte.deleghe, corpo as never, {
        forceFormData: true, preserveScroll: true, only: ['sala', 'passi', 'flash'],
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errori.value = e as Record<string, string>; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" data-testid="finestra-delega-ricevuta" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Segna una delega ricevuta</DialogTitle>
                <DialogDescription>Vale per tutte le date dell'assemblea.</DialogDescription>
            </DialogHeader>
            <div class="space-y-3 text-sm">
                <div class="space-y-1.5">
                    <Label class="text-xs">Chi delega</Label>
                    <v-select v-model="delegante" :options="persone" label="etichetta" :reduce="(o: Opzione) => o.id" :clearable="false" data-testid="chi-delega" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">A chi</Label>
                    <v-select v-model="delegato" :options="aChi" label="etichetta" :reduce="(o: Opzione) => o.id" :clearable="false" data-testid="a-chi" />
                </div>
                <div v-if="delegato === ALTRA" class="space-y-1.5">
                    <Label for="nome-ricevuta" class="text-xs">Il nome e il cognome</Label>
                    <Input id="nome-ricevuta" v-model="nome" class="h-9 text-sm" data-testid="nome-ricevuta" placeholder="Per esempio: Ricci Stefano" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="ricevuta-il" class="text-xs">Ricevuta il</Label>
                        <CampoGiorno id="ricevuta-il" v-model="ricevuta" :max="oggi" class="h-9 text-sm" data-testid="ricevuta-il" />
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-xs">Per quali punti</Label>
                        <v-select v-model="punti" :options="opzioniDeiPunti" label="etichetta" :reduce="(o: Opzione) => o.id" multiple placeholder="Tutti i punti"
                            data-testid="punti-ricevuta" />
                    </div>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">I punti servono per la delega del conduttore: senza, vale per tutti.</p>
                <div class="space-y-1.5">
                    <Label class="text-xs">La scansione</Label>
                    <CampoFile v-model="scansione" accept=".pdf,.jpg,.jpeg,.png" testid="scansione-ricevuta" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">Facoltativa. PDF o immagine, fino a 10 MB.</p>
                </div>

                <p v-if="conto && conto.si_applica && !conto.oltre" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 dark:border-slate-800
                    dark:bg-slate-900 dark:text-slate-300" data-testid="quinto-ricevuta">
                    Con questa delega {{ nomeDelDelegato }} rappresenta per delega {{ conto.frase }}.
                </p>
                <div v-if="conto?.oltre" class="space-y-1 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900 dark:border-rose-900 dark:bg-rose-950/30
                    dark:text-rose-200" data-testid="oltre-ricevuta">
                    <p class="font-semibold">{{ rifiuta ? 'Non si accetta.' : 'Oltre il quinto: decide il presidente.' }}</p>
                    <p data-testid="quinto-ricevuta">
                        Con {{ nomeDi(delegante) }}, {{ nomeDelDelegato }} rappresenterebbe per delega {{ conto.frase }}: più di un quinto {{ diChe }}.
                    </p>
                    <ul class="list-disc space-y-0.5 pl-4">
                        <li>Con più di venti condòmini il delegato non può superare un quinto dei condòmini e del valore (art. 67 c. 1 disp. att.).</li>
                        <li v-if="rifiuta">Impostazione dello studio: «la delega oltre il quinto si rifiuta».</li>
                        <li v-if="rifiuta">Puoi dirlo a {{ nomeDi(delegante) }}: può delegare un'altra persona, o venire.</li>
                        <li v-if="rifiuta">Una delega nuova di {{ nomeDi(delegante) }} sostituisce questa, che resta nell'elenco come rifiutata.</li>
                        <li v-else>Senza il motivo la decide il presidente all'apertura; con il motivo è accettata adesso.</li>
                    </ul>
                    <template v-if="!rifiuta">
                        <Label for="motivo-ricevuta" class="text-xs">Il motivo del presidente (facoltativo)</Label>
                        <Textarea id="motivo-ricevuta" v-model="motivo" rows="2" class="min-h-[60px] resize-none bg-white text-sm dark:bg-slate-950" />
                    </template>
                </div>
                <p v-if="conto && conto.si_applica" class="text-xs text-slate-500 dark:text-slate-400">
                    Il limite: un quinto dei {{ sala.fotografia?.costituzione.totale_teste }} condòmini ({{ conto.limite_teste }}) e dei millesimi
                    ({{ millesimi(conto.limite_valore) }}). Il conto usa i titolari di oggi; all'apertura si rifà.
                </p>
                <ul v-if="Object.keys(errori).length" class="list-disc pl-4 text-xs text-rose-700 dark:text-rose-300" data-testid="errori-ricevuta">
                    <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                </ul>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="!scelto || inCorso" data-testid="registra-ricevuta" @click="registra">
                    {{ conto?.oltre && rifiuta ? 'Registra rifiutata' : 'Registra la delega' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
