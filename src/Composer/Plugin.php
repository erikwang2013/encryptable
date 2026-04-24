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
 * Publishes default config files following each supported framework's official layout:
 *
 * - Laravel / Lumen: {@see https://laravel.com/docs/configuration} — PHP files under {@code config/}
 * - Webman: {@see https://www.workerman.net/doc/webman/config.html} — {@code config/*.php}
 * - ThinkPHP: {@see https://doc.thinkphp.cn/v8_0/config_file.html} — project {@code config/}
 * - Hyperf: {@see https://hyperf.wiki/en/config.html} — merge configs under {@code config/autoload/}
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

        $laravel = isset($names['laravel/framework']);
        $lumen = isset($names['laravel/lumen-framework']);
        $webman = isset($names['workerman/webman']);
        $hyperf = isset($names['hyperf/framework']) || isset($names['hyperf/hyperf']);
        $thinkphp = isset($names['topthink/framework']) || isset($names['topthink/think']);

        $publishFlat = $laravel || $lumen || $webman || $thinkphp;
        $publishHyperfAutoload = $hyperf;

        if (! $publishFlat && ! $publishHyperfAutoload) {
            $io->write(sprintf(
                '<comment>[%s]</comment> Skipped auto config: no Laravel, Lumen, Webman, Hyperf, or ThinkPHP found in composer.json / composer.lock. Copy files manually (see README).',
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
     * @return array<string, true> Lowercased package names from composer.json and composer.lock
     */
    private function collectPackageNamesLowercase(string $projectRoot): array
    {
        $names = [];

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

        $jsonPath = $projectRoot.'/composer.json';
        if (is_readable($jsonPath)) {
            $json = json_decode((string) file_get_contents($jsonPath), true);
            if (is_array($json)) {
                foreach (['require', 'require-dev'] as $section) {
                    foreach (array_keys($json[$section] ?? []) as $pkgName) {
                        if (is_string($pkgName)) {
                            $names[strtolower($pkgName)] = true;
                        }
                    }
                }
            }
        }

        return $names;
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

        $label = implode(' + ', $stack);
        $io->write(sprintf(
            '<info>[%s]</info> Installed <comment>config/encryptable.php</comment> (%s — project <comment>config/</comment> per framework conventions)',
            self::PACKAGE_NAME,
            $label
        ));
    }
}
