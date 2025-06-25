<script setup lang="ts">
import { computed, toRef, watch, ref, onMounted } from 'vue';
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
import type { Flash } from '@/types/flash';
import { LoaderCircle } from 'lucide-vue-next';

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

function submit() {
  form.put(route(generateRoute('settings.notifications.update')), {
    preserveScroll: true
  });
}

</script>

<template>
  <AppLayout>
    <Head title="Impostazioni notifiche" />

    <SettingsLayout>
      <div v-if="flashMessage" class="py-2">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="flex flex-col space-y-6 max-w-3xl mx-auto">
        <HeadingSmall title="Impostazioni notifiche" description="Di seguito puoi selezionare le notifiche email che vuoi ricevere" />

         <div v-if="preferences.length === 0" class="space-y-4 rounded-lg border border-blue-100 bg-blue-50 p-4 dark:border-blue-200/10 dark:bg-blue-700/10">
            <div class="relative space-y-0.5 text-blue-600 dark:text-blue-100">
                <p class="text-sm">Nessuna notifica email disponibile da selezionare.</p>
            </div>
        </div>

        <!-- Show preferences form only if there are preferences -->
        <form v-else @submit.prevent="submit">
          <Card class="border-none shadow-none pl-0">
            <CardContent class="space-y-4 pl-0">
              <div
                v-for="pref in preferences"
                :key="pref.type"
                class="flex items-center justify-between gap-4"
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
                Salva preferenze
              </Button>
            </CardFooter>
          </Card>
        </form>
      </div>
    </SettingsLayout>
  </AppLayout>
</template>
