<script setup lang="ts">
/**
 * «Invia per email», accanto a «Scarica i fogli» nel riquadro del foglio di ogni persona della scheda «Conguaglio», con le stesse scelte delle
 * persone (`chi`: tutte, con il conguaglio a debito, con un indirizzo; `pagina`: solo quelle della pagina). La finestra: l'oggetto (l'ultimo usato
 * nel condominio) e il testo dell'email, che parte dal testo del programma o da un modello dello studio come la finestra delle lettere (il menu, il
 * modello ricordato, «Salva come modello…» e «Aggiorna» per chi gestisce le impostazioni di stampa); i campi si riempiono per ogni persona; in fondo a
 * ogni email, fuori dal testo, la frase della proposta e l'ora del calcolo. Gli indirizzi (la primaria e la secondaria proposte, la PEC se si
 * spunta). Poi l'anteprima di chi riceve che cosa: gli indirizzi di ogni persona, chi non ne ha uno e il suo foglio da scaricare, chi ha un foglio con
 * una parte «non disponibile», il messaggio della prima persona, l'ora del calcolo, il tetto contato sulle persone rimaste; «togli» una persona.
 * «Invia» manda quelle che partono con questo clic; dopo un invio riuscito il pulsante si spegne fino a una nuova anteprima, e «Invia le altre» manda
 * chi resta oltre il tetto. «Rimanda i fogli non partiti, con le cifre di oggi» li rifà. Dopo un invio o un rinvio la scheda rilegge dal server lo
 * stato degli invii ({@link rileggiStato}); i fogli in coda da più di un'ora si dicono, con che cosa fare.
 *
 * Non usa il router di Inertia (il mock della pagina in `Conguaglio.test.ts` non lo ha): lo stato si rilegge con una richiesta sua, leggera.
 */
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Mail } from 'lucide-vue-next';
import { AVVISO_PEC, TIPI_INDIRIZZO, frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { FRASE_MODELLI_SENZA_PERMESSO, modelliDelTipo, testoAggiorna, testoAggiornato, testoSalvato, type ModelloDellaFinestra, type ModelloRicordato } from '@/lib/gestionale/lettere/modelli';
import {
    CON_LE_CIFRE_DI_OGGI, FRASE_CAMPI, FRASE_CONSERVATO, FRASE_FATTO, chiaveDegliInvii, email, fraseDellErroreDiInvio, fraseFissa, partonoOra, persone as quantePersone,
    rileggiStato, statoCorrente, testoDelRinvio, testoEsito, testoInCodaDaTempo, testoTetto, testoUltimoInvio, type PersonaDellAnteprima, type Scelta,
} from '@/lib/gestionale/rendiconto/invioDeiFogli';

const props = defineProps<{
    condominioId: number;
    esercizioId: number;
    /** Le persone scelte nel riquadro, e la pagina se sono solo quelle della pagina. */
    chi: Scelta;
    pagina: number | null;
    /** Quante sono, per il pulsante. */
    quante: number;
}>();

const TIPO = 'foglio_conguaglio';
const { generateRoute } = usePermission();
const page = usePage<{
    invio_fogli?: { da_rimandare?: number; in_coda_da_tempo?: number };
    conguaglio?: { righe?: Array<{ anagrafica_id: number; subtotale: boolean }> };
}>();
const base = () => ({ condominio: props.condominioId, esercizio: props.esercizioId });
const rotta = (nome = '', altro: Record<string, unknown> = {}) =>
    route(generateRoute(`gestionale.esercizi.rendiconto.conguaglio.invio${nome ? `.${nome}` : ''}`), { ...base(), ...altro });
const rottaModelli = (nome: string, altro: Record<string, unknown> = {}) => route(generateRoute(`gestionale.lettere.modelli.${nome}`), { condominio: props.condominioId, ...altro });
const hrefFoglio = (id: number) => route(generateRoute('gestionale.esercizi.rendiconto.conguaglio.foglio'), { ...base(), persona: id });

interface Opzioni {
    oggetto: string; oggetto_di_serie: string; testo_di_serie: string; indirizzi: string[]; senza_server_posta: boolean; tetto_messaggi: number | null; tetto_fogli: number;
    modelli: ModelloDellaFinestra[]; modello_ricordato: ModelloRicordato | null; puo_salvare_il_testo: boolean; frase_proposta: string;
}
interface Anteprima {
    persone: PersonaDellAnteprima[]; messaggi: number; senza_indirizzo: string[]; non_disponibili?: number; partono: { persone: number; messaggi: number };
    tetto_messaggi: number | null; tetto_fogli: number; data: { frase: string; calcolato_il: string | null };
    esempio: { persona: string; oggetto: string; saluto: string; righe: string[]; chiusura: string } | null; senza_server_posta: boolean;
}

const aperta = ref(false);
const opzioni = ref<Opzioni | null>(null);
const oggetto = ref('');
const testo = ref('');
/** Il modello da cui parte il testo: null è il testo del programma. */
const modelloId = ref<number | null>(null);
/** Il testo è stato cambiato a mano dopo il modello (o il testo del programma). */
const testoToccato = ref(false);
/** Il nome del modello ricordato che non c'è più: la finestra lo dice. */
const mancante = ref<string | null>(null);
const nuovoModello = ref<{ aperto: boolean; nome: string; valePer: '' | 'condominio' | 'tutti' }>({ aperto: false, nome: '', valePer: '' });
const indirizzi = ref<string[]>(['email', 'email_secondaria']);
const anteprima = ref<Anteprima | null>(null);
const escluse = ref<number[]>([]);
const errore = ref<string | null>(null);
const esito = ref<string[]>([]);
const inCorso = ref(false);
/** Un invio è riuscito: il pulsante si spegne finché non si rifà l'anteprima. */
const fatto = ref(false);
const restanti = ref<number[] | null>(null);
/** L'esito di «Rimanda i fogli non partiti», fuori dalla finestra. */
const esitoRinvio = ref<string[]>([]);
/** Il numero dell'ultima anteprima chiesta: una risposta arrivata dopo un cambio delle scelte si scarta. */
let numeroAnteprima = 0;

// ── Lo stato degli invii: quello della pagina, o quello riletto dopo un invio o un rinvio ──
const chiave = computed(() => chiaveDegliInvii(props.condominioId, props.esercizioId));
const stato = computed(() => statoCorrente(chiave.value, page.props.invio_fogli));
const daRimandare = computed(() => stato.value?.da_rimandare ?? page.props.invio_fogli?.da_rimandare ?? 0);
const inCodaDaTempo = computed(() => testoInCodaDaTempo(stato.value?.in_coda_da_tempo ?? page.props.invio_fogli?.in_coda_da_tempo ?? 0));
const personeDellaPagina = () => [...new Set((page.props.conguaglio?.righe ?? []).filter((r) => !r.subtotale).map((r) => r.anagrafica_id))];
async function rileggi() {
    try {
        await rileggiStato(rotta('stato'), personeDellaPagina(), chiave.value, page.props.invio_fogli);
    } catch {
        // Lo stato resta quello di prima: la scheda riaperta lo rilegge.
    }
}
// I fogli in coda da tempo arrivano con la pagina; se la pagina non li porta, si chiedono una volta.
onMounted(() => {
    if (page.props.invio_fogli && page.props.invio_fogli.in_coda_da_tempo === undefined) {
        void rileggi();
    }
});

const tipiIndirizzo = ['email', 'email_secondaria', 'pec'];

/** Le scelte sono cambiate: l'anteprima di prima non vale più, e quella in arrivo nemmeno. */
function invalida() {
    anteprima.value = null;
    numeroAnteprima++;
}
watch(() => [props.chi, props.pagina], invalida);

// ── Il testo e i modelli ─────────────────────────────────────────────
const modelli = computed(() => modelliDelTipo(opzioni.value?.modelli ?? [], TIPO));
const modelloScelto = computed(() => (opzioni.value?.modelli ?? []).find((m) => m.id === modelloId.value) ?? null);
const testoDelModello = (id: number | null) => (id === null ? (opzioni.value?.testo_di_serie ?? '') : (opzioni.value?.modelli.find((m) => m.id === id)?.testo ?? ''));
/** La finestra parte dall'ultimo modello scelto nel condominio, se c'è ancora; altrimenti dal testo del programma, e lo dice. */
function partiDalRicordato() {
    const r = opzioni.value?.modello_ricordato ?? null;
    const valido = !!r && !r.mancante && r.id !== null && (opzioni.value?.modelli ?? []).some((m) => m.id === r.id);
    modelloId.value = valido ? r!.id : null;
    mancante.value = r?.mancante ? r.nome : null;
    testo.value = testoDelModello(modelloId.value);
    testoToccato.value = false;
}
function scegliModello(valore: string) {
    modelloId.value = valore === '' ? null : Number(valore);
    mancante.value = null;
    testo.value = testoDelModello(modelloId.value);
    testoToccato.value = false;
    invalida();
}
const ripartiDalModello = () => {
    testo.value = testoDelModello(modelloId.value);
    testoToccato.value = false;
    invalida();
};
const testoMancante = (nome: string) => `Il modello «${nome}», scelto l'ultima volta per l'email dei fogli, non c'è più o non vale più per questo condominio: `
    + 'la finestra parte dal testo del programma.';

async function caricaOpzioni(): Promise<Opzioni> {
    const r = (await axios.get(rotta('opzioni'))).data as Opzioni;
    opzioni.value = r;

    return r;
}

async function apri() {
    aperta.value = true;
    invalida();
    fatto.value = false;
    escluse.value = [];
    errore.value = null;
    esito.value = [];
    restanti.value = null;
    nuovoModello.value = { aperto: false, nome: '', valePer: '' };
    try {
        const r = await caricaOpzioni();
        oggetto.value = r.oggetto;
        indirizzi.value = r.indirizzi;
        partiDalRicordato();
    } catch (e) {
        errore.value = frasePerErrore(e, 'Le opzioni dell\'invio non si sono caricate. Riprova.');
    }
}

/** «Salva come modello…»: il testo della finestra diventa un modello dell'email dei fogli, per questo condominio o per tutti. */
async function salvaModello() {
    const scelto = nuovoModello.value;
    if (!scelto.nome.trim() || scelto.valePer === '') {
        return;
    }
    errore.value = null;
    inCorso.value = true;
    try {
        const r = (await axios.post(rottaModelli('store'), { tipo: TIPO, nome: scelto.nome.trim(), testo: testo.value, per_tutti: scelto.valePer === 'tutti' })).data;
        await caricaOpzioni();
        modelloId.value = r.modello.id;
        testo.value = r.modello.testo;
        testoToccato.value = false;
        nuovoModello.value = { aperto: false, nome: '', valePer: '' };
        esito.value = [testoSalvato(r.modello.nome, r.modello.per_tutti)];
    } catch (e) {
        errore.value = frasePerErrore(e, 'Il modello non è stato salvato. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

/** «Aggiorna «nome»»: il testo della finestra nel modello da cui è partito. */
async function aggiornaModello() {
    const m = modelloScelto.value;
    if (!m) {
        return;
    }
    errore.value = null;
    inCorso.value = true;
    try {
        const r = (await axios.put(rottaModelli('update', { modello: m.id }), { testo: testo.value })).data;
        await caricaOpzioni();
        testo.value = r.modello.testo;
        testoToccato.value = false;
        esito.value = [testoAggiornato(r.modello.nome, r.modello.per_tutti)];
    } catch (e) {
        errore.value = frasePerErrore(e, 'Il modello non è stato aggiornato. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

const alternaIndirizzo = (t: string) => {
    indirizzi.value = indirizzi.value.includes(t) ? indirizzi.value.filter((x) => x !== t) : [...indirizzi.value, t];
    invalida();
};

const scelte = () => ({ chi: props.chi, pagina: props.pagina, indirizzi: indirizzi.value, oggetto: oggetto.value, testo: testo.value, modello_id: modelloId.value });

async function chiediAnteprima() {
    errore.value = null;
    esito.value = [];
    inCorso.value = true;
    const numero = ++numeroAnteprima;
    try {
        const r = (await axios.post(rotta('anteprima'), scelte())).data as Anteprima;
        if (numero !== numeroAnteprima) {
            return;
        }
        anteprima.value = r;
        fatto.value = false;
        escluse.value = [];
        restanti.value = null;
    } catch (e) {
        errore.value = frasePerErrore(e, 'L\'anteprima non è riuscita. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

const personeAnteprima = computed(() => anteprima.value?.persone ?? []);
const conIndirizzo = computed(() => personeAnteprima.value.filter((p) => !p.senza_email && !escluse.value.includes(p.id)));
/** Quelle che partono con questo clic: le rimaste, dentro il tetto, a persone intere (la regola del server). */
const partono = computed(() => (anteprima.value
    ? partonoOra(conIndirizzo.value.map((p) => ({ messaggi: p.indirizzi.length })), anteprima.value.tetto_messaggi, anteprima.value.tetto_fogli)
    : { persone: 0, messaggi: 0 }));
const messaggiDaInviare = computed(() => conIndirizzo.value.reduce((n, p) => n + p.indirizzi.length, 0));
const alterna = (id: number) => {
    escluse.value = escluse.value.includes(id) ? escluse.value.filter((x) => x !== id) : [...escluse.value, id];
};
const tetto = computed(() => (anteprima.value
    ? testoTetto({ messaggi: messaggiDaInviare.value, partono: partono.value, tetto_messaggi: anteprima.value.tetto_messaggi, tetto_fogli: anteprima.value.tetto_fogli })
    : null));

async function invia(soloLe?: number[]) {
    errore.value = null;
    inCorso.value = true;
    try {
        const r = (await axios.post(rotta(), {
            ...scelte(),
            // Le persone viste nell'anteprima e rimaste: chi entra nella scelta dopo non riceve niente. E, a parte, tutte quelle dell'anteprima.
            viste: soloLe ?? conIndirizzo.value.map((p) => p.id),
            in_anteprima: personeAnteprima.value.map((p) => p.id),
        })).data;
        restanti.value = r.restanti?.persone ?? null;
        esito.value = [...testoEsito(r), FRASE_FATTO];
        fatto.value = true;
        await rileggi();
    } catch (e) {
        // Senza una risposta, o con il server caduto a metà, i fogli possono essere partiti: lo stato accanto alle persone si rilegge, e la frase
        // dice di guardarlo prima di riprovare.
        errore.value = fraseDellErroreDiInvio(e);
        await rileggi();
    } finally {
        inCorso.value = false;
    }
}

async function rimandaTutti() {
    esitoRinvio.value = [];
    inCorso.value = true;
    try {
        const r = (await axios.post(rotta('riprova'), {})).data;
        esitoRinvio.value = testoDelRinvio(r);
        await rileggi();
    } catch (e) {
        esitoRinvio.value = [fraseDellErroreDiInvio(e)];
        await rileggi();
    } finally {
        inCorso.value = false;
    }
}
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-2" data-test="invio-fogli">
        <Button variant="outline" class="h-8 px-3 gap-2" :disabled="quante === 0" data-test="invia-per-email" @click="apri"><Mail class="w-4 h-4" /> Invia per email</Button>
        <Button v-if="daRimandare > 0" variant="outline" class="h-8 px-3" :disabled="inCorso" data-test="rimanda-non-partiti" @click="rimandaTutti">
            Rimanda i fogli non partiti, {{ CON_LE_CIFRE_DI_OGGI }} ({{ daRimandare }})
        </Button>
    </span>
    <p v-if="inCodaDaTempo" class="text-xs text-amber-900 dark:text-amber-100" data-test="in-coda-da-tempo">{{ inCodaDaTempo }}</p>
    <p v-for="(riga, i) in esitoRinvio" :key="`r${i}`" class="text-xs text-slate-600 dark:text-slate-300" data-test="esito-rinvio">{{ riga }}</p>

    <Dialog :open="aperta" @update:open="(v: boolean) => (aperta = v)">
        <DialogContent class="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Invia i fogli per email</DialogTitle>
                <DialogDescription>Il foglio di ogni persona scelta nel riquadro ({{ quante }}), in PDF, a ognuno dei suoi indirizzi spuntati qui sotto.</DialogDescription>
            </DialogHeader>

            <div v-if="opzioni" class="space-y-4 text-sm">
                <div class="space-y-1">
                    <label class="block text-xs text-slate-500 dark:text-slate-400" for="oggetto-fogli">Oggetto</label>
                    <Input id="oggetto-fogli" v-model="oggetto" maxlength="200" class="h-8 text-xs" data-test="oggetto" @update:model-value="invalida()" />

                    <label class="block space-y-1 pt-1">
                        <span class="text-xs text-slate-500 dark:text-slate-400">Modello del testo</span>
                        <select :value="modelloId === null ? '' : String(modelloId)" class="h-8 w-full max-w-md rounded-md border px-2 bg-transparent text-xs" data-test="modello"
                            @change="scegliModello(($event.target as HTMLSelectElement).value)">
                            <option value="">Testo del programma</option>
                            <optgroup v-if="modelli.delCondominio.length" label="Per questo condominio">
                                <option v-for="m in modelli.delCondominio" :key="m.id" :value="String(m.id)">{{ m.nome }}</option>
                            </optgroup>
                            <optgroup v-if="modelli.perTutti.length" label="Per tutti i condomini">
                                <option v-for="m in modelli.perTutti" :key="m.id" :value="String(m.id)">{{ m.nome }}</option>
                            </optgroup>
                        </select>
                    </label>
                    <p v-if="mancante !== null" class="text-xs text-amber-800 dark:text-amber-300" data-test="modello-mancante">{{ testoMancante(mancante) }}</p>

                    <label class="block text-xs text-slate-500 dark:text-slate-400" for="testo-fogli">Testo</label>
                    <Textarea id="testo-fogli" v-model="testo" rows="5" data-test="testo" @update:model-value="testoToccato = true; invalida()" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ FRASE_CAMPI }}</p>
                    <p class="text-xs text-slate-600 dark:text-slate-300" data-test="frase-fissa">{{ fraseFissa(opzioni.frase_proposta) }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="testoToccato" size="sm" variant="ghost" class="h-7 text-xs" data-test="riparti" @click="ripartiDalModello">
                            {{ modelloScelto ? `Riparti da «${modelloScelto.nome}»` : 'Riparti dal testo del programma' }}
                        </Button>
                        <Button v-if="opzioni.puo_salvare_il_testo" size="sm" variant="outline" class="h-7 text-xs" data-test="salva-modello" :disabled="inCorso"
                            @click="nuovoModello = { aperto: true, nome: '', valePer: '' }">Salva come modello…</Button>
                        <Button v-if="opzioni.puo_salvare_il_testo && modelloScelto && testoToccato" size="sm" variant="outline" class="h-7 text-xs" data-test="aggiorna-modello"
                            :disabled="inCorso" @click="aggiornaModello">{{ testoAggiorna(modelloScelto.nome, modelloScelto.per_tutti) }}</Button>
                    </div>
                    <div v-if="nuovoModello.aperto" class="space-y-2 rounded-lg border p-2 text-xs" data-test="nuovo-modello">
                        <label class="block space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">Nome del modello</span>
                            <Input v-model="nuovoModello.nome" maxlength="120" class="h-8 text-xs" data-test="nome-modello" />
                        </label>
                        <fieldset class="space-y-1">
                            <legend class="text-slate-500 dark:text-slate-400">Vale per</legend>
                            <label class="flex items-center gap-1"><input v-model="nuovoModello.valePer" type="radio" name="vale-per-fogli" value="condominio" /> solo questo condominio</label>
                            <label class="flex items-center gap-1"><input v-model="nuovoModello.valePer" type="radio" name="vale-per-fogli" value="tutti" /> tutti i condomini</label>
                        </fieldset>
                        <div class="flex gap-2">
                            <Button size="sm" class="h-7 text-xs" data-test="conferma-modello" :disabled="inCorso || !nuovoModello.nome.trim() || nuovoModello.valePer === ''"
                                @click="salvaModello">Salva il modello</Button>
                            <Button size="sm" variant="ghost" class="h-7 text-xs" @click="nuovoModello = { aperto: false, nome: '', valePer: '' }">Annulla</Button>
                        </div>
                    </div>
                    <p v-if="!opzioni.puo_salvare_il_testo" class="text-xs text-slate-500 dark:text-slate-400" data-test="modelli-senza-permesso">{{ FRASE_MODELLI_SENZA_PERMESSO }}</p>
                </div>

                <fieldset class="space-y-1">
                    <legend class="text-xs text-slate-500 dark:text-slate-400">Agli indirizzi</legend>
                    <div class="flex flex-wrap gap-3 text-xs">
                        <label v-for="t in tipiIndirizzo" :key="t" class="flex items-center gap-1" :data-indirizzo="t">
                            <Checkbox :model-value="indirizzi.includes(t)" @update:model-value="alternaIndirizzo(t)" /> {{ TIPI_INDIRIZZO[t] }}
                        </label>
                    </div>
                    <p v-if="indirizzi.includes('pec')" class="text-xs text-slate-500 dark:text-slate-400">{{ AVVISO_PEC }}</p>
                </fieldset>
                <p v-if="opzioni.senza_server_posta" class="text-xs text-amber-800 dark:text-amber-300" data-test="senza-server">
                    Nessun server di posta configurato: i fogli non partono per email. Configuralo nelle impostazioni della posta; intanto puoi scaricarli.
                </p>

                <Button size="sm" :disabled="inCorso || quante === 0" data-test="anteprima" @click="chiediAnteprima">Anteprima: chi riceve il foglio</Button>

                <div v-if="anteprima" class="space-y-2" data-test="anteprima-persone">
                    <p class="text-xs text-slate-600 dark:text-slate-300" data-test="calcolo">{{ anteprima.data.frase }}</p>
                    <p class="text-xs" data-test="conteggi">
                        {{ email(messaggiDaInviare) }} a {{ quantePersone(conIndirizzo.length) }}.<template v-if="anteprima.senza_indirizzo.length">
                            Senza un indirizzo fra quelli scelti: {{ quantePersone(anteprima.senza_indirizzo.length) }}, il loro foglio si scarica.</template>
                    </p>
                    <p v-if="(anteprima.non_disponibili ?? 0) > 0" class="text-xs text-amber-800 dark:text-amber-300" data-test="non-disponibili">
                        {{ anteprima.non_disponibili === 1 ? 'Il foglio di 1 persona ha una parte non ancora calcolata' : `I fogli di ${anteprima.non_disponibili} persone hanno una parte non ancora calcolata` }}:
                        partono lo stesso, e la loro email lo dice in una riga. Il perché è nella scheda, sotto la riga della persona.
                    </p>
                    <p v-if="tetto" class="text-xs text-amber-800 dark:text-amber-300" data-test="tetto">{{ tetto }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ FRASE_CONSERVATO }}</p>

                    <!-- Il messaggio della prima persona, con i campi riempiti -->
                    <div v-if="anteprima.esempio" class="space-y-1 rounded-md border p-2 text-xs" data-test="esempio">
                        <p><span class="text-slate-500 dark:text-slate-400">Oggetto:</span> {{ anteprima.esempio.oggetto }}</p>
                        <p>{{ anteprima.esempio.saluto }}</p>
                        <p v-for="(r, i) in anteprima.esempio.righe" :key="i">{{ r }}</p>
                        <p>{{ anteprima.esempio.chiusura }}</p>
                        <p class="text-slate-500 dark:text-slate-400">In allegato il foglio di {{ anteprima.esempio.persona }}, in PDF.</p>
                    </div>

                    <table class="w-full text-xs">
                        <thead class="text-slate-500 dark:text-slate-400">
                            <tr><th class="p-1 text-left">Nell'invio</th><th class="p-1 text-left">Persona</th><th class="p-1 text-left">Indirizzi</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in personeAnteprima" :key="p.id" class="border-t align-top" data-test="persona-anteprima">
                                <td class="p-1"><Checkbox v-if="!p.senza_email" :model-value="!escluse.includes(p.id)" :aria-label="`Togli ${p.nome}`" @update:model-value="alterna(p.id)" /></td>
                                <td class="p-1">
                                    {{ p.nome }}
                                    <span v-if="p.non_disponibile" class="block text-[11px] text-amber-800 dark:text-amber-300" data-test="persona-non-disponibile">il suo foglio ha una parte non ancora calcolata, e l'email lo dice</span>
                                    <span v-if="testoUltimoInvio(p.ultimo)" class="block text-[11px] text-slate-500 dark:text-slate-400">{{ testoUltimoInvio(p.ultimo) }}</span>
                                </td>
                                <td class="p-1">
                                    <span v-if="p.indirizzi.length">{{ p.indirizzi.map((i) => i.indirizzo).join(', ') }}</span>
                                    <span v-else class="text-amber-800 dark:text-amber-300" data-test="senza-indirizzo">
                                        {{ p.solo_pec ? 'Ha solo la PEC: spuntala, o' : 'Nessun indirizzo fra quelli scelti:' }}
                                        <a :href="hrefFoglio(p.id)" target="_blank" class="underline decoration-dotted underline-offset-4">scarica il suo foglio</a>.
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="anteprima.senza_server_posta" class="text-xs text-amber-800 dark:text-amber-300">
                        Nessun server di posta configurato: i fogli non partono per email.
                    </p>
                </div>

                <p v-for="(riga, i) in esito" :key="i" class="text-xs text-emerald-800 dark:text-emerald-300" data-test="esito">{{ riga }}</p>
                <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-test="errore">{{ errore }}</p>
            </div>
            <p v-else-if="errore" class="text-xs text-red-700 dark:text-red-400">{{ errore }}</p>

            <DialogFooter v-if="anteprima" class="flex flex-wrap gap-2">
                <Button size="sm" :disabled="inCorso || fatto || partono.messaggi === 0" data-test="invia" @click="invia()">Invia {{ email(partono.messaggi) }}</Button>
                <Button v-if="restanti?.length" size="sm" :disabled="inCorso" data-test="invia-le-altre" @click="invia(restanti)">Invia le altre</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
