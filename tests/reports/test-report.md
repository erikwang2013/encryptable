# encryptable 单元测试报告

- 日期：2026-08-27
- 环境：PHP 8.3.7，PHPUnit 11.5.50，PCOV 1.0.12
- 命令：`vendor/bin/phpunit --log-junit tests/reports/phpunit.xml`
- 结果：**208 tests, 413 assertions, 0 failures, 0 errors, 0 warnings, 0 deprecations**
- 覆盖率（`--coverage-filter src`）：Lines **63.57% (438/689)**，Methods **68.14% (77/113)**

## 模块清单（本批新增 14 个测试文件）

| 测试文件 | 覆盖模块 | 测试数 |
|---|---|---|
| tests/PHPEncrypterTest.php | PHPEncrypter（V1/V2 格式、AEAD、dirty-bit、HMAC 完整性、V0 旧格式、轮换、isEncrypted 边界） | 25 |
| tests/DBEncrypterTest.php | DBEncrypter（确定性格式、HMAC 前置、ECB 选择、SQL 片段、列名校验、引号转义） | 19 |
| tests/EncrypterTest.php | Encrypter 抽象基类（cipher 归一化、密钥长度/格式校验、密钥环去重、进程级缓存） | 13 |
| tests/EncryptionFacadeTest.php | Encryption 静态门面（php()/db()、resolver、PSR-11 容器、app() 容器、fallback、rotate 限制） | 10 |
| tests/EncryptableCastTest.php | Encryptable（Eloquent CastsAttributes trait） | 5 |
| tests/SerializerTest.php | Utils/Serializer | 9 |
| tests/EnvConfigTest.php | Config/EnvEncryptableConfig | 10 |
| tests/SupportTest.php | Support/PreviousKeysParser + PackagePluginPaths | 17 |
| tests/RulesTest.php | Rules/UniqueEncrypted + ExistsEncrypted（真实 Validator + 桩 PresenceVerifier，无真实 DB） | 12 |
| tests/ExceptionsTest.php | Exceptions/ 6 个异常类 | 3 |
| tests/BridgeLaravelTest.php | Bridge/Laravel（IlluminateEncryptableConfig + IlluminateDbDriverDetector） | 11 |
| tests/WebmanPluginConfigTest.php | Bridge/Webman/WebmanPluginEncryptableConfig | 9 |
| tests/ServiceProviderTest.php | EncryptableServiceProvider（register/boot/宏/发布路径） | 8 |
| tests/BridgePureTest.php | Bridge/Hyperf/ConfigProvider + Bridge/ThinkPHP/ThinkPsrContainerAdapter | 6 |

另有既有 3 个文件（EncrypterBehaviorTest、EncryptionTest、KeyRotationTest，含 KeyRingTestConfig 辅助类，被本批全部复用）继续通过。

## 行覆盖率（按模块，100% 之外的）

| 类 | 行 % | 未覆盖原因 |
|---|---|---|
| PHPEncrypter | 93.4% (113/121) | 缓存分支、V1-with-AEAD 拒绝路径等防御性分支 |
| DBEncrypter | 93.2% (41/44) | openssl_encrypt 失败分支（无法注入失败） |
| Encryption | 89.4% (42/47) | Hyperf 分支（未安装）、抛 RuntimeException 分支的个别路径 |
| EncryptableServiceProvider | 89.3% (50/56) | Webman 项目布局判断分支（需真实 Webman 目录） |
| Serializer | 94.7% (18/19) | settype 失败分支（无法注入失败） |
| ThinkPsrContainerAdapter | 88.9% (8/9) | 同时具备 bound+ContainerInterface 的混合分支 |
| WebmanPluginEncryptableConfig | 87.5% (14/16) | appConfigAbsolutePath 无 base_path() 分支（本机有 Laravel） |
| 其余可测类 | 100% | — |

未覆盖的 6 个类（HyperfEncryptableConfig / HyperfDbDriverDetector / ThinkEncryptableConfig / ThinkDbDriverDetector / ThinkphpEncryptable / Composer\Plugin）硬依赖未安装框架，**无法加载**，原因明细见 tests/UNCOVERED.md。

## src 业务代码发现与修复

1. **Encryption::setResolver() 无法撤销（已修复）** — 参数改为 `?callable`，`setResolver(null)` 复位默认 fallback 解析（`doResolve()` 已有 null 守卫，解析逻辑未动）。
2. **tryDecryptV2 对非 AEAD 密码传 tag 触发 PHP warning（已修复）** — openssl_decrypt 前加 `isAeadCipher($cipher)` 守卫，非 AEAD 密码不传 $tag；新增回归测试 `test_v2_marker_with_non_aead_cipher_does_not_emit_warning`（V1 载荷翻 marker 为 \x02 且 HMAC 有效，断言无 warning 且静默返回载荷）。

修复后经 reviewer 复审（APPROVE），并清理测试中残留的反射复位写法（EncryptionFacadeTest/RulesTest 改用 `setResolver(null)`）。

## 说明

- Rules 测试通过注入桩 `PresenceVerifierInterface` 到真实 `Illuminate\Validation\Factory`，验证了「传给校验器的值是密文而非明文」这一核心语义，全程未连真实数据库。
- 既有测试风格（PHPUnit、strict_types、版权头、snake_case 方法名）已沿用；每个文件 < 500 行。
- composer audit：33 条告警已全部清零（require-dev 传递依赖补丁级升级，composer.json 零改动），`composer audit --locked` 通过。
- 兼容性：composer.json `php: ^8.0`（运行时），phpstan `phpVersion: 80000` level 8 无错误；CI 矩阵（.github/workflows/ci.yml）覆盖 PHP 8.2/8.3/8.4 全量测试 + PHP 8.0 语法校验；推送规则（.github/workflows/release.yml）自动增量创建 tag 与 release，不含打包步骤。
