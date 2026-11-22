<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «La casella PEC dello studio» nell'elenco delle caselle (scene 1, 2, 3 e 4 della bozza approvata, decisioni 564 e 572). Da impostare, la riga dice che
 * cosa serve, che cosa fa il programma e che cosa resta fuori, e porta a «Imposta la casella PEC». Aperta: l'indirizzo (proposto dalla carta dello studio),
 * il server, la porta, la cifratura (solo SSL e TLS: la PEC non parte in chiaro), l'utente (proposto uguale all'indirizzo), la password, che non si rilegge
 * mai, la prova (di serie verso la stessa casella PEC) e «Salva la casella PEC».
 *
 * La password salvata parte solo verso il server, la porta e la cifratura per cui è scritta: cambiati, la pagina chiede di riscriverla prima che lo dica
 * il server. Con la password da ridigitare la finestra della conferma si apre sulla pagina e il gesto riparte con gli stessi dati (Coda 404).
 *
 * «Togli la casella PEC» (decisione 577), con la conferma: per un cambio di gestore, o per fermare le PEC. La casella torna «Da impostare» e «Manda le PEC»
 * lo dice. La ricevuta di consegna arriva nella casella PEC: l'amministratore la trascina sulla riga dell'invio, e il programma ne legge il giorno e l'ora.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Eye, EyeOff, KeyRound, Loader2, Save, Send, Trash2, Upload } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { confermaPoiRiprova } from '@/composables/useConfermaPassword';
import EsitoDellaProva from './EsitoDellaProva.vue';
import EtichettaConEsempio from './EtichettaConEsempio.vue';
import RigaDellaCasella from './RigaDellaCasella.vue';
import { adessoNellOraDelloStudio, esitoDallErrore, type EsitoDellaProva as Esito } from './aiuti';
import type { CampoDaCorreggere, CasellaPecDelloStudio, RispostaDellaProva } from './tipi';

const props = defineProps<{ casella: CasellaPecDelloStudio }>();

const aperta = defineModel<boolean>('aperta', { default: false });

const v = props.casella.valori;
const indirizzoIniziale = v.indirizzo ?? props.casella.dalla_carta ?? '';
const form = useForm({
    indirizzo: indirizzoIniziale,
    server: v.server ?? '',
    porta: v.porta ?? 465,
    cifratura: v.cifratura ?? 'ssl',
    utente: v.utente ?? indirizzoIniziale,
    password: '',
});

const CIFRATURE = [
    { valore: 'ssl', etichetta: 'SSL', nota: 'con la porta 465' },
    { valore: 'tls', etichetta: 'TLS', nota: 'con la porta 587' },
];

// L'utente e l'indirizzo della prova seguono l'indirizzo finché non li si cambia a mano (proposte 8 e 10).
const utenteSegue = ref(!v.utente || v.utente === indirizzoIniziale);
const destinatario = ref(indirizzoIniziale);
const destinatarioSegue = ref(true);
watch(() => form.indirizzo, (nuovo) => {
    if (utenteSegue.value) form.utente = nuovo;
    if (destinatarioSegue.value) destinatario.value = nuovo;
});
function utenteScritto(valore: string | number) {
    form.utente = String(valore);
    utenteSegue.value = form.utente === form.indirizzo;
}
function destinatarioScritto(valore: string | number) {
    destinatario.value = String(valore);
    destinatarioSegue.value = destinatario.value === form.indirizzo;
}
// La porta segue la cifratura quando è quella dell'altra: SSL con la 465, TLS con la 587.
function cifraturaScelta(valore: 'ssl' | 'tls') {
    form.cifratura = valore;
    if (valore === 'tls' && Number(form.porta) === 465) form.porta = 587;
    if (valore === 'ssl' && Number(form.porta) === 587) form.porta = 465;
}

const mostraPassword = ref(false);
const inProva = ref(false);
const esito = ref<Esito | null>(null);

const daImpostare = computed(() => props.casella.stato === 'da_impostare');
const nonAndata = computed(() => props.casella.stato === 'non_andata' && props.casella.ultima_prova !== null);
const passwordRifiutata = computed(() => !esito.value && nonAndata.value && props.casella.ultima_prova?.motivo?.chiave === 'accesso');
const dallaCarta = computed(() => !!props.casella.dalla_carta && form.indirizzo.trim().toLowerCase() === props.casella.dalla_carta.toLowerCase());
/**
 * Server, porta o cifratura cambiati con la password salvata e il campo vuoto: va riscritta (Coda 311), e la pagina lo dice prima del server. Il confronto
 * è con la casella salvata adesso (la prop), non con quella dell'apertura: dopo «Salva» la salvata è la nuova.
 */
