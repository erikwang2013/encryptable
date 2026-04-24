# Encryptable（erikwang2013/encryptable）

在 **PHP 8.2+** 环境下，为敏感字段提供「可检索的匿名化 / 加密」能力：写入数据库前加密，经 Eloquent（或手动 API）读出时解密；同时可生成与 **MySQL / PostgreSQL** 兼容的 SQL 片段，便于在查询条件中对接已加密列。命名空间仍为 `Maize\Encryptable\...`（历史兼容）。

本仓库在思路与行为上参考并演进自开源项目 **[laravel-encryptable](https://github.com/maize-tech/laravel-encryptable)**（Maize Tech）。若需对照原版设计、Issue 与发布说明，请优先查阅该上游仓库。

---

## 功能概览

- **Eloquent 自定义 Cast**：在模型 `$casts` 中使用 `Encryptable::class`，自动加解密指定属性。
- **PHP 侧加解密**：`Encryption::php()->encrypt()` / `decrypt()`，适合命令行、队列、非模型场景。
- **数据库表达式**：`Encryption::db()->decrypt()` 返回可在 SQL 中拼接的解密片段（MySQL / Postgres 语法分支），便于 `whereRaw` 等与密文列对照查询。
- **校验规则**：`UniqueEncrypted`、`ExistsEncrypted` 及 `Rule::uniqueEncrypted()` / `Rule::existsEncrypted()` 宏（依赖 Laravel 的 `illuminate/validation`）。
- **多运行时桥接**：在 **Laravel 10–12**、基于 Illuminate 的 **Webman**、**Hyperf 2–3**、**ThinkPHP 6–8** 下通过各自方式注册容器与配置；无完整容器时可用环境变量兜底 `Encryption::php()`（各栈能力与接入方式见下文 **「支持的框架」** 表格）。

---

## 环境要求

| 项目 | 说明 |
|------|------|
| PHP | `^8.2`，需启用 `openssl` 扩展 |
| 数据库 | 文档与实现针对 **MySQL**、**PostgreSQL**（`Encryption::db()` 的方言检测依赖驱动名） |

Composer 包名：**[erikwang2013/encryptable](https://packagist.org/packages/erikwang2013/encryptable)**（`composer.json` 中 `name` 字段）。

---

## 支持的框架

下表说明各栈的**约定兼容范围**、接入方式，以及本包能力与 Laravel 专属能力（Eloquent Cast、基于 `illuminate/validation` 的规则）的对应关系。实际以你项目锁定的 PHP / 框架小版本为准。**配置文件请按下文「安装 → 配置文件」表格复制到对应目录。**

| 框架 | 版本（约定） | 接入方式 | Eloquent `$casts`（`Encryptable`） | `Encryption::php()` | `Encryption::db()` | `UniqueEncrypted` / `ExistsEncrypted` 与宏 |
|------|----------------|----------|-----------------------------------|---------------------|---------------------|---------------------------------------------|
| **Laravel** | 10.x ~ 12.x（运行环境 PHP ≥ 8.2） | Composer 包发现注册 `EncryptableServiceProvider` | ✓ | ✓ | ✓ | ✓ |
| **Webman** | 1.x / 2.x，且项目已引入 **Illuminate**（database / support / validation） | 注册 `EncryptableServiceProvider` + 按安装节复制配置 | ✓（使用 Eloquent 时） | ✓ | ✓ | ✓ |
| **Hyperf** | 2.x / 3.x | `extra.hyperf.config` 合并 `Bridge\Hyperf\ConfigProvider` + 按安装节复制 Hyperf 专用配置 | —（非 Laravel 模型 Cast；可在实体/仓储中调用 `Encryption::php()`） | ✓ | ✓（需安装 `hyperf/db-connection`） | —（依赖 Illuminate 校验栈） |
| **ThinkPHP** | 6.x ~ 8.x | `ThinkphpEncryptable::register($app)` + 按安装节复制配置 | —（请用模型获取器/修改器或类型字段自行调用 `Encryption::php()`） | ✓ | ✓ | — |

**图例：** ✓ 表示该能力在本栈有官方桥接或可直接使用；**—** 表示本包未提供该栈的专用实现，需自行在业务层对接。

---

## 安装

```bash
composer require erikwang2013/encryptable
```

### 配置文件（按框架复制到配置目录）

根据当前项目使用的框架，**将包内对应模板复制到该框架约定的配置路径**（再按需修改 `.env` 等）。

| 框架 | 复制源（vendor 内路径，包名以 `erikwang2013/encryptable` 为准） | 复制到（项目内目标路径） |
|------|------------------------------------------------------------------|--------------------------|
| **Laravel** | `vendor/erikwang2013/encryptable/config/encryptable.php` | `config/encryptable.php` |
| **Webman** | 同上 | `config/encryptable.php` |
| **ThinkPHP** | 同上 | `config/encryptable.php` |
| **Hyperf** | `vendor/erikwang2013/encryptable/config/stubs/hyperf-autoload-encryptable.php` | `config/autoload/encryptable.php` |

> **说明：** Laravel / Webman / ThinkPHP 使用同一份扁平模板 `config/encryptable.php`（顶层即 `key`、`cipher`）。Hyperf 的 `HyperfEncryptableConfig` 读取的是 `encryptable.key` / `encryptable.cipher`，因此必须使用 `config/stubs/hyperf-autoload-encryptable.php` 中带 **`encryptable` 分组** 的写法，**不要**直接把 Laravel 用扁平数组的那份文件拷成 `config/autoload/encryptable.php` 而不包一层 `encryptable` 键。

**Shell 示例：**

```bash
# Laravel / Webman / ThinkPHP
cp vendor/erikwang2013/encryptable/config/encryptable.php config/encryptable.php

# Hyperf
cp vendor/erikwang2013/encryptable/config/stubs/hyperf-autoload-encryptable.php config/autoload/encryptable.php
```

Laravel 也可改用发布命令（与手动复制到 `config/encryptable.php` 等价）：

```bash
php artisan vendor:publish --provider="Maize\Encryptable\EncryptableServiceProvider" --tag="encryptable-config"
```

### Laravel（其余步骤）

安装后若已启用包自动发现，会注册 `Maize\Encryptable\EncryptableServiceProvider`。完成上表 **Laravel** 行配置复制或执行 `vendor:publish` 即可。

### Webman（使用 Illuminate 组件时）

按需安装 `illuminate/database`、`illuminate/support`、`illuminate/validation` 等，完成上表 **Webman** 行配置复制后，在插件或启动流程中注册：

`Maize\Encryptable\EncryptableServiceProvider`

### Hyperf

1. 完成上表 **Hyperf** 行配置复制。
2. 本包在 `composer.json` 的 `extra.hyperf.config` 中声明了 `Maize\Encryptable\Bridge\Hyperf\ConfigProvider`，由 Hyperf 合并依赖注入配置。
3. 若使用 `Encryption::db()`，请安装 **`hyperf/db-connection`**。

### ThinkPHP

1. 完成上表 **ThinkPHP** 行配置复制。
2. 在应用启动阶段（例如服务注册或引导类中）调用：

```php
\Maize\Encryptable\Bridge\ThinkPHP\ThinkphpEncryptable::register($app);
```

---

## 配置说明

复制或发布得到 `config/encryptable.php`（或 Hyperf 下 `config/autoload/encryptable.php` 内 `encryptable` 段）后，核心项为：

| 键 | 说明 |
|----|------|
| `key` | 加密密钥，对应环境变量 `ENCRYPTION_KEY`。**一旦用于生产数据，请勿随意更换**，否则历史密文无法解密。 |
| `cipher` | 算法标识，默认 `aes-128-ecb`，对应 `ENCRYPTION_CIPHER`；需与数据库侧（如 MySQL 默认）及既有数据约定一致。 |

无 Laravel / 未绑定容器时，`Encryption::php()` 可回退读取 **`ENCRYPTION_KEY`**、**`ENCRYPTION_CIPHER`**（见 `EnvEncryptableConfig`）。

---

## 使用说明

### 1. 模型 Cast（Laravel / 使用 Eloquent 的 Webman）

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

读写该模型属性时，会自动经过 PHP 侧加解密。

### 2. 手动加解密（PHP）

```php
use Maize\Encryptable\Encryption;

$plain = '需要保护的值';
$cipher = Encryption::php()->encrypt($plain);

$restored = Encryption::php()->decrypt($cipher);
```

### 3. 生成 SQL 中的解密表达式（DB）

```php
use Maize\Encryptable\Encryption;

// 返回可在 SELECT / WHERE 中使用的片段（具体语法随 MySQL / Postgres 变化）
$expr = Encryption::db()->decrypt($encryptedPayloadFromDb);
```

> `DBEncrypter::encrypt()` 会抛出「不支持」异常；本类主要面向「在 SQL 中解密比对」的场景。

### 4. 自定义验证规则

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
        // 或 Rule::existsEncrypted('users')
    ],
]);

