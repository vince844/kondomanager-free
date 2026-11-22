<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «La posta dello studio» nell'elenco delle caselle (scene 1, 2 e 5 della bozza approvata, decisione 572): la riga con lo stato e «Adesso», e aperta i
 * campi di sempre con le parole e i menu degli standard, la prova e «Salva la posta».
 *
 * Il modulo è quello di sempre (Coda 311): i campi ricevuti, la password vuota, che con il campo vuoto resta quella salvata e parte solo verso il suo
 * server. La prova usa i valori che si vedono, anche non salvati, e non li salva; con la password da ridigitare la finestra della conferma si apre sulla
 * pagina e la prova riparte con gli stessi dati (Coda 404). Dove la posta la prepara chi gestisce l'installazione la riga non si apre.
 */
import { computed, nextTick, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Eye, EyeOff, KeyRound, Loader2, Save, Send, Server, Terminal } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import InputError from '@/components/InputError.vue';
import { confermaPoiRiprova } from '@/composables/useConfermaPassword';
import EsitoDellaProva from './EsitoDellaProva.vue';
import EtichettaConEsempio from './EtichettaConEsempio.vue';
import RigaDellaCasella from './RigaDellaCasella.vue';
import { adessoNellOraDelloStudio, esitoDallErrore, type EsitoDellaProva as Esito } from './aiuti';
import type { CampoDaCorreggere, CasellaDellaPosta, ImpostazioniDellaPosta, RispostaDellaProva } from './tipi';

const props = defineProps<{
    settings: ImpostazioniDellaPosta;
    passwordSet?: boolean;
    casella: CasellaDellaPosta;
    /** L'indirizzo proposto per la prova: quello di chi è collegato. */
    destinatarioDiSerie?: string;
}>();

const aperta = defineModel<boolean>('aperta', { default: false });

const form = useForm({
    mail_enabled: props.settings?.mail_enabled ?? false,
    mail_driver: props.settings?.mail_driver ?? 'smtp',
    mail_host: props.settings?.mail_host ?? '',
    mail_port: props.settings?.mail_port ?? '',
    mail_username: props.settings?.mail_username ?? '',
    mail_password: '',
    mail_encryption: props.settings?.mail_encryption ?? 'tls',
    mail_from_address: props.settings?.mail_from_address ?? '',
    mail_from_name: props.settings?.mail_from_name ?? '',
});

const CIFRATURE = [
    { valore: 'tls', etichetta: 'TLS', nota: 'con la porta 587' },
    { valore: 'ssl', etichetta: 'SSL', nota: 'con la porta 465' },
    { valore: 'null', etichetta: 'Nessuna', nota: 'solo su una rete interna' },
];

const MODI = [
    { valore: 'smtp', titolo: 'Un server di posta (SMTP)', testo: 'Consigliato: il server della tua casella, con utente e password.', icona: Server },
    { valore: 'sendmail', titolo: 'La posta del server (Sendmail)', testo: 'Solo su un server dedicato, con la posta già preparata da chi lo gestisce.', icona: Terminal },
];

const mostraPassword = ref(false);
const destinatario = ref(props.destinatarioDiSerie ?? '');
const inProva = ref(false);
const esito = ref<Esito | null>(null);

const smtp = computed(() => form.mail_driver === 'smtp');
const nonAndata = computed(() => props.casella.stato === 'non_andata' && props.casella.ultima_prova !== null);
/** La password salvata non è stata accettata: il campo si segna, e la riga lo dice. */
const passwordRifiutata = computed(() => !esito.value && nonAndata.value && props.casella.ultima_prova?.motivo?.chiave === 'accesso');
const adessoDelloInterruttore = computed(() => form.mail_enabled
    ? 'Adesso: accesa. Le email partono da questa casella.'
    : 'Adesso: spenta. Le email partono con la posta preparata sul server, se c\'è; se non c\'è, restano scritte nel registro del programma.');

