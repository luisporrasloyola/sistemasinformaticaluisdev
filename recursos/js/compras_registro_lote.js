window.purchaseBatch = (() => {
    async function save(item, result, currentGeneration, getGeneration, corrections = null) {
        item.status.classList.remove('is-error');
        item.status.textContent = `Guardando ${result.records.length} compras automáticamente…`;
        const data = new FormData();
        data.set('csrf_token', window.purchaseRegistration.csrf);
        data.set('batch_token', result.batch_token);
        if (corrections) data.set('records', JSON.stringify(corrections));
        data.set('foto', item.file, item.file.name);
        const response = await fetch(window.purchaseRegistration.batchSaveEndpoint,
            {method: 'POST', body: data, credentials: 'same-origin'});
        const saved = await response.json();
        if (currentGeneration !== getGeneration()) return [];
        if (!response.ok) throw new Error(saved.error || 'No se pudo guardar el lote.');
        item.saved = true;
        item.retry.hidden = true;
        item.save.hidden = true;
        item.status.textContent = `${saved.records.length} compras guardadas correctamente.`;
        return saved.records;
    }

    return {save};
})();
