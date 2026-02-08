<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api/client'
import { UiAlert, UiLoading } from '../components/ui'

const status = ref(null)
const error = ref(null)

onMounted(async () => {
  try {
    status.value = await api.get('/')
  } catch (e) {
    error.value = e.message
  }
})
</script>

<template>
  <div class="home">
    <h1>Bitrix25 Planner</h1>
    <p v-if="status">{{ status.app }} — {{ status.status }}</p>
    <UiAlert v-else-if="error" variant="error">API: {{ error }}</UiAlert>
    <UiLoading v-else />
  </div>
</template>

<style scoped lang="scss">
.home {
  padding: 1rem;
}
</style>
