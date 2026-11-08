<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { giornoItaliano } from '@/lib/giorno';
import { vocePropostaPer, vociDellaGestione, type PropostaCommissione, type VoceCommissione } from '@/lib/gestionale/pagamenti/voceCommissione';
import { AlertTriangle, Landmark, ListChecks } from 'lucide-vue-next';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Breadcrumb } from '@/components/PageHeaderGuide.vue';
import type { Flash } from '@/types/flash';

/**
 * Le commissioni dei pagamenti scritte senza voce in un esercizio (`CommissioniSenzaVoce::perEsercizio`), e «Assegna la voce» in blocco.
 *
 * Una gestione alla volta (la voce è di una gestione): si sceglie la gestione, si scelgono le commissioni (o tutte quelle della gestione), si
 * sceglie la voce (proposta dall'ultima commissione della gestione), si guarda l'anteprima e si conferma. La pagina manda gli id scelti: il
 * server li ricontrolla e, se nel frattempo una ha già la voce, rifiuta tutta la richiesta. In un esercizio chiuso le commissioni si vedono e non
 * si toccano. Gli importi sono in centesimi; le date arrivano `aaaa-mm-gg` e si scrivono qui.
 */

interface RigaCommissione { riga_id: number; scrittura_id: number; protocollo: string | null; data: string | null; importo: number; tipo: 'commissione' | 'storno' }
interface Commissione {
    id: number;
    righe: RigaCommissione[];
    fornitore: string | null;
    gestione_id: number | null;
    gestione: string | null;
    importo: number;
    assegnabile: boolean;
    motivo: string | null;
}

const props = defineProps<{
    condominio: { id: number; nome: string };
    condomini: any[];
    esercizio: { id: number; nome: string; stato: string; aperto: boolean };
    commissioni: Commissione[];
    totale: { numero: number; importo: number };
    frase_chiuso: string | null;
    voci: VoceCommissione[];
    proposte: PropostaCommissione[];
}>();

const { generateRoute, generatePath } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash }; errors: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);
const errori = computed(() => page.props.errors ?? {});

const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Pagamenti', href: generatePath('gestionale/:condominio/pagamenti-fornitori', { condominio: props.condominio.id }) },
    { title: 'Commissioni senza voce' },
]);

// Le gestioni che hanno commissioni nell'elenco, nell'ordine in cui compaiono.
const gestioni = computed(() => {
    const viste = new Map<number, string>();
    for (const c of props.commissioni) {
        if (c.gestione_id !== null && !viste.has(c.gestione_id)) viste.set(c.gestione_id, c.gestione ?? `Gestione ${c.gestione_id}`);
    }
    return [...viste].map(([id, nome]) => ({ id, nome }));
});

const gestioneScelta = ref<number | null>(gestioni.value[0]?.id ?? null);
const visibili = computed(() => props.commissioni.filter((c) => c.gestione_id === gestioneScelta.value));
const assegnabili = computed(() => visibili.value.filter((c) => c.assegnabile));
const scelte = ref<number[]>([]);
const voceId = ref<number | null>(null);
const vociDellaScelta = computed(() => vociDellaGestione(props.voci, gestioneScelta.value));
const mostraAnteprima = ref(false);
const invioInCorso = ref(false);

// Cambiando gestione si riparte: nessuna commissione scelta, la voce proposta della nuova gestione.
watch(gestioneScelta, (gestione) => {
    scelte.value = [];
    voceId.value = vocePropostaPer(props.proposte, props.voci, gestione);
    mostraAnteprima.value = false;
}, { immediate: true });

const tutteScelte = computed(() => assegnabili.value.length > 0 && assegnabili.value.every((c) => scelte.value.includes(c.id)));
const scegliTutte = () => {
    scelte.value = tutteScelte.value ? [] : assegnabili.value.map((c) => c.id);
};

const daAssegnare = computed(() => assegnabili.value.filter((c) => scelte.value.includes(c.id)));
const importoScelto = computed(() => daAssegnare.value.reduce((somma, c) => somma + c.importo, 0));
const voceScelta = computed(() => props.voci.find((v) => v.id === voceId.value) ?? null);
const puoAssegnare = computed(() => props.esercizio.aperto && daAssegnare.value.length > 0 && voceScelta.value !== null);

const conferma = () => {
    if (!puoAssegnare.value || invioInCorso.value) return;
    invioInCorso.value = true;
    router.post(
        route(generateRoute('gestionale.esercizi.commissioni-senza-voce.assegna'), { condominio: props.condominio.id, esercizio: props.esercizio.id }),
        { commissioni: daAssegnare.value.map((c) => c.id), voce_id: voceId.value },
        {
            preserveScroll: true,
            onSuccess: () => { scelte.value = []; mostraAnteprima.value = false; },
            onFinish: () => { invioInCorso.value = false; },
        },
    );
};
</script>

