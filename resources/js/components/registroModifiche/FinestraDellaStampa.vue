<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La finestra «Stampa il registro delle modifiche» (la bozza approvata del 21/11/2026), nella forma delle finestre delle ricevute: ancorata in alto, i
 * gruppi con il titolo piccolo e grigio, il riepilogo fisso nel piede accanto ai pulsanti.
 *
 * - **Parte con i filtri della pagina** già scritti, e cambiarli qui non cambia la pagina: il mese della pagina diventa il periodo dal … al … («Questo
 *   mese» se è il mese di oggi, «Dall'inizio» per «Tutti i mesi», le date di un altro mese).
 * - **«Il periodo»**: le scorciatoie in una barra («Questo mese», «Dall'inizio», «Scegli le date»), il menu degli esercizi del condominio (solo nel
 *   registro del condominio: scelto un esercizio, «Dal» e «Al» prendono le sue date) e
 *   i due giorni con il campo del giorno del programma; un giorno cambiato a mano accende «Scegli le date». «Al» prima di «Dal» è un errore rosso sotto
 *   «Al», e allora niente conto.
 * - **«Che cosa»**: chi, su che cosa, che cosa è successo (menu `v-select`, come i filtri della pagina), il testo da cercare; nello studio anche il
 *   condominio.
 * - **Il conto**: a ogni cambio la finestra chiede al server quante righe uscirebbero e il limite di una stampa sola (gli stessi parametri della stampa;
 *   il testo da cercare dopo una breve pausa). Il piede lo dice («Stamperai 134 righe, dal 01/01/2026 al 31/12/2026, sulle assemblee.»); mentre conta,
 *   un segno piccolo nel piede e «Stampa il PDF» spento.
 * - **Oltre il limite** la riga arancio lo dice prima di stampare, con l'azione («Scegli l'esercizio 2026»), e «Stampa il PDF» resta spento.
 * - «Stampa il PDF» apre il PDF in una scheda nuova, come prima, e chiude la finestra.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { today } from '@internationalized/date';
import { RadioGroupItem } from 'reka-ui';
import { AlertTriangle, Loader2, Printer } from 'lucide-vue-next';
import { RadioGroup } from '@/components/ui/radio-group';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import CampoGiorno from '@/components/CampoGiorno.vue';
import type { SceltaDelFiltro } from '@/lib/registroModifiche/registro';
import {
    erroreDelPeriodo, etichettaDellEsercizio, FRASE_NESSUNA_RIGA, fraseOltreIlLimite, nomeDelMenuEsercizio, indirizzoConParametri, nomeDellaScorciatoia, parametriDellaStampa, periodoDi,
    periodoIniziale, riepilogoDellaStampa, scorciatoie, type ContestoDelPeriodo, type EsercizioDellaStampa, type Periodo, type Scorciatoia, type StampaDelRegistro,
} from '@/lib/registroModifiche/stampa';

const props = defineProps<{ stampa: StampaDelRegistro }>();
const aperta = defineModel<boolean>('open', { default: false });

const esercizio = computed(() => (props.stampa.delloStudio ? null : props.stampa.esercizio));
const contesto = (): ContestoDelPeriodo => ({
    oggi: today('Europe/Rome').toString(),
    esercizio: esercizio.value,
    mesi: props.stampa.scelte.mesi.map((m) => m.valore).filter((v) => /^\d{4}-\d{2}$/.test(v)),
});
const scorciatoia = ref<Scorciatoia>('mese');
// «L'esercizio» è un menu a parte, non una scorciatoia della barra.
const voci = computed(() => scorciatoie(null));
const esercizi = computed<EsercizioDellaStampa[]>(() => (props.stampa.delloStudio ? [] : props.stampa.esercizi ?? []));
/** L'esercizio di serie: quello che comprende oggi, se no l'aperto, se no il più recente. */
const esercizioDiSerie = computed(() => {
    const oggi = today('Europe/Rome').toString();

    return esercizi.value.find((e) => e.dal <= oggi && oggi <= e.al) ?? esercizi.value.find((e) => e.stato === 'aperto') ?? esercizi.value[0] ?? null;
});
const esercizioScelto = ref<number | null>(null);
const sceltaEsercizio = computed(() => (scorciatoia.value === 'esercizio' ? esercizioScelto.value : null));
const nomeEsercizioScelto = computed(() => esercizi.value.find((e) => e.id === sceltaEsercizio.value)?.nome ?? '');

