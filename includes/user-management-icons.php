<?php
/** Decorative icons for the user management buttons; labels remain visible. */
return static function (string $name): string {
    $paths = [
        'add' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M20 8v6m-3-3h6"/>',
        'filter' => '<path d="M4 7h16M7 12h10m-7 5h4"/>',
        'clear' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'edit' => '<path d="m16 3 5 5-12 12-6 1 1-6Z"/><path d="m14 5 5 5"/>',
        'delete' => '<path d="M3 6h18M9 6V3h6v3m-10 0 1 15h12l1-15M10 10v7m4-7v7"/>',
        'save' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12l4 4v12a2 2 0 0 1-2 2Z"/><path d="M7 3v6h10V3M7 21v-8h10v8"/>',
        'role' => '<path d="m12 3 8 4v5c0 5-8 9-8 9s-8-4-8-9V7Z"/><path d="m8 12 3 3 5-6"/>',
        'power' => '<path d="M12 2v10M6 5a9 9 0 1 0 12 0"/>',
        'key' => '<circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-4-4 3-3m-6 0 3-3"/>',
        'mobile' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2m-4-8 2 2 4-4"/>',
        'unlock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0m-4 9v2"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="m22 2-11 11"/>',
        'retry' => '<path d="M3 11a9 9 0 1 1 2.6 7M3 4v7h7"/>',
    ];
    return '<svg class="user-action-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
};
