window.personalDepositBatch = (() => {
    async function save(item, result, currentGeneration, getGeneration) {
        item.status.classList.remove('is-error');
        item.status.textContent = `Guardando ${result.records.length} depósitos automáticamente…`;
        const data = new FormData();
        data.set('csrf_token', window.personalDepositGemini.csrf);
        data.set('batch_token', result.batch_token);
        data.set('foto', item.file, item.file.name);
        const response = await fetch(window.personalDepositGemini.batchSaveEndpoint,
            {method: 'POST', body: data, credentials: 'same-origin'});
        const saved = await response.json();
        if (currentGeneration !== getGeneration()) return [];
        if (!response.ok) throw new Error(saved.error || 'No se pudo guardar el lote.');
        item.saved = true;
        item.retry.hidden = true;
        item.save.hidden = true;
        item.status.textContent = `${saved.records.length} depósitos guardados correctamente.`;
        return saved.records;
    }

    return {save};
})();