const passwordDaRiscrivere = computed(() => {
    const salvata = props.casella.valori;

    return props.casella.password_impostata && form.password === '' && (form.server.trim().toLowerCase() !== (salvata.server ?? '').trim().toLowerCase()
        || Number(form.porta) !== Number(salvata.porta) || form.cifratura !== salvata.cifratura);
});

const pulsante = computed<{ testo: string; primario: boolean; campo: CampoDaCorreggere | 'prova' | null }>(() => {
    if (aperta.value) return { testo: 'Chiudi', primario: false, campo: null };
    switch (props.casella.stato) {
        case 'da_impostare': return { testo: 'Imposta la casella PEC', primario: true, campo: null };
        case 'da_provare': return { testo: 'Prova l\'invio', primario: false, campo: 'prova' };
        case 'non_andata': {
            const azione = props.casella.ultima_prova?.motivo?.azione;

            return azione ? { testo: azione.etichetta, primario: false, campo: azione.campo } : { testo: 'Modifica', primario: false, campo: null };
        }
        default: return { testo: 'Modifica', primario: false, campo: null };
    }
});

/**
 * «Modifica» accanto al pulsante dello stato, quando quello non è già «Modifica»: con la casella da provare o con la prova non andata il pulsante porta a un
 * campo solo («Prova l'invio», «Riscrivi la password»), e cambiare il server o l'utente si deve poter fare senza passare da lì.
 */
const conModifica = computed(() => !aperta.value && pulsante.value.campo !== null);

const ID_DEL_CAMPO: Record<string, string> = {
    password: 'pec-password', porta: 'pec-porta', server: 'pec-server', utente: 'pec-utente', cifratura: 'pec-cifratura', mittente: 'pec-indirizzo',
    prova: 'pec-prova',
};

async function correggi(campo: CampoDaCorreggere | 'prova') {
    aperta.value = true;
    if (campo === 'password') form.password = '';
    await nextTick();
    document.getElementById(ID_DEL_CAMPO[campo])?.focus();
}

async function premi() {
    if (aperta.value) {
        aperta.value = false;
        return;
    }
    const campo = pulsante.value.campo;
    if (campo) await correggi(campo);
    else aperta.value = true;
}

// «Togli la casella PEC»: la conferma dice che cosa succede, poi la casella torna da impostare.
const confermaTogli = ref(false);
const togliendo = ref(false);
function togli() {
    confermaTogli.value = false;
    togliendo.value = true;
    router.delete(route('admin.settings.pec.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            aperta.value = false;
            // Il modulo riparte vuoto, con l'indirizzo della carta dello studio proposto come la prima volta.
            const proposto = props.casella.dalla_carta ?? '';
            form.defaults({ indirizzo: proposto, server: '', porta: 465, cifratura: 'ssl', utente: proposto, password: '' });
            form.reset();
        },
        onFinish: () => { togliendo.value = false; },
    });
}

function salva() {
    form.post(route('admin.settings.pec.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('password'),
    });
}

async function provaLInvio() {
    if (!destinatario.value) return;
    await inviaProva({ ...form.data(), test_email: destinatario.value });
}

async function inviaProva(corpo: Record<string, unknown>) {
    inProva.value = true;
    esito.value = null;
    const verso = String(corpo.test_email ?? '');
    try {
        const risposta = await axios.post(route('admin.settings.pec.prova'), corpo);
        if (risposta.data?.success) {
            esito.value = { andata: true, quando: risposta.data.prova?.quando ?? adessoNellOraDelloStudio(), destinatario: verso, motivo: null, testo: null,
                suggerimento: null };
        }
    } catch (errore: unknown) {
        const e = errore as { response?: { status?: number; data?: RispostaDellaProva } };
        if (confermaPoiRiprova(e.response?.status, () => inviaProva(corpo))) return;
        esito.value = esitoDallErrore(e.response?.status, e.response?.data, verso);
    } finally {
        inProva.value = false;
    }
    router.reload({ only: ['caselle', 'invii'] });
}

defineExpose({ form, esito, destinatario, provaLInvio, inProva, utenteScritto, destinatarioScritto, cifraturaScelta });
</script>

