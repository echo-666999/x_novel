// 显示偏好仅使用本地存储，绝不写任务、费用策略或正文。
const defaults = { theme: 'dark', font: 'serif', size: '18', leading: '1.9', width: '40' };
const allowed = { theme: ['dark', 'light', 'system'], font: ['serif', 'sans'], size: ['16','18','20','22'], leading: ['1.7','1.9','2.1'], width: ['32','40','48'] };
let preferences = {...defaults};
try { const saved = JSON.parse(localStorage.getItem('xn-display-v1') || '{}'); for (const key of Object.keys(defaults)) if (allowed[key].includes(saved[key])) preferences[key] = saved[key]; } catch { /* 存储被禁用时保留本次显示能力。 */ }
const system = matchMedia('(prefers-color-scheme: dark)');
function apply() {
    document.documentElement.classList.toggle('dark', preferences.theme === 'dark' || (preferences.theme === 'system' && system.matches));
    document.documentElement.style.setProperty('--xn-reading-size', `${preferences.size}px`);
    document.documentElement.style.setProperty('--xn-reading-leading', preferences.leading);
    document.documentElement.style.setProperty('--xn-reading-width', `${preferences.width}em`);
    document.documentElement.dataset.readingFont = preferences.font;
    document.querySelectorAll('[data-display-preference]').forEach(control => { control.value = preferences[control.dataset.displayPreference]; });
}
apply();
system.addEventListener('change', apply);
document.querySelectorAll('[data-display-preference]').forEach(control => control.addEventListener('change', () => {
    const key = control.dataset.displayPreference;
    if (allowed[key]?.includes(control.value)) preferences[key] = control.value;
    try { localStorage.setItem('xn-display-v1', JSON.stringify(preferences)); } catch { /* 无存储权限不影响当前阅读。 */ }
    apply();
}));
