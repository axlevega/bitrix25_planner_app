<script setup>
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="layout">
    <nav class="nav">
      <RouterLink to="/" class="nav__link">Главная</RouterLink>
      <RouterLink to="/departments" class="nav__link">Отделы</RouterLink>
      <RouterLink to="/specialists" class="nav__link">Специалисты</RouterLink>
      <RouterLink to="/integration-settings" class="nav__link">Настройки интеграции</RouterLink>
    </nav>
    <main class="main">
      <router-view />
    </main>
  </div>
</template>

<style lang="scss" scoped>
.layout {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}
.nav {
  display: flex;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  flex-wrap: wrap;
}
.nav__link {
  color: var(--color-primary);
  text-decoration: none;
  padding: 0.35rem 0.6rem;
  border-radius: 4px;
  font-weight: 500;
  &:hover {
    background: #edf2f7;
  }
  &.router-link-active {
    background: var(--color-primary);
    color: #fff;
  }
}
.main {
  flex: 1;
  padding: 1rem;
  max-width: var(--max-width);
  margin: 0 auto;
  width: 100%;
}
</style>
