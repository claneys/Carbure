/**
 * Budget State Management Module
 * 
 * Manages budget navigation, hierarchy, and children caching.
 * Provides methods for navigating budget categories and maintaining breadcrumb state.
 * 
 * @module budgetStore
 */

/**
 * Creates a budget store instance
 * @param {Object} apiService - API service instance
 * @returns {Object} Budget store state and methods
 */
export function createBudgetStore(apiService) {
    /**
     * Reactive state: Vue must track it, otherwise the components reading it
     * (computed properties) are never refreshed when the data arrives
     */
    const state = Vue.reactive({
        /**
         * Root budget items
         */
        budgets: [],
        
        /**
         * Currently displayed budget list (root or children)
         */
        currentList: [],
        
        /**
         * Navigation breadcrumb stack
         * @type {Array<{id: number, name: string}>}
         */
        breadcrumbStack: [],
        
        /**
         * Cached children budgets by parent ID
         * @type {Object<number, Array>}
         */
        childrenCache: {},
        
        /**
         * Loading states for children requests
         * @type {Object<number, boolean>}
         */
        loadingChildren: {},
        
        /**
         * Main loading state
         */
        isLoading: false,
        
        /**
         * Error message
         */
        error: null
    });
    
    /**
     * Filters and sorts budget items
     * @param {Array} budgets - Raw budget array
     * @returns {Array} Filtered and sorted budget array
     */
    function filterBudgets(budgets) {
        if (!Array.isArray(budgets)) {
            console.warn('filterBudgets received non-array:', budgets);
            return [];
        }
        
        return budgets
            .filter(b => b && b.name) // Only filter out invalid items
            .sort((a, b) => parseFloat(b.progress || 0) - parseFloat(a.progress || 0));
    }
    
    /**
     * Loads root budget data
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<void>}
     */
    async function loadRootBudgets(month, year) {
        state.isLoading = true;
        state.error = null;
        
        try {
            const data = await apiService.fetchBudget(month, year);
            state.budgets = data;
            
            // Reset to root when loading fresh data
            state.breadcrumbStack = [];
            state.currentList = filterBudgets(data);
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            console.error('Budget loading error:', error);
            state.error = error.message;
            state.budgets = [];
            state.currentList = [];
        } finally {
            state.isLoading = false;
        }
    }
    
    /**
     * Navigates to budget children
     * @param {Object} item - Budget item
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<boolean>} True if navigation occurred, false if no children
     */
    async function navigateToChildren(item, month, year) {
        const categoryId = item.id;
        
        // Check cache first
        if (state.childrenCache[categoryId] && Array.isArray(state.childrenCache[categoryId])) {
            const cachedList = state.childrenCache[categoryId];
            
            if (cachedList.length === 0) {
                // No children: stay on current page
                return false;
            }
            
            state.breadcrumbStack.push({ id: categoryId, name: item.name });
            state.currentList = cachedList;
            return true;
        }
        
        // Fetch children from API
        try {
            state.loadingChildren[categoryId] = true;
            
            const children = await apiService.fetchBudget(month, year, categoryId);
            state.childrenCache[categoryId] = children;
            
            if (children.length === 0) {
                // No children: stay on current page
                return false;
            }
            
            state.breadcrumbStack.push({ id: categoryId, name: item.name });
            state.currentList = children;
            return true;
        } catch (error) {
            console.error('Load budget children error', error);
            throw error;
        } finally {
            state.loadingChildren[categoryId] = false;
        }
    }
    
    /**
     * Navigates to a specific breadcrumb level
     * @param {number} index - Breadcrumb index (-1 for root)
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     */
    async function navigateToBreadcrumb(index, month, year) {
        // Navigate to root
        if (index < 0) {
            state.breadcrumbStack = [];
            state.currentList = filterBudgets(state.budgets);
            return;
        }
        
        // Navigate to specific breadcrumb level
        state.breadcrumbStack = state.breadcrumbStack.slice(0, index + 1);
        const target = state.breadcrumbStack[state.breadcrumbStack.length - 1];
        
        if (!target) {
            state.currentList = filterBudgets(state.budgets);
            return;
        }
        
        const cached = state.childrenCache[target.id];
        if (cached && Array.isArray(cached)) {
            state.currentList = cached;
        } else {
            // Fetch if not cached
            await navigateToChildren({ id: target.id, name: target.name }, month, year);
        }
    }
    
    /**
     * Updates a budget item
     * @param {number} budgetId - Budget ID
     * @param {number} month - Month
     * @param {number} year - Year
     * @param {number} newAmount - New budget amount
     * @returns {Promise<void>}
     */
    async function updateBudget(budgetId, month, year, newAmount) {
        await apiService.updateBudget(budgetId, month, year, newAmount);
        // Reload the budgets (a parent depends on its sub-categories), staying on the displayed level
        const stack = state.breadcrumbStack.slice();
        state.childrenCache = {};
        await loadRootBudgets(month, year);
        for (const crumb of stack) {
            await navigateToChildren(crumb, month, year);
        }
    }

    /**
     * Copies the budgets of the previous month to the given month
     * @param {number} month - Target month (1-12)
     * @param {number} year - Target year
     * @param {boolean} overwrite - True to replace the budgets already set
     * @returns {Promise<Object>} {month, source, copied, overwrite}
     */
    async function copyBudgets(month, year, overwrite = false) {
        const result = await apiService.copyBudgets(month, year, overwrite);
        // Reload the budgets (a parent depends on its sub-categories), staying on the displayed level
        const stack = state.breadcrumbStack.slice();
        state.childrenCache = {};
        await loadRootBudgets(month, year);
        for (const crumb of stack) {
            await navigateToChildren(crumb, month, year);
        }
        return result;
    }
    
    /**
     * Aborts all pending requests
     */
    function abortAll() {
        apiService.abortAllExcept('none');
    }
    
    return {
        state,
        loadRootBudgets,
        navigateToChildren,
        navigateToBreadcrumb,
        updateBudget,
        copyBudgets,
        filterBudgets,
        abortAll
    };
}
