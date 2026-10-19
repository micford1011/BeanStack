document.addEventListener('DOMContentLoaded', () => {
  const menuToggle = document.getElementById('menuToggle');
  if (menuToggle) menuToggle.addEventListener('click', () => document.body.classList.toggle('nav-open'));

  document.querySelectorAll('[data-dismiss]').forEach(btn => btn.addEventListener('click', () => btn.parentElement.remove()));

  document.querySelectorAll('[data-modal-open]').forEach(btn => {
    btn.addEventListener('click', () => {
      const modal = document.getElementById(btn.dataset.modalOpen);
      if (!modal) return;
      modal.classList.add('open');
      if (btn.dataset.stockin) {
        const select = document.getElementById('alertProduct');
        if (select) select.value = btn.dataset.stockin;
      }
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach(btn => btn.addEventListener('click', () => {
    const modal = btn.closest('.modal-backdrop');
    if (modal) modal.classList.remove('open');
  }));
  document.querySelectorAll('.modal-backdrop').forEach(modal => modal.addEventListener('click', event => {
    if (event.target === modal) modal.classList.remove('open');
  }));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
  });

  const search = document.getElementById('tableSearch');
  const table = document.getElementById('dataTable');
  if (search && table) search.addEventListener('input', () => {
    const query = search.value.toLowerCase();
    table.querySelectorAll('tbody tr').forEach(row => row.hidden = !row.innerText.toLowerCase().includes(query));
  });

  const productForm = document.getElementById('productForm');
  document.querySelectorAll('.edit-product').forEach(btn => btn.addEventListener('click', () => {
    const p = JSON.parse(btn.dataset.product);
    document.getElementById('productModalTitle').textContent = 'Edit product';
    document.getElementById('productId').value = p.id;
    document.getElementById('productCode').value = p.product_code;
    document.getElementById('productName').value = p.name;
    document.getElementById('productCategory').value = p.category_id || '';
    document.getElementById('productUnit').value = p.unit;
    document.getElementById('productStock').value = p.current_stock;
    document.getElementById('productMinimum').value = p.minimum_stock;
    document.getElementById('productCost').value = p.unit_cost;
    document.getElementById('productModal').classList.add('open');
  }));
  const productModal = document.getElementById('productModal');
  if (productModal && productForm) productModal.addEventListener('click', event => {
    if (event.target === productModal) {
      productModal.classList.remove('open');
    }
  });

  if (window.beanStackData && window.Chart) {
    const d = window.beanStackData;
    const palette = { in: '#a77958', out: '#4a2e1e', grid: '#eee5db', text: '#817469' };
    const ctx = document.getElementById('stockChart');
    if (ctx) new Chart(ctx, {
      type: 'bar',
      data: { labels: d.labels, datasets: [
        { label: 'Stock In', data: d.stockIn, backgroundColor: palette.in, borderRadius: 4, maxBarThickness: 22 },
        { label: 'Stock Out', data: d.stockOut, backgroundColor: palette.out, borderRadius: 4, maxBarThickness: 22 }
      ]},
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false }, ticks: { color: palette.text, font: { size: 10 } } },
          y: { beginAtZero: true, grid: { color: palette.grid }, ticks: { color: palette.text, font: { size: 10 } } } } }
    });
    const categoryCtx = document.getElementById('categoryChart');
    if (categoryCtx) new Chart(categoryCtx, {
      type: 'doughnut',
      data: { labels: d.categoryLabels, datasets: [{ data: d.categoryValues, backgroundColor: ['#4a2e1e','#a77958','#d7b69c','#876044','#c7a17e','#ead8c5'], borderWidth: 3, borderColor: '#fffdf9' }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { color: '#75665a', boxWidth: 9, padding: 12, font: { size: 10 } } } } }
    });
  }
});
