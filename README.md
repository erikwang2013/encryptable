**Languages:** **English (this page)** | [简体中文](docs/README.zh-CN.md)

---

# Encryptable (`erikwang2013/encryptable`)

On **PHP 8.2+**, this package helps you **anonymize / encrypt sensitive attributes in a query-friendly way**: values are encrypted before persistence and decrypted when read through Eloquent (or manual APIs). It can also emit **MySQL / PostgreSQL**-compatible SQL fragments so you can compare or search against encrypted columns in raw queries. The PHP namespace remains `Maize\Encryptable\...` for historical compatibility.

This repository evolves from the ideas and behaviour of **[laravel-encryptable](https://github.com/maize-tech/laravel-encryptable)** (Maize Tech). For the original design, issues, and releases, refer to that upstream project.

---

## Features

- **Eloquent custom cast**: use `Encryptable::class` in `$casts` for transparent encrypt/decrypt on attributes.
- **PHP-side crypto**: `Encryption::php()->encrypt()` / `decrypt()` for CLI, queues, or non-model code paths.
- **DB expressions**: `Encryption::db()->decrypt()` returns a SQL snippet (MySQL vs Postgres branches) suitable for `whereRaw`-style comparisons against stored ciphertext.
- **Validation**: `UniqueEncrypted`, `ExistsEncrypted`, and `Rule::uniqueEncrypted()` / `Rule::existsEncrypted()` macros (requires `illuminate/validation`).
- **Multi-runtime bridges**: Laravel **10–12**, Illuminate-based **Webman**, **Hyperf 2–3**, and **ThinkPHP 6–8** each register container/config in their own way; without a full container, `Encryption::php()` can fall back to **`ENCRYPTION_KEY`** / **`ENCRYPTION_CIPHER`** (see the **Supported frameworks** table below).

---

## Requirements

| Item | Notes |
|------|--------|
| PHP | `^8.2` with the `openssl` extension |
| Databases | Docs and SQL helpers target **MySQL** and **PostgreSQL** (driver name is used to pick the dialect for `Encryption::db()`). |

Packagist: **[erikwang2013/encryptable](https://packagist.org/packages/erikwang2013/encryptable)** (`name` in `composer.json`).

---

## Supported frameworks

The table below summarizes **expected compatibility**, how to wire the package, and which features are Laravel-specific (Eloquent cast, `illuminate/validation` rules). Pin versions in your own project as needed. **Copy the config files as described under Installation → Configuration files.**

| Framework | Version (expected) | Integration | Eloquent `$casts` (`Encryptable`) | `Encryption::php()` | `Encryption::db()` | `UniqueEncrypted` / `ExistsEncrypted` & macros |
|-----------|--------------------|--------------|-------------------------------------|----------------------|---------------------|--------------------------------------------------|
| **Laravel** | 10.x–12.x (PHP ≥ 8.2) | Package discovery registers `EncryptableServiceProvider` | ✓ | ✓ | ✓ | ✓ |
| **Webman** | 1.x / 2.x with **Illuminate** (database / support / validation) | Register `EncryptableServiceProvider` + copy config per Installation | ✓ (when using Eloquent) | ✓ | ✓ | ✓ |
| **Hyperf** | 2.x / 3.x | `extra.hyperf.config` merges `Bridge\Hyperf\ConfigProvider` + copy Hyperf-specific config | — (no Laravel cast; call `Encryption::php()` in entities/repos) | ✓ | ✓ (install `hyperf/db-connection`) | — (needs Illuminate validation stack) |
| **ThinkPHP** | 6.x–8.x | `ThinkphpEncryptable::register($app)` + copy config | — (use accessors/mutators or types with `Encryption::php()`) | ✓ | ✓ | — |

**Legend:** ✓ = supported for this stack out of the box; — = not provided; integrate in your own layer.

---

## Installation

```bash
composer require erikwang2013/encryptable
```

### Configuration files (copy into your framework’s config directory)

For the stack you use, **copy the matching template from this package into the path your framework expects**, then adjust `.env` as needed.

| Framework | Source inside `vendor` (package `erikwang2013/encryptable`) | Destination in your project |
|-----------|--------------------------------------------------------------|------------------------------|
| **Laravel** | `vendor/erikwang2013/encryptable/config/encryptable.php` | `config/encryptable.php` |
| **Webman** | same as above | `config/encryptable.php` |
| **ThinkPHP** | same as above | `config/encryptable.php` |
| **Hyperf** | `vendor/erikwang2013/encryptable/config/stubs/hyperf-autoload-encryptable.php` | `config/autoload/encryptable.php` |

> **Note:** Laravel / Webman / ThinkPHP share the **flat** template `config/encryptable.php` (top-level `key`, `cipher`). `HyperfEncryptableConfig` reads `encryptable.key` / `encryptable.cipher`, so you **must** use the stub that wraps settings under an **`encryptable`** key. Do **not** copy the flat Laravel file into `config/autoload/encryptable.php` without that wrapper.

**Shell examples:**

```bash
# Laravel / Webman / ThinkPHP
cp vendor/erikwang2013/encryptable/config/encryptable.php config/encryptable.php

# Hyperf
cp vendor/erikwang2013/encryptable/config/stubs/hyperf-autoload-encryptable.php config/autoload/encryptable.php
```

Laravel alternative (equivalent to copying into `config/encryptable.php`):

```bash
php artisan vendor:publish --provider="Maize\Encryptable\EncryptableServiceProvider" --tag="encryptable-config"
```

### Laravel (remaining steps)

With package auto-discovery enabled, `Maize\Encryptable\EncryptableServiceProvider` is registered. Finish either the **Laravel** row in the table above or run `vendor:publish`.

### Webman (with Illuminate)

Install `illuminate/database`, `illuminate/support`, `illuminate/validation`, etc. as needed. After copying config for **Webman**, register:

`Maize\Encryptable\EncryptableServiceProvider`

in your plugin/bootstrap code.

### Hyperf

1. Copy config per the **Hyperf** row in the table.
2. This package declares `Maize\Encryptable\Bridge\Hyperf\ConfigProvider` under `composer.json` → `extra.hyperf.config` for Hyperf to merge.
3. For `Encryption::db()`, install **`hyperf/db-connection`**.

### ThinkPHP

1. Copy config per the **ThinkPHP** row.
2. During application bootstrap (e.g. service registration), call:

```php
\Maize\Encryptable\Bridge\ThinkPHP\ThinkphpEncryptable::register($app);
```

---

## Configuration

After copying or publishing `config/encryptable.php` (or the `encryptable` section inside Hyperf’s `config/autoload/encryptable.php`), the main keys are:

| Key | Description |
|-----|-------------|
| `key` | Secret key, from `ENCRYPTION_KEY`. **Do not rotate casually in production** or existing ciphertext becomes unreadable. |
| `cipher` | Cipher id, default `aes-128-ecb`, from `ENCRYPTION_CIPHER`; keep aligned with DB defaults and any existing data contract. |

Without Laravel / bindings, `Encryption::php()` can still read **`ENCRYPTION_KEY`** and **`ENCRYPTION_CIPHER`** via `EnvEncryptableConfig`.

---

## Usage

### 1. Model cast (Laravel / Webman with Eloquent)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Maize\Encryptable\Encryptable;

class User extends Model
{
    protected $fillable = ['name', 'email'];

    protected $casts = [
        'name' => Encryptable::class,
        'email' => Encryptable::class,
    ];
}
```

Attributes are encrypted/decrypted automatically on read/write.

### 2. Manual encrypt/decrypt (PHP)

```php
use Maize\Encryptable\Encryption;

$plain = 'value to protect';
$cipher = Encryption::php()->encrypt($plain);

$restored = Encryption::php()->decrypt($cipher);
```

### 3. SQL decrypt expression (DB)

```php
use Maize\Encryptable\Encryption;

// Fragment usable in SELECT / WHERE (syntax differs for MySQL vs Postgres)
$expr = Encryption::db()->decrypt($encryptedPayloadFromDb);
```

> `DBEncrypter::encrypt()` throws “not supported”; this path targets **SQL-side decrypt/compare** scenarios.

### 4. Custom validation rules

```php
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maize\Encryptable\Rules\ExistsEncrypted;
use Maize\Encryptable\Rules\UniqueEncrypted;

Validator::make($data, [
    'email' => [
        'required',
        'email',
        new ExistsEncrypted('users'),
        // or Rule::existsEncrypted('users')
    ],
]);

Validator::make($data, [
    'email' => [
        'required',
        'email',
        new UniqueEncrypted('users'),
        // or Rule::uniqueEncrypted('users')
    ],
]);
```

---

## Composer / dependencies

- **Runtime `require`**: only `php`, `ext-openssl`, and `psr/container`, so **Hyperf** projects are not forced to pull all of Illuminate.
- **Laravel / Webman (Illuminate)**: satisfy `EncryptableServiceProvider`, casts, and rules via `laravel/framework` or the suggested `illuminate/*` packages (see `composer.json` → `suggest`).

---

## Development

```bash
composer install
composer test        # Pest
composer analyse     # PHPStan (requires local config)
composer format      # Laravel Pint
```

---

## References & credits

- **Upstream reference**: [maize-tech/laravel-encryptable](https://github.com/maize-tech/laravel-encryptable) — canonical design, issues, and community discussion for the original Laravel package.
- This fork extends **multi-framework bridges**, **Composer metadata**, and **config/container resolution**; compare `CHANGELOG` and config when migrating from upstream.

---

## License

MIT. See `LICENSE.md`.
