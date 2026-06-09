document.addEventListener('DOMContentLoaded', () => {
    const tableBody = document.getElementById('pokemon-table-body');
    const nameInput = document.getElementById('search-input');
    const nameDropdown = document.getElementById('search-dropdown');
    const ingInput = document.getElementById('search-ing-input');
    const ingDropdown = document.getElementById('search-ing-dropdown');
    const ticketCheckbox = document.getElementById('ticket-checkbox');
    const oteboDropdown = document.getElementById('otebo-dropdown');
    const themeToggle = document.getElementById('theme-toggle');
    let originalData = [];
    let addedData = JSON.parse(localStorage.getItem('addedPokemon') || '[]');
    // ensure added entries are flagged as non-original for green background
    addedData = addedData.map(item => Object.assign({}, item, { _orig: false }));
    let pokemonData = [];

    // Add/Edit modal elements
    const addBtn = document.getElementById('add-btn');
    const modal = document.getElementById('add-modal');
    const closeBtn = modal.querySelector('.close');
    const formName = document.getElementById('form-name');
    const formIng = document.getElementById('form-ing');
    const formAmount = document.getElementById('form-amount');
    const formLevel = document.getElementById('form-level');
    const formIngPercent = document.getElementById('form-ing_percent');
    const formHelpSpeed = document.getElementById('form-help_speed');
    const formOtebo = document.getElementById('form-otebo');
    const formSpM = document.getElementById('form-spM');
    const formSpS = document.getElementById('form-spS');
    const formIngS = document.getElementById('form-ingS');
    const formIngM = document.getElementById('form-ingM');
    const formNature = document.getElementById('form-nature');
    const saveBtn = document.getElementById('save-btn');

    function setTheme(theme, persist = true) {
        document.documentElement.dataset.theme = theme;
        if (themeToggle) {
            const isDark = theme === 'dark';
            themeToggle.textContent = isDark ? '淺色模式' : '深色模式';
            themeToggle.setAttribute('aria-pressed', String(isDark));
        }
        if (persist) {
            localStorage.setItem('theme', theme);
        }
    }

    const storedTheme = localStorage.getItem('theme');
    const prefersDarkQuery = window.matchMedia('(prefers-color-scheme: dark)');
    setTheme(storedTheme || (prefersDarkQuery.matches ? 'dark' : 'light'), Boolean(storedTheme));
    themeToggle?.addEventListener('click', () => {
        setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
    });
    prefersDarkQuery.addEventListener('change', event => {
        if (!localStorage.getItem('theme')) {
            setTheme(event.matches ? 'dark' : 'light', false);
        }
    });

    // dynamically populate ingredient and amount based on selected Pokémon name
    formName.addEventListener('input', () => {
        const selName = formName.value;
        const related = originalData.filter(e => e.name === selName);
        // clear previous selects
        formIng.innerHTML = '<option value="">-- 請選擇 --</option>';
        formAmount.innerHTML = '<option value="">-- 請選擇 --</option>';
        if (related.length) {
            // known Pokémon: limit to related ingredients and amounts
            const uniqueIngs = Array.from(new Set(related.map(e => e.ing))).sort();
            uniqueIngs.forEach(ing => {
                const opt = document.createElement('option'); opt.value = ing; opt.textContent = ing;
                formIng.appendChild(opt);
            });
            // auto-select first ingredient
            formIng.value = uniqueIngs[0];
            // populate amount options for this ingredient
            formIng.dispatchEvent(new Event('change'));
        } else {
            // arbitrary name: all ingredients and fixed range 2-15
            const allIngs = Array.from(new Set(originalData.map(e => e.ing))).sort();
            allIngs.forEach(ing => {
                const opt = document.createElement('option'); opt.value = ing; opt.textContent = ing; formIng.appendChild(opt);
            });
            for (let i = 2; i <= 15; i++) {
                const opt = document.createElement('option'); opt.value = i; opt.textContent = i; formAmount.appendChild(opt);
            }
        }
    });
    // repopulate amounts and auto-fill help/percent only for known Pokémon
    formIng.addEventListener('change', () => {
        const selName = formName.value;
        const selIng = formIng.value;
        const related = originalData.filter(e => e.name === selName && e.ing === selIng);
        if (!related.length) return;
        formAmount.innerHTML = '<option value="">-- 請選擇 --</option>';
        const uniqueAmts = Array.from(new Set(related.map(e => e.amount))).sort((a, b) => a - b);
        uniqueAmts.forEach(a => {
            const opt = document.createElement('option'); opt.value = a; opt.textContent = a; formAmount.appendChild(opt);
        });
        formAmount.value = uniqueAmts[0];
        // auto-fill defaults
        formHelpSpeed.value = related[0].help_speed;
        formIngPercent.value = related[0].ing_percent;
    });
    // after amount is selected, refine auto-fill if specific
    formAmount.addEventListener('change', () => {
        const selName = formName.value;
        const selIng = formIng.value;
        const selAmt = formAmount.value;
        const match = originalData.find(e => e.name === selName && e.ing === selIng && String(e.amount) === selAmt);
        if (match) {
            formHelpSpeed.value = match.help_speed;
            formIngPercent.value = match.ing_percent;
        }
    });
    let isEditing = false, editingIndex = null;
    // to remember filters when opening modal
    let savedFilters = {};

    function renderTable(data) {
        tableBody.innerHTML = '';
        data.forEach((entry, index) => {
            const tr = document.createElement('tr');
            if (!entry._orig) tr.classList.add('new-entry');
            ['name', 'ing', 'amount', 'level', 'help_speed', 'ing_percent'].forEach(key => {
                const td = document.createElement('td');
                td.textContent = entry[key];
                tr.appendChild(td);
            });
            // display form fields: 幫手獎勵, 幫忙速度M, 幫忙速度S, 食材機率S, 食材機率M
            [entry.otebo, entry.spM, entry.spS, entry.ingS, entry.ingM].forEach(flag => {
                const td = document.createElement('td');
                td.textContent = flag ? '✔' : '-';
                tr.appendChild(td);
            });
            // display 性格
            const tdNature = document.createElement('td');
            const natureLabels = { sp_up: '速度↑', ing_up: '食材↑', ijippari: '固執', hikaeme: '內斂', sp_down: '速度↓', ing_down: '食材↓' };
            tdNature.textContent = natureLabels[entry.nature] || '-';
            tr.appendChild(tdNature);
            // append average swap count with level-specific multiplier
            const tdAvg = document.createElement('td');
            const percent = parseFloat(entry.ing_percent);
            const lvl = parseFloat(entry.level);
            // compute effective percentage with nature and support flags
            let effPercent = percent;
            // nature modifiers: 內斂 or 食材↑ x1.2, 固執 x0.8
            if (entry.nature === 'hikaeme' || entry.nature === 'ing_up') {
                effPercent *= 1.2;
            } else if (entry.nature === 'ijippari' || entry.nature === 'ing_down') {
                effPercent *= 0.8;
            }
            // support modifiers: 食材機率S (0.18), 食材機率M (0.36)
            const supportFactor = 1 + (entry.ingS ? 0.18 : 0) + (entry.ingM ? 0.36 : 0);
            effPercent *= supportFactor;
            // level-specific multiplier: lvl30 -> half, lvl60 -> one-third
            if (lvl < 60) effPercent *= 0.5;
            else if (lvl === 60) effPercent /= 3;
            const avgSwapCount = effPercent > 0 ? 100 / effPercent : 0;
            tdAvg.textContent = avgSwapCount.toFixed(2);
            tr.appendChild(tdAvg);
            // compute raw actual support speed before modifiers
            const helpSpeed = parseFloat(entry.help_speed);
            const level = parseFloat(entry.level);
            const rawActual = helpSpeed * (1 - (level - 1) * 0.002) * 0.45;
            // apply modifiers to raw actual to get effective support speed
            let actual = rawActual;
            // apply 好露營券 discount if checked
            if (ticketCheckbox && ticketCheckbox.checked) {
                actual = actual / 1.2;
            }
            // apply combined modifiers: 幫手獎勵 count, 幫手獎勵 flag, 幫忙速度M, 幫忙速度S; cap multiplier at minimum 0.65
            const oteboCount = oteboDropdown ? parseInt(oteboDropdown.value, 10) : 0;
            const flagOtebo = entry.otebo ? 0.05 : 0;
            const flagSpM = entry.spM ? 0.14 : 0;
            const flagSpS = entry.spS ? 0.07 : 0;
            let modSum = oteboCount * 0.05 + flagOtebo + flagSpM + flagSpS;
            let multiplier = 1 - modSum;
            if (multiplier < 0.65) multiplier = 0.65;
            actual = actual * multiplier;
            // apply nature modifier: 速度↑ or 固執 => x0.9
            if (['sp_up', 'ijippari'].includes(entry.nature)) {
                actual = actual * 0.9;
            }
            if (['sp_down', 'hikaeme'].includes(entry.nature)) {
                actual = actual * 1.075;
            }
            // append raw actual support speed
            const tdRaw = document.createElement('td');
            tdRaw.textContent = !isNaN(actual) ? actual.toFixed(2) : '';
            tr.appendChild(tdRaw);

            actual = actual + 0.5 * avgSwapCount; // add 5 seconds for each average swap count
            // append effective support speed
            const tdActual = document.createElement('td');
            tdActual.textContent = !isNaN(actual) ? actual.toFixed(2) : '';
            tr.appendChild(tdActual);
            // append 每小時取得數量 using adjusted avgSwapCount
            const tdHour = document.createElement('td');
            const amountCount = parseFloat(entry.amount);
            const denom = actual + avgSwapCount * 5;
            const hourCount = denom > 0 ? (3600 / denom) * amountCount : 0;
            tdHour.textContent = !isNaN(hourCount) ? hourCount.toFixed(2) : '';
            tr.appendChild(tdHour);
            // operation buttons: duplicate-edit for all entries, delete only for added entries
            const tdOp = document.createElement('td');
            // duplicate-edit button
            const dupBtn = document.createElement('button');
            dupBtn.textContent = '複製編輯';
            dupBtn.classList.add('dup-btn');
            dupBtn.addEventListener('click', () => {
                // save current filters
                savedFilters = {
                    nameTerm: nameInput.value,
                    nameDropdown: nameDropdown.value,
                    ingTerm: ingInput.value,
                    ingDropdown: ingDropdown.value,
                    ticketChecked: ticketCheckbox.checked,
                    oteboValue: oteboDropdown.value
                };
                // always duplicate as new entry
                isEditing = false; editingIndex = null;
                showModal();
                // populate form fields
                formName.value = entry.name; formName.dispatchEvent(new Event('change'));
                formIng.value = entry.ing; formAmount.value = entry.amount;
                formLevel.value = entry.level; formHelpSpeed.value = entry.help_speed;
                formIngPercent.value = entry.ing_percent;
                formOtebo.checked = entry.otebo; formSpM.checked = entry.spM;
                formSpS.checked = entry.spS; formIngS.checked = entry.ingS;
                formIngM.checked = entry.ingM; formNature.value = entry.nature;
            });
            tdOp.append(dupBtn);
            // delete button for added entries
            if (!entry._orig) {
                const delBtn = document.createElement('button');
                delBtn.textContent = '刪除';
                delBtn.classList.add('delete-btn');
                delBtn.addEventListener('click', () => {
                    // remove this entry from addedData by matching unique properties
                    const idx = addedData.findIndex(e =>
                        e.name === entry.name && e.ing === entry.ing && e.amount === entry.amount && e.level === entry.level
                        && e.help_speed === entry.help_speed && e.ing_percent === entry.ing_percent
                    );
                    if (idx > -1) addedData.splice(idx, 1);
                    localStorage.setItem('addedPokemon', JSON.stringify(addedData));
                    pokemonData = originalData.concat(addedData);
                    applyFilter();
                });
                tdOp.append(delBtn);
            }
            tr.appendChild(tdOp);
            tableBody.appendChild(tr);
        });
    }

    // Apply filtering whenever either input changes
    function applyFilter() {
        const nameTerm = nameInput.value.trim();
        const dropdownTerm = nameDropdown.value;
        const ingTerm = ingInput.value.trim();
        const dropdownIng = ingDropdown.value;
        const filtered = pokemonData.filter(entry =>
            (dropdownTerm === '' || entry.name === dropdownTerm) &&
            (nameTerm === '' || entry.name.includes(nameTerm)) &&
            (dropdownIng === '' || entry.ing === dropdownIng) &&
            (ingTerm === '' || entry.ing.includes(ingTerm))
        );
        renderTable(filtered);
        // After filtering, default sort by 每小時取得數量 (column index 14) descending
        const rows = Array.from(tableBody.querySelectorAll('tr'));
        rows.sort((a, b) => {
            const vA = parseFloat(a.cells[15].textContent) || 0;
            const vB = parseFloat(b.cells[15].textContent) || 0;
            return vB - vA;
        });
        rows.forEach(r => tableBody.appendChild(r));
    }
    // Listen to dropdown change as well
    nameDropdown.addEventListener('change', applyFilter);
    ingDropdown.addEventListener('change', applyFilter);

    // Add sorting on header click for first six data columns
    const columns = ['name', 'ing', 'amount', 'level', 'help_speed', 'ing_percent'];
    let currentSort = { key: null, direction: 1 };
    const headers = document.querySelectorAll('#pokemon-table thead th');
    headers.forEach((th, index) => {
        const key = columns[index];
        if (!key) return;
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            if (currentSort.key === key) currentSort.direction *= -1;
            else { currentSort.key = key; currentSort.direction = 1; }
            pokemonData.sort((a, b) => {
                let aval = a[key], bval = b[key];
                if (['amount', 'level', 'help_speed', 'ing_percent'].includes(key)) {
                    aval = parseFloat(aval); bval = parseFloat(bval);
                }
                if (aval < bval) return -currentSort.direction;
                if (aval > bval) return currentSort.direction;
                return 0;
            });
            applyFilter();
        });
    });

    // Add sorting on 每小時取得數量 column (new index 14) by direct DOM row sort
    let hourSortDir = 1;
    const hourTh = document.querySelectorAll('#pokemon-table thead th')[15];
    hourTh.style.cursor = 'pointer';
    hourTh.addEventListener('click', () => {
        const rows = Array.from(tableBody.querySelectorAll('tr'));
        rows.sort((a, b) => {
            const vA = parseFloat(a.cells[15].textContent) || 0;
            const vB = parseFloat(b.cells[15].textContent) || 0;
            return hourSortDir * (vA - vB);
        });
        rows.forEach(r => tableBody.appendChild(r));
        hourSortDir *= -1;
    });

    nameInput.addEventListener('input', applyFilter);
    ingInput.addEventListener('input', applyFilter);
    ticketCheckbox.addEventListener('change', applyFilter);
    oteboDropdown.addEventListener('change', applyFilter);

    fetch('./data/pokemon_data.json')
        .then(response => {
            if (!response.ok) throw new Error(`Network response was not ok: ${response.statusText}`);
            return response.json();
        })
        .then(data => {
            // flag originals
            originalData = data.map(item => Object.assign({}, item, { _orig: true }));
            // merge original and added entries
            pokemonData = originalData.concat(addedData);
            // populate dropdowns using fetched data
            const names = Array.from(new Set(originalData.map(e => e.name))).sort();
            names.forEach(name => {
                const opt = document.createElement('option'); opt.value = name; opt.textContent = name;
                nameDropdown.appendChild(opt);
            });
            // populate ingredient dropdown
            const ings = Array.from(new Set(originalData.map(e => e.ing))).sort();
            ings.forEach(ing => {
                const opt = document.createElement('option'); opt.value = ing; opt.textContent = ing;
                ingDropdown.appendChild(opt);
            });
            renderTable(pokemonData);
            // Default sort by 每小時取得數量 (column index 14) descending
            const initialRows = Array.from(tableBody.querySelectorAll('tr'));
            initialRows.sort((a, b) => {
                const vA = parseFloat(a.cells[15].textContent) || 0;
                const vB = parseFloat(b.cells[15].textContent) || 0;
                return vB - vA;
            });
            initialRows.forEach(r => tableBody.appendChild(r));
        })
        .catch(error => {
            console.error('Error fetching or parsing JSON:', error);
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 6;
            td.textContent = '資料讀取失敗';
            tr.appendChild(td);
            tableBody.appendChild(tr);
        });

    function showModal() {
        modal.classList.remove('hidden');
        // populate name suggestions in datalist
        const nameList = document.getElementById('form-name-list');
        nameList.innerHTML = '';
        Array.from(new Set(originalData.map(e => e.name))).sort().forEach(name => {
            const opt = document.createElement('option');
            opt.value = name;
            nameList.appendChild(opt);
        });
        // populate ingredient select with unique options
        formIng.innerHTML = '<option value="">-- 請選擇 --</option>';
        Array.from(new Set(originalData.map(e => e.ing))).sort().forEach(ing => {
            const opt = document.createElement('option'); opt.value = ing; opt.textContent = ing; formIng.appendChild(opt);
        });
        // populate amount select with fixed range 2-30
        formAmount.innerHTML = '<option value="">-- 請選擇 --</option>';
        for (let i = 2; i <= 30; i++) {
            const opt = document.createElement('option'); opt.value = i; opt.textContent = i; formAmount.appendChild(opt);
        }
        // clear input value
        formName.value = '';
        formLevel.value = 60;
        formIngPercent.value = '';
        formHelpSpeed.value = '';
        [formOtebo, formSpM, formSpS, formIngS, formIngM].forEach(cb => cb.checked = false);
        formNature.value = '';
    }
    function hideModal() { modal.classList.add('hidden'); }
    addBtn.addEventListener('click', () => {
        // save current filter settings
        savedFilters = {
            nameTerm: nameInput.value,
            nameDropdown: nameDropdown.value,
            ingTerm: ingInput.value,
            ingDropdown: ingDropdown.value,
            ticketChecked: ticketCheckbox.checked,
            oteboValue: oteboDropdown.value
        };
        isEditing = false; editingIndex = null; showModal();
    });
    closeBtn.addEventListener('click', hideModal);
    modal.addEventListener('click', e => { if (e.target === modal) hideModal(); });
    saveBtn.addEventListener('click', () => {
        // build new entry and mark as non-original
        const entry = {
            _orig: false,
            name: formName.value,
            ing: formIng.value,
            amount: formAmount.value,
            level: formLevel.value,
            help_speed: formHelpSpeed.value,
            ing_percent: formIngPercent.value,
            otebo: formOtebo.checked,
            spM: formSpM.checked,
            spS: formSpS.checked,
            ingS: formIngS.checked,
            ingM: formIngM.checked,
            nature: formNature.value
        };
        if (isEditing && editingIndex != null && editingIndex >= originalData.length) {
            // edit added entry
            const idx = editingIndex - originalData.length;
            addedData[idx] = entry;
        } else {
            // new entry
            addedData.push(entry);
        }
        // persist
        localStorage.setItem('addedPokemon', JSON.stringify(addedData));
        // re-merge and refresh
        pokemonData = originalData.concat(addedData);
        // restore saved filters
        nameInput.value = savedFilters.nameTerm;
        nameDropdown.value = savedFilters.nameDropdown;
        ingInput.value = savedFilters.ingTerm;
        ingDropdown.value = savedFilters.ingDropdown;
        ticketCheckbox.checked = savedFilters.ticketChecked;
        oteboDropdown.value = savedFilters.oteboValue;
        hideModal();
        applyFilter();
    });
});