<template>
    <div data-testid="casella-pec" id="casella-pec">
        <RigaDellaCasella
            icona="pec"
            nome="La casella PEC dello studio"
            :stato="casella.stato"
            :parola="casella.parola"
            :riga="casella.riga"
            serve="le convocazioni delle assemblee e le lettere a chi è in ritardo che scegli di mandare per PEC (sollecito, diffida, messa in mora). Le email normali non partono mai da qui."
            :adesso="casella.adesso"
        >
            <Button v-if="conModifica" type="button" size="sm" variant="outline" class="h-8" data-testid="modifica-pec" @click="aperta = true">Modifica</Button>
            <Button type="button" size="sm" :variant="pulsante.primario ? 'default' : 'outline'" class="h-8" data-testid="pulsante-pec" @click="premi">
                {{ pulsante.testo }}
            </Button>
        </RigaDellaCasella>

        <!-- Da impostare e chiusa: che cosa serve, che cosa fa il programma, che cosa resta fuori (scena 1). -->
        <div v-if="!aperta && daImpostare" class="flex flex-col gap-3.5 border-t border-slate-100 bg-slate-50/70 px-4 pt-4 pb-5 sm:pl-[68px] sm:pr-5 dark:border-slate-800 dark:bg-slate-900/40"
            data-testid="pec-da-impostare">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                    <p class="mb-1 flex items-center gap-1.5 font-semibold text-slate-900 dark:text-slate-100"><KeyRound class="h-3.5 w-3.5" /> Che cosa ti serve</p>
                    L'indirizzo PEC e la sua password, e i dati della posta in uscita (server, porta, cifratura): sono nelle istruzioni del tuo gestore PEC, alla voce
                    «posta in uscita» o «SMTP».
                </div>
                <div class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                    <p class="mb-1 flex items-center gap-1.5 font-semibold text-slate-900 dark:text-slate-100"><Send class="h-3.5 w-3.5" /> Che cosa fa il programma</p>
                    Manda le convocazioni con «Manda le PEC», nel passo «Convocazione» dell'assemblea, e le lettere a chi è in ritardo con «Manda N PEC», nella finestra
                    delle lettere: una PEC per condòmino, ognuna segnata nel registro degli invii.
                </div>
                <div class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                    <p class="mb-1 flex items-center gap-1.5 font-semibold text-slate-900 dark:text-slate-100"><Upload class="h-3.5 w-3.5" /> Che cosa resta fuori</p>
                    La ricevuta di consegna arriva nella casella PEC, dal tuo gestore: il programma non legge quella casella. La salvi e la trascini sulla riga dell'invio
                    (della convocazione, o della lettera), e il programma ne legge il giorno e l'ora.
                </div>
            </div>
            <p v-if="casella.dalla_carta" class="text-xs text-slate-500 [overflow-wrap:anywhere] dark:text-slate-400" data-testid="pec-dalla-carta">
                L'indirizzo c'è già nella carta dello studio: {{ casella.dalla_carta }} (Impostazioni › Stampe). Si propone da solo quando apri la casella.
            </p>
        </div>

        <form v-if="aperta" class="flex flex-col gap-3.5 border-t border-slate-100 bg-slate-50/70 px-4 pt-4 pb-5 sm:pl-[68px] sm:pr-5 dark:border-slate-800 dark:bg-slate-900/40"
            data-testid="modulo-pec" @submit.prevent="salva">
            <EsitoDellaProva v-if="nonAndata && !esito && casella.ultima_prova" casella="pec" :andata="false" :quando="casella.ultima_prova.quando"
                :destinatario="casella.ultima_prova.destinatario" :motivo="casella.ultima_prova.motivo" :testo="casella.ultima_prova.testo"
                :puo-riprovare="!!destinatario" @correggi="correggi" @riprova="provaLInvio" />

            <div>
                <EtichettaConEsempio per="pec-indirizzo" testo="Indirizzo PEC"
                    esempio="Esempio: andrea.costa@pec.studiocosta.it. È il mittente delle convocazioni e delle lettere per PEC: il condòmino lo vede nella PEC che riceve." />
                <Input id="pec-indirizzo" v-model="form.indirizzo" type="email" autocomplete="off" class="bg-white text-sm dark:bg-slate-950" />
                <InputError :message="form.errors.indirizzo" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" data-testid="pec-indirizzo-nota">
                    <template v-if="dallaCarta">Preso dalla carta dello studio (Impostazioni › Stampe). È il mittente delle convocazioni e delle lettere per PEC.</template>
                    <template v-else>È il mittente delle convocazioni e delle lettere per PEC.</template>
                </p>
            </div>
            <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div>
                    <EtichettaConEsempio per="pec-server" testo="Server di posta in uscita"
                        esempio="Esempio: smtps.pec.ilgestore.it. Lo trovi nelle istruzioni del gestore PEC, alla voce «posta in uscita» o «SMTP»." />
                    <Input id="pec-server" v-model="form.server" type="text" autocomplete="off" class="bg-white text-sm dark:bg-slate-950" />
                    <InputError :message="form.errors.server" />
                </div>
                <div>
                    <EtichettaConEsempio per="pec-porta" testo="Porta" esempio="Esempio: quasi tutti i gestori PEC usano la 465 con SSL. Se il tuo gestore dice 587, scegli TLS." />
                    <Input id="pec-porta" v-model="form.porta" inputmode="numeric" class="bg-white text-sm tabular-nums dark:bg-slate-950" />
                    <InputError :message="form.errors.porta" />
                </div>
            </div>
            <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                <div class="menu-campo menu-casella">
                    <EtichettaConEsempio per="pec-cifratura" testo="Cifratura" esempio="Esempio: la 465 con SSL. La PEC non parte senza cifratura: per questo «Nessuna» non c'è." />
                    <v-select input-id="pec-cifratura" :options="CIFRATURE" label="etichetta" :reduce="(c: { valore: string }) => c.valore" :searchable="false"
                        :clearable="false" :model-value="form.cifratura" class="w-full bg-white text-sm dark:bg-slate-950" data-testid="cifratura-pec"
                        @update:model-value="cifraturaScelta">
                        <template #option="o">
                            <span class="flex w-full justify-between gap-2"><span>{{ o.etichetta }}</span><span class="text-xs text-slate-500">{{ o.nota }}</span></span>
                        </template>
                    </v-select>
                    <InputError :message="form.errors.cifratura" />
                </div>
                <div>
                    <EtichettaConEsempio per="pec-utente" testo="Utente" esempio="Esempio: di solito è l'indirizzo PEC intero, andrea.costa@pec.studiocosta.it." />
                    <Input id="pec-utente" :model-value="form.utente" type="text" autocomplete="off" class="bg-white text-sm dark:bg-slate-950"
                        @update:model-value="utenteScritto" />
                    <InputError :message="form.errors.utente" />
                    <p v-if="form.utente !== '' && form.utente === form.indirizzo" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Si propone uguale all'indirizzo.</p>
                </div>
            </div>
            <div>
                <EtichettaConEsempio per="pec-password" testo="Password" />
                <div class="relative">
                    <Input id="pec-password" v-model="form.password" :type="mostraPassword ? 'text' : 'password'" autocomplete="new-password"
                        :class="['bg-white pr-10 text-sm dark:bg-slate-950', passwordRifiutata || passwordDaRiscrivere ? 'border-rose-500 ring-2 ring-rose-200 dark:ring-rose-900' : '']"
                        :placeholder="casella.password_impostata && !passwordRifiutata ? 'Lascia vuoto per tenere quella salvata' : 'Scrivi la password della casella PEC'" />
                    <button type="button" class="absolute top-2.5 right-3 text-slate-500 hover:text-slate-800 dark:hover:text-slate-200"
                        :aria-label="mostraPassword ? 'Nascondi la password' : 'Mostra la password'" @click="mostraPassword = !mostraPassword">
                        <EyeOff v-if="mostraPassword" class="h-4 w-4" /><Eye v-else class="h-4 w-4" />
                    </button>
                </div>
                <InputError :message="form.errors.password" />
                <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400" data-testid="stato-password-pec">
                    <span :class="casella.password_impostata
                        ? 'inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-medium text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
                        : 'inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 font-medium text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300'">
                        <KeyRound class="h-3 w-3" /> {{ casella.password_impostata ? 'Impostata' : 'Da impostare' }}
                    </span>
                    <span v-if="passwordRifiutata">Quella salvata non è stata accettata: scrivine una nuova.</span>
                    <span v-else-if="passwordDaRiscrivere" class="text-rose-700 dark:text-rose-300" data-testid="password-da-riscrivere">
                        Hai cambiato server, porta o cifratura: riscrivi la password.
                    </span>
                    <span v-else-if="casella.password_impostata">Non si rilegge mai. Se cambi server, porta o cifratura, va riscritta.</span>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                    <p class="mb-1 flex items-center gap-1.5 font-semibold text-slate-900 dark:text-slate-100"><Send class="h-3.5 w-3.5" /> A che cosa serve</p>
                    Da qui partono le convocazioni per PEC, con «Manda le PEC» nel passo «Convocazione» dell'assemblea, e le lettere a chi è in ritardo, con «Manda N PEC»
                    nella finestra delle lettere.
                </div>
                <div class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300"
                    data-testid="pec-resta-fuori">
                    <p class="mb-1 flex items-center gap-1.5 font-semibold text-slate-900 dark:text-slate-100"><Upload class="h-3.5 w-3.5" /> Che cosa resta fuori</p>
                    Il programma manda la PEC ma non legge la casella. La ricevuta di consegna arriva lì: la salvi e la trascini sulla riga dell'invio, e il programma ne
                    legge il giorno e l'ora («Consegnata il 03/12 alle 10:14») e la archivia sulla riga.
                </div>
            </div>

            <div class="flex flex-col gap-2 border-t border-slate-200 pt-3.5 dark:border-slate-800">
                <EtichettaConEsempio per="pec-prova" testo="Prova l'invio"
                    esempio="Esempio: la stessa casella PEC. Ci arrivano la ricevuta di accettazione e quella di consegna, e vedi che il gestore ha preso la prova." />
                <div class="flex flex-col gap-2 sm:flex-row">
                    <Input id="pec-prova" :model-value="destinatario" type="email" aria-label="Indirizzo della prova" class="bg-white text-sm sm:flex-1 dark:bg-slate-950"
                        @update:model-value="destinatarioScritto" />
                    <Button type="button" variant="outline" class="h-9 gap-2" :disabled="inProva || !destinatario" data-testid="prova-pec" @click="provaLInvio">
                        <Loader2 v-if="inProva" class="h-4 w-4 animate-spin" /><Send v-else class="h-4 w-4" /> Prova l'invio
                    </Button>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">Di serie la prova va alla stessa casella PEC. Usa i valori che vedi, anche se non li hai salvati, e non li salva.</p>
                <EsitoDellaProva v-if="esito" casella="pec" :andata="esito.andata" :quando="esito.quando" :destinatario="esito.destinatario" :motivo="esito.motivo"
                    :testo="esito.testo" :puo-riprovare="!esito.andata && !!destinatario" @correggi="correggi" @riprova="provaLInvio" />
                <EsitoDellaProva v-else-if="casella.stato === 'provata' && casella.ultima_prova" casella="pec" :andata="true" :quando="casella.ultima_prova.quando"
                    :destinatario="casella.ultima_prova.destinatario" :motivo="null" :testo="null" />
                <p v-if="nonAndata && !esito" class="text-xs text-slate-500 dark:text-slate-400">La prova non andata è anche una riga dello storico delle email,
                    in «Audit & Logs di Sistema», scheda «Email Logs», con il testo del server.</p>
            </div>

            <div class="flex flex-col items-stretch gap-3 border-t border-slate-200 pt-3.5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Le convocazioni e le lettere per PEC usano questa casella da quando salvi.</p>
                <div class="flex flex-col-reverse gap-2 sm:flex-row">
                    <Button v-if="!daImpostare" type="button" variant="ghost" class="h-9 gap-2 text-rose-700 hover:text-rose-800 dark:text-rose-300" :disabled="togliendo"
                        data-testid="togli-pec" @click="confermaTogli = true"><Trash2 class="h-4 w-4" /> Togli la casella PEC</Button>
                    <Button type="submit" class="h-9 gap-2" :disabled="form.processing" data-testid="salva-pec"><Save class="h-4 w-4" /> Salva la casella PEC</Button>
                </div>
            </div>
        </form>
        <ConfirmDialog v-model="confermaTogli" title="Togliere la casella PEC dello studio?" confirm-text="Togli la casella PEC" cancel-text="Annulla" variant="destructive"
            description="La casella torna «Da impostare»: le convocazioni e le lettere per PEC non partono, e «Manda le PEC» lo dice, finché non la imposti di nuovo. Le PEC già partite e le loro ricevute restano. Il cambio è una riga della «Storia»."
            @confirm="togli" />
    </div>
</template>

<style scoped>
.menu-casella :deep(.vs__dropdown-toggle) {
    min-height: 2.25rem;
}
</style>
