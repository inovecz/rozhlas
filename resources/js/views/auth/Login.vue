<script setup>
import imgUrl from '../../../img/background.jpg';
import {computed, ref} from "vue";
import router from "../../router.js";
import {jwtDecode} from "jwt-decode";

const username = ref('');
const password = ref('');

const toast = useToast();

const bgStyle = computed(() => {
  return {
    'background-image': `url(${imgUrl})`,
    'background-size': 'cover',
    'background-position': 'center',
  };
});

const login = () => {
  http.post('/auth/login', {
    username: username.value,
    password: password.value,
  }).then(response => {
    localStorage.setItem('token', response.data.access_token);
    const decodedJwt = jwtDecode(response.data.access_token);
    localStorage.setItem('username', decodedJwt.username);
    router.push('/live-broadcast')
  }).catch(() => {
    toast.error('Přihlášení se nezdařilo');
  });
}

</script>

<template>
  <div class="min-h-full flex items-center justify-center" :style="bgStyle">
    <div class="min-w-96 bg-zinc-800/50 backdrop-blur-md p-8 rounded shadow-md border border-white text-gray-50">
      <div class="text-2xl font-semibold mb-6">Administrace</div>

      <!-- Form -->
      <form @submit.prevent="login">
        <!-- Username Input -->
        <div class="mb-4 w-full">
          <label class="block mb-1 text-sm" for="username">Přihlašovací jméno:</label>

          <input id="username" class="w-full text-zinc-900 border px-4 py-2 rounded focus:border-blue-500 focus:shadow-outline outline-none"
                 type="text" autofocus placeholder=""
                 v-model="username" required/>
        </div>

        <div class="mb-4 w-full">
          <label class="block mb-1 text-sm" for="username">Heslo:</label>

          <input id="password" class="w-full text-zinc-900 border px-4 py-2 rounded focus:border-blue-500 focus:shadow-outline outline-none"
                 type="password" placeholder=""
                 v-model="password" required/>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600 focus:outline-none focus:shadow-outline-blue">
          Přihlásit
        </button>
      </form>
    </div>
  </div>
</template>