const periodo = ref<Periodo>({ dal: '', al: '' });
const f = ref({ chi: '', area: '', azione: '', cerca: '', condominio: '' });
/** Il testo da cercare che il conto ha già: si aggiorna dopo una breve pausa, come «Cerca» nella pagina. */
const cercaContata = ref('');

/** L'ultimo conto arrivato, con le scelte con cui è stato chiesto: il piede dice quelle, anche mentre arriva il conto dopo. */
interface Conto { esercizio: string; righe: number; limite: number; periodo: Periodo; scorciatoia: Scorciatoia; area: string; chi: string; condominio: string; cerca: string }
const conto = ref<Conto | null>(null);
const contando = ref(false);
const erroriDelServer = ref<{ dal?: string; al?: string }>({});
const erroreDelConto = ref<string | null>(null);

/** I filtri della pagina nella finestra, e il periodo dal mese della pagina. */
function prepara() {
    const filtri = props.stampa.filtri;
    f.value = {
        chi: filtri.chi ?? '', area: filtri.area ?? '', azione: filtri.azione ?? '', cerca: filtri.cerca ?? '',
        condominio: filtri.condominio !== null && filtri.condominio !== undefined ? String(filtri.condominio) : '',
    };
    cercaContata.value = f.value.cerca;
    const iniziale = periodoIniziale(filtri.mese, contesto());
    scorciatoia.value = iniziale.scorciatoia;
    esercizioScelto.value = null;
    periodo.value = { ...iniziale.periodo };
    conto.value = null;
    erroriDelServer.value = {};
    erroreDelConto.value = null;
}

const erroreAl = computed(() => erroreDelPeriodo(periodo.value) ?? erroriDelServer.value.al ?? null);
const erroreDal = computed(() => erroriDelServer.value.dal ?? null);

/** I parametri della stampa e del conto: gli stessi, così il conto è quello delle righe del PDF. */
const parametri = computed(() => parametriDellaStampa({ ...f.value, cerca: cercaContata.value, periodo: periodo.value }));
const indirizzoDelPdf = computed(() => indirizzoConParametri(props.stampa.indirizzo, parametri.value));

let numeroDelConto = 0;
let ultimaChiesta = '';
/** Chiede il conto delle righe, se i parametri sono cambiati; una risposta arrivata dopo un altro cambio non conta. */
async function conta(forza = false) {
    const chiave = JSON.stringify(parametri.value);
    if (!forza && chiave === ultimaChiesta) return;
    ultimaChiesta = chiave;
    const numero = ++numeroDelConto;
    erroriDelServer.value = {};
    erroreDelConto.value = null;
    if (erroreDelPeriodo(periodo.value)) {
        conto.value = null;
        contando.value = false;

        return;
    }
    contando.value = true;
    const scelte = { periodo: { ...periodo.value }, scorciatoia: scorciatoia.value, area: f.value.area, chi: f.value.chi, condominio: f.value.condominio,
        cerca: cercaContata.value, esercizio: nomeEsercizioScelto.value };
    try {
        const r = await axios.get(props.stampa.conto, { params: { ...parametri.value } });
        if (numero !== numeroDelConto) return;
        conto.value = { righe: Number(r.data?.righe ?? 0), limite: Number(r.data?.limite ?? 0), ...scelte };
    } catch (e) {
        if (numero !== numeroDelConto) return;
        conto.value = null;
        const risposta = (e as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } } | null)?.response;
        const errori = risposta?.status === 422 ? risposta.data?.errors ?? {} : {};
        erroriDelServer.value = { dal: errori.dal?.[0], al: errori.al?.[0] };
        if (!errori.dal && !errori.al) {
            erroreDelConto.value = risposta?.status === 403 ? 'Non hai il permesso di stampare il registro.'
                : risposta?.status === 422 && risposta.data?.message ? risposta.data.message : 'Non riesco a contare le righe: riprova.';
        }
    } finally {
        if (numero === numeroDelConto) contando.value = false;
    }
}

