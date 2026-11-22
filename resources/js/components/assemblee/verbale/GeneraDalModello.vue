<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Genera il verbale dal modello» e «Rigenera dal modello» (scena 2 della bozza delle scene del verbale): il modello (il menu raggruppa «Per il
 * condominio», «Per tutti i condomini», «Del programma»; propone quello del condominio, se c'è, se no l'ultimo usato), che cosa entra nel verbale, e per
 * «Rigenera» che il testo di adesso resta fra le versioni.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { AIUTO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA, TITOLO_GRUPPO } from './finestra';
import type { PaginaDelVerbale } from '@/types/verbale';

const props = defineProps<{ open: boolean; pagina: PaginaDelVerbale; sedutaId: number; rigenera: boolean; discussioni: string; versione: number | null }>();
const emit = defineEmits<{ 'update:open': [v: boolean] }>();

const GRUPPI = { condominio: 'Per questo condominio', tutti: 'Per tutti i condomini', programma: 'Del programma' } as const;
const opzioni = computed(() => props.pagina.modelli.modelli.map((m) => ({ ...m, chiave: m.id ?? 0, etichetta: `${m.nome} · ${GRUPPI[m.gruppo]}${m.ultimo_usato ? ' · l\'ultimo usato' : ''}` })));
const scelto = ref<number>(0);
watch(() => props.open, (a) => { if (a) scelto.value = props.pagina.verbale?.modello?.id ?? props.pagina.modelli.proposto ?? props.pagina.modelli.ultimo_usato ?? 0; },
    { immediate: true });
const nome = computed(() => opzioni.value.find((o) => o.chiave === scelto.value)?.nome ?? 'Testo del programma');
const inCorso = ref(false);
const errore = ref('');

function genera() {
    inCorso.value = true;
    errore.value = '';
    router.post(props.pagina.rotte.genera, { seduta_id: props.sedutaId, modello_id: scelto.value || null }, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errore.value = Object.values(e)[0] ?? 'Il verbale non si è generato.'; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-genera" @open-auto-focus.prevent>
            <DialogHeader :class="TESTA">
                <DialogTitle>{{ rigenera ? 'Rigenerare il verbale?' : 'Genera il verbale dal modello' }}</DialogTitle>
                <DialogDescription>{{ pagina.assemblea.titolo }}</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Il modello</p>
                    <v-select v-model="scelto" :options="opzioni" label="etichetta" :reduce="(o: { chiave: number }) => o.chiave" :clearable="false"
                        :searchable="opzioni.length > 8" data-testid="modello" />
                    <p :class="AIUTO">Propongo il modello del condominio, se c'è; se no l'ultimo usato dallo studio. Il testo del programma non si modifica: si duplica nella pagina dei modelli.</p>
                </section>
                <section v-if="rigenera" :class="GRUPPO" data-testid="rigenera">
                    <p :class="TITOLO_GRUPPO">Che cosa succede</p>
                    <ul class="list-disc space-y-1 pl-5 text-xs text-slate-700 dark:text-slate-300">
                        <li>Il testo di adesso, con le tue correzioni<template v-if="versione"> (versione {{ versione }})</template>, resta fra le versioni: lo ritrovi con «Ripristina».</li>
                        <li>Le parti con i dati della sala si rifanno da quelli di adesso.</li>
                        <li>Le discussioni tornano quelle scritte in sala e nella pagina.</li>
                    </ul>
                </section>
                <section v-else :class="GRUPPO" data-testid="che-cosa-entra">
                    <p :class="TITOLO_GRUPPO">Che cosa entra nel verbale</p>
                    <ul class="list-disc space-y-1 pl-5 text-xs text-slate-700 dark:text-slate-300">
                        <li>La costituzione e la convocazione: dati della sala.</li>
                        <li>Per ogni punto il titolo, la discussione ({{ discussioni }}), i voti con nomi e millesimi, chi non ha votato, il conflitto, l'esito: dati della sala.</li>
                        <li>Le dichiarazioni a verbale e le note del presidente.</li>
                        <li>L'elenco dei presenti in calce, con le deleghe.</li>
                        <li>Il resto del testo viene dal modello: lo correggi nella pagina.</li>
                    </ul>
                    <p :class="AIUTO">Il modello si cambia anche dopo, con «Rigenera dal modello».</p>
                </section>
                <p v-if="errore" class="pb-3 text-sm text-red-600 dark:text-red-500">{{ errore }}</p>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status">
                    <Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
                    <span>Con il modello «{{ nome }}».</span>
                </p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="inCorso" data-testid="conferma-genera" @click="genera">{{ rigenera ? 'Rigenera' : 'Genera il verbale' }}</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
