<script setup>
import {onMounted, reactive, ref} from "vue";
import {useToast} from "vue-toastification";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";
import Button from "../../components/forms/Button.vue";
import GsmService from "../../services/GsmService.js";

const toast = useToast();
const entries = ref([]);
const loading = ref(false);
const form = reactive({
  number: '',
  label: '',
  priority: 'normal'
});

const priorityOptions = [
  {value: 'high', label: 'Vysoká'},
  {value: 'normal', label: 'Normální'},
  {value: 'low', label: 'Nízká'}
];

onMounted(fetchWhitelist);

function resetForm() {
  form.number = '';
  form.label = '';
  form.priority = 'normal';
}

async function fetchWhitelist() {
  loading.value = true;
  try {
    entries.value = await GsmService.fetchWhitelist();
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst whitelist GSM');
  } finally {
    loading.value = false;
  }
}

async function addEntry() {
  if (!form.number) {
    toast.warning('Zadejte telefonní číslo');
    return;
  }
  try {
    const entry = await GsmService.createWhitelist({
      number: form.number,
      label: form.label,
      priority: form.priority,
    });
    entries.value.push(entry);
    toast.success('Vstup byl přidán do whitelistu');
    resetForm();
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se přidat vstup');
  }
}

async function updateEntry(entry) {
  try {
    await GsmService.updateWhitelist(entry.id, {
      number: entry.number,
      label: entry.label,
      priority: entry.priority,
    });
    toast.success('Záznam byl aktualizován');
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se aktualizovat záznam');
  }
}

async function removeEntry(entry) {
  if (!confirm('Opravdu chcete odstranit tento záznam z whitelistu?')) {
    return;
  }
  try {
    await GsmService.deleteWhitelist(entry.id);
    entries.value = entries.value.filter(item => item.id !== entry.id);
    toast.info('Záznam byl odstraněn');
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se odstranit záznam');
  }
}
</script>

<template>
  <PageContent label="Nastavení GSM (Waveshare SIM7600G-H-PCIE)">
    <div class="grid gap-6 md:grid-cols-2">
      <Box label="Whitelist">
        <div class="space-y-4">
          <Input v-model="form.number" label="Telefonní číslo" placeholder="např.: +420123456789"/>
          <Input v-model="form.label" label="Popis" placeholder="Identifikace volajícího"/>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Priorita</label>
            <select v-model="form.priority" class="form-select w-full">
              <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </div>
          <Button icon="mdi-plus" label="Přidat do whitelistu" :disabled="loading" @click="addEntry"/>
        </div>
      </Box>

      <Box label="Aktuální whitelist">
        <div v-if="entries.length === 0" class="text-sm text-gray-500">Žádné položky nejsou evidovány.</div>
        <table v-else class="table text-sm">
          <thead>
          <tr>
            <th>Číslo</th>
            <th>Popis</th>
            <th>Priorita</th>
            <th class="text-right">Akce</th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="entry in entries" :key="entry.id">
            <td><input v-model="entry.number" class="form-input w-full"/></td>
            <td><input v-model="entry.label" class="form-input w-full"/></td>
            <td>
              <select v-model="entry.priority" class="form-select w-full">
                <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
            </td>
            <td class="flex gap-2 justify-end">
              <Button size="xs" icon="mdi-content-save" @click="updateEntry(entry)"/>
              <Button size="xs" variant="danger" icon="mdi-delete" @click="removeEntry(entry)"/>
            </td>
          </tr>
          </tbody>
        </table>
      </Box>
    </div>
  </PageContent>
</template>
