<?php declare(strict_types=1);


namespace MarvinCaspar\Composer;


class Artifact
{
    public function __construct(
        private readonly string          $name,
        private readonly Version         $version,
        private readonly AzureRepository $azureRepository
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): Version
    {
        return $this->version;
    }

    public function getAzureRepository(): AzureRepository
    {
        return $this->azureRepository;
    }
}
