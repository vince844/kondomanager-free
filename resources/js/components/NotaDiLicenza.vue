<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<!--
  La riga della licenza in fondo alle pagine (Coda 493, decisioni 686 e 687): l'avviso di copyright, la licenza, «Note legali» e il codice sorgente, in
  una riga sola. «Note legali» apre una finestra piccola (standard delle finestre, decisione 608: gruppi con il titolo grigio, ancorata in alto, il
  pulsante principale nero) con le quattro cose delle «Appropriate Legal Notices» (LICENSE, sezione 0): il copyright, la licenza, la garanzia, come
  leggere la licenza. La garanzia dice «senza alcuna garanzia», e «salvo quelle dei termini del servizio» con il collegamento solo dove l'istanza ha
  impostato l'indirizzo (`termini_del_servizio_url`, vuoto di serie).
  Un componente solo, usato da tutte le pagine che mostrano la nota: nessuna pagina ne scrive una sua versione.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';

const LICENZA = 'https://www.gnu.org/licenses/agpl-3.0.html';
const CODICE = 'https://github.com/vince844/kondomanager-free';

// `versione`: la sola riga del piè di pagina dell'area privata la chiede (a sinistra, con le icone a destra); le pagine d'accesso no.
const props = defineProps<{ conVersione?: boolean }>();

const page = usePage();
const noteAperte = ref(false);
const versione = computed(() => (page.props.version as string | null | undefined) ?? null);
const termini = computed(() => (page.props.termini_del_servizio_url as string | null | undefined) || null);

defineExpose({ noteAperte });
</script>

<template>
    <div data-nota-di-licenza class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-center text-xs text-muted-foreground" :class="props.conVersione ? 'md:justify-start md:text-left' : ''">
        <span>© Vincenzo Vecchio — KondoManager<template v-if="props.conVersione && versione"> v{{ versione }}</template></span>
        <span aria-hidden="true">·</span>
        <a :href="LICENZA" target="_blank" rel="noopener noreferrer" class="underline-offset-2 transition-colors hover:text-foreground hover:underline">Licenza GNU AGPL-3.0</a>
        <span aria-hidden="true">·</span>
        <button type="button" data-note-legali class="underline-offset-2 transition-colors hover:text-foreground hover:underline" @click="noteAperte = true">Note legali</button>
        <span aria-hidden="true">·</span>
        <a :href="CODICE" target="_blank" rel="noopener noreferrer" class="underline-offset-2 transition-colors hover:text-foreground hover:underline">Codice sorgente</a>

        <Dialog v-model:open="noteAperte">
            <DialogContent data-note-legali-finestra :show-close-button="false" class="top-[10vh] max-h-[80vh] translate-y-0 gap-0 overflow-y-auto p-0 text-left sm:max-w-[520px]">
                <div class="border-b px-[18px] py-3.5">
                    <DialogTitle class="text-[15px] font-semibold">Note legali</DialogTitle>
                    <DialogDescription class="text-xs text-muted-foreground">KondoManager<template v-if="versione">, versione {{ versione }}</template></DialogDescription>
                </div>
                <div class="px-[18px] py-1.5">
                    <section data-gruppo="copyright" class="border-b py-3">
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Copyright</h3>
                        <p class="text-[13px]">© Vincenzo Vecchio — KondoManager.</p>
                    </section>
                    <section data-gruppo="licenza" class="border-b py-3">
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Licenza</h3>
                        <p class="text-[13px]">KondoManager è software libero: si può ridistribuire e modificare secondo i termini della GNU Affero General Public License, versione 3.</p>
                    </section>
                    <section data-gruppo="garanzia" class="border-b py-3">
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Garanzia</h3>
                        <p v-if="termini" class="text-[13px]">
                            È fornito senza alcuna garanzia, salvo quelle dei <a data-termini :href="termini" target="_blank" rel="noopener noreferrer" class="text-sky-700 underline underline-offset-2 dark:text-sky-300">termini del servizio</a>.
                        </p>
                        <p v-else class="text-[13px]">È fornito senza alcuna garanzia, nemmeno di commerciabilità o di idoneità a uno scopo particolare.</p>
                    </section>
                    <section data-gruppo="lettura" class="py-3">
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Come leggere la licenza</h3>
                        <p class="text-[13px]">
                            Il testo completo è su <a :href="LICENZA" target="_blank" rel="noopener noreferrer" class="text-sky-700 underline underline-offset-2 dark:text-sky-300">gnu.org/licenses/agpl-3.0</a>;
                            il codice sorgente è su <a :href="CODICE" target="_blank" rel="noopener noreferrer" class="text-sky-700 underline underline-offset-2 dark:text-sky-300">GitHub</a>.
                        </p>
                    </section>
                </div>
                <div class="flex justify-end gap-2 rounded-b-lg border-t bg-muted/40 px-[18px] py-3">
                    <button type="button" data-chiudi class="inline-flex h-8 items-center rounded-lg bg-primary px-3.5 text-[13px] font-semibold text-primary-foreground" @click="noteAperte = false">Chiudi</button>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
