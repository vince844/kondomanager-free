<script setup lang="ts">

import { ref } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermission } from "@/composables/permissions";

const { generateRoute } = usePermission();

const props = defineProps<{
  preferences: Array<{
    type: string;
    label: string;
    description?: string;
    enabled: boolean;
  }>;
}>();

const form = ref<Record<string, boolean>>({});

props.preferences.forEach(pref => {
  form.value[pref.type] = pref.enabled;
});

function submit() {
  const payload = Object.entries(form.value).map(([type, enabled]) => ({ type, enabled }));
  console.log(payload);
  router.put(route(generateRoute('settings.notifications.update')), { preferences: payload });
}
</script>

<template>
  <AppLayout>
    <Head title="Impostazioni notifiche" />

    <SettingsLayout>
      <div class="flex flex-col space-y-6 max-w-3xl mx-auto">
        <HeadingSmall title="Impostazioni notifiche" description="Seleziona le notifiche che vuoi ricevere" />

        <form @submit.prevent="submit" >
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
                  :model-value="form[pref.type]"
                  @update:modelValue="val => form[pref.type] = val"
                />

              </div>
            </CardContent>

            <CardFooter class="pl-0">
              <Button type="submit">Salva preferenze</Button>
            </CardFooter>
          </Card>
        </form>
      </div>
    </SettingsLayout>
  </AppLayout>
</template>

