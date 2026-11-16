<script setup lang="ts">
/**
 * Le finestre delle risposte ai sei mesi e ai 180 giorni, una sola istanza nella dashboard del condominio: il riquadro e il compito dell'Inbox la aprono con
 * `apri()`. «Registra che hai agito» (una persona), «Fatto» (le persone spuntate, o il rendiconto con una nota se vuoi), «Non serve» con il motivo,
 * «Ricordamelo il…» (il giorno va nell'agenda), «Mettilo in agenda» per il rendiconto. Le scrive il server; i rifiuti arrivano con le loro frasi.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { BellRing, CalendarPlus, CheckCircle2, CircleSlash, Loader2 } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { euroTesto } from '@/lib/gestionale/debiti/rateScadute';
import { domani as giornoDopo } from '@/lib/gestionale/lettere/lettere';
import SeiMesiFattoDialog from './SeiMesiFattoDialog.vue';
import { annoDi, testoDopoLaData, type RichiestaDiRisposta } from '@/lib/gestionale/debiti/seiMesiPerAgire';

const props = defineProps<{ condominioId: number; oggi: string }>();

const { generateRoute } = usePermission();
const richiesta = ref<RichiestaDiRisposta | null>(null);
const aperta = ref(false);
const giornoScelto = ref('');
const testo = ref('');
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);

// Il giorno dopo il giorno dell'amministratore, che arriva dal server: non il giorno del browser.
const domani = computed(() => (props.oggi ? giornoDopo(props.oggi) : ''));
const anno = computed(() => (richiesta.value ? annoDi(richiesta.value.esercizio.nome) : ''));
const diChe = computed(() => (richiesta.value?.argomento === 'rendiconto' ? `rendiconto ${anno.value}` : `esercizio ${anno.value}`));
const dopoLaData = computed(() => richiesta.value?.argomento === 'sei_mesi' && giornoScelto.value && giornoScelto.value > richiesta.value.data
    ? testoDopoLaData(giornoScelto.value, richiesta.value.data) : null);
const indirizzoDispense = computed(() => route(generateRoute('gestionale.dispense.index'), { condominio: props.condominioId }));

/** Apre la finestra della risposta; «Riapri» non ha una finestra: si manda e basta. */
function apri(r: RichiestaDiRisposta) {
    richiesta.value = r;
    errori.value = {};
    testo.value = '';
    giornoScelto.value = r.tipo === 'ricordamelo' ? domani.value : r.tipo === 'in_agenda' ? (r.data >= props.oggi ? r.data : props.oggi) : props.oggi;
    if (r.tipo === 'riapri') {
        manda({ anagrafica_id: r.persona?.anagrafica_id ?? null });

        return;
    }
    aperta.value = true;
}

function manda(dati: Record<string, unknown>) {
    const r = richiesta.value;
    if (!r) return;
    inCorso.value = true;
    router.post(route(generateRoute('gestionale.sei-mesi.risposta'), { condominio: props.condominioId }), {
        tipo: r.tipo, argomento: r.argomento, esercizio_id: r.esercizio.id, ...dati,
    }, {
        preserveScroll: true,
        onSuccess: () => { aperta.value = false; },
        onError: (e: Record<string, string>) => { errori.value = e; },
        onFinish: () => { inCorso.value = false; },
    });
}

function conferma() {
    const r = richiesta.value;
    if (!r) return;
    switch (r.tipo) {
        case 'agito':
            manda({ anagrafica_id: r.persona?.anagrafica_id, giorno: giornoScelto.value, nota: testo.value });
            break;
        case 'non_serve':
            manda({ motivo: testo.value });
            break;
        case 'ricordamelo':
        case 'in_agenda':
            manda({ giorno: giornoScelto.value, nota: testo.value });
            break;
        case 'fatto':
            manda({ nota: testo.value });
            break;
    }
}

defineExpose({ apri });
</script>

