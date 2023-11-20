<?php

namespace MarvinCaspar\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;

class AzurePlugin implements PluginInterface, EventSubscriberInterface, Capable
{
    protected Composer $composer;
    protected IOInterface $io;
    protected bool $hasAzureRepositories = true;

    protected FileHelper $fileHelper;

    protected string $composerCacheDir = '';
    protected string $shortedComposerCacheDir = '~/.composer/cache/azure';

    protected bool $isInstall = false;

    /**
     * @var Artifact[]
     */
    protected array $downloadedArtifacts = [];

    public CommandExecutor $commandExecutor;

    public function __construct()
    {
        $this->commandExecutor = new CommandExecutor();
    }

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->composerCacheDir = (string)$this->composer->getConfig()->get('cache-dir') . DIRECTORY_SEPARATOR . 'azure';
        $this->fileHelper = new FileHelper();

        $extra = $composer->getPackage()->getExtra();
        if (!isset($extra['azure-repositories']) || !is_array($extra['azure-repositories'])) {
            $this->hasAzureRepositories = false;
        }
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        // nothing to do
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        // nothing to do
    }

    public function getCapabilities(): array
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'MarvinCaspar\Composer\CommandProvider',
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_INSTALL_CMD => [['executeInstall', 50000]],
            ScriptEvents::PRE_UPDATE_CMD => [['execute', 50000]],

            ScriptEvents::POST_INSTALL_CMD => [['modifyComposerLockPostInstall', 50000]],
            ScriptEvents::POST_UPDATE_CMD => [['modifyComposerLockPostInstall', 50000]],
        ];
    }

    public function executeInstall(): void
    {
        $this->isInstall = true;
        $this->execute();
    }

    public function execute(): void
    {
        if (!$this->hasAzureRepositories) {
            return;
        }

        // Update lock file to use the local composer cache dir because each user may have a unique cache path
        $this->modifyComposerLock($this->shortedComposerCacheDir, $this->composerCacheDir);

        $azureRepositories = $this->parseRequiredPackages($this->composer);
        $this->fetchAzurePackages($azureRepositories, $this->composer->getPackage()->getName());
        $this->addAzurePackagesAsLocalRepositories();
    }

    public function modifyComposerLockPostInstall(): void
    {
        // Update lock file to use shorted composer cache dir to make it work for all users
        $this->modifyComposerLock($this->composerCacheDir, $this->shortedComposerCacheDir);
    }

    protected function modifyComposerLock(string $search, string $replaceWith): void
    {
        if (!is_file('composer.lock')) {
            return;
        }

        $sedCommand = 'sed -i -e "s|' . $search . '|' . $replaceWith . '|g" composer.lock';
        // on macos sed needs an empty string for the i parameter
        if (strtolower(PHP_OS) === 'darwin') {
            $sedCommand = 'sed -i "" -e "s|' . $search . '|' . $replaceWith . '|g" composer.lock';
        }

        $this->commandExecutor->executeShellCmd($sedCommand);

        $this->io->write('<info>Modified composer.lock path</info>');
    }

    protected function parseRequiredPackages(Composer $composer): array
    {
        $azureRepositories = [];
        $extra = $composer->getPackage()->getExtra();
        $requires = $composer->getPackage()->getRequires();

        if (!isset($extra['azure-repositories'])) {
            return [];
        }

        foreach ($extra['azure-repositories'] as ['organization' => $organization, 'project' => $project, 'feed' => $feed, 'symlink' => $symlink, 'vendors' => $vendors]) {
            $azureRepository = new AzureRepository($organization, $project, $feed, $symlink, $vendors);

            $packages = array_filter(
                array_keys($requires),
                fn(string $packageName) => $this->isPackageInVendorsConfig($packageName, $vendors)
            );

            foreach ($packages as $packageName) {
                if (array_key_exists($packageName, $requires)) {
                    $azureRepository->addArtifact($packageName, $requires[$packageName]->getPrettyConstraint());
                }
            }

            $azureRepositories[] = $azureRepository;
        }

        return $azureRepositories;
    }

    private function isPackageInVendorsConfig(string $packageName, array $vendors): bool
    {
        [$vendor] = explode('/', $packageName);

        return in_array($vendor, $vendors);
    }

    protected function parseRequiredPackagesOnDependency(AzureRepository $azureRepository, Composer $composer): array
    {
        $requires = $composer->getPackage()->getRequires();
        $packages = array_filter(
            array_keys($requires),
            fn(string $packageName) => $this->isPackageInVendorsConfig($packageName, $azureRepository->getVendors())
        );
        foreach ($packages as $packageName) {
            $azureRepository->addArtifact($packageName, $requires[$packageName]->getPrettyConstraint());
        }

        return [$azureRepository];
    }

    protected function fetchAzurePackages(array $azureRepositories, string $packageName, bool $isMainDependency = true): void
    {
        $package_count = 0;

        /** @var AzureRepository $azureRepository */
        foreach ($azureRepositories as $azureRepository) {
            $package_count += $azureRepository->countArtifacts();
        }

        // skip download if package has no azure repositories defined
        if ($package_count == 0) {
            return;
        }

        $this->io->write('');
        $this->io->write('<info>Fetching packages from Azure - ' . $packageName . '</info>');
        $this->downloadAzureArtifacts($azureRepositories, $isMainDependency);
    }

    protected function downloadAzureArtifacts(array $azureRepositories, bool $isMainDependency): void
    {
        /** @var AzureRepository $azureRepository */
        foreach ($azureRepositories as $azureRepository) {
            $artifacts = $azureRepository->getArtifacts();

            /** @var Artifact $artifact */
            foreach ($artifacts as $artifact) {
                if (!$this->alreadyDownloaded($artifact, $isMainDependency)) {
                    $this->downloadAzureArtifact($artifact, $isMainDependency);
                }
            }

        }
    }

    protected function alreadyDownloaded(Artifact $artifact, bool $isMainDependency): bool
    {
        /** @var Artifact $downloadedArtifact */
        foreach ($this->downloadedArtifacts as $downloadedArtifact) {
            // if the dependency comes from the root project, than we want to check for the requested name and version
            // otherwise we just check if the package name matches
            if (
                $isMainDependency &&
                $downloadedArtifact->getName() == $artifact->getName() &&
                $downloadedArtifact->getVersion()->getVersion() == $artifact->getVersion()->getVersion()
            ) {
                return true;
            }

            if (!$isMainDependency && $downloadedArtifact->getName() == $artifact->getName()) {
                return true;
            }
        }
        return false;
    }

    protected function downloadAzureArtifact(Artifact $artifact, bool $isMainDependency): void
    {
        if ($artifact->getVersion()->isDevVersion()) {
            return;
        }

        $azureRepository = $artifact->getAzureRepository();

        $artifactPath = $this->getArtifactPath($azureRepository->getOrganization(), $azureRepository->getFeed(), $artifact);

        // scandir > 2 because of . and .. entries
        if (is_dir($artifactPath) && count(scandir($artifactPath)) > 2) {
            $this->updateArtifactComposerVersion($artifact, $artifactPath);
            $this->io->write('<info>Package ' . $artifact->getName() . ' already downloaded - ' . $artifactPath . '</info>');
            $downloadedArtifact = $artifact;
        } else {
            $command = 'az artifacts universal download';
            $command .= ' --organization ' . 'https://' . $azureRepository->getOrganization();
            $command .= ' --project "' . $azureRepository->getProject() . '"';
            $command .= ' --scope ' . $azureRepository->getScope();
            $command .= ' --feed ' . $azureRepository->getFeed();
            $command .= ' --name ' . str_replace('/', '.', $artifact->getName());
            $command .= ' --version \'' . $artifact->getVersion()->getVersion() . '\'';
            $command .= ' --path ' . $artifactPath;

            $result = $this->commandExecutor->executeShellCmd($command);
            $downloadedArtifact = new Artifact($artifact->getName(), new Version($result->Version), $azureRepository);

            // is wildcard version, than rename downloaded folder to downloaded version
            if ($artifact->getVersion()->isWildcardVersion()) {
                $artifactPathOld = $artifactPath;
                $artifactPath = $this->getArtifactPath($azureRepository->getOrganization(), $azureRepository->getFeed(), $downloadedArtifact);
                $this->fileHelper->copyDirectory($artifactPathOld, $artifactPath);
                $this->fileHelper->removeDirectory($artifactPathOld);
            }

            $this->updateArtifactComposerVersion($downloadedArtifact, $artifactPath);

            $this->io->write('<info>Package ' . $artifact->getName() . ' - ' . $result->Version . ' downloaded - ' . $artifactPath . '</info>');
        }


        if ($isMainDependency) {
            $replaced = false;
            foreach ($this->downloadedArtifacts as $i => $alreadyDownloadedArtifact) {
                if ($alreadyDownloadedArtifact->getName() == $artifact->getName()) {
                    $this->downloadedArtifacts[$i] = $downloadedArtifact;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $this->downloadedArtifacts[] = $downloadedArtifact;
            }
        } else {
            $this->downloadedArtifacts[] = $downloadedArtifact;
        }

        $this->solveDependencies($azureRepository, $artifactPath);
    }

    protected function updateArtifactComposerVersion(Artifact $artifact, string $artifactPath): void
    {
        $artifactComposerFile = sprintf('%s/composer.json', $artifactPath);
        if (file_exists($artifactComposerFile)) {
            $jsonString = file_get_contents($artifactComposerFile);
            $jsonData = json_decode($jsonString, true);

            if (!isset($jsonData['version'])) {
                $jsonData['version'] = $artifact->getVersion()->getDownloadVersion();
                foreach (array_keys($jsonData) as $key) {
                    if (!empty($jsonData[$key])) {
                        continue;
                    }

                    unset($jsonData[$key]);
                }

                file_put_contents($artifactComposerFile, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }

    protected function getArtifactPath(string $organization, string $feed, Artifact $artifact): string
    {
        return implode(
            DIRECTORY_SEPARATOR,
            [
                $this->composerCacheDir,
                $organization,
                $feed,
                $artifact->getName(),
                $artifact->getVersion()->getDownloadVersion(),
            ]
        );
    }


    protected function solveDependencies(AzureRepository $azureRepository, string $packagePath): void
    {
        $composerForPackage = $this->getComposerForPackage($packagePath);
        $azureRepositories = $this->parseRequiredPackagesOnDependency(clone $azureRepository, $composerForPackage);

        $this->fetchAzurePackages($azureRepositories, $composerForPackage->getPackage()->getName(), false);
    }

    protected function getComposerForPackage(string $path): Composer
    {
        $factory = new Factory();
        return $factory->createComposer($this->io, implode(DIRECTORY_SEPARATOR, [$path, Factory::getComposerFile()]));
    }

    protected function addAzurePackagesAsLocalRepositories(): void
    {
        foreach ($this->downloadedArtifacts as $artifact) {
            $repo = $this->composer->getRepositoryManager()->createRepository(
                'path',
                [
                    'url' => $this->getArtifactPath(
                        $artifact->getAzureRepository()->getOrganization(),
                        $artifact->getAzureRepository()->getFeed(),
                        $artifact
                    ),
                    'options' => ['symlink' => $artifact->getAzureRepository()->getSymlink()],
                ]
            );
            $this->composer->getRepositoryManager()->addRepository($repo);
        }

        if (is_file('composer.lock')) {
            $this->adjustPathInComposerLocker();
        }
    }

    protected function adjustPathInComposerLocker(): void
    {
        $this->io->write('<info>Reload composer lock</info>');
        $locker = $this->composer->getLocker();
        if (!$locker->isLocked()) {
            return;
        }
        $lockData = $locker->getLockData();
        $loader = new ArrayLoader(null, true);
        $packages = [];

        foreach ($lockData['packages'] as $package) {
            $packageToLock = $loader->load($package);

            $packageToLock->setDistMirrors(null);
            $packageToLock->setNotificationUrl('');

            $artifact = $this->getDownloadedArtifactByName($packageToLock->getName());
            if ($artifact instanceof Artifact) {
                $packageToLock->setDistType('path');
                $newDistUrl = $this->getArtifactPath(
                    $artifact->getAzureRepository()->getOrganization(),
                    $artifact->getAzureRepository()->getFeed(),
                    $artifact
                );
                $packageToLock->setDistUrl($newDistUrl);
                $package['dist']['url'] = $newDistUrl;

                $packageToLock->setDistReference(null);

                $packageToLock->setTransportOptions([
                    'symlink' => $artifact->getAzureRepository()->getSymlink(),
                    'relative' => false
                ]);
            }

            if ($packageToLock->getDistType() === 'path') {
                $packageToLock->setDistUrl(str_replace($this->shortedComposerCacheDir, $this->composerCacheDir, $package['dist']['url']));
            }

            // Use complete package if the given package is an alias package
            if ($packageToLock instanceof CompleteAliasPackage) {
                $packageToLock = $packageToLock->getAliasOf();
            }

            $packages[] = $packageToLock;
        }
        $packagesDev = [];
        foreach ($lockData['packages-dev'] as $package) {
            $packageToLock = $loader->load($package);
            $packagesDev[] = $packageToLock;
        }

        $locker->setLockData(
            $packages,
            $packagesDev,
            $lockData['platform'],
            $lockData['platform-dev'],
            $lockData['aliases'],
            $lockData['minimum-stability'],
            $lockData['stability-flags'],
            $lockData['prefer-stable'],
            $lockData['prefer-lowest'],
            []
        );
    }

    private function getDownloadedArtifactByName(string $packageName): ?Artifact
    {
        foreach ($this->downloadedArtifacts as $artifact) {
            if ($artifact->getName() !== $packageName) {
                continue;
            }

            return $artifact;
        }

        return null;
    }
}