watch(aperta, (v) => {
    if (!v) return;
    prepara();
    void conta(true);
}, { immediate: true });
watch(parametri, () => { if (aperta.value) void conta(); });

// Il testo da cercare entra nel conto dopo una breve pausa: una lettera per volta non fa una richiesta per lettera.
let attesa: ReturnType<typeof setTimeout> | null = null;
const cercaInAttesa = ref(false);
watch(() => f.value.cerca, (testo) => {
    if (attesa) clearTimeout(attesa);
    if (testo === cercaContata.value) {
        cercaInAttesa.value = false;

        return;
    }
    cercaInAttesa.value = true;
    attesa = setTimeout(() => {
        cercaContata.value = f.value.cerca;
        cercaInAttesa.value = false;
    }, 350);
});

function scegli(s: Scorciatoia | unknown) {
    const id = s as Scorciatoia;
    if (id === 'esercizio') return void scegliEsercizio(esercizioDiSerie.value?.id ?? null);
    scorciatoia.value = id;
    esercizioScelto.value = null;
    const nuovo = periodoDi(id, contesto());
    if (nuovo) periodo.value = nuovo;
}

/** Un esercizio dal menu: «Dal» e «Al» prendono le sue date; svuotato, resta il periodo com'è. */
function scegliEsercizio(id: number | null) {
    const e = esercizi.value.find((x) => x.id === id);
    if (!e) return;
    esercizioScelto.value = e.id;
    scorciatoia.value = 'esercizio';
    periodo.value = { dal: e.dal, al: e.al };
}

/** Un giorno cambiato a mano: il periodo è quello scelto, non più una scorciatoia. */
function cambiaGiorno(quale: 'dal' | 'al', giorno: string) {
    periodo.value = { ...periodo.value, [quale]: giorno };
    scorciatoia.value = 'date';
    esercizioScelto.value = null;
}

const inAggiornamento = computed(() => contando.value || cercaInAttesa.value);
const oltre = computed(() => !!conto.value && conto.value.righe > conto.value.limite);
const vuoto = computed(() => !!conto.value && !inAggiornamento.value && conto.value.righe === 0 && !erroreAl.value && !erroreDal.value && !erroreDelConto.value);
const pronto = computed(() => !!conto.value && !inAggiornamento.value && !oltre.value && !vuoto.value && !erroreAl.value && !erroreDal.value && !erroreDelConto.value);

const nomeDi = (elenco: SceltaDelFiltro[], valore: string) => (valore === '' ? null : elenco.find((s) => s.valore === valore)?.nome ?? null);
const riepilogo = computed(() => conto.value === null ? null : riepilogoDellaStampa({
    righe: conto.value.righe,
    periodo: conto.value.periodo,
    area: conto.value.area || null,
    condominio: props.stampa.delloStudio ? nomeDi(props.stampa.scelte.condomini, conto.value.condominio) : null,
    chi: nomeDi(props.stampa.scelte.chi, conto.value.chi),
    cerca: conto.value.cerca,
    oltre: oltre.value,
}));
const fraseOltre = computed(() => !conto.value ? '' : fraseOltreIlLimite({
    righe: conto.value.righe, limite: conto.value.limite, scorciatoia: conto.value.scorciatoia,
    nome: conto.value.scorciatoia === 'esercizio' ? conto.value.esercizio : nomeDellaScorciatoia(conto.value.scorciatoia, null), conEsercizio: esercizi.value.length > 0,
}));
/** L'azione giusta oltre il limite, nel registro del condominio: un esercizio alla volta. */
const propostaEsercizio = computed(() => (esercizioDiSerie.value && scorciatoia.value !== 'esercizio'
    ? `Scegli ${etichettaDellEsercizio({ dal: esercizioDiSerie.value.dal, al: esercizioDiSerie.value.al }).replace(/^L/, 'l')}` : null));

// La forma delle finestre: i gruppi con il titolo piccolo e grigio, separati da una riga.
const gruppo = 'space-y-3 border-b border-slate-100 py-4 last:border-b-0 dark:border-slate-800';
const titoloGruppo = 'text-xs font-semibold text-slate-500 dark:text-slate-400';
const etichetta = 'mb-1 block text-[13px] font-medium text-slate-700 dark:text-slate-300';
const erroreRosso = 'mt-1 text-sm text-red-600 dark:text-red-500';
const riquadroArancio = 'flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200';
</script>

