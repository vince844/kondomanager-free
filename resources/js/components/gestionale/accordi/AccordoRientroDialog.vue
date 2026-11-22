<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'accordo di rientro di una persona, dalla sua riga dell'elenco «Rate scadute» e dal suo estratto conto: gli accordi che ha (con il segno delle due
 * cifre, il calendario con quanto è versato di ogni rata, la regola di decadenza e i suoi segnali, l'autorizzazione, la nota, la scansione, la chiusura e
 * la decadenza registrata) e il modulo per registrarne uno. Le rate coperte si scelgono fra le scadute della persona il giorno dell'accordo (gli
 * interessi e le spese registrati anche quel giorno), con l'importo riconosciuto proposto dal residuo di quel giorno. Il calendario si propone dal
 * numero delle rate e dal giorno della prima (rate uguali, l'ultima con i centesimi, ogni mese) e si cambia riga per riga; la somma deve fare il debito
 * riconosciuto. La regola di decadenza proposta è quella dell'ultimo accordo del condominio. Sulla parte della rata fuori dall'accordo gli interessi
 * continuano, di serie, o restano sospesi finché l'accordo regge (decisione 450): la scelta è nel menu, con la «i» e un esempio, e la scheda dell'accordo
 * la dice; per un accordo non chiuso si cambia anche dopo, dalla scheda («Cambia la scelta sulla parte fuori»), e il registro dell'accordo dice chi l'ha
 * cambiata e quando. «Autorizzato dall'assemblea» non ha una risposta già scelta.
 * Un accordo alla volta: è un atto con una persona. Non si cancella: si chiude.
 *
 * Le rate si rileggono quando cambia la data dell'accordo: una risposta arrivata dopo un altro cambio della data si scarta.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import CampoFile from '@/components/CampoFile.vue';
import CalendarioDellAccordo from '@/components/gestionale/accordi/CalendarioDellAccordo.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Info } from 'lucide-vue-next';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import ScegliLaDelibera from '@/components/assemblee/ScegliLaDelibera.vue';
import PulsanteDaPortareInAssemblea from '@/components/assemblee/PulsanteDaPortareInAssemblea.vue';
import EsitiDellaScheda from '@/components/assemblee/seguito/EsitiDellaScheda.vue';
import { gestisceLeAssemblee } from '@/lib/assemblee/permessi';
import type { DeliberaDelMenu } from '@/types/assemblee';
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';
import { centesimiDaImporto } from '@/lib/gestionale/pianiRate/totaliPerTipo';
import {
    ESEMPIO_PARTE_FUORI, FRASE_AUTORIZZAZIONE, FRASE_CALENDARIO, FRASE_CALENDARIO_IN_ORDINE, FRASE_CAMBIA_PARTE_FUORI, FRASE_DECADENZA, FRASE_IMPORTO, FRASE_NON_SCADUTE, FRASE_PARTE_FUORI,
    FRASE_REGISTRO, FRASE_REGOLA, FRASE_REGOLA_DI_SERIE, SCELTE_PARTE_FUORI, proponiCalendario, sommaCheNonTorna, testoAutorizzazione, testoParteFuori,
    testoSegnoAccordo, type AccordoRientro, type InteressiParteFuori, type RegolaDiDecadenza,
} from '@/lib/gestionale/debiti/accordiRientro';

interface RataPossibile { rata_id: number; immobile_id: number | null; numero_rata: number; piano: string; unita: string | null; scadenza: string; residuo: number; causale?: string | null }
interface AccordoDellaPersona extends AccordoRientro {
    in_corso: boolean;
    documento: string | null;
    rate: Array<{ rata_id: number; immobile_id: number | null; riconosciuto: number; unita: string | null; piano: string; numero_rata: number; scadenza: string | null; causale?: string | null }>;
    /** La delibera che autorizza l'accordo, se è collegata (ruolo «autorizzazione»). */
    delibera?: DeliberaDelMenu | null;
}

const props = defineProps<{ open: boolean; condominioId: number; persona: { id: number; nome: string } | null; oggi: string }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const permessi = usePermission();
const { generateRoute } = permessi;
// Con «Gestisce le assemblee» l'autorizzazione viene dalla delibera scelta (§1.4 del progetto della tappa 2); senza, la data si scrive a mano come prima.
const conLaDelibera = computed(() => gestisceLeAssemblee(permessi));
const rotta = (nome: string, altro: Record<string, unknown> = {}) => route(generateRoute(`gestionale.accordi-rientro.${nome}`), { condominio: props.condominioId, ...altro });

const accordi = ref<AccordoDellaPersona[]>([]);
const condominioNome = ref('');
const possibili = ref<RataPossibile[]>([]);
const errore = ref<string | null>(null);
const esito = ref<string | null>(null);
const inCorso = ref(false);
let numeroLettura = 0;

const chiave = (r: { rata_id: number; immobile_id: number | null }) => `${r.rata_id}|${r.immobile_id ?? ''}`;
const centesimiInEuro = (c: number) => (c / 100).toFixed(2).replace('.', ',');

/** Il modulo vuoto: niente è già scelto, salvo la data di oggi e la regola dell'ultimo accordo del condominio (messa quando arriva). */
const vuoto = (data: string) => ({
    data_accordo: data,
    scelte: {} as Record<string, boolean>,
    importi: {} as Record<string, string>,
    numero: '',
    prima: '',
    calendario: [] as Array<{ data: string; importo: string }>,
    decadenza_rate: '',
    decadenza_giorni: '',
    // Decisione 450: il di serie, gli interessi continuano sulla parte della rata fuori dall'accordo.
    interessi_parte_fuori: 'continuano' as InteressiParteFuori,
    autorizzato: null as null | '0' | '1',
    data_autorizzazione: '',
    verbale_autorizzazione: '',
    delibera: null as DeliberaDelMenu | null,
    nota: '',
});
const modulo = ref(vuoto(props.oggi));
const regolaDiSerie = ref<RegolaDiDecadenza | null>(null);
let regolaProposta = false;
const file = ref<File | null>(null);
const chiusura = ref<Record<number, { chiuso_il: string; nota_chiusura: string }>>({});
const decadenza = ref<Record<number, { data: string; nota: string }>>({});
const autorizzazione = ref<Record<number, { data_autorizzazione: string; verbale_autorizzazione: string; delibera: DeliberaDelMenu | null }>>({});
const parteFuori = ref<Record<number, InteressiParteFuori>>({});

async function leggi(data?: string) {
    if (!props.persona) {
        return;
    }
    const numero = ++numeroLettura;
    errore.value = null;
    try {
        const r = (await axios.get(rotta('index'), { params: { anagrafica_id: props.persona.id, data: data ?? modulo.value.data_accordo } })).data;
        // Arrivata dopo un altro cambio della data, o di persona: non è la lettura di quello che c'è a video.
        if (numero !== numeroLettura || r.persona?.id !== props.persona?.id) {
            return;
        }
        accordi.value = r.accordi;
        condominioNome.value = r.condominio ?? '';
        possibili.value = r.rate;
        modulo.value.scelte = {};
        modulo.value.importi = Object.fromEntries(r.rate.map((p: RataPossibile) => [chiave(p), centesimiInEuro(p.residuo)]));
        // La regola dell'ultimo accordo del condominio, una volta per apertura: poi la cambia l'amministratore.
        regolaDiSerie.value = r.regola_di_serie ?? null;
        if (!regolaProposta) {
            regolaProposta = true;
            if (r.regola_di_serie) {
                modulo.value.decadenza_rate = r.regola_di_serie.rate != null ? String(r.regola_di_serie.rate) : '';
                modulo.value.decadenza_giorni = r.regola_di_serie.giorni != null ? String(r.regola_di_serie.giorni) : '';
            }
        }
    } catch (e) {
        if (numero === numeroLettura) {
            errore.value = frasePerErrore(e, 'Gli accordi non si sono caricati. Riprova.');
        }
    }
}

watch(() => props.open, (aperta) => {
    if (!aperta) {
        return;
    }
    modulo.value = vuoto(props.oggi);
    regolaProposta = false;
    regolaDiSerie.value = null;
    file.value = null;
    esito.value = null;
    accordi.value = [];
    possibili.value = [];
    leggi(props.oggi);
}, { immediate: true });

watch(() => modulo.value.data_accordo, (data, prima) => {
    if (props.open && data && data !== prima) {
        leggi(data);
    }
});

/**
 * Le rate che sono già in un accordo di rientro in corso (Coda 678, 1): chiave della rata => la ragione, detta con il giorno dell'accordo. La regola è quella
 * del server («Una rata in un accordo solo»): conta ogni accordo della persona non chiuso, o chiuso dopo il giorno del nuovo. Il server la ricontrolla
 * all'invio; qui la finestra non offre una rata che poi rifiuterebbe.
 */
const giaInUnAccordo = computed(() => {
    const ragioni: Record<string, string> = {};
    for (const a of accordi.value) {
        if (a.chiuso_il !== null && a.chiuso_il <= modulo.value.data_accordo) {
            continue;
        }
        for (const r of a.rate) {
            ragioni[chiave(r)] ??= a.chiuso_il === null
                ? `È già nell'accordo del ${giorno(a.data_accordo)}, ancora in corso: chiudilo prima.`
                : `È già nell'accordo del ${giorno(a.data_accordo)}, chiuso il ${giorno(a.chiuso_il)}: scegli una data da quel giorno in poi.`;
        }
    }

    return ragioni;
});

const scelte = computed(() => possibili.value.filter((p) => modulo.value.scelte[chiave(p)] && !giaInUnAccordo.value[chiave(p)]));

/** Il debito riconosciuto: la somma degli importi delle rate scelte, in centesimi. */
const riconosciuto = computed(() => scelte.value.reduce((s, p) => s + centesimiDaImporto(modulo.value.importi[chiave(p)]), 0));

/** La proposta del calendario: si rifà quando cambiano il debito, il numero delle rate o il giorno della prima; le righe cambiate a mano restano fino ad allora. */
watch([riconosciuto, () => modulo.value.numero, () => modulo.value.prima], () => {
    const n = Number.parseInt(String(modulo.value.numero), 10);
    modulo.value.calendario = proponiCalendario(riconosciuto.value, Number.isFinite(n) ? n : 0, modulo.value.prima)
        .map((r) => ({ data: r.data, importo: centesimiInEuro(r.importo) }));
});

/** Che cosa non torna nel calendario: i giorni in ordine dal giorno dell'accordo e ogni rata più di zero, poi la somma. Null se torna. */
const controlloCalendario = computed<string | null>(() => {
    const righe = modulo.value.calendario;
    if (!righe.length) {
        return null;
    }
    const inOrdine = righe.every((r, i) => !!r.data && r.data >= modulo.value.data_accordo && (i === 0 || r.data > righe[i - 1].data)
        && centesimiDaImporto(r.importo) > 0);

    return inOrdine ? sommaCheNonTorna(righe.map((r) => centesimiDaImporto(r.importo)), riconosciuto.value) : FRASE_CALENDARIO_IN_ORDINE;
});
const ultimaRata = computed(() => modulo.value.calendario[modulo.value.calendario.length - 1]?.data ?? null);

const pronto = computed(() => scelte.value.length > 0 && modulo.value.calendario.length > 0 && controlloCalendario.value === null && modulo.value.autorizzato !== null
    && (modulo.value.autorizzato === '0' || (conLaDelibera.value ? modulo.value.delibera !== null : !!modulo.value.data_autorizzazione)));

async function registra() {
    errore.value = null;
    esito.value = null;
    inCorso.value = true;
    const corpo = new FormData();
    corpo.append('anagrafica_id', String(props.persona?.id ?? ''));
    corpo.append('data_accordo', modulo.value.data_accordo);
    modulo.value.calendario.forEach((r, i) => {
        corpo.append(`scadenze[${i}][data]`, r.data);
        corpo.append(`scadenze[${i}][importo]`, r.importo);
    });
    if (String(modulo.value.decadenza_rate).trim() !== '') {
        corpo.append('decadenza_rate', String(modulo.value.decadenza_rate).trim());
    }
    if (String(modulo.value.decadenza_giorni).trim() !== '') {
        corpo.append('decadenza_giorni', String(modulo.value.decadenza_giorni).trim());
    }
    corpo.append('interessi_parte_fuori', modulo.value.interessi_parte_fuori);
    scelte.value.forEach((p, i) => {
        corpo.append(`rate[${i}][rata_id]`, String(p.rata_id));
        if (p.immobile_id !== null) {
            corpo.append(`rate[${i}][immobile_id]`, String(p.immobile_id));
        }
        corpo.append(`rate[${i}][importo]`, modulo.value.importi[chiave(p)] ?? '');
    });
    if (modulo.value.autorizzato !== null) {
        corpo.append('autorizzato_assemblea', modulo.value.autorizzato);
    }
    if (modulo.value.autorizzato === '1' && modulo.value.delibera) {
        // La data e il verbale li prende il server dalla delibera scelta.
        corpo.append('delibera_id', String(modulo.value.delibera.id));
    } else if (modulo.value.autorizzato === '1') {
        corpo.append('data_autorizzazione', modulo.value.data_autorizzazione);
        corpo.append('verbale_autorizzazione', modulo.value.verbale_autorizzazione);
    }
    if (modulo.value.nota.trim() !== '') {
        corpo.append('nota', modulo.value.nota.trim());
    }
    if (file.value) {
        corpo.append('documento', file.value);
    }
    try {
        const r = (await axios.post(rotta('store'), corpo)).data;
        esito.value = r.messaggio;
        modulo.value = { ...vuoto(modulo.value.data_accordo), decadenza_rate: modulo.value.decadenza_rate, decadenza_giorni: modulo.value.decadenza_giorni };
        file.value = null;
        emit('fatto');
        await leggi();
    } catch (e) {
        errore.value = frasePerErrore(e, 'L\'accordo non è stato registrato. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

async function chiudi(a: AccordoDellaPersona) {
    errore.value = null;
    const c = chiusura.value[a.id];
    try {
        const r = (await axios.post(rotta('chiudi', { accordo: a.id }), { chiuso_il: c?.chiuso_il, nota_chiusura: c?.nota_chiusura || null })).data;
        esito.value = r.messaggio;
        emit('fatto');
        await leggi();
    } catch (e) {
        errore.value = frasePerErrore(e, 'L\'accordo non è stato chiuso. Riprova.');
    }
}

async function autorizza(a: AccordoDellaPersona) {
    errore.value = null;
    const v = autorizzazione.value[a.id];
    try {
        const r = (await axios.post(rotta('autorizza', { accordo: a.id }), v?.delibera
            ? { delibera_id: v.delibera.id }
            : { data_autorizzazione: v?.data_autorizzazione, verbale_autorizzazione: v?.verbale_autorizzazione || null })).data;
        esito.value = r.messaggio;
        await leggi();
    } catch (e) {
        errore.value = frasePerErrore(e, 'L\'autorizzazione non è stata registrata. Riprova.');
    }
}

async function registraDecadenza(a: AccordoDellaPersona) {
    errore.value = null;
    const d = decadenza.value[a.id];
    try {
        const r = (await axios.post(rotta('decadenza', { accordo: a.id }), { data: d?.data, nota: d?.nota || null })).data;
        esito.value = r.messaggio;
        emit('fatto');
        await leggi();
    } catch (e) {
        errore.value = frasePerErrore(e, 'La decadenza non è stata registrata. Riprova.');
    }
}

/** La scelta sulla parte fuori cambiata dopo la registrazione (decisione 450): solo la proposta degli interessi la segue. */
async function cambiaParteFuori(a: AccordoDellaPersona) {
    errore.value = null;
    try {
        const r = (await axios.post(rotta('parte-fuori', { accordo: a.id }), { interessi_parte_fuori: parteFuori.value[a.id] })).data;
        esito.value = r.messaggio;
        const { [a.id]: _tolto, ...altri } = parteFuori.value;
        parteFuori.value = altri;
        emit('fatto');
        await leggi();
    } catch (e) {
        errore.value = frasePerErrore(e, 'La scelta non è stata cambiata. Riprova.');
    }
}

const valoreDellaScelta = (s: { valore: InteressiParteFuori }) => s.valore;
const apriParteFuori = (a: AccordoDellaPersona) => { parteFuori.value = { ...parteFuori.value, [a.id]: a.interessi_parte_fuori ?? 'continuano' }; };
const apriChiusura = (a: AccordoDellaPersona) => { chiusura.value = { ...chiusura.value, [a.id]: { chiuso_il: props.oggi, nota_chiusura: '' } }; };
const apriDecadenza = (a: AccordoDellaPersona) => { decadenza.value = { ...decadenza.value, [a.id]: { data: props.oggi, nota: '' } }; };
const apriAutorizzazione = (a: AccordoDellaPersona) => { autorizzazione.value = { ...autorizzazione.value, [a.id]: { data_autorizzazione: '', verbale_autorizzazione: '', delibera: null } }; };
const nomeRata = (r: { numero_rata: number; piano: string; unita: string | null; causale?: string | null }) => r.causale
    ? `${r.unita ? `${r.unita}, ` : ''}${r.causale}`
    : `${r.unita ? `${r.unita}, ` : ''}${r.numero_rata === 0 ? 'rata 0 (pregresso)' : `rata ${r.numero_rata}`}${r.piano ? ` — ${r.piano}` : ''}`;
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Accordo di rientro{{ persona ? ` di ${persona.nome}` : '' }}</DialogTitle>
                <DialogDescription>{{ FRASE_REGISTRO }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-4 text-sm">
                <!-- Gli accordi che la persona ha -->
                <div v-if="accordi.length" class="space-y-2" data-testid="accordi">
                    <div v-for="a in accordi" :key="a.id" class="rounded-lg border p-2 space-y-1 text-xs" data-testid="accordo">
                        <p class="font-medium">{{ testoSegnoAccordo(a) }}</p>
                        <p>{{ testoAutorizzazione(a) }}</p>
                        <p v-if="a.delibera" class="text-slate-600 dark:text-slate-300" data-testid="delibera-dell-accordo">Dalla {{ a.delibera.frase.charAt(0).toLowerCase() + a.delibera.frase.slice(1) }}</p>
                        <p v-if="a.nota" class="text-slate-600 dark:text-slate-300">{{ a.nota }}</p>
                        <ul class="list-disc pl-4">
                            <li v-for="r in a.rate" :key="`${r.rata_id}-${r.immobile_id}`">{{ nomeRata(r) }}: riconosciuti {{ euroTesto(r.riconosciuto) }}</li>
                        </ul>
                        <p data-testid="parte-fuori-scelta">{{ testoParteFuori(a.interessi_parte_fuori) }}</p>
                        <p v-for="(r, i) in a.registro ?? []" :key="`registro-${i}`" class="text-slate-500" data-testid="registro-accordo">{{ r }}</p>
                        <CalendarioDellAccordo :accordo="a" />
                        <p v-if="a.documento">
                            <a :href="rotta('documento', { accordo: a.id })" target="_blank" rel="noopener" class="underline">{{ a.documento }}</a>
                        </p>
                        <!-- L'accordo con il calendario, stampato per la persona. -->
                        <p v-if="a.calendario">
                            <a :href="rotta('stampa', { accordo: a.id })" target="_blank" rel="noopener" class="underline" data-testid="stampa-accordo">Stampa l'accordo</a>
                        </p>
                        <!-- Gli esiti delle delibere sull'accordo: «Autorizzazione respinta dall'assemblea …», i segni; con «Apri l'assemblea». -->
                        <EsitiDellaScheda :condominio-id="condominioId" tipo="accordo_rientro" :id="a.id" />
                        <p v-if="a.decadenza_registrata_il" data-testid="decadenza-registrata">Decadenza registrata il {{ giorno(a.decadenza_registrata_il) }}{{ a.nota_chiusura ? `: ${a.nota_chiusura}` : '.' }}</p>
                        <p v-else-if="a.chiuso_il" data-testid="chiuso">Chiuso il {{ giorno(a.chiuso_il) }}{{ a.nota_chiusura ? `: ${a.nota_chiusura}` : '.' }}</p>
                        <div v-else class="flex flex-wrap gap-2">
                            <Button v-if="!a.autorizzato_assemblea && !autorizzazione[a.id]" size="sm" variant="ghost" class="h-7 text-xs whitespace-nowrap" @click="apriAutorizzazione(a)">Registra l'autorizzazione dell'assemblea</Button>
                            <PulsanteDaPortareInAssemblea v-if="conLaDelibera && !a.autorizzato_assemblea" :condominio-id="condominioId" origine-tipo="accordo_rientro" :origine-id="a.id" forma="riga" />
                            <Button v-if="!parteFuori[a.id]" size="sm" variant="ghost" class="h-7 text-xs whitespace-nowrap" data-testid="apri-parte-fuori" @click="apriParteFuori(a)">Cambia la scelta sulla parte fuori</Button>
                            <Button v-if="!chiusura[a.id] && !decadenza[a.id]" size="sm" variant="ghost" class="h-7 text-xs whitespace-nowrap" data-testid="apri-chiusura" @click="apriChiusura(a)">Chiudi l'accordo</Button>
                            <Button v-if="!decadenza[a.id] && !chiusura[a.id]" size="sm" variant="ghost" class="h-7 text-xs whitespace-nowrap" data-testid="apri-decadenza" @click="apriDecadenza(a)">Registra la decadenza</Button>
                        </div>
                        <div v-if="decadenza[a.id] && !a.chiuso_il" class="space-y-1" data-testid="modulo-decadenza">
                            <p class="text-slate-500">{{ FRASE_DECADENZA }}</p>
                            <div class="flex flex-wrap items-end gap-2">
                                <label class="space-y-1"><span class="block text-slate-500">Decadenza dal</span>
                                    <Input v-model="decadenza[a.id].data" type="date" :min="a.data_accordo" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                                <label class="space-y-1"><span class="block text-slate-500">Nota</span>
                                    <Input v-model="decadenza[a.id].nota" class="h-8 w-[220px] text-xs" /></label>
                                <Button size="sm" variant="outline" class="h-8 text-xs" :disabled="!decadenza[a.id].data" data-testid="conferma-decadenza" @click="registraDecadenza(a)">Conferma la decadenza</Button>
                            </div>
                        </div>
                        <div v-if="autorizzazione[a.id] && !a.autorizzato_assemblea && conLaDelibera" class="space-y-1.5" data-testid="autorizza-con-la-delibera">
                            <ScegliLaDelibera v-model="autorizzazione[a.id].delibera" :condominio-id="condominioId" :condominio-nome="condominioNome" ruolo="autorizzazione"
                                oggetto-tipo="accordo_rientro" :oggetto-id="a.id" per-questo="questo accordo" :vuoto="`Nessuna assemblea di ${condominioNome} ha ancora autorizzato questo accordo.`" />
                            <Button size="sm" variant="outline" class="h-8 text-xs" :disabled="!autorizzazione[a.id].delibera" @click="autorizza(a)">Registra</Button>
                        </div>
                        <div v-if="autorizzazione[a.id] && !a.autorizzato_assemblea && !conLaDelibera" class="flex flex-wrap items-end gap-2">
                            <label class="space-y-1"><span class="block text-slate-500">Data della delibera</span>
                                <Input v-model="autorizzazione[a.id].data_autorizzazione" type="date" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                            <label class="space-y-1"><span class="block text-slate-500">Verbale n.</span>
                                <Input v-model="autorizzazione[a.id].verbale_autorizzazione" class="h-8 w-[100px] text-xs" /></label>
                            <Button size="sm" variant="outline" class="h-8 text-xs" :disabled="!autorizzazione[a.id].data_autorizzazione" @click="autorizza(a)">Registra</Button>
                        </div>
                        <div v-if="parteFuori[a.id] && !a.chiuso_il" class="space-y-1" data-testid="modulo-parte-fuori">
                            <p class="flex items-center gap-1.5 text-slate-500">
                                Sulla parte della rata fuori dall'accordo
                                <TooltipProvider :delay-duration="200">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span tabindex="0" role="img" :aria-label="ESEMPIO_PARTE_FUORI" data-testid="info-cambia-parte-fuori"
                                                class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                                <Info class="w-3.5 h-3.5" />
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ ESEMPIO_PARTE_FUORI }}</p></TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="menu-parte-fuori w-[300px] max-w-full">
                                    <v-select
                                        v-model="parteFuori[a.id]"
                                        :options="SCELTE_PARTE_FUORI"
                                        label="nome"
                                        :reduce="valoreDellaScelta"
                                        :searchable="false"
                                        :clearable="false"
                                        :input-id="`parte-fuori-${a.id}`"
                                        class="w-full"
                                    />
                                </div>
                                <Button size="sm" variant="outline" class="h-8 text-xs" :disabled="parteFuori[a.id] === (a.interessi_parte_fuori ?? 'continuano')"
                                    data-testid="salva-parte-fuori" @click="cambiaParteFuori(a)">Salva la scelta</Button>
                            </div>
                            <p class="text-slate-500">{{ FRASE_CAMBIA_PARTE_FUORI }}</p>
                        </div>
                        <div v-if="chiusura[a.id] && !a.chiuso_il" class="flex flex-wrap items-end gap-2" data-testid="modulo-chiusura">
                            <label class="space-y-1"><span class="block text-slate-500">Chiuso il</span>
                                <Input v-model="chiusura[a.id].chiuso_il" type="date" :min="a.data_accordo" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                            <label class="space-y-1"><span class="block text-slate-500">Nota</span>
                                <Input v-model="chiusura[a.id].nota_chiusura" class="h-8 w-[220px] text-xs" /></label>
                            <Button size="sm" variant="outline" class="h-8 text-xs" :disabled="!chiusura[a.id].chiuso_il" data-testid="chiudi" @click="chiudi(a)">Chiudi</Button>
                        </div>
                    </div>
                </div>

                <!-- Un accordo nuovo -->
                <fieldset class="space-y-3 rounded-lg border p-3" data-testid="modulo-accordo">
                    <legend class="px-1 text-xs font-medium">Registra un accordo</legend>
                    <div class="flex flex-wrap gap-3 text-xs">
                        <label class="space-y-1"><span class="block text-slate-500">Data dell'accordo</span>
                            <Input v-model="modulo.data_accordo" type="date" :max="oggi" class="h-8 w-[150px] text-xs" data-testid="data-accordo" /></label>
                    </div>

                    <div class="space-y-1 text-xs">
                        <p class="text-slate-500">Le rate che può coprire: quelle già scadute al {{ giorno(modulo.data_accordo) }}, e gli interessi e le spese registrati fino a quel giorno, con il residuo di quel giorno. {{ FRASE_IMPORTO }}</p>
                        <p class="text-slate-500" data-testid="frase-non-scadute">{{ FRASE_NON_SCADUTE }}</p>
                        <p v-if="!possibili.length" data-testid="nessuna-rata">Il {{ giorno(modulo.data_accordo) }} {{ persona?.nome }} non ha rate scadute da coprire.</p>
                        <div v-for="p in possibili" :key="chiave(p)" class="flex flex-wrap items-center gap-2" :class="{ 'opacity-60': giaInUnAccordo[chiave(p)] }" data-testid="rata-possibile">
                            <Checkbox :model-value="!!modulo.scelte[chiave(p)] && !giaInUnAccordo[chiave(p)]" :disabled="!!giaInUnAccordo[chiave(p)]"
                                @update:model-value="(v: any) => (modulo.scelte = { ...modulo.scelte, [chiave(p)]: !!v })" />
                            <span class="w-64">{{ nomeRata(p) }}, scaduta il {{ giorno(p.scadenza) }}: residuo {{ euroTesto(p.residuo) }}</span>
                            <Input v-model="modulo.importi[chiave(p)]" inputmode="decimal" :disabled="!modulo.scelte[chiave(p)] || !!giaInUnAccordo[chiave(p)]" class="h-7 w-[110px] text-xs" aria-label="Importo riconosciuto" />
                            <span v-if="giaInUnAccordo[chiave(p)]" class="basis-full pl-6 text-slate-600 dark:text-slate-300" data-testid="rata-gia-in-accordo">{{ giaInUnAccordo[chiave(p)] }}</span>
                        </div>
                    </div>

                    <fieldset class="space-y-2 text-xs" data-testid="calendario">
                        <legend class="text-slate-500">Il calendario</legend>
                        <p>Il debito riconosciuto: <strong>{{ euroTesto(riconosciuto) }}</strong>, la somma delle rate scelte sopra.</p>
                        <div class="flex flex-wrap items-end gap-3">
                            <label class="space-y-1"><span class="block text-slate-500">Numero di rate</span>
                                <Input v-model="modulo.numero" type="number" min="1" max="120" class="h-8 w-[90px] text-xs" data-testid="numero-rate" /></label>
                            <label class="space-y-1"><span class="block text-slate-500">La prima il</span>
                                <Input v-model="modulo.prima" type="date" :min="modulo.data_accordo" class="h-8 w-[150px] text-xs" data-testid="prima-rata" /></label>
                            <span class="pb-2 text-slate-500">poi ogni mese</span>
                        </div>
                        <p class="text-slate-500">{{ FRASE_CALENDARIO }}</p>
                        <div v-for="(r, i) in modulo.calendario" :key="i" class="flex flex-wrap items-center gap-2" data-testid="rata-del-calendario">
                            <span class="w-14 text-slate-500">Rata {{ i + 1 }}</span>
                            <Input v-model="r.data" type="date" :min="modulo.data_accordo" class="h-7 w-[150px] text-xs" :aria-label="`Il giorno della rata ${i + 1}`" />
                            <Input v-model="r.importo" inputmode="decimal" class="h-7 w-[110px] text-xs" :aria-label="`L'importo della rata ${i + 1}`" />
                        </div>
                        <p v-if="ultimaRata && !controlloCalendario" class="text-slate-500" data-testid="fino-al">Fino al {{ giorno(ultimaRata) }}: l'ultima rata del calendario.</p>
                        <p v-if="controlloCalendario" class="text-red-700 dark:text-red-400" data-testid="controllo-calendario">{{ controlloCalendario }}</p>
                    </fieldset>

                    <fieldset class="space-y-1 text-xs" data-testid="regola">
                        <legend class="text-slate-500">La regola di decadenza</legend>
                        <label class="flex flex-wrap items-center gap-2">
                            <Input v-model="modulo.decadenza_rate" type="number" min="1" class="h-7 w-[70px] text-xs" data-testid="decadenza-rate" />
                            <span>rate del calendario non versate, anche non consecutive</span>
                        </label>
                        <label class="flex flex-wrap items-center gap-2">
                            <Input v-model="modulo.decadenza_giorni" type="number" min="0" max="365" class="h-7 w-[70px] text-xs" data-testid="decadenza-giorni" />
                            <span>giorni dopo una rata non versata</span>
                        </label>
                        <p class="text-slate-500">{{ FRASE_REGOLA }}</p>
                        <p v-if="regolaDiSerie" class="text-slate-500" data-testid="regola-di-serie">{{ FRASE_REGOLA_DI_SERIE }}</p>
                    </fieldset>

                    <fieldset class="space-y-1 text-xs" data-testid="parte-fuori">
                        <legend class="flex items-center gap-1.5 text-slate-500">
                            Sulla parte della rata fuori dall'accordo
                            <TooltipProvider :delay-duration="200">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span tabindex="0" role="img" :aria-label="ESEMPIO_PARTE_FUORI" data-testid="info-parte-fuori"
                                            class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                            <Info class="w-3.5 h-3.5" />
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ ESEMPIO_PARTE_FUORI }}</p></TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </legend>
                        <div class="menu-parte-fuori w-[300px] max-w-full">
                            <v-select
                                v-model="modulo.interessi_parte_fuori"
                                :options="SCELTE_PARTE_FUORI"
                                label="nome"
                                :reduce="(s: { valore: InteressiParteFuori }) => s.valore"
                                :searchable="false"
                                :clearable="false"
                                input-id="parte-fuori"
                                class="w-full"
                            />
                        </div>
                        <p class="text-slate-500">{{ FRASE_PARTE_FUORI }}</p>
                    </fieldset>

                    <fieldset class="space-y-1 text-xs">
                        <legend class="text-slate-500">Autorizzato dall'assemblea</legend>
                        <div class="flex gap-3">
                            <label class="flex items-center gap-1" data-testid="autorizzato-si"><input v-model="modulo.autorizzato" type="radio" name="autorizzato" value="1" /> Sì</label>
                            <label class="flex items-center gap-1" data-testid="autorizzato-no"><input v-model="modulo.autorizzato" type="radio" name="autorizzato" value="0" /> No</label>
                        </div>
                        <p class="text-slate-500">{{ FRASE_AUTORIZZAZIONE }}</p>
                        <div v-if="modulo.autorizzato === '1' && conLaDelibera" class="space-y-1" data-testid="accordo-con-la-delibera">
                            <ScegliLaDelibera v-model="modulo.delibera" :condominio-id="condominioId" :condominio-nome="condominioNome" ruolo="autorizzazione"
                                :parla-di="persona ? [['anagrafica', persona.id]] : []" per-questo="questo accordo" />
                            <p class="text-slate-500">Data della delibera: <strong v-if="modulo.delibera" class="text-slate-800 dark:text-slate-200">{{ giorno(modulo.delibera.deliberata_il) }}</strong><span v-else>dalla delibera</span>; il verbale è quello della riunione.</p>
                        </div>
                        <div v-if="modulo.autorizzato === '1' && !conLaDelibera" class="flex flex-wrap gap-3">
                            <label class="space-y-1"><span class="block text-slate-500">Data della delibera</span>
                                <Input v-model="modulo.data_autorizzazione" type="date" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                            <label class="space-y-1"><span class="block text-slate-500">Verbale n.</span>
                                <Input v-model="modulo.verbale_autorizzazione" class="h-8 w-[100px] text-xs" /></label>
                        </div>
                    </fieldset>

                    <label class="block space-y-1 text-xs"><span class="block text-slate-500">Nota</span>
                        <Textarea v-model="modulo.nota" rows="2" /></label>
                    <div class="block space-y-1 text-xs"><span class="block text-slate-500">L'accordo firmato (PDF o immagine, fino a 10 MB)</span>
                        <CampoFile v-model="file" accept=".pdf,.jpg,.jpeg,.png" testid="file-accordo" /></div>

                    <Button size="sm" :disabled="inCorso || !pronto" data-testid="registra-accordo" @click="registra">Registra l'accordo</Button>
                </fieldset>

                <p v-if="esito" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="esito">{{ esito }}</p>
                <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-testid="errore">{{ errore }}</p>
            </div>
        </DialogContent>
    </Dialog>
</template>

<style>
/* Il menu della parte fuori dall'accordo: alto come i campi della finestra, con il loro testo, leggibile nel tema scuro; come il menu dell'anno del tasso
   legale e quelli delle ricevute. */
.menu-parte-fuori .v-select {
    --vs-font-size: 0.75rem;
    --vs-line-height: 1.25;
    --vs-border-radius: 0.375rem;
    --vs-border-color: var(--input);
    --vs-controls-color: rgb(100 116 139);
    background: transparent;
}
.menu-parte-fuori .vs__dropdown-toggle {
    min-height: 2rem;
    padding: 0;
    align-items: center;
}
.menu-parte-fuori .vs__selected-options {
    align-items: center;
    padding-left: 8px;
}
.menu-parte-fuori .vs__selected,
.menu-parte-fuori .vs__search {
    margin: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
}
.menu-parte-fuori .vs__actions {
    padding: 0 8px 0 4px;
}
.menu-parte-fuori .vs__open-indicator {
    transform: scale(0.75);
}
.menu-parte-fuori .vs--open .vs__open-indicator {
    transform: rotate(180deg) scale(0.75);
}
.dark .menu-parte-fuori .v-select {
    --vs-dropdown-bg: rgb(15 23 42);
    --vs-dropdown-color: rgb(226 232 240);
    --vs-dropdown-option-color: rgb(226 232 240);
    --vs-selected-color: rgb(226 232 240);
    --vs-search-input-color: rgb(226 232 240);
    --vs-controls-color: rgb(148 163 184);
}
.dark .menu-parte-fuori .vs__dropdown-toggle {
    background: color-mix(in oklab, var(--input) 30%, transparent);
}
.dark .menu-parte-fuori .vs__dropdown-option--highlight {
    background: rgb(30 41 59) !important;
}
</style>
