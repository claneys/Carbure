/**
 * Transaction Module
 * 
 * Manages transaction-related state and methods.
 * Provides functionality for loading and displaying transactions.
 * 
 * @module transactionModule
 */

// What a bank adds around the merchant: kind of operation first, references after
const LABEL_PREFIX = /^(FACTURE CARTE DU \d{4,8}|PAIEMENT (PAR )?CB|CB|CARTE|PRLV( SEPA)?|VIREMENT( SEPA)?|VIR( SEPA)?( INST)?|RETRAIT( DAB)?|CHQ|CHEQUE)\s+/;
const LABEL_NOISE = /^(CARTE|ECH|MDT|REF|\S*\d\S*\d\S*\d\S*|\d{1,2}\/\d{1,2}\S*)$/;

/**
 * Query of the label search that finds the transactions similar to a label: the
 * label without the kind of operation (CB, PRLV SEPA, VIR...) nor what follows the
 * merchant (card number, date, mandate reference...). It stays a part of the label,
 * so that the label search (a "contains") finds the transaction itself and its
 * siblings; the label itself when too little is left.
 * @param {string} label - Label of a transaction
 * @returns {string}
 */
export function similarLabelQuery(label) {
    const full = String(label || '').toUpperCase().replace(/\s+/g, ' ').trim();
    let rest = full;
    for (let previous = ''; previous !== rest;) {
        previous = rest;
        rest = rest.replace(LABEL_PREFIX, '');
    }
    const words = [];
    for (const word of rest.split(' ')) {
        if (LABEL_NOISE.test(word)) {
            break;
        }
        words.push(word);
    }
    const query = words.join(' ');
    return query.length >= 2 ? query : full;
}

/**
 * Calendar of the recurring series of a month (GET /transaction/recurring), weeks
 * starting on Monday. A received series is on the day of its transaction, an expected
 * one on its usual day (the last day of a shorter month): "late" once that day is past
 * in the current month, "missing" in a past month.
 * @param {Array<Object>} series - Recurring series of the month
 * @param {number} year - Year
 * @param {number} month - Month (1 to 12)
 * @param {Date} [today] - Today
 * @returns {{weeks: Array<Array<Object|null>>, received: {count: number, total: number}, expected: {count: number, total: number}}}
 *          weeks: 7 cells each ({day, date, today, items: [{series, amount, approx, status}]}, null out of the month);
 *          received and expected: count and sum of the amounts
 */
export function recurringCalendar(series, year, month, today = new Date()) {
    const days = new Date(year, month, 0).getDate();
    const prefix = `${year}-${String(month).padStart(2, '0')}-`;
    const monthIndex = year * 12 + month - 1;
    const todayIndex = today.getFullYear() * 12 + today.getMonth();
    const cells = Array.from({ length: days }, (_, i) => ({
        day: i + 1,
        date: prefix + String(i + 1).padStart(2, '0'),
        today: monthIndex === todayIndex && i + 1 === today.getDate(),
        items: []
    }));
    const totals = { received: { count: 0, total: 0 }, expected: { count: 0, total: 0 } };

    for (const one of series) {
        const received = one.status === 'received';
        const date = received && String(one.date || '').startsWith(prefix) ? one.date : null;
        const day = date ? Number(date.slice(8, 10)) : Math.min(Number(one.day), days);
        let status = received ? 'received' : 'expected';
        if (!received && (monthIndex < todayIndex || (monthIndex === todayIndex && day < today.getDate()))) {
            status = monthIndex < todayIndex ? 'missing' : 'late';
        }
        const approx = !received && !!one.variable;
        const amount = Number(approx ? one.average : one.amount);
        cells[day - 1].items.push({ series: one, amount, approx, status });
        const total = totals[received ? 'received' : 'expected'];
        total.count++;
        total.total = Math.round((total.total + amount) * 100) / 100;
    }

    // Monday first: the days of the week before the 1st are empty
    const lead = (new Date(year, month - 1, 1).getDay() + 6) % 7;
    const grid = [...Array(lead).fill(null), ...cells];
    while (grid.length % 7) {
        grid.push(null);
    }
    const weeks = [];
    for (let i = 0; i < grid.length; i += 7) {
        weeks.push(grid.slice(i, i + 7));
    }
    return { weeks, ...totals };
}

/**
 * Creates a transaction module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with transaction methods and computed properties
 */
