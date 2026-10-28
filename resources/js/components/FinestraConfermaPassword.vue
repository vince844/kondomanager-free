<script setup lang="ts">
import axios from 'axios';
import { nextTick, ref, watch } from 'vue';
import { trans, transChoice } from 'laravel-vue-i18n';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { annullaConferma, confermaPasswordAperta, confermaRiuscita } from '@/composables/useConfermaPassword';

/**
 * Coda 404 — la password ridigitata si dà qui, sulla stessa pagina, e la richiesta che l'aveva chiesta riparte da sola.
 *
 * Una finestra sola per l'applicazione, montata alla radice (`app.ts`): la apre `useConfermaPassword` quando una
 * richiesta riceve il 423, sia una visita di Inertia (`lib/clientHttpConConferma.ts`) sia una chiamata `fetch` o `axios`. La password va in JSON a `POST /confirm-password` (che non rimanda da nessuna parte):
 * con il 200 la finestra si chiude e la richiesta riparte; con una password sbagliata resta aperta e dice perché.
 * «Annulla», la X e Esc chiudono e dimenticano la richiesta; la password non resta in memoria dopo la chiusura.
 */
const ID_CAMPO = 'conferma-password-finestra';

const password = ref('');
const errore = ref('');
const inCorso = ref(false);

function campo(): HTMLInputElement | null {
    return document.getElementById(ID_CAMPO) as HTMLInputElement | null;
}

// Alla chiusura, in qualunque modo avvenga, la finestra non tiene niente: né la password né l'errore.
watch(confermaPasswordAperta, (aperta) => {
    if (!aperta) {
        password.value = '';
        errore.value = '';
    }
});

// I minuti da aspettare vengono dal server (`Retry-After`, in secondi); senza, il testo non promette un tempo.
function messaggioDelLimite(retryAfter: unknown): string {
    const secondi = Number(retryAfter);
    if (!Number.isFinite(secondi) || secondi <= 0) return trans('auth.confirm_password_dialog.too_many_later');

    const minuti = Math.ceil(secondi / 60);

    return transChoice('auth.confirm_password_dialog.too_many', minuti, { count: String(minuti) });
}

async function conferma() {
    if (inCorso.value) return;

    inCorso.value = true;
    errore.value = '';
    let confermata = false;

    try {
        await axios.post(route('password.confirm'), { password: password.value }, { headers: { Accept: 'application/json' } });
        confermata = true;
    } catch (e: any) {
        const stato = e?.response?.status;

        if (stato === 422) {
            errore.value = e.response.data?.errors?.password?.[0] ?? trans('auth.confirm_password_dialog.error_generic');
        } else if (stato === 429) {
            errore.value = messaggioDelLimite(e.response.headers?.['retry-after']);
        } else if (stato === 401 || stato === 419) {
            // Con la sessione finita riprovare non può riuscire: si dice di ricaricare, senza farlo da soli (il modulo sotto resta com'è).
            errore.value = trans('auth.confirm_password_dialog.session_ended');
        } else {
            errore.value = trans('auth.confirm_password_dialog.error_generic');
        }

        password.value = '';
        await nextTick();
        campo()?.focus();
    } finally {
        inCorso.value = false;
    }

    // Fuori dal `try`: un errore della richiesta rifatta non è una password sbagliata.
    if (confermata) {
        password.value = '';
        confermaRiuscita();
    }
}

function quandoSiChiude(aperta: boolean) {
    if (!aperta) annullaConferma();
}
</script>

<template>
    <Dialog :open="confermaPasswordAperta" @update:open="quandoSiChiude">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-6" @submit.prevent="conferma">
                <DialogHeader class="space-y-3">
                    <DialogTitle>{{ trans('auth.confirm_password_dialog.title') }}</DialogTitle>
                    <DialogDescription>{{ trans('auth.confirm_password_dialog.description') }}</DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label :for="ID_CAMPO" class="sr-only">{{ trans('auth.label.confirm_password.password') }}</Label>
                    <Input
                        :id="ID_CAMPO"
                        v-model="password"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        :placeholder="trans('auth.placeholder.confirm_password.password')"
                    />
                    <InputError :message="errore" />
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="annullaConferma">
                        {{ trans('auth.confirm_password_dialog.cancel') }}
                    </Button>
                    <Button type="submit" :disabled="inCorso">
                        {{ trans('auth.confirm_password_dialog.confirm') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
