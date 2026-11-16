<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Le finestre delle risposte a un compito dell'agenda, una sola istanza per pagina: il menu «⋯» di ogni riga la apre con `apri(voce, compito)`. Sono le
 * finestre delle risposte dei sei mesi, con le loro parti (il titolo con l'icona, il compito in grigio, il campo, i pulsanti in fondo):
 *
 * - «Fatto»: la nota facoltativa; Invio conferma (Maiuscolo+Invio va a capo).
 * - «Non serve»: il motivo; sul bonifico segnalato dal portale è il rifiuto della segnalazione, e il motivo lo legge il condòmino.
 * - «Ricordamelo»: il giorno, da domani nel giorno dell'amministratore (arriva dal server, non dal browser), e la nota; il giorno va nell'agenda.
 * - «Il registro»: le risposte del compito, la più recente prima.
 *
 * Le scrive il server (`admin.inbox.risposta`); i rifiuti arrivano con le loro frasi.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { BellRing, CheckCircle2, CircleSlash, History, Loader2, XCircle } from 'lucide-vue-next';
import { domani as giornoDopo } from '@/lib/gestionale/lettere/lettere';
import { eUnBonificoSegnalato, fraseDelPromemoriaAParte, fraseDopoLaScadenza, testoDelGestoDelCompito, type CompitoConRisposte, type VoceDelCompito } from '@/lib/inbox/risposteDelCompito';

const props = defineProps<{ oggi: string }>();

const compito = ref<CompitoConRisposte | null>(null);
const voce = ref<VoceDelCompito | null>(null);
const aperta = ref(false);
const registroAperto = ref(false);
const giornoScelto = ref('');
const testo = ref('');
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);

const domani = computed(() => (props.oggi ? giornoDopo(props.oggi) : ''));
const bonifico = computed(() => (compito.value ? eUnBonificoSegnalato(compito.value) : false));
const dopoLaScadenza = computed(() => (compito.value?.scadenza && giornoScelto.value && giornoScelto.value > compito.value.scadenza
    ? fraseDopoLaScadenza(compito.value.scadenza) : null));
const titolo = computed(() => {
    switch (voce.value) {
        case 'fatto': return 'Fatto';
        case 'non_serve': return bonifico.value ? 'Rifiuta la segnalazione' : 'Non serve';
        default: return 'Ricordamelo';
    }
});
const etichettaConferma = computed(() => {
    switch (voce.value) {
        case 'fatto': return 'Fatto';
        case 'non_serve': return bonifico.value ? 'Rifiuta la segnalazione' : 'Registra';
        default: return 'Ricordamelo';
    }
});

function apri(v: VoceDelCompito, c: CompitoConRisposte) {
    compito.value = c;
    voce.value = v;
    errori.value = {};
    testo.value = '';
    giornoScelto.value = domani.value;
    if (v === 'registro') {
        registroAperto.value = true;

        return;
    }
    aperta.value = true;
}

function conferma() {
    const c = compito.value;
    if (!c || !voce.value || voce.value === 'registro' || inCorso.value) return;
    const dati = voce.value === 'fatto' ? { tipo: 'fatto', nota: testo.value }
        : voce.value === 'non_serve' ? { tipo: 'non_serve', motivo: testo.value }
        : { tipo: 'ricordamelo', giorno: giornoScelto.value, nota: testo.value };
    inCorso.value = true;
    router.post(route('admin.inbox.risposta', c.id), dati, {
        preserveScroll: true,
        onSuccess: () => { aperta.value = false; },
        onError: (e: Record<string, string>) => { errori.value = e; },
        onFinish: () => { inCorso.value = false; },
    });
}

/** Con «Fatto» e «Non serve» il cursore va nel campo, e Invio conferma subito; con «Ricordamelo» no: sul telefono si aprirebbe il calendario. */
function fuoco(e: Event) {
    if (voce.value === 'ricordamelo') e.preventDefault();
}

/** Nella finestra di «Fatto» Invio conferma: il gesto resta veloce. */
function invio(e: KeyboardEvent) {
    if (voce.value !== 'fatto' || e.shiftKey) return;
    e.preventDefault();
    conferma();
}

