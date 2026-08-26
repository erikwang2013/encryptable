# UNCOVERED — 未覆盖模块及原因

以下 src 类**无法在当前开发环境实例化**（硬依赖未安装的框架类），未编写测试。其余全部 src 模块均有覆盖（见 tests/reports/test-report.md）。

| 类 | 硬依赖（未安装） | 可测性 |
|---|---|---|
| `src/Bridge/Hyperf/HyperfEncryptableConfig.php` | `Hyperf\Contract\ConfigInterface`（构造器类型约束） | 不可实例化 |
| `src/Bridge/Hyperf/HyperfDbDriverDetector.php` | `Hyperf\DbConnection\Db`（静态调用） | 实例化后调用即 fatal |
| `src/Bridge/ThinkPHP/ThinkEncryptableConfig.php` | `think\facade\Config`（静态调用） | 实例化后调用即 fatal |
| `src/Bridge/ThinkPHP/ThinkDbDriverDetector.php` | `think\facade\Config`（静态调用） | 实例化后调用即 fatal |
| `src/Bridge/ThinkPHP/ThinkphpEncryptable.php` | `think\App`（`register()` 签名类型约束） | 不可实例化 |
| `src/Composer/Plugin.php` | `Composer\Plugin\PluginInterface`、`Composer\Composer` 等 Composer API | 不可实例化 |

已覆盖的同目录纯逻辑类：

- `src/Bridge/Hyperf/ConfigProvider.php`（纯数组 `__invoke`，100% 行覆盖，tests/BridgePureTest.php）
- `src/Bridge/ThinkPHP/ThinkPsrContainerAdapter.php`（仅依赖 `Psr\Container\ContainerInterface`，88.9% 行覆盖，tests/BridgePureTest.php）

验证命令：

```bash
php -r "require 'vendor/autoload.php'; var_dump(class_exists('Hyperf\\Contract\\ConfigInterface'), class_exists('think\\App'), class_exists('Composer\\Composer'));"
# bool(false) bool(false) bool(false)
```

安装 Hyperf / ThinkPHP / Composer API 后，可对照本表补齐：

1. Hyperf：构造 `HyperfEncryptableConfig` 需要一个实现 `ConfigInterface::get()` 的桩；`HyperfDbDriverDetector` 需替换 `Db::connection()`（静态桩或容器注入）。
2. ThinkPHP：`think\facade\Config` 是门面，可先 `Config::set()` 再断言。
3. Composer：`Plugin::onPostPackageEvent()` 需要构造 `PackageEvent`（含 `Composer`、`IOInterface`、`InstallOperation` 桩），可在临时目录验证 `ensurePluginAppPhp` 的文件发布逻辑。