<template>
    <Dialog v-model:open="aperta">
        <!-- La forma delle finestre: ancorata in alto (non si ricentra quando cambia altezza), il contenuto scorre dentro, il riepilogo e i pulsanti fissi
             nel piede; il conto in corso è un segno piccolo nel riepilogo, mai una riga che compare e scompare. -->
        <DialogContent class="top-[5vh] flex max-h-[90vh] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-[620px]" data-testid="finestra-stampa">
            <DialogHeader class="border-b border-slate-100 px-5 pt-4 pb-3 pr-12 text-left dark:border-slate-800">
                <DialogTitle>Stampa il registro delle modifiche</DialogTitle>
                <DialogDescription>{{ stampa.luogo }} · il PDF con la carta dello studio e l'impronta in fondo</DialogDescription>
            </DialogHeader>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 text-sm" data-testid="corpo-finestra">
                <!-- Il periodo: le scorciatoie in una barra, e i due giorni. -->
                <section :class="gruppo">
                    <h3 :class="titoloGruppo" data-testid="titolo-gruppo">Il periodo</h3>
                    <RadioGroup :model-value="scorciatoia" aria-label="Il periodo"
                        :class="['grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 dark:border-slate-700 dark:bg-slate-700', 'grid-cols-3']"
                        data-testid="scorciatoie-periodo" @update:model-value="scegli">
                        <RadioGroupItem
                            v-for="v in voci"
                            :key="v.id"
                            :value="v.id"
                            :data-acceso="scorciatoia === v.id ? 'si' : 'no'"
                            :class="[
                                'flex min-h-8 w-full cursor-pointer items-center justify-center px-2 py-1.5 text-center text-xs leading-snug transition-colors',
                                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                                scorciatoia === v.id
                                    ? 'bg-slate-900 font-semibold text-white dark:bg-white dark:text-slate-900'
                                    : 'bg-white font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900 dark:bg-slate-950 dark:text-slate-300 dark:hover:bg-slate-900',
                            ]"
                        >
                            {{ v.nome }}
                        </RadioGroupItem>
                    </RadioGroup>
                    <div v-if="esercizi.length" class="menu-campo" data-testid="stampa-esercizio">
                        <span :class="etichetta">L'esercizio</span>
                        <v-select :model-value="sceltaEsercizio" :options="esercizi" label="nome" :get-option-label="nomeDelMenuEsercizio" :reduce="(e: EsercizioDellaStampa) => e.id"
                            :clearable="false" :searchable="false" placeholder="Scegli un esercizio" class="w-full" @update:model-value="scegliEsercizio" />
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="stampa-registro-dal" :class="etichetta">Dal</label>
                            <CampoGiorno id="stampa-registro-dal" :model-value="periodo.dal" data-testid="stampa-dal" class="h-9 text-sm"
                                @update:model-value="(g: string) => cambiaGiorno('dal', g)" />
                            <p v-if="erroreDal" :class="erroreRosso" data-testid="errore-dal">{{ erroreDal }}</p>
                        </div>
                        <div>
                            <label for="stampa-registro-al" :class="etichetta">Al</label>
                            <CampoGiorno id="stampa-registro-al" :model-value="periodo.al" data-testid="stampa-al" class="h-9 text-sm"
                                @update:model-value="(g: string) => cambiaGiorno('al', g)" />
                            <p v-if="erroreAl" :class="erroreRosso" data-testid="errore-al">{{ erroreAl }}</p>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Per un'impugnazione basta il mese della delibera<template v-if="esercizio">; per il passaggio di consegne, l'esercizio intero</template>.
                    </p>
                </section>

                <!-- Che cosa: gli stessi filtri della pagina, che qui non cambiano la pagina. -->
                <section :class="gruppo">
                    <h3 :class="titoloGruppo" data-testid="titolo-gruppo">Che cosa</h3>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div v-if="stampa.delloStudio" class="menu-campo sm:col-span-2" data-testid="stampa-condominio">
                            <span :class="etichetta">Condominio</span>
                            <v-select v-model="f.condominio" :options="stampa.scelte.condomini" label="nome" :reduce="(s: SceltaDelFiltro) => s.valore" :clearable="false"
                                :searchable="stampa.scelte.condomini.length > 8" class="w-full" />
                        </div>
                        <div class="menu-campo" data-testid="stampa-chi">
                            <span :class="etichetta">Chi</span>
                            <v-select v-model="f.chi" :options="stampa.scelte.chi" label="nome" :reduce="(s: SceltaDelFiltro) => s.valore" :clearable="false"
                                :searchable="stampa.scelte.chi.length > 8" class="w-full" />
                        </div>
                        <div class="menu-campo" data-testid="stampa-area">
                            <span :class="etichetta">Su che cosa</span>
                            <v-select v-model="f.area" :options="stampa.scelte.aree" label="nome" :reduce="(s: SceltaDelFiltro) => s.valore" :clearable="false"
                                :searchable="false" class="w-full" />
                        </div>
                        <div class="menu-campo" data-testid="stampa-azione">
                            <span :class="etichetta">Che cosa è successo</span>
                            <v-select v-model="f.azione" :options="stampa.scelte.azioni" label="nome" :reduce="(s: SceltaDelFiltro) => s.valore" :clearable="false"
                                :searchable="false" class="w-full" />
                        </div>
                        <label class="block">
                            <span :class="etichetta">Cerca nel testo</span>
                            <Input v-model="f.cerca" type="search" placeholder="per esempio «delega»" maxlength="100" class="h-8 text-sm" data-testid="stampa-cerca" />
                        </label>
                    </div>
                </section>

                <!-- Oltre il limite di una stampa sola: prima di stampare, con l'azione giusta. -->
                <div v-if="oltre" class="py-4">
                    <div :class="riquadroArancio" role="alert" data-testid="oltre-il-limite">
                        <AlertTriangle class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <div class="space-y-2">
                            <p>{{ fraseOltre }}</p>
                            <button v-if="propostaEsercizio" type="button"
                                class="inline-flex h-7 items-center rounded-md border border-amber-300 bg-white px-2.5 text-xs font-medium text-amber-900 hover:bg-amber-100 dark:border-amber-800 dark:bg-slate-900 dark:text-amber-200 dark:hover:bg-slate-800"
                                @click="scegli('esercizio')">
                                {{ propostaEsercizio }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Il piede fisso: quante righe usciranno a sinistra, i pulsanti a destra (vanno sotto il riepilogo quando non ci stanno accanto). -->
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 border-t border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/60" data-testid="piede-finestra">
                <p class="flex min-w-[200px] flex-1 items-center gap-2 text-[13px] text-slate-600 dark:text-slate-300" role="status" data-testid="riepilogo-stampa">
                    <span v-if="inAggiornamento" class="inline-flex shrink-0" data-testid="aggiorno"><Loader2 class="h-3.5 w-3.5 animate-spin" aria-hidden="true" /></span>
                    <Printer v-else class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span class="min-w-0">
                        <template v-if="erroreAl || erroreDal">Correggi il periodo per sapere quante righe usciranno.</template>
                        <template v-else-if="erroreDelConto">{{ erroreDelConto }}</template>
                        <template v-else-if="riepilogo">{{ riepilogo.prima }}<b class="font-semibold text-slate-900 dark:text-white">{{ riepilogo.forte }}</b>{{ riepilogo.dopo }}</template>
                        <template v-else-if="inAggiornamento">Conto le righe…</template>
                        <span v-if="vuoto" class="mt-0.5 block text-xs text-muted-foreground" data-testid="nessuna-riga">{{ FRASE_NESSUNA_RIGA }}</span>
                    </span>
                </p>
                <div class="ml-auto flex flex-wrap justify-end gap-2">
                    <Button variant="outline" @click="aperta = false">Annulla</Button>
                    <Button v-if="pronto" as-child>
                        <a :href="indirizzoDelPdf" target="_blank" rel="noopener" data-testid="stampa-il-pdf" @click="aperta = false">
                            <Printer class="mr-2 h-4 w-4" /> Stampa il PDF
                        </a>
                    </Button>
                    <Button v-else disabled data-testid="stampa-il-pdf"><Printer class="mr-2 h-4 w-4" /> Stampa il PDF</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
