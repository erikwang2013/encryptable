<?php

namespace Maize\Encryptable\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Publishes default config files following each supported framework's official layout.
 *
 * Detection order (merged):
 * 1) {@code vendor/composer/installed.php} / {@code installed.json} — everything actually present in vendor
 * 2) {@code composer.lock} — full resolved graph
 * 3) Root {@code composer.json} {@code require} / {@code require-dev} keys
 * 4) Project filesystem heuristics (Webman {@code support/bootstrap.php}, Laravel {@code artisan}, Hyperf {@code bin/hyperf.php}, ThinkPHP {@code think}, …)
 *
 * @see https://laravel.com/docs/configuration
 * @see https://www.workerman.net/doc/webman/config.html
 * @see https://doc.thinkphp.cn/v8_0/config_file.html
 * @see https://hyperf.wiki/en/config.html
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'erikwang2013/encryptable';

    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageEvent',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageEvent',
        ];
    }

    public function onPostPackageEvent(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        $package = null;
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        }

        if ($package === null || $package->getName() !== self::PACKAGE_NAME) {
            return;
        }

        $composer = $event->getComposer();
        $io = $event->getIO();
        $installationManager = $composer->getInstallationManager();
        $installPath = $installationManager->getInstallPath($package);

        if ($installPath === null || $installPath === '' || ! is_dir($installPath)) {
            return;
        }

        $vendorDir = $composer->getConfig()->get('vendor-dir');
        if (! is_string($vendorDir) || $vendorDir === '') {
            return;
        }

        $vendorReal = realpath($vendorDir);
        if ($vendorReal === false) {
            return;
        }

        $projectRoot = dirname($vendorReal);
        $mainSource = $installPath.'/config/encryptable.php';
        $hyperfStub = $installPath.'/config/stubs/hyperf-autoload-encryptable.php';

        $names = $this->collectPackageNamesLowercase($projectRoot);
        $fs = $this->inferFrameworkFromFilesystem($projectRoot);

        $laravel = isset($names['laravel/framework']) || $fs['laravel'];
        $lumen = isset($names['laravel/lumen-framework']);
        $webman = isset($names['workerman/webman'])
            || $this->hasComposerPackagePrefix($names, 'webman/')
            || $fs['webman'];
        $hyperf = isset($names['hyperf/framework'])
            || isset($names['hyperf/hyperf'])
            || isset($names['hyperf/http-server'])
            || $fs['hyperf'];
        $thinkphp = isset($names['topthink/framework'])
            || isset($names['topthink/think'])
            || $fs['thinkphp'];

        $publishFlat = $laravel || $lumen || $webman || $thinkphp;
        $publishHyperfAutoload = $hyperf;

        if (! $publishFlat && ! $publishHyperfAutoload) {
            $io->write(sprintf(
                '<comment>[%s]</comment> Skipped auto config: no supported framework detected (checked vendor/composer/installed.php|.json, composer.lock, composer.json, and project layout). Copy files manually (see README).',
                self::PACKAGE_NAME
            ));

            return;
        }

        if ($publishHyperfAutoload && is_readable($hyperfStub)) {
            $this->publishHyperfAutoloadConfig($projectRoot, $hyperfStub, $io);
        }

        if ($publishFlat && is_readable($mainSource)) {
            $this->publishFlatConfig($projectRoot, $mainSource, $io, $laravel, $lumen, $webman, $thinkphp);
        }
    }

    /**
     * @return array<string, true> Lowercased Composer package names
     */
    private function collectPackageNamesLowercase(string $projectRoot): array
    {
        $names = [];

        $installedPhp = $projectRoot.'/vendor/composer/installed.php';
        if (is_readable($installedPhp)) {
            /** @var array<string, mixed>|false $data */
            $data = @include $installedPhp;
            if (is_array($data) && isset($data['versions']) && is_array($data['versions'])) {
                foreach (array_keys($data['versions']) as $pkgName) {
                    if (is_string($pkgName) && $pkgName !== '') {
                        $names[strtolower($pkgName)] = true;
                    }
                }
            }
        }

        $installedJson = $projectRoot.'/vendor/composer/installed.json';
        if (is_readable($installedJson)) {
            $json = json_decode((string) file_get_contents($installedJson), true);
            if (is_array($json)) {
                foreach ($json['packages'] ?? [] as $pkg) {
                    if (is_array($pkg) && isset($pkg['name']) && is_string($pkg['name'])) {
                        $names[strtolower($pkg['name'])] = true;
                    }
                }
            }
        }

        $lockPath = $projectRoot.'/composer.lock';
        if (is_readable($lockPath)) {
            $lock = json_decode((string) file_get_contents($lockPath), true);
            if (is_array($lock)) {
                foreach (['packages', 'packages-dev'] as $section) {
                    foreach ($lock[$section] ?? [] as $pkg) {
                        if (is_array($pkg) && isset($pkg['name']) && is_string($pkg['name'])) {
                            $names[strtolower($pkg['name'])] = true;
                        }
                    }
                }
            }
        }

        $composerJson = $projectRoot.'/composer.json';
        if (is_readable($composerJson)) {
            $composer = json_decode((string) file_get_contents($composerJson), true);
            if (is_array($composer)) {
                foreach (['require', 'require-dev'] as $section) {
                    foreach (array_keys($composer[$section] ?? []) as $pkgName) {
                        if (is_string($pkgName)) {
                            $names[strtolower($pkgName)] = true;
                        }
                    }
                }
            }
        }

        return $names;
    }

    /**
     * @param array<string, true> $names
     */
    private function hasComposerPackagePrefix(array $names, string $prefix): bool
    {
        $prefix = strtolower($prefix);
        foreach (array_keys($names) as $n) {
            if (str_starts_with($n, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filesystem hints when composer.json / lock omit framework metapackages (common for Webman plugins).
     *
     * @return array{laravel: bool, webman: bool, hyperf: bool, thinkphp: bool}
     */
    private function inferFrameworkFromFilesystem(string $root): array
    {
        $webman = is_dir($root.'/config')
            && (
                is_file($root.'/start.php')
                || is_file($root.'/windows.php')
                || (is_dir($root.'/support') && is_file($root.'/support/bootstrap.php'))
            );

        $laravel = is_file($root.'/artisan')
            && (
                is_file($root.'/bootstrap/app.php')
                || is_file($root.'/app/Http/Kernel.php')
                || is_dir($root.'/bootstrap/cache')
            );

        $hyperf = is_file($root.'/bin/hyperf.php')
            || (
                is_dir($root.'/config/autoload')
                && (
                    is_file($root.'/config/autoload/server.php')
                    || is_file($root.'/config/autoload/dependencies.php')
                )
            );

        $thinkphp = file_exists($root.'/think') && ! is_dir($root.'/think');

        return [
            'laravel' => $laravel,
            'webman' => $webman,
            'hyperf' => $hyperf,
            'thinkphp' => $thinkphp,
        ];
    }

    private function publishHyperfAutoloadConfig(string $projectRoot, string $hyperfStub, IOInterface $io): void
    {
        $autoloadDir = $projectRoot.'/config/autoload';
        if (! is_dir($autoloadDir) && ! @mkdir($autoloadDir, 0775, true) && ! is_dir($autoloadDir)) {
            $io->writeError(sprintf('<error>[%s]</error> Could not create Hyperf config directory: %s', self::PACKAGE_NAME, $autoloadDir));

            return;
        }

        $target = $autoloadDir.'/encryptable.php';
        if (file_exists($target)) {
            return;
        }

        if (@copy($hyperfStub, $target)) {
            $io->write(sprintf(
                '<info>[%s]</info> Installed Hyperf merge config: <comment>config/autoload/encryptable.php</comment> (see https://hyperf.wiki/en/config.html)',
                self::PACKAGE_NAME
            ));
        } else {
            $io->writeError(sprintf('<error>[%s]</error> Failed to copy Hyperf config to %s', self::PACKAGE_NAME, $target));
        }
    }

    private function publishFlatConfig(
        string $projectRoot,
        string $mainSource,
        IOInterface $io,
        bool $laravel,
        bool $lumen,
        bool $webman,
        bool $thinkphp
    ): void {
        $configDir = $projectRoot.'/config';
        if (! is_dir($configDir) && ! @mkdir($configDir, 0775, true) && ! is_dir($configDir)) {
            $io->writeError(sprintf('<error>[%s]</error> Could not create config directory: %s', self::PACKAGE_NAME, $configDir));

            return;
        }

        $target = $configDir.'/encryptable.php';
        if (file_exists($target)) {
            return;
        }

        if (! @copy($mainSource, $target)) {
            $io->writeError(sprintf('<error>[%s]</error> Failed to copy config to %s', self::PACKAGE_NAME, $target));

            return;
        }

        $stack = [];
        if ($laravel) {
            $stack[] = 'Laravel';
        }
        if ($lumen) {
            $stack[] = 'Lumen';
        }
        if ($webman) {
            $stack[] = 'Webman';
        }
        if ($thinkphp) {
            $stack[] = 'ThinkPHP';
        }

        $label = $stack !== [] ? implode(' + ', $stack) : 'flat config';
        $io->write(sprintf(
            '<info>[%s]</info> Installed <comment>config/encryptable.php</comment> (%s — project <comment>config/</comment> per framework conventions)',
            self::PACKAGE_NAME,
            $label
        ));
    }
}