Validator::make($data, [
    'email' => [
        'required',
        'email',
        new UniqueEncrypted('users'),
        // 或 Rule::uniqueEncrypted('users')
    ],
]);
```

---

## 依赖与 Composer 说明

- **正式依赖**仅包含 `php`、`ext-openssl`、`psr/container`，避免在 **Hyperf** 等栈中强行拉入整套 Illuminate。
- **Laravel / Webman（Illuminate）**：请通过 `laravel/framework` 或按需引入的 `illuminate/*` 满足 `EncryptableServiceProvider`、Cast 与验证规则对 Illuminate 的要求（详见 `composer.json` 的 `suggest` 段）。

---

## 开发与测试

```bash
composer install
composer test        # Pest
composer analyse     # PHPStan（需项目内配置）
composer format      # Laravel Pint
```

---

## 参考与致谢

- **上游参考实现**：[maize-tech/laravel-encryptable](https://github.com/maize-tech/laravel-encryptable) — 原始 Laravel 包的设计、文档与社区讨论请以该仓库为准。
- 本分支在 **多框架桥接**、**Composer 元数据**、**配置与容器解析**等方面做了扩展与调整；若你正在从上游迁移，请对照两边的 `CHANGELOG` 与配置项。

---

## 许可证

MIT。详见仓库内 `LICENSE.md`。
