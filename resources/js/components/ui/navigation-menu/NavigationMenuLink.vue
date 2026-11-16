<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { NavigationMenuLinkEmits, NavigationMenuLinkProps } from "reka-ui"
import {
  NavigationMenuLink,
  useForwardPropsEmits,
} from "reka-ui"

const props = defineProps<NavigationMenuLinkProps>()
const emits = defineEmits<NavigationMenuLinkEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <NavigationMenuLink v-bind="forwarded">
    <slot />
  </NavigationMenuLink>
</template>
