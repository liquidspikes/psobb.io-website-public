# PSOBB Player Portal Custom Modules

This directory allows developers to create custom modules for the Player Portal.

## How to Create a Custom Portal Module

1. Create a `.php` file in this directory (e.g. `lottery.php` or `guild_vault.php`).
2. Inside the file, register your module using `register_portal_module()`:

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
    'render_callback' => function(array $context) {
        ?>
        <div style="padding: 1.5rem; border: 1px solid rgba(0, 255, 255, 0.2); background: rgba(0, 10, 20, 0.5); border-radius: 8px;">
            <h3 style="color:#00ffff; font-family:'Share Tech Mono', monospace; margin-top:0;">
                <i class="fas fa-dice"></i> Lucky Wheel
            </h3>
            <p>Welcome to your custom module view!</p>
        </div>
        <?php
    }
]);
```

## Features Supported Out-of-the-Box:
- **Instant Discovery**: Automatically loaded by `includes/portal/modules.php`.
- **Admin Visibility Control**: Appears automatically in the Admin Site Settings (`admin/site_settings.php`) under "Player Portal Modules" where an administrator can toggle between `Everyone`, `Admin Only` (for private testing), or `Disabled`.
- **Multi-language Support**: Use `__('Your Text')` for translations.
