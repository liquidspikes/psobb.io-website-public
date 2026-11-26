# PSOBB Player Portal Custom Modules

This directory allows developers to drop in custom modules for the Player Portal (`login.php` dashboard) via `includes/portal/modules.php`.

## Built-In Module Keys & Order

Avoid colliding with these built-in keys and DOM pane IDs when choosing your module `'key'`, `'id'`, and `'order'`:

| Key | DOM ID | Order | Location |
|---|---|---|---|
| `hub` | `tab-hub` | `10` | `includes/portal/modules/hub.php` |
| `characters` | `tab-banks` | `20` | `includes/portal/modules/characters.php` |
| `bank` | `tab-bank` | `30` | `includes/portal/modules/bank.php` |
| `guild` | `tab-guild` | `40` | `includes/portal/modules/guild.php` |
| `tekker` | `tab-tekker` | `50` | `includes/portal/modules/tekker.php` |
| `lfg` | `tab-lfg` | `60` | `includes/portal/modules/lfg.php` |
| `chat` | `tab-chat` | `70` | `includes/portal/modules/chat.php` |
| `settings` | `tab-settings` | `80` | `includes/portal/modules/settings.php` |

## How to Create a Custom Portal Module

1. Create a `.php` file in this directory (e.g. `lottery.php` — see `example_tab.php.sample`, which is ignored by `modules.php` until renamed to `*.php`).
2. Inside the file, register your module with `register_portal_module()`, supplying either a **`'file'`** template path or a **`'render_callback'`**:

```php
<?php
/**
 * Custom Portal Module Example
 */
register_portal_module('lottery', [
    'id'          => 'tab-lottery',
    'name'        => __('Lucky Wheel'),
    'icon'        => 'fas fa-dice',
    'description' => __('Spin the daily roulette for random item drops and meseta.'),
    'order'       => 85,
    'default'     => 'everyone', // 'everyone', 'admin_only', or 'disabled'
    // Either specify 'file' => __DIR__ . '/lottery_view.php', or 'render_callback':
    'render_callback' => function(array $context) {
        $slots = $context['existing_slots'] ?? [];
        ?>
        <div style="padding: 1.5rem; border: 1px solid rgba(0, 255, 255, 0.2); background: rgba(0, 10, 20, 0.5); border-radius: 8px;">
            <h3 style="color:#00ffff; font-family:'Share Tech Mono', monospace; margin-top:0;">
                <i class="fas fa-dice"></i> Lucky Wheel
            </h3>
            <p>Welcome to your custom module view! Character slots detected: <?= count($slots) ?></p>
        </div>
        <?php
    }
]);
```

## Runtime Context, Helpers & Auth

* **Context & Globals:** `render_portal_panes()` passes `$context` (including `$context['existing_slots']`, extracted into scope when using `'file'`) and brings globals `$EXP_RATE`, `$DROP_RATE`, `$MESETA_RATE`, `$NEWSERV_COMMAND_PREFIX`, and `$_SESSION['user']` into scope.
* **Admin Visibility Control:** Registered custom modules appear in `/admin/site_settings.php` under "Player Portal Modules", where administrators can override visibility between `everyone`, `admin_only`, or `disabled` (falling back to the module's `'default'` until saved in `config/site.json`).
* **Frontend Helpers & CSRF:** Shared helpers (`escapeHtml`, `window.getCSRFToken()`, etc.) are loaded from `js/pso_utils.js`. Include `'X-CSRF-Token': window.getCSRFToken()` on `POST`/`DELETE` `fetch()` calls.
* **Multi-language Support:** Use `__('Your Text')` from `api/lang.php` for UI localization.