<template>
    <Head title="Commissioni senza voce" />

    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                page-title="Commissioni senza voce"
                :page-subtitle="`Esercizio ${props.esercizio.nome}: le commissioni dei pagamenti registrate prima che avessero una voce.`"
                :guides="[]"
                :breadcrumbs="breadcrumbs"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            />

            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700 space-y-1.5 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                <p>
                    Queste commissioni sono sulle «Spese bancarie» di sistema, senza una voce: stanno nei costi dell'esercizio ma restano fuori dal
                    consuntivo delle voci. Assegnando una voce, la commissione entra nel consuntivo di quella voce.
                </p>
                <p class="text-slate-500">
                    Una commissione e il suo storno prendono la voce insieme. Le commissioni nuove la voce l'hanno già: si sceglie nel pagamento.
                </p>
            </div>

            <div v-if="props.frase_chiuso" data-testo="chiuso" class="flex items-start gap-2 rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                <Landmark class="mt-0.5 h-4 w-4 shrink-0" />
                <span>{{ props.frase_chiuso }}</span>
            </div>

            <p v-if="props.commissioni.length === 0" data-testo="vuoto" class="rounded-xl border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-500">
                In questo esercizio nessuna commissione è registrata senza voce.
            </p>

            <template v-else>
                <div class="flex flex-wrap items-end gap-4">
                    <div v-if="gestioni.length > 1" class="space-y-1.5 min-w-56">
                        <Label for="gestione">Gestione</Label>
                        <v-select id="gestione" v-model="gestioneScelta" :options="gestioni" :reduce="(g: any) => g.id" label="nome" :clearable="false" />
                    </div>
                    <p class="text-sm text-slate-600" data-testo="totale">
                        {{ props.totale.numero === 1 ? '1 commissione' : `${props.totale.numero} commissioni` }} senza voce, per {{ euro(props.totale.importo) }}.
                    </p>
                </div>

                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500 dark:bg-slate-900">
                            <tr>
                                <th class="px-3 py-2 w-10">
                                    <input v-if="props.esercizio.aperto && assegnabili.length > 0" type="checkbox" :checked="tutteScelte" @change="scegliTutte"
                                           :aria-label="`Scegli tutte le ${assegnabili.length}`" />
                                </th>
                                <th class="px-3 py-2">Data</th>
                                <th class="px-3 py-2">Protocollo</th>
                                <th class="px-3 py-2">Fornitore</th>
                                <th class="px-3 py-2 text-right">Importo</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in visibili" :key="c.id" data-riga="commissione" class="border-t border-slate-100 align-top dark:border-slate-800">
                                <td class="px-3 py-2">
                                    <input v-if="c.assegnabile" v-model="scelte" type="checkbox" :value="c.id" :aria-label="`Scegli la commissione ${c.righe[0].protocollo ?? c.id}`" />
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <div v-for="r in c.righe" :key="r.riga_id">{{ giornoItaliano(r.data) }}</div>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <div v-for="r in c.righe" :key="r.riga_id">{{ r.protocollo ?? '—' }}<span v-if="r.tipo === 'storno'" class="text-slate-500"> (storno)</span></div>
                                </td>
                                <td class="px-3 py-2">{{ c.fornitore ?? '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ euro(c.importo) }}</td>
                                <td class="px-3 py-2 text-xs text-slate-500">{{ c.motivo ?? '' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="props.esercizio.aperto" class="rounded-xl border border-slate-200 bg-white p-4 space-y-3 dark:border-slate-800 dark:bg-slate-950">
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="space-y-1.5 min-w-64">
                            <Label for="voce">Voce da assegnare</Label>
                            <v-select id="voce" v-model="voceId" :options="vociDellaScelta" :reduce="(v: VoceCommissione) => v.id" label="label"
                                      placeholder="Su quale voce di spesa vanno le commissioni?" />
                        </div>
                        <Button :disabled="!puoAssegnare" @click="mostraAnteprima = true">
                            <ListChecks class="h-4 w-4 mr-1.5" /> Assegna la voce
                        </Button>
                    </div>
                    <p v-if="errori.voce_id" class="text-sm font-medium text-rose-600">{{ errori.voce_id }}</p>
                    <p v-if="errori.commissioni" class="text-sm font-medium text-rose-600">{{ errori.commissioni }}</p>

                    <div v-if="mostraAnteprima && puoAssegnare" data-blocco="anteprima" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 space-y-2">
                        <p class="flex items-start gap-2">
                            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                {{ daAssegnare.length === 1 ? '1 commissione' : `${daAssegnare.length} commissioni` }} per {{ euro(importoScelto) }}
                                prendono la voce «{{ voceScelta!.label }}»: il consuntivo della voce cambia di {{ euro(importoScelto) }} nell'esercizio {{ props.esercizio.nome }}.
                            </span>
                        </p>
                        <div class="flex gap-2">
                            <Button size="sm" :disabled="invioInCorso" @click="conferma">{{ invioInCorso ? 'Assegnazione…' : 'Conferma' }}</Button>
                            <Button size="sm" variant="outline" @click="mostraAnteprima = false">Annulla</Button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </GestionaleLayout>
</template>
