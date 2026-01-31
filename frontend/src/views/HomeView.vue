<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api/client'

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
    <p v-else-if="error" class="error">API: {{ error }}</p>
    <p v-else>Загрузка…</p>
  </div>
</template>

<style scoped lang="scss">
.home {
  padding: 1rem;
}
.error {
  color: var(--color-error, #c00);
}
</style>
