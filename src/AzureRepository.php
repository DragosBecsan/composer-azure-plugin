<?php

namespace MarvinCaspar\Composer;

class AzureRepository
{
    protected string $organization;
    protected string $project;
    protected string $scope;
    protected string $feed;
    protected bool $symlink;
    protected array $vendors;
    protected array $artifacts = [];

    public function __construct(string $organization, string $project, string $feed, bool $symlink, array $vendors)
    {
        $this->organization = $organization;
        $this->project = $project;
        $this->feed = $feed;
        $this->symlink = $symlink;
        $this->scope = "project";
        $this->vendors = $vendors;
    }

    public function getOrganization(): string
    {
        return $this->organization;
    }

    public function getProject(): string
    {
        return $this->project;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getFeed(): string
    {
        return $this->feed;
    }

    public function getSymlink(): bool
    {
        return $this->symlink;
    }

    public function getVendors(): array
    {
        return $this->vendors;
    }

    public function addArtifact(string $name, string $version): void
    {
        if (str_starts_with($version, '^')) {
            $version = sprintf('%s.*', explode('.', str_replace('^', '', $version))[0]);
        }

        $this->artifacts[] = new Artifact($name, new Version($version), $this);
    }

    public function updateArtifactVersion(int $index, string $version): void
    {
        $this->artifacts[$index]['version'] = $version;
    }

    public function getArtifacts(): array
    {
        return $this->artifacts;
    }

    public function countArtifacts(): int
    {
        return count($this->artifacts);
    }
}
