<script setup>

import {computed, onMounted, ref} from "vue";
import SettingsService from "../../services/SettingsService.js";
import {useDisableAutocomplete} from "../../utils/diableAutocompleteTrait.js";
import {useToast} from "vue-toastification";

useDisableAutocomplete();
const toast = useToast();

const smtpSettings = ref({
  host: '',
  port: '',
  encryption: '',
  username: '',
  password: '',
  from_address: '',
  from_name: '',
});

onMounted(() => {
  fetchSmtpSettings();
});

function fetchSmtpSettings() {
  SettingsService.fetchSmtpSettings().then(response => {
    smtpSettings.value = response;
  }).catch(error => {
    console.error(error);
  });
}

function saveSmtpSettings() {
  SettingsService.saveSmtpSettings(smtpSettings.value).then(() => {
    toast.success('Nastavení SMTP serveru bylo úspěšně uloženo');
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se uložit nastavení SMTP serveru');
  });
}

const cantSave = computed(() => {
  let retVal = false;
  if (smtpSettings.value.host === '') {
    retVal = true;
  }
  if (smtpSettings.value.port === '') {
    retVal = true;
  }
  if (!['TCP', 'SSL', 'TLS'].includes(smtpSettings.value.encryption)) {
    retVal = true;
  }
  if (smtpSettings.value.username === '') {
    retVal = true;
  }
  if (smtpSettings.value.password === '') {
    retVal = true;
  }
  if (smtpSettings.value.from_address === '') {
    retVal = true;
  }
  if (smtpSettings.value.from_name === '') {
    retVal = true;
  }
  return retVal;
});

</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Nastavení SMTP komunikace</h1>
    <div class="content flex flex-col space-y-4">
      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Nastavení poštovního serveru
          </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <label for="smtp-host" class="label">
            <span class="label-text">Adresa SMTP serveru:</span>
          </label>
          <div class="form-control w-full">
            <input id="smtp-host" v-model="smtpSettings.host" type="text" placeholder="Např.: smtp.seznam.cz" class="input input-bordered w-full"/>
          </div>

          <label for="smtp-port" class="label">
            <span class="label-text">Port SMTP serveru:</span>
          </label>
          <div class="form-control w-full">
            <input id="smtp-port" v-model="smtpSettings.port" type="number" placeholder="Např.: 465" class="input input-bordered w-full"/>
          </div>

          <label for="smtp-encryption" class="label">
            <span class="label-text">Typ připojení:</span>
          </label>
          <div class="form-control w-full">
            <select id="smtp-encryption" v-model="smtpSettings.encryption" class="select select-bordered w-full">
              <option value="TCP">TCP</option>
              <option value="SSL">SSL</option>
              <option value="TLS">TLS</option>
            </select>
          </div>

          <label for="smtp-username" class="label">
            <span class="label-text">Uživatelské jméno:</span>
          </label>
          <div class="form-control w-full">
            <input id="smtp-username" v-model="smtpSettings.username" type="text" placeholder="Např.: jan.novak" class="input input-bordered w-full" autocomplete="off"/>
          </div>

          <label for="smtp-password" class="label">
            <span class="label-text">Heslo:</span>
          </label>
          <div class="form-control w-full">
            <input id="smtp-password" v-model="smtpSettings.password" type="password" placeholder="Např.: 1234567890" class="input input-bordered w-full" autocomplete="off"/>
          </div>

          <label for="smtp-from_address" class="label">
            <span class="label-text">E-mailová adresa odesílatele:</span>
          </label>
          <div class="form-control w-full">
            <input id="smtp-from_address" v-model="smtpSettings.from_address" type="email" placeholder="Např.: jan.novak@seznam.cz" class="input input-bordered w-full"/>
          </div>

          <label for="smtp-from_name" class="label">
            <span class="label-text">Jméno odesílatele:</span>
          </label>
          <div class="form-control w-full">
            <input id="smtp-from_name" v-model="smtpSettings.from_name" type="text" placeholder="Např.: Jan Novák" class="input input-bordered w-full"/>
          </div>

          <div class="col-span-1 md:col-span-2 mt-4 flex justify-end">
            <button class="btn btn-primary" @click="saveSmtpSettings" :disabled="cantSave">Uložit</button>
          </div>

        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>