/** Il pulsante della riga, il giusto per lo stato (la tabella degli stati della scena 3). */
const pulsante = computed<{ testo: string; primario: boolean; campo: CampoDaCorreggere | 'prova' | null; accendi?: boolean } | null>(() => {
    if (props.casella.sola_lettura) return null;
    if (aperta.value) return { testo: 'Chiudi', primario: false, campo: null };
    switch (props.casella.stato) {
        case 'spenta': return { testo: 'Accendi', primario: false, campo: null, accendi: true };
        case 'da_impostare': return { testo: 'Imposta la posta', primario: true, campo: 'server' };
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
const conModifica = computed(() => !aperta.value && pulsante.value !== null && !pulsante.value.accendi && pulsante.value.campo !== null && pulsante.value.campo !== 'server');

const ID_DEL_CAMPO: Record<string, string> = {
    password: 'posta-password', porta: 'posta-porta', server: 'posta-server', utente: 'posta-utente', cifratura: 'posta-cifratura',
    mittente: 'posta-mittente', prova: 'posta-prova',
};

async function correggi(campo: CampoDaCorreggere | 'prova') {
    aperta.value = true;
    if (campo === 'password') form.mail_password = '';
    await nextTick();
    document.getElementById(ID_DEL_CAMPO[campo])?.focus();
}

async function premi() {
    const p = pulsante.value;
    if (!p) return;
    if (aperta.value) {
        aperta.value = false;
        return;
    }
    if (p.accendi) form.mail_enabled = true;
    if (p.campo) await correggi(p.campo);
    else aperta.value = true;
}

function salva() {
    form.post(route('admin.settings.mail.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('mail_password'),
    });
}

async function provaLInvio() {
    if (!destinatario.value) return;
    // Il corpo si compone una volta: la prova rifatta dopo la conferma della password manda gli stessi dati.
    await inviaProva({ ...form.data(), test_email: destinatario.value });
}

async function inviaProva(corpo: Record<string, unknown>) {
    inProva.value = true;
    esito.value = null;
    const verso = String(corpo.test_email ?? '');
    try {
        const risposta = await axios.post(route('admin.settings.mail.test'), corpo);
        if (risposta.data?.success) {
            esito.value = { andata: true, quando: adessoNellOraDelloStudio(), destinatario: verso, motivo: null, testo: null, suggerimento: null };
        }
    } catch (errore: unknown) {
        const e = errore as { response?: { status?: number; data?: RispostaDellaProva } };
        // La password va ridigitata (Coda 404): la finestra si apre su questa pagina e la prova riparte da sola.
        if (confermaPoiRiprova(e.response?.status, () => inviaProva(corpo))) return;
        esito.value = esitoDallErrore(e.response?.status, e.response?.data, verso);
    } finally {
        inProva.value = false;
    }
    // La riga «Adesso» e la card in alto seguono la prova ricordata dal server.
    router.reload({ only: ['caselle', 'invii'] });
}

defineExpose({ form, esito, destinatario, provaLInvio, inProva });
</script>

<template>
    <div data-testid="casella-posta">
        <RigaDellaCasella
            icona="posta"
            nome="La posta dello studio"
            :stato="casella.stato"
            :parola="casella.parola"
            :riga="casella.riga"
            serve="le email normali: avvisi di rata, solleciti, ricevute, comunicazioni, inviti al portale."
            :adesso="casella.adesso"
            :consiglio="casella.consiglio ?? null"
        >
            <Button v-if="conModifica" type="button" size="sm" variant="outline" class="h-8" data-testid="modifica-posta" @click="aperta = true">Modifica</Button>
            <Button v-if="pulsante" type="button" size="sm" :variant="pulsante.primario ? 'default' : 'outline'" class="h-8" data-testid="pulsante-posta"
                @click="premi">{{ pulsante.testo }}</Button>
        </RigaDellaCasella>

        <form v-if="aperta && !casella.sola_lettura" class="flex flex-col gap-3.5 border-t border-slate-100 bg-slate-50/70 px-4 pt-4 pb-5 sm:pl-[68px] sm:pr-5 dark:border-slate-800 dark:bg-slate-900/40"
            data-testid="modulo-posta" @submit.prevent="salva">
            <EsitoDellaProva v-if="nonAndata && !esito && casella.ultima_prova" casella="posta" :andata="false" :quando="casella.ultima_prova.quando"
                :destinatario="casella.ultima_prova.destinatario" :motivo="casella.ultima_prova.motivo" :testo="casella.ultima_prova.testo"
                :puo-riprovare="!!destinatario" @correggi="correggi" @riprova="provaLInvio" />

            <div class="flex items-start gap-3.5 rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-950">
                <div class="min-w-0 flex-1">
                    <EtichettaConEsempio per="posta-accesa" testo="Manda la posta da questa casella"
                        esempio="Esempio: spenta su un'installazione nuova, un avviso di rata non parte e resta scritto nel registro del programma; utile finché fai le prove." />
                    <p class="text-sm text-slate-500 dark:text-slate-400">Spenta, il programma usa la posta preparata sul server da chi l'ha installato, se c'è; se non c'è,
                        le email non partono e restano scritte nel registro del programma.</p>
                    <p class="mt-2 rounded-md bg-slate-100 px-2.5 py-1.5 text-xs leading-snug text-slate-700 dark:bg-slate-800/60 dark:text-slate-300"
                        data-testid="adesso-interruttore">{{ adessoDelloInterruttore }}</p>
                </div>
                <Switch id="posta-accesa" v-model="form.mail_enabled" />
            </div>

            <template v-if="form.mail_enabled">
                <div>
                    <EtichettaConEsempio testo="Come si manda" esempio="Esempio: con una casella del tuo dominio, come segreteria@studiocosta.it, scegli «Un server di posta»." />
                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        <button v-for="m in MODI" :key="m.valore" type="button" :data-testid="`modo-${m.valore}`"
                            :class="['flex items-start gap-2.5 rounded-lg border bg-white px-3 py-2.5 text-left text-sm dark:bg-slate-950',
                                form.mail_driver === m.valore ? 'border-slate-900 ring-1 ring-slate-900 dark:border-slate-200 dark:ring-slate-200' : 'border-slate-200 dark:border-slate-800']"
                            @click="form.mail_driver = m.valore">
                            <component :is="m.icona" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-500" />
                            <span>
                                <b class="block font-semibold text-slate-900 dark:text-slate-100">{{ m.titolo }}</b>
                                <span class="text-xs leading-snug text-slate-500 dark:text-slate-400">{{ m.testo }}</span>
                            </span>
                        </button>
                    </div>
                </div>

                <template v-if="smtp">
                    <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <div>
                            <EtichettaConEsempio per="posta-server" testo="Server di posta in uscita"
                                esempio="Esempio: smtp.studiocosta.it. Lo trovi nelle istruzioni della tua casella, alla voce «posta in uscita» o «SMTP»." />
                            <Input id="posta-server" v-model="form.mail_host" type="text" autocomplete="off" class="bg-white text-sm dark:bg-slate-950" />
                            <InputError :message="form.errors.mail_host" />
                        </div>
                        <div>
                            <EtichettaConEsempio per="posta-porta" testo="Porta" esempio="Esempio: 587 con TLS, oppure 465 con SSL." />
                            <Input id="posta-porta" v-model="form.mail_port" inputmode="numeric" class="bg-white text-sm tabular-nums dark:bg-slate-950" />
                            <InputError :message="form.errors.mail_port" />
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                        <div>
                            <EtichettaConEsempio per="posta-utente" testo="Utente" esempio="Esempio: di solito è l'indirizzo intero, segreteria@studiocosta.it." />
                            <Input id="posta-utente" v-model="form.mail_username" type="text" autocomplete="off" class="bg-white text-sm dark:bg-slate-950" />
                        </div>
                        <div>
                            <EtichettaConEsempio per="posta-password" testo="Password" />
                            <div class="relative">
                                <Input id="posta-password" v-model="form.mail_password" :type="mostraPassword ? 'text' : 'password'" autocomplete="new-password"
                                    :class="['bg-white pr-10 text-sm dark:bg-slate-950', passwordRifiutata ? 'border-rose-500 ring-2 ring-rose-200 dark:ring-rose-900' : '']"
                                    :placeholder="passwordSet ? 'Lascia vuoto per tenere quella salvata' : 'Scrivi la password della casella'" />
                                <button type="button" class="absolute top-2.5 right-3 text-slate-500 hover:text-slate-800 dark:hover:text-slate-200"
                                    :aria-label="mostraPassword ? 'Nascondi la password' : 'Mostra la password'" @click="mostraPassword = !mostraPassword">
                                    <EyeOff v-if="mostraPassword" class="h-4 w-4" /><Eye v-else class="h-4 w-4" />
                                </button>
                            </div>
                            <InputError :message="form.errors.mail_password" />
                            <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400" data-testid="stato-password-posta">
                                <span :class="passwordSet
                                    ? 'inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-medium text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    : 'inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 font-medium text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300'">
                                    <KeyRound class="h-3 w-3" /> {{ passwordSet ? 'Impostata' : 'Da impostare' }}
                                </span>
                                <span v-if="passwordRifiutata">Quella salvata non è stata accettata: scrivine una nuova.</span>
                                <span v-else-if="passwordSet">Non si rilegge mai: per cambiarla, scrivine una nuova.</span>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                        <div class="menu-campo menu-casella">
                            <EtichettaConEsempio per="posta-cifratura" testo="Cifratura" esempio="Esempio: con la porta 587 si sceglie TLS, con la 465 SSL." />
                            <v-select input-id="posta-cifratura" :options="CIFRATURE" label="etichetta" :reduce="(c: { valore: string }) => c.valore" :searchable="false"
                                :clearable="false" :model-value="form.mail_encryption ?? 'null'" class="w-full bg-white text-sm dark:bg-slate-950"
                                data-testid="cifratura-posta" @update:model-value="(v: string) => (form.mail_encryption = v)">
                                <template #option="o">
                                    <span class="flex w-full justify-between gap-2"><span>{{ o.etichetta }}</span><span class="text-xs text-slate-500">{{ o.nota }}</span></span>
                                </template>
                            </v-select>
                        </div>
                        <div>
                            <EtichettaConEsempio per="posta-mittente" testo="Indirizzo del mittente"
                                esempio="Esempio: segreteria@studiocosta.it. È l'indirizzo che il condòmino vede quando riceve l'avviso di rata." />
                            <Input id="posta-mittente" v-model="form.mail_from_address" type="email" class="bg-white text-sm dark:bg-slate-950" />
                            <InputError :message="form.errors.mail_from_address" />
                        </div>
                    </div>
                </template>
                <div v-else>
                    <EtichettaConEsempio per="posta-mittente" testo="Indirizzo del mittente"
                        esempio="Esempio: segreteria@studiocosta.it. È l'indirizzo che il condòmino vede quando riceve l'avviso di rata." />
                    <Input id="posta-mittente" v-model="form.mail_from_address" type="email" class="bg-white text-sm dark:bg-slate-950" />
                    <InputError :message="form.errors.mail_from_address" />
                </div>

                <div>
                    <EtichettaConEsempio per="posta-nome" testo="Nome del mittente"
                        esempio="Esempio: «Studio Costa, amministrazioni». È il nome che il condòmino vede accanto all'indirizzo." />
                    <Input id="posta-nome" v-model="form.mail_from_name" type="text" class="bg-white text-sm dark:bg-slate-950" />
                </div>

                <div class="flex flex-col gap-2 border-t border-slate-200 pt-3.5 dark:border-slate-800">
                    <p class="flex items-center gap-1.5 text-sm font-medium text-slate-700 dark:text-slate-300"><Send class="h-3.5 w-3.5" /> Prova l'invio</p>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Input id="posta-prova" v-model="destinatario" type="email" aria-label="Indirizzo della prova" class="bg-white text-sm sm:flex-1 dark:bg-slate-950" />
                        <Button type="button" variant="outline" class="h-9 gap-2" :disabled="inProva || !destinatario" data-testid="prova-posta" @click="provaLInvio">
                            <Loader2 v-if="inProva" class="h-4 w-4 animate-spin" /><Send v-else class="h-4 w-4" /> Prova l'invio
                        </Button>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">La prova usa i valori che vedi, anche se non li hai salvati, e non li salva.</p>
                    <EsitoDellaProva v-if="esito" casella="posta" :andata="esito.andata" :quando="esito.quando" :destinatario="esito.destinatario" :motivo="esito.motivo"
                        :testo="esito.testo" :suggerimento="esito.suggerimento" :puo-riprovare="!esito.andata && !!destinatario" @correggi="correggi" @riprova="provaLInvio" />
                </div>
            </template>

            <div class="flex flex-col items-stretch gap-3 border-t border-slate-200 pt-3.5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Le modifiche valgono da quando salvi; la password salvata parte solo verso questo server.</p>
                <Button type="submit" class="h-9 gap-2" :disabled="form.processing" data-testid="salva-posta"><Save class="h-4 w-4" /> Salva la posta</Button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.menu-casella :deep(.vs__dropdown-toggle) {
    min-height: 2.25rem;
}
</style>
