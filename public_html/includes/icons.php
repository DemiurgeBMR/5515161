<?php
/**
 * Единый набор line-иконок вместо эмодзи — эмодзи рендерится по-разному
 * на разных ОС/браузерах (не для всех есть подходящий шрифт) и выглядит
 * менее профессионально, чем единообразная графика в цвет темы сайта.
 * Инлайн-SVG, а не иконочный шрифт/спрайт — без вешней зависимости, цвет
 * наследуется через currentColor, размер — через font-size обёртки.
 *
 * Использование: rr_icon('bell') — эквивалент <svg>...</svg> высотой 1em.
 * Раскатывается постранично (см. INSTRUCTIONS.md фазы эмодзи), не на весь
 * сайт разом — набор пополняется по мере перехода следующих страниц.
 */

const RR_ICONS = [
    'bell'          => '<path d="M12 3a5 5 0 0 0-5 5v3.2c0 .9-.36 1.77-1 2.4L5 15h14l-1-1.4c-.64-.63-1-1.5-1-2.4V8a5 5 0 0 0-5-5z"/><path d="M9.5 18a2.5 2.5 0 0 0 5 0"/>',
    'shield'        => '<path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/>',
    'plus-circle'   => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
    'card'          => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
    'moon'          => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/>',
    'sun'           => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'mail'          => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M3 6l9 7 9-7"/>',
    'calendar'      => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
    'clock'         => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
    'trash'         => '<path d="M4 7h16M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/><path d="M10 11v6M14 11v6"/>',
    'edit'          => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
    'info-circle'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>',
    'map-pin'       => '<path d="M12 22s7-7.58 7-12A7 7 0 0 0 5 10c0 4.42 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
    'lock'          => '<rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'bolt'          => '<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8z" fill="currentColor" stroke="none"/>',
    'wifi'          => '<path d="M2 8.5a16 16 0 0 1 20 0M5.5 12a11 11 0 0 1 13 0M9 15.5a6 6 0 0 1 6 0"/><circle cx="12" cy="19" r="1" fill="currentColor" stroke="none"/>',
    'droplet'       => '<path d="M12 2s7 8 7 13a7 7 0 0 1-14 0c0-5 7-13 7-13z" fill="currentColor" stroke="none"/>',
    'square'        => '<rect x="4" y="4" width="16" height="16" rx="2"/>',
    'search'        => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
    'check'         => '<path d="M4 12l5 5L20 6"/>',
    'x'             => '<path d="M5 5l14 14M19 5L5 19"/>',
    'lightbulb'     => '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.6 10.8c.6.5 1 1.2 1.1 2h5c.1-.8.5-1.5 1.1-2A6 6 0 0 0 12 3z"/>',
    'help-circle'   => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7v.3M12 17v.01"/>',
    'walk'          => '<circle cx="12" cy="4" r="2"/><path d="M12 6v5l-3 7M12 11l3 7M9 10l-2 3M15 10l2 3"/>',
    'camera'        => '<path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="4"/>',
    'save'          => '<path d="M5 3h11l3 3v15H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M8 3v6h8V3M8 21v-7h8v7"/>',
    'eye'           => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    'building'      => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7h1M8 11h1M8 15h1M15 7h1M15 11h1M15 15h1M10 21v-4h4v4"/>',
    'arrow-right'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'message-circle'=> '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
    'frown'         => '<circle cx="12" cy="12" r="9"/><path d="M8 15a4 4 0 0 1 8 0"/><path d="M9 9h.01M15 9h.01"/>',
    'flame'         => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" fill="currentColor" stroke="none"/>',
    'warning'       => '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v4M12 17v.01"/>',
];

/**
 * rr_icon('bell', 'my-class') -> '<svg class="rr-icon my-class" ...>...</svg>'
 * Без обёртки — просто echo rr_icon('bell') прямо в разметке, как раньше
 * писали эмодзи. Неизвестное имя — пустая строка, а не фатальная ошибка,
 * чтобы опечатка в имени иконки не роняла страницу.
 */
function rr_icon($name, $class = '') {
    if (!isset(RR_ICONS[$name])) {
        return '';
    }
    $classAttr = 'rr-icon' . ($class ? ' ' . $class : '');
    return '<svg class="' . htmlspecialchars($classAttr) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . RR_ICONS[$name] . '</svg>';
}
