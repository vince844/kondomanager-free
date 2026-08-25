<script setup lang="ts">

import { computed, toRef, watch } from 'vue';
import { Head, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import { LoaderCircle } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import { trans } from 'laravel-vue-i18n';

const { generateRoute } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Make preferences reactive by creating a ref from props
const props = defineProps<{
  preferences: Array<{
    type: string;
    label: string;
    description?: string;
    enabled: boolean;
  }>;
}>();

const preferences = toRef(props, 'preferences');

// Initialize form with reactive preferences
const form = useForm({
  preferences: preferences.value.map(pref => ({
    type: pref.type,
    enabled: pref.enabled
  })),
});

// Sync form.preferences whenever preferences prop changes
watch(preferences, (newPrefs) => {
  form.preferences = newPrefs.map(pref => ({
    type: pref.type,
    enabled: pref.enabled
  }));
});

function updateEnabled(type: string, value: boolean) {
  const target = form.preferences.find(pref => pref.type === type);
  if (target) target.enabled = value;
}

/**
 * Quante ne sono accese, per dire all'utente cosa sta guardando prima che clicchi.
 *
 * ⚠️ Si conta su `form.preferences`, non sulla prop: dopo un «attiva tutte» il numero deve
 * cambiare subito, altrimenti il pulsante sembra non aver fatto niente e si clicca due volte.
 */
const attive = computed(() => form.preferences.filter(p => p.enabled).length);
const totali = computed(() => form.preferences.length);

/**
 * Accende o spegne tutto in un colpo.
 *
 * ⚠️ **Cambia solo il modulo, non salva.** La pagina ha un pulsante «Salva preferenze» esplicito, e
 * un'azione che scrivesse a database di nascosto sarebbe l'unica della schermata a comportarsi
 * così: chi clicca per sbaglio deve poter uscire senza aver cambiato niente.
 *
 * ⚠️ Tocca **solo le preferenze che l'utente vede davvero**, perché itera quelle del modulo — che
 * il server ha già filtrato per permesso. Un fornitore non accende con questo pulsante notifiche
 * che nella sua pagina non compaiono.
 */
function impostaTutte(valore: boolean) {
  form.preferences.forEach(pref => { pref.enabled = valore; });
}

function submit() {
  form.put(route(generateRoute('settings.notifications.update')), {
    preserveScroll: true
  });
}

</script>

<template>
  <AppLayout>
    <Head :title="trans('settings.notifications.title')" />

    <SettingsLayout contentClass="w-full">
      <div v-if="flashMessage" class="py-2">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="flex flex-col space-y-6 ">
        <HeadingSmall :title="trans('settings.notifications.heading')" :description="trans('settings.notifications.description')" />

         <div v-if="preferences.length === 0" class="space-y-4 rounded-lg border border-blue-100 bg-blue-50 p-4 dark:border-blue-200/10 dark:bg-blue-700/10">
            <div class="relative space-y-0.5 text-blue-600 dark:text-blue-100">
                <p class="text-sm">{{ trans('settings.notifications.empty') }}</p>
            </div>
        </div>

        <!-- Show preferences form only if there are preferences -->
        <form v-else @submit.prevent="submit">
          <Card class="border-none shadow-none pl-0">
            <CardContent class="space-y-4 pl-0">

              <!--
                Le notifiche sono diventate dodici, e ne arriveranno altre: senza queste due azioni
                spegnerle tutte vuol dire dodici clic. Il conteggio accanto serve a vedere l'effetto
                **prima** di salvare, che è l'unico momento in cui si può ancora tornare indietro.
              -->
              <div class="flex flex-wrap items-center justify-between gap-3 pb-1">
                <p class="text-sm text-muted-foreground">
                  {{ trans('settings.notifications.counter', { attive: attive, totali: totali }) }}
                </p>
                <div class="flex items-center gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="attive === totali"
                    @click="impostaTutte(true)"
                  >
                    {{ trans('settings.notifications.enable_all') }}
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="attive === 0"
                    @click="impostaTutte(false)"
                  >
                    {{ trans('settings.notifications.disable_all') }}
                  </Button>
                </div>
              </div>

              <div
                v-for="pref in preferences"
                :key="pref.type"
                class="flex items-center justify-between gap-4 border p-3 rounded"
              >
                <div>
                  <Label class="block text-sm font-medium leading-none">{{ pref.label }}</Label>
                  <p class="text-sm text-muted-foreground">{{ pref.description }}</p>
                </div>

                <Switch
                  :model-value="form.preferences.find(p => p.type === pref.type)?.enabled"
                  @update:modelValue="val => updateEnabled(pref.type, val)"
                />
              </div>
            </CardContent>

            <CardFooter class="pl-0 flex items-center gap-4">
              <Button :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                {{ trans('settings.notifications.save') }}
              </Button>
            </CardFooter>
          </Card>
        </form>
      </div>
    </SettingsLayout>
  </AppLayout>
</template>
