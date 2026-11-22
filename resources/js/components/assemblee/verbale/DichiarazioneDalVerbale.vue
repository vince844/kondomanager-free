<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Aggiungi una dichiarazione» dalla pagina del verbale durante l'assemblea (scena 1 della bozza delle scene): lo stesso gesto della sala, con il nome di chi
 * la fa; va anche nella sala, sotto il voto. Brevi dichiarazioni (art. 1130 n. 7 c.c.): niente dati delicati, perché il verbale lo leggono tutti.
 */
import { ref, watch } from 'vue';
import axios from 'axios';
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { AIUTO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA } from './finestra';
import { nuovoNumero } from '@/lib/senzaRete/numero';

const props = defineProps<{ open: boolean; punto: { punto_id: number; numero: number } | null; sedutaId: number | null; rotta: string; dispositivo: string }>();
const emit = defineEmits<{ 'update:open': [v: boolean]; fatta: [] }>();
const chi = ref('');
const testo = ref('');
const errore = ref('');
const inCorso = ref(false);
watch(() => props.open, (a) => { if (a) { chi.value = ''; testo.value = ''; errore.value = ''; } });

async function salva() {
    if (!props.punto || props.sedutaId === null || !chi.value.trim() || !testo.value.trim()) return;
    inCorso.value = true;
    errore.value = '';
    const adesso = new Date().toISOString();
    try {
        // Il numero è un UUID anche dove il browser non ha `crypto.randomUUID` (il server dell'ufficio su http): il server lo vuole così.
        const r = await axios.post(props.rotta, { dispositivo: props.dispositivo, gesti: [{ gesto: nuovoNumero(), tipo: 'dichiarazione',
            dati: { seduta_id: props.sedutaId, punto_id: props.punto.punto_id, nome: chi.value.trim(), testo: testo.value.trim() }, orologio: adesso, segnato_il: adesso }] });
        const risposta = r.data?.risposte?.[0];
        if (risposta?.esito === 'rifiutato') { errore.value = risposta.messaggio ?? 'La dichiarazione non è stata accettata.'; return; }
        emit('fatta');
        emit('update:open', false);
    } catch {
        errore.value = 'La dichiarazione non è partita: controlla la rete e riprova.';
    } finally {
        inCorso.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-dichiarazione-verbale">
            <DialogHeader :class="TESTA">
                <DialogTitle>Dichiarazione a verbale, punto {{ punto?.numero }}</DialogTitle>
                <DialogDescription>Una breve dichiarazione, che il verbale riporta con il nome di chi la fa (art. 1130 n. 7 c.c.).</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <Label for="chi-dichiara-verbale" class="text-xs">Chi la fa</Label>
                    <Input id="chi-dichiara-verbale" v-model="chi" class="h-9 text-sm" data-testid="chi-dichiara-verbale" />
                    <Label for="testo-dichiara-verbale" class="text-xs">La dichiarazione</Label>
                    <Textarea id="testo-dichiara-verbale" v-model="testo" rows="3" class="min-h-[72px] resize-none text-sm" data-testid="testo-dichiara-verbale" />
                    <InputError :message="errore" />
                    <p :class="AIUTO">Va anche nella sala, sotto il voto. Niente dati delicati: il verbale lo leggono tutti.</p>
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" /><span>Con il nome di chi la fa.</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="!chi.trim() || !testo.trim() || inCorso" data-testid="salva-dichiarazione-verbale" @click="salva">Salva la dichiarazione</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