defineExpose({ apri });
</script>

<template>
    <Dialog v-model:open="aperta">
        <DialogContent class="sm:max-w-[450px]" data-testid="finestra-risposta" @open-auto-focus="fuoco">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2" :class="{ 'text-red-600 dark:text-red-400': voce === 'non_serve' && bonifico }">
                    <CheckCircle2 v-if="voce === 'fatto'" class="h-5 w-5 text-emerald-600" />
                    <XCircle v-else-if="voce === 'non_serve' && bonifico" class="h-5 w-5" />
                    <CircleSlash v-else-if="voce === 'non_serve'" class="h-5 w-5 text-slate-500" />
                    <BellRing v-else class="h-5 w-5 text-amber-600" />
                    {{ titolo }}
                </DialogTitle>
                <DialogDescription class="text-slate-500 dark:text-slate-400">{{ compito?.title }}</DialogDescription>
            </DialogHeader>

            <div class="grid gap-3 py-2 text-sm">
                <p v-if="voce === 'fatto' && bonifico" class="text-xs text-slate-600 dark:text-slate-300">Chiude il compito. L'incasso si registra con «Registra l'incasso».</p>
                <p v-if="voce === 'non_serve' && bonifico" class="text-xs text-slate-600 dark:text-slate-300">Il motivo lo legge il condòmino nel portale.</p>
                <template v-if="voce === 'ricordamelo'">
                    <p class="text-xs text-slate-600 dark:text-slate-300">Il giorno va nell'agenda.</p>
                    <p v-if="compito?.promemoria_a_parte" class="text-xs text-slate-600 dark:text-slate-300">{{ fraseDelPromemoriaAParte(compito.type) }}</p>
                    <div class="grid gap-1.5">
                        <Label for="compito-giorno" class="text-xs">Il giorno</Label>
                        <Input id="compito-giorno" v-model="giornoScelto" type="date" class="h-8 w-44 text-xs" data-testid="giorno" :min="domani" />
                        <p v-if="dopoLaScadenza" class="text-xs text-amber-700 dark:text-amber-400" data-testid="dopo-la-scadenza">{{ dopoLaScadenza }}</p>
                    </div>
                </template>
                <div class="grid gap-1.5">
                    <Label for="compito-testo" class="text-xs">{{ voce === 'non_serve' ? 'Perché' : 'Una nota, se vuoi' }}</Label>
                    <Textarea id="compito-testo" v-model="testo" rows="3" class="min-h-[80px] resize-none text-sm" @keydown.enter="invio" />
                </div>
                <ul v-if="Object.keys(errori).length" class="list-disc pl-4 text-xs text-rose-700 dark:text-rose-300" data-testid="errori">
                    <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                </ul>
            </div>

            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" :disabled="inCorso" @click="aperta = false">Annulla</Button>
                <Button size="sm" :variant="voce === 'non_serve' && bonifico ? 'destructive' : 'default'" :disabled="inCorso" data-testid="conferma" @click="conferma">
                    <Loader2 v-if="inCorso" class="mr-1 h-4 w-4 animate-spin" />
                    {{ etichettaConferma }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="registroAperto">
        <DialogContent class="sm:max-w-[450px]" data-testid="finestra-registro">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><History class="h-5 w-5 text-slate-500" /> Il registro</DialogTitle>
                <DialogDescription class="text-slate-500 dark:text-slate-400">{{ compito?.title }}</DialogDescription>
            </DialogHeader>
            <ul class="space-y-1 py-2 text-xs text-slate-600 dark:text-slate-300">
                <li v-for="(r, i) in compito?.risposta?.registro ?? []" :key="i">
                    {{ r.quando }} · {{ r.utente }} · {{ testoDelGestoDelCompito(String(r.tipo), r.dati) }}<template v-if="r.nota">: «{{ r.nota }}»</template>
                </li>
            </ul>
            <DialogFooter>
                <Button variant="outline" size="sm" @click="registroAperto = false">Chiudi</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