export function createTransactionModule(getApiService) {
    return {
        data() {
            return {
                transactions: [],
                loadingTransactions: false,
                transactionController: null,

                // 'all' or 'unchecked'
                transactionFilter: 'all',

                // Recurring series of the month (salary, rent, subscriptions...) and the
                // filter that shows only their transactions
                recurring: [],
                recurringOnly: false,
                recurringRequest: 0,
                // Calendar of the recurring series of the month (modal)
                showRecurringCalendar: false,

                // Rule proposed after a manual categorization: {keyword, category, categoryName}
                ruleSuggestion: null,
                // Transaction shown in the detail modal, its category and checked state being edited
                transactionDetail: null,
                transactionDetailCategory: '',
                transactionDetailPointed: false,
                savingTransaction: false,

                // Search by label
                searchQuery: '',
                searchResults: [],
                searching: false,
                searchTimer: null
            };
        },

        computed: {
            /**
             * Checks if a search is active (2 characters minimum)
             * @returns {boolean}
             */
            isSearching() {
                return this.searchQuery.trim().length >= 2;
            },

            /**
             * Number of transactions not checked yet
             * @returns {number}
             */
            uncheckedCount() {
                return this.transactions.filter(t => !Number(t.pointed)).length;
            },

            /**
             * Transactions to display: search results or the month, filtered
             * @returns {Array}
             */
            displayedTransactions() {
                let list = this.isSearching ? this.searchResults : this.transactions;
                if (this.recurringOnly && !this.isSearching) {
                    list = list.filter(t => this.isRecurring(t));
                }
                if (this.transactionFilter === 'unchecked') {
                    return list.filter(t => !Number(t.pointed));
                }
                return list;
            },

            /**
             * Ids of the recurring transactions of the month
             * @returns {Set<number>}
             */
            recurringIds() {
                return new Set(this.recurring.flatMap(series => series.ids.map(Number)));
            },

            /**
             * Recurring series not seen in the month yet
             * @returns {Array}
             */
            recurringExpected() {
                return this.recurring.filter(series => series.status === 'expected');
            },

            /**
             * Calendar of the recurring series of the selected month
             * @returns {{weeks: Array<Array<Object|null>>, received: Object, expected: Object}}
             */
            recurringCalendar() {
                return recurringCalendar(this.recurring, Number(this.selectedYear), Number(this.selectedMonth));
            },

            /**
             * Short names of the days of the week, Monday first
             * @returns {Array<string>}
             */
            recurringWeekdays() {
                const format = new Intl.DateTimeFormat(this.intlLocale, { weekday: 'short' });
                // 2024-01-01 is a Monday
                return Array.from({ length: 7 }, (_, i) => format.format(new Date(2024, 0, 1 + i)));
            },

            /**
             * Month and year of the calendar ("octobre 2026")
             * @returns {string}
             */
            recurringCalendarTitle() {
                return new Intl.DateTimeFormat(this.intlLocale, { month: 'long', year: 'numeric' })
                    .format(new Date(Number(this.selectedYear), Number(this.selectedMonth) - 1, 1));
            },

            /**
             * Whether the selected month is the current one (expected series may still come)
             * @returns {boolean}
             */
            isCurrentMonth() {
                const now = new Date();
                return Number(this.selectedMonth) === now.getMonth() + 1 && Number(this.selectedYear) === now.getFullYear();
            },

            /**
             * Sum of the displayed transactions (search results, filter applied)
             * @returns {number}
             */
            displayedTotal() {
                return this.displayedTransactions.reduce((sum, t) => sum + parseFloat(t.amount), 0);
            },

            /**
             * Calculates income/expense statistics from transactions
             * @returns {{income: number, expense: number, balance: number}}
             */
            stats() {
                const income = this.transactions
                    .filter(t => t.amount > 0)
                    .reduce((sum, t) => sum + parseFloat(t.amount), 0);
                
                const expense = this.transactions
                    .filter(t => t.amount < 0)
                    .reduce((sum, t) => sum + parseFloat(t.amount), 0);
                
                return {
                    income,
                    expense,
                    balance: income + expense
                };
            }
        },

        methods: {
            /**
             * Loads transactions for selected month/year
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async loadTransactions(month, year) {
                const apiService = getApiService();
                if (!apiService) {
                    console.error('API service not initialized');
                    return;
                }

                this.loadingTransactions = true;
                this.error = null;

                try {
                    // Cancel previous request
                    if (this.transactionController) {
                        this.transactionController.abort();
                    }
                    this.transactionController = new AbortController();

                    const data = await apiService.fetchTransactions(
                        month,
                        year,
                        this.transactionController.signal
                    );

                    this.transactions = Array.isArray(data) ? data : [];
                    // Categories are shown on every row (name or picker)
                    this.ensureCategories();
                    this.loadRecurring(month, year);
                } catch (err) {
                    if (err.name === 'AbortError') {
                        return;
                    }
                    
                    this.error = err.message;
                    this.transactions = [];
                } finally {
                    this.loadingTransactions = false;
                    this.transactionController = null;
                }
            },

            /**
             * Loads the recurring series of a month (an answer for another month is ignored)
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async loadRecurring(month, year) {
                const request = ++this.recurringRequest;
                try {
                    const series = await getApiService().fetchRecurring(month, year);
                    if (request === this.recurringRequest) {
                        this.recurring = series;
                    }
                } catch (err) {
                    console.error('Error loading recurring transactions:', err);
                    if (request === this.recurringRequest) {
                        this.recurring = [];
                    }
                }
            },

            /**
             * Whether a transaction belongs to a recurring series of the month
             * @param {Object} transaction - Transaction
             * @returns {boolean}
             */
            isRecurring(transaction) {
                return this.recurringIds.has(Number(transaction.id));
            },

            /**
             * Loads the categories once (for the category picker of the transactions)
             * @returns {Promise<void>}
             */
            async ensureCategories() {
                if (this.ruleCategories.length === 0 && getApiService()) {
                    try {
                        this.ruleCategories = await getApiService().fetchCategories();
                    } catch (err) {
                        console.error('Error loading categories:', err);
                    }
                }
            },

            /**
             * Name of the category of a transaction ('' when unknown)
             * @param {Object} transaction - Transaction
             * @returns {string}
             */
            categoryName(transaction) {
                const category = this.ruleCategories.find(c => Number(c.id) === Number(transaction.category));
                return category && Number(category.id) !== 0 ? category.name : '';
            },

            /**
             * Category of a transaction (null when it has none)
             * @param {Object} transaction - Transaction
             * @returns {Object|null}
             */
            transactionCategory(transaction) {
                const id = Number(transaction.category);
                return id ? this.ruleCategories.find(c => Number(c.id) === id) || null : null;
            },

            /**
             * Icon of a transaction: the one of its category, as shown on the categories page,
             * the icon of the bank operation type when it has no category
             * @param {Object} transaction - Transaction
             * @returns {string}
             */
            transactionIcon(transaction) {
                const category = this.transactionCategory(transaction);
                return category ? this.categoryIcon(category) : this.getTransactionIcon(transaction.type);
            },

            /**
             * Colors of the icon of a categorized transaction (null: colors of the operation type)
             * @param {Object} transaction - Transaction
             * @returns {Object|null}
             */
            transactionIconStyle(transaction) {
                const category = this.transactionCategory(transaction);
                return category ? this.categoryBadgeStyle(category) : null;
            },

            /**
             * Checks if a transaction has no category yet
             * @param {Object} transaction - Transaction
             * @returns {boolean}
             */
            isUncategorized(transaction) {
                return !Number(transaction.category);
            },

            /**
             * Sets the category of a transaction, then proposes a rule for the next ones
             * @param {Object} transaction - Transaction
             * @param {string} category - Selected category ID
             * @returns {Promise<void>}
             */
            async categorizeTransaction(transaction, category) {
                if (!category) {
                    return;
                }

                try {
                    await getApiService().setTransactionCategory(transaction.id, category);
                    transaction.category = Number(category);
                    transaction.pointed = 1;
                    const option = this.ruleCategoryOptions.find(o => Number(o.id) === Number(category));
                    // Only administrators manage the rules
                    this.ruleSuggestion = !this.isAdmin ? null : {
                        keyword: transaction.label.trim(),
                        category: Number(category),
                        categoryName: option ? option.label : ''
                    };
                } catch (err) {
                    this.showToast(err.message);
                }
            },

            /**
             * Opens the detail modal of a transaction
             * @param {Object} transaction - Transaction
             */
            openTransactionModal(transaction) {
                this.transactionDetail = transaction;
                this.transactionDetailCategory = Number(transaction.category) || 0;
                this.transactionDetailPointed = !!Number(transaction.pointed);
                // The categories are needed by the picker
                if (!this.ruleCategories.length && getApiService()) {
                    getApiService().fetchCategories().then(c => { this.ruleCategories = c; }).catch(() => {});
                }
                this.$nextTick(() => document.querySelector('.modal-form .category-picker-button')?.focus());
            },

            /** Opens the calendar of the recurring transactions of the month */
            openRecurringCalendar() {
                this.showRecurringCalendar = true;
                this.$nextTick?.(() => document.querySelector('.modal-recurring-calendar .modal-close')?.focus());
            },

            /** Closes the calendar of the recurring transactions */
            closeRecurringCalendar() {
                this.showRecurringCalendar = false;
            },

            /**
             * Opens the detail of the transaction of a received series, from the calendar
             * @param {Object} item - Item of the calendar ({series})
             */
            openRecurringTransaction(item) {
                const id = Number(item.series.ids?.[0]);
                const transaction = this.transactions.find(t => Number(t.id) === id);
                if (transaction) {
                    this.showRecurringCalendar = false;
                    this.openTransactionModal(transaction);
                }
            },

            /**
             * Closes the detail modal of a transaction
             */
            closeTransactionModal() {
                this.transactionDetail = null;
            },

            /**
             * Saves the category and the checked state of the transaction of the modal
             * @returns {Promise<void>}
             */
            async saveTransactionDetail() {
                const transaction = this.transactionDetail;
                const category = Number(this.transactionDetailCategory) || 0;
                const pointed = this.transactionDetailPointed;
                this.savingTransaction = true;
                try {
                    if (category !== (Number(transaction.category) || 0)) {
                        const wasUncategorized = this.isUncategorized(transaction);
                        // Changing the category also checks the transaction
                        await getApiService().setTransactionCategory(transaction.id, category);
                        transaction.category = category;
                        transaction.pointed = 1;
                        if (wasUncategorized && category !== 0 && this.isAdmin) {
                            const option = this.ruleCategoryOptions.find(o => Number(o.id) === category);
                            this.ruleSuggestion = { keyword: transaction.label.trim(), category, categoryName: option ? option.label : '' };
                        }
                    }
                    if (pointed !== !!Number(transaction.pointed)) {
                        await this.toggleTransactionPointed(transaction);
                    }
                    this.transactionDetail = null;
                    this.showToast(this.t('transactionSaved'));
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.savingTransaction = false;
                }
            },

            /**
             * Deletes the transaction of the detail modal, after a confirmation; the list,
             * the totals and the periods are then reloaded
             * @returns {Promise<void>}
             */
            async deleteTransactionDetail() {
                const transaction = this.transactionDetail;
                if (!transaction || !confirm(this.t('confirmDeleteTransaction', { label: transaction.label }))) {
                    return;
                }
                this.savingTransaction = true;
                try {
                    await getApiService().deleteTransaction(transaction.id);
                    this.transactionDetail = null;
                    this.searchResults = this.searchResults.filter(t => Number(t.id) !== Number(transaction.id));
                    this.showToast(this.t('transactionDeleted'));
                    this.loadPeriods();
                    this.loadTransactions(this.selectedMonth, this.selectedYear);
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.savingTransaction = false;
                }
            },

            /**
             * Opens the rules tab and the rule modal with the suggested rule pre-filled
             */
            createSuggestedRule() {
                const suggestion = this.ruleSuggestion;
                this.ruleSuggestion = null;
                this.setActiveTab('rules');
                this.openRuleModal({ keyword: suggestion.keyword, category: suggestion.category });
            },

            /**
             * Checks or unchecks a transaction (optimistic update)
             * @param {Object} transaction - Transaction to update
             * @returns {Promise<void>}
             */
            async toggleTransactionPointed(transaction) {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }

                const pointed = !Number(transaction.pointed);
                transaction.pointed = pointed ? 1 : 0;

                try {
                    await apiService.setTransactionPointed(transaction.id, pointed);
                } catch (err) {
                    transaction.pointed = pointed ? 0 : 1;
                    this.showToast(err.message);
                }
            },

            /**
             * Schedules a search after the user stops typing
             */
            onSearchInput() {
                clearTimeout(this.searchTimer);
                if (!this.isSearching) {
                    this.searchResults = [];
                    return;
                }
                this.searchTimer = setTimeout(() => this.runSearch(), 300);
            },

            /**
             * Closes the detail of a transaction and searches the transactions whose label
             * is similar to its one, like a search typed by the user (cleared the same way)
             * @returns {Promise<void>}
             */
            async searchSimilarTransactions() {
                const transaction = this.transactionDetail;
                if (!transaction) {
                    return;
                }
                this.transactionDetail = null;
                clearTimeout(this.searchTimer);
                this.searchQuery = similarLabelQuery(transaction.label);
                this.$nextTick?.(() => document.querySelector('.transaction-search input')?.focus());
                await this.runSearch();
            },

            /**
             * Searches transactions by label
             * @returns {Promise<void>}
             */
            async runSearch() {
                const apiService = getApiService();
                if (!apiService || !this.isSearching) {
                    return;
                }

                const query = this.searchQuery.trim();
                this.searching = true;
                try {
                    const results = await apiService.searchTransactions(query);
                    // Ignore late answers for an older query
                    if (query === this.searchQuery.trim()) {
                        this.searchResults = results;
                    }
                } catch (err) {
                    this.showToast(err.message);
                } finally {
                    this.searching = false;
                }
            },

            /**
             * Clears the search and goes back to the month view
             */
            clearSearch() {
                clearTimeout(this.searchTimer);
                this.searchQuery = '';
                this.searchResults = [];
            },

            /**
             * Aborts any pending transaction request
             */
            abortTransactions() {
                if (this.transactionController && typeof this.transactionController.abort === 'function') {
                    try {
                        this.transactionController.abort();
                    } catch (e) {
                        // Ignore abort errors
                    }
                    this.transactionController = null;
                }
            }
        }
    };
}
