/**
 * BRLove API - Global Theme Controller 5.0
 * Instant, zero-flicker light/dark toggle and reactive color schemes with scheme picker dropdown
 */
(function() {
    function getStoredMode() {
        const local = localStorage.getItem('theme_mode');
        if (local === 'light' || local === 'dark') return local;
        const cookieMatch = document.cookie.match(/(?:^|;\s*)theme_mode=([^;]+)/);
        if (cookieMatch && (cookieMatch[1] === 'light' || cookieMatch[1] === 'dark')) return cookieMatch[1];
        return document.documentElement.getAttribute('data-theme') || 'light';
    }

    function getStoredScheme() {
        const local = localStorage.getItem('theme_scheme');
        if (local) return local;
        const cookieMatch = document.cookie.match(/(?:^|;\s*)theme_scheme=([^;]+)/);
        if (cookieMatch) return cookieMatch[1];
        return document.documentElement.getAttribute('data-scheme') || 'purple_cyan';
    }

    // 1. Aplicação imediata no documento
    const activeMode = getStoredMode();
    const activeScheme = getStoredScheme();

    document.documentElement.setAttribute('data-theme', activeMode);
    document.documentElement.setAttribute('data-scheme', activeScheme);
    if (activeMode === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }

    // 2. Funções globais expostas no window
    window.getCurrentThemeMode = function() {
        if (document.documentElement.classList.contains('dark') || document.documentElement.getAttribute('data-theme') === 'dark') {
            return 'dark';
        }
        return 'light';
    };

    window.getCurrentThemeScheme = function() {
        return document.documentElement.getAttribute('data-scheme') || 'purple_cyan';
    };

    window._lastThemeToggle = 0;

    window.toggleThemeMode = function(e) {
        if (e && e.preventDefault) e.preventDefault();
        if (e && e.stopPropagation) e.stopPropagation();

        const now = Date.now();
        if (now - window._lastThemeToggle < 300) {
            return;
        }
        window._lastThemeToggle = now;

        const current = window.getCurrentThemeMode();
        const next = (current === 'dark') ? 'light' : 'dark';
        window.setThemeMode(next);
    };

    window.setThemeMode = function(mode) {
        if (mode !== 'light' && mode !== 'dark') return;
        
        document.documentElement.setAttribute('data-theme', mode);
        if (mode === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }

        try {
            localStorage.setItem('theme_mode', mode);
            document.cookie = `theme_mode=${mode}; path=/; max-age=31536000; SameSite=Lax`;
        } catch (err) {}

        window.updateAllThemeIcons(mode);

        const basePath = window.BRLOVE_BASE_URL || '';
        fetch(`${basePath}/api/theme.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=set&mode=${encodeURIComponent(mode)}`
        }).catch(() => {});
    };

    window.setThemeScheme = function(scheme) {
        if (!scheme) return;
        document.documentElement.setAttribute('data-scheme', scheme);

        try {
            localStorage.setItem('theme_scheme', scheme);
            document.cookie = `theme_scheme=${scheme}; path=/; max-age=31536000; SameSite=Lax`;
        } catch (err) {}

        // Fecha menus abertos
        document.querySelectorAll('.scheme-dropdown-menu').forEach(el => el.classList.add('hidden'));

        const basePath = window.BRLOVE_BASE_URL || '';
        fetch(`${basePath}/api/theme.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=set&scheme=${encodeURIComponent(scheme)}`
        }).catch(() => {});
    };

    window.updateAllThemeIcons = function(mode) {
        const current = mode || window.getCurrentThemeMode();
        document.querySelectorAll('.theme-toggle-icon, #themeToggleIcon').forEach(icon => {
            if (current === 'dark') {
                icon.className = 'theme-toggle-icon fa-solid fa-moon text-indigo-400 text-sm';
            } else {
                icon.className = 'theme-toggle-icon fa-solid fa-sun text-amber-500 text-sm';
            }
        });
    };

    // 3. Listener global de cliques
    document.addEventListener('click', function(e) {
        // Toggle de claro/escuro
        const toggleBtn = e.target.closest('.btn-theme-toggle, #themeToggleBtn');
        if (toggleBtn) {
            e.preventDefault();
            e.stopPropagation();
            window.toggleThemeMode(e);
            return;
        }

        // Toggle do menu de schemes (ícone da paleta)
        const schemePickerBtn = e.target.closest('.btn-scheme-picker');
        if (schemePickerBtn) {
            e.preventDefault();
            e.stopPropagation();
            const parent = schemePickerBtn.closest('.relative') || schemePickerBtn.parentElement;
            const menu = parent ? parent.querySelector('.scheme-dropdown-menu') : null;
            if (menu) {
                menu.classList.toggle('hidden');
            }
            return;
        }

        // Seleção de um scheme
        const schemeItemBtn = e.target.closest('[data-set-scheme]');
        if (schemeItemBtn) {
            const scheme = schemeItemBtn.getAttribute('data-set-scheme');
            if (scheme) {
                e.preventDefault();
                e.stopPropagation();
                window.setThemeScheme(scheme);
            }
            return;
        }

        // Clicar fora fecha menus
        document.querySelectorAll('.scheme-dropdown-menu').forEach(el => el.classList.add('hidden'));
    }, true);

    // 4. Inicialização
    document.addEventListener('DOMContentLoaded', function() {
        const currentMode = window.getCurrentThemeMode();
        window.updateAllThemeIcons(currentMode);
    });
})();
