/**
 * Transaction Module
 * 
 * Manages transaction-related state and methods.
 * Provides functionality for loading and displaying transactions.
 * 
 * @module transactionModule
 */

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