<template>
    <SeiMesiFattoDialog
        v-if="richiesta && richiesta.tipo === 'fatto' && richiesta.argomento === 'sei_mesi'"
        v-model:open="aperta"
        :esercizio="richiesta.esercizio"
        :persone="richiesta.persone ?? []"
        :oggi="oggi"
        :errori="errori"
        :in-corso="inCorso"
        @registra="(d) => manda(d)"
    />
    <Dialog v-else-if="richiesta" v-model:open="aperta">
        <DialogContent class="sm:max-w-[450px]" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <template v-if="richiesta.tipo === 'agito'"><CheckCircle2 class="h-5 w-5 text-emerald-600" /> Registra che hai agito</template>
                    <template v-else-if="richiesta.tipo === 'fatto'"><CheckCircle2 class="h-5 w-5 text-emerald-600" /> Fatto: {{ diChe }}</template>
                    <template v-else-if="richiesta.tipo === 'non_serve'"><CircleSlash class="h-5 w-5 text-slate-500" /> Non serve: {{ diChe }}</template>
                    <template v-else-if="richiesta.tipo === 'ricordamelo'"><BellRing class="h-5 w-5 text-amber-600" /> Ricordamelo</template>
                    <template v-else><CalendarPlus class="h-5 w-5 text-emerald-600" /> Mettilo in agenda</template>
                </DialogTitle>
                <DialogDescription>
                    <template v-if="richiesta.tipo === 'agito' && richiesta.persona">
                        {{ richiesta.persona.nome }} · esercizio {{ anno }} · {{ euroTesto(richiesta.persona.cifra) }} di questo esercizio
                    </template>
                    <template v-else-if="richiesta.tipo === 'non_serve'">Il compito si chiude. La riga resta nel riquadro, in grigio, con il motivo: si riapre da lì.</template>
                    <template v-else-if="richiesta.tipo === 'ricordamelo'">Il giorno va nell'agenda del condominio.</template>
                    <template v-else-if="richiesta.tipo === 'in_agenda'">Convocare l'assemblea per il rendiconto {{ anno }}: il giorno va nell'agenda del condominio.</template>
                    <template v-else>Chiude il compito del rendiconto {{ anno }}.</template>
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-3 py-2 text-sm">
                <div v-if="richiesta.tipo !== 'non_serve' && richiesta.tipo !== 'fatto'" class="grid gap-1.5">
                    <Label for="risposta-giorno" class="text-xs">Il giorno</Label>
                    <Input id="risposta-giorno" v-model="giornoScelto" type="date" class="h-8 w-44 text-xs" data-testid="giorno"
                        :max="richiesta.tipo === 'agito' ? oggi : undefined" :min="richiesta.tipo === 'ricordamelo' ? domani : richiesta.tipo === 'in_agenda' ? oggi : undefined" />
                    <p v-if="dopoLaData && richiesta.tipo === 'ricordamelo'" class="text-xs text-amber-700 dark:text-amber-400" data-testid="dopo-la-data">{{ dopoLaData }}</p>
                </div>
                <div class="grid gap-1.5">
                    <Label for="risposta-testo" class="text-xs">
                        {{ richiesta.tipo === 'agito' ? 'Che cosa hai fatto' : richiesta.tipo === 'non_serve' ? 'Perché' : 'Una nota, se vuoi' }}
                    </Label>
                    <Textarea id="risposta-testo" v-model="testo" rows="3" class="min-h-[80px] resize-none text-sm"
                        :placeholder="richiesta.tipo === 'agito' ? 'Per esempio: pratica data all\'avv. Gallo; ricorso depositato il 12/03/2026.' : ''" />
                </div>
                <p v-if="richiesta.tipo === 'agito' && richiesta.persona" class="text-xs text-slate-600 dark:text-slate-300">
                    {{ richiesta.persona.nome }} esce dal riquadro per l'esercizio {{ anno }}. La riga resta nel registro. Torna se le rate di questo esercizio salgono.
                </p>
                <p v-if="richiesta.tipo === 'non_serve' && richiesta.argomento === 'sei_mesi'" class="text-xs text-slate-600 dark:text-slate-300">
                    Se l'assemblea ha deciso di non agire, registra la dispensa: è un'altra cosa.
                    <Link :href="indirizzoDispense" class="font-medium text-primary hover:underline">Registra la dispensa dell'assemblea</Link>
                </p>
                <p v-if="richiesta.tipo === 'fatto'" class="text-xs text-slate-600 dark:text-slate-300">Il registro tiene il giorno, chi e la nota.</p>
                <ul v-if="Object.keys(errori).length" class="list-disc pl-4 text-xs text-rose-700 dark:text-rose-300" data-testid="errori">
                    <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                </ul>
            </div>

            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" :disabled="inCorso" @click="aperta = false">Annulla</Button>
                <Button size="sm" :disabled="inCorso" data-testid="conferma" @click="conferma">
                    <Loader2 v-if="inCorso" class="mr-1 h-4 w-4 animate-spin" />
                    {{ richiesta.tipo === 'ricordamelo' ? 'Ricordamelo' : richiesta.tipo === 'in_agenda' ? 'Mettilo in agenda' : richiesta.tipo === 'fatto' ? 'Fatto' : 'Registra' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
