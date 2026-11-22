<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Segna impugnata» (scena 3 della bozza del verbale): un segno sulla delibera con il giorno, la nota e l'atto, che non ferma niente (art. 1137 c.c.:
 * l'azione non sospende l'esecuzione).
 */
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import CampoFile from '@/components/CampoFile.vue';
import CampoGiorno from '@/components/CampoGiorno.vue';
import InputError from '@/components/InputError.vue';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { AIUTO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA } from './finestra';

const props = defineProps<{ open: boolean; punto: number | null; rotta: string }>();
const emit = defineEmits<{ 'update:open': [v: boolean] }>();
const oggi = giornoDiRoma();
const giorno = ref(oggi);
const nota = ref('');
const atto = ref<File | null>(null);
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
watch(() => props.open, (a) => { if (a) { giorno.value = oggi; nota.value = ''; atto.value = null; errori.value = {}; } });
function segna() {
    inCorso.value = true;
    router.post(props.rotta, { giorno: giorno.value, nota: nota.value.trim(), ...(atto.value ? { atto: atto.value } : {}) } as never, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errori.value = e as Record<string, string>; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-impugnata">
            <DialogHeader :class="TESTA">
                <DialogTitle>Segna impugnata la delibera del punto {{ punto }}</DialogTitle>
                <DialogDescription>È un segno sulla delibera, con la data e l'atto: non ferma niente.</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <Label class="text-xs">Il giorno</Label>
                    <CampoGiorno v-model="giorno" :max="oggi" class="h-9 text-sm" aria-label="Il giorno dell'impugnazione" />
                    <InputError :message="errori.giorno" />
                    <Label for="nota-impugnata" class="text-xs">La nota</Label>
                    <Textarea id="nota-impugnata" v-model="nota" rows="2" class="min-h-[60px] resize-none text-sm" placeholder="Per esempio: citazione di Rossi Mario, Tribunale di Milano." />
                    <InputError :message="errori.nota" />
                    <Label class="text-xs">L'atto (facoltativo)</Label>
                    <CampoFile v-model="atto" accept=".pdf,.p7m,.jpg,.jpeg,.png" testid="atto-impugnata" />
                    <p :class="AIUTO">L'azione non sospende l'esecuzione della delibera (art. 1137 c.c.).</p>
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" /><span>Il segno resta nel registro delle modifiche.</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="!nota.trim() || !giorno || inCorso" data-testid="conferma-impugnata" @click="segna">Segna impugnata</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
